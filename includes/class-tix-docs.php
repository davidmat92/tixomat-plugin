<?php
if (!defined('ABSPATH')) exit;

class TIX_Docs {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets($hook) {
        if ($hook !== 'tixomat_page_tix-docs') return;
        wp_enqueue_style('dashicons');
        wp_enqueue_style('tix-admin', TIXOMAT_URL . 'assets/css/admin.css', ['tix-google-fonts'], TIXOMAT_VERSION);
    }

    public static function add_menu() {
        add_submenu_page(
            'tixomat',
            'Tixomat Dokumentation',
            'Dokumentation',
            'manage_options',
            'tix-docs',
            [__CLASS__, 'render']
        );
    }

    public static function render() {
        ?>
        <div class="wrap tix-settings-wrap">
            <h1>Tixomat &ndash; Dokumentation</h1>

            <div class="tix-settings-grid">
                <div class="tix-app tix-docs-app">

                    <nav class="tix-nav">
                        <button type="button" class="tix-nav-tab active" data-tab="meta">
                            <span class="dashicons dashicons-database"></span>
                            <span class="tix-nav-label">Meta-Felder</span>
                        </button>
                        <button type="button" class="tix-nav-tab" data-tab="shortcodes">
                            <span class="dashicons dashicons-shortcode"></span>
                            <span class="tix-nav-label">Shortcodes</span>
                        </button>
                        <button type="button" class="tix-nav-tab" data-tab="functions">
                            <span class="dashicons dashicons-admin-tools"></span>
                            <span class="tix-nav-label">Funktionen</span>
                        </button>
                        <button type="button" class="tix-nav-tab" data-tab="ajax">
                            <span class="dashicons dashicons-rest-api"></span>
                            <span class="tix-nav-label">AJAX &amp; Hooks</span>
                        </button>
                        <button type="button" class="tix-nav-tab" data-tab="templates">
                            <span class="dashicons dashicons-media-text"></span>
                            <span class="tix-nav-label">Ticket-Vorlagen</span>
                        </button>
                        <button type="button" class="tix-nav-tab" data-tab="promoter">
                            <span class="dashicons dashicons-businessman"></span>
                            <span class="tix-nav-label">Promoter</span>
                        </button>
                        <button type="button" class="tix-nav-tab" data-tab="organizer">
                            <span class="dashicons dashicons-groups"></span>
                            <span class="tix-nav-label">Veranstalter</span>
                        </button>
                        <button type="button" class="tix-nav-tab" data-tab="landing">
                            <span class="dashicons dashicons-welcome-view-site"></span>
                            <span class="tix-nav-label">Landingpage</span>
                        </button>
                        <button type="button" class="tix-nav-tab" data-tab="datamodel">
                            <span class="dashicons dashicons-index-card"></span>
                            <span class="tix-nav-label">Datenmodell</span>
                        </button>
                        <button type="button" class="tix-nav-tab" data-tab="hooks">
                            <span class="dashicons dashicons-randomize"></span>
                            <span class="tix-nav-label">Hooks</span>
                        </button>
                        <button type="button" class="tix-nav-tab" data-tab="bot">
                            <span class="dashicons dashicons-format-chat"></span>
                            <span class="tix-nav-label">Ticket-Bot</span>
                        </button>
                        <button type="button" class="tix-nav-tab" data-tab="rest-api">
                            <span class="dashicons dashicons-rest-api"></span>
                            <span class="tix-nav-label">REST API</span>
                        </button>
                    </nav>

                    <div class="tix-content">

                        <?php self::render_meta_tab(); ?>
                        <?php self::render_shortcodes_tab(); ?>
                        <?php self::render_functions_tab(); ?>
                        <?php self::render_ajax_tab(); ?>
                        <?php self::render_templates_tab(); ?>
                        <?php self::render_promoter_tab(); ?>
                        <?php self::render_organizer_tab(); ?>
                        <?php self::render_landing_tab(); ?>
                        <?php self::render_datamodel_tab(); ?>
                        <?php self::render_hooks_tab(); ?>
                        <?php self::render_bot_tab(); ?>
                        <?php self::render_rest_api_tab(); ?>

                    </div>
                </div>
            </div>
        </div>

        <script>
        (function() {
            'use strict';
            var app = document.querySelector('.tix-docs-app');
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
                    if (window.sessionStorage) sessionStorage.setItem('tix_docs_tab', t);
                }.bind(tab));
            });

            if (window.sessionStorage) {
                var saved = sessionStorage.getItem('tix_docs_tab');
                if (saved) {
                    var btn = app.querySelector('.tix-nav-tab[data-tab="' + saved + '"]');
                    if (btn) btn.click();
                }
            }
        })();
        </script>
        <?php
    }

    // ══════════════════════════════════════
    // TAB 1: META-FELDER
    // ══════════════════════════════════════

    private static function render_meta_tab() {
        ?>
        <div class="tix-pane active" data-pane="meta">

            <p class="description">
                <strong>Meta-Felder</strong> sind gespeicherte Daten, die zu jedem Event (oder Abonnent, Warenkorb etc.) geh&ouml;ren.
                Du kannst sie z.B. in Page-Buildern oder eigenen Templates mit <code>get_post_meta($post_id, '_tix_...', true)</code> auslesen.
                Alle mit &bdquo;Breakdance&ldquo; markierten Felder werden automatisch beim Sync generiert und sind f&uuml;r die Verwendung in Breakdance Dynamic Data optimiert.
            </p>

            <?php
            // ── Datum & Uhrzeit ──
            self::meta_card('Datum &amp; Uhrzeit', 'dashicons-calendar-alt', [
                ['_tix_date_start', 'Startdatum des Events', 'Datum (JJJJ-MM-TT)'],
                ['_tix_date_end', 'Enddatum des Events (bei mehrt&auml;gigen Events)', 'Datum (JJJJ-MM-TT)'],
                ['_tix_time_start', 'Beginn-Uhrzeit', 'Uhrzeit (HH:MM)'],
                ['_tix_time_end', 'End-Uhrzeit', 'Uhrzeit (HH:MM)'],
                ['_tix_time_doors', 'Einlass-Uhrzeit (optional)', 'Uhrzeit (HH:MM)'],
                ['_tix_date_start_formatted', '<span class="tix-badge-bd">Breakdance</span> Formatiertes Startdatum (z.B. &bdquo;15.03.2026&ldquo;)', 'Text (d.m.Y)'],
                ['_tix_date_end_formatted', '<span class="tix-badge-bd">Breakdance</span> Formatiertes Enddatum', 'Text (d.m.Y)'],
                ['_tix_time_start_formatted', '<span class="tix-badge-bd">Breakdance</span> Formatierte Beginn-Uhrzeit (z.B. &bdquo;19:00&ldquo;)', 'Text (H:i)'],
                ['_tix_time_end_formatted', '<span class="tix-badge-bd">Breakdance</span> Formatierte End-Uhrzeit', 'Text (H:i)'],
                ['_tix_doors_formatted', '<span class="tix-badge-bd">Breakdance</span> Formatierte Einlass-Uhrzeit', 'Text (H:i)'],
                ['_tix_doors_display', '<span class="tix-badge-bd">Breakdance</span> Einlass-Anzeige (z.B. &bdquo;Einlass: 18:00 Uhr&ldquo;)', 'Text'],
                ['_tix_date_display', '<span class="tix-badge-bd">Breakdance</span> Fertige Datumsanzeige mit Uhrzeit (z.B. &bdquo;15.03.2026, 19:00 &ndash; 23:00 Uhr&ldquo;)', 'Text'],
                ['_tix_date_multiline', '<span class="tix-badge-bd">Breakdance</span> Mehrzeilige Datumsanzeige (mit &lt;br&gt; getrennt)', 'HTML'],
                ['_tix_date_only', '<span class="tix-badge-bd">Breakdance</span> Nur Datum ohne Uhrzeit (z.B. &bdquo;15.03.2026&ldquo; oder &bdquo;15.03. &ndash; 17.03.2026&ldquo;)', 'Text'],
                ['_tix_time_only', '<span class="tix-badge-bd">Breakdance</span> Nur Uhrzeit ohne Datum (z.B. &bdquo;19:00 &ndash; 23:00 Uhr&ldquo;)', 'Text'],
                ['_tix_countdown_target', '<span class="tix-badge-bd">Breakdance</span> Countdown-Ziel im ISO-Format (JJJJ-MM-TTThh:mm)', 'Text'],
                ['_tix_date_today_prefix', '<span class="tix-badge-bd">Breakdance</span> Dynamischer Prefix &bdquo;Heute&ldquo; / &bdquo;Morgen&ldquo; oder leer', 'Text'],
            ]);

            // ── Ort & Veranstalter ──
            self::meta_card('Ort &amp; Veranstalter', 'dashicons-location', [
                ['_tix_location_id', 'Location-Post-ID (Referenz auf CPT tix_location)', 'Zahl'],
                ['_tix_location', 'Name der Location / Venue', 'Text'],
                ['_tix_address', 'Volle Adresse (Stra&szlig;e, PLZ, Stadt)', 'Text'],
                ['_tix_organizer_id', 'Organizer-Post-ID (Referenz auf CPT tix_organizer)', 'Zahl'],
                ['_tix_organizer', 'Name des Veranstalters', 'Text'],
                ['_tix_location_full', '<span class="tix-badge-bd">Breakdance</span> Location + Adresse kombiniert', 'Text'],
                ['_tix_location_display', '<span class="tix-badge-bd">Breakdance</span> Adressanzeige', 'Text'],
                ['_tix_address_display', '<span class="tix-badge-bd">Breakdance</span> Adressanzeige (Kurzform)', 'Text'],
                ['_tix_organizer_display', '<span class="tix-badge-bd">Breakdance</span> Veranstalter-Name', 'Text'],
            ]);

            // ── Location & Organizer CPT ──
            self::meta_card('Location &amp; Organizer <small>(CPTs: tix_location, tix_organizer)</small>', 'dashicons-building', [
                ['_tix_loc_address', 'Adresse der Location', 'Text'],
                ['_tix_loc_description', 'Beschreibung der Location', 'Text (HTML)'],
                ['_tix_org_address', 'Adresse des Veranstalters', 'Text'],
                ['_tix_org_description', 'Beschreibung des Veranstalters', 'Text (HTML)'],
                ['_tix_org_user_id', 'Verkn&uuml;pfter WP-User (f&uuml;r Veranstalter-Dashboard Login)', 'Zahl (User-ID)'],
            ]);

            // ── Info-Sektionen ──
            self::meta_card('Info-Sektionen', 'dashicons-text-page', [
                ['_tix_info_description', 'Beschreibungs-Text', 'Text (HTML)'],
                ['_tix_info_description_label', 'Individuelles Label f&uuml;r Beschreibung', 'Text'],
                ['_tix_info_lineup', 'Lineup / K&uuml;nstler', 'Text (HTML)'],
                ['_tix_info_lineup_label', 'Individuelles Label f&uuml;r Lineup', 'Text'],
                ['_tix_info_specials', 'Specials / Besonderheiten', 'Text (HTML)'],
                ['_tix_info_specials_label', 'Individuelles Label f&uuml;r Specials', 'Text'],
                ['_tix_info_extra_info', 'Zus&auml;tzliche Informationen', 'Text (HTML)'],
                ['_tix_info_extra_info_label', 'Individuelles Label f&uuml;r Extra-Info', 'Text'],
                ['_tix_info_age_limit', 'Altersbeschr&auml;nkung (z.B. &bdquo;18&ldquo;)', 'Text'],
                ['_tix_info_age_limit_label', 'Individuelles Label f&uuml;r Altersbeschr&auml;nkung', 'Text'],
                ['_tix_excerpt', '<span class="tix-badge-bd">Breakdance</span> Event-Kurzbeschreibung (aus WP-Auszug)', 'Text'],
                ['_tix_description', '<span class="tix-badge-bd">Breakdance</span> Beschreibung (Kopie f&uuml;r Dynamic Data)', 'Text (HTML)'],
                ['_tix_description_label', '<span class="tix-badge-bd">Breakdance</span> Beschreibungs-Label', 'Text'],
                ['_tix_lineup', '<span class="tix-badge-bd">Breakdance</span> Lineup (Kopie f&uuml;r Dynamic Data)', 'Text (HTML)'],
                ['_tix_lineup_label', '<span class="tix-badge-bd">Breakdance</span> Lineup-Label', 'Text'],
                ['_tix_specials', '<span class="tix-badge-bd">Breakdance</span> Specials (Kopie f&uuml;r Dynamic Data)', 'Text (HTML)'],
                ['_tix_specials_label', '<span class="tix-badge-bd">Breakdance</span> Specials-Label', 'Text'],
                ['_tix_extra_info', '<span class="tix-badge-bd">Breakdance</span> Extra-Info (Kopie f&uuml;r Dynamic Data)', 'Text (HTML)'],
                ['_tix_extra_info_label', '<span class="tix-badge-bd">Breakdance</span> Extra-Info-Label', 'Text'],
                ['_tix_age_limit_display', '<span class="tix-badge-bd">Breakdance</span> Altersbeschr&auml;nkung formatiert (z.B. &bdquo;18+&ldquo;)', 'Text'],
            ]);

            // ── Tickets & Preise ──
            self::meta_card('Tickets &amp; Preise', 'dashicons-tickets-alt', [
                ['_tix_tickets_enabled', 'Sind Tickets f&uuml;r dieses Event aktiviert?', 'Ja/Nein (1/0)'],
                ['_tix_ticket_categories', 'Alle Ticket-Kategorien (Array mit name, price, sale_price, qty, desc, image_id, online, offline_ticket, phases[], product_id, sku)', 'Array'],
                ['_tix_ticket_count', 'Anzahl Online-Ticket-Kategorien', 'Zahl'],
                ['_tix_offline_count', 'Anzahl Offline-Ticket-Kategorien (nicht im Shop)', 'Zahl'],
                ['_tix_combo_deals', 'Konfigurierte Kombi-Ticket-Angebote', 'Array'],
                ['_tix_group_discount', 'Staffelrabatt-Stufen (z.B. ab 5 Tickets = 10%)', 'Array'],
            ]);

            // ── Breakdance Ticket-Meta (pro Kategorie) ──
            self::meta_card('Ticket-Meta pro Kategorie <small>(Breakdance Dynamic Data)</small>', 'dashicons-tickets-alt', [
                ['_tix_ticket_{N}_name', '<span class="tix-badge-bd">Breakdance</span> Name der N-ten Ticket-Kategorie', 'Text'],
                ['_tix_ticket_{N}_price', '<span class="tix-badge-bd">Breakdance</span> Regul&auml;rer Preis', 'Zahl'],
                ['_tix_ticket_{N}_price_formatted', '<span class="tix-badge-bd">Breakdance</span> Formatierter Preis (z.B. &bdquo;25,00 &euro;&ldquo;)', 'Text'],
                ['_tix_ticket_{N}_sale_price', '<span class="tix-badge-bd">Breakdance</span> Aktionspreis (bei aktiver Preisphase)', 'Zahl'],
                ['_tix_ticket_{N}_sale_price_formatted', '<span class="tix-badge-bd">Breakdance</span> Formatierter Aktionspreis', 'Text'],
                ['_tix_ticket_{N}_phase_name', '<span class="tix-badge-bd">Breakdance</span> Name der aktiven Preisphase (z.B. &bdquo;Early Bird&ldquo;)', 'Text'],
                ['_tix_ticket_{N}_phase_until', '<span class="tix-badge-bd">Breakdance</span> Ablaufdatum der aktuellen Phase', 'Datum'],
                ['_tix_ticket_{N}_qty', '<span class="tix-badge-bd">Breakdance</span> Kapazit&auml;t / Verf&uuml;gbar', 'Zahl'],
                ['_tix_ticket_{N}_desc', '<span class="tix-badge-bd">Breakdance</span> Beschreibung der Kategorie', 'Text'],
                ['_tix_ticket_{N}_product_id', '<span class="tix-badge-bd">Breakdance</span> WooCommerce-Produkt-ID', 'Zahl'],
                ['_tix_ticket_{N}_image_url', '<span class="tix-badge-bd">Breakdance</span> Produktbild-URL', 'URL'],
                ['_tix_ticket_{N}_add_to_cart_url', '<span class="tix-badge-bd">Breakdance</span> In-den-Warenkorb-URL', 'URL'],
            ]);

            // ── Offline-Ticket-Meta (pro Kategorie) ──
            self::meta_card('Offline-Ticket-Meta pro Kategorie <small>(Breakdance Dynamic Data)</small>', 'dashicons-tickets-alt', [
                ['_tix_offline_{M}_name', '<span class="tix-badge-bd">Breakdance</span> Name der M-ten Offline-Kategorie', 'Text'],
                ['_tix_offline_{M}_price', '<span class="tix-badge-bd">Breakdance</span> Preis', 'Zahl'],
                ['_tix_offline_{M}_price_formatted', '<span class="tix-badge-bd">Breakdance</span> Formatierter Preis', 'Text'],
                ['_tix_offline_{M}_sale_price', '<span class="tix-badge-bd">Breakdance</span> Aktionspreis', 'Zahl'],
                ['_tix_offline_{M}_sale_price_formatted', '<span class="tix-badge-bd">Breakdance</span> Formatierter Aktionspreis', 'Text'],
                ['_tix_offline_{M}_desc', '<span class="tix-badge-bd">Breakdance</span> Beschreibung', 'Text'],
            ]);

            // ── Vorverkauf ──
            self::meta_card('Vorverkauf', 'dashicons-clock', [
                ['_tix_presale_active', 'Ist der Vorverkauf aktiv?', 'Ja/Nein (1/0)'],
                ['_tix_presale_end', 'Enddatum des Vorverkaufs (manuell/fix)', 'Datum+Uhrzeit'],
                ['_tix_presale_end_mode', 'Modus: manual, fixed oder before_event', 'Text'],
                ['_tix_presale_end_offset', 'Stunden vor Event-Start (bei before_event)', 'Zahl'],
                ['_tix_presale_end_computed', '<span class="tix-badge-bd">Breakdance</span> Berechnetes VVK-Enddatum', 'Datum+Uhrzeit'],
            ]);

            // ── Status & Anzeige ──
            self::meta_card('Status &amp; Anzeige', 'dashicons-visibility', [
                ['_tix_event_status', 'Manueller Event-Status (available, few_tickets, sold_out, cancelled, postponed, past)', 'Text'],
                ['_tix_status', '<span class="tix-badge-bd">Breakdance</span> Berechneter Status (auto oder manuell)', 'Text'],
                ['_tix_status_label', '<span class="tix-badge-bd">Breakdance</span> Status-Label zur Anzeige (z.B. &bdquo;Ausverkauft&ldquo;)', 'Text'],
                ['_tix_is_past', '<span class="tix-badge-bd">Breakdance</span> Liegt das Event in der Vergangenheit?', 'Ja/Nein (1/0)'],
                ['_tix_is_past_label', '<span class="tix-badge-bd">Breakdance</span> Label f&uuml;r vergangene Events', 'Text'],
            ]);

            // ── Preisanzeige ──
            self::meta_card('Preisanzeige', 'dashicons-money-alt', [
                ['_tix_price_min', '<span class="tix-badge-bd">Breakdance</span> G&uuml;nstigster Ticketpreis', 'Zahl'],
                ['_tix_price_max', '<span class="tix-badge-bd">Breakdance</span> Teuerster Ticketpreis', 'Zahl'],
                ['_tix_price_min_formatted', '<span class="tix-badge-bd">Breakdance</span> Formatierter Mindestpreis inkl. MwSt.-Text (z.B. &bdquo;ab 15,00 &euro; inkl. MwSt.&ldquo;)', 'Text'],
                ['_tix_price_max_formatted', '<span class="tix-badge-bd">Breakdance</span> Formatierter H&ouml;chstpreis inkl. MwSt.-Text', 'Text'],
                ['_tix_price_range', '<span class="tix-badge-bd">Breakdance</span> Preisspanne (z.B. &bdquo;ab 15,00 &euro;&ldquo;)', 'Text'],
                ['_tix_price_range_full', '<span class="tix-badge-bd">Breakdance</span> Vollst&auml;ndige Preisspanne (z.B. &bdquo;15 &euro; &ndash; 50 &euro;&ldquo;)', 'Text'],
                ['_tix_price_label', '<span class="tix-badge-bd">Breakdance</span> Preis-Label ohne MwSt. (z.B. &bdquo;Tickets ab 15,00&euro;&ldquo;)', 'Text'],
            ]);

            // ── Verkaufszahlen ──
            self::meta_card('Verkaufszahlen', 'dashicons-chart-bar', [
                ['_tix_sold_total', '<span class="tix-badge-bd">Breakdance</span> Insgesamt verkaufte Tickets', 'Zahl'],
                ['_tix_sold_percent', '<span class="tix-badge-bd">Breakdance</span> Verkaufte Tickets in Prozent (0&ndash;100)', 'Zahl'],
                ['_tix_sold_display', '<span class="tix-badge-bd">Breakdance</span> Anzeige-Text (z.B. &bdquo;45/100 (75%)&ldquo;)', 'Text'],
                ['_tix_capacity_total', '<span class="tix-badge-bd">Breakdance</span> Gesamtkapazit&auml;t &uuml;ber alle Kategorien', 'Zahl'],
                ['_tix_product_ids', '<span class="tix-badge-bd">Breakdance</span> WooCommerce-Produkt-IDs (komma-getrennt)', 'Text'],
            ]);

            // ── Medien ──
            self::meta_card('Medien (Bilder &amp; Video)', 'dashicons-format-gallery', [
                ['_tix_gallery', 'Galerie-Attachment-IDs', 'Array'],
                ['_tix_gallery_ids', '<span class="tix-badge-bd">Breakdance</span> Komma-getrennte Bild-IDs', 'Text'],
                ['_tix_gallery_urls', '<span class="tix-badge-bd">Breakdance</span> Bild-URLs komma-getrennt (normale Gr&ouml;&szlig;e)', 'Text'],
                ['_tix_gallery_urls_large', '<span class="tix-badge-bd">Breakdance</span> Bild-URLs komma-getrennt (gro&szlig;)', 'Text'],
                ['_tix_gallery_count', '<span class="tix-badge-bd">Breakdance</span> Anzahl Galerie-Bilder', 'Zahl'],
                ['_tix_video_url', 'Video-URL', 'URL'],
                ['_tix_video_type', '<span class="tix-badge-bd">Breakdance</span> Video-Plattform (youtube, vimeo, self-hosted, external)', 'Text'],
                ['_tix_video_id', '<span class="tix-badge-bd">Breakdance</span> Extrahierte Video-ID', 'Text'],
                ['_tix_video_embed', '<span class="tix-badge-bd">Breakdance</span> Fertiger Embed-HTML-Code', 'HTML'],
            ]);

            // ── FAQ (Breakdance pro Eintrag) ──
            self::meta_card('FAQ', 'dashicons-editor-help', [
                ['_tix_faq', 'FAQ-Eintr&auml;ge (Array von {q, a})', 'Array'],
                ['_tix_faq_count', '<span class="tix-badge-bd">Breakdance</span> Anzahl FAQ-Eintr&auml;ge', 'Zahl'],
                ['_tix_faq_{N}_question', '<span class="tix-badge-bd">Breakdance</span> N-te FAQ-Frage', 'Text'],
                ['_tix_faq_{N}_answer', '<span class="tix-badge-bd">Breakdance</span> N-te FAQ-Antwort (Plaintext)', 'Text'],
                ['_tix_faq_{N}_answer_html', '<span class="tix-badge-bd">Breakdance</span> N-te FAQ-Antwort (mit wpautop)', 'HTML'],
            ]);

            // ── Gewinnspiel ──
            self::meta_card('Gewinnspiel (Raffle)', 'dashicons-tickets', [
                ['_tix_raffle_enabled', 'Gewinnspiel f&uuml;r dieses Event aktiviert', 'Ja/Nein (1/&quot;&quot;)'],
                ['_tix_raffle_title', 'Titel des Gewinnspiels', 'Text'],
                ['_tix_raffle_description', 'Beschreibung / Teilnahmebedingungen', 'HTML'],
                ['_tix_raffle_end_date', 'Teilnahmeschluss (Y-m-d H:i Format)', 'Datetime'],
                ['_tix_raffle_max_entries', 'Max. Teilnehmer (0 = unbegrenzt)', 'Zahl'],
                ['_tix_raffle_status', 'Status: open, closed, drawn', 'Text'],
                ['_tix_raffle_prizes', 'Preise (Array von {name, qty, type, cat_index})', 'Array'],
                ['_tix_raffle_winners', 'Gewinner nach Auslosung (Array von {name, email, prize_name, ...})', 'Array'],
                ['_tix_raffle_drawn_at', 'Zeitpunkt der Auslosung', 'Datetime'],
            ]);

            // ── Rabattcodes ──
            self::meta_card('Rabattcodes', 'dashicons-tag', [
                ['_tix_discount_codes', 'Rabattcodes (Array von {code, type, amount, limit, expiry, coupon_id})', 'Array'],
            ]);

            // ── Presale & Warteliste ──
            self::meta_card('Presale &amp; Warteliste', 'dashicons-clock', [
                ['_tix_presale_start', 'Vorverkaufsstart (datetime-local Format)', 'Datetime'],
                ['_tix_waitlist_enabled', 'Warteliste f&uuml;r dieses Event aktiviert', 'Ja/Nein (1/&quot;&quot;)'],
            ]);

            // ── Feedback ──
            self::meta_card('Post-Event Feedback', 'dashicons-star-filled', [
                ['_tix_feedback_avg', 'Durchschnittliche Bewertung (1.0&ndash;5.0, gecacht)', 'Zahl'],
                ['_tix_feedback_count', 'Anzahl Bewertungen (gecacht)', 'Zahl'],
            ]);

            // ── Timetable / Programm ──
            self::meta_card('Programm / Timetable', 'dashicons-calendar-alt', [
                ['_tix_stages', 'B&uuml;hnen/R&auml;ume (Array von {name, color})', 'Array'],
                ['_tix_timetable', 'Programm-Slots pro Tag (verschachteltes Array: Datum =&gt; [{time, end, stage, title, desc}])', 'Array'],
            ]);

            // ── Upsell ──
            self::meta_card('Zusatzprodukte', 'dashicons-megaphone', [
                ['_tix_upsell_events', '&Auml;hnliche Events (IDs) f&uuml;r Zusatzprodukte', 'Array von IDs'],
                ['_tix_upsell_disabled', 'Zusatzprodukte f&uuml;r dieses Event deaktiviert?', 'Ja/Nein (1/0)'],
            ]);

            // ── Serientermine ──
            self::meta_card('Serientermine &ndash; Master-Events', 'dashicons-backup', [
                ['_tix_series_enabled', 'Dieses Event ist ein Serien-Master', 'Ja/Nein (1/0)'],
                ['_tix_series_mode', 'Serien-Modus: periodic oder manual', 'Text'],
                ['_tix_series_pattern', 'Wiederholungsmuster (frequency, days, week_of, day_of, day_num, end_mode, end_date, end_count)', 'Array'],
                ['_tix_series_manual_dates', 'Manuelle Termine (Array von {date_start, date_end})', 'Array'],
                ['_tix_series_children', 'Kind-Event Post-IDs (nach Datum sortiert)', 'Array von IDs'],
            ]);

            self::meta_card('Serientermine &ndash; Kind-Events', 'dashicons-backup', [
                ['_tix_series_parent', 'Master-Event Post-ID', 'Zahl'],
                ['_tix_series_index', 'Reihenfolge-Index in der Serie (0-basiert)', 'Zahl'],
                ['_tix_series_detached', 'Von Master-Updates getrennt (nicht mehr synchronisiert)', 'Ja/Nein (1/0)'],
                ['_tix_series_badge', '<span class="tix-badge-bd">Breakdance</span> Badge-Text &bdquo;Serientermin&ldquo; oder leer', 'Text'],
                ['_tix_series_next_url', '<span class="tix-badge-bd">Breakdance</span> URL zum n&auml;chsten Termin in der Serie', 'URL'],
                ['_tix_series_count', '<span class="tix-badge-bd">Breakdance</span> Gesamtzahl Termine in der Serie', 'Zahl'],
            ]);

            // ── Weitere Features ──
            self::meta_card('Erweiterte Event-Features', 'dashicons-admin-generic', [
                ['_tix_guest_list_enabled', 'G&auml;steliste aktiviert?', 'Ja/Nein (1/0)'],
                ['_tix_guest_list', 'G&auml;ste-Eintr&auml;ge (Array: name, email, checkin, checked_in_at, qr_code)', 'Array'],
                ['_tix_checkin_password', 'Passwort f&uuml;r den Check-in-Zugang', 'Text'],
                ['_tix_express_checkout', 'Express-Checkout f&uuml;r dieses Event aktiviert?', 'Ja/Nein (1/0)'],
                ['_tix_abandoned_cart', 'Warenkorb-Recovery f&uuml;r dieses Event aktiviert?', 'Ja/Nein (1/0)'],
                ['_tix_ticket_transfer', 'Ticket-Umschreibung f&uuml;r dieses Event aktiviert?', 'Ja/Nein (1/0)'],
                ['_tix_embed_enabled', 'Embed/Einbettung aktiviert?', 'Ja/Nein (1/0)'],
                ['_tix_embed_domains', 'Erlaubte Domains f&uuml;r Einbettung', 'Text (komma-getrennt)'],
            ]);

            // ── Charity / Soziales Projekt ──
            self::meta_card('Soziales Projekt (Charity)', 'dashicons-heart', [
                ['_tix_charity_enabled', 'Soziales Projekt f&uuml;r dieses Event aktiviert?', 'Ja/Nein (1/0)'],
                ['_tix_charity_name', 'Name des unterst&uuml;tzten Projekts', 'Text'],
                ['_tix_charity_percent', 'Anteil des Warenkorbs in Prozent', 'Zahl (1-100)'],
                ['_tix_charity_desc', 'Kurzbeschreibung des Projekts', 'Text'],
                ['_tix_charity_image', 'Attachment-ID des Projekt-Logos', 'Zahl'],
            ]);

            // ── Support-System ──
            self::meta_card('Support-System (tix_support_ticket)', 'dashicons-format-chat', [
                ['_tix_sp_email', 'Kunden-E-Mail (Pflicht)', 'E-Mail'],
                ['_tix_sp_name', 'Kundenname', 'Text'],
                ['_tix_sp_order_id', 'Verkn&uuml;pfte WooCommerce-Bestellnummer', 'Zahl'],
                ['_tix_sp_ticket_code', 'Verkn&uuml;pfter Ticket-Code', 'Text (12-stellig alphanumerisch)'],
                ['_tix_sp_category', 'Kategorie-Slug', 'Text'],
                ['_tix_sp_priority', 'Priorit&auml;t', 'Text (normal|high|urgent)'],
                ['_tix_sp_assignee', 'Zugewiesener Bearbeiter (User-ID)', 'Zahl'],
                ['_tix_sp_access_key', 'Zuf&auml;lliger Key f&uuml;r Gast-Zugriff', 'Text (32 Zeichen)'],
                ['_tix_sp_last_reply', 'Zeitpunkt der letzten Nachricht', 'ISO-Timestamp'],
                ['_tix_sp_messages', 'Nachrichten-Verlauf (JSON-Array)', 'JSON'],
            ]);

            // ── Sync & API ──
            self::meta_card('Synchronisierung &amp; API', 'dashicons-update', [
                ['_tix_last_sync_time', 'Zeitpunkt der letzten Synchronisierung', 'Zeitstempel'],
                ['_tix_last_sync_log', 'Protokoll der letzten Synchronisierung', 'Array'],
                ['_tix_synced_at', 'Sync-Zeitstempel', 'Zeitstempel'],
                ['_tix_product_ids', 'WooCommerce-Produkt-IDs (komma-getrennt)', 'Text'],
            ]);

            // ── Kalender ──
            self::meta_card('Kalender-Integration', 'dashicons-calendar', [
                ['_tix_calendar_ics_url', '<span class="tix-badge-bd">Breakdance</span> URL zur ICS-Datei (Apple/Outlook)', 'URL'],
                ['_tix_calendar_google_url', '<span class="tix-badge-bd">Breakdance</span> URL zum Hinzuf&uuml;gen in Google Calendar', 'URL'],
            ]);

            // ── WC Product Meta ──
            self::meta_card('WooCommerce-Produkt-Meta <small>(auf von EH erstellten Produkten)</small>', 'dashicons-cart', [
                ['_tix_parent_event_id', 'Referenz zum Eltern-Tixomat-Event', 'Zahl'],
                ['_tix_category_index', 'Index der Ticket-Kategorie im Event', 'Zahl'],
                ['_available_checkins_per_ticket', 'Erlaubte Check-ins pro Ticket', 'Zahl'],
            ]);

            // ── Newsletter-Abonnenten ──
            self::meta_card('Newsletter-Abonnenten <small>(CPT: tix_subscriber)</small>', 'dashicons-email', [
                ['_tix_sub_type', 'Kontakt-Typ: E-Mail oder WhatsApp', 'Text (email/whatsapp)'],
                ['_tix_sub_contact', 'E-Mail-Adresse oder Telefonnummer', 'Text'],
                ['_tix_sub_name', 'Vor- und Nachname', 'Text'],
                ['_tix_sub_address', 'Rechnungsadresse (Stra&szlig;e, PLZ, Stadt, Land)', 'Text'],
                ['_tix_sub_order', 'Zugeh&ouml;rige WooCommerce-Bestell-ID', 'Zahl'],
                ['_tix_sub_purchases', 'Alle K&auml;ufe des Abonnenten (Array: order_id, items, total)', 'Array'],
                ['_tix_sub_date', 'Anmeldedatum', 'Datum (ISO 8601)'],
            ]);

            // ── Verlassene Warenkörbe ──
            self::meta_card('Verlassene Warenk&ouml;rbe <small>(CPT: tix_abandoned_cart)</small>', 'dashicons-cart', [
                ['_tix_ac_status', 'Status: pending, sent, recovered, expired', 'Text'],
                ['_tix_ac_email', 'E-Mail-Adresse des Kunden', 'E-Mail'],
                ['_tix_ac_event_id', 'Zugeh&ouml;riges Event', 'Zahl (Event-ID)'],
                ['_tix_ac_token', 'Einzigartiger Wiederherstellungs-Token', 'Text (32 Zeichen)'],
                ['_tix_ac_session_id', 'WooCommerce-Session-ID', 'Text'],
                ['_tix_ac_cart_data', 'Gespeicherte Warenkorb-Daten', 'Serialisiert'],
                ['_tix_ac_wc_cart', 'WooCommerce-Warenkorb-Backup', 'Serialisiert'],
                ['_tix_ac_sent_at', 'Zeitpunkt der E-Mail-Versendung', 'Datum'],
            ]);

            // ── Barcode ──
            self::meta_card('Strichcode (Barcode)', 'dashicons-editor-code', [
                ['_tix_barcode', 'Barcode f&uuml;r Event aktiviert (0/1)', 'Ja/Nein (1/0)'],
            ]);

            // ── Tickets (CPT) ──
            self::meta_card('Tickets <small>(CPT: tix_ticket)</small>', 'dashicons-tickets-alt', [
                ['_tix_ticket_code', 'Eindeutiger 12-stelliger alphanumerischer Ticket-Code', 'Text (12 Zeichen)'],
                ['_tix_ticket_event_id', 'Zugeh&ouml;rige Event-ID', 'Zahl'],
                ['_tix_ticket_order_id', 'Zugeh&ouml;rige WooCommerce-Bestellnummer', 'Zahl'],
                ['_tix_ticket_product_id', 'WooCommerce-Produkt-ID der Ticket-Kategorie', 'Zahl'],
                ['_tix_ticket_cat_index', 'Index der Ticket-Kategorie im Event', 'Zahl'],
                ['_tix_ticket_status', 'Status des Tickets', 'Text (valid|used|cancelled)'],
                ['_tix_ticket_owner_name', 'Name des Ticket-Inhabers', 'Text'],
                ['_tix_ticket_owner_email', 'E-Mail des Ticket-Inhabers', 'E-Mail'],
                ['_tix_ticket_download_token', 'Kryptischer 64-Zeichen-Hex-Token f&uuml;r Download-URL', 'Text (64 Zeichen)'],
                ['_tix_ticket_seat_id', 'Reservierter Sitzplatz (bei Saalplan)', 'Text'],
                ['_tix_ticket_checked_in', 'Wurde das Ticket eingecheckt?', 'Ja/Nein (1/0)'],
                ['_tix_ticket_checkin_time', 'Zeitpunkt des Check-ins', 'ISO-Timestamp'],
                ['_tix_ticket_checkin_by', 'Check-in durchgef&uuml;hrt von (Methode/User)', 'Text'],
            ]);

            // ── Ticket-Vorlagen (CPT) ──
            self::meta_card('Ticket-Vorlagen <small>(CPT: tix_ticket_tpl)</small>', 'dashicons-media-text', [
                ['_tix_template_config', 'Vollst&auml;ndige Template-Konfiguration (Hintergrundbild, Felder, Positionen, Stile)', 'JSON'],
            ]);

            // ── Event-Meta: Ticket-Vorlage ──
            self::meta_card('Ticket-Vorlage <small>(Event-Meta)</small>', 'dashicons-media-text', [
                ['_tix_ticket_template_mode', 'Template-Modus: global, template, custom oder none', 'Text'],
                ['_tix_ticket_template_id', 'ID der gew&auml;hlten Ticket-Vorlage (bei Modus &bdquo;template&ldquo;)', 'Zahl (CPT-ID)'],
                ['_tix_ticket_template', 'Inline-Template-Konfiguration (bei Modus &bdquo;custom&ldquo;)', 'JSON'],
            ]);

            // ── Saalplan ──
            self::meta_card('Saalplan (Seat Map)', 'dashicons-layout', [
                ['_tix_seatmap_id', 'Saalplan-ID f&uuml;r dieses Event (0 = kein Saalplan)', 'Number (Post-ID)'],
                ['_tix_seatmap_mode', 'Platzvergabe-Modus: <code>manual</code> (Kunde w&auml;hlt) oder <code>best</code> (automatisch)', 'Text (manual/best)'],
            ]);

            // ── KI-Schutz (Content Guard) ──
            self::meta_card('KI-Schutz (Content Guard)', 'dashicons-shield', [
                ['_tix_ai_approved', 'Event wurde von KI-Pr&uuml;fung genehmigt', 'Ja/Nein (1/0)'],
                ['_tix_ai_flagged', 'Event wurde von KI-Pr&uuml;fung abgelehnt', 'Ja/Nein (1/0)'],
                ['_tix_ai_flag_reason', 'Begr&uuml;ndung der Ablehnung (deutsch)', 'Text'],
                ['_tix_ai_content_hash', 'MD5-Hash des zuletzt gepr&uuml;ften Contents (Cache)', 'Text (md5)'],
                ['_tix_ai_checked_at', 'Zeitstempel der letzten KI-Pr&uuml;fung', 'Zeitstempel (Unix)'],
            ]);
            ?>

        </div>
        <?php
    }

    private static function meta_card($title, $icon, $rows) {
        ?>
        <div class="tix-card">
            <div class="tix-card-header">
                <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                <h3><?php echo $title; ?></h3>
            </div>
            <div class="tix-card-body" style="padding:0;">
                <table class="tix-tbl" style="width:100%;margin:0;">
                    <thead>
                        <tr>
                            <th style="width:260px;">Meta-Key</th>
                            <th>Beschreibung</th>
                            <th style="width:160px;">Typ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                        <tr class="tix-row">
                            <td><code style="font-size:11px;"><?php echo esc_html($row[0]); ?></code></td>
                            <td><?php echo $row[1]; ?></td>
                            <td><span style="font-size:12px;color:#64748b;"><?php echo $row[2]; ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // ══════════════════════════════════════
    // TAB 2: SHORTCODES
    // ══════════════════════════════════════


    private static function render_shortcodes_tab() {
        global $shortcode_tags;
        $registry = self::shortcodes_registry();
        $tix_tags = array_filter(array_keys($shortcode_tags ?: []), function($t){
            return strpos($t, 'tix_') === 0 || strpos($t, 'tixomat') === 0;
        });
        sort($tix_tags);
        $total      = count($tix_tags);
        $documented = count(array_intersect($tix_tags, array_keys($registry)));
        ?>
        <div class="tix-pane" data-pane="shortcodes">

            <p class="description">
                <strong>Shortcodes</strong> werden automatisch aus <code>$shortcode_tags</code> erkannt — diese Liste ist also immer aktuell und zeigt alles, was dein aktuelles Plugin registriert hat.
                Im Editor einfach als Text-Block einfügen.
            </p>

            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:13px;color:#075985;">
                <strong><?php echo $total; ?> aktive Shortcodes</strong> registriert — davon <?php echo $documented; ?> mit Beschreibung, <?php echo $total - $documented; ?> ohne (Auto-Detect-Platzhalter).
            </div>

            <?php foreach ($tix_tags as $tag):
                $d = $registry[$tag] ?? null;
                if ($d) {
                    self::shortcode_card($tag, $d['desc'], $d['params'] ?? [], $d['examples'][0] ?? "[{$tag}]", $d['examples'][1] ?? '');
                } else {
                    self::shortcode_card($tag,
                        '<em style="color:#94a3b8;">Auto-erkannt aus <code>add_shortcode()</code>. Noch keine Beschreibung hinterlegt — kann in <code>TIX_Docs::shortcodes_registry()</code> ergänzt werden.</em>',
                        [], "[{$tag}]", '');
                }
            endforeach; ?>

        </div>
        <?php
    }

    /**
     * Registry aller bekannten Shortcodes mit Beschreibung + Parametern.
     * Wird mit $shortcode_tags abgeglichen — fehlende Einträge fallen auf
     * Auto-Detect-Platzhalter zurück.
     */
    public static function shortcodes_registry() {
        return [
            'tix_event_page' => [
                'desc' => 'Komplette Event-Detailseite (Hero, Datum, Ort, Ticket-Selektor, Beschreibung, Lineup, Galerie, Video, FAQ, Map, Serien). Zeigt nur Sektionen mit Daten. Bettet <code>[tix_ticket_selector]</code>, <code>[tix_faq]</code>, <code>[tix_calendar]</code>, <code>[tix_upsell]</code> ein.',
                'params' => [['id', '(aktuelle Seite)', 'Event-ID, optional.']],
                'examples' => ['[tix_event_page]', '[tix_event_page id="123"]'],
            ],
            'tix_ticket_selector' => [
                'desc' => 'Ticketauswahl mit Kategorien, Preisen, Mengen, Bundles, Gruppenrabatt, Phasen-Preisen (Early-Bird), Kaufen-Button.',
                'params' => [['id', '(aktuelle Seite)', 'Event-ID.']],
                'examples' => ['[tix_ticket_selector]', '[tix_ticket_selector id="123"]'],
            ],
            'tix_countdown' => [
                'desc' => 'Live-Countdown bis zum Event-Start. Versteckt sich wenn Event vorbei ist.',
                'params' => [
                    ['id', '(auto)', 'Event-ID.'],
                    ['label', '(leer)', 'Text über dem Countdown.'],
                ],
                'examples' => ['[tix_countdown]', '[tix_countdown id="123" label="Countdown läuft!"]'],
            ],
            'tix_checkout' => [
                'desc' => 'Kompletter Checkout-Flow (Rechnungsdaten, Warenkorb, Zahlung). Ersetzt die WC-Kasse mit Tixomat-Design. Mehrstufig, mit Countdown + Newsletter-Opt-In.',
                'params' => [],
                'examples' => ['[tix_checkout]'],
            ],
            'tix_native_cart' => [
                'desc' => 'Eigenständiger Warenkorb ohne WooCommerce. Zeigt Items, Gesamtsumme, Gutschein-Feld und "Weiter zur Kasse".',
                'params' => [],
                'examples' => ['[tix_native_cart]'],
            ],
            'tix_events' => [
                'desc' => 'Event-Kartenraster mit Filter. Kann nach Veranstalter, Location, Datum, Kategorie einschränken.',
                'params' => [
                    ['count', '12', 'Anzahl Events.'],
                    ['organizer', '', 'Organizer-ID (filtert nur auf dessen Events).'],
                    ['location', '', 'Location-ID.'],
                    ['category', '', 'Kategorie-Slug.'],
                    ['show_past', 'false', '"true" = auch vergangene Events.'],
                ],
                'examples' => ['[tix_events count="6"]', '[tix_events organizer="128" count="3"]'],
            ],
            'tix_homepage' => [
                'desc' => 'Komplette Tixomat-Homepage mit Sektionen (Hero, Empfehlungen, kommende Events, Verlauf, etc.). Konfigurierbar via Homepage-Baukasten in den Einstellungen.',
                'params' => [],
                'examples' => ['[tix_homepage]'],
            ],
            'tix_calendar' => [
                'desc' => 'Monats-Kalender-Ansicht eines Events (Serien-Termine) oder aller kommenden Events.',
                'params' => [['id', '(auto)', 'Event-ID für Serien, leer für alle.']],
                'examples' => ['[tix_calendar]', '[tix_calendar id="123"]'],
            ],
            'tix_search' => [
                'desc' => 'Such-Feld mit Live-Vorschlägen. Durchsucht Events, Locations, Organizer nach Volltext. Ergebnisse auf <code>/events/?s=...</code>.',
                'params' => [
                    ['placeholder', 'Suche...', 'Platzhaltertext.'],
                    ['position', '', 'Positionierung (z.B. "header").'],
                ],
                'examples' => ['[tix_search]', '[tix_search placeholder="Event oder Ort..."]'],
            ],
            'tix_faq' => [
                'desc' => 'FAQ-Liste mit Accordion. Pro Event oder global.',
                'params' => [['id', '(auto)', 'Event-ID.']],
                'examples' => ['[tix_faq]', '[tix_faq id="123"]'],
            ],
            'tix_upsell' => [
                'desc' => 'Upsell-Produkte im Checkout (Getränke-Pauschale, VIP, Merch). Wird automatisch von <code>[tix_checkout]</code> eingebettet.',
                'params' => [['id', '(auto)', 'Event-ID.']],
                'examples' => ['[tix_upsell]'],
            ],
            'tix_seatmap' => [
                'desc' => 'Interaktiver Saalplan zur Sitzplatz-Auswahl.',
                'params' => [
                    ['id', '(auto)', 'Event-ID.'],
                    ['seatmap', '', 'Seatmap-ID falls abweichend.'],
                ],
                'examples' => ['[tix_seatmap]', '[tix_seatmap seatmap="42"]'],
            ],
            'tix_raffle' => [
                'desc' => 'Verlosung/Gewinnspiel-Block. Teilnehmer-Formular mit Datenschutz-Opt-In.',
                'params' => [['id', '', 'Raffle-ID.']],
                'examples' => ['[tix_raffle id="7"]'],
            ],
            'tix_checkin' => [
                'desc' => 'Tür-Einlass-App (QR-Scanner, Gästelisten-Suche). Zugriff via Event-Passwort.',
                'params' => [['id', '(auto)', 'Event-ID.']],
                'examples' => ['[tix_checkin]'],
            ],
            'tix_pos' => [
                'desc' => 'Point-of-Sale: direkter Ticketverkauf an der Abendkasse (für Mitarbeiter). Barzahlung + sofortiger Ticket-Druck.',
                'params' => [],
                'examples' => ['[tix_pos]'],
            ],
            'tix_account'        => ['desc' => 'Kunden-Account-Bereich (Meine Daten, Tickets, Bestellungen).', 'params' => [], 'examples' => ['[tix_account]']],
            'tix_my_tickets'     => ['desc' => 'Liste aller Tickets des eingeloggten Users mit PDF-Download.', 'params' => [], 'examples' => ['[tix_my_tickets]']],
            'tix_order_history'  => ['desc' => 'Bestellhistorie des eingeloggten Users.', 'params' => [], 'examples' => ['[tix_order_history]']],
            'tix_ticket_transfer' => ['desc' => 'Formular zum Übertragen eines Tickets an andere Person (Umschreibung).', 'params' => [['code', '', 'Ticket-Code.']], 'examples' => ['[tix_ticket_transfer]']],
            'tix_timetable'      => ['desc' => 'Tages-/Stunden-Timetable eines Events (mehrere Acts/Slots).', 'params' => [['id', '(auto)', 'Event-ID.']], 'examples' => ['[tix_timetable]']],
            'tix_support'        => ['desc' => 'Kunden-Support-Interface (Anfrage stellen, Status ansehen).', 'params' => [], 'examples' => ['[tix_support]']],
            'tix_feedback'       => ['desc' => 'Post-Event-Feedback-Formular mit Sterne-Rating + freiem Text.', 'params' => [['id', '', 'Event-ID.']], 'examples' => ['[tix_feedback id="123"]']],
            'tix_share'          => ['desc' => 'Share-Buttons (WhatsApp, Facebook, E-Mail, Copy-Link) für aktuelle Event-Seite.', 'params' => [], 'examples' => ['[tix_share]']],
            'tix_cta_button'     => ['desc' => 'Call-to-Action-Button mit Tixomat-Styling. Nutzt konfigurierte Primärfarbe.', 'params' => [['text', '', 'Button-Text.'], ['url', '', 'Ziel-URL.']], 'examples' => ['[tix_cta_button text="Jetzt Tickets!" url="/events/xyz/"]']],
            'tix_table_reservation' => ['desc' => 'Tischreservierungs-Modul (zu Event, mit Personen-Anzahl + Zusatzwünschen).', 'params' => [['id', '(auto)', 'Event-ID.']], 'examples' => ['[tix_table_reservation]']],
            'tix_table_button'   => ['desc' => 'Button der ein Modal mit Tischreservierung öffnet.', 'params' => [['id', '(auto)', 'Event-ID.'], ['text', 'Tisch reservieren', 'Button-Text.']], 'examples' => ['[tix_table_button]']],
            'tix_ticket_modal'   => ['desc' => 'Ticketauswahl als Modal-Popup (lazy-loaded). Ideal für Homepage oder Side-Widgets.', 'params' => [['id', '', 'Event-ID.'], ['text', 'Tickets kaufen', 'Trigger-Text.']], 'examples' => ['[tix_ticket_modal id="123"]']],
            'tix_express_checkout' => ['desc' => 'Ein-Klick-Checkout-Button für User mit gespeicherten Daten. Bypass des regulären Formulars.', 'params' => [['id', '', 'Event-ID.']], 'examples' => ['[tix_express_checkout]']],
            'tix_register_event' => ['desc' => 'Öffentliches Event-Registrierungs-Formular (Veranstalter-Self-Service zum Event-Einreichen).', 'params' => [], 'examples' => ['[tix_register_event]']],
            'tix_promoter_dashboard' => ['desc' => 'Promoter-Dashboard (eigene Statistik, Payout, Verkäufe via Affiliate-Link).', 'params' => [], 'examples' => ['[tix_promoter_dashboard]']],
            'tix_promoter_signup'    => ['desc' => 'Anmeldeformular neuer Promoter. Legt User + Promoter-Profil an.', 'params' => [], 'examples' => ['[tix_promoter_signup]']],
            'tix_organizer_dashboard' => ['desc' => 'Frontend-Variante des Organizer-Dashboards (Statistik, Einnahmen, Gäste).', 'params' => [], 'examples' => ['[tix_organizer_dashboard]']],
            'tix_cart' => ['desc' => 'WooCommerce-kompatible Warenkorb-Variante (Legacy). Für native Installationen lieber <code>[tix_native_cart]</code> verwenden.', 'params' => [], 'examples' => ['[tix_cart]']],
        ];
    }

    private static function shortcode_card($tag, $description, $params, $example_simple, $example_full) {
        ?>
        <div class="tix-card">
            <div class="tix-card-header">
                <span class="dashicons dashicons-shortcode"></span>
                <h3><code style="font-size:13px;background:rgba(37,99,235,0.08);padding:2px 8px;border-radius:4px;">[<?php echo esc_html($tag); ?>]</code></h3>
            </div>
            <div class="tix-card-body">
                <p style="margin:0 0 14px;color:#475569;line-height:1.6;"><?php echo $description; ?></p>

                <?php if (!empty($params)): ?>
                <table class="tix-tbl" style="width:100%;margin-bottom:14px;">
                    <thead>
                        <tr>
                            <th style="width:120px;">Parameter</th>
                            <th style="width:140px;">Standard</th>
                            <th>Beschreibung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($params as $p): ?>
                        <tr class="tix-row">
                            <td><code style="font-size:11px;"><?php echo esc_html($p[0]); ?></code></td>
                            <td><span style="font-size:12px;color:#64748b;"><?php echo $p[1]; ?></span></td>
                            <td><?php echo $p[2]; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php elseif (empty($params)): ?>
                <p style="font-size:13px;color:#94a3b8;margin:0 0 14px;">Dieser Shortcode hat keine Parameter.</p>
                <?php endif; ?>

                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;">
                    <strong style="font-size:11px;text-transform:uppercase;letter-spacing:0.04em;color:#64748b;">Beispiel</strong><br>
                    <code style="font-size:13px;color:#1e293b;"><?php echo esc_html($example_simple); ?></code>
                    <?php if ($example_full): ?>
                    <br><code style="font-size:13px;color:#1e293b;"><?php echo esc_html($example_full); ?></code>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    // ══════════════════════════════════════
    // TAB 3: FUNKTIONEN
    // ══════════════════════════════════════

    private static function render_functions_tab() {
        ?>
        <div class="tix-pane" data-pane="functions">

            <p class="description">
                <strong>&Uuml;bersicht aller automatischen Funktionen</strong>, die Tixomat im Hintergrund f&uuml;r dich &uuml;bernimmt.
                Du musst nichts davon manuell einrichten &ndash; alles passiert automatisch, sobald du ein Event erstellst oder bearbeitest.
            </p>

            <?php
            // ── Wizard ──
            self::function_card('5-Schritte-Wizard (Einfacher Modus)', 'dashicons-welcome-learn-more', [
                'Neue Events werden im <strong>Einfachen Modus</strong> (Wizard) erstellt: 5 Schritte f&uuml;hren durch alle wichtigen Eingaben.',
                '<strong>Schritt 1 &ndash; Basis:</strong> Titel, Datum, Uhrzeit, Einlass, Location, Veranstalter.',
                '<strong>Schritt 2 &ndash; Tickets:</strong> Ticket-Kategorien mit Preis, Kapazit&auml;t, Phasen, Beschreibung und Bild.',
                '<strong>Schritt 3 &ndash; Infos:</strong> Beschreibung, Lineup, Specials, Extra-Info, Altersbeschr&auml;nkung.',
                '<strong>Schritt 4 &ndash; Medien:</strong> Beitragsbild, Galerie, Video-URL.',
                '<strong>Schritt 5 &ndash; Zusammenfassung:</strong> &Uuml;bersicht aller Eingaben mit Tipp f&uuml;r Serientermine.',
                'Der Wizard kann jederzeit in den <strong>Experten-Modus</strong> (Tab-Ansicht) umgeschaltet werden.',
            ]);

            // ── Synchronisierung ──
            self::function_card('Automatische Synchronisierung', 'dashicons-update', [
                'Wenn du ein Event speicherst, erstellt das Plugin automatisch die passenden <strong>WooCommerce-Produkte</strong>.',
                'F&uuml;r jede Ticket-Kategorie (z.B. &bdquo;Stehplatz&ldquo;, &bdquo;VIP&ldquo;) wird ein eigenes WooCommerce-Produkt mit Preis und Lagerbestand angelegt.',
                '&Auml;nderst du Preise, Kapazit&auml;ten oder Kategorien, werden die zugeh&ouml;rigen Produkte automatisch aktualisiert.',
                'Gel&ouml;schte Kategorien entfernen auch die zugeh&ouml;rigen Produkte.',
                'Beim Sync werden automatisch &uuml;ber <strong>80 Breakdance-Meta-Felder</strong> generiert (Formatierte Daten, Preise, Ticket-Details, FAQ usw.).',
                '<strong>Serien-Master-Events</strong> werden NICHT synchronisiert &ndash; nur Kind-Events erhalten eigene Produkte.',
            ]);

            // ── Serientermine ──
            self::function_card('Serientermine (Recurring Events)', 'dashicons-backup', [
                'F&uuml;r wiederkehrende Veranstaltungen gibt es den Tab <strong>&bdquo;Serientermine&ldquo;</strong> im Experten-Modus.',
                'Ein <strong>Master-Event</strong> speichert das Serienmuster und dient als Vorlage. Das System generiert <strong>Kind-Event-Posts</strong> f&uuml;r jeden Termin.',
                '<strong>Periodischer Modus:</strong> W&ouml;chentlich, Alle 2 Wochen, Monatlich (z.B. &bdquo;jeden 1. Samstag&ldquo;), Am X. des Monats.',
                '<strong>Manueller Modus:</strong> Beliebige Daten per Datumseingabe.',
                'Jedes Kind ist ein vollwertiges Event mit <strong>eigenen WC-Produkten und Stock</strong>.',
                'Bei &Auml;nderungen am Master werden alle nicht-abgetrennten Kinder automatisch aktualisiert (Titel, Location, Tickets, Info, FAQ, Medien).',
                'Kinder mit Verk&auml;ufen werden <strong>nie gel&ouml;scht</strong> &ndash; nur vom Master getrennt.',
                'In der Admin-Liste werden Serien-Kinder standardm&auml;&szlig;ig <strong>ausgeblendet</strong>. Filter: Alle / Einzeltermine / Master / Serientermine.',
                'Sicherheits-Cap: Maximal <strong>365 Termine</strong> pro Serie.',
            ]);

            // ── Dynamische Preise ──
            self::function_card('Dynamische Preisgestaltung', 'dashicons-money-alt', [
                '<strong>Preis-Phasen:</strong> Pro Ticket-Kategorie k&ouml;nnen mehrere Preisstufen definiert werden (z.B. Early Bird, Regular, Last Minute). Der Preis wechselt automatisch zum eingestellten Datum.',
                'Die aktive Phase wird &uuml;ber WooCommerce-Preisfilter in Echtzeit angewendet &ndash; der Kunde sieht immer den aktuellen Preis.',
                '<strong>Gruppenrabatt:</strong> Staffelrabatte f&uuml;r gr&ouml;&szlig;ere Bestellungen (z.B. ab 5 Tickets = 10% Rabatt, ab 10 Tickets = 20%).',
                '<strong>Kombi-Deals:</strong> Rabatt wenn Tickets f&uuml;r mehrere Events zusammen gekauft werden.',
                'Phasennamen und Ablaufdaten werden als Breakdance-Meta gespeichert (<code>_tix_ticket_{N}_phase_name</code>, <code>_tix_ticket_{N}_phase_until</code>).',
            ]);

            // ── Vorverkauf ──
            self::function_card('Vorverkauf', 'dashicons-clock', [
                'Der Vorverkauf kann pro Event aktiviert und mit einem Enddatum versehen werden.',
                'Das Enddatum kann <strong>manuell</strong> gesetzt, <strong>fix</strong> gew&auml;hlt oder <strong>automatisch</strong> X Stunden vor dem Event berechnet werden.',
                'Alle 10 Minuten pr&uuml;ft das Plugin (Cron: <code>tix_presale_check</code>), ob ein Vorverkauf abgelaufen ist, und deaktiviert ihn automatisch.',
                'Gleichzeitig werden <strong>vergangene Events</strong> und <strong>Preisphasen-Wechsel</strong> gepr&uuml;ft.',
                'Abgelaufene Vorverkaufs-Events zeigen eine &bdquo;Vorverkauf beendet&ldquo;-Meldung statt der Ticketauswahl.',
            ]);

            // ── E-Mail-System ──
            self::function_card('E-Mail-System', 'dashicons-email-alt', [
                '<strong>Bestellbest&auml;tigung:</strong> Nach dem Kauf erh&auml;lt der Kunde eine E-Mail mit allen Ticket-Details, QR-Codes und Event-Informationen.',
                '<strong>Erinnerung:</strong> Vor dem Event wird automatisch eine Erinnerungs-E-Mail an alle Ticketk&auml;ufer gesendet (Zeitpunkt konfigurierbar).',
                '<strong>Follow-up:</strong> Nach dem Event wird eine Dankes-E-Mail versendet (z.B. mit Feedback-Link, Zeitpunkt konfigurierbar).',
                '<strong>Warenkorb-Recovery:</strong> Wenn ein Kunde den Checkout verl&auml;sst, ohne zu kaufen, erh&auml;lt er nach einer einstellbaren Verz&ouml;gerung eine Erinnerungs-E-Mail.',
                '<strong>Ticket-Transfer:</strong> Der neue Ticket-Inhaber erh&auml;lt automatisch eine Benachrichtigung.',
                'Alle E-Mails nutzen das Tixomat-Design mit konfigurierbarem Logo, Markenname und Footer-Text.',
            ]);

            // ── Ticket-System ──
            self::function_card('Ticket-System (PDF-Tickets)', 'dashicons-tickets-alt', [
                'Pro verkauftem Ticket wird automatisch ein <strong>eigener CPT-Eintrag</strong> (<code>tix_ticket</code>) erstellt.',
                'Jedes Ticket erh&auml;lt einen <strong>12-stelligen alphanumerischen Code</strong> (kryptographisch sicher, ca. 1,7 &times; 10<sup>18</sup> m&ouml;gliche Kombinationen).',
                '<strong>PDF-Generierung</strong> per GD-Bibliothek: Hintergrundbild + positionierbare Text-, QR-Code- und Barcode-Felder.',
                '<strong>QR-Code</strong> und <strong>Strichcode</strong> werden direkt in PHP gerendert (keine externen Services).',
                '<strong>Download-URLs</strong> verwenden kryptische 64-Zeichen-Hex-Tokens (<code>?tix_dl=TOKEN</code>) &ndash; alte URLs bleiben r&uuml;ckw&auml;rtskompatibel.',
                '<strong>Template-Priorisierung:</strong> Ticket-Vorlage (CPT) &gt; Eigene Vorlage (Inline) &gt; Globale Einstellungen.',
                '<strong>Proportionale QR/Barcode-Gr&ouml;&szlig;en&auml;nderung</strong> im Template-Editor &ndash; Seitenverh&auml;ltnis bleibt erhalten.',
                'Tickets k&ouml;nnen per Admin-Aktion <strong>erneut versendet</strong> oder <strong>storniert</strong> werden.',
            ]);

            // ── Check-in ──
            self::function_card('Check-in-System', 'dashicons-smartphone', [
                'Jeder Ticketk&auml;ufer erh&auml;lt einen <strong>QR-Code</strong> per E-Mail und in &bdquo;Meine Tickets&ldquo;.',
                'Am Einlass kann der QR-Code mit dem <strong>eingebauten Kamera-Scanner</strong> (Shortcode <code>[tix_checkin]</code>) gescannt werden.',
                'Alternativ ist eine <strong>manuelle Code-Eingabe</strong> oder <strong>Namenssuche</strong> in der G&auml;steliste m&ouml;glich.',
                'Der Check-in ist <strong>passwortgesch&uuml;tzt</strong> &ndash; das Passwort wird pro Event im Editor eingestellt.',
                'Es wird live angezeigt, wie viele G&auml;ste bereits eingecheckt sind.',
                '<strong>Teilweises Einchecken:</strong> Bei Gruppen (z.B. +4 Begleitung) kann die Anzahl eingecheckter Personen per &minus;/+ angepasst werden. Nicht eingecheckte Begleiter lassen sich sp&auml;ter erneut scannen.',
                'G&auml;ste k&ouml;nnen <strong>einzeln per E-Mail benachrichtigt</strong> oder <strong>alle auf einmal</strong> angeschrieben werden.',
                'Teilnehmer-Liste kann als <strong>CSV-Datei exportiert</strong> werden.',
            ]);

            // ── Express Checkout ──
            self::function_card('Express Checkout (1-Klick-Kauf)', 'dashicons-performance', [
                'Kunden, die bereits einmal gekauft haben, sehen einen <strong>&bdquo;Express-Checkout&ldquo;-Button</strong> direkt bei der Ticketauswahl.',
                'Mit einem Klick wird die Bestellung sofort aufgegeben &ndash; ohne nochmal Rechnungsdaten eingeben zu m&uuml;ssen.',
                'Funktioniert mit gespeicherten Zahlungsmethoden und Bank&uuml;berweisung (BACS).',
                'Kann global und pro Event einzeln aktiviert/deaktiviert werden (unter &bdquo;Erweitert&ldquo;).',
                'Mit <code>[tix_express_checkout]</code> kann ein Button mit Modal <strong>&uuml;berall auf der Seite</strong> platziert werden.',
            ]);

            // ── Gruppenbuchung ──
            self::function_card('Gruppenbuchung', 'dashicons-groups', [
                'Erm&ouml;glicht es einer Gruppe, gemeinsam Tickets zu kaufen &ndash; <strong>jeder gibt seine eigenen Daten ein</strong>.',
                'Ein Gruppenleiter erstellt eine Session und teilt den <strong>Einladungs-Link</strong> mit den Gruppenmitgliedern.',
                'Jedes Mitglied w&auml;hlt seine Tickets und gibt eigene Rechnungsdaten ein.',
                'Der Gruppenleiter sieht live den <strong>Status aller Mitglieder</strong> und schlie&szlig;t den Kauf f&uuml;r alle ab.',
                'Die Group-Session wird per <strong>Transient</strong> gespeichert (48h G&uuml;ltigkeit).',
                'Kann global in den Einstellungen aktiviert werden.',
            ]);

            // ── Newsletter ──
            self::function_card('Newsletter', 'dashicons-email', [
                'Im Checkout kann optional eine <strong>Newsletter-Anmeldung</strong> angezeigt werden (E-Mail oder WhatsApp).',
                'Abonnenten werden als eigener Inhaltstyp gespeichert (unter Events &rarr; Newsletter).',
                'Name, Adresse und alle K&auml;ufe des Abonnenten werden automatisch gespeichert und aktualisiert.',
                'Abonnenten k&ouml;nnen als <strong>CSV-Datei exportiert</strong> werden.',
                'Optional kann ein <strong>Webhook</strong> konfiguriert werden, um Anmeldungen an externe Dienste zu senden.',
            ]);

            // ── Verlassene Warenkörbe ──
            self::function_card('Verlassene Warenk&ouml;rbe', 'dashicons-cart', [
                'Wenn ein Kunde seine E-Mail-Adresse im Checkout eingibt, aber nicht kauft, wird der Warenkorb gespeichert.',
                'Nach einer einstellbaren Verz&ouml;gerung (Standard: 30 Minuten) wird automatisch eine <strong>Erinnerungs-E-Mail</strong> gesendet.',
                'Die E-Mail enth&auml;lt einen Link, der den Warenkorb automatisch wiederherstellt (per Token).',
                'Wenn der Kunde doch noch kauft, wird die E-Mail <strong>automatisch storniert</strong>.',
                'Alle verlassenen Warenk&ouml;rbe sind unter Events &rarr; Warenk&ouml;rbe einsehbar mit Status (pending, sent, recovered, expired).',
                'Kann global und pro Event einzeln aktiviert werden.',
            ]);

            // ── Ticket-Umschreibung ──
            self::function_card('Ticket-Umschreibung', 'dashicons-randomize', [
                'K&auml;ufer k&ouml;nnen <strong>ein oder mehrere Tickets gleichzeitig</strong> &uuml;ber ein Formular auf eine andere Person &uuml;bertragen.',
                'Der K&auml;ufer gibt seine <strong>Bestellnummer und E-Mail-Adresse</strong> ein, um seine Tickets zu finden.',
                'Anschlie&szlig;end w&auml;hlt er die gew&uuml;nschten Tickets per <strong>Checkbox</strong> aus und gibt <strong>Name und E-Mail</strong> des neuen Inhabers ein.',
                'Vor dem Absenden muss der K&auml;ufer best&auml;tigen, dass er <strong>keinen Zugriff mehr</strong> auf die umgeschriebenen Tickets hat.',
                'Falls der neue Inhaber noch kein Benutzerkonto hat, wird <strong>automatisch ein Konto erstellt</strong>.',
                'Die umgeschriebenen Tickets erscheinen unter <strong>&bdquo;Meine Tickets&ldquo;</strong> des neuen Inhabers.',
                'Die Funktion muss <strong>global in den Einstellungen</strong> und <strong>pro Event</strong> aktiviert werden.',
            ]);

            // ── Embed ──
            self::function_card('Event-Einbettung (Embed)', 'dashicons-admin-site-alt3', [
                'Events k&ouml;nnen per <strong>iFrame auf externen Websites</strong> eingebettet werden.',
                'Die Embed-URL hat das Format: <code>?tix_embed=EVENT_ID</code> mit optionalem <code>&amp;theme=light|dark</code>.',
                'Erlaubte Domains werden pro Event unter <code>_tix_embed_domains</code> konfiguriert.',
                'Die Embed-Ansicht zeigt den Ticket-Selector in einem minimalen Layout ohne Header/Footer.',
            ]);

            // ── Kalender ──
            self::function_card('Kalender-Integration', 'dashicons-calendar', [
                'F&uuml;r jedes Event wird automatisch eine <strong>ICS-Datei</strong> generiert (f&uuml;r Apple Kalender und Outlook).',
                'Au&szlig;erdem wird ein <strong>Google-Calendar-Link</strong> erstellt.',
                'Besucher k&ouml;nnen das Event mit einem Klick in ihren Kalender &uuml;bernehmen.',
                'Der Kalender-Button besitzt <strong>eigene Design-Einstellungen</strong> (Textfarbe, Hintergrund, Rahmen, Eckenradius, Hover-Effekte).',
            ]);

            // ── Publish-Validierung ──
            self::function_card('Publish-Validierung', 'dashicons-warning', [
                'Beim Ver&ouml;ffentlichen eines Events werden <strong>automatisch Pflichtfelder gepr&uuml;ft</strong>.',
                '<strong>Pflichtfelder (Fehler):</strong> Startdatum, mindestens eine Ticket-Kategorie mit Preis und Name.',
                '<strong>Empfohlene Felder (Warnung):</strong> Location, Beitragsbild, Enddatum.',
                'Fehler verhindern das Ver&ouml;ffentlichen und werden als Admin-Notice angezeigt.',
                'Warnungen erlauben das Ver&ouml;ffentlichen, weisen aber auf fehlende Daten hin.',
                '<strong>Serien-Master-Events</strong> &uuml;berspringen die Validierung.',
            ]);

            // ── Duplizierung ──
            self::function_card('Event-Duplizierung', 'dashicons-admin-page', [
                '&Uuml;ber die <strong>Row-Action &bdquo;Duplizieren&ldquo;</strong> in der Event-Liste kann ein Event komplett kopiert werden.',
                'Kopiert werden: Titel, alle Meta-Felder, Taxonomien und Beitragsbild.',
                '<strong>NICHT kopiert</strong> werden: Sync-IDs (product_id, sku), Serien-Links, Breakdance-Meta.',
                'Das Duplikat wird als <strong>Entwurf</strong> erstellt &ndash; beim Ver&ouml;ffentlichen werden WC-Produkte neu erstellt.',
            ]);

            // ── KI-Schutz ──
            self::function_card('KI-Schutz (Content Guard)', 'dashicons-shield', [
                'Pr&uuml;ft Event-Inhalte automatisch via <strong>Anthropic Claude API</strong> auf verbotene, diskriminierende oder sch&auml;dliche Inhalte.',
                'Pr&uuml;ft: <strong>Titel + URL-Slug + Kurzbeschreibung + Info-Sektionen</strong>.',
                '<strong>Fail-Closed:</strong> Bei API-Fehlern (Netzwerk, 401, 429, 529) wird das Event <strong>nicht</strong> ver&ouml;ffentlicht.',
                'Bei Ablehnung: Event wird auf Entwurf zur&uuml;ckgesetzt. <strong>Slug wird sanitized</strong> (event-entwurf-{id}).',
                '<strong>Content-Hash-Cache:</strong> MD5-Hash verhindert doppelte API-Calls bei unver&auml;ndertem Content.',
                'Aktivierbar unter <strong>Einstellungen &rarr; Erweitert &rarr; KI-Schutz</strong>. Ben&ouml;tigt Anthropic API Key.',
                'Modell: <code>claude-3-5-haiku-20241022</code> &ndash; ca. 0,001 &euro; pro Pr&uuml;fung.',
                'Admin-Spalte zeigt Status: &#x2713; (genehmigt), &#x26A0;&#xFE0F; (abgelehnt, mit Tooltip), &mdash; (nicht gepr&uuml;ft).',
            ]);

            // ── Force Delete & Cleanup ──
            self::function_card('Force Delete &amp; Restlose Event-L&ouml;schung', 'dashicons-trash', [
                '<strong>Force Delete:</strong> &Uuml;ber die Row-Action &bdquo;Unwiderruflich l&ouml;schen&ldquo; wird ein Event sofort und permanent gel&ouml;scht.',
                'Dabei werden <strong>alle Hooks deaktiviert</strong> (Cleanup, Series, Sync, Metabox) f&uuml;r volle Kontrolle.',
                'Bei Serien-Mastern: Alle Kinder + deren Produkte werden mit gel&ouml;scht.',
                'Bei Serien-Kindern: Das Kind wird aus dem <code>_tix_series_children</code>-Array des Masters entfernt.',
                '<strong>Restlose L&ouml;schung</strong> via <code>TIX_Cleanup::purge_event_data()</code> &ndash; l&ouml;scht <strong>alle</strong> verkn&uuml;pften Daten:',
                '&bull; <strong>Custom Tables:</strong> Raffle, Waitlist, Feedback, Seatmap, Ticket-DB, Promoter-Events, Promoter-Provisionen',
                '&bull; <strong>Verkn&uuml;pfte CPTs:</strong> Abandoned Carts, Subscribers, WC-Coupons',
                '&bull; <strong>Cron-Jobs:</strong> Geplante Reminder- und Follow-up-E-Mails',
                '&bull; <strong>Transients:</strong> Sync-Log, AI-Flag, Publish-Error, Publish-Warning',
                '<strong>Orphan-Bereinigung:</strong> &Uuml;ber &bdquo;Verwaiste Daten bereinigen&ldquo; werden aufger&auml;umt:',
                '1. <strong>Event-Kinder</strong> &ndash; Serien-Kinder deren Master nicht mehr existiert (inkl. purge_event_data)',
                '2. <strong>WC-Produkte</strong> &ndash; Produkte deren Eltern-Event nicht mehr existiert',
                '3. <strong>TIX-Tickets</strong> &ndash; Tickets deren Event nicht mehr existiert',
            ]);

            // ── Dynamischer Prefix ──
            self::function_card('Dynamischer Heute/Morgen-Prefix', 'dashicons-calendar-alt', [
                'Das Breakdance-Meta-Feld <code>_tix_date_today_prefix</code> enth&auml;lt dynamisch &bdquo;<strong>Heute</strong>&ldquo; oder &bdquo;<strong>Morgen</strong>&ldquo;.',
                'F&uuml;r Events, die weder heute noch morgen stattfinden, ist das Feld leer.',
                'Wird beim Lesen via <code>get_post_metadata</code>-Filter in Echtzeit berechnet &ndash; keine Cron-Abh&auml;ngigkeit.',
                'Ideal f&uuml;r Breakdance-Templates: &bdquo;Heute, 20:00 Uhr&ldquo; statt nur &bdquo;15.03.2026, 20:00 Uhr&ldquo;.',
            ]);

            // ── SEO ──
            self::function_card('SEO &amp; Social Media', 'dashicons-share', [
                '<strong>Open Graph:</strong> Beim Teilen auf Facebook, WhatsApp & Co. werden automatisch Titel, Beschreibung, Bild und Datum des Events angezeigt.',
                '<strong>JSON-LD:</strong> Strukturierte Daten (Schema.org) werden automatisch eingebunden, damit Google das Event als Event erkennt.',
                'Beides passiert vollautomatisch &ndash; du musst nichts konfigurieren.',
            ]);

            // ── Einstellungen ──
            self::function_card('Einstellungs-Modi', 'dashicons-admin-generic', [
                'Die Einstellungsseite bietet zwei Modi: <strong>Empfohlene Einstellungen</strong> (Light/Dark Theme-Preset) und <strong>Manuelle Einstellungen</strong> (alle Optionen).',
                'Kategorien: Allgemein, Checkout, E-Mails, Features, Design (Farben, Ticket-Selector, Checkout, Kalender-Button, Zusatzprodukte, FAQ, Express, My-Tickets).',
                'Alle Einstellungen werden in einer einzigen Option <code>tix_settings</code> gespeichert und per <code>tix_get_settings()</code> gecached abgerufen.',
            ]);

            // ── Dashboard Widget ──
            self::function_card('Admin-Dashboard', 'dashicons-dashboard', [
                'Im WordPress-Dashboard wird ein <strong>&Uuml;bersichts-Widget</strong> angezeigt mit den n&auml;chsten anstehenden Events.',
                'Du siehst auf einen Blick: Event-Name, Datum, Verkaufszahlen und Status.',
            ]);

            // ── Statistik-Dashboard ──
            self::function_card('Statistik-Dashboard', 'dashicons-chart-area', [
                'Eigene Admin-Seite unter <strong>Tixomat &rarr; Statistiken</strong> mit umfassender Datenanalyse.',
                '<strong>8 Tabs:</strong> &Uuml;bersicht, Umsatz, Tickets, Events, Check-in, Warenk&ouml;rbe, Newsletter, Rabatte.',
                '<strong>KPI-Cards</strong> mit Trend-Vergleich zur Vorperiode (prozentualer Anstieg/R&uuml;ckgang).',
                'Interaktive <strong>Charts</strong> (Chart.js) f&uuml;r alle Metriken &ndash; Linien-, Balken- und Donut-Diagramme.',
                '<strong>Globale Filter:</strong> Zeitraum (Presets + Datumswahl), Event, Location, Kategorie.',
                '<strong>CSV-Export</strong> pro Tab f&uuml;r externe Auswertung.',
                '<strong>Transient-Caching</strong> (10 Min.) f&uuml;r Performance bei gro&szlig;en Datenmengen.',
                'Vollst&auml;ndig <strong>HPOS-kompatible</strong> WooCommerce-Queries.',
            ]);

            // ── Barcode ──
            self::function_card('Strichcode (Barcode)', 'dashicons-editor-code', [
                '<strong>Code128-B Barcode</strong> f&uuml;r Handscanner auf Tickets.',
                'Globaler Toggle in <strong>Einstellungen &rarr; Erweitert</strong>.',
                '<strong>Per-Event Toggle</strong> im Erweitert-Tab des Event-Editors.',
                'Im Template-Editor als <strong>positionierbares Feld</strong> verf&uuml;gbar.',
                'Reine <strong>PHP/GD-Implementierung</strong> (kein externer Service erforderlich).',
                'Kodiert den 12-stelligen Ticket-Code als scanbaren Strichcode.',
            ]);

            // ── Soziales Projekt (Charity) ──
            self::function_card('Soziales Projekt (Charity)', 'dashicons-heart', [
                'Pro Event ein <strong>unterst&uuml;tztes Projekt</strong> mit Name, Beschreibung und Logo.',
                '<strong>Prozent-Anteil</strong> des Warenkorbs angeben, der an das Projekt geht.',
                'Globaler Toggle in <strong>Einstellungen &rarr; Erweitert</strong>.',
                '<strong>Per-Event Konfiguration</strong> im Erweitert-Tab des Event-Editors.',
                '<strong>Charity-Banner</strong> im Ticket-Selector und auf der Danke-Seite.',
            ]);

            // ── Support-System ──
            self::function_card('Support-System (CRM + Kunden-Portal)', 'dashicons-format-chat', [
                '<strong>Admin-Dashboard</strong> unter Tixomat &rarr; Support mit 3 Tabs: Anfragen, Kunden-Suche, Statistiken.',
                '<strong>Kunden-Suche</strong> erkennt automatisch E-Mail, Bestellnr. (#12345) und 12-stellige Ticket-Codes. Tickets k&ouml;nnen direkt ge&ouml;ffnet werden.',
                '<strong>Anfragen-System</strong> (CPT <code>tix_support_ticket</code>) mit 4 Status: Offen, In Bearbeitung, Gel&ouml;st, Geschlossen.',
                '<strong>Nachrichten-Thread</strong> mit 3 Typen: Kundennachricht, Admin-Antwort, Interne Notiz.',
                '<strong>Quick Actions:</strong> Ticket &ouml;ffnen, Download-Link kopieren, E-Mail erneut senden, Inhaber &auml;ndern, Bestellung &ouml;ffnen.',
                '<strong>Verknüpfte Tickets:</strong> Alle Tickets einer Bestellung werden in der Sidebar angezeigt mit schnellen Aktionen.',
                '<strong>Kunden-Portal</strong> per Shortcode <code>[tix_support]</code> &ndash; Anfragen erstellen, ansehen, antworten.',
                '<strong>Floating Chat-Widget</strong> &ndash; optionaler Chat-Button auf allen Seiten (Setting: <code>support_chat_enabled</code>).',
                '<strong>Auto-Login</strong> f&uuml;r eingeloggte User &ndash; kein Auth-Screen, Bestellungen werden automatisch als Dropdown angezeigt.',
                '<strong>E-Mail-Benachrichtigungen</strong> bei neuer Anfrage, Admin-Antwort, Kunden-Antwort und Status&auml;nderung.',
                'Globaler Toggle + konfigurierbare Kategorien in <strong>Einstellungen &rarr; Erweitert</strong>.',
            ]);

            // ── Inline-Erstellung ──
            self::function_card('Inline-Erstellung Location &amp; Veranstalter', 'dashicons-location', [
                'Locations und Veranstalter k&ouml;nnen <strong>direkt im Event-Editor</strong> erstellt werden &ndash; ohne Seitenwechsel.',
                '<strong>Modal-Dialog</strong> mit Name, Adresse und Beschreibung.',
                'Neue Eintr&auml;ge werden <strong>sofort ins Dropdown</strong> eingef&uuml;gt und automatisch ausgew&auml;hlt.',
                '<strong>Google Places Autocomplete</strong> f&uuml;r Adressen (wenn API-Key in den Einstellungen konfiguriert).',
                'Funktioniert sowohl im <strong>Gef&uuml;hrt-</strong> als auch im <strong>Experte-Modus</strong>.',
            ]);

            self::function_card('Saalplan (Seat Map)', 'dashicons-layout', [
                'Visueller <strong>Sitzplan-Editor</strong> mit 4 Layout-Typen: Theater, U-Form, Stadion, Arena.',
                '<strong>Sektionen = Ticket-Kategorien:</strong> Preis und Kategorie-Name pro Sektion definierbar.',
                'WooCommerce-Produkte werden <strong>automatisch pro Sektion</strong> erstellt und aktualisiert.',
                '<strong>Frontend-Modal:</strong> &bdquo;Platz w&auml;hlen&ldquo;-Button &ouml;ffnet interaktive Sitzplatzauswahl im Ticket-Selector.',
                '<strong>&bdquo;Bester verf&uuml;gbarer Platz&ldquo;</strong> Algorithmus: zusammenh&auml;ngende Sitze, m&ouml;glichst zentral.',
                'Manuelle Platzwahl: alle Sektionen farbcodiert, Klick auf einzelne Sitze.',
                '<strong>15-Minuten Reservierung</strong> mit automatischer Freigabe (Cron).',
                'Echtzeit-Verf&uuml;gbarkeit per AJAX, Session-basierte Reservierung.',
                '<strong>Event-Level Konfiguration</strong> im Erweitert-Tab (Saalplan + Platzvergabe-Modus).',
                'Standalone-Shortcode: <code>[tix_seatmap event="123"]</code>.',
                '<strong>Responsive:</strong> Vollbild-Modal auf Mobile.',
            ]);

            // ── Warenkorb & Mini-Cart ──
            self::function_card('Warenkorb &amp; Mini-Cart', 'dashicons-cart', [
                '<strong>Warenkorb-Shortcode</strong> <code>[tix_cart]</code> &ndash; Eigenst&auml;ndige Warenkorb-Seite im Tixomat-Design (gleiche CSS-Variablen wie Checkout).',
                '<strong>Mini-Cart Drawer</strong> &ndash; Slide-in von rechts mit hellem Design, Artikel&uuml;bersicht, Mengensteuerung und &bdquo;Zur Kasse&ldquo;-Button.',
                'Trigger per <code>class="tix-minicart-trigger"</code> oder <code>data-tix-minicart</code> Attribut auf beliebigen Elementen.',
                '<strong>Live-Badge</strong> <code>.tix-minicart-count</code> zeigt Artikelanzahl und wird per <strong>WooCommerce Fragments</strong> bei jedem AJAX-Add-to-Cart aktualisiert.',
                'Specials-Upsell und Tischreservierung werden automatisch in Warenkorb und Checkout eingebettet.',
            ]);

            // ── Tischreservierung ──
            self::function_card('Tischreservierung', 'dashicons-welcome-widgets-menus', [
                '<strong>Standalone SPA</strong> per <code>[tix_table_reservation]</code>: Monatskalender &rarr; Event &rarr; Tischkategorie &rarr; Buchungsformular.',
                '<strong>Checkout-Integration:</strong> Tischkarten werden automatisch auf Step 1 des Checkouts und auf der Warenkorb-Seite angezeigt (unter Specials).',
                'Pro Event konfigurierbar: Tischkategorien mit Name, Kapazit&auml;t, Preis, Mindestbestellwert.',
                '<strong>3 Zahlungsmodi:</strong> Vor Ort, Anzahlung, Vollzahlung &ndash; jeweils pro Event einstellbar.',
                'WooCommerce-Integration: Reservierungen werden als Cart-Fee (Mindestbestellwert) oder WC-Order gespeichert.',
                'Design: <code>--tix-*</code> CSS-Variablen, konsistent mit Checkout und Ticket-Selector.',
            ]);

            // ── POS / Abendkasse ──
            self::function_card('POS / Abendkasse', 'dashicons-store', [
                '<strong>Fullscreen-SPA</strong> per <code>[tix_pos]</code> &ndash; optimiert f&uuml;r Tablets am Einlass.',
                '<strong>PIN-Login:</strong> 4-6-stelliger PIN pro WordPress-User (gespeichert als <code>_tix_pos_pin</code>).',
                '<strong>Screens:</strong> PIN &rarr; Event-Auswahl &rarr; Ticketverkauf &rarr; Zahlung &rarr; Wechselgeld &rarr; Erfolg (QR-Codes).',
                '<strong>3 Zahlungsarten:</strong> Bar (mit Wechselgeldrechner), EC-Karte (SumUp-Integration), Kostenlos.',
                '<strong>Kassenbericht:</strong> Tagesumsatz nach Zahlungsart, Ticket-Kategorie, stundweise Aufschl&uuml;sselung.',
                '<strong>Transaktionshistorie:</strong> Alle POS-Verk&auml;ufe mit Storno-Funktion.',
                'WooCommerce-Orders mit <code>_tix_pos_order</code> Meta + korrekter Payment-Method.',
                'Aktivierbar unter <strong>Einstellungen &rarr; Erweitert &rarr; POS / Abendkasse</strong>.',
            ]);

            // ── Admin Shell / Fullscreen ──
            self::function_card('Admin Shell / Fullscreen-Modus', 'dashicons-fullscreen-alt', [
                '<strong>Fullscreen-UI:</strong> Versteckt WordPress-Chrome (Admin-Bar, Sidebar, Footer) auf allen Tixomat-Seiten.',
                '<strong>Eigene Sidebar-Navigation:</strong> Linke Sidebar mit Logo, Gruppen (Events, Ticketing, Verwaltung, Einstellungen, Hilfe).',
                '<strong>Organizer-Modus:</strong> Veranstalter erhalten eine reduzierte Sidebar (Dashboard, Meine Events, Ticketing, Verwaltung, Einstellungen). Admin-Bar wird f&uuml;r Organizer komplett ausgeblendet.',
                '<strong>URL-Rewriting:</strong> <code>wp-admin</code>-URLs werden kosmetisch durch den Organizer-Slug ersetzt (<code>history.replaceState</code>).',
                '<strong>Kontextabh&auml;ngig:</strong> Auf der Einstellungs-Seite zeigt die Sidebar die Settings-Tabs, auf der Doku-Seite die Doku-Tabs, auf der Support-Seite die Support-Tabs.',
                '<strong>Floating Publish-Button:</strong> Oben rechts fix &ndash; Aktualisieren/Ver&ouml;ffentlichen + Status-Anzeige + Vorschau-Link. Auch auf Post-Edit-Seiten verf&uuml;gbar.',
                '<strong>Responsive:</strong> Auf Mobile wird die Sidebar als Slide-In angezeigt.',
                'Versteckt automatisch: Breakdance-Button, LiteSpeed-Metabox, WP-Sidebar-Boxen (Kategorien, Beitragsbild, Textauszug sind in den Metabox-Tabs).',
                'Deaktivierbar unter <strong>Einstellungen &rarr; Erweitert &rarr; Admin-Ansicht</strong>.',
                'Dateien: <code>class-tix-admin-shell.php</code>, <code>admin-shell.css</code>, <code>admin-shell.js</code>.',
            ]);

            // ── Veranstalter-Admin ──
            self::function_card('Veranstalter-Admin', 'dashicons-businessman', [
                '<strong>Rolle &amp; Capabilities:</strong> <code>tix_organizer</code> erh&auml;lt <code>edit_posts</code>, <code>publish_posts</code>, <code>delete_posts</code>, <code>edit_others_posts</code>, <code>upload_files</code>.',
                '<strong>Event-Ownership:</strong> <code>pre_get_posts</code> filtert Events nach <code>_tix_organizer_id</code> &ndash; Organizer sehen nur eigene Events.',
                '<strong>Capability Mapping:</strong> <code>map_meta_cap</code> stellt sicher, dass Organizer nur eigene Events bearbeiten k&ouml;nnen.',
                '<strong>Admin-Men&uuml;:</strong> Alle WP-Men&uuml;s entfernt au&szlig;er Tixomat, Upload, Profil.',
                '<strong>Login-Redirect:</strong> Organizer werden nach Login zu <code>tix-organizer-dashboard</code> weitergeleitet.',
                '<strong>Erlaubte Post-Types:</strong> <code>event</code>, <code>tix_location</code>, <code>tix_ticket</code>, <code>tix_seatmap</code>, <code>tix_ticket_tpl</code>, <code>tix_subscriber</code>.',
                '<strong>Auto-Assign:</strong> Neue Events erhalten automatisch <code>_tix_organizer_id</code>.',
                '<strong>User-Profil:</strong> <code>_tix_organizer_name</code> auf dem Profil, synchronisiert mit <code>tix_organizer</code> CPT-Titel.',
                '<strong>5 Admin-Seiten:</strong> Dashboard (KPI-Cards + Top-5-Events), Bestellungen (Tabelle mit Event-Filter + Suche + Pagination), G&auml;steliste (CSV-Export), E-Mail (Bulk-Mail an K&auml;ufer, 1&times;/Tag/Event), Abrechnung (monatlicher CSV-Export).',
                '<strong>Benachrichtigungen:</strong> Neue-Bestellung-E-Mail an Organizer + Low-Stock-Warnung (&lt;&nbsp;10&nbsp;% Restbestand, einmaliges Flag <code>_tix_low_stock_notified</code>).',
                '<strong>Ticket-Filter:</strong> <code>tix_ticket</code>-Liste zeigt nur Tickets der eigenen Events.',
                'Datei: <code>class-tix-organizer-admin.php</code>.',
            ]);

            // ── Custom Login URLs ──
            self::function_card('Custom Login URLs', 'dashicons-admin-links', [
                '<strong>Custom Login URL:</strong> z.&thinsp;B. <code>/anmelden/</code> statt <code>/wp-login.php</code>.',
                '<strong>Custom Organizer URL:</strong> z.&thinsp;B. <code>/veranstalter/</code> leitet zum Dashboard weiter.',
                '<strong>Settings:</strong> <code>login_slug</code> und <code>organizer_slug</code> im Tab <strong>Erweitert</strong>.',
                '<strong>URL-Intercept:</strong> Per <code>init</code>-Hook wird der URL-Pfad geparst und auf die Custom-Seiten gemappt.',
                '<strong>wp-login.php Redirect:</strong> Automatische Weiterleitung von <code>wp-login.php</code> zur Custom-Login-URL.',
                '<strong>Gebrandete Login-Seite:</strong> Tixomat-Logo, Hintergrund <code>#FAF8F4</code>, orangener Button.',
                '<strong>Login-Verarbeitung:</strong> POST wird direkt mit <code>wp_signon()</code> verarbeitet, Organizer-spezifischer Redirect nach Login.',
                'Datei: <code>class-tix-custom-urls.php</code>.',
            ]);

            // ── REST API ──
            self::function_card('REST API <small>(tixomat/v1)</small>', 'dashicons-rest-api', [
                '<strong>24 Endpoints</strong> im Namespace <code>tixomat/v1</code> f&uuml;r die Tixomat-App (iOS/Android).',
                '<strong>Auth:</strong> WordPress Application Passwords (Basic Auth) oder <code>X-Tix-Token</code> / <code>Bearer</code> Header.',
                '<strong>Gruppen:</strong> Discovery, Events, Check-in, G&auml;steliste, Tickets, POS, Auth, Customer.',
                'Details im Tab <strong>REST API</strong>.',
            ]);
            ?>

        </div>
        <?php
    }

    private static function function_card($title, $icon, $points) {
        ?>
        <div class="tix-card">
            <div class="tix-card-header">
                <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                <h3><?php echo $title; ?></h3>
            </div>
            <div class="tix-card-body">
                <ul style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($points as $point): ?>
                    <li style="padding-left:20px;position:relative;line-height:1.6;color:#475569;font-size:13px;">
                        <span style="position:absolute;left:0;color:#2563eb;">&#x2022;</span>
                        <?php echo $point; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php
    }

    // ══════════════════════════════════════
    // TAB 4: AJAX & HOOKS
    // ══════════════════════════════════════


    private static function render_ajax_tab() {
        global $wp_filter;
        $actions = [];
        foreach (($wp_filter ?? []) as $hook => $obj) {
            if (strpos($hook, 'wp_ajax_tix') !== 0 && strpos($hook, 'wp_ajax_nopriv_tix') !== 0) continue;
            $action = str_replace(['wp_ajax_nopriv_', 'wp_ajax_'], '', $hook);
            $is_public = strpos($hook, 'wp_ajax_nopriv_') === 0;
            $callbacks = [];
            if (is_object($obj) && !empty($obj->callbacks)) {
                foreach ($obj->callbacks as $prio => $cbs) {
                    foreach ($cbs as $cb) {
                        $fn = $cb['function'] ?? null;
                        if (is_array($fn)) {
                            $class = is_object($fn[0]) ? get_class($fn[0]) : $fn[0];
                            $callbacks[] = $class . '::' . $fn[1];
                        } elseif (is_string($fn)) {
                            $callbacks[] = $fn;
                        }
                    }
                }
            }
            if (!isset($actions[$action])) $actions[$action] = ['public' => false, 'private' => false, 'callbacks' => []];
            if ($is_public) $actions[$action]['public']  = true;
            else            $actions[$action]['private'] = true;
            foreach ($callbacks as $c) {
                if (!in_array($c, $actions[$action]['callbacks'], true)) $actions[$action]['callbacks'][] = $c;
            }
        }
        ksort($actions);
        ?>
        <div class="tix-pane" data-pane="ajax">
            <p class="description">
                <strong>AJAX-Endpunkte</strong> werden automatisch aus <code>$wp_filter['wp_ajax_*']</code> erkannt. URL immer: <code>/wp-admin/admin-ajax.php</code> mit <code>action=&lt;name&gt;</code>.
            </p>

            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:13px;color:#075985;">
                <strong><?php echo count($actions); ?> Tixomat-AJAX-Actions</strong> registriert (Stand: Live-Scan).
                Spalte <em>Zugriff</em>: <code>Admin</code> = nur für eingeloggte User, <code>Public</code> = auch nopriv.
            </div>

            <table class="tix-tbl" style="width:100%;">
                <thead>
                    <tr>
                        <th style="width:36%;">Action</th>
                        <th style="width:12%;">Zugriff</th>
                        <th>Callback</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($actions as $name => $info):
                        $access = $info['public'] && $info['private'] ? 'Beides'
                                 : ($info['public'] ? 'Public' : 'Admin');
                        $badge  = $info['public'] ? '#10b981' : '#6366f1';
                    ?>
                    <tr class="tix-row">
                        <td><code style="font-size:12px;background:rgba(37,99,235,0.06);padding:2px 6px;border-radius:4px;"><?php echo esc_html($name); ?></code></td>
                        <td><span style="display:inline-block;padding:2px 8px;background:<?php echo $badge; ?>15;color:<?php echo $badge; ?>;font-size:11px;font-weight:600;border-radius:6px;"><?php echo $access; ?></span></td>
                        <td style="font-family:monospace;font-size:11px;color:#475569;"><?php echo esc_html(implode(', ', $info['callbacks'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ══════════════════════════════════════
    // TAB: LANDING (Organizer-Landingpages)
    // ══════════════════════════════════════

    private static function render_landing_tab() {
        $enabled = class_exists('TIX_Organizer_Landing') && TIX_Organizer_Landing::is_feature_enabled();
        ?>
        <div class="tix-pane" data-pane="landing">

            <p class="description">
                Veranstalter-Landingpages: Jeder freigeschaltete Veranstalter bekommt eine eigene, brandbare Seite mit eigener URL (Pfad <code>/v/{slug}/</code> oder Subdomain <code>{slug}.evendis.de</code>).
            </p>

            <div style="background:<?php echo $enabled ? '#ecfdf5' : '#fef3c7'; ?>;border:1px solid <?php echo $enabled ? '#6ee7b7' : '#fcd34d'; ?>;border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:13px;color:<?php echo $enabled ? '#065f46' : '#92400e'; ?>;">
                <strong>Feature-Status:</strong> <?php echo $enabled ? '✓ aktiv auf dieser Site' : 'nicht aktiv'; ?>
                (steuerbar über <code>tix_settings.landing_pages_enabled</code>, automatisch an auf <code>evendis.de</code>)
            </div>

            <?php // ── Architektur ── ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-layout"></span>
                    <h3>Architektur &amp; Routing</h3>
                </div>
                <div class="tix-card-body">
                    <ul style="margin:0;padding-left:20px;line-height:1.8;">
                        <li><strong>Pfad-URL</strong>: <code>https://evendis.de/v/{slug}/</code> — immer aktiv, Fallback-URL.</li>
                        <li><strong>Subdomain</strong>: <code>https://{slug}.evendis.de/</code> — aktiv wenn <code>tix_settings.landing_use_subdomain = 1</code> und DNS-Wildcard konfiguriert.</li>
                        <li><strong>Subdomain-Kontext</strong>: beim Request wird <code>TIX_ON_ORG_SUBDOMAIN</code> + <code>TIX_ORG_SUBDOMAIN_SLUG</code> definiert.</li>
                        <li><strong>URL-Rewrite</strong>: <code>home_url</code>, <code>site_url</code>, <code>rest_url</code> werden auf Subdomain-Requests gefiltert → alle Permalinks bleiben auf Subdomain.</li>
                        <li><strong>Unbekannte Subdomain</strong>: 301 zur Hauptdomain (SEO-Schutz gegen Duplicate Content).</li>
                        <li><strong>Sub-Pfade auf Subdomain</strong>: <code>/events/{slug}/</code>, <code>/checkout/</code>, <code>/kasse/</code> funktionieren. <code>/events/</code> (Archiv) und alle anderen unbekannten Pfade → 302 zur Landing-Root.</li>
                    </ul>
                </div>
            </div>

            <?php // ── Meta-Felder ── ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-database"></span>
                    <h3>Meta-Felder (Post-Type <code>tix_organizer</code>)</h3>
                </div>
                <div class="tix-card-body">
                    <table class="tix-tbl" style="width:100%;">
                        <thead>
                            <tr><th style="width:35%;">Meta-Key</th><th style="width:15%;">Typ</th><th>Beschreibung</th></tr>
                        </thead>
                        <tbody>
                            <?php
                            $metas = [
                                '_tix_org_landing_approved'       => ['bool',     'Admin-Freischaltung. Nur mit 1 wird Landing öffentlich rendert.'],
                                '_tix_org_landing_slug'           => ['string',   'URL-Slug (z.B. <code>kitchen-klub</code>). Unique pro Site. Reservierte Slugs (www, admin etc.) geblockt.'],
                                '_tix_org_landing_sections'       => ['array',    'Section-Builder: Reihenfolge + Enabled. Struktur: <code>[{id, enabled}, …]</code>. IDs: <code>events, about, partners</code>.'],
                                '_tix_org_landing_color_mode'     => ['string',   '<code>light</code> oder <code>dark</code>. Default: light.'],
                                '_tix_org_landing_primary_light'  => ['hex',      'Primärfarbe Light Mode. Default: <code>#E8445A</code>.'],
                                '_tix_org_landing_primary_dark'   => ['hex',      'Primärfarbe Dark Mode.'],
                                '_tix_org_landing_bg_light'       => ['hex',      'Hintergrundfarbe Light Mode. Default: <code>#ffffff</code>.'],
                                '_tix_org_landing_bg_dark'        => ['hex',      'Hintergrundfarbe Dark Mode. Default: <code>#0b0a12</code>.'],
                                '_tix_org_landing_header_bg_light'   => ['hex',   'Header-BG Light (leer → BG).'],
                                '_tix_org_landing_header_bg_dark'    => ['hex',   'Header-BG Dark.'],
                                '_tix_org_landing_header_text_light' => ['hex',   'Header-Schrift Light.'],
                                '_tix_org_landing_header_text_dark'  => ['hex',   'Header-Schrift Dark.'],
                                '_tix_org_landing_footer_bg_light'   => ['hex',   'Footer-BG Light (leer → Surface).'],
                                '_tix_org_landing_footer_bg_dark'    => ['hex',   'Footer-BG Dark.'],
                                '_tix_org_landing_footer_text_light' => ['hex',   'Footer-Schrift Light.'],
                                '_tix_org_landing_footer_text_dark'  => ['hex',   'Footer-Schrift Dark.'],
                                '_tix_org_landing_logo_id'        => ['int',      'WP Attachment-ID für Logo (Header + Landing).'],
                                '_tix_org_landing_favicon_id'     => ['int',      'Attachment-ID für Favicon.'],
                                '_tix_org_landing_hero_id'        => ['int',      'Attachment-ID für Hero-Bild (Background).'],
                                '_tix_org_landing_hero_video_id'  => ['int',      'Attachment-ID für MP4-Video-Upload (optional).'],
                                '_tix_org_landing_hero_video_url' => ['url',      'Externe Video-URL (MP4/YouTube/Vimeo). Hat Vorrang vor Upload.'],
                                '_tix_org_landing_tagline'        => ['string',   'Untertitel im Hero (max. 100 Zeichen).'],
                                '_tix_org_landing_description'    => ['richtext', 'Text für die "Über uns"-Sektion.'],
                                '_tix_org_landing_cta_text'       => ['string',   'Text des Hero-CTA-Buttons. Default: "Tickets sichern".'],
                                '_tix_org_landing_cta_url'        => ['url',      'Ziel des CTA (URL oder <code>#anker</code>). Default: <code>#events</code>.'],
                                '_tix_org_landing_countdown_enabled' => ['bool',  'Live-Countdown zum nächsten Event im Hero anzeigen.'],
                                '_tix_org_landing_social'         => ['array',    'Social-Links. Keys: <code>website, instagram, facebook, tiktok, x, youtube, spotify</code>.'],
                                '_tix_org_landing_contact_email'  => ['email',    'Öffentliche Kontakt-E-Mail im Footer (optional).'],
                                '_tix_org_landing_show_past_events' => ['bool',   'Vergangene Events als ausklappbare Sektion zeigen.'],
                                '_tix_org_landing_partners'       => ['array',    'Partner-Logo-Strip. Struktur: <code>[{name, logo_id, url}, …]</code>.'],
                                '_tix_org_landing_partners_heading' => ['string', 'Überschrift der Partner-Sektion. Default: "Unsere Partner".'],
                            ];
                            foreach ($metas as $key => [$type, $desc]): ?>
                                <tr class="tix-row">
                                    <td><code style="font-size:11px;"><?php echo esc_html($key); ?></code></td>
                                    <td><span style="font-size:11px;color:#6366f1;font-weight:600;"><?php echo esc_html($type); ?></span></td>
                                    <td style="font-size:13px;color:#475569;"><?php echo wp_kses_post($desc); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php // ── Settings / Optionen ── ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-admin-settings"></span>
                    <h3>Globale Settings (<code>tix_settings</code> + andere)</h3>
                </div>
                <div class="tix-card-body">
                    <table class="tix-tbl" style="width:100%;">
                        <tbody>
                            <tr class="tix-row"><td><code>tix_settings.landing_pages_enabled</code></td><td>Feature-Flag. Default an auf evendis.de.</td></tr>
                            <tr class="tix-row"><td><code>tix_settings.landing_use_subdomain</code></td><td>Subdomain-Modus ein/aus. Braucht DNS-Wildcard + SSL.</td></tr>
                            <tr class="tix-row"><td><code>tix_landing_footer_credit</code> (Option)</td><td>Override für Footer-Credit <code>{text, url}</code>. Leer → Site-Name + home_url.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php // ── Public API ── ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-admin-tools"></span>
                    <h3>Public API (<code>TIX_Organizer_Landing</code>)</h3>
                </div>
                <div class="tix-card-body">
                    <table class="tix-tbl" style="width:100%;">
                        <tbody>
                            <tr class="tix-row"><td><code>is_feature_enabled()</code></td><td>Bool. Ist Landing-Feature auf dieser Site aktiv?</td></tr>
                            <tr class="tix-row"><td><code>canonical_url($slug)</code></td><td>Liefert die Haupt-URL (Subdomain wenn Feature an, sonst Pfad).</td></tr>
                            <tr class="tix-row"><td><code>get_organizer_by_slug($slug)</code></td><td>Post-Objekt oder null.</td></tr>
                            <tr class="tix-row"><td><code>is_approved($org_id)</code></td><td>Ist Organizer-Landing freigeschaltet?</td></tr>
                            <tr class="tix-row"><td><code>get_landing_data($org)</code></td><td>Array mit allen gesammelten Daten für Rendering (logo, hero, colors, socials, …).</td></tr>
                            <tr class="tix-row"><td><code>sections_registry()</code></td><td>Liefert verfügbare Section-IDs + Labels.</td></tr>
                            <tr class="tix-row"><td><code>get_sections_config($org_id)</code></td><td>Liefert die gespeicherte Reihenfolge + Enabled-State (mit Auto-Defaults).</td></tr>
                            <tr class="tix-row"><td><code>track_view($org_id, $event_id)</code></td><td>Zeichnet einen Seitenaufruf in <code>wp_tix_landing_views</code> auf (wird automatisch gerufen).</td></tr>
                            <tr class="tix-row"><td><code>analytics_summary($org_id, $days)</code></td><td>Views / Unique / Top-Events / Referrer / Conversions der letzten N Tage.</td></tr>
                            <tr class="tix-row"><td><code>get_footer_credit()</code></td><td>Array <code>{text, url}</code> für "by ..."-Footer.</td></tr>
                            <tr class="tix-row"><td><code>shade_color($hex, $amount)</code></td><td>Farbe heller/dunkler. Auto-Richtung je nach Luminanz.</td></tr>
                            <tr class="tix-row"><td><code>detect_video_type($url)</code></td><td>Liefert <code>mp4</code>/<code>youtube</code>/<code>vimeo</code>.</td></tr>
                            <tr class="tix-row"><td><code>get_video_embed_url($url, $type)</code></td><td>YouTube/Vimeo → Embed-iframe-URL mit Autoplay/Loop/Mute.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php // ── Tracking / Analytics ── ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-chart-bar"></span>
                    <h3>Analytics-Datenbank</h3>
                </div>
                <div class="tix-card-body">
                    <p>Eigene Tabelle <code><?php echo esc_html($GLOBALS['wpdb']->prefix . 'tix_landing_views'); ?></code> — wird automatisch angelegt.</p>
                    <pre style="background:#1e293b;color:#e2e8f0;padding:12px 14px;border-radius:8px;font-size:11px;overflow-x:auto;">id, org_id, slug, event_id, url_path,
referrer, utm_source, utm_medium, utm_campaign,
ip_hash (MD5 mit Tagesdatum = 1× pro Tag unique),
is_bot (UA-basiert), created_at</pre>
                    <p style="font-size:13px;color:#64748b;margin-top:10px;">
                        <strong>Trigger:</strong> Jeder Landing-Render (<code>render_landing</code>), jeder Event-Detail-Render auf Subdomain (<code>maybe_track_event_view</code>).<br>
                        <strong>Attribution:</strong> Cookie <code>tix_ol_src</code> (7 Tage) → speichert bei Order-Creation <code>_tix_ol_source</code> Order-Meta → Conversion-Count matched.
                    </p>
                </div>
            </div>

            <?php // ── Admin-Oberflächen ── ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-admin-users"></span>
                    <h3>Admin-Oberflächen</h3>
                </div>
                <div class="tix-card-body">
                    <ul style="margin:0;padding-left:20px;line-height:1.8;font-size:13px;">
                        <li><strong>Veranstalter-Edit-Seite</strong> — Block <em>Landingpage</em> direkt unter dem Titel: Admin-Freischaltung (nur <code>manage_options</code>) + Slug-Verwaltung mit Live-Check.</li>
                        <li><strong>Tixomat → Meine Landingpage</strong> — Self-Service-Seite für den Veranstalter (sichtbar wenn <code>_tix_org_user_id</code> gesetzt oder Admin mit <code>?org=ID</code>). Logo/Hero/Farben/CTA/Social/Analytics.</li>
                        <li><strong>Tixomat → Einstellungen → Landingpages</strong> — Admin-only. Footer-Credit-Override.</li>
                    </ul>
                </div>
            </div>

            <?php // ── Section-Builder Registry ── ?>
            <?php if (class_exists('TIX_Organizer_Landing')):
                $registry = TIX_Organizer_Landing::sections_registry(); ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-schedule"></span>
                    <h3>Section-Registry (drag&amp;drop + enable/disable)</h3>
                </div>
                <div class="tix-card-body">
                    <table class="tix-tbl" style="width:100%;">
                        <tbody>
                            <?php foreach ($registry as $id => $meta): ?>
                                <tr class="tix-row">
                                    <td style="width:30%;"><code><?php echo esc_html($id); ?></code></td>
                                    <td><?php echo esc_html($meta['icon'] . ' ' . $meta['label']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }
    private static function render_templates_tab() {
        ?>
        <div class="tix-pane" data-pane="templates">

            <h3>Ticket-Vorlagen System</h3>
            <p>Ab Version 1.28.0 k&ouml;nnen Ticket-Vorlagen als eigener Post-Type verwaltet werden. Dies erm&ouml;glicht die Wiederverwendung von Vorlagen &uuml;ber mehrere Events hinweg.</p>

            <h4>Vorlage erstellen</h4>
            <ol>
            <li>Navigiere zu <strong>tixomat &rarr; Ticket-Vorlagen &rarr; Neue Vorlage</strong></li>
            <li>Gib der Vorlage einen aussagekr&auml;ftigen Namen</li>
            <li>Lade ein Hintergrundbild hoch (empfohlen: 2480&times;3508 px f&uuml;r A4)</li>
            <li>Aktiviere und positioniere die gew&uuml;nschten Felder per Drag &amp; Drop</li>
            <li>Ver&ouml;ffentliche die Vorlage</li>
            </ol>

            <h4>Vorlage einem Event zuweisen</h4>
            <p>Im Event-Editor unter dem Tab &bdquo;Ticket-Vorlage&ldquo; gibt es folgende Optionen:</p>
            <table class="widefat">
            <thead><tr><th>Modus</th><th>Beschreibung</th></tr></thead>
            <tbody>
            <tr><td><strong>Globale Vorlage</strong></td><td>Verwendet die unter Einstellungen &rarr; Tickets konfigurierte Standard-Vorlage</td></tr>
            <tr><td><strong>Vorlage w&auml;hlen</strong></td><td>W&auml;hlt eine der gespeicherten Ticket-Vorlagen (CPT) aus</td></tr>
            <tr><td><strong>Eigene Vorlage</strong></td><td>Inline-Editor f&uuml;r eine event-spezifische Vorlage</td></tr>
            <tr><td><strong>Keine</strong></td><td>Kein PDF-Ticket, stattdessen HTML-Ansicht</td></tr>
            </tbody>
            </table>

            <h4>Template-Editor Funktionen</h4>
            <p>Der visuelle Editor unterst&uuml;tzt folgende Feld-Eigenschaften:</p>
            <table class="widefat">
            <thead><tr><th>Eigenschaft</th><th>Typ</th><th>Beschreibung</th></tr></thead>
            <tbody>
            <tr><td>Position (X/Y)</td><td>Pixel</td><td>Position auf dem Template-Bild</td></tr>
            <tr><td>Breite/H&ouml;he</td><td>Pixel</td><td>Dimensionen des Feldes</td></tr>
            <tr><td>Schriftgr&ouml;&szlig;e</td><td>8&ndash;200</td><td>Schriftgr&ouml;&szlig;e in Punkt</td></tr>
            <tr><td>Schriftart</td><td>Auswahl</td><td>Sans-Serif, Serif, Monospace</td></tr>
            <tr><td>Schriftstil</td><td>Auswahl</td><td>Normal, Fett</td></tr>
            <tr><td>Farbe</td><td>Hex</td><td>Textfarbe</td></tr>
            <tr><td>Ausrichtung</td><td>Auswahl</td><td>Links, Mitte, Rechts</td></tr>
            <tr><td>Zeichenabstand</td><td>-5 bis 50</td><td>Abstand zwischen einzelnen Zeichen</td></tr>
            <tr><td>Zeilenh&ouml;he</td><td>0.8&ndash;3.0</td><td>Vertikaler Zeilenabstand (Faktor)</td></tr>
            <tr><td>Drehung</td><td>-180&deg; bis 180&deg;</td><td>Rotation des Feldes</td></tr>
            <tr><td>Deckkraft</td><td>0&ndash;100%</td><td>Transparenz des Feldes</td></tr>
            <tr><td>Hintergrund</td><td>Hex/leer</td><td>Optionale Hintergrundfarbe</td></tr>
            <tr><td>Rahmen</td><td>Farbe + Breite</td><td>Optionaler Rahmen um das Feld</td></tr>
            <tr><td>Innenabstand</td><td>0&ndash;50</td><td>Padding innerhalb des Feldes</td></tr>
            <tr><td>Textumwandlung</td><td>Auswahl</td><td>Normal, GROSSBUCHSTABEN, kleinbuchstaben</td></tr>
            </tbody>
            </table>

            <h4>Download-URLs</h4>
            <p>Ticket-Download-URLs verwenden ab v1.28.0 kryptische 64-Zeichen-Tokens f&uuml;r mehr Sicherheit:</p>
            <code>https://deine-domain.de/?tix_dl=a8f3c72e9b0d4e5f6a1b2c3d...</code>
            <p>Bestehende URLs mit <code>tix_ticket_code</code> + <code>tix_ticket_key</code> funktionieren weiterhin (R&uuml;ckw&auml;rtskompatibilit&auml;t).</p>

            <h4>Verf&uuml;gbare Platzhalter</h4>
            <table class="widefat">
            <thead><tr><th>Feld</th><th>Beschreibung</th></tr></thead>
            <tbody>
            <tr><td>Event-Name</td><td>Titel des Events</td></tr>
            <tr><td>Datum</td><td>Formatiertes Eventdatum</td></tr>
            <tr><td>Beginn</td><td>Startzeit des Events</td></tr>
            <tr><td>Einlass</td><td>Einlasszeit</td></tr>
            <tr><td>Veranstaltungsort</td><td>Name der Location</td></tr>
            <tr><td>Adresse</td><td>Adresse der Location</td></tr>
            <tr><td>Ticket-Kategorie</td><td>Name der Kategorie</td></tr>
            <tr><td>Preis</td><td>Ticketpreis</td></tr>
            <tr><td>Inhaber</td><td>Name des K&auml;ufers</td></tr>
            <tr><td>E-Mail</td><td>E-Mail des K&auml;ufers</td></tr>
            <tr><td>Ticket-Code</td><td>Eindeutiger 12-stelliger alphanumerischer Code</td></tr>
            <tr><td>Bestellnummer</td><td>WooCommerce Bestellnummer</td></tr>
            <tr><td>Sitzplatz</td><td>Zugewiesener Sitzplatz (bei Saalplan)</td></tr>
            <tr><td>QR-Code</td><td>QR-Code f&uuml;r Check-in Scanner</td></tr>
            <tr><td>Strichcode</td><td>Code128-B Barcode f&uuml;r Handscanner</td></tr>
            <tr><td>Eigener Text</td><td>Frei definierbarer Text</td></tr>
            </tbody>
            </table>

        </div>
        <?php
    }

    // ══════════════════════════════════════
    // TAB 6: PROMOTER-SYSTEM
    // ══════════════════════════════════════

    private static function render_promoter_tab() {
        ?>
        <div class="tix-pane" data-pane="promoter">

            <p class="description">
                <strong>Promoter-System</strong> &ndash; Umfassendes Affiliate-/Promoter-Portal f&uuml;r Event-Verk&auml;ufe.
                Promoter k&ouml;nnen pro Event zugewiesen werden, erhalten Provisionen und k&ouml;nnen K&auml;ufer-Rabatte weitergeben.
                Das System umfasst Admin-Verwaltung, Frontend-Dashboard, Referral-Tracking und WooCommerce-Integration.
            </p>

            <?php
            // ── Aktivierung ──
            self::function_card('Aktivierung &amp; Einrichtung', 'dashicons-admin-plugins', [
                'Unter <strong>Tixomat &rarr; Einstellungen &rarr; Features</strong> den Schalter <strong>&bdquo;Promoter-System&ldquo;</strong> aktivieren.',
                'Das Plugin erstellt automatisch 4 Datenbank-Tabellen: <code>tixomat_promoters</code>, <code>tixomat_promoter_events</code>, <code>tixomat_promoter_commissions</code>, <code>tixomat_promoter_payouts</code>.',
                'Neue WP-Rolle <code>tix_promoter</code> wird registriert (nur Lese-Rechte).',
                'Falls die Tabellen nicht erstellt werden: Plugin deaktivieren und wieder aktivieren.',
            ]);

            // ── Admin-Backend ──
            self::function_card('Admin-Backend <small>(Tixomat &rarr; Promoter)</small>', 'dashicons-admin-users', [
                '<strong>Tab &bdquo;Promoter&ldquo;</strong> &ndash; Promoter anlegen, bearbeiten, deaktivieren. Jeder Promoter wird mit einem WordPress-Benutzer verkn&uuml;pft und erh&auml;lt einen eindeutigen Promoter-Code.',
                '<strong>Tab &bdquo;Events&ldquo;</strong> &ndash; Promoter einem Event zuordnen mit individueller Provision (Prozent oder Festbetrag), optionalem K&auml;ufer-Rabatt und Promo-Code. Bei Promo-Code wird automatisch ein WooCommerce-Coupon erstellt.',
                '<strong>Tab &bdquo;Provisionen&ldquo;</strong> &ndash; Alle berechneten Provisionen mit Filtern nach Promoter, Event, Zeitraum und Status (ausstehend, genehmigt, bezahlt, storniert).',
                '<strong>Tab &bdquo;Auszahlungen&ldquo;</strong> &ndash; Abrechnungszeitr&auml;ume erstellen, als bezahlt markieren, stornieren. CSV-Export f&uuml;r Buchhaltung.',
                '<strong>Tab &bdquo;Statistiken&ldquo;</strong> &ndash; KPIs (Gesamtumsatz, Provision, ausstehend, aktive Promoter) und Top-Promoter Chart.',
                '<strong>Event-Metabox</strong> &ndash; Auf der Event-Bearbeitungsseite werden zugeordnete Promoter angezeigt (read-only).',
            ]);

            // ── Referral-Tracking ──
            self::function_card('Referral-Tracking &amp; Attribution', 'dashicons-admin-links', [
                '<strong>Referral-Link:</strong> <code>https://deine-seite.de/event/.../?ref=PROMOTER-CODE</code> &ndash; Setzt ein Cookie (30 Tage) + WC-Session.',
                '<strong>Promo-Code:</strong> K&auml;ufer gibt den Code im Checkout ein &rarr; WC-Coupon wird erkannt &rarr; Attribution wird gesetzt.',
                '<strong>Priorit&auml;t der Attribution:</strong> 1. Promo-Code (explizit, h&ouml;chste Priorit&auml;t), 2. Cookie (persistent, 30 Tage), 3. Session (aktueller Besuch).',
                'Bei Referral-Link ohne Promo-Code: Rabatt wird automatisch als WC Cart Fee abgezogen (falls konfiguriert).',
                'Attribution wird pro Cart-Item gespeichert via <code>woocommerce_add_cart_item_data</code>.',
            ]);

            // ── WooCommerce-Integration ──
            self::function_card('WooCommerce-Integration', 'dashicons-cart', [
                '<strong>Order-Meta (HPOS-kompatibel):</strong> <code>_tix_promoter_id</code>, <code>_tix_promoter_code</code>, <code>_tix_promoter_attribution</code> werden bei Checkout gespeichert.',
                '<strong>Provisionsberechnung:</strong> Hook <code>woocommerce_order_status_completed</code> (Priorit&auml;t 15, nach Ticket-Erstellung). Pro Line-Item wird die Provision berechnet und in die Datenbank geschrieben.',
                '<strong>Stornierung:</strong> Bei <code>woocommerce_order_status_cancelled</code> oder <code>woocommerce_order_status_refunded</code> werden die zugeh&ouml;rigen Provisionen auf <code>cancelled</code> gesetzt.',
                '<strong>WC-Coupon:</strong> Bei Event-Zuordnung mit Promo-Code + Rabatt wird automatisch ein <code>shop_coupon</code>-Post erstellt (Produktbeschr&auml;nkung auf zugeh&ouml;rige WC-Produkte).',
            ]);

            // ── Frontend-Dashboard ──
            self::function_card('Frontend-Dashboard <small>(Shortcode)</small>', 'dashicons-dashboard', [
                '<strong>Shortcode:</strong> <code>[tix_promoter_dashboard]</code> &ndash; Auf einer beliebigen Seite einf&uuml;gen.',
                'Login-Gate: Nicht eingeloggte Benutzer sehen ein Login-Formular. Benutzer ohne Promoter-Rolle sehen eine Fehlermeldung.',
                '<strong>Tab &bdquo;&Uuml;bersicht&ldquo;</strong> &ndash; KPI-Cards (Gesamtumsatz, Provision, ausstehend, aktive Events) und Umsatz-Chart.',
                '<strong>Tab &bdquo;Meine Events&ldquo;</strong> &ndash; Zugewiesene Events mit Referral-Links und Promo-Codes (Copy-to-Clipboard).',
                '<strong>Tab &bdquo;Verk&auml;ufe&ldquo;</strong> &ndash; Letzte zugeordnete Bestellungen mit Datumsfilter.',
                '<strong>Tab &bdquo;Provisionen&ldquo;</strong> &ndash; Provisionshistorie mit Status-Badges.',
                '<strong>Tab &bdquo;Auszahlungen&ldquo;</strong> &ndash; Auszahlungshistorie.',
            ]);

            // ── Datenbank-Tabellen ──
            self::function_card('Datenbank-Tabellen', 'dashicons-database', [
                '<code>wp_tixomat_promoters</code> &ndash; Promoter-Stammdaten (user_id, promoter_code, display_name, status, notes).',
                '<code>wp_tixomat_promoter_events</code> &ndash; Zuordnung Promoter &harr; Event (commission_type, commission_value, discount_type, discount_value, promo_code, coupon_id, status). UNIQUE KEY auf (promoter_id, event_id).',
                '<code>wp_tixomat_promoter_commissions</code> &ndash; Berechnete Provisionen pro Order-Line-Item (promoter_id, event_id, order_id, attribution, tickets_qty, order_total, commission_amount, discount_amount, status, payout_id).',
                '<code>wp_tixomat_promoter_payouts</code> &ndash; Abrechnungen (promoter_id, period_from, period_to, total_sales, total_commission, commission_count, status, paid_date, payment_note).',
            ]);

            // ── AJAX-Endpoints ──
            self::function_card('AJAX-Endpoints', 'dashicons-rest-api', [
                '<strong>Admin (manage_options):</strong> <code>tix_promoter_save</code>, <code>tix_promoter_delete</code>, <code>tix_promoter_list</code>, <code>tix_promoter_search_users</code>, <code>tix_promoter_assign</code>, <code>tix_promoter_unassign</code>, <code>tix_promoter_assignments</code>, <code>tix_promoter_commissions</code>, <code>tix_promoter_create_payout</code>, <code>tix_promoter_mark_paid</code>, <code>tix_promoter_cancel_payout</code>, <code>tix_promoter_payouts</code>, <code>tix_promoter_stats</code>, <code>tix_promoter_generate_code</code>.',
                '<strong>Frontend (tix_promoter):</strong> <code>tix_pd_overview</code>, <code>tix_pd_events</code>, <code>tix_pd_sales</code>, <code>tix_pd_commissions</code>, <code>tix_pd_payouts</code>.',
                '<strong>CSV-Export:</strong> <code>admin_post_tix_promoter_export_csv</code> (GET, mit Nonce).',
            ]);

            // ── Provisions-Modelle ──
            self::function_card('Provisions-Modelle', 'dashicons-chart-pie', [
                '<strong>Prozent:</strong> Provision = Bestellbetrag &times; Provisionssatz / 100. Beispiel: 10% von 50 &euro; = 5 &euro; Provision.',
                '<strong>Festbetrag:</strong> Provision = Festbetrag &times; Ticket-Anzahl. Beispiel: 2,50 &euro; &times; 3 Tickets = 7,50 &euro; Provision.',
                '<strong>K&auml;ufer-Rabatt:</strong> Optional pro Event-Zuordnung. Wird als WC-Coupon (bei Promo-Code) oder als Cart Fee (bei Referral-Link) angewendet.',
                '<strong>Status-Flow:</strong> pending &rarr; approved &rarr; paid (oder cancelled bei Storno).',
            ]);
            ?>

        </div>
        <?php
    }

    // ══════════════════════════════════════
    // TAB 7: VERANSTALTER-DASHBOARD
    // ══════════════════════════════════════

    private static function render_organizer_tab() {
        ?>
        <div class="tix-pane" data-pane="organizer">

            <p class="description">
                <strong>Veranstalter-Dashboard</strong> &ndash; Frontend-Dashboard f&uuml;r externe Veranstalter.
                Erm&ouml;glicht Event-Erstellung, -Bearbeitung und -Verwaltung <strong>ohne wp-admin-Zugang</strong>.
                Jeder Veranstalter sieht nur seine eigenen Events und Bestellungen.
            </p>

            <?php
            // ── Aktivierung ──
            self::function_card('Aktivierung &amp; Einrichtung', 'dashicons-admin-plugins', [
                'Unter <strong>Tixomat &rarr; Einstellungen &rarr; Features</strong> den Schalter <strong>&bdquo;Veranstalter-Dashboard&ldquo;</strong> aktivieren.',
                'Neue WP-Rolle <code>tix_organizer</code> wird automatisch registriert (Rechte: <code>read</code>, <code>upload_files</code>).',
                'Einen <strong>tix_organizer</strong> CPT-Eintrag anlegen und unter &bdquo;Verkn&uuml;pfter Benutzer&ldquo; den WP-User zuweisen.',
                'Shortcode <code>[tix_organizer_dashboard]</code> auf einer beliebigen WordPress-Seite einf&uuml;gen (z.B. <code>/veranstalter/</code>).',
            ]);

            // ── Shortcode ──
            self::function_card('Shortcode', 'dashicons-shortcode', [
                '<code>[tix_organizer_dashboard]</code> &ndash; Zeigt das komplette Veranstalter-Dashboard an.',
                '<strong>Login-Gate:</strong> Nicht eingeloggte Benutzer sehen ein Login-Formular.',
                '<strong>Kein Zugang:</strong> Benutzer ohne Veranstalter-Verkn&uuml;pfung sehen eine Fehlermeldung.',
                '<strong>Veranstalter:</strong> Eingeloggter und verkn&uuml;pfter User sieht das vollst&auml;ndige Dashboard.',
            ]);

            // ── User-Mapping ──
            self::function_card('User-to-Organizer Mapping', 'dashicons-admin-users', [
                '<strong>Meta-Feld:</strong> <code>_tix_org_user_id</code> auf dem <code>tix_organizer</code> CPT verkn&uuml;pft einen WP-User mit einem Veranstalter.',
                '<strong>Admin-UI:</strong> Im Veranstalter-Editor erscheint ein Dropdown &bdquo;Verkn&uuml;pfter Benutzer&ldquo; mit allen WP-Usern.',
                '<strong>Ownership-Chain:</strong> WP User &rarr; tix_organizer (via <code>_tix_org_user_id</code>) &rarr; event (via <code>_tix_organizer_id</code>) &rarr; WC Products &rarr; WC Orders.',
                '<strong>Isolation:</strong> Jeder Veranstalter sieht nur seine eigenen Events, Bestellungen und Statistiken.',
            ]);

            // ── Dashboard-Tabs ──
            self::function_card('Dashboard-Tabs (6 Tabs)', 'dashicons-dashboard', [
                '<strong>&Uuml;bersicht</strong> &ndash; KPI-Cards (Events, Tickets verkauft, Umsatz, Auslastung) + 30-Tage-Verkaufschart.',
                '<strong>Meine Events</strong> &ndash; Event-Liste als Karten (Status-Badge, Datum, Kapazit&auml;t). Neues Event (Wizard), Bearbeiten (Editor-Overlay), Duplizieren, L&ouml;schen.',
                '<strong>Bestellungen</strong> &ndash; Tabelle aller Bestellungen f&uuml;r eigene Events. Filter nach Datum und Event.',
                '<strong>G&auml;steliste</strong> &ndash; Manuelle G&auml;ste + verkaufte Tickets kombiniert. Check-In per Toggle-Button.',
                '<strong>Statistiken</strong> &ndash; KPIs + Verkaufschart mit Event-Filter (Dropdown).',
                '<strong>Einstellungen</strong> &ndash; Profil (Anzeigename &auml;ndern).',
            ]);

            // ── Event-Editor ──
            self::function_card('Event-Editor (Fullscreen-Overlay)', 'dashicons-edit', [
                '<strong>Wizard-Modus</strong> (neue Events): 3 Schritte &ndash; Grunddaten (Titel, Datum, Uhrzeit) &rarr; Tickets (Name, Preis, Menge) &rarr; Zusammenfassung.',
                '<strong>Editor-Modus</strong> (bestehende Events): 9 Tabs &ndash; Grunddaten, Info, Tickets, Medien, FAQ, Rabattcodes, Gewinnspiel, Programm, Vorverkauf.',
                'Neues Event wird als <code>draft</code> gespeichert (optional: Auto-Publish per Setting <code>organizer_auto_publish</code>).',
                '<strong>Repeater-Felder:</strong> Tickets, FAQ, Rabattcodes, Gewinnspiel-Preise, B&uuml;hnen (hinzuf&uuml;gen/entfernen).',
                '<strong>Media-Upload:</strong> Featured Image und Video-URL direkt im Editor.',
            ]);

            // ── AJAX-Endpoints ──
            self::function_card('AJAX-Endpoints (16 Endpunkte)', 'dashicons-rest-api', [
                '<strong>Dashboard:</strong> <code>tix_od_overview</code> (KPIs + Chart), <code>tix_od_stats</code> (Statistiken mit Event-Filter), <code>tix_od_profile</code> (Profil speichern).',
                '<strong>Events:</strong> <code>tix_od_events</code> (Liste), <code>tix_od_event_detail</code> (Einzelnes Event laden), <code>tix_od_save_event</code> (Erstellen/Bearbeiten), <code>tix_od_delete_event</code> (Papierkorb), <code>tix_od_duplicate_event</code> (Duplizieren).',
                '<strong>Bestellungen:</strong> <code>tix_od_orders</code> (Liste), <code>tix_od_order_detail</code> (Details).',
                '<strong>G&auml;steliste:</strong> <code>tix_od_guestlist</code> (Laden), <code>tix_od_guestlist_save</code> (Speichern), <code>tix_od_checkin</code> (Check-In Toggle).',
                '<strong>Medien:</strong> <code>tix_od_upload_media</code> (Bild-Upload via <code>media_handle_upload</code>).',
                '<strong>Extras:</strong> <code>tix_od_save_discount</code> (Rabattcodes &rarr; WC_Coupons), <code>tix_od_raffle_draw</code> (Gewinnspiel auslosen).',
                '<strong>Security:</strong> Alle Endpoints nutzen <code>ajax_guard()</code> (Nonce + Login + Organizer-Lookup). Ownership-Check bei jedem Event-Zugriff.',
            ]);

            // ── Settings ──
            self::function_card('Einstellungen', 'dashicons-admin-generic', [
                '<code>organizer_dashboard_enabled</code> &ndash; Dashboard global aktivieren/deaktivieren (Default: 0).',
                '<code>organizer_auto_publish</code> &ndash; Events automatisch ver&ouml;ffentlichen statt als Draft speichern (Default: 0).',
            ]);

            // ── Dateien ──
            self::function_card('Dateien', 'dashicons-media-code', [
                '<code>includes/class-tix-organizer-dashboard.php</code> &ndash; Hauptklasse: Shortcode, alle 16 AJAX-Endpoints, Rendering.',
                '<code>assets/css/organizer-dashboard.css</code> &ndash; Dashboard-Styling (Prefix: <code>.tix-od-*</code>).',
                '<code>assets/js/organizer-dashboard.js</code> &ndash; Tab-Navigation, AJAX-Calls, Event-Karten, KPIs.',
                '<code>assets/css/organizer-event-editor.css</code> &ndash; Event-Editor-Styling (Prefix: <code>.tix-oe-*</code>).',
                '<code>assets/js/organizer-event-editor.js</code> &ndash; Event-Editor: Wizard + Vollst&auml;ndiger Editor mit 9 Tabs.',
            ]);
            ?>

        </div>
        <?php
    }

    // ══════════════════════════════════════
    // TAB 8: TICKET-BOT
    // ══════════════════════════════════════


    // ══════════════════════════════════════
    // TAB: DATENMODELL (CPTs + Taxonomien + DB-Tabellen)
    // ══════════════════════════════════════

    private static function render_datamodel_tab() {
        // CPTs
        $all_cpts = get_post_types(['_builtin' => false], 'objects');
        $cpts = array_filter($all_cpts, function($pt){
            return strpos($pt->name, 'tix_') === 0 || $pt->name === 'event';
        });
        // Taxonomien
        $all_tax = get_taxonomies(['_builtin' => false], 'objects');
        $taxes = array_filter($all_tax, function($t){
            return strpos($t->name, 'tix_') === 0 || strpos($t->name, 'event_') === 0;
        });
        // DB-Tabellen
        global $wpdb;
        $tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}tix_%'");
        sort($tables);
        ?>
        <div class="tix-pane" data-pane="datamodel">

            <p class="description">
                Live-Scan der registrierten Tixomat-Datentypen. Quellen: <code>get_post_types()</code>, <code>get_taxonomies()</code>, <code>SHOW TABLES</code>.
                Immer aktuell — zeigt exakt was auf dieser Installation aktiv ist.
            </p>

            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:13px;color:#075985;">
                <strong><?php echo count($cpts); ?> Custom Post Types</strong> ·
                <strong><?php echo count($taxes); ?> Taxonomien</strong> ·
                <strong><?php echo count($tables); ?> DB-Tabellen</strong>
            </div>

            <?php // ── Custom Post Types ── ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-admin-post"></span>
                    <h3>Custom Post Types</h3>
                </div>
                <div class="tix-card-body">
                    <table class="tix-tbl" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:20%;">Name</th>
                                <th style="width:20%;">Label</th>
                                <th style="width:10%;">Public</th>
                                <th style="width:10%;">Show UI</th>
                                <th>Supports</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cpts as $pt):
                                $supports = get_all_post_type_supports($pt->name);
                            ?>
                            <tr class="tix-row">
                                <td><code style="font-size:11px;"><?php echo esc_html($pt->name); ?></code></td>
                                <td style="font-size:13px;"><?php echo esc_html($pt->labels->singular_name ?? ''); ?></td>
                                <td><?php echo $pt->public ? '✓' : '—'; ?></td>
                                <td><?php echo $pt->show_ui ? '✓' : '—'; ?></td>
                                <td style="font-size:11px;color:#64748b;"><?php echo esc_html(implode(', ', array_keys($supports))); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php // ── Taxonomien ── ?>
            <?php if (!empty($taxes)): ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-category"></span>
                    <h3>Taxonomien</h3>
                </div>
                <div class="tix-card-body">
                    <table class="tix-tbl" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:25%;">Name</th>
                                <th style="width:25%;">Label</th>
                                <th>Post Types</th>
                                <th style="width:15%;">Hierarchisch</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($taxes as $tx): ?>
                            <tr class="tix-row">
                                <td><code style="font-size:11px;"><?php echo esc_html($tx->name); ?></code></td>
                                <td style="font-size:13px;"><?php echo esc_html($tx->labels->singular_name ?? ''); ?></td>
                                <td style="font-size:11px;color:#64748b;"><?php echo esc_html(implode(', ', (array) $tx->object_type)); ?></td>
                                <td><?php echo $tx->hierarchical ? '✓' : '—'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php // ── DB-Tabellen ── ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-database"></span>
                    <h3>Custom DB-Tabellen (<code><?php echo esc_html($wpdb->prefix); ?>tix_*</code>)</h3>
                </div>
                <div class="tix-card-body">
                    <?php if (empty($tables)): ?>
                        <p style="color:#94a3b8;">Keine Tixomat-Tabellen gefunden.</p>
                    <?php endif; ?>
                    <?php foreach ($tables as $t):
                        $cols = $wpdb->get_results("DESCRIBE `" . esc_sql($t) . "`");
                        $rows_count = intval($wpdb->get_var("SELECT COUNT(*) FROM `" . esc_sql($t) . "`"));
                    ?>
                    <details style="margin-bottom:12px;border:1px solid #e5e7eb;border-radius:8px;">
                        <summary style="padding:12px 16px;cursor:pointer;user-select:none;background:#f9fafb;border-radius:8px;font-family:monospace;font-size:13px;display:flex;justify-content:space-between;">
                            <span><strong><?php echo esc_html($t); ?></strong></span>
                            <span style="color:#64748b;font-size:11px;"><?php echo count($cols); ?> Spalten · <?php echo number_format($rows_count, 0, ',', '.'); ?> Rows</span>
                        </summary>
                        <div style="padding:12px 16px;">
                            <table class="tix-tbl" style="width:100%;font-size:12px;">
                                <thead>
                                    <tr>
                                        <th style="width:25%;">Spalte</th>
                                        <th style="width:20%;">Typ</th>
                                        <th style="width:8%;">Null</th>
                                        <th style="width:8%;">Key</th>
                                        <th>Default</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cols as $c): ?>
                                    <tr class="tix-row">
                                        <td style="font-family:monospace;"><?php echo esc_html($c->Field); ?></td>
                                        <td style="font-family:monospace;color:#6366f1;"><?php echo esc_html($c->Type); ?></td>
                                        <td><?php echo esc_html($c->Null); ?></td>
                                        <td><?php echo esc_html($c->Key ?: '—'); ?></td>
                                        <td style="font-family:monospace;color:#64748b;"><?php echo esc_html($c->Default ?? 'NULL'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
        <?php
    }

    // ══════════════════════════════════════
    // TAB: HOOKS (Actions + Filters grep aus Source)
    // ══════════════════════════════════════

    private static function render_hooks_tab() {
        $scan = self::scan_hooks();
        ?>
        <div class="tix-pane" data-pane="hooks">

            <p class="description">
                Action- und Filter-Hooks die vom Plugin <strong>emittiert</strong> werden (<code>do_action()</code> / <code>apply_filters()</code>).
                Auto-gescannt aus den Quellcode-Dateien. Extension-Points für eigene Erweiterungen.
            </p>

            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:13px;color:#075985;">
                <strong><?php echo count($scan['actions']); ?> Actions</strong> ·
                <strong><?php echo count($scan['filters']); ?> Filter</strong>
                gefunden · Scan-Dauer: ~<?php echo intval($scan['duration_ms']); ?> ms · Dateien: <?php echo $scan['files_count']; ?>
            </div>

            <?php // ── Actions ── ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-bell"></span>
                    <h3>Actions (<code>do_action()</code>)</h3>
                </div>
                <div class="tix-card-body">
                    <table class="tix-tbl" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:40%;">Action-Name</th>
                                <th>Datei(en)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($scan['actions'] as $name => $files): ?>
                            <tr class="tix-row">
                                <td><code style="font-size:12px;background:rgba(99,102,241,0.08);padding:2px 6px;border-radius:4px;"><?php echo esc_html($name); ?></code></td>
                                <td style="font-family:monospace;font-size:11px;color:#64748b;"><?php echo esc_html(implode(', ', $files)); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php // ── Filters ── ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-filter"></span>
                    <h3>Filter (<code>apply_filters()</code>)</h3>
                </div>
                <div class="tix-card-body">
                    <table class="tix-tbl" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:40%;">Filter-Name</th>
                                <th>Datei(en)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($scan['filters'] as $name => $files): ?>
                            <tr class="tix-row">
                                <td><code style="font-size:12px;background:rgba(16,185,129,0.08);padding:2px 6px;border-radius:4px;"><?php echo esc_html($name); ?></code></td>
                                <td style="font-family:monospace;font-size:11px;color:#64748b;"><?php echo esc_html(implode(', ', $files)); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
        <?php
    }

    /** Scannt alle Plugin-Dateien nach do_action() und apply_filters() Calls */
    private static function scan_hooks() {
        $cache = get_transient('tix_docs_hooks_scan');
        if ($cache && !isset($_GET['rescan'])) return $cache;

        $start = microtime(true);
        $actions = [];
        $filters = [];
        $files = glob(TIXOMAT_PATH . 'includes/*.php');
        $plugin_main = TIXOMAT_PATH . 'tixomat.php';
        if (file_exists($plugin_main)) $files[] = $plugin_main;

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if (!$content) continue;
            $shortname = basename($file);

            if (preg_match_all('/do_action\s*\(\s*[\'"]([a-z_][a-z0-9_]*)[\'"]/i', $content, $m)) {
                foreach ($m[1] as $name) {
                    if (strpos($name, 'tix') !== 0 && strpos($name, 'wp_ajax') !== 0) continue;
                    if (strpos($name, 'wp_ajax') === 0) continue; // Die sind im AJAX-Tab
                    if (!isset($actions[$name])) $actions[$name] = [];
                    if (!in_array($shortname, $actions[$name], true)) $actions[$name][] = $shortname;
                }
            }
            if (preg_match_all('/apply_filters\s*\(\s*[\'"]([a-z_][a-z0-9_]*)[\'"]/i', $content, $m)) {
                foreach ($m[1] as $name) {
                    if (strpos($name, 'tix') !== 0) continue;
                    if (!isset($filters[$name])) $filters[$name] = [];
                    if (!in_array($shortname, $filters[$name], true)) $filters[$name][] = $shortname;
                }
            }
        }
        ksort($actions);
        ksort($filters);

        $result = [
            'actions'     => $actions,
            'filters'     => $filters,
            'files_count' => count($files),
            'duration_ms' => (microtime(true) - $start) * 1000,
        ];
        set_transient('tix_docs_hooks_scan', $result, HOUR_IN_SECONDS);
        return $result;
    }

    private static function render_bot_tab() {
        ?>
        <div class="tix-pane" data-pane="bot">

            <p class="description">
                Der <strong>Ticket-Bot</strong> ist ein KI-gest&uuml;tzter Chatbot (Claude AI), der &uuml;ber drei Kan&auml;le erreichbar ist:
                <strong>Webchat</strong> (auf der Website), <strong>Telegram</strong> und <strong>WhatsApp</strong>.
                Er besteht aus drei MU-Plugins (WordPress-Seite) und einem Python-Backend (PythonAnywhere).
            </p>

            <?php
            // ── Architektur ──
            self::function_card('Architektur &amp; Komponenten', 'dashicons-networking', [
                '<strong>MU-Plugin:</strong> <code>mu-plugins/tix-bot-api.php</code> &ndash; WordPress REST API f&uuml;r das Bot-Backend (Event-Daten, Ticket-Lookup, Checkout-URLs).',
                '<strong>MU-Plugin:</strong> <code>mu-plugins/tix-bot-chat.php</code> &ndash; Frontend Chat-Widget &amp; Embedded Chat (Shortcodes, AJAX f&uuml;r WC-Warenkorb).',
                '<strong>MU-Plugin:</strong> <code>mu-plugins/tix-bot-admin.php</code> &ndash; Admin-Dashboard (Live-Gespr&auml;che, Statistiken, Suche, Einstellungen).',
                '<strong>Python-Backend:</strong> Flask-App auf PythonAnywhere &ndash; Anthropic Claude AI, Session-Management (SQLite), Multi-Channel-Routing.',
            ]);

            // ── Kanäle ──
            self::function_card('Unterst&uuml;tzte Kan&auml;le', 'dashicons-share', [
                '<strong>Webchat:</strong> Chat-ID-Prefix <code>web_</code> &ndash; Kommunikation &uuml;ber REST API (<code>/chat/init</code>, <code>/chat/send</code>, <code>/chat/action</code>). WooCommerce-Warenkorb-Integration.',
                '<strong>Telegram:</strong> Chat-ID-Prefix <code>tg_</code> &ndash; Webhook-basiert. Unterst&uuml;tzt Text, Inline-Keyboards und Sprachnachrichten (Whisper-Transkription).',
                '<strong>WhatsApp:</strong> Chat-ID-Prefix <code>wa_</code> &ndash; Webhook-basiert (Meta Business API). Unterst&uuml;tzt Text, Interactive Buttons/Lists und Sprachnachrichten.',
                'Alle Kan&auml;le nutzen denselben <strong>Unified Handler</strong> &ndash; konsistentes Bot-Verhalten &uuml;ber alle Plattformen.',
            ]);

            // ── Shortcodes ──
            self::function_card('Shortcodes', 'dashicons-shortcode', [
                '<code>[tix_chat]</code> &ndash; Eingebetteter Chat (Parameter: <code>height</code>, Standard <code>700px</code>). Ideal f&uuml;r dedizierte Chat-Seiten.',
                '<code>[tix_chat_widget]</code> &ndash; Schwebendes Chat-Widget (unten rechts). Klick &ouml;ffnet Popup-Fenster.',
                '<strong>Auto-Widget:</strong> Wenn in den Bot-Einstellungen aktiviert (<code>tix_auto_widget</code>), wird <code>[tix_chat_widget]</code> automatisch auf allen Frontend-Seiten eingebunden.',
            ]);

            // ── WordPress REST API (tix-bot/v1) ──
            self::meta_card('WordPress REST API <small>(tix-bot/v1)</small>', 'dashicons-rest-api', [
                ['GET /events', 'Alle kommenden Events mit Ticket-Kategorien, Preisen, Verf&uuml;gbarkeit', 'Auth: X-Bot-Secret'],
                ['GET /event/{id}', 'Einzelnes Event nach Post-ID', 'Auth: X-Bot-Secret'],
                ['POST /tickets/lookup', 'Ticket-Suche per E-Mail + Verifizierung (order_id oder last_name). Rate-Limit: 5 Versuche / 15 Min', 'Auth: X-Bot-Secret'],
                ['POST /cart/checkout-url', 'Checkout-URL generieren aus Produkt-IDs + Mengen', 'Auth: X-Bot-Secret'],
                ['GET /customer/exists', 'Pr&uuml;fen ob ein Kunde existiert (per E-Mail)', 'Auth: X-Bot-Secret'],
            ]);

            // ── Bot-Backend API ──
            self::meta_card('Bot-Backend API <small>(Python/Flask)</small>', 'dashicons-cloud', [
                ['POST /chat/init', 'Chat-Session initialisieren (visitor_id, optional WP-User-Daten)', 'Webchat'],
                ['POST /chat/send', 'Nachricht senden (chat_id, message, optional wc_cart)', 'Webchat'],
                ['POST /chat/action', 'Button-Callback verarbeiten (chat_id, callback)', 'Webchat'],
                ['POST /webhook', 'Telegram-Webhook (Text, Sprachnachrichten, Inline-Keyboard-Callbacks)', 'Telegram'],
                ['GET+POST /whatsapp', 'WhatsApp-Webhook (Verification + Nachrichten)', 'WhatsApp'],
                ['GET /health', 'Bot-Status, aktive Sessions, Event-Count, Cache-Alter', '&Ouml;ffentlich'],
            ]);

            // ── Admin API ──
            self::meta_card('Admin-Backend API <small>(via AJAX-Proxy)</small>', 'dashicons-admin-network', [
                ['GET /admin/sessions', 'Aktive Sessions auflisten (Filter: ?channel=web|telegram|whatsapp)', 'Admin'],
                ['GET /admin/conversation/{id}', 'Gespr&auml;chsverlauf einer aktiven Session laden', 'Admin'],
                ['GET /admin/history/{id}', 'Archiviertes Gespr&auml;ch laden', 'Admin'],
                ['POST /admin/reply', 'Admin-Antwort an Kunden senden (alle Kan&auml;le). Parameter: message, human_mode', 'Admin'],
                ['GET /admin/stats', 'Statistiken (KPIs, Charts, Top-Suchanfragen, Top-Events). Parameter: ?days=N', 'Admin'],
                ['GET /admin/search', 'Volltextsuche &uuml;ber alle Sessions + Archiv. Parameter: ?q=term', 'Admin'],
                ['GET /admin/notifications', 'Neue Nachrichten seit Zeitstempel. Parameter: ?since=ISO', 'Admin'],
                ['GET+POST /admin/config', 'Bot-Konfiguration lesen/aktualisieren', 'Admin'],
            ]);

            // ── AJAX Endpoints (WC-Warenkorb) ──
            self::meta_card('AJAX-Endpoints <small>(wp_ajax_ / wp_ajax_nopriv_)</small>', 'dashicons-cart', [
                ['tix_bot_get_cart', 'Aktuellen WooCommerce-Warenkorb abfragen (items, count, total, URLs)', 'GET'],
                ['tix_bot_add_to_cart', 'Einzelnes Produkt zum Warenkorb hinzuf&uuml;gen (product_id, quantity)', 'POST'],
                ['tix_bot_add_batch', 'Mehrere Produkte auf einmal hinzuf&uuml;gen (items als JSON-Array)', 'POST'],
                ['tix_bot_remove_from_cart', 'Produkt aus Warenkorb entfernen (product_id)', 'POST'],
                ['tix_bot_clear_cart', 'Gesamten Warenkorb leeren', 'POST'],
            ]);

            // ── WordPress AJAX (Admin) ──
            self::meta_card('Admin-AJAX-Endpoints <small>(wp_ajax_)</small>', 'dashicons-admin-tools', [
                ['txba_proxy', 'Proxy-Endpunkt: Leitet Admin-Anfragen an das Bot-Backend weiter (vermeidet CORS, h&auml;lt API-Key serverseitig)', 'Admin (manage_woocommerce)'],
                ['txba_save_settings', 'Bot-Einstellungen speichern (auto_widget). Synchronisiert mit Bot-Backend', 'Admin (manage_woocommerce)'],
            ]);

            // ── Admin-Seiten ──
            self::function_card('Admin-Dashboard <small>(Men&uuml;: Ticket-Bot)</small>', 'dashicons-admin-generic', [
                '<strong>Live:</strong> Echtzeit-Gespr&auml;chsansicht mit Zwei-Panel-Layout. Links: Session-Liste (Filter nach Kanal, Auto-Refresh 15s). Rechts: Gespr&auml;chsverlauf mit Admin-Antwortfeld.',
                '<strong>Admin-Antworten:</strong> Direkte Antwort an Kunden &uuml;ber alle Kan&auml;le. <em>Human-Mode</em> Toggle schaltet den Bot f&uuml;r eine Konversation aus.',
                '<strong>Statistiken:</strong> KPI-Cards (aktive Sessions, Nachrichten, Conversion-Rate, Drop-off-Rate), Charts (Nachrichten/Tag, Kanal-Verteilung), Top-Suchanfragen, No-Result-Suchen, Top-Events.',
                '<strong>Suche:</strong> Volltextsuche &uuml;ber alle aktiven und archivierten Gespr&auml;che (Name, E-Mail, Inhalt, Warenkorb).',
                '<strong>Einstellungen:</strong> Bot Health-Check, Kanal-Status, Auto-Widget Toggle, Shortcode-Referenz.',
            ]);

            // ── Session-Tags ──
            self::function_card('Session-Tags &amp; Status', 'dashicons-tag', [
                '<span style="background:#e67e22;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;">HUMAN</span> &ndash; Bot ist deaktiviert, Admin antwortet manuell.',
                '<span style="background:#95a5a6;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;">ARCHIV</span> &ndash; Session ist abgelaufen (nach 24 Stunden Inaktivit&auml;t).',
                '<span style="background:#e74c3c;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;">TICKET</span> &ndash; Kunde hat ein Support-Ticket erstellt.',
                '<span style="background:#3498db;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;">web</span> <span style="background:#27ae60;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;">telegram</span> <span style="background:#25d366;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;">whatsapp</span> &ndash; Kanal-Badges.',
            ]);

            // ── Authentifizierung ──
            self::meta_card('Authentifizierung', 'dashicons-lock', [
                ['X-Bot-Secret', 'Shared Secret zwischen WordPress und Bot-Backend (definiert in wp-config.php als TIX_BOT_API_SECRET)', 'REST API (tix-bot/v1)'],
                ['X-Admin-Key', 'Admin-API-Key f&uuml;r das Bot-Backend (in tix-bot-admin.php definiert)', 'Admin API'],
                ['WordPress Nonce/Cookie', 'Standard-WordPress-Authentifizierung f&uuml;r AJAX', 'WC-Cart AJAX + Admin AJAX'],
                ['Telegram Bot Token', 'Token in Webhook-URL registriert', 'Telegram'],
                ['WhatsApp Verify Token', 'Meta Business API Webhook-Verifizierung', 'WhatsApp'],
            ]);

            // ── Datenbanken (Bot-Backend) ──
            self::function_card('Datenbanken <small>(Bot-Backend, SQLite)</small>', 'dashicons-database', [
                '<code>sessions.db</code> &ndash; Aktive Chat-Sessions (chat_id, Gespr&auml;chsverlauf, Warenkorb, Status, Kundendaten). WAL-Modus f&uuml;r Thread-Sicherheit. Auto-Cleanup nach 24h &rarr; Archivierung.',
                '<code>bot_history.db</code> &ndash; Archiv mit 4 Tabellen:',
                '&nbsp;&nbsp;&bull; <code>conversations</code> &ndash; Archivierte Sessions (Kanal, Name, Verlauf, Warenkorb, Nachrichten-Anzahl)',
                '&nbsp;&nbsp;&bull; <code>daily_stats</code> &ndash; T&auml;gliche Aggregate (Sessions, Nachrichten, Kanal-Aufteilung, Warenkörbe)',
                '&nbsp;&nbsp;&bull; <code>search_queries</code> &ndash; Suchanfragen-Tracking (Query, Ergebnis-Anzahl, Kanal)',
                '&nbsp;&nbsp;&bull; <code>events</code> &ndash; Event-Tracking (Typ, Kanal, Daten)',
            ]);

            // ── WordPress Optionen & Transients ──
            self::meta_card('WordPress-Optionen &amp; Transients', 'dashicons-admin-settings', [
                ['tix_auto_widget', 'Chat-Widget automatisch auf allen Seiten einbinden', 'Option (boolean)'],
                ['tixbot_rl_{md5(email)}', 'Rate-Limit f&uuml;r Ticket-Lookup (max. 5 Versuche / 15 Min)', 'Transient (900s TTL)'],
            ]);

            // ── Konstanten ──
            self::meta_card('Konstanten &amp; Konfiguration', 'dashicons-admin-settings', [
                ['TIX_BOT_SECRET', 'Shared Secret (aus wp-config.php: TIX_BOT_API_SECRET)', 'tix-bot-api.php'],
                ['TIX_BOT_RATE_LIMIT_MAX', '5 Versuche pro E-Mail', 'tix-bot-api.php'],
                ['TIX_BOT_RATE_LIMIT_WINDOW', '900 Sekunden (15 Min)', 'tix-bot-api.php'],
                ['TIX_CHAT_API', 'Bot-Backend URL (PythonAnywhere)', 'tix-bot-chat.php'],
                ['TIX_CHAT_LOGO', 'Logo-Pfad f&uuml;r Chat-Widget', 'tix-bot-chat.php'],
                ['TIX_CHAT_BETA', 'Beta-Badge im Widget anzeigen', 'tix-bot-chat.php'],
                ['TXBA_API', 'Bot-Backend URL f&uuml;r Admin', 'tix-bot-admin.php'],
                ['TXBA_KEY', 'Admin-API-Key f&uuml;r Bot-Backend', 'tix-bot-admin.php'],
            ]);

            // ── localStorage ──
            self::meta_card('Browser-Speicher <small>(localStorage)</small>', 'dashicons-portfolio', [
                ['tix_history_{hostname}', 'Chat-Verlauf (bis zu 100 Nachrichten + chatId) &ndash; pro Domain', 'Frontend Chat'],
                ['tix_visitor', 'Persistente Besucher-ID (einmalig generiert)', 'Frontend Chat'],
                ['txba_n', 'Benachrichtigungs-Toggle (ein/aus)', 'Admin Dashboard'],
            ]);

            // ── Bot-Backend Konfiguration ──
            self::function_card('Bot-Backend Konfiguration <small>(Umgebungsvariablen)</small>', 'dashicons-admin-settings', [
                '<code>TELEGRAM_TOKEN</code> &ndash; Telegram Bot API Token',
                '<code>ANTHROPIC_API_KEY</code> &ndash; Claude AI API-Schl&uuml;ssel',
                '<code>WOOCOMMERCE_URL</code> &ndash; WooCommerce-Site URL (Standard: https://tixomat.de)',
                '<code>WC_CONSUMER_KEY</code> / <code>WC_CONSUMER_SECRET</code> &ndash; WooCommerce REST API Zugangsdaten',
                '<code>WP_BOT_SECRET</code> &ndash; Secret f&uuml;r tix-bot/v1 API',
                '<code>TIX_BOT_API_URL</code> &ndash; WordPress Bot-API Basis-URL',
                '<code>WHATSAPP_TOKEN</code> / <code>WHATSAPP_PHONE_ID</code> &ndash; WhatsApp Business API Zugangsdaten',
                '<code>WEBCHAT_SECRET</code> &ndash; Webchat Authentifizierungs-Secret',
                '<code>ADMIN_API_KEY</code> &ndash; Admin-Dashboard API-Schl&uuml;ssel',
                '<code>OPENAI_API_KEY</code> &ndash; OpenAI-Schl&uuml;ssel f&uuml;r Whisper Spracherkennung',
            ]);

            // ── Benachrichtigungen ──
            self::function_card('Benachrichtigungssystem <small>(Admin)</small>', 'dashicons-bell', [
                'Polling alle <strong>12 Sekunden</strong> auf <code>/admin/notifications</code> f&uuml;r neue Kundennachrichten.',
                'Nutzt die <strong>Browser Notification API</strong> (fragt Berechtigung an).',
                'Zus&auml;tzlich <strong>In-Page Toast-Notifications</strong> (auto-dismiss nach 8 Sekunden).',
                'Klick auf Toast &ouml;ffnet die betreffende Konversation.',
            ]);

            // ── Warenkorb-Integration ──
            self::function_card('WooCommerce Warenkorb-Integration', 'dashicons-cart', [
                'Warenkorb-Status wird alle <strong>15 Sekunden</strong> per AJAX (<code>tix_bot_get_cart</code>) aktualisiert.',
                'Bot-Antworten k&ouml;nnen <code>wc_actions</code> enthalten &ndash; Array mit Aktionen: <code>add</code>, <code>remove</code>, <code>clear</code> (jeweils <code>product_id</code>, <code>quantity</code>).',
                'Aktionen werden <strong>sequenziell</strong> &uuml;ber <code>processWcActions()</code> verarbeitet.',
                'Die <strong>Warenkorb-Leiste</strong> im Chat zeigt Artikelanzahl, Gesamtsumme und &bdquo;Zur Kasse&ldquo;-Button.',
            ]);
            ?>

        </div>
        <?php
    }

    // ══════════════════════════════════════
    // TAB 9: REST API
    // ══════════════════════════════════════


    private static function render_rest_api_tab() {
        $routes = function_exists('rest_get_server') ? rest_get_server()->get_routes() : [];
        $tix_routes = [];
        foreach ($routes as $route => $handlers) {
            // Nur Tixomat-spezifische Routes
            if (strpos($route, '/tix/') === false
                && strpos($route, '/tixomat/') === false
                && strpos($route, '/wp/v2/tix_') === false
                && strpos($route, '/wp/v2/event') === false) continue;
            $tix_routes[$route] = $handlers;
        }
        ksort($tix_routes);
        ?>
        <div class="tix-pane" data-pane="rest-api">

            <p class="description">
                <strong>REST-API-Endpunkte</strong> — Auto-Scan aus <code>rest_get_server()->get_routes()</code>.
                Filtert nur Tixomat-Routes. Basis-URL: <code><?php echo esc_html(get_rest_url()); ?></code>
            </p>

            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:13px;color:#075985;">
                <strong><?php echo count($tix_routes); ?> Tixomat-REST-Endpoints</strong> registriert.
            </div>

            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-rest-api"></span>
                    <h3>Endpoints</h3>
                </div>
                <div class="tix-card-body">
                    <?php if (empty($tix_routes)): ?>
                        <p style="color:#94a3b8;">Keine Tixomat-REST-Routes registriert. Prüfe ob <code>TIX_REST_API::init()</code> geladen ist.</p>
                    <?php endif; ?>
                    <?php foreach ($tix_routes as $route => $handlers):
                        // handlers kann ein Array von Route-Definitionen sein
                        $methods = [];
                        $callbacks = [];
                        $args = [];
                        $permissions = [];
                        foreach ($handlers as $h) {
                            if (!empty($h['methods'])) {
                                foreach ((array) $h['methods'] as $method => $v) {
                                    if ($v) $methods[$method] = true;
                                }
                            }
                            if (!empty($h['callback'])) {
                                $cb = $h['callback'];
                                if (is_array($cb)) {
                                    $class = is_object($cb[0]) ? get_class($cb[0]) : $cb[0];
                                    $callbacks[] = $class . '::' . $cb[1];
                                } elseif (is_string($cb)) {
                                    $callbacks[] = $cb;
                                }
                            }
                            if (!empty($h['permission_callback'])) {
                                $pc = $h['permission_callback'];
                                if ($pc === '__return_true') $permissions[] = 'public';
                                elseif (is_array($pc)) $permissions[] = (is_object($pc[0]) ? get_class($pc[0]) : $pc[0]) . '::' . $pc[1];
                                elseif (is_string($pc)) $permissions[] = $pc;
                            }
                            if (!empty($h['args']) && is_array($h['args'])) {
                                foreach ($h['args'] as $arg_name => $arg_def) {
                                    $args[$arg_name] = $arg_def;
                                }
                            }
                        }
                    ?>
                    <details style="margin-bottom:10px;border:1px solid #e5e7eb;border-radius:8px;">
                        <summary style="padding:12px 14px;cursor:pointer;user-select:none;background:#f9fafb;border-radius:8px;display:flex;justify-content:space-between;align-items:center;gap:12px;">
                            <span style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;">
                                <?php foreach (array_keys($methods) as $method):
                                    $color = ['GET' => '#10b981', 'POST' => '#6366f1', 'PUT' => '#f59e0b', 'PATCH' => '#f59e0b', 'DELETE' => '#ef4444'][$method] ?? '#64748b';
                                ?>
                                    <span style="background:<?php echo $color; ?>;color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;"><?php echo esc_html($method); ?></span>
                                <?php endforeach; ?>
                                <code style="font-family:monospace;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($route); ?></code>
                            </span>
                            <span style="color:#64748b;font-size:11px;"><?php echo count($args); ?> Args</span>
                        </summary>
                        <div style="padding:12px 16px;font-size:12px;">
                            <?php if (!empty($callbacks)): ?>
                                <div style="margin-bottom:8px;"><strong>Callback:</strong> <code><?php echo esc_html(implode(', ', array_unique($callbacks))); ?></code></div>
                            <?php endif; ?>
                            <?php if (!empty($permissions)): ?>
                                <div style="margin-bottom:8px;"><strong>Permission:</strong> <code><?php echo esc_html(implode(', ', array_unique($permissions))); ?></code></div>
                            <?php endif; ?>
                            <?php if (!empty($args)): ?>
                                <div><strong>Args:</strong></div>
                                <table class="tix-tbl" style="width:100%;font-size:11px;margin-top:6px;">
                                    <thead><tr><th>Name</th><th>Typ</th><th>Required</th><th>Default</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($args as $arg_name => $arg_def): ?>
                                        <tr class="tix-row">
                                            <td style="font-family:monospace;"><?php echo esc_html($arg_name); ?></td>
                                            <td style="color:#6366f1;"><?php echo esc_html($arg_def['type'] ?? '—'); ?></td>
                                            <td><?php echo !empty($arg_def['required']) ? '✓' : '—'; ?></td>
                                            <td style="color:#64748b;"><?php echo isset($arg_def['default']) ? esc_html(is_bool($arg_def['default']) ? ($arg_def['default']?'true':'false') : $arg_def['default']) : '—'; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </details>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
        <?php
    }
}
