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
        wp_enqueue_style('tix-admin', TIXOMAT_URL . 'assets/css/admin.css', [], TIXOMAT_VERSION);
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
                <div class="tix-app tix-settings-app">

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
                        <button type="button" class="tix-nav-tab" data-tab="bot">
                            <span class="dashicons dashicons-format-chat"></span>
                            <span class="tix-nav-label">Ticket-Bot</span>
                        </button>
                    </nav>

                    <div class="tix-content">

                        <?php self::render_meta_tab(); ?>
                        <?php self::render_shortcodes_tab(); ?>
                        <?php self::render_functions_tab(); ?>
                        <?php self::render_ajax_tab(); ?>
                        <?php self::render_templates_tab(); ?>
                        <?php self::render_promoter_tab(); ?>
                        <?php self::render_bot_tab(); ?>

                    </div>
                </div>
            </div>
        </div>

        <script>
        (function() {
            'use strict';
            var app = document.querySelector('.tix-settings-app');
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
        ?>
        <div class="tix-pane" data-pane="shortcodes">

            <p class="description">
                <strong>Shortcodes</strong> sind kurze Platzhalter, die du in jede Seite oder jeden Beitrag einf&uuml;gen kannst.
                WordPress ersetzt sie automatisch durch die entsprechende Funktion (z.B. eine Ticketauswahl oder einen Countdown).
                F&uuml;ge den Shortcode einfach als Text-Block in deinen Editor ein.
            </p>

            <?php
            // ── tix_event_page ──
            self::shortcode_card(
                'tix_event_page',
                'Zeigt eine komplette Event-Detailseite an &ndash; mit Hero-Bild, Datum/Ort/Preis, Ticket-Selektor, Beschreibung, Line-Up, Galerie (mit Lightbox), Video, FAQ, Location-Karte, Serientermine und &auml;hnliche Events. <strong>Zeigt nur Sektionen an, die auch Daten enthalten.</strong> Bettet automatisch <code>[tix_ticket_selector]</code>, <code>[tix_faq]</code>, <code>[tix_calendar]</code> und <code>[tix_upsell]</code> ein.',
                [
                    ['id', '(aktuelle Seite)', 'Event-ID. Wenn leer, wird automatisch das Event der aktuellen Seite verwendet.'],
                ],
                '[tix_event_page]',
                '[tix_event_page id="123"]'
            );

            // ── tix_ticket_selector ──
            self::shortcode_card(
                'tix_ticket_selector',
                'Zeigt die komplette Ticketauswahl f&uuml;r ein Event an &ndash; mit Kategorien, Preisen, Mengenauswahl, Kombi-Deals, Gruppenrabatt und Kaufen-Button. Unterst&uuml;tzt dynamische Preisphasen (Early Bird usw.) und zeigt automatisch den aktuellen Preis.',
                [
                    ['id', '(aktuelle Seite)', 'Event-ID. Wenn leer, wird automatisch das Event der aktuellen Seite verwendet.'],
                ],
                '[tix_ticket_selector]',
                '[tix_ticket_selector id="123"]'
            );

            // ── tix_countdown ──
            self::shortcode_card(
                'tix_countdown',
                'Zeigt einen Countdown-Timer an, der bis zum Event-Start herunterz&auml;hlt (Tage, Stunden, Minuten, Sekunden). Versteckt sich automatisch, wenn das Event vorbei ist.',
                [
                    ['id', '(aktuelle Seite)', 'Event-ID. Wenn leer, wird automatisch das Event der aktuellen Seite verwendet.'],
                    ['label', '(leer)', 'Optionaler Text &uuml;ber dem Countdown (z.B. &bdquo;Noch bis zum Event&ldquo;).'],
                ],
                '[tix_countdown]',
                '[tix_countdown id="123" label="Countdown l&auml;uft!"]'
            );

            // ── tix_checkout ──
            self::shortcode_card(
                'tix_checkout',
                'Zeigt das komplette Checkout-Formular an (Rechnungsdaten, Warenkorb, Zahlung). Ersetzt die Standard-WooCommerce-Kasse mit dem Tixomat-Design. Unterst&uuml;tzt Newsletter-Anmeldung, Countdown-Timer und AGB-Checkboxen.',
                [
                    ['terms_url', '(aus Einstellungen)', 'URL zu den AGB. Wenn leer, wird der Wert aus den Plugin-Einstellungen verwendet.'],
                    ['privacy_url', '(aus Einstellungen)', 'URL zur Datenschutzerkl&auml;rung. Wenn leer, wird der Wert aus den Plugin-Einstellungen verwendet.'],
                ],
                '[tix_checkout]',
                '[tix_checkout terms_url="https://meine-seite.de/agb" privacy_url="https://meine-seite.de/datenschutz"]'
            );

            // ── tix_my_tickets ──
            self::shortcode_card(
                'tix_my_tickets',
                'Zeigt dem eingeloggten Benutzer seine gekauften Tickets an &ndash; inklusive QR-Codes, Event-Details und Bestellinformationen. Ber&uuml;cksichtigt auch umgeschriebene Tickets (Ticket-Transfer). Ideal f&uuml;r eine &bdquo;Meine Tickets&ldquo;-Seite im Kundenbereich.',
                [],
                '[tix_my_tickets]',
                null
            );

            // ── tix_calendar ──
            self::shortcode_card(
                'tix_calendar',
                'Zeigt einen &bdquo;Zum Kalender hinzuf&uuml;gen&ldquo;-Button an, &uuml;ber den Besucher das Event in Google Calendar, Apple Kalender oder Outlook speichern k&ouml;nnen. Generiert automatisch eine ICS-Datei und einen Google-Calendar-Link.',
                [
                    ['id', '(aktuelle Seite)', 'Event-ID. Wenn leer, wird das Event der aktuellen Seite verwendet.'],
                    ['class', '(leer)', 'Zus&auml;tzliche CSS-Klasse f&uuml;r eigenes Styling.'],
                ],
                '[tix_calendar]',
                '[tix_calendar id="123" class="mein-style"]'
            );

            // ── tix_upsell ──
            self::shortcode_card(
                'tix_upsell',
                'Zeigt &auml;hnliche oder empfohlene Events an (&bdquo;Das k&ouml;nnte dich auch interessieren&ldquo;). Nutzt die manuell zugewiesenen Events oder zeigt automatisch Events aus der gleichen Kategorie. Zeigt nur zuk&uuml;nftige, ver&ouml;ffentlichte Events.',
                [
                    ['id', '(aktuelle Seite)', 'Event-ID. Wenn leer, wird das aktuelle Event verwendet.'],
                    ['count', '(aus Einstellungen)', 'Anzahl angezeigter Events. Standard: Wert aus den Plugin-Einstellungen.'],
                    ['heading', '(leer)', '&Uuml;berschrift &uuml;ber den empfohlenen Events.'],
                    ['exclude', '(leer)', 'Event-IDs, die nicht angezeigt werden sollen (komma-getrennt).'],
                    ['class', '(leer)', 'Zus&auml;tzliche CSS-Klasse.'],
                ],
                '[tix_upsell]',
                '[tix_upsell count="4" heading="Weitere Events" exclude="10,20"]'
            );

            // ── tix_faq ──
            self::shortcode_card(
                'tix_faq',
                'Zeigt die FAQ (H&auml;ufige Fragen) eines Events als aufklappbares Akkordeon an. Die Fragen werden im Event-Editor unter dem Tab &bdquo;FAQ&ldquo; gepflegt.',
                [
                    ['id', '(aktuelle Seite)', 'Event-ID. Wenn leer, wird das aktuelle Event verwendet.'],
                    ['title', 'H&auml;ufige Fragen', '&Uuml;berschrift &uuml;ber dem FAQ-Bereich.'],
                    ['class', '(leer)', 'Zus&auml;tzliche CSS-Klasse.'],
                    ['wide', '0', 'Auf &bdquo;1&ldquo; setzen f&uuml;r volle Breite ohne Rahmen.'],
                ],
                '[tix_faq]',
                '[tix_faq id="123" title="Wichtige Fragen" wide="1"]'
            );

            // ── tix_checkin ──
            self::shortcode_card(
                'tix_checkin',
                'Zeigt das Check-in-System mit QR-Code-Scanner (Kamera), manueller Code-Eingabe und G&auml;steliste an. Gesch&uuml;tzt durch ein Passwort (wird im Event-Editor eingestellt). Zeigt live, wie viele G&auml;ste bereits eingecheckt sind.',
                [
                    ['event_id', '(leer)', 'Event-ID zum Vorausw&auml;hlen. Wenn gesetzt, ist dieses Event im Dropdown vorausgew&auml;hlt.'],
                ],
                '[tix_checkin]',
                '[tix_checkin event_id="123"]'
            );

            // ── tix_express_checkout ──
            self::shortcode_card(
                'tix_express_checkout',
                'Zeigt einen Express-Checkout-Button, der ein Modal mit Ticket-Auswahl &ouml;ffnet. Im Modal k&ouml;nnen Tickets gew&auml;hlt, die Nutzungsbedingungen akzeptiert und der Kauf direkt abgeschlossen werden &ndash; ohne Warenkorb-Umweg. Wird nur f&uuml;r eingeloggte Nutzer mit gespeicherter Zahlungsmethode und aktiviertem Express-Checkout (global + pro Event) angezeigt.',
                [
                    ['id', '(aktuelle Seite)', 'Event-ID. Wenn leer, wird das Event der aktuellen Seite verwendet.'],
                    ['label', 'Express-Checkout', 'Text auf dem Trigger-Button.'],
                ],
                '[tix_express_checkout]',
                '[tix_express_checkout id="123" label="Jetzt kaufen"]'
            );

            // ── tix_ticket_transfer ──
            self::shortcode_card(
                'tix_ticket_transfer',
                'Zeigt ein Formular zur Ticket-Umschreibung. Der K&auml;ufer gibt Bestellnummer und E-Mail ein, w&auml;hlt Tickets per Checkbox aus und tr&auml;gt den neuen Inhaber ein. Der neue Inhaber erh&auml;lt automatisch ein Konto und eine E-Mail-Benachrichtigung. Umgeschriebene Tickets erscheinen unter &bdquo;Meine Tickets&ldquo; des neuen Inhabers.',
                [
                    ['id', '(aktuelle Seite)', 'Event-ID. Wenn leer, wird das Event der aktuellen Seite verwendet.'],
                ],
                '[tix_ticket_transfer]',
                '[tix_ticket_transfer id="123"]'
            );

            // ── tix_series_dates ──
            self::shortcode_card(
                'tix_series_dates',
                'Zeigt auf einer Event-Einzelseite die Geschwister-Termine einer Eventserie an. Listet alle zuk&uuml;nftigen, ver&ouml;ffentlichten Termine der Serie mit Datum und Link zur Ticket-Seite. Wird nur auf Kind-Events (Teil einer Serie) angezeigt.',
                [
                    ['id', '(aktuelle Seite)', 'Event-ID. Wenn leer, wird das aktuelle Event verwendet.'],
                ],
                '[tix_series_dates]',
                '[tix_series_dates id="123"]'
            );

            // ── tix_seatmap ──
            self::shortcode_card(
                'tix_seatmap',
                'Zeigt einen interaktiven Saalplan (Seat Map) als Standalone-Ansicht an. Besucher sehen alle Sektionen farbcodiert mit Preisen und Verf&uuml;gbarkeit. Ideal f&uuml;r eine eigene &bdquo;Saalplan&ldquo;-Seite. F&uuml;r die volle Kauf-Integration nutze stattdessen den <code>[tix_ticket_selector]</code> mit aktiviertem Saalplan im Event.',
                [
                    ['id', '(aus Event-Meta)', 'Saalplan-ID. Wenn leer, wird der dem Event zugewiesene Saalplan verwendet.'],
                    ['event', '(aktuelle Seite)', 'Event-ID. Wenn leer, wird das Event der aktuellen Seite verwendet.'],
                ],
                '[tix_seatmap]',
                '[tix_seatmap event="123"]'
            );

            // ── tix_support ──
            self::shortcode_card(
                'tix_support',
                'Zeigt das Kunden-Support-Portal an. Eingeloggte Benutzer werden automatisch authentifiziert und sehen ihre Anfragen. G&auml;ste geben E-Mail und Bestellnummer zur Anmeldung ein. Erm&ouml;glicht das Erstellen neuer Anfragen, Einsehen bestehender Anfragen und Antworten auf Nachrichten. Voraussetzung: Support-System muss in den Einstellungen aktiviert sein.',
                [],
                '[tix_support]',
                null
            );

            // ── tix_promoter_dashboard ──
            self::shortcode_card(
                'tix_promoter_dashboard',
                'Zeigt das Promoter-Dashboard im Frontend an. Promoter k&ouml;nnen hier ihre zugewiesenen Events, Verk&auml;ufe, Provisionen und Auszahlungen einsehen. Erfordert eingeloggten Benutzer mit Promoter-Status.',
                [],
                '[tix_promoter_dashboard]',
                null
            );

            // ── tix_chat ──
            self::shortcode_card(
                'tix_chat',
                'Bettet den KI-Chatbot als eingebettetes Element auf der Seite ein. Ideal f&uuml;r eine dedizierte Chat-/Support-Seite. Unterst&uuml;tzt WooCommerce-Warenkorb-Integration, Ticket-Suche und Kundenservice.',
                [
                    ['height', '700px', 'CSS-H&ouml;he des Chat-Containers.'],
                ],
                '[tix_chat]',
                '[tix_chat height="500px"]'
            );

            // ── tix_chat_widget ──
            self::shortcode_card(
                'tix_chat_widget',
                'Zeigt ein schwebendes Chat-Widget (Blase) unten rechts an. Klick &ouml;ffnet ein Popup-Chat-Fenster mit voller Bot-Funktionalit&auml;t. Kann &uuml;ber die Bot-Einstellungen auch automatisch auf allen Seiten eingebunden werden (<code>Auto-Widget</code>).',
                [],
                '[tix_chat_widget]',
                null
            );

            // ── tix_raffle ──
            self::shortcode_card(
                'tix_raffle',
                'Zeigt das Gewinnspiel-Formular f&uuml;r ein Event an. Besucher k&ouml;nnen mit Name und E-Mail teilnehmen. Zeigt je nach Status: Teilnahmeformular mit Countdown, geschlossene Meldung oder Gewinnerliste. Wird automatisch auf der Event-Seite eingebettet, kann aber auch separat verwendet werden.',
                [
                    ['id', 'Aktueller Post', 'Event-Post-ID. Muss nur angegeben werden, wenn der Shortcode au&szlig;erhalb der Event-Seite verwendet wird.'],
                ],
                '[tix_raffle]',
                '[tix_raffle id="123"]'
            );
            ?>

        </div>
        <?php
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

            // ── Force Delete & Cleanup ──
            self::function_card('Force Delete &amp; Orphan-Bereinigung', 'dashicons-trash', [
                '<strong>Force Delete:</strong> &Uuml;ber die Row-Action &bdquo;Unwiderruflich l&ouml;schen&ldquo; wird ein Event sofort und permanent gel&ouml;scht.',
                'Dabei werden <strong>alle Hooks deaktiviert</strong> (Cleanup, Series, Sync, Metabox) f&uuml;r volle Kontrolle.',
                'Bei Serien-Mastern: Alle Kinder + deren Produkte werden mit gel&ouml;scht.',
                'Bei Serien-Kindern: Das Kind wird aus dem <code>_tix_series_children</code>-Array des Masters entfernt.',
                '<strong>Orphan-Bereinigung:</strong> &Uuml;ber &bdquo;Verwaiste Daten bereinigen&ldquo; werden 3 Typen aufger&auml;umt:',
                '1. <strong>Event-Kinder</strong> &ndash; Serien-Kinder deren Master nicht mehr existiert',
                '2. <strong>WC-Produkte</strong> &ndash; Produkte deren Eltern-Event nicht mehr existiert',
                '3. <strong>API-Keys</strong> &ndash; API-Keys deren Eltern-Event nicht mehr existiert',
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
        ?>
        <div class="tix-pane" data-pane="ajax">

            <p class="description">
                <strong>AJAX-Endpoints, Admin-Post-Actions, Cron-Hooks und Transients</strong> &ndash; technische Referenz f&uuml;r Entwickler.
            </p>

            <?php
            // ── AJAX Endpoints ──
            self::meta_card('AJAX-Endpoints <small>(wp_ajax_ / wp_ajax_nopriv_)</small>', 'dashicons-rest-api', [
                ['tix_add_to_cart', 'Ticket(s) in den WooCommerce-Warenkorb legen', 'Frontend'],
                ['tix_validate_coupon', 'Gutschein-Code validieren', 'Frontend'],
                ['tix_update_cart', 'Warenkorb aktualisieren (Mengen &auml;ndern)', 'Frontend'],
                ['tix_apply_coupon', 'Gutschein anwenden', 'Frontend'],
                ['tix_remove_coupon', 'Gutschein entfernen', 'Frontend'],
                ['tix_countdown_clear', 'Checkout-Countdown zur&uuml;cksetzen', 'Frontend'],
                ['tix_login', 'Login w&auml;hrend des Checkouts (nur nopriv)', 'Frontend'],
                ['tix_capture_cart_email', 'E-Mail vor Kauf erfassen (Abandoned Cart)', 'Frontend'],
                ['tix_express_checkout', 'Express-Checkout ausf&uuml;hren (nur ajax)', 'Frontend'],
                ['tix_group_create', 'Gruppenbuchung-Session erstellen', 'Frontend'],
                ['tix_group_add', 'Mitglied zur Gruppe hinzuf&uuml;gen', 'Frontend'],
                ['tix_group_status', 'Gruppen-Status abfragen', 'Frontend'],
                ['tix_group_remove', 'Mitglied aus Gruppe entfernen', 'Frontend'],
                ['tix_group_checkout', 'Gruppen-Checkout abschlie&szlig;en', 'Frontend'],
                ['tix_ics', 'ICS-Kalenderdatei generieren', 'Frontend'],
                ['tix_guest_validate', 'G&auml;ste-QR-Code / Ticket-Code validieren &amp; einchecken (unterst&uuml;tzt Teil-Check-in)', 'Frontend'],
                ['tix_guest_list_status', 'G&auml;steliste-Status abfragen (inkl. Teil-Check-in-Z&auml;hler)', 'Frontend'],
                ['tix_guest_update_checkin', 'Check-in-Z&auml;hler eines Gastes anpassen (Partial Check-in &minus;/+)', 'Frontend'],
                ['tix_guest_checkin', 'Gast einchecken', 'Admin'],
                ['tix_guest_send_email', 'E-Mail an einzelnen Gast senden', 'Admin'],
                ['tix_guest_send_all_emails', 'Massen-E-Mail an alle G&auml;ste', 'Admin'],
                ['tix_teilnehmer_csv', 'Teilnehmer als CSV exportieren', 'Admin'],
                ['tix_transfer_lookup', 'Bestellung f&uuml;r Ticket-Transfer suchen', 'Frontend'],
                ['tix_transfer_save', 'Ticket-Transfer ausf&uuml;hren', 'Frontend'],
                ['tix_create_location', 'Neue Location per Modal erstellen (Name, Adresse, Beschreibung)', 'Admin'],
                ['tix_create_organizer', 'Neuen Veranstalter per Modal erstellen (Name, Adresse, Beschreibung)', 'Admin'],
            ]);

            // ── Ticket-Verwaltung AJAX Endpoints ──
            self::meta_card('Ticket-AJAX-Endpoints <small>(wp_ajax_)</small>', 'dashicons-tickets-alt', [
                ['tix_ticket_resend', 'Einzelnes Ticket erneut per E-Mail versenden', 'Admin'],
                ['tix_ticket_resend_order', 'Alle Tickets einer Bestellung erneut versenden', 'Admin'],
                ['tix_ticket_toggle_status', 'Ticket-Status umschalten (valid &harr; cancelled)', 'Admin'],
                ['tix_template_preview', 'Template-Vorschau generieren (PDF-Preview)', 'Admin'],
                ['tix_checkin_combined_list', 'Kombinierte G&auml;ste- und Ticket-Liste f&uuml;r Check-in laden', 'Frontend'],
                ['tix_ticket_toggle_checkin', 'Check-in-Status eines gekauften Tickets umschalten', 'Frontend'],
            ]);

            // ── Saalplan AJAX Endpoints ──
            self::meta_card('Saalplan-AJAX-Endpoints <small>(wp_ajax_ / wp_ajax_nopriv_)</small>', 'dashicons-layout', [
                ['tix_seatmap_save', 'Saalplan speichern + WC-Produkte pro Sektion synchronisieren', 'Admin'],
                ['tix_seatmap_load', 'Saalplan-Daten laden', 'Admin'],
                ['tix_seat_availability', 'Sitzplatz-Verf&uuml;gbarkeit f&uuml;r Event/Saalplan abfragen (inkl. Sektions-Preise)', 'Frontend'],
                ['tix_reserve_seats', 'Sitzpl&auml;tze reservieren (15-Min-Session-Reservierung)', 'Frontend'],
                ['tix_release_seats', 'Sitzplatz-Reservierungen freigeben', 'Frontend'],
                ['tix_best_available', 'Bester verf&uuml;gbarer Platz (Parameter: event_id, seatmap_id, section_id, qty)', 'Frontend'],
            ]);

            // ── Statistik AJAX Endpoints ──
            self::meta_card('Statistik-AJAX-Endpoints <small>(wp_ajax_)</small>', 'dashicons-chart-area', [
                ['tix_stats_overview', 'Statistik: &Uuml;bersicht (KPIs + Charts)', 'Admin'],
                ['tix_stats_revenue', 'Statistik: Umsatz-Analyse', 'Admin'],
                ['tix_stats_tickets', 'Statistik: Ticket-Analyse', 'Admin'],
                ['tix_stats_events', 'Statistik: Event-Analyse', 'Admin'],
                ['tix_stats_checkin', 'Statistik: Check-in-Analyse', 'Admin'],
                ['tix_stats_carts', 'Statistik: Warenk&ouml;rbe-Analyse', 'Admin'],
                ['tix_stats_newsletter', 'Statistik: Newsletter-Analyse', 'Admin'],
                ['tix_stats_discounts', 'Statistik: Rabatt-Analyse', 'Admin'],
                ['tix_stats_export', 'Statistik: CSV-Export (Parameter: tab)', 'Admin'],
            ]);

            // ── Support AJAX Endpoints ──
            self::meta_card('Support-AJAX-Endpoints <small>(wp_ajax_ / wp_ajax_nopriv_)</small>', 'dashicons-format-chat', [
                ['tix_support_search', 'Kunden/Tickets/Bestellungen suchen (Auto-Detect: E-Mail, #Order, 12-stelliger Code)', 'Admin'],
                ['tix_support_list', 'Anfragen-Liste mit Status-/Kategorie-Filter und Suche', 'Admin'],
                ['tix_support_detail', 'Einzelne Anfrage mit Nachrichten-Thread laden', 'Admin'],
                ['tix_support_reply', 'Admin-Antwort senden (+ E-Mail an Kunden)', 'Admin'],
                ['tix_support_note', 'Interne Notiz hinzuf&uuml;gen (nur Admin-sichtbar)', 'Admin'],
                ['tix_support_status', 'Status einer Anfrage &auml;ndern (+ E-Mail bei &rarr; gel&ouml;st)', 'Admin'],
                ['tix_support_resend_ticket', 'Ticket-E-Mail erneut an Kunden senden', 'Admin'],
                ['tix_support_change_owner', 'Ticketinhaber &auml;ndern (Name + E-Mail)', 'Admin'],
                ['tix_support_create', 'Neue Support-Anfrage erstellen', 'Frontend + Admin'],
                ['tix_support_customer_auth', 'Kunden-Authentifizierung (E-Mail + Bestellnr.)', 'Frontend'],
                ['tix_support_customer_list', 'Eigene Anfragen laden (Frontend-Portal)', 'Frontend'],
                ['tix_support_customer_detail', 'Einzelne eigene Anfrage laden (ohne interne Notizen)', 'Frontend'],
                ['tix_support_customer_reply', 'Kunden-Antwort senden (+ E-Mail an Admin)', 'Frontend'],
            ]);

            // ── Sync AJAX Endpoints ──
            self::meta_card('Sync-AJAX-Endpoints <small>(wp_ajax_)</small>', 'dashicons-update', [
                ['tix_sync_test_connection', 'Verbindung zur externen Datenbank testen', 'Admin'],
                ['tix_sync_all', 'Vollst&auml;ndige Synchronisierung aller Events ausl&ouml;sen', 'Admin'],
            ]);

            // ── Bot REST API ──
            self::meta_card('Bot REST API <small>(wp-json/tix-bot/v1)</small>', 'dashicons-format-chat', [
                ['GET /events', 'Alle kommenden Events mit Kategorien, Preisen, Verf&uuml;gbarkeit (max. 50)', 'X-Bot-Secret'],
                ['GET /event/{id}', 'Einzelnes Event nach Post-ID', 'X-Bot-Secret'],
                ['POST /tickets/lookup', 'Ticket-Suche: E-Mail + Verifizierung (order_id/last_name). Rate-Limit: 5/15 Min', 'X-Bot-Secret'],
                ['POST /cart/checkout-url', 'Checkout-URL generieren (items: [{product_id, quantity}])', 'X-Bot-Secret'],
                ['GET /customer/exists', 'Kundenexistenz pr&uuml;fen (?email=)', 'X-Bot-Secret'],
            ]);

            // ── Bot Chat AJAX ──
            self::meta_card('Bot-Chat AJAX-Endpoints <small>(wp_ajax_ / wp_ajax_nopriv_)</small>', 'dashicons-cart', [
                ['tix_bot_get_cart', 'Aktuellen WC-Warenkorb abfragen (items, count, total, URLs)', 'GET'],
                ['tix_bot_add_to_cart', 'Produkt hinzuf&uuml;gen (product_id, quantity)', 'POST'],
                ['tix_bot_add_batch', 'Mehrere Produkte hinzuf&uuml;gen (items: JSON-Array)', 'POST'],
                ['tix_bot_remove_from_cart', 'Produkt entfernen (product_id)', 'POST'],
                ['tix_bot_clear_cart', 'Warenkorb leeren', 'POST'],
            ]);

            // ── Bot Admin AJAX ──
            self::meta_card('Bot-Admin AJAX-Endpoints <small>(wp_ajax_)</small>', 'dashicons-admin-network', [
                ['txba_proxy', 'Proxy: Leitet Admin-Anfragen an Bot-Backend weiter (CORS-frei, API-Key serverseitig)', 'manage_woocommerce'],
                ['txba_save_settings', 'Bot-Einstellungen speichern (auto_widget) + Sync mit Backend', 'manage_woocommerce'],
            ]);

            // ── Admin Post Actions ──
            self::meta_card('Admin-Post-Actions <small>(admin_post_)</small>', 'dashicons-admin-links', [
                ['tix_duplicate_event', 'Event komplett duplizieren (als Entwurf)', 'Admin'],
                ['tix_force_delete_event', 'Event permanent l&ouml;schen inkl. aller Abh&auml;ngigkeiten', 'Admin'],
                ['tix_cleanup_orphans', 'Verwaiste Daten bereinigen (WC-Produkte, Kind-Events)', 'Admin'],
                ['tix_export_subscribers', 'Newsletter-Abonnenten als CSV exportieren', 'Admin'],
                ['tix_export_tickets_csv', 'Ticket-Daten als CSV exportieren', 'Admin'],
            ]);

            // ── Cron Hooks ──
            self::meta_card('Cron-Hooks', 'dashicons-clock', [
                ['tix_presale_check', 'Alle 10 Min: VVK-Ablauf, vergangene Events, Preisphasen pr&uuml;fen', 'Wiederkehrend'],
                ['tix_send_reminder_email', 'Erinnerungs-E-Mail vor dem Event versenden', 'Einzeln/Bestellung'],
                ['tix_send_followup_email', 'Follow-up-E-Mail nach dem Event versenden', 'Einzeln/Bestellung'],
                ['tix_cleanup_expired_seats', 'Alle 5 Min: Abgelaufene Sitzplatz-Reservierungen bereinigen', 'Wiederkehrend'],
                ['tix_send_abandoned_cart_email', 'Verlassene-Warenkorb E-Mail versenden (Action Scheduler)', 'Einzeln/Warenkorb'],
            ]);

            // ── Transients ──
            self::meta_card('Transients (Cache)', 'dashicons-database', [
                ['tix_sync_log_{POST_ID}', 'Letztes Sync-Protokoll (30 Sek.)', 'Cache'],
                ['tix_publish_error_{POST_ID}', 'Publish-Validierungsfehler (60 Sek.)', 'Admin-Notice'],
                ['tix_publish_warning_{POST_ID}', 'Publish-Validierungswarnungen (60 Sek.)', 'Admin-Notice'],
                ['tix_delete_blocked_{USER_ID}', 'L&ouml;sch-Schutz-Hinweis (30 Sek.)', 'Admin-Notice'],
                ['tix_orphan_cleanup_result_{USER_ID}', 'Orphan-Bereinigungsergebnis (30 Sek.)', 'Admin-Notice'],
                ['tix_force_delete_result_{USER_ID}', 'Force-Delete-Ergebnis (30 Sek.)', 'Admin-Notice'],
                ['tix_cart_transfer_{TOKEN}', 'Warenkorb-Wiederherstellungsdaten (15 Min.)', 'Cart Recovery'],
                ['tix_group_{TOKEN}', 'Gruppenbuchung-Session (48 Std.)', 'Group Booking'],
                ['tix_stats_{TAB}_{HASH}', 'Statistik-Daten pro Tab + Filter (10 Min.)', 'Statistik-Cache'],
                ['tixbot_rl_{MD5(EMAIL)}', 'Bot Ticket-Lookup Rate-Limit (5 Versuche / 15 Min.)', 'Bot Rate-Limit'],
            ]);

            // ── Save-Post Hooks ──
            self::meta_card('save_post_event Reihenfolge', 'dashicons-sort', [
                ['Priorit&auml;t 10', 'TIX_Metabox::save() &ndash; Meta-Felder speichern', 'Admin'],
                ['Priorit&auml;t 20', 'TIX_Sync::sync() &ndash; WC/TC synchronisieren + Breakdance-Meta', 'Admin'],
                ['Priorit&auml;t 25', 'TIX_Series::on_save() &ndash; Kind-Events generieren/aktualisieren', 'Admin'],
            ]);
            ?>

        </div>
        <?php
    }

    // ══════════════════════════════════════
    // TAB 5: TICKET-VORLAGEN
    // ══════════════════════════════════════

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
    // TAB 7: TICKET-BOT
    // ══════════════════════════════════════

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
}
