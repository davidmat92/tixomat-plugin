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
            'ep_hero_auto'       => 0,        // Auto-Höhe: Bild bestimmt Höhe
            'ep_layout'          => '2col',   // '1col' oder '2col'
            'ep_max_width'       => 1100,
            'ep_col_split'       => 65,         // Spaltenverteilung: % für Hauptspalte (Rest = Sidebar)
            'ep_col_gap'         => 36,         // Abstand zwischen den Spalten (px)
            'ep_gap'             => 32,
            'ep_gap_tl'          => 28,
            'ep_gap_tp'          => 24,
            'ep_gap_pl'          => 20,
            'ep_gap_pp'          => 16,
            'ep_pad_x'           => 32,       // Padding links/rechts (Desktop)
            'ep_pad_x_tl'       => 28,       // Padding ≤1119 (Tablet Landscape)
            'ep_pad_x_tp'       => 20,       // Padding ≤1023 (Tablet Portrait)
            'ep_pad_x_pl'       => 16,       // Padding ≤767  (Phone Landscape)
            'ep_pad_x_pp'       => 12,       // Padding ≤479  (Phone Portrait)
            'ep_card_gap'       => 0,        // Karten-Abstand vom Rand Desktop
            'ep_card_gap_tl'    => 0,        // Karten-Abstand ≤1119
            'ep_card_gap_tp'    => 0,        // Karten-Abstand ≤1023
            'ep_card_gap_pl'    => 0,        // Karten-Abstand ≤767
            'ep_card_gap_pp'    => 0,        // Karten-Abstand ≤479
            'ep_card_pad'       => 28,       // Karten-Innenabstand Desktop
            'ep_card_pad_tl'    => 20,       // Karten-Innenabstand ≤1119
            'ep_card_pad_tp'    => 20,       // Karten-Innenabstand ≤1023
            'ep_card_pad_pl'    => 16,       // Karten-Innenabstand ≤767
            'ep_card_pad_pp'    => 12,       // Karten-Innenabstand ≤479
            'ep_pad_top'         => 32,       // Padding oben Desktop
            'ep_pad_top_tl'      => 28,
            'ep_pad_top_tp'      => 24,
            'ep_pad_top_pl'      => 20,
            'ep_pad_top_pp'      => 16,
            'ep_pad_bottom'      => 64,       // Padding unten Desktop
            'ep_pad_bottom_tl'   => 56,
            'ep_pad_bottom_tp'   => 48,
            'ep_pad_bottom_pl'   => 40,
            'ep_pad_bottom_pp'   => 32,
            'ep_sticky_offset'   => 56,       // Abstand unter Sticky-Header (px)
            // ── Event-Karten Seite ──
            'ec_page_enabled'    => 0,        // Automatische /events/ Seite
            'ec_pad_x'           => 32,
            'ec_pad_y'           => 56,
            'ec_max_width'       => 1200,
            'ec_show_search'     => 1,
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
            'ep_sticky_tabs'     => 1,        // Sticky Tab-Navigation

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
            'account_activation_redirect' => '/tickets/', // Wohin nach Account-Aktivierung (Passwort gesetzt)
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
            'ht_version'             => 'v1',   // v1 Classic | v2 Premium | v3 Festival | v4 Holographic | v5 Cyberpunk | v6 Retro
            'ht_show_event_cover'    => 0,      // Event-Bild als Hero oben im Ticket (nur wenn gesetzt)
            'ht_show_countdown'      => 0,      // Countdown zum Event oben
            'ht_show_verified_badge' => 0,      // "✓ Offizielles Ticket"-Badge im Header
            'ht_show_agb_footer'     => 1,      // AGB/Rückerstattung-Link im Footer
            'ht_seasonal_enabled'    => 0,      // Saison-Overlays (Weihnachten/Halloween/Valentin) über V1-V6
            'ht_watermark_enabled'   => 0,      // Diagonal-Wasserzeichen mit Ticket-ID
            'ht_watermark_color'     => '',     // Wasserzeichen-Farbe (leer = Version-Defaults)
            'ht_weather_enabled'     => 0,      // Live-Wetter am Event-Tag
            'ht_action_save_image'   => 1,      // Button "Als Bild speichern"
            'ht_action_wallets'      => 1,      // Apple/Google Wallet Buttons
            'ht_action_print'        => 1,      // Button "Ticket drucken"
            'ht_share_image'         => '',     // Eigenes OG-Bild für Ticket-Shares (statt Event-Cover)
            'ht_qr_bright_mode'      => 1,      // Bei QR-Tap: Fullscreen + Wake-Lock für max. Helligkeit
            'ht_checkin_sound'       => 1,      // Audio-Feedback beim Check-in
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

            // ── Ticket-Badge (Check-in-Status + Personenzuordnung) ──
            'badge_pending_bg'   => '#6366f1',   // Hintergrund: Noch nicht eingecheckt
            'badge_pending_text' => '#ffffff',   // Schrift: Noch nicht eingecheckt
            'badge_done_bg'      => '#10b981',   // Hintergrund: Eingecheckt
            'badge_done_text'    => '#ffffff',   // Schrift: Eingecheckt

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
            'promoter_page_id'      => 0,
            'promoter_auth_method'  => 'both', // both | magic_link | wp_login
            'promoter_default_commission_type'  => 'percent', // global Fallback fuer alle Promoter/Events
            'promoter_default_commission_value' => 0,         // 0 = kein Default (keine Provision wenn nirgends gesetzt)
            'promoter_fullscreen'   => 1, // Fullscreen-Modus fuer Promoter-Dashboard (kein Theme)
            // ── Sponsor-System ──
            'sponsor_enabled'    => 1,
            'sponsor_page_id'    => 0,
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
            'gateway_provider_order' => 'stripe,mollie,paypal,bank',
            'mollie_enabled'         => 1,
            'mollie_api_key'         => '',
            'mollie_test_mode'       => 0,
            'mollie_enabled_methods' => [],
            'mollie_methods_order'   => '', // kommagetrennte Reihenfolge
            'stripe_enabled'              => 0,
            'stripe_test_mode'            => 1,
            'stripe_enabled_methods'      => [],
            'stripe_methods_order'        => '',
            'stripe_publishable_key_live' => '',
            'stripe_secret_key_live'      => '',
            'stripe_webhook_secret_live'  => '',
            'stripe_publishable_key_test' => '',
            'stripe_secret_key_test'      => '',
            'stripe_webhook_secret_test'  => '',
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
            // ── Event-Homepage ──
            'hp_enabled'             => 0,        // Homepage-Shortcode aktiv
            'hp_hero_count'          => 5,        // Anzahl Hero-Events
            'hp_grid_count'          => 8,        // Anfängliche Grid-Events
            'hp_show_hero'           => 1,        // Hero-Bereich anzeigen
            'hp_show_time_filter'    => 1,        // Zeitfilter-Bar anzeigen
            'hp_show_cat_filter'     => 1,        // Kategorie-Chips anzeigen
            'hp_show_load_more'      => 1,        // Mehr-laden Button
            'hp_exclude_categories'  => '',       // Kommagetrennte Slugs zum Ausschließen
            'hp_only_featured_hero'  => 0,        // Hero NUR Featured Events
            'hp_hero_style'          => 'grid',   // grid | slider | fullwidth
            'hp_show_popular'        => 1,        // "Heute beliebt" Sektion
            'hp_popular_count'       => 4,        // Anzahl beliebte Events
            'hp_show_countdown'      => 1,        // Countdown bei bald startenden Events
            'hp_url_sync'            => 1,        // Filter in URL schreiben
            'hp_show_newsletter'     => 0,        // Newsletter-Banner
            'hp_newsletter_title'    => 'Bleib auf dem Laufenden',
            'hp_newsletter_text'     => 'Erhalte die besten Events direkt in dein Postfach.',
            'hp_newsletter_url'      => '',       // URL zum Newsletter-Formular
            'hp_show_list_toggle'    => 1,        // Grid/Listen-Umschalter
            'hp_show_spotlight'      => 0,        // Veranstalter-Spotlight
            'hp_spotlight_org_id'    => 0,        // Veranstalter-ID (0 = automatisch)
            'hp_spotlight_count'     => 3,        // Events im Spotlight
            'hp_smart_time'          => 1,        // Tageszeit-basierte Filtervorauswahl
            'hp_max_width'           => 1200,     // Max-Breite in px
            'hp_pad_x'               => 0,        // Seitliches Padding
            'hp_pad_y'               => 0,        // Padding oben/unten
            // Dashboard-Sektionen
            'hp_show_stats_bar'      => 1,        // Stats-Bar anzeigen
            'hp_show_cat_tiles'      => 1,        // Kategorie-Kacheln anzeigen
            'hp_show_this_week'      => 1,        // "Diese Woche" Sektion
            'hp_this_week_days'      => 7,        // Tage voraus (3-14)
            'hp_show_locations'      => 1,        // Location-Spotlight anzeigen
            'hp_locations_count'     => 6,        // Anzahl Locations
            'hp_sections'            => [],       // Homepage-Baukasten (Reihenfolge + Enabled) — Migration bei erstem Lesen
            'hp_section_spacing'     => 40,       // Standard-Abstand zwischen Sektionen (px)
            'hp_cache_enabled'       => 0,        // Homepage-Sektionen-Caching
            'hp_cache_ttl'           => 600,      // Cache-TTL (Sekunden)
            // ── Phase-2 Sektionen ──
            'hp_show_hero_countdown' => 0,
            'hp_countdown_event_id'  => 0,
            'hp_countdown_label'     => 'Next Event',
            'hp_show_last_chance'    => 0,
            'hp_last_chance_hours'   => 48,
            'hp_last_chance_count'   => 4,
            'hp_show_weekday_grid'   => 0,
            'hp_weekday_weeks'       => 2,
            'hp_show_voucher'        => 0,
            'hp_voucher_title'       => 'Verschenke ein Erlebnis',
            'hp_voucher_text'        => 'Gutscheine für jedes Event — der perfekte Geschenktipp.',
            'hp_voucher_btn'         => 'Gutschein kaufen',
            'hp_voucher_url'         => '',
            'hp_voucher_image_id'    => 0,
            'hp_voucher_bg'          => '#FFE066',
            'hp_voucher_fg'          => '#0D0B09',
            'hp_show_partners'       => 0,
            'hp_partners_title'      => 'Bekannt aus & Partner',
            'hp_partners_logos'      => [],
            'hp_show_faq'            => 0,
            'hp_faq_title'           => 'Häufige Fragen',
            'hp_faq_items'           => [],
            // ── Phase-3 Sektionen ──
            'hp_show_greeting'       => 0,
            'hp_show_stories'        => 0,
            'hp_stories_count'       => 12,
            'hp_stories_size'        => 72,
            'hp_show_promoted'       => 0,
            'hp_show_favorites'      => 0,
            'hp_show_recent'         => 0,
            'hp_show_editorial'      => 0,
            'hp_editorial_event_id'  => 0,
            'hp_editorial_label'     => 'Empfehlung der Redaktion',
            'hp_editorial_title_override' => '',
            'hp_editorial_text'      => '',
            'hp_editorial_byline'    => '',
            'hp_editorial_cta'       => 'Event ansehen',
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
            // Thank-You-Page
            'ty_back_link_text'        => '← Zurück zu den Events',
            'ty_back_link_url'         => '',  // leer = home_url('/events/'); ansonsten z.B. https://example.com/programm/
            'ty_back_link_show'        => 1,
            'ty_my_tickets_url'        => '',  // leer = Auto-Detection via TIX_My_Tickets::get_tickets_page_url()
            // Kampagnen-Tracking
            'campaign_tracking_enabled'  => 0,
            'campaign_cookie_days'       => 30,
            'campaign_custom_channels'   => '[]',
            // ── Meta Ads ──
            'meta_pixel_enabled'       => 0,
            'meta_pixel_id'            => '',
            // Google Ads / GA4 / GTM
            'google_ads_enabled'           => 0,
            'google_ads_id'                => '',  // AW-XXXXXXXXX
            'google_ads_conversion_label'  => '',  // Label-Teil nach dem '/' im Conversion-Tag
            'google_ads_send_purchase'     => 1,   // bei Purchase auf Thank-You-Seite triggern
            'ga4_enabled'                  => 0,
            'ga4_measurement_id'           => '',  // G-XXXXXXXXX
            'gtm_enabled'                  => 0,
            'gtm_container_id'             => '',  // GTM-XXXXXXX
            'google_consent_mode'          => 'always', // 'always' oder 'consent_required'
            'google_consent_cookie'        => 'cookie_consent',
            // Auto-Coupon-Popup (Frontend)
            'coupon_popup_enabled'         => 1,
            'coupon_popup_headline'        => '🎁 Dein Rabatt ist aktiv!',
            'coupon_popup_subtext'         => 'Wir haben dir bereits einen Gutschein im Warenkorb hinterlegt — du sparst beim Checkout automatisch.',
            'coupon_popup_cta'             => 'Jetzt Tickets sichern',
            'coupon_popup_cta_url'         => '',
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
            // ── Rechnungen / Steuerlich relevante Aussteller-Daten ──
            'invoice_company_name'    => '',
            'invoice_company_address' => '',     // Mehrzeilige Anschrift (Straße, PLZ Ort, Land)
            'invoice_company_tax_id'  => '',     // Steuernummer (vom Finanzamt vergeben)
            'invoice_company_ust_id'  => '',     // USt-IdNr. (DE...)
            'invoice_managing_director' => '',   // Geschäftsführer-Name
            'invoice_register_court'  => '',     // Amtsgericht (z.B. "Amtsgericht Köln")
            'invoice_register_number' => '',     // Handelsregister-Nr (z.B. "HRB 12345")
            'invoice_email'           => '',     // Geschäfts-Email
            'invoice_phone'           => '',     // Geschäfts-Telefon
            'invoice_footer_text'     => '',     // Optionaler eigener Footer-Hinweis
            // ── Branding ──
            'branding_enabled'  => 1,
            'branding_url'      => 'https://mdj.events',
            // ── Fun Mode ──
            'fun_topbar'         => 0,
            'fun_topbar_text'    => '{name}, du bist eine geile Sau',
            // ── Auto-Archiv ──
            'auto_archive_enabled' => 1,
            'auto_archive_days'    => 0,
            // ── Auto-Complete ──
            'auto_complete_enabled' => 1,
            // ── Sponsor (Thank-You) ──
            'sponsor_enabled'    => 0,
            'sponsor_image_url'  => '',
            'sponsor_logo_width' => 30,
            // Ticket-Template
            'ticket_template'    => '',
            // ── Ticket-Bot ──
            'bot_avatar_id'          => 0,
            'bot_font'               => 'Inter',
            'bot_color_bg'           => '#FAF8F4',
            'bot_color_bg_header'    => '#ffffff',
            'bot_color_bg_input'     => '#ffffff',
            'bot_color_bg_card'      => '#ffffff',
            'bot_color_bg_input_field' => '#F3F0EA',
            'bot_color_accent'       => '#FF5500',
            'bot_color_accent_hover' => '#CC4400',
            'bot_color_text'         => '#0D0B09',
            'bot_color_text_muted'   => 'rgba(13,11,9,.50)',
            'bot_color_text_light'   => 'rgba(13,11,9,.35)',
            'bot_color_border'       => '#EDE9E0',
            'bot_color_user_bubble'  => '#FF5500',
            'bot_color_user_text'    => '#ffffff',
            'bot_enabled'            => 0,
            'bot_hub_url'            => 'https://tixomat.pythonanywhere.com',
            'bot_hub_master_key'     => '',
            'bot_hub_admin_key'      => '',
            'bot_api_secret'         => '',
            'bot_telegram_token'     => '',
            'bot_telegram_enabled'   => 0,
            'bot_whatsapp_token'     => '',
            'bot_whatsapp_phone_id'  => '',
            'bot_whatsapp_verify'    => '',
            'bot_whatsapp_enabled'   => 0,
            'bot_anthropic_key'      => '',
            'bot_name'               => 'Ticket-Assistent',
            'bot_greeting'           => '',
            'bot_personality'        => '',
            'bot_webchat_enabled'    => 1,
            'bot_registered'         => 0,
            'bot_tenant_id'          => '',

            // ─────────────────────────────────────────
            // Wallet-Integration (Apple Wallet + Google Wallet)
            // Buttons existieren bereits als Platzhalter — Settings hier
            // werden gefüllt sobald Apple-/Google-Accounts angelegt sind.
            // ─────────────────────────────────────────
            'wallet_enabled'                 => 0,        // Master-Switch (beide gleichzeitig aus)
            // Apple Wallet (.pkpass)
            'wallet_apple_enabled'           => 0,
            'wallet_apple_pass_type_id'      => '',       // z.B. pass.de.tixomat.ticket
            'wallet_apple_team_id'           => '',       // 10-stellige Apple Team-ID
            'wallet_apple_org_name'          => '',       // Organisations-Name (oben auf Pass)
            'wallet_apple_cert_path'         => '',       // Pfad zur .p12-Datei (auf Server)
            'wallet_apple_cert_password'     => '',       // Passwort für .p12
            'wallet_apple_wwdr_path'         => '',       // Pfad zur WWDR-Cert (.pem)
            'wallet_apple_logo_url'          => '',       // 160×50 PNG (transparent)
            'wallet_apple_icon_url'          => '',       // 29×29 PNG
            'wallet_apple_strip_url'         => '',       // 320×123 PNG (optional, Hintergrundbild oben)
            'wallet_apple_bg_color'          => '#0f172a',
            'wallet_apple_fg_color'          => '#ffffff',
            'wallet_apple_label_color'       => '#cbd5e1',
            'wallet_apple_relevant_radius'   => 200,      // Meter — Push am Venue
            // Google Wallet (Save-to-Wallet API)
            'wallet_google_enabled'          => 0,
            'wallet_google_issuer_id'        => '',       // Numerische Issuer-ID
            'wallet_google_service_email'    => '',       // service@xyz.iam.gserviceaccount.com
            'wallet_google_service_key'      => '',       // Private Key (PEM, mehrzeilig)
            'wallet_google_class_suffix'     => 'tixomat-event-ticket',  // ID-Suffix (zusammen mit Issuer)
            'wallet_google_logo_url'         => '',
            'wallet_google_hero_url'         => '',
            'wallet_google_bg_color'         => '#0f172a',
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

        // Export / Import AJAX
        add_action('wp_ajax_tix_export_settings', [__CLASS__, 'ajax_export']);
        add_action('wp_ajax_tix_import_settings', [__CLASS__, 'ajax_import']);

        // PayPal-Verbindungstest (AJAX + URL-Fallback)
        add_action('wp_ajax_tix_paypal_test',     [__CLASS__, 'ajax_paypal_test']);
        add_action('admin_init',                  [__CLASS__, 'maybe_handle_paypal_test_url']);
    }

    /**
     * URL-basierter PayPal-Test (Fallback, umgeht admin-ajax.php).
     * Trigger: /wp-admin/admin.php?page=tix-settings&tix_paypal_test=1&_wpnonce=XYZ
     * Setzt Ergebnis in Transient → wird auf Settings-Seite als Notice gerendert.
     */
    public static function maybe_handle_paypal_test_url() {
        if (empty($_GET['tix_paypal_test']) || ($_GET['page'] ?? '') !== 'tix-settings') return;
        if (!current_user_can('manage_options')) return;
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'tix_paypal_test_url')) {
            set_transient('tix_paypal_test_result_' . get_current_user_id(), [
                'success' => false,
                'message' => 'Nonce ungültig – bitte den Button erneut klicken (nicht die URL aus der Historie).',
            ], 60);
            wp_safe_redirect(admin_url('admin.php?page=tix-settings#tix-paypal-test-result'));
            exit;
        }

        if (!class_exists('TIX_Gateway_PayPal')) {
            set_transient('tix_paypal_test_result_' . get_current_user_id(), [
                'success' => false,
                'message' => 'PayPal-Gateway nicht geladen.',
            ], 60);
            wp_safe_redirect(admin_url('admin.php?page=tix-settings#tix-paypal-test-result'));
            exit;
        }

        $ref = new ReflectionClass('TIX_Gateway_PayPal');
        $m = $ref->getMethod('get_access_token');
        $m->setAccessible(true);
        $token = $m->invoke(null);

        $s = get_option(self::OPTION_KEY, []);
        $mode = !empty($s['paypal_sandbox']) ? 'Sandbox' : 'Live';

        set_transient('tix_paypal_test_result_' . get_current_user_id(), [
            'success' => (bool) $token,
            'message' => $token
                ? $mode . ' – Authentifizierung erfolgreich'
                : (TIX_Gateway_PayPal::get_last_auth_error() ?: 'Authentifizierung fehlgeschlagen'),
        ], 60);

        wp_safe_redirect(admin_url('admin.php?page=tix-settings#tix-paypal-test-result'));
        exit;
    }

    /**
     * AJAX: PayPal-Zugangsdaten gegen die API testen.
     * Nutzt die zuletzt gespeicherten Keys aus tix_settings.
     */
    public static function ajax_paypal_test() {
        // Akzeptiere beide Nonce-Param-Varianten (robust gegen Security-Plugins,
        // die _wpnonce in POST als Form-Submit interpretieren)
        if (!check_ajax_referer('tix_paypal_test', 'nonce', false) &&
            !check_ajax_referer('tix_paypal_test', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Nonce ungültig oder abgelaufen – Seite neu laden.'], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }
        if (!class_exists('TIX_Gateway_PayPal')) {
            wp_send_json_error(['message' => 'PayPal-Gateway nicht geladen.']);
        }

        // Protected get_access_token via Reflection aufrufen
        $ref = new ReflectionClass('TIX_Gateway_PayPal');
        $m = $ref->getMethod('get_access_token');
        $m->setAccessible(true);
        $token = $m->invoke(null);

        if ($token) {
            $s = get_option(self::OPTION_KEY, []);
            $mode = !empty($s['paypal_sandbox']) ? 'Sandbox' : 'Live';
            wp_send_json_success(['message' => $mode . ' – Authentifizierung erfolgreich']);
        }

        $err = TIX_Gateway_PayPal::get_last_auth_error();
        wp_send_json_error(['message' => $err ?: 'Authentifizierung fehlgeschlagen']);
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
        $clean['ep_hero_auto']   = !empty($input['ep_hero_auto']) ? 1 : 0;
        $clean['ep_layout'] = in_array($input['ep_layout'] ?? '', ['1col', '2col']) ? $input['ep_layout'] : '2col';
        $clean['ep_pad_x']    = max(0, min(80, intval($input['ep_pad_x'] ?? 32)));
        $clean['ep_pad_x_tl'] = max(0, min(80, intval($input['ep_pad_x_tl'] ?? 28)));
        $clean['ep_pad_x_tp'] = max(0, min(80, intval($input['ep_pad_x_tp'] ?? 20)));
        $clean['ep_pad_x_pl'] = max(0, min(80, intval($input['ep_pad_x_pl'] ?? 16)));
        $clean['ep_pad_x_pp'] = max(0, min(80, intval($input['ep_pad_x_pp'] ?? 12)));
        // Karten-Abstand vom Rand (pro Breakpoint)
        $clean['ep_card_gap']    = max(0, min(80, intval($input['ep_card_gap'] ?? 0)));
        $clean['ep_card_gap_tl'] = max(0, min(80, intval($input['ep_card_gap_tl'] ?? 0)));
        $clean['ep_card_gap_tp'] = max(0, min(80, intval($input['ep_card_gap_tp'] ?? 0)));
        $clean['ep_card_gap_pl'] = max(0, min(80, intval($input['ep_card_gap_pl'] ?? 0)));
        $clean['ep_card_gap_pp'] = max(0, min(80, intval($input['ep_card_gap_pp'] ?? 0)));
        // Karten-Innenabstand (pro Breakpoint)
        $clean['ep_card_pad']    = max(0, min(80, intval($input['ep_card_pad'] ?? 28)));
        $clean['ep_card_pad_tl'] = max(0, min(80, intval($input['ep_card_pad_tl'] ?? 20)));
        $clean['ep_card_pad_tp'] = max(0, min(80, intval($input['ep_card_pad_tp'] ?? 20)));
        $clean['ep_card_pad_pl'] = max(0, min(80, intval($input['ep_card_pad_pl'] ?? 16)));
        $clean['ep_card_pad_pp'] = max(0, min(80, intval($input['ep_card_pad_pp'] ?? 12)));
        // Padding oben (pro Breakpoint)
        $clean['ep_pad_top']       = max(0, min(120, intval($input['ep_pad_top'] ?? 32)));
        $clean['ep_pad_top_tl']    = max(0, min(120, intval($input['ep_pad_top_tl'] ?? 28)));
        $clean['ep_pad_top_tp']    = max(0, min(120, intval($input['ep_pad_top_tp'] ?? 24)));
        $clean['ep_pad_top_pl']    = max(0, min(120, intval($input['ep_pad_top_pl'] ?? 20)));
        $clean['ep_pad_top_pp']    = max(0, min(120, intval($input['ep_pad_top_pp'] ?? 16)));
        // Padding unten (pro Breakpoint)
        $clean['ep_pad_bottom']    = max(0, min(120, intval($input['ep_pad_bottom'] ?? 64)));
        $clean['ep_pad_bottom_tl'] = max(0, min(120, intval($input['ep_pad_bottom_tl'] ?? 56)));
        $clean['ep_pad_bottom_tp'] = max(0, min(120, intval($input['ep_pad_bottom_tp'] ?? 48)));
        $clean['ep_pad_bottom_pl'] = max(0, min(120, intval($input['ep_pad_bottom_pl'] ?? 40)));
        $clean['ep_pad_bottom_pp'] = max(0, min(120, intval($input['ep_pad_bottom_pp'] ?? 32)));
        // Rückwärtskompatibel: ep_pad_y Fallback
        $clean['ep_pad_y'] = $clean['ep_pad_top'];
        $clean['ep_sticky_offset'] = max(0, min(200, intval($input['ep_sticky_offset'] ?? 56)));
        $clean['ec_page_enabled'] = !empty($input['ec_page_enabled']) ? 1 : 0;
        $clean['ec_pad_x'] = max(0, min(80, intval($input['ec_pad_x'] ?? 32)));
        $clean['ec_pad_y'] = max(0, min(120, intval($input['ec_pad_y'] ?? 56)));
        $clean['ec_max_width']   = max(600, min(2000, intval($input['ec_max_width'] ?? 1200)));
        $clean['ec_show_search'] = !empty($input['ec_show_search']) ? 1 : 0;
        // Event-Seite Zahlen
        $clean['ep_max_width'] = max(400, min(1600, intval($input['ep_max_width'] ?? 1100)));
        $clean['ep_col_split'] = max(50, min(80, intval($input['ep_col_split'] ?? 65)));
        $clean['ep_col_gap']   = max(0, min(100, intval($input['ep_col_gap'] ?? 36)));
        $clean['ep_gap']       = max(0, min(60, intval($input['ep_gap'] ?? 32)));
        $clean['ep_gap_tl']    = max(0, min(60, intval($input['ep_gap_tl'] ?? 28)));
        $clean['ep_gap_tp']    = max(0, min(60, intval($input['ep_gap_tp'] ?? 24)));
        $clean['ep_gap_pl']    = max(0, min(60, intval($input['ep_gap_pl'] ?? 20)));
        $clean['ep_gap_pp']    = max(0, min(60, intval($input['ep_gap_pp'] ?? 16)));
        $clean['ep_radius']    = max(0, min(24, intval($input['ep_radius'] ?? 12)));
        // Event-Seite Ticket-Modus
        $clean['ep_ticket_mode'] = in_array($input['ep_ticket_mode'] ?? '', ['selector', 'modal', 'both']) ? $input['ep_ticket_mode'] : 'selector';
        // Event-Seite Toggles
        foreach (['ep_show_hero', 'ep_show_gallery', 'ep_show_video', 'ep_show_faq', 'ep_show_location', 'ep_show_organizer', 'ep_show_series', 'ep_show_charity', 'ep_show_upsell', 'ep_show_calendar', 'ep_show_phases', 'ep_show_raffle', 'ep_show_share', 'ep_show_timetable', 'ep_sticky_tabs'] as $k) {
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
        // Redirect-Ziel: relativer Pfad oder absolute URL
        $redir = trim((string) ($input['account_activation_redirect'] ?? '/tickets/'));
        if ($redir === '') $redir = '/tickets/';
        $clean['account_activation_redirect'] = (strpos($redir, 'http') === 0) ? esc_url_raw($redir) : $redir;
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
        $clean['ht_version']             = in_array($input['ht_version'] ?? 'v1', ['v1','v2','v3','v4','v5','v6'], true) ? $input['ht_version'] : 'v1';
        $clean['ht_seasonal_enabled']    = !empty($input['ht_seasonal_enabled']) ? 1 : 0;
        $clean['ht_watermark_enabled']   = !empty($input['ht_watermark_enabled']) ? 1 : 0;
        $clean['ht_watermark_color']     = self::sanitize_color($input['ht_watermark_color'] ?? '') ?: '';
        $clean['ht_weather_enabled']     = !empty($input['ht_weather_enabled']) ? 1 : 0;
        $clean['ht_checkin_sound']       = !empty($input['ht_checkin_sound']) ? 1 : 0;
        // Action-Buttons — default 1 (an) bei Fresh-Install, aber beim Save kommt nur was gecheckt ist;
        // Wir interpretieren "nicht im Input" beim ersten Save auch als "nicht an", daher strict 0/1.
        $clean['ht_action_save_image']   = !empty($input['ht_action_save_image']) ? 1 : 0;
        $clean['ht_action_wallets']      = !empty($input['ht_action_wallets']) ? 1 : 0;
        $clean['ht_action_print']        = !empty($input['ht_action_print']) ? 1 : 0;
        $clean['ht_show_order_bundle_btn'] = !empty($input['ht_show_order_bundle_btn']) ? 1 : 0;
        $clean['ht_share_image']         = esc_url_raw($input['ht_share_image'] ?? '');
        $clean['ht_qr_bright_mode']      = !empty($input['ht_qr_bright_mode']) ? 1 : 0;
        $clean['ht_show_event_cover']    = !empty($input['ht_show_event_cover']) ? 1 : 0;
        $clean['ht_show_countdown']      = !empty($input['ht_show_countdown']) ? 1 : 0;
        $clean['ht_show_verified_badge'] = !empty($input['ht_show_verified_badge']) ? 1 : 0;
        $clean['ht_show_agb_footer']     = !empty($input['ht_show_agb_footer']) ? 1 : 0;

        // Ticket-Badge (Check-in + Personenzuordnung)
        foreach (['badge_pending_bg', 'badge_pending_text', 'badge_done_bg', 'badge_done_text'] as $k) {
            $clean[$k] = self::sanitize_color($input[$k] ?? '') ?: $d[$k];
        }

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
        $clean['promoter_page_id']     = max(0, intval($input['promoter_page_id'] ?? 0));
        $clean['promoter_auth_method'] = in_array($input['promoter_auth_method'] ?? '', ['both', 'magic_link', 'wp_login']) ? $input['promoter_auth_method'] : 'both';
        $clean['promoter_default_commission_type']  = in_array($input['promoter_default_commission_type'] ?? '', ['percent', 'fixed']) ? $input['promoter_default_commission_type'] : 'percent';
        $clean['promoter_default_commission_value'] = max(0, floatval(str_replace(',', '.', $input['promoter_default_commission_value'] ?? 0)));
        $clean['promoter_fullscreen'] = !empty($input['promoter_fullscreen']) ? 1 : 0;
        $clean['sponsor_enabled']     = !empty($input['sponsor_enabled']) ? 1 : 0;
        $clean['sponsor_page_id']     = max(0, intval($input['sponsor_page_id'] ?? 0));
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
        if (isset($input['gateway_provider_order'])) {
            // Whitelist auf bekannte Provider, Reihenfolge respektieren
            $allowed_providers = ['stripe', 'mollie', 'paypal', 'bank'];
            $raw = array_filter(array_map('trim', explode(',', (string) $input['gateway_provider_order'])));
            $valid = array_values(array_intersect($raw, $allowed_providers));
            // Fehlende ans Ende ergänzen, damit keiner verloren geht
            foreach ($allowed_providers as $p) {
                if (!in_array($p, $valid, true)) $valid[] = $p;
            }
            $clean['gateway_provider_order'] = implode(',', $valid);
        }
        $clean['mollie_enabled']   = !empty($input['mollie_enabled']) ? 1 : 0;
        $clean['mollie_api_key']   = sanitize_text_field($input['mollie_api_key'] ?? '');
        $clean['mollie_test_mode'] = !empty($input['mollie_test_mode']) ? 1 : 0;
        // Methoden-Filter: nur speichern wenn der Marker mitgeschickt wurde (Tab gerendert wurde)
        if (isset($input['mollie_methods_marker'])) {
            $clean['mollie_enabled_methods'] = is_array($input['mollie_enabled_methods'] ?? null)
                ? array_values(array_map('sanitize_text_field', $input['mollie_enabled_methods']))
                : [];
            $clean['mollie_methods_order'] = sanitize_text_field($input['mollie_methods_order'] ?? '');
        }
        if (isset($input['stripe_methods_marker'])) {
            $clean['stripe_enabled_methods'] = is_array($input['stripe_enabled_methods'] ?? null)
                ? array_values(array_map('sanitize_text_field', $input['stripe_enabled_methods']))
                : [];
            $clean['stripe_methods_order'] = sanitize_text_field($input['stripe_methods_order'] ?? '');
        }
        // Rechnungs-Settings (eigener Sub-Key 'invoicing', vom Invoicing-Tab gerendert)
        if (isset($input['invoicing_marker'])) {
            $inv_in = is_array($input['invoicing'] ?? null) ? $input['invoicing'] : [];
            if (class_exists('TIX_Invoicing')) {
                $clean['invoicing'] = TIX_Invoicing::sanitize_settings($inv_in);
            } else {
                $clean['invoicing'] = $inv_in;
            }
        }
        $clean['stripe_enabled']              = !empty($input['stripe_enabled']) ? 1 : 0;
        $clean['stripe_test_mode']            = !empty($input['stripe_test_mode']) ? 1 : 0;
        $clean['stripe_publishable_key_live'] = sanitize_text_field($input['stripe_publishable_key_live'] ?? '');
        $clean['stripe_secret_key_live']      = sanitize_text_field($input['stripe_secret_key_live']      ?? '');
        $clean['stripe_webhook_secret_live']  = sanitize_text_field($input['stripe_webhook_secret_live']  ?? '');
        $clean['stripe_publishable_key_test'] = sanitize_text_field($input['stripe_publishable_key_test'] ?? '');
        $clean['stripe_secret_key_test']      = sanitize_text_field($input['stripe_secret_key_test']      ?? '');
        $clean['stripe_webhook_secret_test']  = sanitize_text_field($input['stripe_webhook_secret_test']  ?? '');
        // Wenn Settings sich aendern → Methoden-Caches invalidieren
        if (class_exists('TIX_Gateway_Mollie')) TIX_Gateway_Mollie::clear_methods_cache();
        if (class_exists('TIX_Gateway_Stripe')) TIX_Gateway_Stripe::clear_methods_cache();
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

        // Event-Homepage
        $clean['hp_enabled']            = !empty($input['hp_enabled']) ? 1 : 0;
        $clean['hp_hero_count']         = max(0, min(10, intval($input['hp_hero_count'] ?? 5)));
        $clean['hp_grid_count']         = max(4, min(24, intval($input['hp_grid_count'] ?? 8)));
        $clean['hp_show_hero']          = !empty($input['hp_show_hero']) ? 1 : 0;
        $clean['hp_show_time_filter']   = !empty($input['hp_show_time_filter']) ? 1 : 0;
        $clean['hp_show_cat_filter']    = !empty($input['hp_show_cat_filter']) ? 1 : 0;
        $clean['hp_show_load_more']     = !empty($input['hp_show_load_more']) ? 1 : 0;
        $clean['hp_exclude_categories'] = sanitize_text_field($input['hp_exclude_categories'] ?? '');
        $clean['hp_only_featured_hero'] = !empty($input['hp_only_featured_hero']) ? 1 : 0;
        $clean['hp_hero_style']         = in_array($input['hp_hero_style'] ?? 'grid', ['grid', 'slider', 'fullwidth']) ? $input['hp_hero_style'] : 'grid';
        $clean['hp_show_popular']       = !empty($input['hp_show_popular']) ? 1 : 0;
        $clean['hp_popular_count']      = max(2, min(12, intval($input['hp_popular_count'] ?? 4)));
        $clean['hp_show_countdown']     = !empty($input['hp_show_countdown']) ? 1 : 0;
        $clean['hp_url_sync']           = !empty($input['hp_url_sync']) ? 1 : 0;
        $clean['hp_show_newsletter']    = !empty($input['hp_show_newsletter']) ? 1 : 0;
        $clean['hp_newsletter_title']   = sanitize_text_field($input['hp_newsletter_title'] ?? '');
        $clean['hp_newsletter_text']    = sanitize_text_field($input['hp_newsletter_text'] ?? '');
        $clean['hp_newsletter_url']     = esc_url_raw($input['hp_newsletter_url'] ?? '');
        $clean['hp_show_list_toggle']   = !empty($input['hp_show_list_toggle']) ? 1 : 0;
        $clean['hp_show_spotlight']     = !empty($input['hp_show_spotlight']) ? 1 : 0;
        $clean['hp_spotlight_org_id']   = intval($input['hp_spotlight_org_id'] ?? 0);
        $clean['hp_spotlight_count']    = max(1, min(6, intval($input['hp_spotlight_count'] ?? 3)));
        $clean['hp_smart_time']         = !empty($input['hp_smart_time']) ? 1 : 0;
        $clean['hp_max_width']          = max(600, min(1800, intval($input['hp_max_width'] ?? 1200)));
        $clean['hp_pad_x']             = max(0, min(100, intval($input['hp_pad_x'] ?? 0)));
        $clean['hp_pad_y']             = max(0, min(120, intval($input['hp_pad_y'] ?? 0)));
        // Dashboard-Sektionen
        $clean['hp_show_stats_bar']    = !empty($input['hp_show_stats_bar']) ? 1 : 0;
        $clean['hp_show_cat_tiles']    = !empty($input['hp_show_cat_tiles']) ? 1 : 0;
        $clean['hp_show_this_week']    = !empty($input['hp_show_this_week']) ? 1 : 0;
        $clean['hp_this_week_days']    = max(3, min(14, intval($input['hp_this_week_days'] ?? 7)));
        $clean['hp_show_locations']    = !empty($input['hp_show_locations']) ? 1 : 0;
        $clean['hp_locations_count']   = max(3, min(12, intval($input['hp_locations_count'] ?? 6)));
        $clean['hp_section_spacing']   = max(0, min(200, intval($input['hp_section_spacing'] ?? 40)));
        $clean['hp_cache_enabled']     = !empty($input['hp_cache_enabled']) ? 1 : 0;
        $clean['hp_cache_ttl']         = max(60, min(3600, intval($input['hp_cache_ttl'] ?? 600)));

        // ── Phase-2 Sektionen ──
        $clean['hp_show_hero_countdown'] = !empty($input['hp_show_hero_countdown']) ? 1 : 0;
        $clean['hp_countdown_event_id']  = max(0, intval($input['hp_countdown_event_id'] ?? 0));
        $clean['hp_countdown_label']     = sanitize_text_field($input['hp_countdown_label'] ?? 'Next Event');

        $clean['hp_show_last_chance']  = !empty($input['hp_show_last_chance']) ? 1 : 0;
        $clean['hp_last_chance_hours'] = max(6, min(168, intval($input['hp_last_chance_hours'] ?? 48)));
        $clean['hp_last_chance_count'] = max(2, min(8, intval($input['hp_last_chance_count'] ?? 4)));

        $clean['hp_show_weekday_grid'] = !empty($input['hp_show_weekday_grid']) ? 1 : 0;
        $clean['hp_weekday_weeks']     = max(1, min(4, intval($input['hp_weekday_weeks'] ?? 2)));

        $clean['hp_show_voucher']     = !empty($input['hp_show_voucher']) ? 1 : 0;
        $clean['hp_voucher_title']    = sanitize_text_field($input['hp_voucher_title'] ?? '');
        $clean['hp_voucher_text']     = sanitize_text_field($input['hp_voucher_text'] ?? '');
        $clean['hp_voucher_btn']      = sanitize_text_field($input['hp_voucher_btn'] ?? 'Gutschein kaufen');
        $clean['hp_voucher_url']      = esc_url_raw($input['hp_voucher_url'] ?? '');
        $clean['hp_voucher_image_id'] = max(0, intval($input['hp_voucher_image_id'] ?? 0));
        $clean['hp_voucher_bg']       = self::sanitize_color($input['hp_voucher_bg'] ?? '') ?: '#FFE066';
        $clean['hp_voucher_fg']       = self::sanitize_color($input['hp_voucher_fg'] ?? '') ?: '#0D0B09';

        $clean['hp_show_partners']  = !empty($input['hp_show_partners']) ? 1 : 0;
        $clean['hp_partners_title'] = sanitize_text_field($input['hp_partners_title'] ?? '');
        $clean['hp_partners_logos'] = [];
        $logos_json = $input['hp_partners_logos_json'] ?? '';
        if (!empty($logos_json)) {
            $logos_data = json_decode(wp_unslash($logos_json), true);
            if (is_array($logos_data)) {
                foreach ($logos_data as $logo) {
                    if (!is_array($logo) || empty($logo['image_id'])) continue;
                    $clean['hp_partners_logos'][] = [
                        'image_id' => max(0, intval($logo['image_id'])),
                        'link'     => esc_url_raw($logo['link'] ?? ''),
                        'alt'      => sanitize_text_field($logo['alt'] ?? ''),
                    ];
                }
            }
        }

        // ── Phase-3 Sektionen ──
        $clean['hp_show_greeting']  = !empty($input['hp_show_greeting']) ? 1 : 0;
        $clean['hp_show_stories']   = !empty($input['hp_show_stories']) ? 1 : 0;
        $clean['hp_stories_count']  = max(5, min(20, intval($input['hp_stories_count'] ?? 12)));
        $clean['hp_stories_size']   = max(50, min(160, intval($input['hp_stories_size'] ?? 72)));
        $clean['hp_show_promoted']  = !empty($input['hp_show_promoted']) ? 1 : 0;
        $clean['hp_show_favorites'] = !empty($input['hp_show_favorites']) ? 1 : 0;
        $clean['hp_show_recent']    = !empty($input['hp_show_recent']) ? 1 : 0;

        $clean['hp_show_editorial']         = !empty($input['hp_show_editorial']) ? 1 : 0;
        $clean['hp_editorial_event_id']     = max(0, intval($input['hp_editorial_event_id'] ?? 0));
        $clean['hp_editorial_label']        = sanitize_text_field($input['hp_editorial_label'] ?? 'Empfehlung der Redaktion');
        $clean['hp_editorial_title_override'] = sanitize_text_field($input['hp_editorial_title_override'] ?? '');
        $clean['hp_editorial_text']         = wp_kses_post($input['hp_editorial_text'] ?? '');
        $clean['hp_editorial_byline']       = sanitize_text_field($input['hp_editorial_byline'] ?? '');
        $clean['hp_editorial_cta']          = sanitize_text_field($input['hp_editorial_cta'] ?? 'Event ansehen');

        $clean['hp_show_faq']  = !empty($input['hp_show_faq']) ? 1 : 0;
        $clean['hp_faq_title'] = sanitize_text_field($input['hp_faq_title'] ?? 'Häufige Fragen');
        $clean['hp_faq_items'] = [];
        $faq_json = $input['hp_faq_items_json'] ?? '';
        if (!empty($faq_json)) {
            $faq_data = json_decode(wp_unslash($faq_json), true);
            if (is_array($faq_data)) {
                foreach ($faq_data as $faq) {
                    if (!is_array($faq)) continue;
                    $q = sanitize_text_field($faq['question'] ?? '');
                    $a = wp_kses_post($faq['answer'] ?? '');
                    if (!$q || !$a) continue;
                    $clean['hp_faq_items'][] = ['question' => $q, 'answer' => $a];
                }
            }
        }

        // Homepage-Baukasten: Sektions-Reihenfolge + Enabled-Flags (via JSON hidden input)
        $clean['hp_sections'] = [];
        $sec_json = $input['hp_sections_json'] ?? '';
        if (!empty($sec_json)) {
            $sec_data = json_decode(wp_unslash($sec_json), true);
            if (is_array($sec_data) && class_exists('TIX_Event_Homepage')) {
                $registry = TIX_Event_Homepage::get_section_registry();
                $seen = [];
                foreach ($sec_data as $row) {
                    if (!is_array($row)) continue;
                    $id = sanitize_key($row['id'] ?? '');
                    if (!$id || !isset($registry[$id]) || in_array($id, $seen, true)) continue;
                    $seen[] = $id;
                    $sp = isset($row['spacing']) ? intval($row['spacing']) : -1;
                    if ($sp !== -1) $sp = max(0, min(200, $sp));
                    $clean['hp_sections'][] = [
                        'id'      => $id,
                        'enabled' => !empty($row['enabled']),
                        'spacing' => $sp,
                    ];
                }
                // Legacy-Flags spiegeln, damit die bestehenden Cards (Hero-Bereich, Sektionen, etc.)
                // im Einklang mit dem Baukasten bleiben.
                foreach ($clean['hp_sections'] as $row) {
                    $id = $row['id'];
                    $legacy = $registry[$id]['legacy_key'] ?? '';
                    if ($legacy) {
                        $clean[$legacy] = $row['enabled'] ? 1 : 0;
                    }
                }
            }
        }

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
                    if (isset($vals['size']) && $vals['size'] !== '') {
                        $size = intval($vals['size']);
                        if ($size >= 8 && $size <= 60 && $size !== intval($def['size'])) $entry['size'] = $size;
                    }
                    // Responsive Breakpoint Sizes
                    foreach (['size_tl', 'size_tp', 'size_pl', 'size_pp'] as $bp_key) {
                        if (isset($vals[$bp_key]) && $vals[$bp_key] !== '') {
                            $bp_size = intval($vals[$bp_key]);
                            if ($bp_size >= 8 && $bp_size <= 60) $entry[$bp_key] = $bp_size;
                        }
                    }
                    if (isset($vals['font']) && $vals['font'] !== '') {
                        $font = sanitize_text_field($vals['font']);
                        if (in_array($font, $allowed_fonts, true) && $font !== ($def['font'] ?? '')) $entry['font'] = $font;
                    }
                    if (isset($vals['weight']) && $vals['weight'] !== '') {
                        $weight = intval($vals['weight']);
                        if ($weight >= 100 && $weight <= 900 && $weight % 100 === 0 && $weight !== intval($def['weight'])) $entry['weight'] = $weight;
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
                                $entry[$prop] = $val;
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
        // Thank-You-Page
        $clean['ty_back_link_text']       = sanitize_text_field($input['ty_back_link_text'] ?? '← Zurück zu den Events');
        $clean['ty_back_link_url']        = esc_url_raw($input['ty_back_link_url'] ?? '');
        $clean['ty_back_link_show']       = !empty($input['ty_back_link_show']) ? 1 : 0;
        $clean['ty_my_tickets_url']       = esc_url_raw($input['ty_my_tickets_url'] ?? '');
        // Kampagnen-Tracking
        $clean['campaign_tracking_enabled']  = !empty($input['campaign_tracking_enabled']) ? 1 : 0;
        $clean['campaign_cookie_days']       = max(1, min(365, intval($input['campaign_cookie_days'] ?? 30)));
        $clean['campaign_custom_channels']   = sanitize_text_field($input['campaign_custom_channels'] ?? '[]');
        // Meta Ads
        $clean['meta_pixel_enabled']     = !empty($input['meta_pixel_enabled']) ? 1 : 0;
        $clean['meta_pixel_id']          = preg_replace('/[^0-9]/', '', $input['meta_pixel_id'] ?? '');

        // Google Ads / GA4 / GTM Sanitize
        $clean['google_ads_enabled']           = !empty($input['google_ads_enabled']) ? 1 : 0;
        // AW-1234567890 oder nur die Ziffern — wir akzeptieren beides
        $google_ads_id_raw = strtoupper(trim($input['google_ads_id'] ?? ''));
        $google_ads_id_raw = preg_replace('/[^A-Z0-9-]/', '', $google_ads_id_raw);
        if ($google_ads_id_raw && !str_starts_with($google_ads_id_raw, 'AW-')) {
            // Nur Zahlen → automatisch "AW-" prefix
            if (preg_match('/^[0-9]+$/', $google_ads_id_raw)) {
                $google_ads_id_raw = 'AW-' . $google_ads_id_raw;
            }
        }
        $clean['google_ads_id']                = $google_ads_id_raw;
        $clean['google_ads_conversion_label']  = preg_replace('/[^A-Za-z0-9_-]/', '', $input['google_ads_conversion_label'] ?? '');
        $clean['google_ads_send_purchase']     = !empty($input['google_ads_send_purchase']) ? 1 : 0;

        $clean['ga4_enabled']                  = !empty($input['ga4_enabled']) ? 1 : 0;
        $ga4_id = strtoupper(trim($input['ga4_measurement_id'] ?? ''));
        $ga4_id = preg_replace('/[^A-Z0-9-]/', '', $ga4_id);
        if ($ga4_id && !str_starts_with($ga4_id, 'G-')) {
            $ga4_id = 'G-' . $ga4_id;
        }
        $clean['ga4_measurement_id']           = $ga4_id;

        $clean['gtm_enabled']                  = !empty($input['gtm_enabled']) ? 1 : 0;
        $gtm_id = strtoupper(trim($input['gtm_container_id'] ?? ''));
        $gtm_id = preg_replace('/[^A-Z0-9-]/', '', $gtm_id);
        if ($gtm_id && !str_starts_with($gtm_id, 'GTM-')) {
            $gtm_id = 'GTM-' . $gtm_id;
        }
        $clean['gtm_container_id']             = $gtm_id;

        $clean['google_consent_mode']          = in_array($input['google_consent_mode'] ?? 'always', ['always', 'consent_required'], true) ? $input['google_consent_mode'] : 'always';
        $clean['google_consent_cookie']        = sanitize_text_field($input['google_consent_cookie'] ?? 'cookie_consent');

        // Auto-Coupon-Popup
        $clean['coupon_popup_enabled']  = !empty($input['coupon_popup_enabled']) ? 1 : 0;
        $clean['coupon_popup_headline'] = sanitize_text_field($input['coupon_popup_headline'] ?? '');
        $clean['coupon_popup_subtext']  = sanitize_textarea_field($input['coupon_popup_subtext'] ?? '');
        $clean['coupon_popup_cta']      = sanitize_text_field($input['coupon_popup_cta'] ?? '');
        $clean['coupon_popup_cta_url']  = esc_url_raw($input['coupon_popup_cta_url'] ?? '');
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
        $clean['invoice_company_name']      = sanitize_text_field($input['invoice_company_name'] ?? '');
        $clean['invoice_company_ust_id']    = sanitize_text_field($input['invoice_company_ust_id'] ?? '');
        $clean['invoice_managing_director'] = sanitize_text_field($input['invoice_managing_director'] ?? '');
        $clean['invoice_register_court']    = sanitize_text_field($input['invoice_register_court'] ?? '');
        $clean['invoice_register_number']   = sanitize_text_field($input['invoice_register_number'] ?? '');
        $clean['invoice_email']             = sanitize_email($input['invoice_email'] ?? '');
        $clean['invoice_phone']             = sanitize_text_field($input['invoice_phone'] ?? '');
        $clean['invoice_company_address'] = sanitize_textarea_field($input['invoice_company_address'] ?? '');
        $clean['invoice_company_tax_id']  = sanitize_text_field($input['invoice_company_tax_id'] ?? '');
        $clean['invoice_footer_text']     = sanitize_textarea_field($input['invoice_footer_text'] ?? '');

        // Branding
        $clean['branding_enabled'] = !empty($input['branding_enabled']) ? 1 : 0;
        $clean['branding_url']     = esc_url_raw($input['branding_url'] ?? 'https://mdj.events');

        // Fun Mode
        $clean['fun_topbar'] = !empty($input['fun_topbar']) ? 1 : 0;
        $clean['fun_topbar_text'] = sanitize_text_field($input['fun_topbar_text'] ?? '{name}, du bist eine geile Sau');

        // Auto-Archiv
        $clean['auto_archive_enabled'] = !empty($input['auto_archive_enabled']) ? 1 : 0;
        $clean['auto_archive_days']    = max(0, min(365, intval($input['auto_archive_days'] ?? 0)));

        // Auto-Complete
        $clean['auto_complete_enabled'] = !empty($input['auto_complete_enabled']) ? 1 : 0;

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

        // Bot — Appearance
        $clean['bot_avatar_id'] = absint($input['bot_avatar_id'] ?? 0);
        $bot_fonts = ['Inter', 'Sora', 'DM Sans', 'Outfit', 'Poppins', 'Montserrat', 'Open Sans', 'Roboto', 'Lato', 'Nunito', 'Raleway', 'Playfair Display', 'Oswald', 'Source Sans 3', 'Work Sans', 'Manrope', 'Plus Jakarta Sans', 'Figtree'];
        $clean['bot_font'] = in_array($input['bot_font'] ?? 'Inter', $bot_fonts, true) ? $input['bot_font'] : 'Inter';
        $bot_color_keys = ['bot_color_bg', 'bot_color_bg_header', 'bot_color_bg_input', 'bot_color_bg_card', 'bot_color_bg_input_field', 'bot_color_accent', 'bot_color_accent_hover', 'bot_color_text', 'bot_color_text_muted', 'bot_color_text_light', 'bot_color_border', 'bot_color_user_bubble', 'bot_color_user_text'];
        foreach ($bot_color_keys as $ck) {
            $val = self::sanitize_color($input[$ck] ?? '');
            $clean[$ck] = $val !== '' ? $val : ($d[$ck] ?? '');
        }

        // Bot — General
        $clean['bot_enabled']           = !empty($input['bot_enabled']) ? 1 : 0;
        $clean['bot_hub_url']           = esc_url_raw(rtrim($input['bot_hub_url'] ?? '', '/'));
        $clean['bot_hub_master_key']    = sanitize_text_field($input['bot_hub_master_key'] ?? '');
        $clean['bot_hub_admin_key']     = sanitize_text_field($input['bot_hub_admin_key'] ?? '');
        $clean['bot_api_secret']        = sanitize_text_field($input['bot_api_secret'] ?? '');
        $clean['bot_telegram_token']    = sanitize_text_field($input['bot_telegram_token'] ?? '');
        $clean['bot_telegram_enabled']  = !empty($input['bot_telegram_enabled']) ? 1 : 0;
        $clean['bot_whatsapp_token']    = sanitize_text_field($input['bot_whatsapp_token'] ?? '');
        $clean['bot_whatsapp_phone_id'] = sanitize_text_field($input['bot_whatsapp_phone_id'] ?? '');
        $clean['bot_whatsapp_verify']   = sanitize_text_field($input['bot_whatsapp_verify'] ?? '');
        $clean['bot_whatsapp_enabled']  = !empty($input['bot_whatsapp_enabled']) ? 1 : 0;
        $clean['bot_anthropic_key']     = sanitize_text_field($input['bot_anthropic_key'] ?? '');
        $clean['bot_name']              = sanitize_text_field($input['bot_name'] ?? 'Ticket-Assistent');
        $clean['bot_greeting']          = sanitize_textarea_field($input['bot_greeting'] ?? '');
        $clean['bot_personality']       = sanitize_textarea_field($input['bot_personality'] ?? '');
        $clean['bot_webchat_enabled']   = !empty($input['bot_webchat_enabled']) ? 1 : 0;
        // bot_registered and bot_tenant_id are programmatic, preserve existing
        $existing_s = get_option('tix_settings', []);
        $clean['bot_registered']        = intval($input['bot_registered'] ?? $existing_s['bot_registered'] ?? 0);
        $clean['bot_tenant_id']         = sanitize_text_field($input['bot_tenant_id'] ?? $existing_s['bot_tenant_id'] ?? '');

        // ── Wallet (Apple + Google) ──
        $clean['wallet_enabled']               = !empty($input['wallet_enabled']) ? 1 : 0;
        // Apple
        $clean['wallet_apple_enabled']         = !empty($input['wallet_apple_enabled']) ? 1 : 0;
        $clean['wallet_apple_pass_type_id']    = sanitize_text_field($input['wallet_apple_pass_type_id'] ?? '');
        $clean['wallet_apple_team_id']         = sanitize_text_field($input['wallet_apple_team_id'] ?? '');
        $clean['wallet_apple_org_name']        = sanitize_text_field($input['wallet_apple_org_name'] ?? '');
        // Pfade NICHT mit esc_url_raw, das sind Filesystem-Pfade
        $clean['wallet_apple_cert_path']       = sanitize_text_field($input['wallet_apple_cert_path'] ?? '');
        // Passwort: NICHT trimmen/escapen (kann Sonderzeichen enthalten); nur Längenlimit gegen Müll
        $cert_pw = (string) ($input['wallet_apple_cert_password'] ?? '');
        if (strlen($cert_pw) > 256) $cert_pw = substr($cert_pw, 0, 256);
        $clean['wallet_apple_cert_password']   = $cert_pw;
        $clean['wallet_apple_wwdr_path']       = sanitize_text_field($input['wallet_apple_wwdr_path'] ?? '');
        $clean['wallet_apple_logo_url']        = esc_url_raw($input['wallet_apple_logo_url'] ?? '');
        $clean['wallet_apple_icon_url']        = esc_url_raw($input['wallet_apple_icon_url'] ?? '');
        $clean['wallet_apple_strip_url']       = esc_url_raw($input['wallet_apple_strip_url'] ?? '');
        $clean['wallet_apple_bg_color']        = self::sanitize_color($input['wallet_apple_bg_color'] ?? '') ?: '#0f172a';
        $clean['wallet_apple_fg_color']        = self::sanitize_color($input['wallet_apple_fg_color'] ?? '') ?: '#ffffff';
        $clean['wallet_apple_label_color']     = self::sanitize_color($input['wallet_apple_label_color'] ?? '') ?: '#cbd5e1';
        $clean['wallet_apple_relevant_radius'] = max(50, min(2000, intval($input['wallet_apple_relevant_radius'] ?? 200)));
        // Google
        $clean['wallet_google_enabled']        = !empty($input['wallet_google_enabled']) ? 1 : 0;
        $clean['wallet_google_issuer_id']      = preg_replace('/[^0-9]/', '', (string)($input['wallet_google_issuer_id'] ?? ''));
        $clean['wallet_google_service_email']  = sanitize_email($input['wallet_google_service_email'] ?? '');
        // Private Key: Mehrzeilig, KEIN sanitize (zerlegt PEM-Format). Nur Längenlimit + CR-Normalisierung.
        $pk = (string) ($input['wallet_google_service_key'] ?? '');
        $pk = str_replace("\r\n", "\n", $pk);
        $pk = str_replace("\r", "\n", $pk);
        if (strlen($pk) > 16384) $pk = substr($pk, 0, 16384);
        $clean['wallet_google_service_key']    = $pk;
        $clean['wallet_google_class_suffix']   = sanitize_key($input['wallet_google_class_suffix'] ?? 'tixomat-event-ticket');
        $clean['wallet_google_logo_url']       = esc_url_raw($input['wallet_google_logo_url'] ?? '');
        $clean['wallet_google_hero_url']       = esc_url_raw($input['wallet_google_hero_url'] ?? '');
        $clean['wallet_google_bg_color']       = self::sanitize_color($input['wallet_google_bg_color'] ?? '') ?: '#0f172a';

        // Veranstalter-Landing Feature-Flags (werden auch von separater Admin-Seite gesetzt,
        // dürfen nicht durch Settings-Save gestrippt werden)
        $clean['landing_pages_enabled'] = !empty($input['landing_pages_enabled']) ? 1 : (intval($existing_s['landing_pages_enabled'] ?? 0));
        $clean['landing_use_subdomain'] = !empty($input['landing_use_subdomain']) ? 1 : (intval($existing_s['landing_use_subdomain'] ?? 0));
        $clean['stats_source']          = sanitize_text_field($input['stats_source'] ?? $existing_s['stats_source'] ?? 'auto');

        // Preserve unknown keys (forward-compat): alle Werte aus existing_s die nicht in $clean landeten
        if (is_array($existing_s)) {
            foreach ($existing_s as $k => $v) {
                if (!array_key_exists($k, $clean) && strpos($k, 'landing_') === 0) {
                    $clean[$k] = $v;
                }
            }
        }

        // LiteSpeed Cache purgen damit CSS-Änderungen sofort greifen
        if (class_exists('LiteSpeed\Purge')) {
            \LiteSpeed\Purge::purge_all();
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
        // sel_*-Werte nur ausgeben wenn sie gesetzt UND abweichend vom globalen Pendant sind
        $sel_vars = [];
        if (!empty($s['sel_bg']))            $sel_vars[] = '--tix-sel-cat-bg: ' . $s['sel_bg'];
        $sel_brd = $s['sel_border_color'] ?? '';
        if ($sel_brd !== '' && $sel_brd !== ($s['color_border'] ?? '')) {
            $sel_vars[] = '--tix-cat-border: ' . $sel_brd;
        }
        $sel_act = $s['sel_active_border'] ?? '';
        if ($sel_act !== '' && $sel_act !== ($s['color_focus'] ?? '') && $sel_act !== ($s['color_primary'] ?? '')) {
            $sel_vars[] = '--tix-sel-active-border: ' . $sel_act;
        }
        if (!empty($s['sel_active_bg']))     $sel_vars[] = '--tix-sel-active-bg: ' . $s['sel_active_bg'];
        $sel_hvr = $s['sel_hover_text'] ?? '';
        if ($sel_hvr !== '' && $sel_hvr !== ($s['color_border'] ?? '')) {
            $sel_vars[] = '--tix-sel-hover-text: ' . $sel_hvr;
        }
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
        $col_split = intval($s['ep_col_split'] ?? 65);
        if ($col_split !== 65) {
            $side = 100 - $col_split;
            $ep_vars[] = '--ep-col-split: ' . $col_split . 'fr ' . $side . 'fr';
        }
        $col_gap = intval($s['ep_col_gap'] ?? 36);
        $ep_vars[] = '--ep-col-gap: ' . $col_gap . 'px';
        if ((int)$s['ep_radius'] !== 12)     $ep_vars[] = '--ep-radius: ' . intval($s['ep_radius']) . 'px';
        if (!empty($s['ep_bg']))             $ep_vars[] = '--ep-bg: ' . $s['ep_bg'];
        if (!empty($s['ep_text']))           $ep_vars[] = '--ep-text: ' . $s['ep_text'];
        if (!empty($s['ep_muted']))          $ep_vars[] = '--ep-muted: ' . $s['ep_muted'];
        if (!empty($s['ep_border']))         $ep_vars[] = '--ep-border: ' . $s['ep_border'];
        // Padding + Sticky-Vars (Desktop)
        $ep_vars[] = '--tse-pad-x: ' . intval($s['ep_pad_x'] ?? 32) . 'px';
        $ep_vars[] = '--tse-pad-top: ' . intval($s['ep_pad_top'] ?? 32) . 'px';
        $ep_vars[] = '--tse-pad-bottom: ' . intval($s['ep_pad_bottom'] ?? 64) . 'px';
        $ep_vars[] = '--ep-gap: ' . intval($s['ep_gap'] ?? 32) . 'px';
        $ep_vars[] = '--tse-sticky-offset: ' . intval($s['ep_sticky_offset'] ?? 56) . 'px';
        $desktop_gap = intval($s['ep_card_gap'] ?? 0);
        $ep_vars[] = '--tse-card-gap: ' . $desktop_gap . 'px';
        $ep_vars[] = '--tse-card-pad: ' . intval($s['ep_card_pad'] ?? 28) . 'px';
        // Wenn Karten-Abstand > 0: Radius + Border wiederherstellen
        if ($desktop_gap > 0) {
            $ep_vars[] = '--tse-card-radius: var(--tix-card-radius, 16px)';
            $ep_vars[] = '--tse-card-border: 1px solid var(--tix-card-sand, #E3DED4)';
        }
        if (!empty($ep_vars)) {
            echo ".tix-ep, .tse-wrap {\n    " . implode(";\n    ", $ep_vars) . ";\n}\n";
        }
        // Responsive Breakpoints (alle Variablen pro Breakpoint)
        $resp_bps = [
            ['width' => 1119, 'suffix' => '_tl'],
            ['width' => 1023, 'suffix' => '_tp'],
            ['width' => 767,  'suffix' => '_pl'],
            ['width' => 479,  'suffix' => '_pp'],
        ];
        foreach ($resp_bps as $bp) {
            $sfx = $bp['suffix'];
            $vars = [];
            $vars[] = '--tse-pad-x:' . intval($s["ep_pad_x{$sfx}"] ?? 20) . 'px';
            $vars[] = '--tse-pad-top:' . intval($s["ep_pad_top{$sfx}"] ?? 24) . 'px';
            $vars[] = '--tse-pad-bottom:' . intval($s["ep_pad_bottom{$sfx}"] ?? 48) . 'px';
            $vars[] = '--ep-gap:' . intval($s["ep_gap{$sfx}"] ?? 24) . 'px';
            $gap_val = intval($s["ep_card_gap{$sfx}"] ?? 0);
            $vars[] = '--tse-card-gap:' . $gap_val . 'px';
            $vars[] = '--tse-card-pad:' . intval($s["ep_card_pad{$sfx}"] ?? 20) . 'px';
            if ($gap_val > 0) {
                $vars[] = '--tse-card-radius:var(--tix-card-radius, 16px)';
                $vars[] = '--tse-card-border:1px solid var(--tix-card-sand, #E3DED4)';
            } else {
                $vars[] = '--tse-card-radius:0px';
                $vars[] = '--tse-card-border:none';
            }
            echo "@media(max-width:{$bp['width']}px){.tix-ep,.tse-wrap{" . implode(';', $vars) . "}}\n";
        }
        // Sticky Tabs deaktivieren → komplett ausblenden
        if (empty($s['ep_sticky_tabs'])) {
            echo ".tse-tabs { display: none !important; }\n";
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

        // WooCommerce Klassen → echte CSS-Selektoren (für Typo + Farben)
        $wc_selector_map = [
                'wc-nav-link'     => '.woocommerce-MyAccount-navigation ul li a',
                'wc-content-h2'   => '.woocommerce-MyAccount-content h2',
                'wc-content-h3'   => '.woocommerce-MyAccount-content h3',
                'wc-table-th'     => '.woocommerce-MyAccount-content table th',
                'wc-table-td'     => '.woocommerce-MyAccount-content table td',
                'wc-order-status' => '.woocommerce-MyAccount-content .woocommerce-orders-table .woocommerce-orders-table__cell-order-status',
                'wc-button'       => '.woocommerce-MyAccount-content .woocommerce-Button, .woocommerce-MyAccount-content .button',
                'wc-label'        => '.woocommerce-MyAccount-content label',
                'wc-input'        => '.woocommerce-MyAccount-content input[type="text"], .woocommerce-MyAccount-content input[type="email"], .woocommerce-MyAccount-content input[type="tel"], .woocommerce-MyAccount-content select, .woocommerce-MyAccount-content textarea',
                'wc-message'      => '.woocommerce-message, .woocommerce-info, .woocommerce-error',
        ];

        // ── Per-Class Typografie: ALLE Klassen explizit ausgeben (Override oder Default) ──
        $typo_classes = $s['tix_typo_classes'] ?? [];
        if (!is_array($typo_classes)) $typo_classes = [];
        $registry_flat = self::typo_class_registry_flat();

        $bp_map = [
            'size_tl' => 1119,
            'size_tp' => 1023,
            'size_pl' => 767,
            'size_pp' => 479,
        ];
        $bp_rules = [];

        foreach ($registry_flat as $cls => $def) {
            $vals = $typo_classes[$cls] ?? [];
            $selector = $wc_selector_map[$cls] ?? '.' . $cls;

            // Effektive Werte: Override wenn vorhanden, sonst Registry-Default
            $eff_size   = isset($vals['size'])   ? intval($vals['size'])   : $def['size'];
            $eff_font   = isset($vals['font'])   ? $vals['font']          : $def['font'];
            $eff_weight = isset($vals['weight']) ? intval($vals['weight']) : $def['weight'];

            $props = [];
            $props[] = 'font-size: ' . $eff_size . 'px !important';
            if ($eff_font === 'heading') {
                $props[] = "font-family: '{$typo_heading}', sans-serif !important";
            } elseif ($eff_font === 'body') {
                $props[] = "font-family: '{$typo_body}', sans-serif !important";
            } else {
                $f = esc_attr($eff_font);
                $props[] = "font-family: '{$f}', sans-serif !important";
            }
            $props[] = 'font-weight: ' . $eff_weight . ' !important';
            echo $selector . " { " . implode('; ', $props) . "; }\n";

            // Responsive Breakpoint font-sizes (nur wenn Override gesetzt)
            foreach ($bp_map as $bp_key => $width) {
                if (isset($vals[$bp_key])) {
                    $bp_rules[$width][] = $selector . ' { font-size: ' . intval($vals[$bp_key]) . 'px !important; }';
                }
            }
        }

        // Media-Queries ausgeben
        krsort($bp_rules);
        foreach ($bp_rules as $width => $rules) {
            echo "@media(max-width:{$width}px){\n" . implode("\n", $rules) . "\n}\n";
        }

        // ── Per-Class Farben Overrides ──
        $color_classes = $s['tix_color_classes'] ?? [];
        if (!empty($color_classes) && is_array($color_classes)) {
            $color_registry_flat = self::color_class_registry_flat();
            $css_prop_map = ['color' => 'color', 'bg' => 'background-color', 'border' => 'border-color'];
            foreach ($color_classes as $cls => $vals) {
                if (!isset($color_registry_flat[$cls]) || !is_array($vals)) continue;
                $defaults = $color_registry_flat[$cls]['props'] ?? [];
                $selector = $wc_selector_map[$cls] ?? '.' . $cls;
                $props = [];
                foreach ($css_prop_map as $key => $css_prop) {
                    if (isset($vals[$key])) {
                        // Nur ausgeben wenn vom Default abweichend
                        if (isset($defaults[$key]) && strtolower($vals[$key]) === strtolower($defaults[$key])) continue;
                        $props[] = $css_prop . ': ' . esc_attr($vals[$key]) . ' !important';
                    }
                }
                if (!empty($props)) {
                    echo $selector . " { " . implode('; ', $props) . "; }\n";
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

            <?php // ── Permanent-Banner für Wallet (NEU) — bis konfiguriert ── ?>
            <?php if (empty($s['wallet_enabled'])): ?>
            <div id="tix-wallet-promo-banner" style="background:linear-gradient(90deg,#0f172a,#1e293b);color:#fff;padding:14px 20px;border-radius:12px;margin:12px 0 16px;display:flex;align-items:center;gap:14px;box-shadow:0 4px 12px rgba(15,23,42,0.15);">
                <div style="font-size:32px;line-height:1;">🪪</div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:14px;font-weight:700;margin-bottom:2px;">
                        <span style="background:#FF5500;color:#fff;font-size:9px;padding:2px 6px;border-radius:6px;letter-spacing:0.05em;margin-right:6px;vertical-align:middle;">NEU</span>
                        Apple Wallet &amp; Google Wallet sind jetzt einrichtbar
                    </div>
                    <div style="font-size:12px;opacity:0.75;line-height:1.5;">Tickets als Pass auf iPhone &amp; Android — Lock-Screen-Push, Geo-Reminder, kein PDF-Download mehr nötig.</div>
                </div>
                <button type="button" onclick="document.querySelector('.tix-nav-tab[data-tab=wallet]').click(); document.getElementById('tix-wallet-promo-banner').scrollIntoView({behavior:'smooth',block:'start'});"
                        style="background:#FF5500;color:#fff;border:none;padding:10px 18px;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap;">
                    🚀 Jetzt einrichten
                </button>
            </div>
            <?php endif; ?>

            <form method="post" action="options.php" id="tix-settings-form">
                <?php settings_fields('tix_settings_group'); ?>

                <div class="tix-settings-grid">

                    <?php // ═════════════ LEFT: TABBED SETTINGS ═════════════ ?>
                    <div class="tix-app tix-settings-app">

                        <?php // ── SEARCH BAR ── ?>
                        <div class="tix-settings-search-wrap" id="tix-search-wrap">
                            <span class="dashicons dashicons-search tix-search-icon"></span>
                            <input type="text" id="tix-settings-search" class="tix-settings-search" placeholder="Einstellungen durchsuchen…" autocomplete="off" />
                            <span class="tix-search-clear" id="tix-search-clear" title="Suche leeren">&times;</span>
                            <div class="tix-search-results" id="tix-search-results"></div>
                        </div>

                        <?php // ── TAB NAVIGATION ── ?>
                        <nav class="tix-nav">
                            <button type="button" class="tix-nav-tab" data-tab="wallet" style="position:relative;">
                                <span class="dashicons dashicons-id-alt"></span>
                                <span class="tix-nav-label">Wallet</span>
                                <span style="position:absolute;top:-4px;right:-4px;background:#FF5500;color:#fff;font-size:9px;font-weight:700;padding:2px 5px;border-radius:8px;line-height:1;letter-spacing:0.04em;">NEU</span>
                            </button>
                            <button type="button" class="tix-nav-tab" data-tab="invoice" style="position:relative;">
                                <span class="dashicons dashicons-media-document"></span>
                                <span class="tix-nav-label">Rechnungen</span>
                                <span style="position:absolute;top:-4px;right:-4px;background:#FF5500;color:#fff;font-size:9px;font-weight:700;padding:2px 5px;border-radius:8px;line-height:1;letter-spacing:0.04em;">NEU</span>
                            </button>
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
                            <button type="button" class="tix-nav-tab" data-tab="bot">
                                <span class="dashicons dashicons-format-chat"></span>
                                <span class="tix-nav-label">Ticket-Bot</span>
                            </button>
                            <button type="button" class="tix-nav-tab" data-tab="event-homepage">
                                <span class="dashicons dashicons-admin-home"></span>
                                <span class="tix-nav-label">Event-Homepage</span>
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
                                    <span class="dashicons dashicons-chart-area"></span>
                                    <span class="tix-nav-label">Tracking-Pixel</span>
                                </button>
                                <button type="button" class="tix-nav-tab" data-tab="export-import">
                                    <span class="dashicons dashicons-database-export"></span>
                                    <span class="tix-nav-label">Export / Import</span>
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
                                        <p class="tix-settings-hint" style="margin-bottom:12px;">Setzt alle Farben des Plugins auf einmal. Einzelne Farben k&ouml;nnen danach manuell angepasst werden.</p>
                                        <input type="hidden" name="<?php echo $ok; ?>[theme_mode]" id="tix-theme-mode" value="<?php echo esc_attr($s['theme_mode'] ?: 'light'); ?>">
                                        <div class="tix-theme-presets" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:10px;">
                                            <?php
                                            $presets_ui = [
                                                'light'     => ['Light',          ['#ffffff','#1e293b','#3b82f6','#EDE9E0','#22c55e','#FAF8F4']],
                                                'dark'      => ['Dark',           ['#111111','#c8ff00','#c8ff00','#333333','#4caf50','#1a1a1a']],
                                                'festival'  => ['Festival',       ['#0f0f1a','#FF5500','#e94560','#2a2a3a','#4caf50','#1a1a2e']],
                                                'corporate' => ['Corporate',      ['#f8fafc','#1e293b','#3b82f6','#e2e8f0','#22c55e','#ffffff']],
                                                'elegant'   => ['Elegant',        ['#f5f0e8','#2d2d2d','#c9a84c','#d4c9b5','#4caf50','#ffffff']],
                                                'neon'      => ['Neon',           ['#0a0a0a','#ff00ff','#00ffff','#333333','#c8ff00','#1a1a1a']],
                                                'warm'      => ['Warm',           ['#FFF8F0','#8B4513','#D2691E','#E8D5C0','#22c55e','#ffffff']],
                                                'ocean'     => ['Ocean',          ['#f0f7ff','#0c4a6e','#0891b2','#bae6fd','#22c55e','#ffffff']],
                                            ];
                                            $current = $s['theme_mode'] ?? 'light';
                                            foreach ($presets_ui as $key => $info):
                                                $colors = $info[1];
                                            ?>
                                            <button type="button" class="tix-ci-theme-btn<?php echo $current === $key ? ' active' : ''; ?>" data-theme="<?php echo $key; ?>" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:10px 8px;border:2px solid <?php echo $current === $key ? 'var(--tix-primary,#6366f1)' : '#d1d5db'; ?>;border-radius:10px;background:#fff;cursor:pointer;transition:border-color .2s;">
                                                <span style="display:flex;gap:2px;">
                                                    <?php foreach ($colors as $c): ?>
                                                    <span style="width:16px;height:16px;border-radius:50%;background:<?php echo $c; ?>;border:1px solid rgba(0,0,0,.1);"></span>
                                                    <?php endforeach; ?>
                                                </span>
                                                <span style="font-size:11px;font-weight:600;color:#374151;"><?php echo $info[0]; ?></span>
                                            </button>
                                            <?php endforeach; ?>
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
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label" for="tix-account-redirect">Redirect nach Account-Aktivierung</label>
                                                <input type="text" id="tix-account-redirect"
                                                       name="<?php echo self::OPTION_KEY; ?>[account_activation_redirect]"
                                                       value="<?php echo esc_attr($s['account_activation_redirect'] ?? '/tickets/'); ?>"
                                                       class="regular-text"
                                                       placeholder="/tickets/"
                                                       style="max-width:400px;font-family:monospace;">
                                                <p class="tix-field-hint">Relativer Pfad (z.B. <code>/account/</code>) oder absolute URL. Greift wenn ein Kunde nach dem Kauf sein Passwort über den Aktivierungs-Link in der Bestätigungsmail setzt.</p>
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

                                            <script>
                                            jQuery(function($){
                                                function syncOrder($list){
                                                    var ids = $list.find('> li').map(function(){ return $(this).data('id'); }).get();
                                                    $($list.data('target')).val(ids.join(','));
                                                }
                                                $('.tix-gw-sortable').each(function(){
                                                    var $list = $(this);
                                                    $list.sortable({
                                                        handle: '.tix-drag-handle',
                                                        placeholder: 'tix-gw-placeholder',
                                                        update: function(){ syncOrder($list); }
                                                    });
                                                });
                                                // Sicherheitsnetz: vor Submit alle Listen explizit syncen
                                                $('#tix-settings-form').on('submit', function(){
                                                    $('.tix-gw-sortable').each(function(){ syncOrder($(this)); });
                                                });
                                            });
                                            </script>
                                            <?php
                                            wp_enqueue_script('jquery-ui-sortable');
                                            $provider_labels = [
                                                'stripe' => ['label' => '💳 Stripe',          'desc' => 'Karte, Klarna, Apple/Google Pay, SEPA, ...'],
                                                'mollie' => ['label' => '🇪🇺 Mollie',          'desc' => 'Karte, Klarna, iDEAL, Bancontact, Billie, ...'],
                                                'paypal' => ['label' => '🅿️ PayPal',           'desc' => 'Direkter PayPal-Account-Login'],
                                                'bank'   => ['label' => '🏦 Banküberweisung', 'desc' => 'Vorkasse (Order pending bis Zahlungseingang)'],
                                            ];
                                            $provider_order = array_filter(array_map('trim', explode(',', (string) ($s['gateway_provider_order'] ?? 'stripe,mollie,paypal,bank'))));
                                            // Unbekannte oder fehlende ans Ende
                                            foreach (array_keys($provider_labels) as $p) {
                                                if (!in_array($p, $provider_order, true)) $provider_order[] = $p;
                                            }
                                            ?>
                                            <div class="tix-field tix-field-full">
                                                <label style="font-weight:600;display:block;margin-bottom:6px;">Reihenfolge der Anbieter im Checkout</label>
                                                <input type="hidden" id="tix-provider-order" name="tix_settings[gateway_provider_order]" value="<?php echo esc_attr(implode(',', $provider_order)); ?>">
                                                <ul id="tix-provider-sortable" class="tix-gw-sortable" data-target="#tix-provider-order" style="list-style:none;margin:0;padding:8px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;display:flex;flex-direction:column;gap:4px;">
                                                    <?php foreach ($provider_order as $p):
                                                        if (!isset($provider_labels[$p])) continue;
                                                        $info = $provider_labels[$p];
                                                    ?>
                                                        <li data-id="<?php echo esc_attr($p); ?>" style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;cursor:move;">
                                                            <span class="tix-drag-handle" style="color:#9ca3af;font-size:18px;line-height:1;user-select:none;flex-shrink:0;" title="Ziehen zum Sortieren">⋮⋮</span>
                                                            <div>
                                                                <div style="font-weight:600;font-size:13px;color:#0f172a;"><?php echo esc_html($info['label']); ?></div>
                                                                <div style="font-size:11px;color:#64748b;"><?php echo esc_html($info['desc']); ?></div>
                                                            </div>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                                <p class="tix-settings-hint" style="margin-top:6px;">Bestimmt die Reihenfolge, in der die Anbieter-Methoden im Checkout untereinander stehen. Innerhalb jedes Anbieters gilt zusätzlich die Methoden-Reihenfolge weiter unten.</p>
                                            </div>

                                            <div class="tix-field tix-field-full" style="border-top:1px solid #e5e7eb;padding-top:14px;margin-top:6px;">
                                                <strong style="display:block;margin-bottom:8px;color:#0f172a;">🇪🇺 Mollie</strong>
                                                <?php self::checkbox_row('mollie_enabled', 'Mollie aktivieren (im Checkout anzeigen)', $s); ?>
                                            </div>
                                            <?php self::text_row('mollie_api_key', 'Mollie API Key', $s, 'live_... oder test_...'); ?>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('mollie_test_mode', 'Mollie Test-Modus', $s); ?>
                                                <p class="tix-settings-hint"><a href="https://my.mollie.com/dashboard/developers/api-keys" target="_blank">Mollie Keys →</a></p>
                                            </div>
                                            <?php
                                            // Mollie-Methoden-Auswahl (nur wenn Key konfiguriert)
                                            if (!empty($s['mollie_api_key']) && class_exists('TIX_Gateway_Mollie')) {
                                                wp_enqueue_script('jquery-ui-sortable');
                                                $reload = !empty($_GET['tix_mollie_reload']);
                                                if ($reload) TIX_Gateway_Mollie::clear_methods_cache();
                                                $mollie_methods  = TIX_Gateway_Mollie::get_methods($reload);
                                                $mollie_selected = is_array($s['mollie_enabled_methods'] ?? null) ? $s['mollie_enabled_methods'] : [];
                                                $mollie_order    = array_filter(array_map('trim', explode(',', (string) ($s['mollie_methods_order'] ?? ''))));
                                                // Methoden nach gespeicherter Reihenfolge sortieren, neue ans Ende
                                                $mollie_sorted = self::sort_methods_by_order($mollie_methods, $mollie_order);
                                                $reload_url = wp_nonce_url(add_query_arg('tix_mollie_reload', '1'), 'tix_mollie_reload');
                                                ?>
                                                <div class="tix-field tix-field-full">
                                                    <label style="font-weight:600;display:block;margin-bottom:6px;">Im Checkout angezeigte Methoden & Reihenfolge</label>
                                                    <input type="hidden" name="tix_settings[mollie_methods_marker]" value="1">
                                                    <input type="hidden" id="tix-mollie-order" name="tix_settings[mollie_methods_order]" value="<?php echo esc_attr(implode(',', wp_list_pluck($mollie_sorted, 'id'))); ?>">
                                                    <?php if (empty($mollie_methods)): ?>
                                                        <p style="color:#92400e;background:#fef3c7;padding:10px 14px;border-radius:8px;font-size:13px;">Keine Methoden gefunden. Aktiviere sie zuerst im Mollie-Dashboard und <a href="<?php echo esc_url($reload_url); ?>">klicke hier zum Neuladen</a>.</p>
                                                    <?php else: ?>
                                                        <ul id="tix-mollie-sortable" class="tix-gw-sortable" data-target="#tix-mollie-order" style="list-style:none;margin:0;padding:8px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;display:flex;flex-direction:column;gap:4px;">
                                                            <?php foreach ($mollie_sorted as $m): $checked = in_array($m['id'], $mollie_selected, true); ?>
                                                                <li data-id="<?php echo esc_attr($m['id']); ?>" style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;cursor:move;">
                                                                    <span class="tix-drag-handle" style="color:#9ca3af;font-size:18px;line-height:1;user-select:none;flex-shrink:0;" title="Ziehen zum Sortieren">⋮⋮</span>
                                                                    <input type="checkbox" name="tix_settings[mollie_enabled_methods][]" value="<?php echo esc_attr($m['id']); ?>" <?php checked($checked); ?>>
                                                                    <?php if (!empty($m['image'])): ?><img src="<?php echo esc_url($m['image']); ?>" alt="" style="height:20px;max-width:40px;object-fit:contain;flex-shrink:0;"><?php endif; ?>
                                                                    <span style="font-size:13px;"><?php echo esc_html($m['description']); ?></span>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                        <p class="tix-settings-hint" style="margin-top:6px;">Ziehen zum Sortieren · Häkchen für Anzeige im Checkout. <strong>Leer = alle anzeigen.</strong> · <a href="<?php echo esc_url($reload_url); ?>">Vom Mollie-Dashboard neu laden</a></p>
                                                    <?php endif; ?>
                                                </div>
                                                <?php
                                            }
                                            ?>

                                            <div class="tix-field tix-field-full" style="border-top:1px solid #e5e7eb;padding-top:14px;margin-top:6px;">
                                                <strong style="display:block;margin-bottom:8px;color:#0f172a;">💳 Stripe</strong>
                                                <?php self::checkbox_row('stripe_enabled', 'Stripe aktivieren (im Checkout anzeigen)', $s); ?>
                                                <?php self::checkbox_row('stripe_test_mode', 'Test-Modus (sk_test_/pk_test_-Keys nutzen)', $s); ?>
                                            </div>
                                            <?php self::text_row('stripe_publishable_key_live', 'Stripe Publishable Key (Live)', $s, 'pk_live_...'); ?>
                                            <?php self::text_row('stripe_secret_key_live',      'Stripe Secret Key (Live)',      $s, 'sk_live_...'); ?>
                                            <?php self::text_row('stripe_webhook_secret_live',  'Stripe Webhook Secret (Live)',  $s, 'whsec_...'); ?>
                                            <?php self::text_row('stripe_publishable_key_test', 'Stripe Publishable Key (Test)', $s, 'pk_test_...'); ?>
                                            <?php self::text_row('stripe_secret_key_test',      'Stripe Secret Key (Test)',      $s, 'sk_test_...'); ?>
                                            <?php self::text_row('stripe_webhook_secret_test',  'Stripe Webhook Secret (Test)',  $s, 'whsec_...'); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">
                                                    <a href="https://dashboard.stripe.com/apikeys" target="_blank">Stripe Keys →</a> ·
                                                    Webhook-URL für Stripe-Dashboard:
                                                    <code style="display:inline-block;padding:2px 8px;background:#f1f5f9;border-radius:4px;font-size:12px;"><?php echo esc_html(admin_url('admin-ajax.php?action=tix_stripe_webhook')); ?></code>
                                                    — Events: <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code>, <code>checkout.session.async_payment_failed</code>, <code>charge.refunded</code>
                                                </p>
                                            </div>
                                            <?php
                                            // Stripe-Methoden-Auswahl
                                            $stripe_secret = !empty($s['stripe_test_mode']) ? ($s['stripe_secret_key_test'] ?? '') : ($s['stripe_secret_key_live'] ?? '');
                                            if (!empty($s['stripe_enabled']) && !empty($stripe_secret) && class_exists('TIX_Gateway_Stripe')) {
                                                wp_enqueue_script('jquery-ui-sortable');
                                                $reload = !empty($_GET['tix_stripe_reload']);
                                                if ($reload) TIX_Gateway_Stripe::clear_methods_cache();
                                                $stripe_methods  = TIX_Gateway_Stripe::get_methods($reload);
                                                $stripe_selected = is_array($s['stripe_enabled_methods'] ?? null) ? $s['stripe_enabled_methods'] : [];
                                                $stripe_order    = array_filter(array_map('trim', explode(',', (string) ($s['stripe_methods_order'] ?? ''))));
                                                $stripe_sorted   = self::sort_methods_by_order($stripe_methods, $stripe_order);
                                                $reload_url = wp_nonce_url(add_query_arg('tix_stripe_reload', '1'), 'tix_stripe_reload');
                                                ?>
                                                <div class="tix-field tix-field-full">
                                                    <label style="font-weight:600;display:block;margin-bottom:6px;">Im Checkout angezeigte Stripe-Methoden & Reihenfolge</label>
                                                    <input type="hidden" name="tix_settings[stripe_methods_marker]" value="1">
                                                    <input type="hidden" id="tix-stripe-order" name="tix_settings[stripe_methods_order]" value="<?php echo esc_attr(implode(',', wp_list_pluck($stripe_sorted, 'id'))); ?>">
                                                    <?php if (empty($stripe_methods)): ?>
                                                        <p style="color:#92400e;background:#fef3c7;padding:10px 14px;border-radius:8px;font-size:13px;">Keine Methoden gefunden. Aktiviere sie zuerst im Stripe-Dashboard und <a href="<?php echo esc_url($reload_url); ?>">klicke hier zum Neuladen</a>.</p>
                                                    <?php else: ?>
                                                        <ul id="tix-stripe-sortable" class="tix-gw-sortable" data-target="#tix-stripe-order" style="list-style:none;margin:0;padding:8px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;display:flex;flex-direction:column;gap:4px;">
                                                            <?php foreach ($stripe_sorted as $m): $checked = in_array($m['id'], $stripe_selected, true); ?>
                                                                <li data-id="<?php echo esc_attr($m['id']); ?>" style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;cursor:move;">
                                                                    <span class="tix-drag-handle" style="color:#9ca3af;font-size:18px;line-height:1;user-select:none;flex-shrink:0;" title="Ziehen zum Sortieren">⋮⋮</span>
                                                                    <input type="checkbox" name="tix_settings[stripe_enabled_methods][]" value="<?php echo esc_attr($m['id']); ?>" <?php checked($checked); ?>>
                                                                    <?php if (!empty($m['image'])): ?><img src="<?php echo esc_url($m['image']); ?>" alt="" style="height:20px;max-width:40px;object-fit:contain;flex-shrink:0;"><?php endif; ?>
                                                                    <span style="font-size:13px;"><?php echo esc_html($m['label']); ?></span>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                        <p class="tix-settings-hint" style="margin-top:6px;">Ziehen zum Sortieren · Häkchen für Anzeige im Checkout. <strong>Leer = alle anzeigen.</strong> · <a href="<?php echo esc_url($reload_url); ?>">Vom Stripe-Dashboard neu laden</a></p>
                                                    <?php endif; ?>
                                                </div>
                                                <style>.tix-gw-placeholder{height:42px;background:#eef2ff;border:2px dashed #c7d2fe;border-radius:6px;margin:0;}</style>
                                                <?php
                                            }
                                            ?>
                                            <?php self::text_row('paypal_client_id', 'PayPal Client ID', $s, 'A...'); ?>
                                            <?php self::text_row('paypal_secret', 'PayPal Secret', $s, 'E...'); ?>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('paypal_sandbox', 'PayPal Sandbox', $s); ?>
                                                <?php
                                                // Test-Ergebnis aus vorherigem Klick anzeigen
                                                $pp_test_result = get_transient('tix_paypal_test_result_' . get_current_user_id());
                                                if ($pp_test_result) delete_transient('tix_paypal_test_result_' . get_current_user_id());
                                                $test_url = wp_nonce_url(
                                                    admin_url('admin.php?page=tix-settings&tix_paypal_test=1'),
                                                    'tix_paypal_test_url'
                                                );
                                                ?>
                                                <p class="tix-settings-hint">
                                                    <a href="https://developer.paypal.com/dashboard/applications" target="_blank">PayPal Dashboard →</a>
                                                    &nbsp;·&nbsp;
                                                    <a href="<?php echo esc_url($test_url); ?>" class="button" id="tix-paypal-test-result" style="margin-left:8px;">Verbindung testen</a>
                                                    <?php if ($pp_test_result): ?>
                                                        <span style="margin-left:10px; color: <?php echo $pp_test_result['success'] ? '#10b981' : '#ef4444'; ?>; font-weight:600;">
                                                            <?php echo $pp_test_result['success'] ? '✓' : '✗'; ?>
                                                            <?php echo esc_html($pp_test_result['message']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </p>
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

                                <?php // ── Card: Thank-You-Page ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        <h3>Thank-You-Page (Bestellbestätigung)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin-top:0;">
                                            Konfiguration der Seite die nach erfolgreicher Bestellung angezeigt wird.
                                        </p>
                                        <div class="tix-field-grid">
                                            <?php self::text_row('ty_my_tickets_url', '"Meine Tickets"-Link (Hinweisbox)', $s, 'https://example.com/meine-tickets/'); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">
                                                    Wird in der hellblauen Hinweisbox auf der Thank-You-Page als „Meine Tickets-Bereich" verlinkt. Leer lassen → automatisch ermittelt (Page mit <code>[tix_my_tickets]</code>-Shortcode).
                                                </p>
                                            </div>
                                            <div class="tix-field tix-field-full" style="border-top:1px solid #e5e7eb; padding-top:12px; margin-top:8px;">
                                                <?php self::checkbox_row('ty_back_link_show', 'Zurück-Link am Ende der Seite anzeigen', $s, 'Zeigt unten einen Link zurück z.B. zur Eventliste oder Startseite.'); ?>
                                            </div>
                                            <?php self::text_row('ty_back_link_text', 'Zurück-Link-Text', $s, '← Zurück zu den Events'); ?>
                                            <?php self::text_row('ty_back_link_url',  'Zurück-Link-URL', $s, 'https://example.com/programm/'); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">
                                                    URL leer lassen → führt zu <code>/events/</code>. Falls du keine Event-Übersichtsseite hast, eigene Ziel-URL angeben.
                                                </p>
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

                                <?php // ── Card: HTML-Ticket Design ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-layout"></span>
                                        <h3>HTML-Ticket Design</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin-bottom:12px;">Gestalte das HTML-Ticket (Fallback ohne Bild-Template). Diese Farben werden beim direkten Download im Browser angezeigt.</p>

                                        <?php // ── Version-Auswahl (V1/V2/V3/V4) ── ?>
                                        <?php $cur_version = $s['ht_version'] ?? 'v1';
                                              $vrow = function($v, $title, $desc, $badge = '') use ($ok, $cur_version) { ?>
                                            <label style="display:flex;flex-direction:column;gap:4px;padding:14px;border:2px solid <?php echo $cur_version === $v ? '#111' : '#e5e7eb'; ?>;border-radius:10px;cursor:pointer;background:<?php echo $cur_version === $v ? '#fafafa' : '#fff'; ?>;transition:all .15s;">
                                                <div style="display:flex;align-items:center;gap:8px;">
                                                    <input type="radio" name="<?php echo $ok; ?>[ht_version]" value="<?php echo esc_attr($v); ?>" <?php checked($cur_version === $v); ?> style="margin:0;">
                                                    <strong><?php echo esc_html($title); ?></strong>
                                                    <?php if ($badge): ?><span style="font-size:10px;background:#ede9fe;color:#5b21b6;padding:2px 6px;border-radius:6px;font-weight:700;letter-spacing:.3px;"><?php echo esc_html($badge); ?></span><?php endif; ?>
                                                </div>
                                                <span style="font-size:12px;color:#666;line-height:1.4;"><?php echo $desc; ?></span>
                                            </label>
                                        <?php }; ?>
                                        <div class="tix-field tix-field-full" style="margin-bottom:20px;">
                                            <label class="tix-field-label" style="display:block;margin-bottom:8px;">Design-Version</label>
                                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                                                <?php $vrow('v1', 'V1 · Classic', 'Clean &amp; klar. Header, Body mit QR + Info, Footer-Nachricht. Groß tauglich über alle Geräte.'); ?>
                                                <?php $vrow('v2', 'V2 · Premium', 'Glow &amp; Gradient. Perforation, QR-Corner-Brackets, moderne Typografie, subtiler Shine.'); ?>
                                                <?php $vrow('v3', 'V3 · Festival', 'Event-Cover als Hero oben, Event-Titel überlagert, minimalistischer Body. Voller Impact.'); ?>
                                                <?php $vrow('v4', 'V4 · Holographic', 'V2-Basis mit animiertem Conic-Gradient-Shine. Premium-Optik mit Hologramm-Effekt.'); ?>
                                                <?php $vrow('v5', 'V5 · Cyberpunk', 'Neon-Grid, Glitch-Text, Cyan/Magenta. Dystopian-Club-Feeling für Techno &amp; elektronische Musik.', 'NEU'); ?>
                                                <?php $vrow('v6', 'V6 · Retro/Vintage', 'Aged Paper, Typewriter-Font, Sepia. Hollywood-Cinema-Ticket-Feeling für Theater &amp; Kino.', 'NEU'); ?>
                                            </div>
                                        </div>

                                        <?php // ── Feature-Toggles ── ?>
                                        <div class="tix-field tix-field-full" style="margin-bottom:20px;">
                                            <label class="tix-field-label" style="display:block;margin-bottom:8px;">Ticket-Features</label>
                                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                                                <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;">
                                                    <input type="checkbox" name="<?php echo $ok; ?>[ht_show_event_cover]" value="1" <?php checked(!empty($s['ht_show_event_cover'])); ?>>
                                                    <span style="font-size:13px;"><strong>Event-Cover</strong> · Event-Bild als Hero oben</span>
                                                </label>
                                                <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;">
                                                    <input type="checkbox" name="<?php echo $ok; ?>[ht_show_countdown]" value="1" <?php checked(!empty($s['ht_show_countdown'])); ?>>
                                                    <span style="font-size:13px;"><strong>Countdown</strong> · "Noch 2 Tage · 14 Std…"</span>
                                                </label>
                                                <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;">
                                                    <input type="checkbox" name="<?php echo $ok; ?>[ht_show_verified_badge]" value="1" <?php checked(!empty($s['ht_show_verified_badge'])); ?>>
                                                    <span style="font-size:13px;"><strong>Verified-Badge</strong> · "✓ Offizielles Ticket"</span>
                                                </label>
                                                <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;">
                                                    <input type="checkbox" name="<?php echo $ok; ?>[ht_show_agb_footer]" value="1" <?php checked(!empty($s['ht_show_agb_footer'])); ?>>
                                                    <span style="font-size:13px;"><strong>AGB-Footer</strong> · Link zu AGB &amp; Widerruf</span>
                                                </label>
                                                <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;">
                                                    <input type="checkbox" name="<?php echo $ok; ?>[ht_seasonal_enabled]" value="1" <?php checked(!empty($s['ht_seasonal_enabled'])); ?>>
                                                    <span style="font-size:13px;"><strong>🎄 Saison-Overlays</strong> · Weihnachten/Halloween/Valentin</span>
                                                </label>
                                                <div style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;">
                                                    <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;flex:1;">
                                                        <input type="checkbox" name="<?php echo $ok; ?>[ht_watermark_enabled]" value="1" <?php checked(!empty($s['ht_watermark_enabled'])); ?>>
                                                        <span style="font-size:13px;"><strong>💧 Wasserzeichen</strong> · Diagonale Ticket-ID (Anti-Fake)</span>
                                                    </label>
                                                    <span style="display:inline-flex;align-items:center;gap:6px;">
                                                        <span style="font-size:11px;color:#6b7280;">Farbe:</span>
                                                        <input type="color" name="<?php echo $ok; ?>[ht_watermark_color]" value="<?php echo esc_attr(!empty($s['ht_watermark_color']) ? $s['ht_watermark_color'] : '#fafafa'); ?>" style="width:34px;height:26px;border:1px solid #d1d5db;border-radius:4px;padding:0;cursor:pointer;" title="Wasserzeichen-Farbe">
                                                    </span>
                                                </div>
                                                <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;">
                                                    <input type="checkbox" name="<?php echo $ok; ?>[ht_weather_enabled]" value="1" <?php checked(!empty($s['ht_weather_enabled'])); ?>>
                                                    <span style="font-size:13px;"><strong>☁️ Live-Wetter</strong> · Prognose am Event-Tag</span>
                                                </label>
                                                <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;">
                                                    <input type="checkbox" name="<?php echo $ok; ?>[ht_checkin_sound]" value="1" <?php checked(!empty($s['ht_checkin_sound'])); ?>>
                                                    <span style="font-size:13px;"><strong>🔊 Check-in-Sound</strong> · Ton + Vibration beim Scan</span>
                                                </label>
                                                <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;">
                                                    <input type="checkbox" name="<?php echo $ok; ?>[ht_qr_bright_mode]" value="1" <?php checked(!empty($s['ht_qr_bright_mode'])); ?>>
                                                    <span style="font-size:13px;"><strong>☀️ QR-Max-Helligkeit</strong> · Fullscreen + Wake-Lock beim QR-Tap</span>
                                                </label>
                                            </div>

                                            <?php // ── Action-Button-Toggles (default: alle an) ── ?>
                                            <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;">
                                                <div style="font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px;">Aktions-Buttons auf der Ticket-Seite</div>
                                                <div class="tix-field-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                                                    <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;">
                                                        <input type="checkbox" name="<?php echo $ok; ?>[ht_action_save_image]" value="1" <?php checked(!empty($s['ht_action_save_image'])); ?>>
                                                        <span style="font-size:13px;"><strong>📷 Als Bild speichern</strong></span>
                                                    </label>
                                                    <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;">
                                                        <input type="checkbox" name="<?php echo $ok; ?>[ht_action_wallets]" value="1" <?php checked(!empty($s['ht_action_wallets'])); ?>>
                                                        <span style="font-size:13px;"><strong>👝 Apple / Google Wallet</strong></span>
                                                    </label>
                                                    <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;">
                                                        <input type="checkbox" name="<?php echo $ok; ?>[ht_action_print]" value="1" <?php checked(!empty($s['ht_action_print'])); ?>>
                                                        <span style="font-size:13px;"><strong>🖨️ Ticket drucken</strong></span>
                                                    </label>
                                                    <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;">
                                                        <?php $bundle_btn_on = !isset($s['ht_show_order_bundle_btn']) || !empty($s['ht_show_order_bundle_btn']); ?>
                                                        <input type="checkbox" name="<?php echo $ok; ?>[ht_show_order_bundle_btn]" value="1" <?php checked($bundle_btn_on); ?>>
                                                        <span style="font-size:13px;"><strong>📋 „Alle Tickets der Bestellung"-Button</strong></span>
                                                    </label>
                                                </div>
                                                <p style="margin:8px 0 0;font-size:11px;color:#9ca3af;line-height:1.5;">Der Bundle-Button erscheint unten auf jedem Ticket — aber nur wenn die Bestellung mehr als 1 Ticket enthält.</p>
                                            </div>
                                        </div>

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

                                            <?php // ── Share-Bild (Open Graph) ── ?>
                                            <div class="tix-field tix-field-full" style="margin-top:12px;">
                                                <label class="tix-field-label">Ticket-Share-Bild (Open Graph)</label>
                                                <div style="display:flex;gap:12px;align-items:center;">
                                                    <input type="text" name="<?php echo $ok; ?>[ht_share_image]" id="tix-ht-share-url"
                                                           value="<?php echo esc_attr($s['ht_share_image'] ?? ''); ?>"
                                                           class="regular-text" placeholder="Bild-URL eingeben oder Bild w&auml;hlen"
                                                           style="flex:1;">
                                                    <button type="button" class="button" id="tix-ht-share-btn">Bild w&auml;hlen</button>
                                                </div>
                                                <?php if (!empty($s['ht_share_image'])) : ?>
                                                    <div style="margin-top:8px;" id="tix-ht-share-preview">
                                                        <img src="<?php echo esc_url($s['ht_share_image']); ?>" style="max-width:200px;height:auto;border:1px solid #e5e7eb;border-radius:6px;">
                                                        <button type="button" class="button-link" style="color:#ef4444;margin-left:8px;vertical-align:top;" onclick="document.getElementById('tix-ht-share-url').value='';document.getElementById('tix-ht-share-preview').remove();">Entfernen</button>
                                                    </div>
                                                <?php endif; ?>
                                                <p class="tix-field-hint">Wird angezeigt wenn Kunden das Ticket teilen (WhatsApp, Messenger, etc.). Empfohlen: 1200&times;630 px, mit „🎟️ Ticket"-Branding damit Empf&auml;nger nicht denken, es sei ein Event-Kauf-Link. <strong>Leer</strong> = Event-Cover wird genutzt.</p>
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

                                            $('#tix-ht-share-btn').on('click',function(e){
                                                e.preventDefault();
                                                var frame = wp.media({title:'Ticket-Share-Bild w\u00e4hlen',multiple:false,library:{type:'image'}});
                                                frame.on('select',function(){
                                                    var url = frame.state().get('selection').first().toJSON().url;
                                                    $('#tix-ht-share-url').val(url);
                                                    var $prev = $('#tix-ht-share-preview');
                                                    if ($prev.length) {
                                                        $prev.find('img').attr('src', url);
                                                    } else {
                                                        $('#tix-ht-share-url').closest('.tix-field').append(
                                                            '<div style="margin-top:8px;" id="tix-ht-share-preview">' +
                                                            '<img src="' + url + '" style="max-width:200px;height:auto;border:1px solid #e5e7eb;border-radius:6px;">' +
                                                            '<button type="button" class="button-link" style="color:#ef4444;margin-left:8px;vertical-align:top;" onclick="document.getElementById(\'tix-ht-share-url\').value=\'\';document.getElementById(\'tix-ht-share-preview\').remove();">Entfernen</button>' +
                                                            '</div>'
                                                        );
                                                    }
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

                                <?php // ── Card: Ticket-Badge (Check-in-Status) ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-shield-alt"></span>
                                        <h3>Ticket-Badge</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin-bottom:12px;">Farben f&uuml;r das Check-in-Badge auf der Online-Ticket-Ansicht. Das Badge zeigt den Check-in-Status + die zugewiesene Person und aktualisiert sich live w&auml;hrend des Einlasses.</p>
                                        <div class="tix-field-grid">
                                            <?php
                                            self::color_row('badge_pending_bg',   'Badge-Farbe (nicht eingecheckt)',       $s);
                                            self::color_row('badge_pending_text', 'Schriftfarbe (nicht eingecheckt)',       $s);
                                            self::color_row('badge_done_bg',      'Badge-Farbe (eingecheckt)',              $s);
                                            self::color_row('badge_done_text',    'Schriftfarbe (eingecheckt)',             $s);
                                            ?>
                                        </div>

                                        <?php // ── Live-Vorschau ── ?>
                                        <div style="margin-top:20px;border-top:1px solid #e5e7eb;padding-top:20px;">
                                            <h4 style="margin:0 0 12px;font-size:14px;font-weight:600;color:#374151;">Vorschau</h4>
                                            <div id="tix-badge-preview" style="display:flex;flex-direction:column;gap:10px;align-items:flex-start;"></div>
                                        </div>

                                        <script>
                                        jQuery(function($){
                                            function bv(id) {
                                                var el = document.querySelector('[name="tix_settings[' + id + ']"]');
                                                return el ? el.value : '';
                                            }
                                            function renderBadgePreview() {
                                                var pbg = bv('badge_pending_bg') || '#6366f1',
                                                    pfg = bv('badge_pending_text') || '#ffffff',
                                                    dbg = bv('badge_done_bg') || '#10b981',
                                                    dfg = bv('badge_done_text') || '#ffffff';
                                                var editIcon = '<svg style="width:12px;height:12px;display:inline-block;vertical-align:middle;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>';
                                                $('#tix-badge-preview').html(
                                                    '<div style="background:' + pbg + ';color:' + pfg + ';display:inline-flex;align-items:center;gap:8px;padding:10px 14px 10px 16px;border-radius:999px;font-size:14px;font-weight:600;box-shadow:0 2px 6px rgba(0,0,0,.15);">' +
                                                        '<span>Nicht eingecheckt</span>' +
                                                        '<span style="opacity:.55;">\u00b7</span>' +
                                                        '<span style="font-weight:700;">Max Mustermann</span>' +
                                                        '<span style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:rgba(0,0,0,.1);margin-left:4px;">' + editIcon + '</span>' +
                                                    '</div>' +
                                                    '<div style="background:' + dbg + ';color:' + dfg + ';display:inline-flex;align-items:center;gap:8px;padding:10px 14px 10px 16px;border-radius:999px;font-size:14px;font-weight:600;box-shadow:0 2px 6px rgba(0,0,0,.15);">' +
                                                        '<span>Eingecheckt \u2713 \u00b7 19:42</span>' +
                                                        '<span style="opacity:.55;">\u00b7</span>' +
                                                        '<span style="font-weight:700;">Max Mustermann</span>' +
                                                    '</div>'
                                                );
                                            }
                                            renderBadgePreview();
                                            $(document).on('change input', '[name^="tix_settings[badge_"]', renderBadgePreview);
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
                                            <?php self::checkbox_row('ep_hero_auto', 'Hero-Bild: Auto-Höhe', $s, 'Bild nimmt seine natürliche Höhe ein statt einer fixen Höhe.'); ?>
                                            <?php if (empty($s['ep_hero_auto'])): ?>
                                                <?php self::range_row('ep_hero_height', 'Hero-Bild-Höhe', $s, 150, 600, 'px'); ?>
                                            <?php endif; ?>
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

                                            <!-- ── Gruppierung: Spalten ── -->
                                            <div class="tix-field tix-field-full" style="border:1px solid #e5e7eb;border-radius:10px;padding:16px 16px 8px;background:#fafafa;">
                                                <div style="font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:12px;">Spalten</div>
                                                <?php self::range_row('ep_col_split', 'Spaltenverteilung (Content / Sidebar)', $s, 50, 80, '%', 1); ?>
                                                <?php self::range_row('ep_col_gap', 'Spalten-Abstand', $s, 0, 100, 'px', 1); ?>
                                            </div>
                                            <?php
                                            // ── Sektions-Abstand (responsiv) ──
                                            self::responsive_number_row('Sektions-Abstand (responsiv)', [
                                                'ep_gap'    => ['label' => 'Desktop', 'icon' => '🖥️', 'def' => 32],
                                                'ep_gap_tl' => ['label' => '≤1119', 'icon' => '💻', 'def' => 28],
                                                'ep_gap_tp' => ['label' => '≤1023', 'icon' => '📱↔', 'def' => 24],
                                                'ep_gap_pl' => ['label' => '≤767', 'icon' => '📱', 'def' => 20],
                                                'ep_gap_pp' => ['label' => '≤479', 'icon' => '📱↕', 'def' => 16],
                                            ], $s, 0, 60, 'Vertikaler Abstand zwischen den Sektionen/Karten.');
                                            ?>

                                            <!-- ── Gruppierung: Abstände vom Rand ── -->
                                            <div class="tix-field tix-field-full" style="border:1px solid #e5e7eb;border-radius:10px;padding:16px 16px 8px;background:#fafafa;">
                                                <div style="font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:12px;">Abstände vom Rand</div>

                                                <div style="margin-bottom:14px;">
                                                    <label class="tix-field-label" style="margin-bottom:6px;">Text — Abstand vom Rand (responsiv)</label>
                                                    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;">
                                                        <?php
                                                        $bp_pads = [
                                                            'ep_pad_x'    => ['label' => 'Desktop', 'icon' => '🖥️'],
                                                            'ep_pad_x_tl' => ['label' => '≤1119', 'icon' => '💻'],
                                                            'ep_pad_x_tp' => ['label' => '≤1023', 'icon' => '📱↔'],
                                                            'ep_pad_x_pl' => ['label' => '≤767', 'icon' => '📱'],
                                                            'ep_pad_x_pp' => ['label' => '≤479', 'icon' => '📱↕'],
                                                        ];
                                                        foreach ($bp_pads as $bp_key => $bp_meta):
                                                            $bp_val = intval($s[$bp_key] ?? 32);
                                                        ?>
                                                        <div style="text-align:center;">
                                                            <div style="font-size:11px;color:#64748b;margin-bottom:4px;" title="<?php echo esc_attr($bp_meta['label']); ?>">
                                                                <?php echo $bp_meta['icon']; ?> <?php echo $bp_meta['label']; ?>
                                                            </div>
                                                            <input type="number"
                                                                   name="tix_settings[<?php echo $bp_key; ?>]"
                                                                   value="<?php echo $bp_val; ?>"
                                                                   min="0" max="80" step="1"
                                                                   style="width:100%;text-align:center;padding:6px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-weight:600;">
                                                            <div style="font-size:10px;color:#94a3b8;margin-top:2px;">px</div>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <p style="font-size:11px;color:#94a3b8;margin:4px 0 0;">Innenabstand des Texts innerhalb der Spalte (links/rechts).</p>
                                                </div>

                                                <div style="border-top:1px solid #e5e7eb;padding-top:14px;margin-bottom:14px;">
                                                    <label class="tix-field-label" style="margin-bottom:6px;">Karten — Abstand vom Rand (responsiv)</label>
                                                    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;">
                                                        <?php
                                                        $card_gap_bps = [
                                                            'ep_card_gap'    => ['label' => 'Desktop', 'icon' => '🖥️', 'def' => 0],
                                                            'ep_card_gap_tl' => ['label' => '≤1119', 'icon' => '💻', 'def' => 0],
                                                            'ep_card_gap_tp' => ['label' => '≤1023', 'icon' => '📱↔', 'def' => 0],
                                                            'ep_card_gap_pl' => ['label' => '≤767', 'icon' => '📱', 'def' => 0],
                                                            'ep_card_gap_pp' => ['label' => '≤479', 'icon' => '📱↕', 'def' => 0],
                                                        ];
                                                        foreach ($card_gap_bps as $cg_key => $cg_meta): ?>
                                                        <div style="text-align:center;">
                                                            <div style="font-size:11px;color:#64748b;margin-bottom:4px;"><?php echo $cg_meta['icon']; ?> <?php echo $cg_meta['label']; ?></div>
                                                            <input type="number" name="tix_settings[<?php echo $cg_key; ?>]"
                                                                   value="<?php echo intval($s[$cg_key] ?? $cg_meta['def']); ?>"
                                                                   min="0" max="80" step="1"
                                                                   style="width:100%;text-align:center;padding:6px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-weight:600;">
                                                            <div style="font-size:10px;color:#94a3b8;margin-top:2px;">px</div>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <p style="font-size:11px;color:#94a3b8;margin:4px 0 0;">Abstand der Karten vom Bildschirmrand. 0 = randlos (edge-to-edge).</p>
                                                </div>
                                            </div>

                                            <!-- Karten-Innenabstand (responsiv) -->
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label">Karten — Innenabstand (responsiv)</label>
                                                <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-top:6px;">
                                                    <?php
                                                    $card_pad_bps = [
                                                        'ep_card_pad'    => ['label' => 'Desktop', 'icon' => '🖥️', 'def' => 28],
                                                        'ep_card_pad_tl' => ['label' => '≤1119', 'icon' => '💻', 'def' => 20],
                                                        'ep_card_pad_tp' => ['label' => '≤1023', 'icon' => '📱↔', 'def' => 20],
                                                        'ep_card_pad_pl' => ['label' => '≤767', 'icon' => '📱', 'def' => 16],
                                                        'ep_card_pad_pp' => ['label' => '≤479', 'icon' => '📱↕', 'def' => 12],
                                                    ];
                                                    foreach ($card_pad_bps as $cp_key => $cp_meta): ?>
                                                    <div style="text-align:center;">
                                                        <div style="font-size:11px;color:#64748b;margin-bottom:4px;"><?php echo $cp_meta['icon']; ?> <?php echo $cp_meta['label']; ?></div>
                                                        <input type="number" name="tix_settings[<?php echo $cp_key; ?>]"
                                                               value="<?php echo intval($s[$cp_key] ?? $cp_meta['def']); ?>"
                                                               min="0" max="80" step="1"
                                                               style="width:100%;text-align:center;padding:6px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-weight:600;">
                                                        <div style="font-size:10px;color:#94a3b8;margin-top:2px;">px</div>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <p style="font-size:11px;color:#94a3b8;margin:6px 0 0;">Padding innerhalb der Karten (Abstand vom Kartenrand zum Inhalt).</p>
                                            </div>

                                            <!-- ── Gruppierung: Padding oben/unten ── -->
                                            <div class="tix-field tix-field-full" style="border:1px solid #e5e7eb;border-radius:10px;padding:16px 16px 8px;background:#fafafa;">
                                                <div style="font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:12px;">Vertikales Padding</div>
                                                <?php
                                                self::responsive_number_row('Padding oben (responsiv)', [
                                                    'ep_pad_top'    => ['label' => 'Desktop', 'icon' => '🖥️', 'def' => 32],
                                                    'ep_pad_top_tl' => ['label' => '≤1119', 'icon' => '💻', 'def' => 28],
                                                    'ep_pad_top_tp' => ['label' => '≤1023', 'icon' => '📱↔', 'def' => 24],
                                                    'ep_pad_top_pl' => ['label' => '≤767', 'icon' => '📱', 'def' => 20],
                                                    'ep_pad_top_pp' => ['label' => '≤479', 'icon' => '📱↕', 'def' => 16],
                                                ], $s, 0, 120, 'Abstand oben über dem Inhalt (beide Spalten).');

                                                self::responsive_number_row('Padding unten (responsiv)', [
                                                    'ep_pad_bottom'    => ['label' => 'Desktop', 'icon' => '🖥️', 'def' => 64],
                                                    'ep_pad_bottom_tl' => ['label' => '≤1119', 'icon' => '💻', 'def' => 56],
                                                    'ep_pad_bottom_tp' => ['label' => '≤1023', 'icon' => '📱↔', 'def' => 48],
                                                    'ep_pad_bottom_pl' => ['label' => '≤767', 'icon' => '📱', 'def' => 40],
                                                    'ep_pad_bottom_pp' => ['label' => '≤479', 'icon' => '📱↕', 'def' => 32],
                                                ], $s, 0, 120, 'Abstand unten nach dem Inhalt (beide Spalten).');
                                                ?>
                                            </div>
                                            <?php self::checkbox_row('ep_sticky_tabs', 'Sticky Tab-Navigation', $s, 'Tab-Leiste klebt beim Scrollen oben fest. Deaktivieren = Tabs scrollen mit dem Inhalt.'); ?>
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
                                            <?php self::range_row('ec_max_width', 'Maximale Breite', $s, 600, 2000, 'px', 50); ?>
                                            <?php self::range_row('ec_pad_x', 'Padding seitlich', $s, 0, 80, 'px'); ?>
                                            <?php self::range_row('ec_pad_y', 'Padding oben/unten', $s, 0, 120, 'px'); ?>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ec_show_search', 'Suchfeld anzeigen', $s, 'Zeigt die Suchleiste über dem Event-Grid. Deaktiviere, wenn du die Suche bereits anderswo (z.B. im Header) platziert hast.'); ?>
                                            </div>
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

                            <?php // ═══ PANE: EVENT-HOMEPAGE ═══ ?>
                            <div class="tix-pane" data-pane="event-homepage">

                                <?php // ── Card: Homepage-Baukasten (Sektions-Reihenfolge & Sichtbarkeit) ── ?>
                                <?php
                                $hp_sections_config = class_exists('TIX_Event_Homepage') ? TIX_Event_Homepage::get_sections_config() : [];
                                $hp_section_registry = class_exists('TIX_Event_Homepage') ? TIX_Event_Homepage::get_section_registry() : [];
                                ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-sort"></span>
                                        <h3>Baukasten — Reihenfolge & Sichtbarkeit</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin:0 0 12px;">Ziehe die Sektionen in deine Wunsch-Reihenfolge. Per Checkbox ein-/ausblenden. Pro Sektion kannst du den Abstand zur nächsten Sektion individuell setzen — leer lassen nutzt den Default unten.</p>

                                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:10px 14px;background:#fef3c7;border-radius:8px;">
                                            <span class="dashicons dashicons-editor-expand" style="color:#92400e;"></span>
                                            <label style="font-size:13px;color:#92400e;">
                                                <strong>Standard-Abstand</strong> zwischen Sektionen:
                                                <input type="number" name="<?php echo TIX_Settings::OPTION_KEY; ?>[hp_section_spacing]" value="<?php echo esc_attr(intval($s['hp_section_spacing'] ?? 40)); ?>" min="0" max="200" style="width:70px;padding:4px 8px;margin-left:6px;"> px
                                            </label>
                                        </div>

                                        <ul id="tix-hp-sections-sortable" style="list-style:none;margin:0;padding:0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;background:#fff;">
                                            <?php foreach ($hp_sections_config as $row):
                                                $id = $row['id'];
                                                $def = $hp_section_registry[$id] ?? null;
                                                if (!$def) continue;
                                                // spacing > 0 = explizit gesetzt; 0/-1/fehlt → Feld leer zeigen (Default aktiv)
                                                $spacing_val = !empty($row['spacing']) && intval($row['spacing']) > 0 ? intval($row['spacing']) : '';
                                            ?>
                                            <li class="tix-hp-sec-item" data-id="<?php echo esc_attr($id); ?>" data-legacy-key="<?php echo esc_attr($def['legacy_key'] ?? ''); ?>" style="display:flex;align-items:center;gap:12px;padding:10px 14px;border-bottom:1px solid #f1f5f9;background:#fff;">
                                                <span class="tix-hp-sec-handle" title="Zum Verschieben ziehen" style="cursor:grab;color:#94a3b8;font-size:18px;user-select:none;">☰</span>
                                                <span class="dashicons dashicons-<?php echo esc_attr($def['icon']); ?>" style="color:#64748b;"></span>
                                                <span style="flex:1;font-weight:600;font-size:14px;"><?php echo esc_html($def['label']); ?></span>
                                                <label style="display:flex;align-items:center;gap:4px;font-size:11px;color:#64748b;" title="Abstand nach dieser Sektion (leer = Default)">
                                                    <input type="number" class="tix-hp-sec-spacing" value="<?php echo esc_attr($spacing_val); ?>" min="0" max="200" placeholder="–" style="width:52px;padding:3px 6px;font-size:12px;">
                                                    <span>px</span>
                                                </label>
                                                <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#64748b;cursor:pointer;">
                                                    <input type="checkbox" class="tix-hp-sec-enabled" <?php checked($row['enabled']); ?>>
                                                    Anzeigen
                                                </label>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <input type="hidden" name="<?php echo TIX_Settings::OPTION_KEY; ?>[hp_sections_json]" id="tix-hp-sections-json" value="<?php echo esc_attr(wp_json_encode($hp_sections_config)); ?>">

                                        <script>
                                        (function(){
                                            var list = document.getElementById('tix-hp-sections-sortable');
                                            var hidden = document.getElementById('tix-hp-sections-json');
                                            if (!list || !hidden) return;

                                            function syncJson(){
                                                var rows = [];
                                                list.querySelectorAll('.tix-hp-sec-item').forEach(function(el){
                                                    var sp = el.querySelector('.tix-hp-sec-spacing');
                                                    var spVal = sp && sp.value !== '' ? parseInt(sp.value, 10) : -1;
                                                    rows.push({
                                                        id: el.getAttribute('data-id'),
                                                        enabled: el.querySelector('.tix-hp-sec-enabled').checked,
                                                        spacing: spVal
                                                    });
                                                });
                                                hidden.value = JSON.stringify(rows);
                                            }

                                            // Bidirektionaler Sync mit Legacy-Checkboxen
                                            function findLegacyInput(key){
                                                if (!key) return null;
                                                return document.querySelector('input[name$="[' + key + ']"]');
                                            }
                                            function syncLegacyFromSection(item){
                                                var key = item.getAttribute('data-legacy-key');
                                                var legacy = findLegacyInput(key);
                                                if (!legacy) return;
                                                var checked = item.querySelector('.tix-hp-sec-enabled').checked;
                                                if (legacy.checked !== checked) legacy.checked = checked;
                                            }
                                            // Initial Legacy → Baukasten
                                            list.querySelectorAll('.tix-hp-sec-item').forEach(function(item){
                                                var key = item.getAttribute('data-legacy-key');
                                                var legacy = findLegacyInput(key);
                                                if (!legacy) return;
                                                // Legacy-Toggle ändert Baukasten-Checkbox
                                                legacy.addEventListener('change', function(){
                                                    var cb = item.querySelector('.tix-hp-sec-enabled');
                                                    if (cb.checked !== legacy.checked) {
                                                        cb.checked = legacy.checked;
                                                        syncJson();
                                                    }
                                                });
                                            });

                                            // Checkbox-Änderungen im Baukasten tracken
                                            list.addEventListener('change', function(e){
                                                if (e.target && e.target.classList.contains('tix-hp-sec-enabled')) {
                                                    var item = e.target.closest('.tix-hp-sec-item');
                                                    if (item) syncLegacyFromSection(item);
                                                    syncJson();
                                                }
                                                if (e.target && e.target.classList.contains('tix-hp-sec-spacing')) {
                                                    syncJson();
                                                }
                                            });
                                            list.addEventListener('input', function(e){
                                                if (e.target && e.target.classList.contains('tix-hp-sec-spacing')) {
                                                    syncJson();
                                                }
                                            });

                                            // Drag & Drop (vanilla, ohne externe Libs)
                                            var dragEl = null;
                                            list.querySelectorAll('.tix-hp-sec-item').forEach(function(item){
                                                item.setAttribute('draggable', 'true');
                                                item.addEventListener('dragstart', function(e){
                                                    dragEl = item;
                                                    item.style.opacity = '0.4';
                                                    e.dataTransfer.effectAllowed = 'move';
                                                });
                                                item.addEventListener('dragend', function(){
                                                    item.style.opacity = '';
                                                    syncJson();
                                                });
                                                item.addEventListener('dragover', function(e){
                                                    e.preventDefault();
                                                    if (dragEl === item) return;
                                                    var rect = item.getBoundingClientRect();
                                                    var mid = rect.top + rect.height / 2;
                                                    if (e.clientY < mid) {
                                                        item.parentNode.insertBefore(dragEl, item);
                                                    } else {
                                                        item.parentNode.insertBefore(dragEl, item.nextSibling);
                                                    }
                                                });
                                            });

                                            // Initial sync (falls sich nichts geändert hat, haben wir den Current-State)
                                            syncJson();
                                        })();
                                        </script>
                                    </div>
                                </div>

                                <?php // ── Card: Performance / Caching ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-performance"></span>
                                        <h3>Performance &amp; Caching</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_cache_enabled', 'Sektionen-Cache aktivieren', $s, 'Cached gerenderte Homepage-Sektionen per Transient. Nur für anonyme Besucher aktiv. Bei Event-Änderungen automatisch invalidiert.'); ?>
                                            </div>
                                            <?php self::range_row('hp_cache_ttl', 'Cache-Dauer (Sekunden)', $s, 60, 3600, 's', 60); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint" style="margin:0;">Empfehlung: 600s (10 min) für die meisten Seiten. Länger = mehr Performance, kürzer = aktueller. Wird bei jedem Event-Save automatisch geleert.</p>
                                            </div>
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
                                        <p class="tix-settings-hint" style="margin:0 0 16px;">Steuert den <code>[tix_homepage]</code> Shortcode — eine Event-Startseite mit Hero, Zeitfilter und Kategorie-Chips.</p>
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_enabled', 'Event-Homepage aktivieren', $s, 'Aktiviert den [tix_homepage] Shortcode.'); ?>
                                            </div>
                                            <?php self::range_row('hp_max_width', 'Max. Breite', $s, 600, 1800, 'px', 10); ?>
                                            <?php self::range_row('hp_pad_x', 'Padding seitlich', $s, 0, 100, 'px'); ?>
                                            <?php self::range_row('hp_pad_y', 'Padding oben/unten', $s, 0, 120, 'px'); ?>
                                            <?php self::range_row('hp_grid_count', 'Events im Grid (Initial)', $s, 4, 24, '', 4); ?>
                                            <?php self::text_row('hp_exclude_categories', 'Kategorien ausschließen', $s, 'z.B. intern,test (Kommagetrennte Slugs)'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Hero ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-cover-image"></span>
                                        <h3>Hero-Bereich</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_hero', 'Hero-Bereich anzeigen', $s, 'Großes Featured-Event + kleinere Events oben.'); ?>
                                            </div>
                                            <?php self::range_row('hp_hero_count', 'Anzahl Hero-Events', $s, 1, 8, '', 1); ?>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_only_featured_hero', 'Hero nur mit Empfehlungen', $s, 'Zeigt im Hero-Bereich nur Events mit "Empfehlung"-Badge.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label" style="margin-bottom:8px;display:block;">Hero-Variante</label>
                                                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                                                    <?php foreach (['grid' => 'Grid (1 groß + kleine)', 'slider' => 'Slider (Autoplay)', 'fullwidth' => 'Fullwidth (1 Event)'] as $val => $lbl): ?>
                                                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:8px 16px;border:2px solid <?php echo ($s['hp_hero_style'] ?? 'grid') === $val ? 'var(--tix-admin-accent,#FF5500)' : '#e5e7eb'; ?>;border-radius:8px;background:<?php echo ($s['hp_hero_style'] ?? 'grid') === $val ? 'rgba(255,85,0,.06)' : '#fff'; ?>;">
                                                        <input type="radio" name="<?php echo TIX_Settings::OPTION_KEY; ?>[hp_hero_style]" value="<?php echo $val; ?>" <?php checked($s['hp_hero_style'] ?? 'grid', $val); ?> style="margin:0;">
                                                        <span style="font-size:13px;"><?php echo $lbl; ?></span>
                                                    </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Filter & Ansicht ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-filter"></span>
                                        <h3>Filter & Ansicht</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_time_filter', 'Zeitfilter anzeigen (Heute/Morgen/Wochenende)', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_cat_filter', 'Kategorie-Chips anzeigen', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_load_more', '"Mehr Events laden" Button anzeigen', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_list_toggle', 'Grid/Listen-Umschalter anzeigen', $s, 'Besucher kann zwischen Karten-Grid und kompakter Listenansicht wechseln.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_url_sync', 'Filter in URL synchronisieren', $s, 'Schreibt aktive Filter in die URL (?time=weekend&cat=konzert) zum Teilen.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_smart_time', 'Intelligente Zeitfilter-Vorauswahl', $s, 'Morgens wird "Heute", abends "Morgen" automatisch hervorgehoben.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Sektionen ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-excerpt-view"></span>
                                        <h3>Sektionen</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_popular', '"Heute beliebt" Sektion', $s, 'Zeigt die meistverkauften Events als eigene Reihe.'); ?>
                                            </div>
                                            <?php self::range_row('hp_popular_count', 'Anzahl beliebte Events', $s, 2, 12, '', 1); ?>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_countdown', 'Countdown bei bald startenden Events', $s, 'Zeigt "Beginnt in Xh" bei Events < 24h.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Veranstalter-Spotlight ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-star-filled"></span>
                                        <h3>Veranstalter-Spotlight</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_spotlight', 'Veranstalter-Spotlight anzeigen', $s, 'Zeigt einen Veranstalter mit Logo, Beschreibung und dessen nächsten Events.'); ?>
                                            </div>
                                            <?php self::range_row('hp_spotlight_org_id', 'Veranstalter-ID', $s, 0, 9999, '', 1); ?>
                                            <p class="tix-settings-hint" style="margin-top:-8px;">0 = Veranstalter mit den meisten kommenden Events. Oder Post-ID eines tix_organizer eingeben.</p>
                                            <?php self::range_row('hp_spotlight_count', 'Anzahl Events im Spotlight', $s, 1, 6, '', 1); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Newsletter-Banner ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-email-alt2"></span>
                                        <h3>Newsletter-Banner</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_newsletter', 'Newsletter-Banner zwischen Sektionen anzeigen', $s); ?>
                                            </div>
                                            <?php self::text_row('hp_newsletter_title', 'Titel', $s, 'Bleib auf dem Laufenden'); ?>
                                            <?php self::text_row('hp_newsletter_text', 'Text', $s, 'Erhalte die besten Events direkt in dein Postfach.'); ?>
                                            <?php self::text_row('hp_newsletter_url', 'Link / URL', $s, 'https://...'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Dashboard — Stats-Bar ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-chart-bar"></span>
                                        <h3>Stats-Bar</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_stats_bar', 'Stats-Bar unter dem Hero anzeigen', $s, 'Zeigt Anzahl Events, Locations, Veranstalter und Kategorien mit animiertem CountUp.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Dashboard — Kategorie-Kacheln ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-screenoptions"></span>
                                        <h3>Kategorie-Kacheln</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_cat_tiles', 'Kategorie-Kacheln mit Icons anzeigen', $s, 'Visuell ansprechende Kacheln zum schnellen Filtern nach Kategorie. Nur Kategorien mit Events werden gezeigt.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Dashboard — Diese Woche ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-calendar-alt"></span>
                                        <h3>Diese Woche</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_this_week', '"Bald geht\'s los" Sektion anzeigen', $s, 'Kompakte Tages-Karten mit Events der n&auml;chsten Tage. Erzeugt Dringlichkeit.'); ?>
                                            </div>
                                            <?php self::range_row('hp_this_week_days', 'Tage voraus', $s, 3, 14, '', 1); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Dashboard — Beliebte Locations ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-location"></span>
                                        <h3>Beliebte Locations</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_locations', 'Location-Spotlight anzeigen', $s, 'Zeigt die beliebtesten Locations mit Anzahl kommender Events und n&auml;chstem Event.'); ?>
                                            </div>
                                            <?php self::range_row('hp_locations_count', 'Anzahl Locations', $s, 3, 12, '', 1); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ═══ PHASE-2: NEUE SEKTIONEN ═══ ?>

                                <?php // ── Card: Hero-Countdown ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-clock"></span>
                                        <h3>Hero-Countdown</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_hero_countdown', 'Countdown-Banner anzeigen', $s, 'Großer Banner mit Live-Countdown zu einem Highlight-Event.'); ?>
                                            </div>
                                            <?php self::text_row('hp_countdown_label', 'Label (über dem Titel)', $s, 'z.B. Next Event, Mega-Party, Sommer-Finale'); ?>
                                            <?php self::range_row('hp_countdown_event_id', 'Event-ID (0 = nächstes Event automatisch)', $s, 0, 99999, '', 1); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Letzte Chance ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-warning"></span>
                                        <h3>Letzte Chance (FOMO)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_last_chance', '"Jetzt oder nie" Sektion anzeigen', $s, 'Zeigt Events, die innerhalb der nächsten Stunden stattfinden — erzeugt Dringlichkeit.'); ?>
                                            </div>
                                            <?php self::range_row('hp_last_chance_hours', 'Zeitfenster (Stunden voraus)', $s, 6, 168, 'h', 6); ?>
                                            <?php self::range_row('hp_last_chance_count', 'Max. Anzahl Events', $s, 2, 8, '', 1); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Wochentag-Grid ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-calendar"></span>
                                        <h3>Wochentag-Grid (Mo–So)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_weekday_grid', 'Wochentag-Grid anzeigen', $s, 'Kompakte Spalten je Wochentag — ideal für Club/Bar-Kalender.'); ?>
                                            </div>
                                            <?php self::range_row('hp_weekday_weeks', 'Wochen voraus', $s, 1, 4, '', 1); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Geschenkgutschein-Banner ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-tickets-alt"></span>
                                        <h3>Geschenkgutschein-Banner</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_voucher', 'Gutschein-Banner anzeigen', $s, 'Auffälliger Banner mit CTA zum Gutschein-Kauf — ideal für saisonale Promos.'); ?>
                                            </div>
                                            <?php self::text_row('hp_voucher_title', 'Titel', $s, 'Verschenke ein Erlebnis'); ?>
                                            <?php self::text_row('hp_voucher_text', 'Text', $s, 'Gutscheine für jedes Event — der perfekte Geschenktipp.'); ?>
                                            <?php self::text_row('hp_voucher_btn', 'Button-Text', $s, 'Gutschein kaufen'); ?>
                                            <?php self::text_row('hp_voucher_url', 'Link / URL', $s, 'https://...'); ?>
                                            <?php
                                            // Bild-Picker
                                            $voucher_img_id  = intval($s['hp_voucher_image_id'] ?? 0);
                                            $voucher_img_url = $voucher_img_id ? wp_get_attachment_image_url($voucher_img_id, 'medium') : '';
                                            ?>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label" style="display:block;margin-bottom:6px;">Banner-Bild (optional)</label>
                                                <div class="tix-hp-imgpick" style="display:flex;align-items:center;gap:12px;">
                                                    <div class="tix-hp-imgpick-box <?php echo $voucher_img_url ? 'has-img' : ''; ?>" data-target="hp_voucher_image_id" style="width:120px;height:80px;border:2px dashed #cbd5e1;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;background:#f8fafc;">
                                                        <?php if ($voucher_img_url): ?>
                                                            <img src="<?php echo esc_url($voucher_img_url); ?>" style="max-width:100%;max-height:100%;object-fit:cover;">
                                                        <?php else: ?>
                                                            <span class="dashicons dashicons-format-image" style="font-size:28px;width:28px;height:28px;color:#94a3b8;"></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <input type="hidden" name="<?php echo TIX_Settings::OPTION_KEY; ?>[hp_voucher_image_id]" id="hp_voucher_image_id" value="<?php echo $voucher_img_id ?: ''; ?>">
                                                    <?php if ($voucher_img_url): ?><a href="#" class="tix-hp-imgpick-clear" data-target="hp_voucher_image_id" style="font-size:12px;">Bild entfernen</a><?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="tix-field">
                                                <label class="tix-field-label" style="display:block;margin-bottom:4px;">Hintergrundfarbe</label>
                                                <input type="color" name="<?php echo TIX_Settings::OPTION_KEY; ?>[hp_voucher_bg]" value="<?php echo esc_attr($s['hp_voucher_bg'] ?? '#FFE066'); ?>">
                                            </div>
                                            <div class="tix-field">
                                                <label class="tix-field-label" style="display:block;margin-bottom:4px;">Textfarbe</label>
                                                <input type="color" name="<?php echo TIX_Settings::OPTION_KEY; ?>[hp_voucher_fg]" value="<?php echo esc_attr($s['hp_voucher_fg'] ?? '#0D0B09'); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Partner/Presse-Logos ── ?>
                                <?php $partners_logos = isset($s['hp_partners_logos']) && is_array($s['hp_partners_logos']) ? $s['hp_partners_logos'] : []; ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-businesswoman"></span>
                                        <h3>Partner/Presse-Logos</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_partners', 'Logo-Strip anzeigen', $s, 'Zentriert ausgerichtete Logos als Social Proof / Trust-Element.'); ?>
                                            </div>
                                            <?php self::text_row('hp_partners_title', 'Überschrift (optional)', $s, 'Bekannt aus & Partner'); ?>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label" style="display:block;margin-bottom:6px;">Logos</label>
                                                <div id="tix-hp-partners-list" style="display:flex;flex-direction:column;gap:10px;"></div>
                                                <button type="button" class="button" id="tix-hp-partners-add" style="margin-top:10px;">+ Logo hinzufügen</button>
                                                <input type="hidden" name="<?php echo TIX_Settings::OPTION_KEY; ?>[hp_partners_logos_json]" id="hp_partners_logos_json" value="<?php echo esc_attr(wp_json_encode($partners_logos)); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ═══ PHASE-3: WISHLIST / RECENT / PROMOTED / STORIES / GREETING ═══ ?>

                                <?php // ── Card: Smart-Greeting ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-smiley"></span>
                                        <h3>Smart-Greeting (Tageszeit-Text)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_greeting', 'Dynamischen Begrüßungstext anzeigen', $s, 'Zeigt je nach Tageszeit/Wochentag passende Texte wie „Guten Morgen, was steht heute Abend an?" oder „Wochenende ruft 🎉".'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Story-Carousel ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-images-alt2"></span>
                                        <h3>Story-Carousel (Insta-Style)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_stories', 'Story-Reihe anzeigen', $s, 'Horizontal scrollbare runde Cover-Bilder mit Gradient-Rand. Führt direkt auf Event-Detailseiten. Automatisch zentriert, scrollt erst wenn zu viele.'); ?>
                                            </div>
                                            <?php self::range_row('hp_stories_count', 'Anzahl Stories', $s, 5, 20, '', 1); ?>
                                            <?php self::range_row('hp_stories_size', 'Story-Größe (Durchmesser)', $s, 50, 160, 'px', 2); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Favoriten / Kürzlich ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-heart"></span>
                                        <h3>Wishlist &amp; Kürzlich angesehen</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_favorites', '„Deine Favoriten" Sektion anzeigen', $s, 'Zeigt Events, die der Besucher per Herz-Icon markiert hat. Wird per LocalStorage persistent gespeichert (auch für Gäste ohne Login).'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_recent', '„Kürzlich angesehen" Sektion anzeigen', $s, 'Zeigt die letzten ~10 Events, die der Besucher angeklickt hat. Nur sichtbar wenn Browsing-Verlauf vorhanden.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint" style="margin:0;">💡 Beide Sektionen blenden sich automatisch aus, wenn keine Daten vorhanden sind. Werden erst lazy-loaded via AJAX, wenn der Besucher was gespeichert hat.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Promoted Events ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-megaphone"></span>
                                        <h3>Promoted Events (bezahltes Placement)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_promoted', '„Empfohlene Events" Sektion anzeigen', $s, 'Events, die als Promoted markiert sind (Metabox im Event-Editor), werden hier mit „Anzeige"-Label dargestellt. Ideal für neuen Revenue-Stream.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint" style="margin:0;">💡 Einzelne Events als Promoted markieren: Event bearbeiten → Sidebar „✨ Promoted Event" (nur für Admins sichtbar).</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Empfehlung der Redaktion ── ?>
                                <?php
                                $editorial_event_id = intval($s['hp_editorial_event_id'] ?? 0);
                                $event_choices = get_posts([
                                    'post_type'      => 'event',
                                    'post_status'    => 'publish',
                                    'posts_per_page' => 100,
                                    'orderby'        => 'title',
                                    'order'          => 'ASC',
                                ]);
                                ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-edit-large"></span>
                                        <h3>Empfehlung der Redaktion</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_editorial', '„Empfehlung der Redaktion" Sektion anzeigen', $s, 'Ein kuratiertes Highlight-Event mit redaktionellem Fließtext. Baut Kompetenz &amp; Vertrauen auf („Warum du unbedingt hin musst").'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label" style="display:block;margin-bottom:6px;">Event auswählen <span style="color:#dc2626;">*</span></label>
                                                <select name="<?php echo TIX_Settings::OPTION_KEY; ?>[hp_editorial_event_id]" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
                                                    <option value="0">— Kein Event ausgewählt —</option>
                                                    <?php foreach ($event_choices as $ev):
                                                        $date = get_post_meta($ev->ID, '_tix_date_start', true);
                                                        $label = get_the_title($ev->ID);
                                                        if ($date) $label .= ' · ' . date_i18n('d.m.Y', strtotime($date));
                                                    ?>
                                                        <option value="<?php echo intval($ev->ID); ?>" <?php selected($editorial_event_id, $ev->ID); ?>><?php echo esc_html($label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <?php self::text_row('hp_editorial_label', 'Label (klein, über Titel)', $s, 'Empfehlung der Redaktion'); ?>
                                            <?php self::text_row('hp_editorial_title_override', 'Titel-Override (optional)', $s, 'Leer = Event-Titel'); ?>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label" style="display:block;margin-bottom:6px;">Redaktioneller Text</label>
                                                <?php
                                                wp_editor(
                                                    $s['hp_editorial_text'] ?? '',
                                                    'hp_editorial_text_editor',
                                                    [
                                                        'textarea_name' => TIX_Settings::OPTION_KEY . '[hp_editorial_text]',
                                                        'textarea_rows' => 6,
                                                        'media_buttons' => false,
                                                        'teeny'         => true,
                                                        'tinymce'       => ['toolbar1' => 'bold,italic,underline,link,unlink,bullist,numlist,undo,redo'],
                                                    ]
                                                );
                                                ?>
                                                <p class="tix-settings-hint" style="margin:6px 0 0;">Empfohlene Länge: 60–180 Wörter. Erzählend schreiben, wie in einer Zeitschrift — warum dieses Event besonders ist.</p>
                                            </div>
                                            <?php self::text_row('hp_editorial_byline', 'Autor / Byline (optional)', $s, 'z.B. Anna · Events-Redaktion'); ?>
                                            <?php self::text_row('hp_editorial_cta', 'CTA-Button-Text', $s, 'Event ansehen'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: FAQ-Accordion ── ?>
                                <?php $faq_items = isset($s['hp_faq_items']) && is_array($s['hp_faq_items']) ? $s['hp_faq_items'] : []; ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-editor-help"></span>
                                        <h3>FAQ-Accordion</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('hp_show_faq', 'FAQ-Sektion anzeigen', $s, 'Aufklappbare Fragen & Antworten.'); ?>
                                            </div>
                                            <?php self::text_row('hp_faq_title', 'Überschrift', $s, 'Häufige Fragen'); ?>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label" style="display:block;margin-bottom:6px;">Einträge</label>
                                                <div id="tix-hp-faq-list" style="display:flex;flex-direction:column;gap:10px;"></div>
                                                <button type="button" class="button" id="tix-hp-faq-add" style="margin-top:10px;">+ Frage hinzufügen</button>
                                                <input type="hidden" name="<?php echo TIX_Settings::OPTION_KEY; ?>[hp_faq_items_json]" id="hp_faq_items_json" value="<?php echo esc_attr(wp_json_encode($faq_items)); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <script>
                                (function($){
                                    // ── Image-Picker (Gutschein-Banner u.a.) ──
                                    $(document).on('click', '.tix-hp-imgpick-box', function(e){
                                        e.preventDefault();
                                        var $box = $(this);
                                        var target = $box.data('target');
                                        var frame = wp.media({ title: 'Bild wählen', button: { text: 'Einfügen' }, multiple: false, library: { type: 'image' } });
                                        frame.on('select', function(){
                                            var att = frame.state().get('selection').first().toJSON();
                                            $('#' + target).val(att.id);
                                            var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
                                            $box.addClass('has-img').html('<img src="' + url + '" style="max-width:100%;max-height:100%;object-fit:cover;">');
                                            if (!$box.siblings('.tix-hp-imgpick-clear').length) {
                                                $box.siblings('input[type=hidden]').after('<a href="#" class="tix-hp-imgpick-clear" data-target="' + target + '" style="font-size:12px;">Bild entfernen</a>');
                                            }
                                        });
                                        frame.open();
                                    });
                                    $(document).on('click', '.tix-hp-imgpick-clear', function(e){
                                        e.preventDefault();
                                        var target = $(this).data('target');
                                        $('#' + target).val('');
                                        $(this).closest('.tix-hp-imgpick').find('.tix-hp-imgpick-box').removeClass('has-img').html('<span class="dashicons dashicons-format-image" style="font-size:28px;width:28px;height:28px;color:#94a3b8;"></span>');
                                        $(this).remove();
                                    });

                                    // ── Partners Logo-Liste ──
                                    function renderPartners(){
                                        var data = [];
                                        try { data = JSON.parse($('#hp_partners_logos_json').val() || '[]'); } catch(e){ data = []; }
                                        var $list = $('#tix-hp-partners-list').empty();
                                        data.forEach(function(row, i){
                                            var imgUrl = row.image_url || '';
                                            var html = '<div class="tix-hp-partner-row" data-i="' + i + '" style="display:flex;gap:10px;align-items:center;padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;">'
                                                + '<div class="tix-hp-partner-img" style="width:80px;height:50px;border:1px dashed #cbd5e1;border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;background:#f8fafc;">'
                                                + (row.image_id ? '<img data-id="' + row.image_id + '" src="" style="max-width:100%;max-height:100%;object-fit:contain;">' : '<span class="dashicons dashicons-format-image" style="color:#94a3b8;"></span>')
                                                + '</div>'
                                                + '<input type="text" class="tix-hp-partner-alt" placeholder="Alt-Text" value="' + (row.alt || '').replace(/"/g,'&quot;') + '" style="width:140px;">'
                                                + '<input type="url" class="tix-hp-partner-link" placeholder="Link (optional)" value="' + (row.link || '').replace(/"/g,'&quot;') + '" style="flex:1;">'
                                                + '<button type="button" class="tix-hp-partner-remove button-link-delete" style="color:#b00;">Entfernen</button>'
                                                + '</div>';
                                            $list.append(html);
                                        });
                                        // Load image URLs async
                                        $list.find('img[data-id]').each(function(){
                                            var $img = $(this);
                                            var id = $img.data('id');
                                            if (!id) return;
                                            wp.media.attachment(id).fetch().then(function(){
                                                var att = wp.media.attachment(id).toJSON();
                                                var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                                                $img.attr('src', url);
                                            });
                                        });
                                    }
                                    function saveP(){
                                        var data = [];
                                        $('#tix-hp-partners-list .tix-hp-partner-row').each(function(){
                                            var $r = $(this);
                                            var imgId = $r.find('.tix-hp-partner-img img').data('id') || 0;
                                            data.push({
                                                image_id: imgId,
                                                alt: $r.find('.tix-hp-partner-alt').val(),
                                                link: $r.find('.tix-hp-partner-link').val(),
                                            });
                                        });
                                        $('#hp_partners_logos_json').val(JSON.stringify(data));
                                    }
                                    $('#tix-hp-partners-add').on('click', function(){
                                        var data = [];
                                        try { data = JSON.parse($('#hp_partners_logos_json').val() || '[]'); } catch(e){}
                                        data.push({ image_id: 0, alt: '', link: '' });
                                        $('#hp_partners_logos_json').val(JSON.stringify(data));
                                        renderPartners();
                                    });
                                    $(document).on('click', '.tix-hp-partner-img', function(){
                                        var $img = $(this);
                                        var frame = wp.media({ title: 'Logo wählen', button: { text: 'Einfügen' }, multiple: false, library: { type: 'image' } });
                                        frame.on('select', function(){
                                            var att = frame.state().get('selection').first().toJSON();
                                            var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                                            $img.html('<img data-id="' + att.id + '" src="' + url + '" style="max-width:100%;max-height:100%;object-fit:contain;">');
                                            saveP();
                                        });
                                        frame.open();
                                    });
                                    $(document).on('click', '.tix-hp-partner-remove', function(){
                                        $(this).closest('.tix-hp-partner-row').remove();
                                        saveP();
                                    });
                                    $(document).on('input change', '.tix-hp-partner-alt, .tix-hp-partner-link', saveP);

                                    // ── FAQ-Liste ──
                                    function renderFaq(){
                                        var data = [];
                                        try { data = JSON.parse($('#hp_faq_items_json').val() || '[]'); } catch(e){ data = []; }
                                        var $list = $('#tix-hp-faq-list').empty();
                                        data.forEach(function(row, i){
                                            var html = '<div class="tix-hp-faq-row" data-i="' + i + '" style="display:flex;flex-direction:column;gap:6px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;">'
                                                + '<input type="text" class="tix-hp-faq-q" placeholder="Frage" value="' + (row.question || '').replace(/"/g,'&quot;') + '" style="font-weight:600;">'
                                                + '<textarea class="tix-hp-faq-a" placeholder="Antwort" rows="3" style="resize:vertical;">' + (row.answer || '').replace(/</g,'&lt;') + '</textarea>'
                                                + '<button type="button" class="tix-hp-faq-remove button-link-delete" style="align-self:flex-start;color:#b00;">Entfernen</button>'
                                                + '</div>';
                                            $list.append(html);
                                        });
                                    }
                                    function saveF(){
                                        var data = [];
                                        $('#tix-hp-faq-list .tix-hp-faq-row').each(function(){
                                            var $r = $(this);
                                            data.push({
                                                question: $r.find('.tix-hp-faq-q').val(),
                                                answer:   $r.find('.tix-hp-faq-a').val(),
                                            });
                                        });
                                        $('#hp_faq_items_json').val(JSON.stringify(data));
                                    }
                                    $('#tix-hp-faq-add').on('click', function(){
                                        var data = [];
                                        try { data = JSON.parse($('#hp_faq_items_json').val() || '[]'); } catch(e){}
                                        data.push({ question: '', answer: '' });
                                        $('#hp_faq_items_json').val(JSON.stringify(data));
                                        renderFaq();
                                    });
                                    $(document).on('click', '.tix-hp-faq-remove', function(){
                                        $(this).closest('.tix-hp-faq-row').remove();
                                        saveF();
                                    });
                                    $(document).on('input change', '.tix-hp-faq-q, .tix-hp-faq-a', saveF);

                                    // Initial Render
                                    renderPartners();
                                    renderFaq();
                                })(jQuery);
                                </script>

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

                                <?php // ── Card: Chat-Bot Schrift ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-format-chat"></span>
                                        <h3>Chat-Bot Schrift</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field">
                                                <label class="tix-field-label">Bot-Font</label>
                                                <select name="tix_settings[bot_font]" style="width:100%;">
                                                    <?php foreach ($fonts as $f): ?>
                                                        <option value="<?php echo esc_attr($f); ?>" <?php selected($s['bot_font'] ?? 'Inter', $f); ?>><?php echo esc_html($f); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <p class="tix-field-hint" style="margin-top:8px;">Schriftart f&uuml;r das Chat-Widget. Standard: Inter.</p>
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
                                            var defSize = parseInt(row.dataset.defSize, 10);
                                            var defFont = row.dataset.defFont;
                                            var defWeight = parseInt(row.dataset.defWeight, 10);
                                            // Desktop size — nur senden wenn abweichend vom Default
                                            if (sizeInput.value !== '' && parseInt(sizeInput.value, 10) !== defSize) {
                                                entry.size = parseInt(sizeInput.value, 10);
                                            }
                                            // Responsive breakpoint sizes — nur senden wenn gesetzt
                                            ['size_tl', 'size_tp', 'size_pl', 'size_pp'].forEach(function(p) {
                                                var inp = row.querySelector('input[data-prop="' + p + '"]');
                                                if (inp && inp.value !== '') entry[p] = parseInt(inp.value, 10);
                                            });
                                            // Font + Weight — nur senden wenn abweichend vom Default
                                            if (fontSelect.value !== defFont) entry.font = fontSelect.value;
                                            if (parseInt(weightSelect.value, 10) !== defWeight) entry.weight = parseInt(weightSelect.value, 10);
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
                                .tix-clr-palette-swatches { display: flex; gap: 2px; flex-wrap: wrap; min-height: 0; }
                                .tix-clr-palette-swatches:empty { display: none; }
                                .tix-clr-palette-btn { width: 14px; height: 14px; border-radius: 3px; border: 1px solid rgba(0,0,0,.1); cursor: pointer; padding: 0; transition: transform .1s; flex-shrink: 0; }
                                .tix-clr-palette-btn:hover { transform: scale(1.3); box-shadow: 0 0 0 1.5px var(--tix-primary, #FF5500); z-index: 1; }
                                @media (max-width: 900px) {
                                    .tix-clr-row { grid-template-columns: 1fr; gap: 4px; padding: 10px 14px; }
                                    .tix-clr-row-head { display: none; }
                                    .tix-clr-cell.tix-clr-empty { display: none; }
                                }
                                </style>

                                <?php // ── Card: Chat-Bot Farben ── ?>
                                <div class="tix-card" style="margin-bottom:16px;">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-format-chat"></span>
                                        <h3>Chat-Bot</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <?php
                                        $bot_color_map = [
                                            'bot_color_accent'       => ['label' => 'Akzentfarbe',        'hint' => 'Buttons, Links, Bubble-Button'],
                                            'bot_color_accent_hover' => ['label' => 'Akzent Hover',       'hint' => 'Hover-Zustand der Akzentfarbe'],
                                            'bot_color_bg'           => ['label' => 'Hintergrund',        'hint' => 'Haupt-Hintergrund des Widgets'],
                                            'bot_color_bg_header'    => ['label' => 'Header-Hintergrund', 'hint' => 'Kopfbereich und Eingabebereich'],
                                            'bot_color_bg_card'      => ['label' => 'Karten-Hintergrund', 'hint' => 'Nachrichtenblasen, Willkommens-Karte'],
                                            'bot_color_bg_input'     => ['label' => 'Eingabe-Hintergrund','hint' => 'Bereich um das Eingabefeld'],
                                            'bot_color_bg_input_field' => ['label' => 'Eingabefeld',      'hint' => 'Das Textfeld selbst'],
                                            'bot_color_text'         => ['label' => 'Text',               'hint' => 'Haupttextfarbe'],
                                            'bot_color_text_muted'   => ['label' => 'Text Gedämpft',   'hint' => 'Sekundärer Text'],
                                            'bot_color_text_light'   => ['label' => 'Text Hell',          'hint' => 'Uhrzeit, Platzhalter'],
                                            'bot_color_border'       => ['label' => 'Rahmen',             'hint' => 'Trennlinien und Rahmen'],
                                            'bot_color_user_bubble'  => ['label' => 'User-Nachricht',     'hint' => 'Hintergrund der Nutzer-Blase'],
                                            'bot_color_user_text'    => ['label' => 'User-Text',          'hint' => 'Textfarbe in der Nutzer-Blase'],
                                        ];
                                        ?>
                                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;">
                                            <?php foreach ($bot_color_map as $key => $meta): ?>
                                            <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:8px;background:#fafbfc;">
                                                <div class="tix-clr-swatch" style="background:<?php echo esc_attr($s[$key] ?? $d[$key]); ?>;flex-shrink:0;">
                                                    <input type="color" value="<?php
                                                        $cv = $s[$key] ?? $d[$key];
                                                        // Color picker braucht hex - rgba Fallback
                                                        echo esc_attr(preg_match('/^#[0-9a-fA-F]{6}$/', $cv) ? $cv : '#FF5500');
                                                    ?>" onchange="this.closest('.tix-clr-swatch').style.background=this.value;this.closest('.tix-clr-swatch').nextElementSibling.querySelector('input').value=this.value;">
                                                </div>
                                                <div style="flex:1;min-width:0;">
                                                    <div style="font-size:12px;font-weight:600;color:#374151;"><?php echo esc_html($meta['label']); ?></div>
                                                    <div style="font-size:10px;color:#9ca3af;"><?php echo esc_html($meta['hint']); ?></div>
                                                    <input type="text" name="tix_settings[<?php echo $key; ?>]" value="<?php echo esc_attr($s[$key] ?? $d[$key]); ?>" style="width:100%;margin-top:4px;padding:3px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11px;font-family:monospace;" spellcheck="false" onchange="var sw=this.closest('div').parentNode.querySelector('.tix-clr-swatch');if(sw)sw.style.background=this.value;">
                                                    <div class="tix-palette-swatches tix-bot-palette" data-target-key="<?php echo esc_attr($key); ?>" style="margin-top:4px;"></div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>

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
                                                <div style="display:flex;flex-direction:column;gap:2px;">
                                                    <div style="display:flex;align-items:center;gap:4px;">
                                                        <div class="tix-clr-swatch" style="background:<?php echo esc_attr($cur_val); ?>;">
                                                            <input type="color" data-prop="<?php echo $prop; ?>" data-role="picker" value="<?php echo esc_attr($cur_val); ?>" tabindex="-1">
                                                        </div>
                                                        <input type="text" data-prop="<?php echo $prop; ?>" data-role="hex" value="<?php echo esc_attr($cur_val); ?>" class="tix-clr-hex <?php echo $is_changed ? 'tix-clr-changed' : ''; ?>" maxlength="9" spellcheck="false">
                                                    </div>
                                                    <div class="tix-clr-palette-swatches" data-prop="<?php echo $prop; ?>"></div>
                                                </div>
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
                                                if (hex.value.trim() !== '') {
                                                    entry[p] = hex.value.trim();
                                                }
                                            });
                                            if (Object.keys(entry).length > 0) overrides[cls] = entry;
                                        });
                                        document.getElementById('tix-color-json').value = JSON.stringify(overrides);
                                    });
                                }

                                // ── Palette-Swatches in Akkordeon-Farbzellen ──
                                function tixRefreshClrPaletteSwatches() {
                                    // Palette aus dem Repeater lesen (gleiche Quelle wie refreshAllPaletteSwatches)
                                    var palette = [];
                                    var repeater = document.getElementById('tix-palette-repeater');
                                    if (repeater) {
                                        repeater.querySelectorAll('.tix-palette-row').forEach(function(row) {
                                            var name  = row.querySelector('.tix-palette-name') ? row.querySelector('.tix-palette-name').value.trim() : '';
                                            var color = row.querySelector('.tix-palette-color-input') ? row.querySelector('.tix-palette-color-input').value.trim() : '';
                                            if (name && color) palette.push({name: name, color: color});
                                        });
                                    }
                                    // Alle .tix-clr-palette-swatches im Colors-Pane befüllen
                                    document.querySelectorAll('.tix-clr-palette-swatches').forEach(function(container) {
                                        var prop = container.dataset.prop;
                                        var row  = container.closest('.tix-clr-row');
                                        container.innerHTML = '';
                                        if (palette.length === 0) return;
                                        palette.forEach(function(entry) {
                                            var btn = document.createElement('button');
                                            btn.type = 'button';
                                            btn.className = 'tix-clr-palette-btn';
                                            btn.style.background = entry.color;
                                            btn.title = entry.name + ': ' + entry.color;
                                            btn.addEventListener('click', function() {
                                                if (!row) return;
                                                var hex = row.querySelector('input[data-role="hex"][data-prop="' + prop + '"]');
                                                var picker = row.querySelector('input[data-role="picker"][data-prop="' + prop + '"]');
                                                var swatch = row.querySelector('.tix-clr-swatch input[data-prop="' + prop + '"]');
                                                if (hex) {
                                                    hex.value = entry.color;
                                                    var defKey = 'def' + prop.charAt(0).toUpperCase() + prop.slice(1);
                                                    hex.classList.toggle('tix-clr-changed', entry.color !== (row.dataset[defKey] || ''));
                                                }
                                                if (picker && entry.color.match(/^#[0-9a-fA-F]{6}$/)) picker.value = entry.color;
                                                if (swatch) swatch.closest('.tix-clr-swatch').style.background = entry.color;
                                                tixColorUpdateResetBtn(row);
                                            });
                                            container.appendChild(btn);
                                        });
                                    });
                                }
                                // Initial render + hook into palette changes
                                tixRefreshClrPaletteSwatches();
                                // Re-render whenever Design palette changes (observed via MutationObserver on repeater)
                                var clrRepeater = document.getElementById('tix-palette-repeater');
                                if (clrRepeater) {
                                    new MutationObserver(function() { setTimeout(tixRefreshClrPaletteSwatches, 50); })
                                        .observe(clrRepeater, {childList: true, subtree: true, characterData: true});
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
                                            <div class="tix-field tix-field-full">
                                                <button type="button" id="tix-meta-test-btn" class="button button-secondary" style="display:inline-flex;align-items:center;gap:6px;">
                                                    <span class="dashicons dashicons-controls-play" style="font-size:16px;width:16px;height:16px;vertical-align:middle;"></span> Test-Event senden
                                                </button>
                                                <span id="tix-meta-test-result" style="margin-left:10px;font-size:13px;"></span>
                                                <p class="tix-field-hint" style="margin-top:8px;">Sendet ein PageView-Event an Meta. Bei gesetztem Test Event Code erscheint es sofort im <a href="https://business.facebook.com/events_manager" target="_blank">Events Manager → Test Events</a>. Ohne Code im normalen Event-Stream.</p>
                                                <script>
                                                (function($){
                                                    $('#tix-meta-test-btn').on('click', function(){
                                                        var $btn = $(this), $res = $('#tix-meta-test-result');
                                                        $btn.prop('disabled', true);
                                                        $res.html('<span style="color:#64748b;">Sende…</span>');
                                                        $.post(ajaxurl, {action:'tix_meta_test_pixel', nonce:'<?php echo wp_create_nonce('tix_admin_nonce'); ?>'}, function(r){
                                                            $btn.prop('disabled', false);
                                                            if (r.success) {
                                                                $res.html('<span style="background:#d1fae5;color:#065f46;padding:4px 10px;border-radius:5px;">✓ ' + r.data.message + ' (events_received: ' + r.data.events_received + ')</span>');
                                                            } else {
                                                                $res.html('<span style="background:#fee2e2;color:#991b1b;padding:4px 10px;border-radius:5px;">✗ ' + (r.data && r.data.message ? r.data.message : 'Fehler') + '</span>');
                                                            }
                                                        }).fail(function(){
                                                            $btn.prop('disabled', false);
                                                            $res.html('<span style="background:#fee2e2;color:#991b1b;padding:4px 10px;border-radius:5px;">✗ Netzwerk-Fehler</span>');
                                                        });
                                                    });
                                                })(jQuery);
                                                </script>
                                            </div>
                                        </div>

                                        <?php // ── Letzte CAPI-Conversions (Server-side Purchase-Events) ── ?>
                                        <?php
                                        global $wpdb;
                                        $conv_table = $wpdb->prefix . 'tix_meta_conversions';
                                        $has_table = $wpdb->get_var("SHOW TABLES LIKE '$conv_table'") === $conv_table;
                                        if ($has_table) {
                                            $convs = $wpdb->get_results("SELECT * FROM $conv_table ORDER BY id DESC LIMIT 20");
                                            ?>
                                            <div style="margin-top:18px;border-top:1px solid #e5e7eb;padding-top:14px;">
                                                <h4 style="margin:0 0 10px;font-size:13px;color:#0f172a;display:flex;justify-content:space-between;align-items:center;">
                                                    <span>📡 Letzte 20 CAPI-Übertragungen</span>
                                                    <?php $count_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $conv_table"); ?>
                                                    <span style="font-weight:400;color:#64748b;font-size:12px;">Insgesamt: <?php echo $count_total; ?></span>
                                                </h4>
                                                <?php if (empty($convs)): ?>
                                                    <p style="color:#9ca3af;font-size:13px;background:#f9fafb;padding:12px;border-radius:6px;">Noch keine CAPI-Conversions übertragen. Sobald die erste bezahlte Bestellung eingeht, erscheint sie hier.</p>
                                                <?php else: ?>
                                                    <div style="max-height:280px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;">
                                                    <table class="widefat striped" style="margin:0;font-size:12px;">
                                                        <thead><tr>
                                                            <th>Zeitpunkt</th><th>Event</th><th>Order</th><th>Wert</th><th>UTM-Source</th><th>fbclid</th><th>Event-ID (Dedup)</th>
                                                        </tr></thead>
                                                        <tbody>
                                                            <?php foreach ($convs as $c): ?>
                                                            <tr>
                                                                <td><?php echo esc_html(date_i18n('d.m.Y H:i:s', strtotime($c->created_at))); ?></td>
                                                                <td><strong><?php echo esc_html($c->meta_event_name); ?></strong></td>
                                                                <td><?php echo $c->order_id ? '<a href="' . esc_url(admin_url('admin.php?page=tix-orders&order_id=' . intval($c->order_id))) . '">#' . intval($c->order_id) . '</a>' : '—'; ?></td>
                                                                <td><?php echo $c->value > 0 ? number_format(floatval($c->value), 2, ',', '.') . ' ' . esc_html($c->currency) : '—'; ?></td>
                                                                <td><?php echo esc_html($c->utm_source ?: '—'); ?></td>
                                                                <td><?php echo $c->fbclid ? '<span style="color:#16a34a;" title="fbclid vorhanden — Klick aus Meta-Ad zurückverfolgt">✓</span>' : '<span style="color:#9ca3af;">—</span>'; ?></td>
                                                                <td><code style="font-size:10px;background:#f1f5f9;padding:1px 4px;border-radius:3px;"><?php echo esc_html(mb_substr($c->event_id ?: '', 0, 22)); ?></code></td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                    </div>
                                                    <p class="tix-field-hint" style="margin-top:8px;">Jede Zeile = ein Server-seitig an Meta gesendeter Purchase-Event. <strong>Event-ID</strong> ist der Dedup-Key zwischen Browser-Pixel und CAPI (Meta zählt nicht doppelt). <strong>fbclid</strong>-Spalte ✓ bedeutet, der Kauf konnte einer Meta-Anzeige zugeordnet werden.</p>
                                                <?php endif; ?>
                                            </div>
                                            <?php
                                        }
                                        ?>

                                        <div style="margin-top:14px;padding:12px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:13px;color:#1e3a8a;">
                                            <strong>🔍 Externe Kontrolle:</strong>
                                            <ul style="margin:6px 0 0 18px;padding:0;list-style:disc;">
                                                <li><a href="https://business.facebook.com/events_manager" target="_blank">Meta Events Manager</a> → Pixel auswählen → Tab <strong>Test Events</strong> (Test Code oben eingeben) — zeigt Events live nach 1–2 Sekunden.</li>
                                                <li>Browser-Plugin <a href="https://chrome.google.com/webstore/detail/meta-pixel-helper/fdgfkebogiimcoedlicjlajpkdmockpc" target="_blank">Meta Pixel Helper</a> (Chrome) — prüft Pixel auf einzelnen Seiten.</li>
                                                <li>Events Manager → <strong>Übersicht</strong> → „Server" zeigt CAPI-Eingang, „Browser" den Pixel — gute Score = beide ähnlich hoch + Dedup-Match.</li>
                                            </ul>
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

                                <?php // ── Card: Google Ads ── ?>
                                <div class="tix-card" style="border-left:4px solid #4285f4;">
                                    <div class="tix-card-header" style="background:linear-gradient(90deg,#fff,#f0f7ff);">
                                        <span class="dashicons dashicons-google" style="color:#4285f4;"></span>
                                        <h3>Google Ads</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-field-hint" style="margin-bottom:12px;">Conversion-Tracking für Google Ads-Kampagnen. Sendet Purchase-Events mit Bestellwert auf der Thank-You-Seite.</p>
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('google_ads_enabled', 'Google Ads Tracking aktivieren', $s, 'Lädt das gtag.js-Snippet im Frontend.'); ?>
                                            </div>
                                            <?php self::text_row('google_ads_id', 'Google Ads Conversion-ID', $s, 'AW-1234567890'); ?>
                                            <?php self::text_row('google_ads_conversion_label', 'Conversion-Label (Purchase)', $s, 'AbCdEfGhIjK'); ?>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('google_ads_send_purchase', 'Purchase-Conversion auf Thank-You senden', $s, 'Sendet automatisch das Conversion-Event mit Bestellwert (transaction_id, value).'); ?>
                                            </div>
                                        </div>
                                        <details style="margin-top:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;">
                                            <summary style="cursor:pointer;font-weight:600;font-size:12px;">Wo finde ich diese Werte?</summary>
                                            <div style="font-size:12px;color:#475569;line-height:1.6;margin-top:8px;">
                                                <strong>Conversion-ID</strong>: Im Google-Ads-Konto → Tools &amp; Einstellungen → Conversions → eigener Conversion-Eintrag → Tag-Setup → "Conversion-ID" (Format: <code>AW-1234567890</code>).<br>
                                                <strong>Conversion-Label</strong>: Direkt darunter unter "Conversion-Label" (Format: zufälliger 11-Zeichen-String wie <code>AbC1dEf2gH3</code>).
                                            </div>
                                        </details>
                                    </div>
                                </div>

                                <?php // ── Card: Google Analytics 4 ── ?>
                                <div class="tix-card" style="border-left:4px solid #f59e0b;">
                                    <div class="tix-card-header" style="background:linear-gradient(90deg,#fff,#fffbeb);">
                                        <span class="dashicons dashicons-chart-line" style="color:#f59e0b;"></span>
                                        <h3>Google Analytics 4 (GA4)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-field-hint" style="margin-bottom:12px;">GA4-Tracking für Page-Views und E-Commerce-Events (view_item, add_to_cart, purchase).</p>
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ga4_enabled', 'GA4 aktivieren', $s, 'Lädt das gtag.js-Snippet im Frontend.'); ?>
                                            </div>
                                            <?php self::text_row('ga4_measurement_id', 'Measurement-ID', $s, 'G-XXXXXXXXXX'); ?>
                                        </div>
                                        <p class="tix-field-hint" style="margin-top:12px;font-size:12px;">Findest du in GA4 → Verwaltung → Datenstreams → Web-Stream → Mess-ID (beginnt mit <code>G-</code>).</p>
                                    </div>
                                </div>

                                <?php // ── Card: Google Tag Manager (optional) ── ?>
                                <div class="tix-card" style="border-left:4px solid #34a853;">
                                    <div class="tix-card-header" style="background:linear-gradient(90deg,#fff,#f0fdf4);">
                                        <span class="dashicons dashicons-tag" style="color:#34a853;"></span>
                                        <h3>Google Tag Manager (optional)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-field-hint" style="margin-bottom:12px;">Falls du GTM nutzt, kannst du hier die Container-ID eintragen — alle Pixel laufen dann zentral darüber.</p>
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('gtm_enabled', 'GTM aktivieren', $s, 'Lädt den GTM-Container im Frontend (head + body noscript).'); ?>
                                            </div>
                                            <?php self::text_row('gtm_container_id', 'GTM Container-ID', $s, 'GTM-XXXXXXX'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Google Consent ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-privacy"></span>
                                        <h3>Google Consent-Modus</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-field-hint" style="margin-bottom:12px;">DSGVO-konformes Laden der Google-Tags — wartet bis Cookie-Consent gegeben wurde.</p>
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label">Consent-Modus</label>
                                                <select name="<?php echo self::OPTION_KEY; ?>[google_consent_mode]" class="tix-select-input">
                                                    <option value="always" <?php selected($s['google_consent_mode'] ?? 'always', 'always'); ?>>Tags immer laden</option>
                                                    <option value="consent_required" <?php selected($s['google_consent_mode'] ?? '', 'consent_required'); ?>>Nur nach Cookie-Consent laden</option>
                                                </select>
                                            </div>
                                            <?php self::text_row('google_consent_cookie', 'Consent-Cookie Name', $s, 'cookie_consent'); ?>
                                        </div>
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

                                <?php // ── Card: Event-Verteilung (Push) ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-rss"></span>
                                        <h3>Event-Verteilung (Senden)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint" style="margin-top:0;">
                                                    Events automatisch an eine zentrale Plattform senden. Aktiviere pro Event die Checkbox „Auf Plattform veröffentlichen".
                                                </p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('syndication_enabled', 'Verteilung aktivieren', $s, 'Events können an eine externe Plattform gesendet werden.'); ?>
                                            </div>
                                            <?php self::text_row('syndication_api_url', 'Plattform API-URL', $s, 'https://evendis.de/wp-json/tixomat/v1'); ?>
                                            <?php self::text_row('syndication_api_key', 'API Key', $s, 'tix_syn_...'); ?>
                                            <?php self::text_row('syndication_site_name', 'Anzeigename dieser Seite', $s, 'z.B. Kitchen Klub'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Event-Verteilung (Empfang) ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-download"></span>
                                        <h3>Event-Verteilung (Empfang)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint" style="margin-top:0;">
                                                    Events von externen Tixomat-Installationen empfangen. Verteilte Events werden automatisch erstellt und bei Klick auf „Tickets" zur Quellseite weitergeleitet.
                                                </p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('syndication_receive_enabled', 'Empfang aktivieren', $s, 'Diese Seite kann verteilte Events empfangen.'); ?>
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
                                                <p class="tix-settings-hint">Diesen Key dem Sender mitteilen. Er wird als Authentifizierungs-Header gesendet.</p>
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
                                            <div class="tix-field">
                                                <label class="tix-field-label">Promoter-Dashboard-Seite</label>
                                                <?php
                                                wp_dropdown_pages([
                                                    'selected'         => intval($s['promoter_page_id'] ?? 0),
                                                    'name'             => self::OPTION_KEY . '[promoter_page_id]',
                                                    'show_option_none' => '— Seite wählen —',
                                                    'option_none_value'=> 0,
                                                ]);
                                                $promoter_page_id = intval($s['promoter_page_id'] ?? 0);
                                                if ($promoter_page_id):
                                                    $page_url = get_permalink($promoter_page_id);
                                                    if ($page_url): ?>
                                                        <p class="tix-settings-hint" style="margin-top:6px;">
                                                            <span class="dashicons dashicons-yes-alt" style="color:#10b981;font-size:14px;width:14px;height:14px;vertical-align:text-top;"></span>
                                                            <a href="<?php echo esc_url($page_url); ?>" target="_blank"><?php echo esc_html($page_url); ?></a>
                                                        </p>
                                                    <?php endif;
                                                endif; ?>
                                                <p class="tix-settings-hint">Auf welcher Seite das Promoter-Dashboard liegt (mit Shortcode <code>[tix_promoter_dashboard]</code>). Wird f&uuml;r Login-Redirects, Magic-Link-Mails und den Account-Link genutzt. Wenn nicht gesetzt: Slug <code>/promoter/</code> wird probiert.</p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('promoter_fullscreen', 'Fullscreen-Modus für Promoter-Dashboard', $s, 'Zeigt das Promoter-Dashboard auf voller Seitenbreite ohne WordPress-Theme-Elemente (Header, Footer, Sidebar). Nutzt eine eigene Top-Bar mit Logo + Logout. Empfohlen: <strong>aktiv</strong>.'); ?>
                                            </div>
                                            <div class="tix-field">
                                                <label class="tix-field-label">Anmeldemethode</label>
                                                <?php $auth_method = $s['promoter_auth_method'] ?? 'both'; ?>
                                                <select name="<?php echo self::OPTION_KEY; ?>[promoter_auth_method]">
                                                    <option value="both" <?php selected($auth_method, 'both'); ?>>Beide — Magic-Link UND WordPress-Login</option>
                                                    <option value="magic_link" <?php selected($auth_method, 'magic_link'); ?>>Nur Magic-Link (E-Mail)</option>
                                                    <option value="wp_login"   <?php selected($auth_method, 'wp_login'); ?>>Nur WordPress-Login</option>
                                                </select>
                                                <p class="tix-settings-hint"><strong>Magic-Link:</strong> Promoter gibt seine E-Mail ein, bekommt einen Login-Link per Mail (kein WP-Account n&ouml;tig). <strong>Beide:</strong> empfohlen f&uuml;r maximale Flexibilit&auml;t.</p>
                                            </div>
                                            <!-- ═══════════════════════════════════════
                                                 SUB-CARD A: Standard-Provision (Live-Fallback)
                                                 ═══════════════════════════════════════ -->
                                            <div class="tix-field tix-field-full" style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:14px 18px;margin-top:8px;">
                                                <h4 style="margin:0 0 6px;display:flex;align-items:center;gap:8px;color:#075985;font-size:14px;">
                                                    <span class="dashicons dashicons-shield" style="color:#075985;"></span>
                                                    Standard-Provision &mdash; Live-Fallback
                                                    <span class="tix-tooltip" data-tix-tip="Wird zur Laufzeit angewandt — speichert NICHTS in der DB. Greift, wenn ein Promoter weder eine event-spezifische noch eine globale Zuordnung hat. Änderungen wirken sofort für alle." style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#075985;color:#fff;font-size:11px;font-weight:700;cursor:help;">?</span>
                                                </h4>
                                                <p style="margin:0 0 10px;color:#075985;font-size:12px;line-height:1.6;">
                                                    Gilt f&uuml;r <strong>alle</strong> Promoter und Events, wenn nirgendwo eine spezifische Provision gesetzt ist.
                                                    <br><strong>Hierarchie:</strong> Event-spezifische Zuordnung &gt; globale Promoter-Zuordnung (&bdquo;Alle Events&ldquo;) &gt; <em>Standard hier</em>.
                                                </p>
                                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                                                    <div>
                                                        <label class="tix-field-label" style="font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:#0f172a;">Provisions-Typ</label>
                                                        <?php $def_t = $s['promoter_default_commission_type'] ?? 'percent'; ?>
                                                        <select name="<?php echo self::OPTION_KEY; ?>[promoter_default_commission_type]" style="width:100%;">
                                                            <option value="percent" <?php selected($def_t, 'percent'); ?>>Prozent (%)</option>
                                                            <option value="fixed"   <?php selected($def_t, 'fixed'); ?>>Festbetrag (&euro; pro Ticket)</option>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="tix-field-label" style="font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:#0f172a;">Wert</label>
                                                        <input type="number" step="0.01" min="0" max="100"
                                                               name="<?php echo self::OPTION_KEY; ?>[promoter_default_commission_value]"
                                                               value="<?php echo esc_attr(number_format(floatval($s['promoter_default_commission_value'] ?? 0), 2, '.', '')); ?>"
                                                               style="width:100%;">
                                                    </div>
                                                </div>
                                                <p style="margin:8px 0 0;color:#075985;font-size:11px;font-style:italic;">10 = 10% &middot; 2 = 2&euro; pro Ticket &middot; <strong>0</strong> = kein Default (nur explizite Zuordnungen geben Provision).</p>
                                            </div>

                                            <!-- ═══════════════════════════════════════
                                                 SUB-CARD B: Empfehlungsprogramm (Self-Signup)
                                                 ═══════════════════════════════════════ -->
                                            <div class="tix-field tix-field-full" style="background:#fefce8;border:1px solid #fde68a;border-radius:10px;padding:14px 18px;margin-top:14px;">
                                                <h4 style="margin:0 0 6px;display:flex;align-items:center;gap:8px;color:#7c2d12;font-size:14px;">
                                                    <span class="dashicons dashicons-megaphone" style="color:#7c2d12;"></span>
                                                    Empfehlungsprogramm &mdash; Self-Signup
                                                    <span class="tix-tooltip" data-tix-tip="Wird einmal bei Self-Signup-Anmeldung in die DB geschrieben. Aenderungen wirken NUR fuer NEUE Anmeldungen — bestehende Promoter behalten ihre alte Quote." style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#7c2d12;color:#fff;font-size:11px;font-weight:700;cursor:help;">?</span>
                                                </h4>
                                                <p style="margin:0 0 10px;color:#7c2d12;font-size:12px;line-height:1.6;">
                                                    L&auml;sst K&auml;ufer sich selbst als Promoter anmelden &uuml;ber den Shortcode <code style="background:rgba(255,255,255,0.7);padding:1px 6px;border-radius:4px;">[tix_promoter_signup]</code>.
                                                    Die Provisions-Werte werden bei der Anmeldung als <strong>feste Zuordnung</strong> in der Datenbank gespeichert.
                                                </p>

                                                <div class="tix-field tix-field-full" style="margin-bottom:12px;">
                                                    <?php self::checkbox_row('promoter_self_signup', 'Self-Signup erlauben', $s); ?>
                                                </div>

                                                <div id="tix-signup-fields" style="<?php echo empty($s['promoter_self_signup']) ? 'display:none;' : ''; ?>">
                                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                                                        <div>
                                                            <label class="tix-field-label" style="font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:#0f172a;">Initial-Provisions-Typ</label>
                                                            <select name="tix_settings[promoter_signup_commission_type]" style="width:100%;">
                                                                <option value="fixed" <?php selected($s['promoter_signup_commission_type'] ?? 'fixed', 'fixed'); ?>>Festbetrag (&euro;)</option>
                                                                <option value="percent" <?php selected($s['promoter_signup_commission_type'] ?? 'fixed', 'percent'); ?>>Prozent (%)</option>
                                                            </select>
                                                        </div>
                                                        <div>
                                                            <label class="tix-field-label" style="font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:#0f172a;">Initial-Wert</label>
                                                            <input type="text"
                                                                   name="tix_settings[promoter_signup_commission_value]"
                                                                   value="<?php echo esc_attr($s['promoter_signup_commission_value'] ?? '2'); ?>"
                                                                   style="width:100%;"
                                                                   placeholder="z.B. 2">
                                                        </div>
                                                    </div>
                                                    <div class="tix-field tix-field-full" style="margin-top:12px;">
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

                                            <!-- ═══════════════════════════════════════
                                                 Tooltip-Markup + Self-Signup-Toggle-JS
                                                 ═══════════════════════════════════════ -->
                                            <style>
                                                .tix-tooltip { position: relative; }
                                                .tix-tooltip:hover::after,
                                                .tix-tooltip:focus::after {
                                                    content: attr(data-tix-tip);
                                                    position: absolute;
                                                    left: 50%;
                                                    bottom: 100%;
                                                    transform: translateX(-50%) translateY(-8px);
                                                    background: #0f172a;
                                                    color: #fff;
                                                    padding: 8px 12px;
                                                    border-radius: 6px;
                                                    font-size: 12px;
                                                    font-weight: 400;
                                                    line-height: 1.5;
                                                    width: 280px;
                                                    text-align: left;
                                                    white-space: normal;
                                                    z-index: 1000;
                                                    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
                                                    pointer-events: none;
                                                }
                                                .tix-tooltip:hover::before,
                                                .tix-tooltip:focus::before {
                                                    content: '';
                                                    position: absolute;
                                                    left: 50%;
                                                    bottom: 100%;
                                                    transform: translateX(-50%);
                                                    border: 6px solid transparent;
                                                    border-top-color: #0f172a;
                                                    z-index: 1000;
                                                    pointer-events: none;
                                                }
                                            </style>
                                            <script>
                                                (function() {
                                                    var checkbox = document.querySelector('input[name="tix_settings[promoter_self_signup]"]');
                                                    var wrap = document.getElementById('tix-signup-fields');
                                                    if (checkbox && wrap) {
                                                        checkbox.addEventListener('change', function() {
                                                            wrap.style.display = this.checked ? '' : 'none';
                                                        });
                                                    }
                                                })();
                                            </script>
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

                                <?php // Rechnungs-Card wurde in den eigenen "Rechnungen"-Tab verschoben ?>

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

                                <?php // ── Card: Fun Mode ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-smiley"></span>
                                        <h3>Fun Mode</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('fun_topbar', 'Motivations-Topbar anzeigen', $s, 'Zeigt eine motivierende Nachricht am oberen Bildschirmrand an. Weil du es verdient hast.'); ?>
                                            </div>
                                            <?php self::text_row('fun_topbar_text', 'Topbar-Text', $s, '{name}, du bist eine geile Sau'); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint"><code>{name}</code> wird durch den Anzeigenamen des Nutzers ersetzt.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Auto-Archiv ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-archive"></span>
                                        <h3>Auto-Archiv</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('auto_archive_enabled', 'Abgelaufene Events automatisch archivieren', $s, 'L&auml;uft t&auml;glich und verschiebt Events, deren Enddatum vorbei ist, ins Archiv. Archivierte Events werden im Frontend nicht mehr angezeigt.'); ?>
                                            </div>
                                            <div class="tix-field">
                                                <label class="tix-field-label" for="tix-auto-archive-days">Gnadenfrist (Tage)</label>
                                                <input type="number" min="0" max="365" step="1" id="tix-auto-archive-days"
                                                    name="tix_settings[auto_archive_days]"
                                                    value="<?php echo esc_attr(intval($s['auto_archive_days'] ?? 0)); ?>"
                                                    class="small-text">
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">Anzahl Tage nach dem Enddatum, bevor archiviert wird. <code>0</code> = sofort am Tag nach dem Event.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Auto-Complete ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        <h3>Auto-Complete</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('auto_complete_enabled', 'Bestellungen bei Sofort-Zahlung automatisch abschlie&szlig;en', $s, 'Bestellungen, die mit Zahlarten wie PayPal, Mollie oder Kreditkarte bezahlt werden, werden direkt auf &bdquo;Abgeschlossen&ldquo; gesetzt. Banküberweisung bleibt auf &bdquo;Wartend&ldquo;.'); ?>
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

                            <?php // ═══ PANE: TICKET-BOT ═══ ?>
                            <div class="tix-pane" data-pane="bot">

                                <?php // ── Card: Allgemein ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-settings"></span>
                                        <h3>Allgemein</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('bot_enabled', 'Bot aktivieren', $s, 'Aktiviert die REST-API-Endpunkte und den Ticket-Bot.'); ?>
                                            </div>
                                            <?php self::text_row('bot_name', 'Bot-Name', $s, 'Ticket-Assistent'); ?>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label">Bot-Avatar</label>
                                                <div style="display:flex;align-items:center;gap:12px;">
                                                    <?php
                                                    $bot_avatar_id = intval($s['bot_avatar_id'] ?? 0);
                                                    $bot_avatar_url = $bot_avatar_id ? wp_get_attachment_image_url($bot_avatar_id, 'thumbnail') : '';
                                                    ?>
                                                    <div id="tix-bot-avatar-preview" style="width:60px;height:60px;border-radius:50%;background:#f3f4f6;display:flex;align-items:center;justify-content:center;overflow:hidden;border:2px solid #e5e7eb;flex-shrink:0;">
                                                        <?php if ($bot_avatar_url): ?>
                                                            <img src="<?php echo esc_url($bot_avatar_url); ?>" style="width:100%;height:100%;object-fit:cover;">
                                                        <?php else: ?>
                                                            <span style="font-size:24px;">🤖</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div style="display:flex;flex-direction:column;gap:6px;">
                                                        <button type="button" class="button" id="tix-bot-avatar-upload">Bild w&auml;hlen</button>
                                                        <button type="button" class="button" id="tix-bot-avatar-remove" style="color:#ef4444;<?php echo $bot_avatar_id ? '' : 'display:none;'; ?>">Entfernen</button>
                                                    </div>
                                                    <input type="hidden" name="tix_settings[bot_avatar_id]" id="tix-bot-avatar-id" value="<?php echo esc_attr($bot_avatar_id); ?>">
                                                </div>
                                                <p class="tix-settings-hint">Wird als Profilbild des Bots im Chat-Widget angezeigt. Empfohlen: quadratisch, mind. 88&times;88px.</p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label">Begr&uuml;&szlig;ung</label>
                                                <textarea name="tix_settings[bot_greeting]" rows="3" class="regular-text" style="width:100%;" placeholder="Hallo! Ich bin dein Ticket-Assistent..."><?php echo esc_textarea($s['bot_greeting'] ?? ''); ?></textarea>
                                                <p class="tix-settings-hint">Wird dem Nutzer beim ersten Kontakt angezeigt.</p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label">Pers&ouml;nlichkeit</label>
                                                <textarea name="tix_settings[bot_personality]" rows="3" class="regular-text" style="width:100%;" placeholder="Freundlich, hilfsbereit, kennt sich mit Events aus..."><?php echo esc_textarea($s['bot_personality'] ?? ''); ?></textarea>
                                                <p class="tix-settings-hint">Beschreibt den Ton und Stil des Bots in der Konversation.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Verbindung ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-cloud"></span>
                                        <h3>Verbindung</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php self::text_row('bot_hub_url', 'Hub-URL', $s, 'https://tixomat.pythonanywhere.com'); ?>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label">Hub Master-Key</label>
                                                <input type="password" name="tix_settings[bot_hub_master_key]" value="<?php echo esc_attr($s['bot_hub_master_key'] ?? ''); ?>" class="regular-text" style="width:100%;" placeholder="Master-Key vom Hub-Betreiber">
                                                <p class="tix-settings-hint">Wird zur Bot-Registrierung benoetigt. Erhaeltst du vom Plattform-Betreiber.</p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label">Hub Admin-Key <span style="font-size:11px;color:#94a3b8;font-weight:normal;">(optional, nur Plattform-Admin)</span></label>
                                                <input type="password" name="tix_settings[bot_hub_admin_key]" value="<?php echo esc_attr($s['bot_hub_admin_key'] ?? ''); ?>" class="regular-text" style="width:100%;" placeholder="Nur fuer den Plattform-Admin">
                                                <p class="tix-settings-hint">Erlaubt das Verwalten aller registrierten Seiten. Nur noetig fuer den zentralen Admin.</p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label">API-Secret</label>
                                                <div style="display:flex;gap:8px;align-items:center;">
                                                    <input type="text" name="tix_settings[bot_api_secret]" id="tix-bot-api-secret" value="<?php echo esc_attr($s['bot_api_secret'] ?? ''); ?>" class="regular-text" style="flex:1;font-family:monospace;font-size:13px;">
                                                    <button type="button" class="button" id="tix-bot-generate-secret">Generieren</button>
                                                </div>
                                                <p class="tix-settings-hint">Wird zur Authentifizierung der Bot-API-Anfragen verwendet.</p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label">Status</label>
                                                <div id="tix-bot-status" style="display:flex;align-items:center;gap:8px;padding:8px 0;">
                                                    <?php if (!empty($s['bot_registered'])) : ?>
                                                        <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#22c55e;"></span>
                                                        <span style="color:#22c55e;font-weight:600;">Registriert</span>
                                                    <?php else : ?>
                                                        <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ef4444;"></span>
                                                        <span style="color:#ef4444;font-weight:600;">Nicht registriert</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="tix-field tix-field-full" style="display:flex;gap:8px;">
                                                <?php if (empty($s['bot_registered'])) : ?>
                                                    <button type="button" class="button button-primary" id="tix-bot-register-btn">Registrieren / Deployen</button>
                                                <?php else : ?>
                                                    <button type="button" class="button button-primary" id="tix-bot-register-btn">Erneut registrieren</button>
                                                    <button type="button" class="button" id="tix-bot-unregister-btn" style="color:#ef4444;">Abmelden</button>
                                                <?php endif; ?>
                                                <button type="button" class="button" id="tix-bot-test-btn">Verbindung testen</button>
                                                <span id="tix-bot-action-msg" style="line-height:30px;margin-left:8px;"></span>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label">Tenant-ID</label>
                                                <input type="text" value="<?php echo esc_attr($s['bot_tenant_id'] ?? ''); ?>" class="regular-text" style="width:100%;font-family:monospace;font-size:13px;background:#f8f8f8;" readonly onclick="this.select();">
                                                <input type="hidden" name="tix_settings[bot_registered]" value="<?php echo esc_attr($s['bot_registered'] ?? 0); ?>">
                                                <input type="hidden" name="tix_settings[bot_tenant_id]" value="<?php echo esc_attr($s['bot_tenant_id'] ?? ''); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Kan&auml;le ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-share"></span>
                                        <h3>Kan&auml;le</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('bot_webchat_enabled', 'Webchat aktiviert', $s, 'Zeigt das Chat-Widget auf der Webseite an.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full" style="border-top:1px solid #eee;padding-top:12px;margin-top:4px;">
                                                <?php self::checkbox_row('bot_telegram_enabled', 'Telegram aktiviert', $s); ?>
                                            </div>
                                            <?php self::text_row('bot_telegram_token', 'Telegram Bot-Token', $s, '123456:ABC-DEF...'); ?>
                                            <div class="tix-field tix-field-full" style="border-top:1px solid #eee;padding-top:12px;margin-top:4px;">
                                                <?php self::checkbox_row('bot_whatsapp_enabled', 'WhatsApp aktiviert', $s); ?>
                                            </div>
                                            <?php self::text_row('bot_whatsapp_token', 'WhatsApp Token', $s); ?>
                                            <?php self::text_row('bot_whatsapp_phone_id', 'WhatsApp Phone-ID', $s); ?>
                                            <?php self::text_row('bot_whatsapp_verify', 'WhatsApp Verify-Token', $s); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: KI-Einstellungen ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-lightbulb"></span>
                                        <h3>KI-Einstellungen</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label">Anthropic API-Key</label>
                                                <input type="password" name="tix_settings[bot_anthropic_key]" value="<?php echo esc_attr($s['bot_anthropic_key'] ?? ''); ?>" class="regular-text" style="width:100%;" placeholder="sk-ant-...">
                                                <p class="tix-settings-hint">Wird f&uuml;r die KI-gest&uuml;tzte Konversation ben&ouml;tigt. Jede Seite kann einen eigenen Key nutzen.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Hub-Status ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-heart"></span>
                                        <h3>Hub-Status</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <div id="tix-bot-health" style="padding:12px;border-radius:8px;background:#f8f8f8;">
                                                    <span style="color:#94a3b8;">Lade Hub-Status...</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Tenant-Verwaltung (nur mit Admin-Key) ── ?>
                                <?php if (!empty($s['bot_hub_admin_key'])) : ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-networking"></span>
                                        <h3>Registrierte Seiten</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <p style="font-size:13px;color:#64748b;margin:0 0 12px;">
                                                    Alle beim Hub registrierten Websites. Falls eine Seite nicht mehr existiert, kann der Tenant hier entfernt werden.
                                                </p>
                                                <div id="tix-bot-tenants" style="padding:12px;border-radius:8px;background:#f8f8f8;">
                                                    <span style="color:#94a3b8;">Lade Tenants...</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <script>
                                (function(){
                                    'use strict';

                                    // ── Avatar Upload (WP Media) ──
                                    var avatarUploadBtn = document.getElementById('tix-bot-avatar-upload');
                                    var avatarRemoveBtn = document.getElementById('tix-bot-avatar-remove');
                                    var avatarIdInput   = document.getElementById('tix-bot-avatar-id');
                                    var avatarPreview   = document.getElementById('tix-bot-avatar-preview');
                                    var avatarFrame;

                                    if (avatarUploadBtn) {
                                        avatarUploadBtn.addEventListener('click', function(e) {
                                            e.preventDefault();
                                            if (avatarFrame) { avatarFrame.open(); return; }
                                            avatarFrame = wp.media({
                                                title: 'Bot-Avatar wählen',
                                                button: { text: 'Als Avatar verwenden' },
                                                multiple: false,
                                                library: { type: 'image' }
                                            });
                                            avatarFrame.on('select', function() {
                                                var att = avatarFrame.state().get('selection').first().toJSON();
                                                var url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                                                avatarIdInput.value = att.id;
                                                avatarPreview.innerHTML = '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover;">';
                                                if (avatarRemoveBtn) avatarRemoveBtn.style.display = '';
                                            });
                                            avatarFrame.open();
                                        });
                                    }
                                    if (avatarRemoveBtn) {
                                        avatarRemoveBtn.addEventListener('click', function(e) {
                                            e.preventDefault();
                                            avatarIdInput.value = '0';
                                            avatarPreview.innerHTML = '<span style="font-size:24px;">🤖</span>';
                                            this.style.display = 'none';
                                        });
                                    }

                                    // ── Secret generieren ──
                                    var genBtn = document.getElementById('tix-bot-generate-secret');
                                    if (genBtn) {
                                        genBtn.addEventListener('click', function() {
                                            var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+';
                                            var result = '';
                                            var arr = new Uint8Array(32);
                                            crypto.getRandomValues(arr);
                                            for (var i = 0; i < 32; i++) {
                                                result += chars.charAt(arr[i] % chars.length);
                                            }
                                            document.getElementById('tix-bot-api-secret').value = result;
                                        });
                                    }

                                    var nonce = '<?php echo wp_create_nonce('tix_admin_action'); ?>';
                                    var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
                                    var msgEl = document.getElementById('tix-bot-action-msg');

                                    function showMsg(text, color) {
                                        if (!msgEl) return;
                                        msgEl.textContent = text;
                                        msgEl.style.color = color || '#333';
                                        setTimeout(function() { msgEl.textContent = ''; }, 8000);
                                    }

                                    // ── Hilfsfunktion: aktuelle Formularwerte lesen ──
                                    function getLiveField(name) {
                                        var el = document.querySelector('[name="tix_settings[' + name + ']"]');
                                        return el ? el.value : '';
                                    }

                                    // ── Registrieren ──
                                    var regBtn = document.getElementById('tix-bot-register-btn');
                                    if (regBtn) {
                                        regBtn.addEventListener('click', function() {
                                            regBtn.disabled = true;
                                            regBtn.textContent = 'Registriere...';
                                            var fd = new FormData();
                                            fd.append('action', 'tix_bot_register');
                                            fd.append('nonce', nonce);
                                            // Aktuelle Formularwerte mitsenden (falls noch nicht gespeichert)
                                            fd.append('bot_hub_url', getLiveField('bot_hub_url'));
                                            fd.append('bot_hub_master_key', getLiveField('bot_hub_master_key'));
                                            fd.append('bot_hub_admin_key', getLiveField('bot_hub_admin_key'));
                                            fd.append('bot_api_secret', getLiveField('bot_api_secret'));
                                            fetch(ajaxUrl, {method:'POST', body: fd})
                                                .then(function(r) { return r.json(); })
                                                .then(function(d) {
                                                    if (d.success) {
                                                        showMsg(d.data.message, '#22c55e');
                                                        setTimeout(function() { location.reload(); }, 1500);
                                                    } else {
                                                        showMsg(d.data || 'Fehler', '#ef4444');
                                                        regBtn.disabled = false;
                                                        regBtn.textContent = 'Registrieren / Deployen';
                                                    }
                                                })
                                                .catch(function(e) {
                                                    showMsg('Netzwerkfehler: ' + e.message, '#ef4444');
                                                    regBtn.disabled = false;
                                                    regBtn.textContent = 'Registrieren / Deployen';
                                                });
                                        });
                                    }

                                    // ── Abmelden ──
                                    var unregBtn = document.getElementById('tix-bot-unregister-btn');
                                    if (unregBtn) {
                                        unregBtn.addEventListener('click', function() {
                                            if (!confirm('Bot wirklich vom Hub abmelden?')) return;
                                            unregBtn.disabled = true;
                                            var fd = new FormData();
                                            fd.append('action', 'tix_bot_unregister');
                                            fd.append('nonce', nonce);
                                            fetch(ajaxUrl, {method:'POST', body: fd})
                                                .then(function(r) { return r.json(); })
                                                .then(function(d) {
                                                    if (d.success) {
                                                        showMsg(d.data.message, '#22c55e');
                                                        setTimeout(function() { location.reload(); }, 1500);
                                                    } else {
                                                        showMsg(d.data || 'Fehler', '#ef4444');
                                                        unregBtn.disabled = false;
                                                    }
                                                })
                                                .catch(function(e) {
                                                    showMsg('Netzwerkfehler: ' + e.message, '#ef4444');
                                                    unregBtn.disabled = false;
                                                });
                                        });
                                    }

                                    // ── Verbindung testen ──
                                    var testBtn = document.getElementById('tix-bot-test-btn');
                                    if (testBtn) {
                                        testBtn.addEventListener('click', function() {
                                            testBtn.disabled = true;
                                            testBtn.textContent = 'Teste...';
                                            var fd = new FormData();
                                            fd.append('action', 'tix_bot_test');
                                            fd.append('nonce', nonce);
                                            fetch(ajaxUrl, {method:'POST', body: fd})
                                                .then(function(r) { return r.json(); })
                                                .then(function(d) {
                                                    if (d.success) {
                                                        showMsg(d.data.message, '#22c55e');
                                                    } else {
                                                        showMsg(d.data || 'Fehler', '#ef4444');
                                                    }
                                                    testBtn.disabled = false;
                                                    testBtn.textContent = 'Verbindung testen';
                                                })
                                                .catch(function(e) {
                                                    showMsg('Netzwerkfehler: ' + e.message, '#ef4444');
                                                    testBtn.disabled = false;
                                                    testBtn.textContent = 'Verbindung testen';
                                                });
                                        });
                                    }

                                    // ── Health Check beim Pane-Oeffnen ──
                                    function checkHealth() {
                                        var healthEl = document.getElementById('tix-bot-health');
                                        if (!healthEl) return;
                                        var hubUrl = '<?php echo esc_js(rtrim($s['bot_hub_url'] ?? '', '/')); ?>';
                                        if (!hubUrl) {
                                            healthEl.innerHTML = '<span style="color:#94a3b8;">Keine Hub-URL konfiguriert.</span>';
                                            return;
                                        }
                                        var adminKey = getLiveField('bot_hub_admin_key');
                                        var fetchOpts = {mode: 'cors'};
                                        if (adminKey) {
                                            fetchOpts.headers = {'X-Hub-Admin-Key': adminKey};
                                        }
                                        fetch(hubUrl + '/health', fetchOpts)
                                            .then(function(r) { return r.json(); })
                                            .then(function(d) {
                                                var html = '<div style="display:flex;align-items:center;gap:8px;">';
                                                html += '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#22c55e;"></span>';
                                                html += '<strong style="color:#22c55e;">Hub erreichbar</strong>';
                                                html += '</div>';
                                                if (d.version) html += '<div style="margin-top:6px;font-size:13px;color:#64748b;">Version: ' + d.version + '</div>';
                                                if (d.tenants !== undefined) html += '<div style="font-size:13px;color:#64748b;">Aktive Tenants: ' + d.tenants + '</div>';
                                                healthEl.innerHTML = html;
                                            })
                                            .catch(function() {
                                                healthEl.innerHTML = '<div style="display:flex;align-items:center;gap:8px;">'
                                                    + '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ef4444;"></span>'
                                                    + '<strong style="color:#ef4444;">Hub nicht erreichbar</strong>'
                                                    + '</div>'
                                                    + '<div style="margin-top:6px;font-size:13px;color:#64748b;">CORS-Fehler oder Server offline.</div>';
                                            });
                                    }

                                    // ── Tenant-Verwaltung ──
                                    function loadTenants() {
                                        var el = document.getElementById('tix-bot-tenants');
                                        if (!el) return;
                                        var fd = new FormData();
                                        fd.append('action', 'tix_bot_list_tenants');
                                        fd.append('nonce', nonce);
                                        fetch(ajaxUrl, {method:'POST', body: fd})
                                            .then(function(r) { return r.json(); })
                                            .then(function(d) {
                                                if (!d.success) {
                                                    if (d.data === 'no_admin_key') {
                                                        el.innerHTML = '<span style="color:#94a3b8;">Admin-Key erforderlich.</span>';
                                                    } else {
                                                        el.innerHTML = '<span style="color:#ef4444;">' + (d.data || 'Fehler beim Laden') + '</span>';
                                                    }
                                                    return;
                                                }
                                                var tenants = d.data.tenants || [];
                                                var ownTenant = d.data.own_tenant || '';
                                                if (!tenants.length) {
                                                    el.innerHTML = '<span style="color:#94a3b8;">Keine Tenants registriert.</span>';
                                                    return;
                                                }
                                                var html = '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
                                                html += '<thead><tr style="border-bottom:2px solid #e2e8f0;text-align:left;">';
                                                html += '<th style="padding:6px 8px;">Seite</th>';
                                                html += '<th style="padding:6px 8px;">Tenant-ID</th>';
                                                html += '<th style="padding:6px 8px;width:100px;"></th>';
                                                html += '</tr></thead><tbody>';
                                                tenants.forEach(function(t) {
                                                    var isOwn = t.tenant_id === ownTenant;
                                                    html += '<tr style="border-bottom:1px solid #e2e8f0;" data-tenant="' + t.tenant_id + '">';
                                                    html += '<td style="padding:8px;">';
                                                    html += '<strong>' + (t.site_name || t.site_url) + '</strong>';
                                                    html += '<br><span style="color:#94a3b8;font-size:12px;">' + t.site_url + '</span>';
                                                    if (isOwn) html += ' <span style="background:#dbeafe;color:#2563eb;padding:1px 6px;border-radius:4px;font-size:11px;">Diese Seite</span>';
                                                    html += '</td>';
                                                    html += '<td style="padding:8px;font-family:monospace;font-size:12px;color:#64748b;">' + t.tenant_id + '</td>';
                                                    html += '<td style="padding:8px;text-align:right;">';
                                                    html += '<button type="button" class="tix-remove-tenant-btn" data-tid="' + t.tenant_id + '" data-name="' + (t.site_name || t.site_url) + '"';
                                                    html += ' style="background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:12px;">';
                                                    html += 'Entfernen</button>';
                                                    html += '</td></tr>';
                                                });
                                                html += '</tbody></table>';
                                                el.innerHTML = html;

                                                // Loesch-Buttons binden
                                                el.querySelectorAll('.tix-remove-tenant-btn').forEach(function(btn) {
                                                    btn.addEventListener('click', function() {
                                                        var tid = btn.dataset.tid;
                                                        var tname = btn.dataset.name;
                                                        if (!confirm('Tenant "' + tname + '" (' + tid + ') wirklich vom Hub entfernen?\n\nDer Bot wird fuer diese Seite deaktiviert.')) return;
                                                        btn.disabled = true;
                                                        btn.textContent = 'Entferne...';
                                                        var rfd = new FormData();
                                                        rfd.append('action', 'tix_bot_remove_tenant');
                                                        rfd.append('nonce', nonce);
                                                        rfd.append('tenant_id', tid);
                                                        fetch(ajaxUrl, {method:'POST', body: rfd})
                                                            .then(function(r) { return r.json(); })
                                                            .then(function(rd) {
                                                                if (rd.success) {
                                                                    showMsg(rd.data.message, '#22c55e');
                                                                    var row = btn.closest('tr');
                                                                    if (row) row.remove();
                                                                    // Tabelle leer? Hinweis zeigen
                                                                    if (!el.querySelector('tr[data-tenant]')) {
                                                                        el.innerHTML = '<span style="color:#94a3b8;">Keine Tenants registriert.</span>';
                                                                    }
                                                                } else {
                                                                    showMsg(rd.data || 'Fehler', '#ef4444');
                                                                    btn.disabled = false;
                                                                    btn.textContent = 'Entfernen';
                                                                }
                                                            })
                                                            .catch(function(e) {
                                                                showMsg('Netzwerkfehler: ' + e.message, '#ef4444');
                                                                btn.disabled = false;
                                                                btn.textContent = 'Entfernen';
                                                            });
                                                    });
                                                });
                                            })
                                            .catch(function(e) {
                                                el.innerHTML = '<span style="color:#ef4444;">Fehler: ' + e.message + '</span>';
                                            });
                                    }

                                    // Health Check + Tenants laden wenn Pane sichtbar wird
                                    var botPane = document.querySelector('[data-pane="bot"]');
                                    if (botPane) {
                                        var observer = new MutationObserver(function(mutations) {
                                            mutations.forEach(function(m) {
                                                if (botPane.classList.contains('active')) {
                                                    checkHealth();
                                                    loadTenants();
                                                }
                                            });
                                        });
                                        observer.observe(botPane, {attributes: true, attributeFilter: ['class']});
                                        // Falls bereits aktiv (z.B. via URL-Hash)
                                        if (botPane.classList.contains('active')) {
                                            checkHealth();
                                            loadTenants();
                                        }
                                    }
                                })();
                                </script>

                            </div>

                            <?php // ═══ PANE: EXPORT / IMPORT ═══ ?>
                            <div class="tix-pane" data-pane="export-import">

                                <?php // ── Export ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-download"></span>
                                        <h3>Einstellungen exportieren</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint">Exportiert <strong>alle</strong> Plugin-Einstellungen als JSON-Datei. Die Datei kann auf einer anderen Instanz importiert werden.</p>
                                        <p class="tix-settings-hint" style="margin-bottom:16px;"><strong>Enthalten:</strong> Alle Design-, Farb-, Typografie-, Checkout-, Bot-, Marketing-, Fee-, Template- und Erweitert-Einstellungen sowie separate Optionen (Coupons, Bestellnummern-Sequenz).</p>
                                        <button type="button" class="button button-primary" id="tix-export-btn">
                                            <span class="dashicons dashicons-download" style="margin-top:4px;margin-right:4px;"></span>
                                            Einstellungen exportieren
                                        </button>
                                        <span id="tix-export-status" style="margin-left:12px;font-size:13px;color:#6b7280;"></span>
                                    </div>
                                </div>

                                <?php // ── Import ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-upload"></span>
                                        <h3>Einstellungen importieren</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin-bottom:4px;"><strong>Achtung:</strong> Der Import &uuml;berschreibt alle bestehenden Einstellungen. Erstelle vorher einen Export als Backup!</p>
                                        <p class="tix-settings-hint" style="margin-bottom:16px;">W&auml;hle eine zuvor exportierte JSON-Datei aus:</p>

                                        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                                            <label class="button" style="cursor:pointer;display:inline-flex;align-items:center;gap:4px;">
                                                <span class="dashicons dashicons-media-code" style="margin-top:3px;"></span>
                                                JSON-Datei w&auml;hlen
                                                <input type="file" accept=".json,application/json" id="tix-import-file" style="display:none;">
                                            </label>
                                            <span id="tix-import-filename" style="font-size:13px;color:#6b7280;">Keine Datei gew&auml;hlt</span>
                                        </div>

                                        <div id="tix-import-preview" style="display:none;margin-top:16px;">
                                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;">
                                                <h4 style="margin:0 0 8px;font-size:14px;">Vorschau</h4>
                                                <div id="tix-import-info" style="font-size:13px;color:#374151;line-height:1.6;"></div>
                                            </div>
                                            <div style="margin-top:16px;display:flex;gap:8px;">
                                                <button type="button" class="button button-primary" id="tix-import-btn" disabled>
                                                    <span class="dashicons dashicons-upload" style="margin-top:4px;margin-right:4px;"></span>
                                                    Jetzt importieren
                                                </button>
                                                <button type="button" class="button" id="tix-import-cancel">Abbrechen</button>
                                            </div>
                                            <span id="tix-import-status" style="display:block;margin-top:8px;font-size:13px;color:#6b7280;"></span>
                                        </div>
                                    </div>
                                </div>

                                <script>
                                (function(){
                                    'use strict';

                                    var importData = null;

                                    // ── EXPORT ──
                                    document.getElementById('tix-export-btn').addEventListener('click', function(){
                                        var btn = this;
                                        var status = document.getElementById('tix-export-status');
                                        btn.disabled = true;
                                        status.textContent = 'Exportiere...';
                                        fetch(ajaxurl + '?action=tix_export_settings&_wpnonce=<?php echo wp_create_nonce("tix_export_settings"); ?>')
                                            .then(function(r){ return r.json(); })
                                            .then(function(d){
                                                if(!d.success){ status.textContent = 'Fehler: ' + (d.data||''); btn.disabled=false; return; }
                                                var blob = new Blob([JSON.stringify(d.data, null, 2)], {type:'application/json'});
                                                var url = URL.createObjectURL(blob);
                                                var a = document.createElement('a');
                                                var date = new Date().toISOString().slice(0,10);
                                                a.href = url;
                                                a.download = 'tixomat-settings-' + date + '.json';
                                                a.click();
                                                URL.revokeObjectURL(url);
                                                status.textContent = 'Export erfolgreich!';
                                                status.style.color = '#16a34a';
                                                btn.disabled = false;
                                                setTimeout(function(){ status.textContent=''; status.style.color=''; }, 3000);
                                            })
                                            .catch(function(e){ status.textContent='Fehler: '+e.message; btn.disabled=false; });
                                    });

                                    // ── IMPORT: File selection ──
                                    document.getElementById('tix-import-file').addEventListener('change', function(){
                                        var file = this.files[0];
                                        var nameEl = document.getElementById('tix-import-filename');
                                        var preview = document.getElementById('tix-import-preview');
                                        var info = document.getElementById('tix-import-info');
                                        var btn = document.getElementById('tix-import-btn');

                                        if(!file){ nameEl.textContent='Keine Datei'; preview.style.display='none'; importData=null; return; }
                                        nameEl.textContent = file.name + ' (' + (file.size/1024).toFixed(1) + ' KB)';

                                        var reader = new FileReader();
                                        reader.onload = function(e){
                                            try {
                                                importData = JSON.parse(e.target.result);
                                                if(!importData._tix_export || !importData.tix_settings){
                                                    info.innerHTML = '<span style="color:#dc2626;">Ung\u00fcltige Datei: Kein g\u00fcltiger Tixomat-Export.</span>';
                                                    btn.disabled = true;
                                                    preview.style.display = '';
                                                    return;
                                                }
                                                var keys = Object.keys(importData.tix_settings).length;
                                                var extras = Object.keys(importData).filter(function(k){ return k!=='tix_settings' && k!=='_tix_export'; }).length;
                                                info.innerHTML = '<strong>Plugin-Version:</strong> ' + (importData._tix_export.version||'?')
                                                    + '<br><strong>Export-Datum:</strong> ' + (importData._tix_export.date||'?')
                                                    + '<br><strong>Quell-Site:</strong> ' + (importData._tix_export.site||'?')
                                                    + '<br><strong>Einstellungen:</strong> ' + keys + ' Keys'
                                                    + '<br><strong>Weitere Optionen:</strong> ' + extras;
                                                btn.disabled = false;
                                            } catch(err) {
                                                info.innerHTML = '<span style="color:#dc2626;">JSON-Fehler: ' + err.message + '</span>';
                                                btn.disabled = true;
                                                importData = null;
                                            }
                                            preview.style.display = '';
                                        };
                                        reader.readAsText(file);
                                    });

                                    // ── IMPORT: Cancel ──
                                    document.getElementById('tix-import-cancel').addEventListener('click', function(){
                                        importData = null;
                                        document.getElementById('tix-import-preview').style.display = 'none';
                                        document.getElementById('tix-import-file').value = '';
                                        document.getElementById('tix-import-filename').textContent = 'Keine Datei gew\u00e4hlt';
                                    });

                                    // ── IMPORT: Execute ──
                                    document.getElementById('tix-import-btn').addEventListener('click', function(){
                                        if(!importData) return;
                                        if(!confirm('Bist du sicher? Alle bestehenden Einstellungen werden \u00fcberschrieben!')) return;

                                        var btn = this;
                                        var status = document.getElementById('tix-import-status');
                                        btn.disabled = true;
                                        status.textContent = 'Importiere...';
                                        status.style.color = '';

                                        fetch(ajaxurl, {
                                            method: 'POST',
                                            headers: {'Content-Type':'application/x-www-form-urlencoded'},
                                            body: 'action=tix_import_settings&_wpnonce=<?php echo wp_create_nonce("tix_import_settings"); ?>&data=' + encodeURIComponent(JSON.stringify(importData))
                                        })
                                        .then(function(r){ return r.json(); })
                                        .then(function(d){
                                            if(d.success){
                                                status.textContent = 'Import erfolgreich! Seite wird neu geladen...';
                                                status.style.color = '#16a34a';
                                                setTimeout(function(){ location.reload(); }, 1500);
                                            } else {
                                                status.textContent = 'Fehler: ' + (d.data||'');
                                                status.style.color = '#dc2626';
                                                btn.disabled = false;
                                            }
                                        })
                                        .catch(function(e){ status.textContent='Fehler: '+e.message; status.style.color='#dc2626'; btn.disabled=false; });
                                    });
                                })();
                                </script>

                            </div>

                            <?php // ═══ PANE: RECHNUNGEN / AUSSTELLER ═══ ?>
                            <div class="tix-pane" data-pane="invoice">

                                <?php // ── Hinweis-Banner ── ?>
                                <div class="tix-card" style="margin-bottom:16px;background:linear-gradient(135deg,#fef3c7,#fde68a);border-color:#f59e0b;">
                                    <div class="tix-card-body" style="padding:16px 20px;">
                                        <div style="display:flex;align-items:flex-start;gap:12px;">
                                            <span style="font-size:24px;">📋</span>
                                            <div style="flex:1;">
                                                <strong style="color:#78350f;display:block;margin-bottom:4px;font-size:14px;">Rechnungs- &amp; Aussteller-Daten</strong>
                                                <span style="color:#92400e;font-size:13px;line-height:1.5;">Diese Felder erscheinen im Aussteller-Block des Event-Bericht-PDFs (Steuerberater-tauglich) und werden später für Einzelrechnungen genutzt. Vollst&auml;ndige Angaben sind Pflicht nach §14 UStG.</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Firmen-Stammdaten ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-businessperson"></span>
                                        <h3>Firmen-Stammdaten</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php self::text_row('invoice_company_name', 'Firmenname / Aussteller', $s, 'MDJ Veranstaltungs UG (haftungsbeschränkt)'); ?>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label">Firmenadresse (Straße + PLZ Ort)</label>
                                                <textarea name="<?php echo self::OPTION_KEY; ?>[invoice_company_address]" rows="3" class="large-text" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;resize:vertical;" placeholder="Musterstra&szlig;e 1&#10;12345 Berlin&#10;Deutschland"><?php echo esc_textarea($s['invoice_company_address'] ?? ''); ?></textarea>
                                            </div>
                                            <?php self::text_row('invoice_managing_director', 'Geschäftsführer/in', $s, 'Max Mustermann'); ?>
                                            <?php self::text_row('invoice_email', 'Geschäfts-Email', $s, 'office@meine-firma.de'); ?>
                                            <?php self::text_row('invoice_phone', 'Geschäfts-Telefon', $s, '+49 30 12345678'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Steuer-IDs ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-money-alt"></span>
                                        <h3>Steuer-Identifikation</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin-bottom:12px;font-size:12px;">
                                            <strong>Steuernummer</strong> = vom Finanzamt vergeben (z.B. <code>123/456/78901</code>) ·
                                            <strong>USt-IdNr.</strong> = europäische ID (z.B. <code>DE123456789</code>). Idealerweise beides eintragen — pflicht ist mindestens eines davon.
                                        </p>
                                        <div class="tix-field-grid">
                                            <?php self::text_row('invoice_company_tax_id', 'Steuernummer (vom Finanzamt)', $s, '123/456/78901'); ?>
                                            <?php self::text_row('invoice_company_ust_id', 'USt-IdNr.', $s, 'DE123456789'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Handelsregister ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-bank"></span>
                                        <h3>Handelsregister</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php self::text_row('invoice_register_court', 'Amtsgericht / Registergericht', $s, 'Amtsgericht Köln'); ?>
                                            <?php self::text_row('invoice_register_number', 'Handelsregister-Nr.', $s, 'HRB 12345'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Footer ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-edit"></span>
                                        <h3>Optionale Fußzeile</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label">Zusätzlicher Footer-Text (z.B. Bankverbindung)</label>
                                                <textarea name="<?php echo self::OPTION_KEY; ?>[invoice_footer_text]" rows="3" class="large-text" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;resize:vertical;" placeholder="Bankverbindung: IBAN DE12 3456 7890 1234 5678 90 · BIC ABCDDEFFXXX&#10;Sparkasse Musterstadt"><?php echo esc_textarea($s['invoice_footer_text'] ?? ''); ?></textarea>
                                                <p class="tix-field-hint">Erscheint als zusätzliche Zeilen im Aussteller-Block, z.B. für Bankverbindung. Optional.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Vorschau ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-visibility"></span>
                                        <h3>Vorschau Aussteller-Block</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin-bottom:12px;font-size:12px;">So erscheinen deine Daten im PDF-Bericht (Steuerberater-Format):</p>
                                        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:18px 22px;font-family:Helvetica,Arial,sans-serif;font-size:13px;line-height:1.6;color:#1f2937;">
                                            <?php
                                            $preview_lines = [];
                                            if (!empty($s['invoice_company_name'])) $preview_lines[] = '<strong>' . esc_html($s['invoice_company_name']) . '</strong>';
                                            if (!empty($s['invoice_company_address'])) {
                                                foreach (preg_split('/\r\n|\r|\n/', $s['invoice_company_address']) as $ln) {
                                                    $ln = trim($ln);
                                                    if ($ln) $preview_lines[] = esc_html($ln);
                                                }
                                            }
                                            $tax_line = [];
                                            if (!empty($s['invoice_company_tax_id'])) $tax_line[] = 'Steuernr.: ' . esc_html($s['invoice_company_tax_id']);
                                            if (!empty($s['invoice_company_ust_id'])) $tax_line[] = 'USt-IdNr.: ' . esc_html($s['invoice_company_ust_id']);
                                            if (!empty($tax_line)) $preview_lines[] = implode(' · ', $tax_line);
                                            $rep_line = [];
                                            if (!empty($s['invoice_managing_director'])) $rep_line[] = 'Geschäftsführer: ' . esc_html($s['invoice_managing_director']);
                                            if (!empty($s['invoice_register_court']) && !empty($s['invoice_register_number'])) $rep_line[] = esc_html($s['invoice_register_court']) . ' ' . esc_html($s['invoice_register_number']);
                                            elseif (!empty($s['invoice_register_court'])) $rep_line[] = esc_html($s['invoice_register_court']);
                                            elseif (!empty($s['invoice_register_number'])) $rep_line[] = esc_html($s['invoice_register_number']);
                                            if (!empty($rep_line)) $preview_lines[] = implode(' · ', $rep_line);
                                            $contact = [];
                                            if (!empty($s['invoice_email'])) $contact[] = esc_html($s['invoice_email']);
                                            if (!empty($s['invoice_phone'])) $contact[] = esc_html($s['invoice_phone']);
                                            if (!empty($contact)) $preview_lines[] = implode(' · ', $contact);
                                            if (!empty($s['invoice_footer_text'])) {
                                                foreach (preg_split('/\r\n|\r|\n/', $s['invoice_footer_text']) as $ln) {
                                                    $ln = trim($ln);
                                                    if ($ln) $preview_lines[] = esc_html($ln);
                                                }
                                            }
                                            if (empty($preview_lines)) {
                                                echo '<em style="color:#9ca3af;">Trag oben deine Firmendaten ein — die Vorschau erscheint nach dem Speichern.</em>';
                                            } else {
                                                foreach ($preview_lines as $ln) {
                                                    echo $ln . '<br>';
                                                }
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card-Block: Automatische Rechnungen (Lexware Office etc.) ── ?>
                                <?php if (class_exists('TIX_Invoicing')) TIX_Invoicing::render_settings_pane(); ?>

                            </div>

                            <?php // ═══ PANE: WALLET (Apple + Google) ═══ ?>
                            <div class="tix-pane" data-pane="wallet">

                                <?php // ── Status / Hinweis ── ?>
                                <div class="tix-card" style="margin-bottom:16px;background:linear-gradient(135deg,#fef3c7,#fde68a);border-color:#f59e0b;">
                                    <div class="tix-card-body" style="padding:16px 20px;">
                                        <div style="display:flex;align-items:center;gap:12px;">
                                            <span style="font-size:28px;">🚧</span>
                                            <div>
                                                <strong style="color:#78350f;display:block;margin-bottom:2px;">Funktion in Vorbereitung</strong>
                                                <span style="color:#92400e;font-size:13px;">Die Wallet-Generierung ist noch nicht aktiv. Du kannst hier bereits alle Zugangsdaten und Branding-Optionen eintragen — sobald deine Apple- und Google-Accounts angelegt sind, lässt sich die Funktion ohne weitere Code-Änderungen scharfschalten.</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Master-Switch ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-id-alt"></span>
                                        <h3>Wallet-Buttons</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php self::checkbox_row('wallet_enabled', 'Wallet-Funktion aktivieren', $s, 'Wenn aktiviert, werden die "Apple Wallet" / "Google Wallet"-Buttons auf den Tickets angezeigt. Beide Anbieter können separat aktiviert werden.'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Apple Wallet ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header" style="background:linear-gradient(90deg,#000,#1f2937);color:#fff;">
                                        <span class="dashicons dashicons-smartphone" style="color:#fff;"></span>
                                        <h3 style="color:#fff;">Apple Wallet (.pkpass)</h3>
                                    </div>
                                    <div class="tix-card-body">

                                        <details style="margin-bottom:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;">
                                            <summary style="cursor:pointer;font-weight:600;color:#0f172a;">📋 Voraussetzungen — was du vorher anlegen musst</summary>
                                            <ol style="margin:10px 0 0 18px;font-size:13px;line-height:1.7;color:#475569;">
                                                <li>Apple Developer Account ($99/Jahr) unter <a href="https://developer.apple.com/programs/" target="_blank">developer.apple.com</a></li>
                                                <li>Pass Type ID erstellen unter <em>Certificates → Identifiers → Pass Type IDs</em> (z.B. <code>pass.de.deinedomain.ticket</code>)</li>
                                                <li>Pass-Zertifikat erzeugen → <code>.cer</code> herunterladen → in Schlüsselbund importieren → als <code>.p12</code> exportieren</li>
                                                <li>Apple WWDR Cert von <a href="https://www.apple.com/certificateauthority/" target="_blank">apple.com/certificateauthority</a> als <code>.pem</code> herunterladen</li>
                                                <li>Beide Dateien per FTP/SSH in einen privaten Ordner auf den Server kopieren (NICHT in <code>/uploads/</code>!) — z.B. <code>/wp-content/tixomat-secrets/</code></li>
                                            </ol>
                                        </details>

                                        <div class="tix-field-grid">
                                            <?php self::checkbox_row('wallet_apple_enabled', 'Apple Wallet aktivieren', $s, 'Zeigt den Apple-Wallet-Button auf iOS-Geräten an.'); ?>
                                        </div>

                                        <h4 style="margin:18px 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;">Apple Developer Account</h4>
                                        <div class="tix-field-grid">
                                            <?php self::text_row('wallet_apple_pass_type_id', 'Pass Type Identifier', $s, 'pass.de.tixomat.ticket'); ?>
                                            <?php self::text_row('wallet_apple_team_id', 'Apple Team Identifier', $s, 'ABC1234567'); ?>
                                            <?php self::text_row('wallet_apple_org_name', 'Organisations-Name', $s, 'Tixomat GmbH'); ?>
                                        </div>

                                        <h4 style="margin:18px 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;">Zertifikate (Server-Pfade)</h4>
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label">Pass-Zertifikat (.p12) Pfad</label>
                                                <input type="text" name="<?php echo $ok; ?>[wallet_apple_cert_path]" value="<?php echo esc_attr($s['wallet_apple_cert_path'] ?? ''); ?>" class="regular-text" style="width:100%;font-family:monospace;" placeholder="/home/runcloud/webapps/.../wp-content/tixomat-secrets/pass.p12">
                                                <p class="tix-field-hint">Absoluter Pfad zur exportierten <code>.p12</code>-Datei. <strong>Niemals in <code>/uploads/</code> ablegen</strong> — der Ordner ist öffentlich erreichbar.</p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label">Zertifikat-Passwort</label>
                                                <input type="password" name="<?php echo $ok; ?>[wallet_apple_cert_password]" value="<?php echo esc_attr($s['wallet_apple_cert_password'] ?? ''); ?>" class="regular-text" style="width:100%;" placeholder="Passwort beim .p12-Export gesetzt" autocomplete="new-password">
                                                <p class="tix-field-hint">Das Passwort, das du beim Exportieren der <code>.p12</code> aus dem Schlüsselbund vergeben hast.</p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label">WWDR-Zertifikat (.pem) Pfad</label>
                                                <input type="text" name="<?php echo $ok; ?>[wallet_apple_wwdr_path]" value="<?php echo esc_attr($s['wallet_apple_wwdr_path'] ?? ''); ?>" class="regular-text" style="width:100%;font-family:monospace;" placeholder="/home/runcloud/webapps/.../wp-content/tixomat-secrets/wwdr.pem">
                                                <p class="tix-field-hint">Apple Worldwide Developer Relations Certificate (Apple's Intermediate-Cert für Pass-Signaturen).</p>
                                            </div>
                                        </div>

                                        <h4 style="margin:18px 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;">Branding</h4>
                                        <div class="tix-field-grid">
                                            <?php
                                            self::media_url_row('wallet_apple_logo_url', 'Logo (160×50 PNG)', $s, 'Transparentes PNG, max. 160px breit. Wird oben links auf dem Pass angezeigt.');
                                            self::media_url_row('wallet_apple_icon_url', 'Icon (29×29 PNG)', $s, 'Quadratisches Icon, wird in iOS-Notifications & Lock-Screen-Vorschau angezeigt.');
                                            self::media_url_row('wallet_apple_strip_url', 'Strip-Bild (320×123 PNG, optional)', $s, 'Großflächiges Header-Bild. Leer = nur Hintergrundfarbe.');
                                            ?>
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">Hintergrundfarbe</label>
                                                <div class="tix-field-input">
                                                    <input type="color" name="<?php echo $ok; ?>[wallet_apple_bg_color]" value="<?php echo esc_attr($s['wallet_apple_bg_color'] ?? '#0f172a'); ?>" style="width:60px;height:36px;cursor:pointer;">
                                                    <input type="text" value="<?php echo esc_attr($s['wallet_apple_bg_color'] ?? '#0f172a'); ?>" style="width:90px;font-family:monospace;" oninput="this.previousElementSibling.value=this.value">
                                                </div>
                                            </div>
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">Textfarbe</label>
                                                <div class="tix-field-input">
                                                    <input type="color" name="<?php echo $ok; ?>[wallet_apple_fg_color]" value="<?php echo esc_attr($s['wallet_apple_fg_color'] ?? '#ffffff'); ?>" style="width:60px;height:36px;cursor:pointer;">
                                                    <input type="text" value="<?php echo esc_attr($s['wallet_apple_fg_color'] ?? '#ffffff'); ?>" style="width:90px;font-family:monospace;" oninput="this.previousElementSibling.value=this.value">
                                                </div>
                                            </div>
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">Label-Farbe</label>
                                                <div class="tix-field-input">
                                                    <input type="color" name="<?php echo $ok; ?>[wallet_apple_label_color]" value="<?php echo esc_attr($s['wallet_apple_label_color'] ?? '#cbd5e1'); ?>" style="width:60px;height:36px;cursor:pointer;">
                                                    <input type="text" value="<?php echo esc_attr($s['wallet_apple_label_color'] ?? '#cbd5e1'); ?>" style="width:90px;font-family:monospace;" oninput="this.previousElementSibling.value=this.value">
                                                    <p class="tix-field-desc">Farbe der kleinen Beschriftungen (z.B. "DATUM", "EINLASS").</p>
                                                </div>
                                            </div>
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">Geo-Push-Radius</label>
                                                <div class="tix-field-input">
                                                    <input type="number" name="<?php echo $ok; ?>[wallet_apple_relevant_radius]" value="<?php echo esc_attr($s['wallet_apple_relevant_radius'] ?? 200); ?>" min="50" max="2000" step="50" style="width:90px;"> Meter
                                                    <p class="tix-field-desc">Innerhalb dieses Radius um den Venue erscheint das Ticket automatisch auf dem Lock-Screen (z.B. 200m).</p>
                                                </div>
                                            </div>
                                        </div>

                                        <?php // ── Live-Vorschau Apple Wallet ── ?>
                                        <h4 style="margin:24px 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;">Live-Vorschau</h4>
                                        <div style="display:flex;justify-content:center;padding:30px 20px;background:linear-gradient(135deg,#f1f5f9,#e2e8f0);border-radius:14px;">
                                            <div id="tix-apple-pass-preview" style="width:320px;max-width:100%;background:<?php echo esc_attr($s['wallet_apple_bg_color'] ?? '#0f172a'); ?>;color:<?php echo esc_attr($s['wallet_apple_fg_color'] ?? '#ffffff'); ?>;border-radius:14px;padding:14px 18px 18px;box-shadow:0 8px 24px rgba(0,0,0,0.18);font-family:-apple-system,BlinkMacSystemFont,'SF Pro Text',sans-serif;position:relative;overflow:hidden;">
                                                <!-- Header: Logo + Logo Text -->
                                                <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                                                    <?php if (!empty($s['wallet_apple_logo_url'])): ?>
                                                        <img id="tix-aw-pv-logo" src="<?php echo esc_url($s['wallet_apple_logo_url']); ?>" style="max-height:30px;max-width:120px;width:auto;">
                                                    <?php else: ?>
                                                        <div id="tix-aw-pv-logo" style="height:30px;width:80px;background:rgba(255,255,255,0.12);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:10px;color:<?php echo esc_attr($s['wallet_apple_label_color'] ?? '#cbd5e1'); ?>;">LOGO</div>
                                                    <?php endif; ?>
                                                    <div style="margin-left:auto;text-align:right;font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;opacity:0.85;" id="tix-aw-pv-org"><?php echo esc_html($s['wallet_apple_org_name'] ?? 'Tixomat'); ?></div>
                                                </div>

                                                <!-- Strip Image (optional) -->
                                                <?php if (!empty($s['wallet_apple_strip_url'])): ?>
                                                <div id="tix-aw-pv-strip-wrap" style="margin:0 -18px 12px;">
                                                    <img id="tix-aw-pv-strip" src="<?php echo esc_url($s['wallet_apple_strip_url']); ?>" style="width:100%;height:auto;display:block;">
                                                </div>
                                                <?php else: ?>
                                                <div id="tix-aw-pv-strip-wrap" style="display:none;margin:0 -18px 12px;">
                                                    <img id="tix-aw-pv-strip" src="" style="width:100%;height:auto;display:block;">
                                                </div>
                                                <?php endif; ?>

                                                <!-- Header Field: Event-Name -->
                                                <div style="margin-bottom:14px;">
                                                    <div style="font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:<?php echo esc_attr($s['wallet_apple_label_color'] ?? '#cbd5e1'); ?>;margin-bottom:2px;" class="tix-aw-pv-label">EVENT</div>
                                                    <div style="font-size:18px;font-weight:600;line-height:1.15;">Mallorca Festival XXL</div>
                                                </div>

                                                <!-- Primary Field: Datum (groß) -->
                                                <div style="margin-bottom:18px;">
                                                    <div style="font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:<?php echo esc_attr($s['wallet_apple_label_color'] ?? '#cbd5e1'); ?>;margin-bottom:2px;" class="tix-aw-pv-label">DATUM</div>
                                                    <div style="font-size:26px;font-weight:600;line-height:1;">Sa, 12. Sep</div>
                                                </div>

                                                <!-- Secondary fields row -->
                                                <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:12px;">
                                                    <div>
                                                        <div style="font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:<?php echo esc_attr($s['wallet_apple_label_color'] ?? '#cbd5e1'); ?>;margin-bottom:1px;" class="tix-aw-pv-label">EINLASS</div>
                                                        <div style="font-size:15px;font-weight:500;">19:00</div>
                                                    </div>
                                                    <div>
                                                        <div style="font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:<?php echo esc_attr($s['wallet_apple_label_color'] ?? '#cbd5e1'); ?>;margin-bottom:1px;" class="tix-aw-pv-label">VENUE</div>
                                                        <div style="font-size:15px;font-weight:500;">Megapark</div>
                                                    </div>
                                                </div>

                                                <!-- Auxiliary fields row -->
                                                <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:14px;">
                                                    <div>
                                                        <div style="font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:<?php echo esc_attr($s['wallet_apple_label_color'] ?? '#cbd5e1'); ?>;margin-bottom:1px;" class="tix-aw-pv-label">KATEGORIE</div>
                                                        <div style="font-size:13px;font-weight:500;">VIP-Ticket</div>
                                                    </div>
                                                    <div>
                                                        <div style="font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:<?php echo esc_attr($s['wallet_apple_label_color'] ?? '#cbd5e1'); ?>;margin-bottom:1px;" class="tix-aw-pv-label">NAME</div>
                                                        <div style="font-size:13px;font-weight:500;">M. Mustermann</div>
                                                    </div>
                                                </div>

                                                <!-- QR Code Placeholder -->
                                                <div style="background:#fff;padding:10px;border-radius:6px;display:flex;align-items:center;justify-content:center;">
                                                    <svg width="120" height="120" viewBox="0 0 120 120" style="display:block;">
                                                        <rect width="120" height="120" fill="#fff"/>
                                                        <g fill="#000">
                                                            <!-- Korner-Detection-Pattern simulation -->
                                                            <rect x="8" y="8" width="24" height="24"/><rect x="14" y="14" width="12" height="12" fill="#fff"/><rect x="18" y="18" width="4" height="4" fill="#000"/>
                                                            <rect x="88" y="8" width="24" height="24"/><rect x="94" y="14" width="12" height="12" fill="#fff"/><rect x="98" y="18" width="4" height="4" fill="#000"/>
                                                            <rect x="8" y="88" width="24" height="24"/><rect x="14" y="94" width="12" height="12" fill="#fff"/><rect x="18" y="98" width="4" height="4" fill="#000"/>
                                                            <!-- Random data pattern -->
                                                            <rect x="40" y="12" width="4" height="4"/><rect x="48" y="12" width="4" height="4"/><rect x="56" y="12" width="4" height="4"/><rect x="68" y="12" width="4" height="4"/><rect x="76" y="12" width="4" height="4"/>
                                                            <rect x="40" y="20" width="4" height="4"/><rect x="52" y="20" width="4" height="4"/><rect x="64" y="20" width="4" height="4"/><rect x="80" y="20" width="4" height="4"/>
                                                            <rect x="44" y="28" width="4" height="4"/><rect x="56" y="28" width="4" height="4"/><rect x="72" y="28" width="4" height="4"/><rect x="80" y="28" width="4" height="4"/>
                                                            <rect x="40" y="40" width="4" height="4"/><rect x="48" y="40" width="4" height="4"/><rect x="56" y="40" width="4" height="4"/><rect x="64" y="40" width="4" height="4"/><rect x="76" y="40" width="4" height="4"/><rect x="84" y="40" width="4" height="4"/><rect x="92" y="40" width="4" height="4"/>
                                                            <rect x="44" y="48" width="4" height="4"/><rect x="52" y="48" width="4" height="4"/><rect x="60" y="48" width="4" height="4"/><rect x="68" y="48" width="4" height="4"/><rect x="80" y="48" width="4" height="4"/><rect x="100" y="48" width="4" height="4"/>
                                                            <rect x="40" y="56" width="4" height="4"/><rect x="56" y="56" width="4" height="4"/><rect x="72" y="56" width="4" height="4"/><rect x="84" y="56" width="4" height="4"/><rect x="92" y="56" width="4" height="4"/>
                                                            <rect x="48" y="64" width="4" height="4"/><rect x="56" y="64" width="4" height="4"/><rect x="64" y="64" width="4" height="4"/><rect x="76" y="64" width="4" height="4"/><rect x="88" y="64" width="4" height="4"/>
                                                            <rect x="40" y="72" width="4" height="4"/><rect x="52" y="72" width="4" height="4"/><rect x="68" y="72" width="4" height="4"/><rect x="80" y="72" width="4" height="4"/><rect x="96" y="72" width="4" height="4"/>
                                                            <rect x="44" y="80" width="4" height="4"/><rect x="60" y="80" width="4" height="4"/><rect x="72" y="80" width="4" height="4"/><rect x="84" y="80" width="4" height="4"/><rect x="92" y="80" width="4" height="4"/><rect x="100" y="80" width="4" height="4"/>
                                                            <rect x="40" y="92" width="4" height="4"/><rect x="48" y="92" width="4" height="4"/><rect x="68" y="92" width="4" height="4"/><rect x="80" y="92" width="4" height="4"/><rect x="100" y="92" width="4" height="4"/>
                                                            <rect x="48" y="100" width="4" height="4"/><rect x="56" y="100" width="4" height="4"/><rect x="64" y="100" width="4" height="4"/><rect x="76" y="100" width="4" height="4"/><rect x="92" y="100" width="4" height="4"/>
                                                        </g>
                                                    </svg>
                                                </div>
                                                <div style="text-align:center;font-size:10px;font-family:Menlo,monospace;margin-top:6px;opacity:0.7;letter-spacing:0.1em;">TIX-A1B2-C3D4-E5F6</div>
                                            </div>
                                        </div>
                                        <p class="tix-settings-hint" style="text-align:center;margin-top:10px;font-size:12px;">↑ So sieht dein Pass auf dem iPhone Lock-Screen aus (vereinfachte Vorschau)</p>

                                        <script>
                                        (function(){
                                            // Live-Update der Apple-Wallet-Vorschau
                                            var pv = document.getElementById('tix-apple-pass-preview');
                                            if (!pv) return;
                                            function getInput(name) { return document.querySelector('[name="<?php echo $ok; ?>['+name+']"]'); }
                                            function bind(name, cb){
                                                var el = getInput(name);
                                                if (!el) return;
                                                el.addEventListener('input', function(){ cb(el.value); });
                                                el.addEventListener('change', function(){ cb(el.value); });
                                            }
                                            bind('wallet_apple_bg_color', function(v){ pv.style.background = v; });
                                            bind('wallet_apple_fg_color', function(v){ pv.style.color = v; });
                                            bind('wallet_apple_label_color', function(v){
                                                pv.querySelectorAll('.tix-aw-pv-label').forEach(function(el){ el.style.color = v; });
                                            });
                                            bind('wallet_apple_org_name', function(v){
                                                var el = document.getElementById('tix-aw-pv-org'); if (el) el.textContent = v || 'Tixomat';
                                            });
                                            bind('wallet_apple_logo_url', function(v){
                                                var el = document.getElementById('tix-aw-pv-logo'); if (!el) return;
                                                if (v) {
                                                    var img = document.createElement('img');
                                                    img.id = 'tix-aw-pv-logo';
                                                    img.src = v; img.style.cssText = 'max-height:30px;max-width:120px;width:auto;';
                                                    el.replaceWith(img);
                                                } else {
                                                    var div = document.createElement('div');
                                                    div.id = 'tix-aw-pv-logo';
                                                    div.style.cssText = 'height:30px;width:80px;background:rgba(255,255,255,0.12);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:10px;';
                                                    div.textContent = 'LOGO';
                                                    el.replaceWith(div);
                                                }
                                            });
                                            bind('wallet_apple_strip_url', function(v){
                                                var wrap = document.getElementById('tix-aw-pv-strip-wrap');
                                                var img  = document.getElementById('tix-aw-pv-strip');
                                                if (!wrap || !img) return;
                                                if (v) { img.src = v; wrap.style.display = 'block'; }
                                                else   { img.src = ''; wrap.style.display = 'none'; }
                                            });
                                        })();
                                        </script>

                                    </div>
                                </div>

                                <?php // ── Google Wallet ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header" style="background:linear-gradient(90deg,#1a73e8,#4285f4);color:#fff;">
                                        <span class="dashicons dashicons-cloud" style="color:#fff;"></span>
                                        <h3 style="color:#fff;">Google Wallet</h3>
                                    </div>
                                    <div class="tix-card-body">

                                        <details style="margin-bottom:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;">
                                            <summary style="cursor:pointer;font-weight:600;color:#0f172a;">📋 Voraussetzungen — was du vorher anlegen musst</summary>
                                            <ol style="margin:10px 0 0 18px;font-size:13px;line-height:1.7;color:#475569;">
                                                <li>Google Cloud Console Account → Projekt erstellen unter <a href="https://console.cloud.google.com/" target="_blank">console.cloud.google.com</a></li>
                                                <li>Google Wallet API aktivieren (im Projekt → APIs &amp; Services → Library → "Google Wallet API")</li>
                                                <li>Service Account erstellen (IAM &amp; Admin → Service Accounts) → JSON-Key herunterladen</li>
                                                <li>Issuer-Account beantragen unter <a href="https://wallet.google.com/business/issuersignup" target="_blank">wallet.google.com/business/issuersignup</a> (kostenlos, 1–3 Tage Wartezeit)</li>
                                                <li>Service-Account-E-Mail beim Issuer als Berechtigter hinterlegen</li>
                                                <li>Aus der heruntergeladenen JSON-Datei die Felder <code>client_email</code> und <code>private_key</code> hier eintragen</li>
                                            </ol>
                                        </details>

                                        <div class="tix-field-grid">
                                            <?php self::checkbox_row('wallet_google_enabled', 'Google Wallet aktivieren', $s, 'Zeigt den Google-Wallet-Button auf Android-/Chrome-Geräten an.'); ?>
                                        </div>

                                        <h4 style="margin:18px 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;">Google Cloud Service Account</h4>
                                        <div class="tix-field-grid">
                                            <?php self::text_row('wallet_google_issuer_id', 'Issuer ID', $s, '3388000000022000000'); ?>
                                            <?php self::text_row('wallet_google_service_email', 'Service-Account E-Mail', $s, 'wallet@projekt-xyz.iam.gserviceaccount.com'); ?>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label">Private Key (PEM)</label>
                                                <textarea name="<?php echo $ok; ?>[wallet_google_service_key]" rows="8" class="regular-text" style="width:100%;font-family:monospace;font-size:11px;" placeholder="-----BEGIN PRIVATE KEY-----&#10;MIIEvQIBADAN...&#10;-----END PRIVATE KEY-----" autocomplete="off"><?php echo esc_textarea($s['wallet_google_service_key'] ?? ''); ?></textarea>
                                                <p class="tix-field-hint">Aus der heruntergeladenen JSON-Datei das Feld <code>private_key</code> komplett kopieren (inkl. <code>-----BEGIN/END-----</code>-Zeilen). <strong>Niemals weitergeben.</strong></p>
                                            </div>
                                            <?php self::text_row('wallet_google_class_suffix', 'Class-ID Suffix', $s, 'tixomat-event-ticket'); ?>
                                        </div>

                                        <h4 style="margin:18px 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;">Branding</h4>
                                        <div class="tix-field-grid">
                                            <?php
                                            self::media_url_row('wallet_google_logo_url', 'Logo (660×660 PNG)', $s, 'Quadratisches Logo, wird klein im Pass angezeigt.');
                                            self::media_url_row('wallet_google_hero_url', 'Hero-Bild (1860×600 PNG)', $s, 'Großflächiges Header-Bild oben im Pass.');
                                            ?>
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">Hintergrundfarbe</label>
                                                <div class="tix-field-input">
                                                    <input type="color" name="<?php echo $ok; ?>[wallet_google_bg_color]" value="<?php echo esc_attr($s['wallet_google_bg_color'] ?? '#0f172a'); ?>" style="width:60px;height:36px;cursor:pointer;">
                                                    <input type="text" value="<?php echo esc_attr($s['wallet_google_bg_color'] ?? '#0f172a'); ?>" style="width:90px;font-family:monospace;" oninput="this.previousElementSibling.value=this.value">
                                                </div>
                                            </div>
                                        </div>

                                        <?php // ── Live-Vorschau Google Wallet ── ?>
                                        <h4 style="margin:24px 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;">Live-Vorschau</h4>
                                        <div style="display:flex;justify-content:center;padding:30px 20px;background:linear-gradient(135deg,#f1f5f9,#e2e8f0);border-radius:14px;">
                                            <div id="tix-google-pass-preview" style="width:340px;max-width:100%;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,0.18);font-family:'Google Sans','Roboto',-apple-system,sans-serif;">
                                                <!-- Header bar with bg color -->
                                                <div id="tix-gw-pv-header" style="background:<?php echo esc_attr($s['wallet_google_bg_color'] ?? '#0f172a'); ?>;padding:14px 18px;display:flex;align-items:center;gap:12px;">
                                                    <?php if (!empty($s['wallet_google_logo_url'])): ?>
                                                        <img id="tix-gw-pv-logo" src="<?php echo esc_url($s['wallet_google_logo_url']); ?>" style="width:36px;height:36px;border-radius:50%;background:#fff;object-fit:contain;padding:4px;">
                                                    <?php else: ?>
                                                        <div id="tix-gw-pv-logo" style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,0.18);display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;font-weight:600;">T</div>
                                                    <?php endif; ?>
                                                    <div style="color:#fff;flex:1;min-width:0;">
                                                        <div style="font-size:11px;opacity:0.7;text-transform:uppercase;letter-spacing:0.04em;">Event-Ticket</div>
                                                        <div style="font-size:14px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" id="tix-gw-pv-orgline">Tixomat</div>
                                                    </div>
                                                </div>

                                                <!-- Hero image (optional) -->
                                                <?php if (!empty($s['wallet_google_hero_url'])): ?>
                                                    <img id="tix-gw-pv-hero" src="<?php echo esc_url($s['wallet_google_hero_url']); ?>" style="width:100%;height:120px;object-fit:cover;display:block;">
                                                <?php else: ?>
                                                    <img id="tix-gw-pv-hero" src="" style="width:100%;height:120px;object-fit:cover;display:none;">
                                                <?php endif; ?>

                                                <!-- Body content -->
                                                <div style="padding:16px 18px;color:#202124;">
                                                    <div style="font-size:11px;color:#5f6368;text-transform:uppercase;letter-spacing:0.05em;font-weight:500;">Event</div>
                                                    <div style="font-size:18px;font-weight:500;color:#202124;line-height:1.2;margin-bottom:14px;">Mallorca Festival XXL</div>

                                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                                                        <div>
                                                            <div style="font-size:11px;color:#5f6368;font-weight:500;">DATUM</div>
                                                            <div style="font-size:14px;color:#202124;">Sa, 12.09.</div>
                                                        </div>
                                                        <div>
                                                            <div style="font-size:11px;color:#5f6368;font-weight:500;">EINLASS</div>
                                                            <div style="font-size:14px;color:#202124;">19:00 Uhr</div>
                                                        </div>
                                                        <div>
                                                            <div style="font-size:11px;color:#5f6368;font-weight:500;">VENUE</div>
                                                            <div style="font-size:14px;color:#202124;">Megapark</div>
                                                        </div>
                                                        <div>
                                                            <div style="font-size:11px;color:#5f6368;font-weight:500;">KATEGORIE</div>
                                                            <div style="font-size:14px;color:#202124;">VIP-Ticket</div>
                                                        </div>
                                                    </div>

                                                    <!-- Barcode area -->
                                                    <div style="border-top:1px solid #e8eaed;margin-top:6px;padding-top:14px;display:flex;flex-direction:column;align-items:center;">
                                                        <svg width="190" height="58" viewBox="0 0 190 58" style="display:block;">
                                                            <rect width="190" height="58" fill="#fff"/>
                                                            <g fill="#202124">
                                                                <rect x="2" y="6" width="2" height="46"/><rect x="6" y="6" width="1" height="46"/><rect x="9" y="6" width="3" height="46"/>
                                                                <rect x="14" y="6" width="1" height="46"/><rect x="17" y="6" width="2" height="46"/><rect x="22" y="6" width="3" height="46"/>
                                                                <rect x="27" y="6" width="1" height="46"/><rect x="30" y="6" width="2" height="46"/><rect x="34" y="6" width="1" height="46"/>
                                                                <rect x="37" y="6" width="3" height="46"/><rect x="42" y="6" width="2" height="46"/><rect x="46" y="6" width="1" height="46"/>
                                                                <rect x="49" y="6" width="2" height="46"/><rect x="53" y="6" width="3" height="46"/><rect x="58" y="6" width="1" height="46"/>
                                                                <rect x="61" y="6" width="2" height="46"/><rect x="65" y="6" width="3" height="46"/><rect x="70" y="6" width="1" height="46"/>
                                                                <rect x="73" y="6" width="2" height="46"/><rect x="77" y="6" width="1" height="46"/><rect x="80" y="6" width="3" height="46"/>
                                                                <rect x="85" y="6" width="2" height="46"/><rect x="89" y="6" width="1" height="46"/><rect x="92" y="6" width="3" height="46"/>
                                                                <rect x="97" y="6" width="1" height="46"/><rect x="100" y="6" width="2" height="46"/><rect x="104" y="6" width="3" height="46"/>
                                                                <rect x="109" y="6" width="1" height="46"/><rect x="112" y="6" width="2" height="46"/><rect x="116" y="6" width="1" height="46"/>
                                                                <rect x="119" y="6" width="3" height="46"/><rect x="124" y="6" width="2" height="46"/><rect x="128" y="6" width="1" height="46"/>
                                                                <rect x="131" y="6" width="2" height="46"/><rect x="135" y="6" width="3" height="46"/><rect x="140" y="6" width="1" height="46"/>
                                                                <rect x="143" y="6" width="2" height="46"/><rect x="147" y="6" width="3" height="46"/><rect x="152" y="6" width="1" height="46"/>
                                                                <rect x="155" y="6" width="2" height="46"/><rect x="159" y="6" width="1" height="46"/><rect x="162" y="6" width="3" height="46"/>
                                                                <rect x="167" y="6" width="2" height="46"/><rect x="171" y="6" width="3" height="46"/><rect x="176" y="6" width="1" height="46"/>
                                                                <rect x="179" y="6" width="2" height="46"/><rect x="183" y="6" width="3" height="46"/>
                                                            </g>
                                                        </svg>
                                                        <div style="font-size:11px;font-family:'Roboto Mono',monospace;color:#5f6368;margin-top:6px;letter-spacing:0.08em;">TIX-A1B2-C3D4-E5F6</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="tix-settings-hint" style="text-align:center;margin-top:10px;font-size:12px;">↑ So sieht dein Pass in Google Wallet aus (vereinfachte Vorschau)</p>

                                        <script>
                                        (function(){
                                            var pv = document.getElementById('tix-google-pass-preview');
                                            if (!pv) return;
                                            function getInput(name) { return document.querySelector('[name="<?php echo $ok; ?>['+name+']"]'); }
                                            function bind(name, cb){
                                                var el = getInput(name);
                                                if (!el) return;
                                                el.addEventListener('input', function(){ cb(el.value); });
                                                el.addEventListener('change', function(){ cb(el.value); });
                                            }
                                            bind('wallet_google_bg_color', function(v){
                                                var h = document.getElementById('tix-gw-pv-header');
                                                if (h) h.style.background = v;
                                            });
                                            bind('wallet_google_logo_url', function(v){
                                                var el = document.getElementById('tix-gw-pv-logo'); if (!el) return;
                                                if (v) {
                                                    var img = document.createElement('img');
                                                    img.id = 'tix-gw-pv-logo';
                                                    img.src = v;
                                                    img.style.cssText = 'width:36px;height:36px;border-radius:50%;background:#fff;object-fit:contain;padding:4px;';
                                                    el.replaceWith(img);
                                                } else {
                                                    var div = document.createElement('div');
                                                    div.id = 'tix-gw-pv-logo';
                                                    div.style.cssText = 'width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,0.18);display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;font-weight:600;';
                                                    div.textContent = 'T';
                                                    el.replaceWith(div);
                                                }
                                            });
                                            bind('wallet_google_hero_url', function(v){
                                                var el = document.getElementById('tix-gw-pv-hero');
                                                if (!el) return;
                                                if (v) { el.src = v; el.style.display = 'block'; }
                                                else   { el.src = ''; el.style.display = 'none'; }
                                            });
                                        })();
                                        </script>

                                    </div>
                                </div>

                                <?php // ── Bottom-Hinweis ── ?>
                                <div class="tix-card" style="background:#f0f9ff;border-color:#bae6fd;">
                                    <div class="tix-card-body">
                                        <p style="margin:0;font-size:13px;color:#075985;line-height:1.6;">
                                            <span style="font-size:18px;vertical-align:middle;">💡</span>
                                            <strong>Was passiert wenn das aktiv ist?</strong> Auf jedem Ticket erscheint je ein Button für Apple bzw. Google Wallet. Klick → das Plugin generiert ein signiertes Pass-Paket (<code>.pkpass</code> bzw. JWT-Save-Link), das der Nutzer ohne Konto in seine Wallet hinzufügen kann. Dort sind QR-Code, Datum, Venue, Sitzplatz und dein Branding sofort verfügbar — auch offline am Eingang.
                                        </p>
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

            // Restore saved tab — URL-Hash (#wallet) hat Vorrang vor sessionStorage
            (function() {
                var hash = (window.location.hash || '').replace(/^#/, '');
                if (hash) {
                    var hashBtn = app.querySelector('.tix-nav-tab[data-tab="' + hash + '"]');
                    if (hashBtn) {
                        hashBtn.click();
                        // Sicherstellen dass der "Mehr"-Bereich aufklappt falls Tab dort liegt
                        var moreTabs = document.getElementById('tix-settings-more-tabs');
                        if (moreTabs && moreTabs.contains(hashBtn) && !moreTabs.classList.contains('tix-settings-more-open')) {
                            var moreBtn = document.getElementById('tix-settings-more-btn');
                            moreTabs.classList.add('tix-settings-more-open');
                            if (moreBtn) moreBtn.classList.add('active');
                        }
                        // Smoothscroll zum Tab
                        try { hashBtn.scrollIntoView({behavior: 'smooth', block: 'nearest', inline: 'center'}); } catch(e) {}
                        return;
                    }
                }
                if (window.sessionStorage) {
                    var saved = sessionStorage.getItem('tix_settings_tab');
                    if (saved) {
                        var btn = app.querySelector('.tix-nav-tab[data-tab="' + saved + '"]');
                        if (btn) btn.click();
                    }
                }
            })();

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
                    color_primary:'#FF5500', color_text:'',
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
                    color_primary:'#c8ff00', color_text:'#ffffff',
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
                },
                festival: {
                    color_primary:'#FF5500', color_text:'#ffffff',
                    btn1_bg:'#FF5500', btn1_color:'#ffffff', btn1_hover_bg:'#e04a00', btn1_hover_color:'',
                    btn2_bg:'', btn2_color:'#FF5500', btn2_hover_bg:'rgba(255,85,0,0.1)', btn2_hover_color:'',
                    color_border:'#2a2a3a', color_input_border:'#3a3a4a',
                    color_focus:'#e94560', color_sale:'#e94560', save_badge_bg:'#FF5500', save_badge_text:'#ffffff',
                    color_success:'#4caf50',
                    color_card_bg:'#1a1a2e', color_input_bg:'#0f0f1a', shortcode_bg:'#0f0f1a',
                    sel_bg:'#1a1a2e', sel_border_color:'#2a2a3a',
                    sel_active_border:'#FF5500', sel_active_bg:'#2a1a0a', sel_hover_text:'',
                    faq_bg:'#1a1a2e', faq_list_bg:'#0f0f1a',
                    faq_hover_bg:'#252540', faq_hover_text:'', faq_active_bg:'#2a2a3a',
                    faq_border_color:'#2a2a3a', faq_divider_color:'#2a2a3a',
                    faq_accent_color:'#FF5500', faq_icon_color:'#94a3b8', faq_link_color:'#e94560',
                    ec_modal_bg:'#1a1a2e',
                    ec_modal_border:'#2a2a3a', ec_cat_border:'#2a2a3a', ec_cat_active:'#FF5500',
                    mt_bg:'#0f0f1a', mt_card_bg:'#1a1a2e',
                    mt_border_color:'#2a2a3a', mt_ticket_bg:'#2a1a0a', mt_accent_color:'#FF5500',
                    ci_bg:'#0f0f1a', ci_surface:'#1a1a2e', ci_border:'#2a2a3a',
                    ci_text:'#ffffff', ci_muted:'#94a3b8', ci_accent:'#FF5500',
                    ci_accent_text:'#ffffff', ci_ok:'#4caf50', ci_warn:'#eab308', ci_err:'#ef4444'
                },
                corporate: {
                    color_primary:'#3b82f6', color_text:'',
                    btn1_bg:'#1e293b', btn1_color:'#ffffff', btn1_hover_bg:'#334155', btn1_hover_color:'',
                    btn2_bg:'', btn2_color:'#1e293b', btn2_hover_bg:'#f1f5f9', btn2_hover_color:'',
                    color_border:'#e2e8f0', color_input_border:'#cbd5e1',
                    color_focus:'#3b82f6', color_sale:'#ef4444', save_badge_bg:'', save_badge_text:'',
                    color_success:'#22c55e',
                    color_card_bg:'#ffffff', color_input_bg:'#ffffff', shortcode_bg:'#f8fafc',
                    sel_bg:'#ffffff', sel_border_color:'#e2e8f0',
                    sel_active_border:'#3b82f6', sel_active_bg:'#eff6ff', sel_hover_text:'',
                    faq_bg:'#ffffff', faq_list_bg:'#f8fafc',
                    faq_hover_bg:'#f1f5f9', faq_hover_text:'', faq_active_bg:'#e2e8f0',
                    faq_border_color:'#e2e8f0', faq_divider_color:'#e2e8f0',
                    faq_accent_color:'#1e293b', faq_icon_color:'', faq_link_color:'#3b82f6',
                    ec_modal_bg:'#ffffff',
                    ec_modal_border:'#e2e8f0', ec_cat_border:'#e2e8f0', ec_cat_active:'#3b82f6',
                    mt_bg:'#f8fafc', mt_card_bg:'#ffffff',
                    mt_border_color:'#e2e8f0', mt_ticket_bg:'#f0fdf4', mt_accent_color:'#1e293b',
                    ci_bg:'#f8fafc', ci_surface:'#ffffff', ci_border:'#e2e8f0',
                    ci_text:'#1e293b', ci_muted:'#64748b', ci_accent:'#1e293b',
                    ci_accent_text:'#ffffff', ci_ok:'#22c55e', ci_warn:'#eab308', ci_err:'#ef4444'
                },
                elegant: {
                    color_primary:'#c9a84c', color_text:'#2d2d2d',
                    btn1_bg:'#2d2d2d', btn1_color:'#f5f0e8', btn1_hover_bg:'#444444', btn1_hover_color:'',
                    btn2_bg:'', btn2_color:'#2d2d2d', btn2_hover_bg:'#f5f0e8', btn2_hover_color:'',
                    color_border:'#d4c9b5', color_input_border:'#c4b9a5',
                    color_focus:'#c9a84c', color_sale:'#b76e79', save_badge_bg:'#c9a84c', save_badge_text:'#ffffff',
                    color_success:'#4caf50',
                    color_card_bg:'#ffffff', color_input_bg:'#fdfcfa', shortcode_bg:'#f5f0e8',
                    sel_bg:'#ffffff', sel_border_color:'#d4c9b5',
                    sel_active_border:'#c9a84c', sel_active_bg:'#faf5e8', sel_hover_text:'',
                    faq_bg:'#ffffff', faq_list_bg:'#f5f0e8',
                    faq_hover_bg:'#f0ebe0', faq_hover_text:'', faq_active_bg:'#e8e0d0',
                    faq_border_color:'#d4c9b5', faq_divider_color:'#d4c9b5',
                    faq_accent_color:'#2d2d2d', faq_icon_color:'#8a7e6e', faq_link_color:'#c9a84c',
                    ec_modal_bg:'#ffffff',
                    ec_modal_border:'#d4c9b5', ec_cat_border:'#d4c9b5', ec_cat_active:'#c9a84c',
                    mt_bg:'#f5f0e8', mt_card_bg:'#ffffff',
                    mt_border_color:'#d4c9b5', mt_ticket_bg:'#faf5e8', mt_accent_color:'#2d2d2d',
                    ci_bg:'#f5f0e8', ci_surface:'#ffffff', ci_border:'#d4c9b5',
                    ci_text:'#2d2d2d', ci_muted:'#8a7e6e', ci_accent:'#2d2d2d',
                    ci_accent_text:'#f5f0e8', ci_ok:'#4caf50', ci_warn:'#eab308', ci_err:'#ef4444'
                },
                neon: {
                    color_primary:'#ff00ff', color_text:'#ffffff',
                    btn1_bg:'#ff00ff', btn1_color:'#000000', btn1_hover_bg:'#cc00cc', btn1_hover_color:'',
                    btn2_bg:'', btn2_color:'#00ffff', btn2_hover_bg:'rgba(0,255,255,0.1)', btn2_hover_color:'',
                    color_border:'#333333', color_input_border:'#444444',
                    color_focus:'#00ffff', color_sale:'#ff00ff', save_badge_bg:'#ff00ff', save_badge_text:'#000000',
                    color_success:'#c8ff00',
                    color_card_bg:'#1a1a1a', color_input_bg:'#0a0a0a', shortcode_bg:'#0a0a0a',
                    sel_bg:'#1a1a1a', sel_border_color:'#333333',
                    sel_active_border:'#00ffff', sel_active_bg:'#0a1a1a', sel_hover_text:'',
                    faq_bg:'#1a1a1a', faq_list_bg:'#0a0a0a',
                    faq_hover_bg:'#2a2a2a', faq_hover_text:'', faq_active_bg:'#333333',
                    faq_border_color:'#333333', faq_divider_color:'#333333',
                    faq_accent_color:'#00ffff', faq_icon_color:'#94a3b8', faq_link_color:'#ff00ff',
                    ec_modal_bg:'#1a1a1a',
                    ec_modal_border:'#333333', ec_cat_border:'#333333', ec_cat_active:'#ff00ff',
                    mt_bg:'#0a0a0a', mt_card_bg:'#1a1a1a',
                    mt_border_color:'#333333', mt_ticket_bg:'#1a0a1a', mt_accent_color:'#ff00ff',
                    ci_bg:'#0a0a0a', ci_surface:'#1a1a1a', ci_border:'#333333',
                    ci_text:'#ffffff', ci_muted:'#94a3b8', ci_accent:'#ff00ff',
                    ci_accent_text:'#000000', ci_ok:'#c8ff00', ci_warn:'#eab308', ci_err:'#ef4444'
                },
                warm: {
                    color_primary:'#D2691E', color_text:'#5C3317',
                    btn1_bg:'#8B4513', btn1_color:'#ffffff', btn1_hover_bg:'#6B3410', btn1_hover_color:'',
                    btn2_bg:'', btn2_color:'#8B4513', btn2_hover_bg:'#FFF0E0', btn2_hover_color:'',
                    color_border:'#E8D5C0', color_input_border:'#D4C0A8',
                    color_focus:'#D2691E', color_sale:'#ef4444', save_badge_bg:'#D2691E', save_badge_text:'#ffffff',
                    color_success:'#22c55e',
                    color_card_bg:'#ffffff', color_input_bg:'#FFFBF5', shortcode_bg:'#FFF8F0',
                    sel_bg:'#ffffff', sel_border_color:'#E8D5C0',
                    sel_active_border:'#D2691E', sel_active_bg:'#FFF0E0', sel_hover_text:'',
                    faq_bg:'#ffffff', faq_list_bg:'#FFF8F0',
                    faq_hover_bg:'#FFF0E0', faq_hover_text:'', faq_active_bg:'#FFE8D0',
                    faq_border_color:'#E8D5C0', faq_divider_color:'#E8D5C0',
                    faq_accent_color:'#8B4513', faq_icon_color:'#A08060', faq_link_color:'#D2691E',
                    ec_modal_bg:'#ffffff',
                    ec_modal_border:'#E8D5C0', ec_cat_border:'#E8D5C0', ec_cat_active:'#D2691E',
                    mt_bg:'#FFF8F0', mt_card_bg:'#ffffff',
                    mt_border_color:'#E8D5C0', mt_ticket_bg:'#FFF0E0', mt_accent_color:'#8B4513',
                    ci_bg:'#FFF8F0', ci_surface:'#ffffff', ci_border:'#E8D5C0',
                    ci_text:'#5C3317', ci_muted:'#A08060', ci_accent:'#8B4513',
                    ci_accent_text:'#ffffff', ci_ok:'#22c55e', ci_warn:'#eab308', ci_err:'#ef4444'
                },
                ocean: {
                    color_primary:'#0891b2', color_text:'#0c4a6e',
                    btn1_bg:'#0c4a6e', btn1_color:'#ffffff', btn1_hover_bg:'#083b5a', btn1_hover_color:'',
                    btn2_bg:'', btn2_color:'#0c4a6e', btn2_hover_bg:'#f0f7ff', btn2_hover_color:'',
                    color_border:'#bae6fd', color_input_border:'#7dd3fc',
                    color_focus:'#0891b2', color_sale:'#ef4444', save_badge_bg:'#0891b2', save_badge_text:'#ffffff',
                    color_success:'#22c55e',
                    color_card_bg:'#ffffff', color_input_bg:'#f8fdff', shortcode_bg:'#f0f7ff',
                    sel_bg:'#ffffff', sel_border_color:'#bae6fd',
                    sel_active_border:'#0891b2', sel_active_bg:'#ecfeff', sel_hover_text:'',
                    faq_bg:'#ffffff', faq_list_bg:'#f0f7ff',
                    faq_hover_bg:'#e0f2fe', faq_hover_text:'', faq_active_bg:'#bae6fd',
                    faq_border_color:'#bae6fd', faq_divider_color:'#bae6fd',
                    faq_accent_color:'#0c4a6e', faq_icon_color:'#0891b2', faq_link_color:'#0891b2',
                    ec_modal_bg:'#ffffff',
                    ec_modal_border:'#bae6fd', ec_cat_border:'#bae6fd', ec_cat_active:'#0891b2',
                    mt_bg:'#f0f7ff', mt_card_bg:'#ffffff',
                    mt_border_color:'#bae6fd', mt_ticket_bg:'#ecfeff', mt_accent_color:'#0c4a6e',
                    ci_bg:'#f0f7ff', ci_surface:'#ffffff', ci_border:'#bae6fd',
                    ci_text:'#0c4a6e', ci_muted:'#0891b2', ci_accent:'#0c4a6e',
                    ci_accent_text:'#ffffff', ci_ok:'#22c55e', ci_warn:'#eab308', ci_err:'#ef4444'
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
                    document.querySelectorAll('.tix-ci-theme-btn').forEach(function(b) {
                        b.classList.remove('active');
                        b.style.borderColor = '#d1d5db';
                    });
                    this.classList.add('active');
                    this.style.borderColor = 'var(--tix-primary,#6366f1)';
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

        <?php // ── Settings Search ── ?>
        <script>
        (function() {
            'use strict';

            var app      = document.querySelector('.tix-settings-app');
            var input    = document.getElementById('tix-settings-search');
            var results  = document.getElementById('tix-search-results');
            var clearBtn = document.getElementById('tix-search-clear');
            if (!app || !input || !results) return;

            // ── Build search index from DOM ──
            var index = [];

            // Tab name → icon mapping
            var tabMap = {};
            app.querySelectorAll('.tix-nav-tab[data-tab]').forEach(function(btn) {
                var icon = btn.querySelector('.dashicons');
                var label = btn.querySelector('.tix-nav-label');
                if (label) {
                    tabMap[btn.getAttribute('data-tab')] = {
                        label: label.textContent.trim(),
                        icon: icon ? icon.className.replace('dashicons ', '').replace('dashicons-', '') : 'admin-generic'
                    };
                }
            });

            // Crawl all panes
            app.querySelectorAll('.tix-pane[data-pane]').forEach(function(pane) {
                var tabId = pane.getAttribute('data-pane');
                var tabInfo = tabMap[tabId] || { label: tabId, icon: 'admin-generic' };

                // Index card headers (h3)
                pane.querySelectorAll('.tix-card').forEach(function(card, cardIdx) {
                    var h3 = card.querySelector('.tix-card-header h3');
                    var cardTitle = h3 ? h3.textContent.trim() : '';

                    if (cardTitle) {
                        index.push({
                            label: cardTitle,
                            meta: tabInfo.label,
                            icon: tabInfo.icon,
                            tab: tabId,
                            card: card,
                            type: 'card'
                        });
                    }

                    // Index field labels
                    card.querySelectorAll('.tix-field-label, .tix-field label, label.tix-toggle').forEach(function(lbl) {
                        var text = lbl.textContent.trim();
                        if (!text || text.length < 2) return;
                        // Skip duplicate if same as card title
                        if (text === cardTitle) return;

                        index.push({
                            label: text,
                            meta: tabInfo.label + ' → ' + (cardTitle || 'Einstellungen'),
                            icon: tabInfo.icon,
                            tab: tabId,
                            card: card,
                            field: lbl,
                            type: 'field'
                        });
                    });

                    // Index select/input with name attributes (setting keys)
                    card.querySelectorAll('input[name], select[name], textarea[name]').forEach(function(el) {
                        var name = el.getAttribute('name') || '';
                        // Extract key from tix_settings[key_name]
                        var m = name.match(/tix_settings\[([^\]]+)\]/);
                        if (m) {
                            var key = m[1];
                            // Check if already indexed by label
                            var already = false;
                            var closestLabel = el.closest('.tix-field, .tix-field-full');
                            if (closestLabel && closestLabel.querySelector('.tix-field-label, label')) already = true;

                            if (!already) {
                                index.push({
                                    label: key.replace(/_/g, ' '),
                                    meta: tabInfo.label + ' → ' + (cardTitle || 'Einstellungen'),
                                    icon: tabInfo.icon,
                                    tab: tabId,
                                    card: card,
                                    field: el,
                                    type: 'key'
                                });
                            }
                        }
                    });
                });

                // Index accordion headers (for Typography/Colors)
                pane.querySelectorAll('.tix-typo-accordion-header, .tix-color-accordion-header, [class*="accordion-header"]').forEach(function(hdr) {
                    var text = hdr.textContent.trim().replace(/[\u25B6\u25BC]/g, '').trim();
                    if (text) {
                        index.push({
                            label: text,
                            meta: tabInfo.label,
                            icon: tabInfo.icon,
                            tab: tabId,
                            card: hdr.closest('.tix-card') || hdr,
                            type: 'accordion'
                        });
                    }
                });
            });

            // ── Search logic ──
            var activeIdx = -1;
            var filtered = [];

            function search(query) {
                query = query.toLowerCase().trim();
                if (!query || query.length < 2) {
                    results.classList.remove('open');
                    results.innerHTML = '';
                    clearBtn.classList.toggle('visible', input.value.length > 0);
                    return;
                }

                clearBtn.classList.add('visible');

                // Split query into words for multi-word matching
                var words = query.split(/\s+/).filter(function(w) { return w.length > 0; });

                filtered = index.filter(function(item) {
                    var haystack = (item.label + ' ' + item.meta).toLowerCase();
                    return words.every(function(w) { return haystack.indexOf(w) !== -1; });
                });

                // Deduplicate by label+tab
                var seen = {};
                filtered = filtered.filter(function(item) {
                    var key = item.label + '|' + item.tab;
                    if (seen[key]) return false;
                    seen[key] = true;
                    return true;
                });

                // Limit results
                filtered = filtered.slice(0, 15);

                if (filtered.length === 0) {
                    results.innerHTML = '<div class="tix-search-no-results">Keine Ergebnisse f&uuml;r &bdquo;' + escHtml(query) + '&ldquo;</div>';
                    results.classList.add('open');
                    activeIdx = -1;
                    return;
                }

                var html = '';
                filtered.forEach(function(item, i) {
                    var label = highlightMatch(item.label, words);
                    html += '<div class="tix-search-result' + (i === 0 ? ' active' : '') + '" data-idx="' + i + '">'
                        + '<div class="tix-search-result-icon"><span class="dashicons dashicons-' + item.icon + '"></span></div>'
                        + '<div class="tix-search-result-text">'
                        + '<div class="tix-search-result-label">' + label + '</div>'
                        + '<div class="tix-search-result-meta">' + escHtml(item.meta) + '</div>'
                        + '</div></div>';
                });

                results.innerHTML = html;
                results.classList.add('open');
                activeIdx = 0;

                // Click handlers
                results.querySelectorAll('.tix-search-result').forEach(function(el) {
                    el.addEventListener('click', function() {
                        var idx = parseInt(this.getAttribute('data-idx'));
                        selectResult(idx);
                    });
                    el.addEventListener('mouseenter', function() {
                        results.querySelectorAll('.tix-search-result').forEach(function(r) { r.classList.remove('active'); });
                        this.classList.add('active');
                        activeIdx = parseInt(this.getAttribute('data-idx'));
                    });
                });
            }

            function selectResult(idx) {
                var item = filtered[idx];
                if (!item) return;

                // Close search
                results.classList.remove('open');
                input.value = '';
                clearBtn.classList.remove('visible');

                // Switch to correct tab
                var tabBtn = app.querySelector('.tix-nav-tab[data-tab="' + item.tab + '"]');
                if (tabBtn) {
                    // Open "Mehr" section if the tab is hidden
                    var moreTabs = document.getElementById('tix-settings-more-tabs');
                    if (moreTabs && moreTabs.contains(tabBtn) && !moreTabs.classList.contains('tix-settings-more-open')) {
                        var moreBtn = document.getElementById('tix-settings-more-btn');
                        if (moreBtn) moreBtn.click();
                    }
                    tabBtn.click();
                }

                // Scroll to and highlight card
                setTimeout(function() {
                    var target = item.card;
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        target.classList.remove('tix-search-highlight');
                        void target.offsetWidth; // force reflow
                        target.classList.add('tix-search-highlight');

                        // If accordion, open it
                        if (item.type === 'accordion') {
                            var accBody = target.nextElementSibling;
                            if (accBody && accBody.style.display === 'none') {
                                target.click();
                            }
                        }
                    }
                }, 120);
            }

            function highlightMatch(text, words) {
                var safe = escHtml(text);
                words.forEach(function(w) {
                    var re = new RegExp('(' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
                    safe = safe.replace(re, '<mark>$1</mark>');
                });
                return safe;
            }

            function escHtml(s) {
                var d = document.createElement('div');
                d.textContent = s;
                return d.innerHTML;
            }

            // ── Event listeners ──
            var debounce = null;
            input.addEventListener('input', function() {
                clearTimeout(debounce);
                debounce = setTimeout(function() { search(input.value); }, 150);
            });

            input.addEventListener('keydown', function(e) {
                if (!results.classList.contains('open')) return;
                var items = results.querySelectorAll('.tix-search-result');

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIdx = Math.min(activeIdx + 1, items.length - 1);
                    items.forEach(function(r) { r.classList.remove('active'); });
                    if (items[activeIdx]) { items[activeIdx].classList.add('active'); items[activeIdx].scrollIntoView({ block: 'nearest' }); }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIdx = Math.max(activeIdx - 1, 0);
                    items.forEach(function(r) { r.classList.remove('active'); });
                    if (items[activeIdx]) { items[activeIdx].classList.add('active'); items[activeIdx].scrollIntoView({ block: 'nearest' }); }
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (activeIdx >= 0) selectResult(activeIdx);
                } else if (e.key === 'Escape') {
                    results.classList.remove('open');
                    input.blur();
                }
            });

            clearBtn.addEventListener('click', function() {
                input.value = '';
                results.classList.remove('open');
                clearBtn.classList.remove('visible');
                input.focus();
            });

            // Close on outside click
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#tix-search-wrap')) {
                    results.classList.remove('open');
                }
            });

            // Keyboard shortcut: Ctrl+K or Cmd+K to focus search
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                    e.preventDefault();
                    input.focus();
                    input.select();
                }
            });
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

    /**
     * Responsive Number-Input-Reihe (5 Breakpoints).
     */
    private static function responsive_number_row($label, $breakpoints, $s, $min = 0, $max = 80, $hint = '') {
        ?>
        <div class="tix-field tix-field-full">
            <label class="tix-field-label"><?php echo esc_html($label); ?></label>
            <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-top:6px;">
                <?php foreach ($breakpoints as $key => $meta): ?>
                <div style="text-align:center;">
                    <div style="font-size:11px;color:#64748b;margin-bottom:4px;"><?php echo $meta['icon']; ?> <?php echo $meta['label']; ?></div>
                    <input type="number" name="tix_settings[<?php echo esc_attr($key); ?>]"
                           value="<?php echo intval($s[$key] ?? $meta['def']); ?>"
                           min="<?php echo $min; ?>" max="<?php echo $max; ?>" step="1"
                           style="width:100%;text-align:center;padding:6px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-weight:600;">
                    <div style="font-size:10px;color:#94a3b8;margin-top:2px;">px</div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($hint): ?>
            <p style="font-size:11px;color:#94a3b8;margin:6px 0 0;"><?php echo esc_html($hint); ?></p>
            <?php endif; ?>
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
     * Bild-URL-Feld mit Media-Library-Picker und Vorschau.
     * Ergänzt automatisch JS für den Picker — globaler Helper, eindeutig per key.
     */
    private static function media_url_row($key, $label, $s, $hint = '') {
        $val  = $s[$key] ?? '';
        $name = self::OPTION_KEY . "[$key]";
        $field_id = 'tix-media-' . sanitize_key($key);
        ?>
        <div class="tix-field tix-field-full">
            <label class="tix-field-label"><?php echo esc_html($label); ?></label>
            <div style="display:flex;gap:8px;align-items:center;">
                <input type="text" id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($name); ?>"
                       value="<?php echo esc_attr($val); ?>"
                       class="regular-text" style="flex:1;" placeholder="https://...">
                <button type="button" class="button" data-tix-media-pick="<?php echo esc_attr($field_id); ?>">Bild wählen</button>
                <?php if ($val): ?><button type="button" class="button-link" style="color:#ef4444;" data-tix-media-clear="<?php echo esc_attr($field_id); ?>">Entfernen</button><?php endif; ?>
            </div>
            <?php if ($val): ?>
                <div style="margin-top:8px;" id="<?php echo esc_attr($field_id); ?>-preview">
                    <img src="<?php echo esc_url($val); ?>" style="max-height:60px;width:auto;background:#0f172a;padding:6px 10px;border-radius:6px;">
                </div>
            <?php endif; ?>
            <?php if ($hint): ?><p class="tix-field-hint"><?php echo $hint; ?></p><?php endif; ?>
        </div>
        <script>
        (function(){
            if (window.tixMediaPickerInit) return;
            window.tixMediaPickerInit = true;
            jQuery(document).on('click', '[data-tix-media-pick]', function(e){
                e.preventDefault();
                var fieldId = this.getAttribute('data-tix-media-pick');
                var input = document.getElementById(fieldId);
                if (!input) return;
                var frame = wp.media({title:'Bild wählen',multiple:false,library:{type:'image'}});
                frame.on('select', function(){
                    var url = frame.state().get('selection').first().toJSON().url;
                    input.value = url;
                    var prev = document.getElementById(fieldId + '-preview');
                    if (prev) {
                        prev.innerHTML = '<img src="' + url + '" style="max-height:60px;width:auto;background:#0f172a;padding:6px 10px;border-radius:6px;">';
                    } else {
                        var d = document.createElement('div');
                        d.id = fieldId + '-preview';
                        d.style.marginTop = '8px';
                        d.innerHTML = '<img src="' + url + '" style="max-height:60px;width:auto;background:#0f172a;padding:6px 10px;border-radius:6px;">';
                        input.parentNode.parentNode.appendChild(d);
                    }
                });
                frame.open();
            });
            jQuery(document).on('click', '[data-tix-media-clear]', function(e){
                e.preventDefault();
                var fieldId = this.getAttribute('data-tix-media-clear');
                var input = document.getElementById(fieldId);
                if (input) input.value = '';
                var prev = document.getElementById(fieldId + '-preview');
                if (prev) prev.remove();
            });
        })();
        </script>
        <?php
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
                'tix-ep-location-desc'    => ['label' => 'Ort-Kurzbeschreibung','size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-ep-organizer-name'   => ['label' => 'Veranstalter',        'size' => 14, 'font' => 'body', 'weight' => 600],
                'tix-ep-organizer-label'  => ['label' => 'Veranstalter-Label',  'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-ep-organizer-desc'   => ['label' => 'Veranstalter-Kurzbeschreibung', 'size' => 13, 'font' => 'body', 'weight' => 400],
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
                'tix-ci-list-header'   => ['label' => 'Listen-Header',    'size' => 15, 'font' => 'body', 'weight' => 650],
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
            'Social Proof' => [
                'tix-sp' => ['label' => 'Social Proof Text', 'size' => 13, 'font' => 'body', 'weight' => 600],
            ],
            'My Account' => [
                'wc-nav-link'     => ['label' => 'Navigation',       'size' => 14, 'font' => 'body', 'weight' => 500],
                'wc-content-h2'   => ['label' => 'Überschrift H2',   'size' => 18, 'font' => 'heading', 'weight' => 700],
                'wc-content-h3'   => ['label' => 'Überschrift H3',   'size' => 15, 'font' => 'body', 'weight' => 400],
                'wc-table-th'     => ['label' => 'Tabellen-Header',  'size' => 12, 'font' => 'body', 'weight' => 700],
                'wc-table-td'     => ['label' => 'Tabellen-Zelle',   'size' => 14, 'font' => 'body', 'weight' => 400],
                'wc-order-status' => ['label' => 'Bestell-Status',   'size' => 12, 'font' => 'body', 'weight' => 700],
                'wc-button'       => ['label' => 'Button',           'size' => 14, 'font' => 'body', 'weight' => 700],
                'wc-label'        => ['label' => 'Formular-Label',   'size' => 13, 'font' => 'body', 'weight' => 600],
                'wc-input'        => ['label' => 'Eingabefeld',      'size' => 15, 'font' => 'body', 'weight' => 400],
                'wc-message'      => ['label' => 'Meldung',          'size' => 14, 'font' => 'body', 'weight' => 400],
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
                'tix-re-field-label'   => ['label' => 'Feld-Label',      'size' => 12, 'font' => 'body', 'weight' => 600],
                'tix-re-legal'         => ['label' => 'Rechtliches',     'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-re-btn-primary'   => ['label' => 'Haupt-Button',    'size' => 14, 'font' => 'body', 'weight' => 600],
                'tix-re-error'         => ['label' => 'Fehler-Meldung',  'size' => 14, 'font' => 'body', 'weight' => 400],
            ],
            'Veranstalter-Landing' => [
                'tix-brand-name'                 => ['label' => 'Header-Veranstaltername', 'size' => 16, 'font' => 'heading', 'weight' => 700],
                'tix-org-brand-header-back'      => ['label' => 'Header "Zurück"-Button',  'size' => 13, 'font' => 'heading', 'weight' => 600],
                'tix-ol-hero-title'              => ['label' => 'Hero-Titel',              'size' => 40, 'font' => 'heading', 'weight' => 700],
                'tix-ol-hero-tagline'            => ['label' => 'Hero-Untertitel',         'size' => 17, 'font' => 'body',    'weight' => 400],
                'tix-ol-hero-cta'                => ['label' => 'Hero-CTA-Button',         'size' => 14, 'font' => 'heading', 'weight' => 600],
                'tix-ol-section-heading'         => ['label' => 'Sektions-Überschrift',    'size' => 32, 'font' => 'heading', 'weight' => 700],
                'tix-ol-about-text'              => ['label' => 'Über-uns-Text',           'size' => 16, 'font' => 'body',    'weight' => 400],
                'tix-ol-empty'                   => ['label' => 'Leere-Liste-Hinweis',     'size' => 15, 'font' => 'body',    'weight' => 400],
                'tix-ol-past-details'            => ['label' => 'Vergangene-Events-Toggle','size' => 14, 'font' => 'heading', 'weight' => 600],
                'tix-org-brand-footer-links'     => ['label' => 'Footer-Links',            'size' => 14, 'font' => 'heading', 'weight' => 500],
                'tix-org-brand-footer-meta'      => ['label' => 'Footer-Credit (unten)',   'size' => 11, 'font' => 'body',    'weight' => 400],
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
                'tix-ep-location-desc'  => ['label' => 'Ort-Kurzbeschreibung',  'props' => ['color' => '#64748b']],
                'tix-ep-organizer-name' => ['label' => 'Veranstalter',           'props' => ['color' => '#1f2937']],
                'tix-ep-organizer-label'=> ['label' => 'Veranstalter-Label',     'props' => ['color' => '#64748b']],
                'tix-ep-organizer-desc' => ['label' => 'Veranstalter-Kurzbeschreibung', 'props' => ['color' => '#64748b']],
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
            'Magic-Link Login' => [
                'tix-magic-block'        => ['label' => 'Box',                  'props' => ['bg' => '#ffffff', 'border' => '#e2e8f0']],
                'tix-magic-title'        => ['label' => 'Überschrift',          'props' => ['color' => '#0f172a']],
                'tix-magic-text'         => ['label' => 'Beschreibungstext',    'props' => ['color' => '#64748b']],
                'tix-magic-input'        => ['label' => 'Eingabefeld',          'props' => ['bg' => '#ffffff', 'color' => '#0f172a', 'border' => '#cbd5e1']],
                'tix-magic-btn'          => ['label' => 'Button',               'props' => ['bg' => '#0284c7', 'color' => '#ffffff']],
            ],
            'Coupon-Popup' => [
                'tix-cp-overlay'      => ['label' => 'Hintergrund-Overlay',    'props' => ['bg' => 'rgba(15,23,42,.65)']],
                'tix-cp-modal'        => ['label' => 'Modal-Hintergrund',      'props' => ['bg' => '#ffffff']],
                'tix-cp-banner'       => ['label' => 'Banner (oben, Wert-Bereich)', 'props' => ['bg' => '#FF5500', 'color' => '#ffffff']],
                'tix-cp-saving'       => ['label' => 'Banner-Label "Du sparst"',    'props' => ['color' => '#ffffff']],
                'tix-cp-value'        => ['label' => 'Banner-Wert (groß)',          'props' => ['color' => '#ffffff']],
                'tix-cp-headline'     => ['label' => 'Überschrift',                 'props' => ['color' => '#0f172a']],
                'tix-cp-subtext'      => ['label' => 'Beschreibungstext',           'props' => ['color' => '#64748b']],
                'tix-cp-code-box'     => ['label' => 'Code-Box',                    'props' => ['bg' => '#f0fdf4', 'border' => '#22c55e']],
                'tix-cp-code-label'   => ['label' => 'Code-Label "Gutscheincode"',  'props' => ['color' => '#15803d']],
                'tix-cp-code'         => ['label' => 'Code-Text',                   'props' => ['color' => '#15803d']],
                'tix-cp-code-status'  => ['label' => 'Code-Status "✓ aktiv"',       'props' => ['color' => '#16a34a']],
                'tix-cp-cta'          => ['label' => 'CTA-Button',                  'props' => ['bg' => '#0f172a', 'color' => '#ffffff']],
                'tix-cp-close'        => ['label' => 'Schließen-Button (×)',        'props' => ['bg' => 'rgba(255,255,255,.25)', 'color' => '#ffffff']],
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

    /* =========================================================
     * Export / Import AJAX handlers
     * ========================================================= */

    /**
     * Separate wp_options die zum Plugin gehoeren (neben tix_settings).
     */
    private static function extra_option_keys(): array {
        return [
            'tix_order_seq',
            'tix_coupons',
            'tix_db_version',
            'tix_checkout_page_id',
            'tix_auto_widget',
            'tix_organizer_auto_publish',
            'tix_meta_adspend',
        ];
    }

    /**
     * AJAX: Export alle Settings als JSON.
     */
    public static function ajax_export() {
        check_ajax_referer('tix_export_settings');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung.', 403);
        }

        $payload = [
            '_tix_export' => [
                'plugin'  => 'tixomat',
                'version' => defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : '?',
                'date'    => gmdate('c'),
                'site'    => get_site_url(),
                'wp'      => get_bloginfo('version'),
            ],
            'tix_settings' => get_option('tix_settings', []),
        ];

        // Separate Optionen
        foreach (self::extra_option_keys() as $key) {
            $val = get_option($key, null);
            if ($val !== null) {
                $payload[$key] = $val;
            }
        }

        wp_send_json_success($payload);
    }

    /**
     * AJAX: Import Settings aus JSON.
     */
    public static function ajax_import() {
        check_ajax_referer('tix_import_settings');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung.', 403);
        }

        $raw = isset($_POST['data']) ? wp_unslash($_POST['data']) : '';
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            wp_send_json_error('Ungueltige JSON-Daten.');
        }

        // Muss tix_settings enthalten
        if (!isset($data['tix_settings']) || !is_array($data['tix_settings'])) {
            wp_send_json_error('JSON enthaelt kein gueltiges tix_settings Array.');
        }

        // Meta pruefen (optional, nur Warnung)
        $meta = $data['_tix_export'] ?? [];

        // --- tix_settings importieren ---
        $imported = $data['tix_settings'];

        // Sanitize: Sicherstellen dass nur erlaubte Keys drin sind
        // Wir mergen mit bestehenden Defaults und sanitizen die Werte
        $sanitized = [];
        foreach ($imported as $k => $v) {
            if (is_string($k)) {
                // Einfache Sanitization: Strings escapen, Arrays durchlassen, Numerics casten
                if (is_array($v)) {
                    $sanitized[$k] = self::sanitize_setting_array($v);
                } elseif (is_numeric($v)) {
                    $sanitized[$k] = is_float($v + 0) ? (float)$v : (int)$v;
                } else {
                    $sanitized[$k] = sanitize_text_field((string)$v);
                }
            }
        }

        update_option('tix_settings', $sanitized);

        // --- Separate Optionen importieren ---
        $extra_updated = 0;
        foreach (self::extra_option_keys() as $key) {
            if (array_key_exists($key, $data)) {
                $val = $data[$key];
                // Sanitize je nach Typ
                if (is_array($val)) {
                    $val = self::sanitize_setting_array($val);
                } elseif (is_numeric($val)) {
                    $val = is_float($val + 0) ? (float)$val : (int)$val;
                } else {
                    $val = sanitize_text_field((string)$val);
                }
                update_option($key, $val);
                $extra_updated++;
            }
        }

        $count = count($sanitized) + $extra_updated;
        $source = !empty($meta['site']) ? ' von ' . esc_html($meta['site']) : '';

        wp_send_json_success(sprintf(
            'Import erfolgreich! %d Einstellungen importiert%s.',
            $count,
            $source
        ));
    }

    /**
     * Rekursive Sanitization fuer verschachtelte Arrays.
     */
    private static function sanitize_setting_array(array $arr): array {
        $clean = [];
        foreach ($arr as $k => $v) {
            $key = is_string($k) ? sanitize_text_field($k) : (int)$k;
            if (is_array($v)) {
                $clean[$key] = self::sanitize_setting_array($v);
            } elseif (is_numeric($v)) {
                $clean[$key] = is_float($v + 0) ? (float)$v : (int)$v;
            } elseif (is_bool($v)) {
                $clean[$key] = $v;
            } else {
                $clean[$key] = sanitize_text_field((string)$v);
            }
        }
        return $clean;
    }

    /**
     * Sortiert eine Liste von Gateway-Methoden nach einer gespeicherten Reihenfolge.
     * Unbekannte/neue Methoden landen ans Ende.
     *
     * @param array $methods Array of ['id' => ..., ...] entries.
     * @param array $order   Ordered list of method-IDs.
     */
    public static function sort_methods_by_order(array $methods, array $order): array {
        if (empty($order)) return $methods;
        $by_id = [];
        foreach ($methods as $m) $by_id[$m['id']] = $m;
        $sorted = [];
        foreach ($order as $id) {
            if (isset($by_id[$id])) {
                $sorted[] = $by_id[$id];
                unset($by_id[$id]);
            }
        }
        foreach ($by_id as $rest) $sorted[] = $rest;
        return $sorted;
    }
}
