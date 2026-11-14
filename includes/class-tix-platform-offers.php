<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Platform_Offers – Angebote des Plattform-Betreibers (1.38.670, Doku §93, Auftrag uebergaben/auftrag-angebote-plattform-2026-11-05.md).
 *
 *  - Nur im Mehr-Veranstalter-Modus (evendis), nur für Plattform-Admins (manage_options) und den Vertrieb (Rolle tix_sales).
 *    Der Vertrieb sieht nur seine eigenen Angebote, Admins alle. Den Katalog ändern nur Admins.
 *  - Katalog (Option tix_offer_catalog): Tarife, Module, Leistungen mit Listenpreis, Vorteile, Wettbewerb.
 *    Startwerte von veranstalter.evendis.de/preise/ und der Startseite (Stand 06.11.2026). Vergleich mit Wettbewerbern nur im Studio, nie im PDF.
 *  - Angebote in der Tabelle {prefix}tix_platform_offers, Nummernkreis AN-JJJJ-NNNN (je Jahr, unter Sperre), Versionen -2, -3 …
 *  - PDF über TIX_PDFA (Manrope) bzw. TIX_Simple_PDF im Stil der Werbe-PDF, Mail mit Anhang, Link „Interesse bestätigen“ (unverbindlich) mit Token und Ablauf
 *    (Seite ?tix_offer=<token> und /public/offers/{token}*), Ablauf täglich per Cron.
 */
class TIX_Platform_Offers {

    const NS = 'tixomat/v1';
    const DB_VERSION = '1';
    const OPT_DB = 'tix_offer_db_version';
    const OPT_CATALOG = 'tix_offer_catalog';
    const OPT_SEQ = 'tix_offer_seq';
    const CRON = 'tix_offer_expire';
    const STATUSES = ['draft', 'sent', 'viewed', 'accepted', 'rejected', 'expired', 'replaced']; // accepted = „Interesse bestätigt“ (vorerst unverbindlich, User 06.11.)
    const DECIDED = ['accepted', 'rejected'];
    const INTERVALS = ['monthly', 'yearly', 'once', 'per_event', 'per_ticket'];
    const TYPES = ['plan', 'module', 'service', 'custom'];

    // Farben der Werbe-PDF
    const C_NIGHT = [0.173, 0.184, 0.247];   // #2C2F3F
    const C_RED = [0.988, 0.184, 0.333];     // #FC2F55
    const C_MUTED = [0.365, 0.380, 0.459];   // #5D6175
    const C_LINE = [0.890, 0.898, 0.925];    // #E3E5EC
    const C_SOFT = [0.957, 0.961, 0.973];    // #F4F5F8
    const C_PINK = [1.0, 0.961, 0.969];      // #FFF5F7

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_create'], 30);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('template_redirect', [__CLASS__, 'public_page'], 1);
        add_action(self::CRON, [__CLASS__, 'expire_due']);
        add_action('init', function () { if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 600, 'daily', self::CRON); }, 40);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tix_platform_offers';
    }

    public static function maybe_create() {
        if (get_option(self::OPT_DB) === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        $cs = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            number varchar(32) NOT NULL DEFAULT '',
            base_number varchar(32) NOT NULL DEFAULT '',
            version int(11) NOT NULL DEFAULT 1,
            status varchar(16) NOT NULL DEFAULT 'draft',
            owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
            company varchar(190) NOT NULL DEFAULT '',
            contact_name varchar(190) NOT NULL DEFAULT '',
            contact_email varchar(190) NOT NULL DEFAULT '',
            plan varchar(190) NOT NULL DEFAULT '',
            total_once decimal(12,2) NOT NULL DEFAULT 0,
            total_year decimal(12,2) NOT NULL DEFAULT 0,
            valid_until date NULL DEFAULT NULL,
            token varchar(64) NOT NULL DEFAULT '',
            token_expires datetime NULL DEFAULT NULL,
            views int(11) NOT NULL DEFAULT 0,
            viewed_at datetime NULL DEFAULT NULL,
            sent_at datetime NULL DEFAULT NULL,
            decided_at datetime NULL DEFAULT NULL,
            data longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY owner_id (owner_id),
            KEY base_number (base_number),
            KEY token (token)
        ) $cs;");
        update_option(self::OPT_DB, self::DB_VERSION, false);
    }

    // ═══════════════ Rechte ═══════════════

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function is_admin_user() {
        return current_user_can('manage_options');
    }

    public static function is_sales() {
        return class_exists('TIX_Sales') && TIX_Sales::is_sales_user();
    }

    public static function perm() {
        if (!self::multi()) return new WP_Error('rest_no_route', 'Nur auf der Plattform.', ['status' => 404]);
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        return (self::is_admin_user() || self::is_sales()) ? true : new WP_Error('rest_forbidden', 'Nur für Plattform-Admins und den Vertrieb.', ['status' => 403]);
    }

    public static function perm_admin() {
        $p = self::perm();
        if ($p !== true) return $p;
        return self::is_admin_user() ? true : new WP_Error('rest_forbidden', 'Den Katalog pflegen nur Plattform-Admins.', ['status' => 403]);
    }

    public static function perm_public() {
        return self::multi() ? true : new WP_Error('rest_no_route', 'Nur auf der Plattform.', ['status' => 404]);
    }

    private static function can_see($row) {
        return $row && (self::is_admin_user() || intval($row->owner_id) === get_current_user_id());
    }

    // ═══════════════ Katalog ═══════════════

    /** Startwerte des Katalogs: Preise und Texte von veranstalter.evendis.de/preise/ und der Startseite (Stand 06.11.2026, User-Entscheid). */
    public static function default_catalog() {
        // Verkäufe Selbstgehosteter über evendis.de: Kanal-Gebühr inkl. MwSt., Zahlungskosten beim eigenen Anbieter (User 11.11.2026)
        $chs = class_exists('TIX_Channel_Fee') ? TIX_Channel_Fee::settings() : ['percent' => 2.9, 'fixed' => 0.49];
        $ch_txt = rtrim(rtrim(number_format(floatval($chs['percent']), 2, ',', '.'), '0'), ',') . ' % + ' . number_format(floatval($chs['fixed']), 2, ',', '.') . ' € je Ticket inkl. MwSt., zzgl. Zahlungskosten deines eigenen Anbieters';
        $b = function ($key, $title, $text, $group = 'shop') { return ['key' => $key, 'title' => $title, 'text' => $text, 'group' => $group]; };
        $benefits = [
            // In jedem evendis-Konto ohne Aufpreis
            $b('designs', '5 Designs', '5 Designs für deine Veranstalter-Seite.', 'konto'),
            $b('dein_link', 'Dein Link mit QR-Code', 'Dein Link mit QR-Code und Story-Bild, für Instagram-Bio, Flyer, Plakate und WhatsApp.', 'konto'),
            $b('kalender_abo', 'Kalender-Abo', 'Kalender-Abo für deine Gäste.', 'konto'),
            $b('csv_export', 'Datenexport als CSV', 'Deine Daten jederzeit als CSV.', 'konto'),
            $b('awareness', 'Awareness-Funktionen', 'Codewort, Awareness-Punkt und „Hilfe vor Ort“ – kostenlos in jedem Paket.', 'konto'),
            $b('selbst_veranstalter', 'Von Veranstaltern für Veranstalter', 'Seit über 20 Jahren stehen wir selbst hinter Events.', 'konto'),
            // Kostenlos eintragen
            $b('reichweite', 'Auf evendis.de und in der App', 'Dein Event auf evendis.de und in der evendis-App.', 'eintrag'),
            $b('eintritt_frei', 'Ohne Ticketverkauf', 'Eintritt frei, Abendkasse oder Link zu deinem eigenen Ticketshop.', 'eintrag'),
            $b('follower', 'Veranstalter-Seite mit Followern', 'Gäste folgen dir und erfahren von neuen Events. Nachrichten an deine Follower.', 'eintrag'),
            // Tickets über evendis
            $b('eigene_seite', 'Deine eigene Seite', 'evendis.de/v/deinname – auf deiner Seite stehen nur deine Events.', 'plattform'),
            $b('keine_grundgebuehr', 'Keine Grundgebühr', 'Keine Kosten ohne Verkauf, Freitickets kostenlos.', 'plattform'),
            $b('zahlung_inklusive', 'Zahlungskosten enthalten', 'Zahlungskosten enthalten, evendis trägt sie: Kartenzahlung, Apple Pay & Co. – keine zweite Rechnung vom Zahlungsanbieter.', 'plattform'),
            $b('gebuehr_weitergeben', 'Du entscheidest, wer die Gebühr trägt', 'Deine Gäste, du – oder ihr teilt sie euch.', 'plattform'),
            $b('einlass_kasse', 'Einlass, Abendkasse und Statistik', 'Einlass-Scanner, Abendkasse, Gästeliste und Statistik inklusive.', 'plattform'),
            $b('abrechnung', 'Abrechnung je Event', 'Umsatz, Gebühren, Erstattungen und Auszahlung je Event als PDF.', 'plattform'),
            // Eigener Shop
            $b('shop_domain', 'Shop unter deiner Domain', 'In deinem Look, Betrieb durch uns.', 'shop'),
            $b('daten_gehoeren_dir', 'Gäste und Bestellungen gehören dir', 'Deine Gäste kaufen direkt bei dir.', 'shop'),
            $b('null_je_ticket', '0 € je Ticket', 'Keine Gebühr je Ticket in deinem eigenen Shop.', 'shop'),
            $b('evendis_kanal', 'Zusätzlich auf evendis.de', 'Verkäufe über evendis.de: ' . $ch_txt . '. Die Gebühr trägst du; deine Gäste zahlen nur deine eigene Service- bzw. Vorverkaufsgebühr.', 'shop'),
            $b('einrichtung_inklusive', 'Einrichtung inklusive', 'Beim Jahresvertrag richten wir deinen Shop für dich ein.', 'shop'),
            $b('updates', 'Updates und Support inklusive', 'Neue Funktionen kommen automatisch dazu.', 'shop'),
            // Eigene App
            $b('app_stores', 'In App Store und Google Play', 'Unter deinem Namen.', 'app'),
            $b('app_module', 'Module nach Wahl', 'Module wie Prämien und Musikwunsch.', 'app'),
            $b('app_betreuung', 'Einrichtung und Updates', 'Wir richten die App ein und kümmern uns um Updates.', 'app'),
            // Selbstgehostet (User 14.11.)
            $b('daten_eigener_server', 'Deine Daten liegen allein bei dir', 'Deine Daten liegen allein bei dir auf deinem eigenen Server.', 'selfhosted'),
        ];
        $i = function (array $a) {
            return $a + ['type' => 'module', 'short' => '', 'description' => '', 'prices' => [], 'default_interval' => 'monthly', 'fee_percent' => null, 'fee_fixed' => null,
                'price_note' => '', 'tax_note' => '', 'term' => '', 'benefits' => [], 'adds' => [], 'recommended' => false, 'active' => true, 'modes' => []]; // modes leer = alle Betriebsarten
        };
        $shop = ['shop_domain', 'daten_gehoeren_dir', 'null_je_ticket', 'evendis_kanal', 'updates'];
        $items = [
            $i(['key' => 'kostenlos', 'type' => 'plan', 'name' => 'Kostenlos eintragen', 'short' => 'Ohne Ticketverkauf',
                'description' => 'Event auf evendis.de und in der evendis-App, ohne Ticketverkauf. Eintritt frei, Abendkasse oder Link zu deinem eigenen Ticketshop.',
                'prices' => ['once' => 0], 'default_interval' => 'once', 'price_note' => 'ohne Gebühr', 'benefits' => ['reichweite', 'eintritt_frei', 'follower']]),
            $i(['key' => 'tickets', 'type' => 'plan', 'name' => 'Tickets über evendis', 'short' => 'Mit Ticketverkauf',
                'description' => 'Verkauf über evendis.de und die evendis-App. Keine Grundgebühr, keine Kosten ohne Verkauf, Freitickets kostenlos. Verkauf startet nach Freigabe deines Kontos.',
                'default_interval' => 'per_ticket', 'fee_percent' => 2.9, 'fee_fixed' => 0.49, /* 11.11.2026, vorher 2,5 % + 0,59 € */ 'price_note' => 'je verkauftem Ticket, inkl. MwSt. – Zahlungskosten enthalten, evendis trägt sie',
                'tax_note' => 'inkl. MwSt.', 'term' => 'keine Bindung', 'benefits' => ['eigene_seite', 'keine_grundgebuehr', 'zahlung_inklusive', 'gebuehr_weitergeben', 'einlass_kasse', 'abrechnung'], 'recommended' => true]),
            $i(['key' => 'shop', 'type' => 'plan', 'name' => 'Eigener Shop', 'short' => 'Jahresvertrag',
                'description' => 'Shop unter deiner Domain, Betrieb durch uns. Gäste und Bestellungen gehören dir, 0 € je Ticket im eigenen Shop. Verkäufe über evendis.de: ' . $ch_txt . '. Einrichtung inklusive.',
                'prices' => [], 'default_interval' => 'yearly', /* Jahresvertrag: Listenpreis = Staffel bei der führenden Ticketzahl (1.38.823, clean_data); monatlich nur „Eigener Shop – monatlich kündbar“ */ 'price_note' => 'Jahresvertrag nach Staffel, Einrichtung inklusive', 'term' => 'Jahresvertrag', 'benefits' => array_merge($shop, ['einrichtung_inklusive'])]),
            $i(['key' => 'shop_monatlich', 'type' => 'plan', 'name' => 'Eigener Shop – monatlich kündbar', 'short' => 'Ohne Jahresbindung',
                'description' => 'Shop unter deiner Domain, Betrieb durch uns. Gäste und Bestellungen gehören dir, 0 € je Ticket im eigenen Shop. Verkäufe über evendis.de: ' . $ch_txt . '.',
                'prices' => ['monthly' => 99], 'price_note' => 'monatlich kündbar', 'term' => 'monatlich kündbar', 'benefits' => $shop]),
            $i(['key' => 'app', 'name' => 'Eigene App – Basis', 'short' => 'In App Store und Google Play',
                'description' => 'Deine App unter deinem Namen in App Store und Google Play. Setzt eigenen Shop oder Lizenz voraus.',
                'prices' => ['monthly' => 149], 'adds' => ['app_einrichtung'], 'benefits' => ['app_stores', 'app_module', 'app_betreuung'], 'modes' => self::OWN_MODES]),
            $i(['key' => 'app_einrichtung', 'type' => 'service', 'name' => 'App-Einrichtung', 'description' => 'Einmalig für die Einrichtung der App.',
                'prices' => ['once' => 1490], 'default_interval' => 'once', 'modes' => self::OWN_MODES]),
            $i(['key' => 'app_modul', 'name' => 'App-Modul', 'description' => 'Je Modul, z. B. Prämien oder Musikwunsch.', 'prices' => ['monthly' => 29], 'modes' => self::OWN_MODES]),
            $i(['key' => 'app_alle_module', 'name' => 'Alle App-Module', 'description' => 'Alle Module der App zusammen.', 'prices' => ['monthly' => 249], 'modes' => self::OWN_MODES]),
            $i(['key' => 'lizenz', 'type' => 'plan', 'name' => 'Lizenz', 'short' => 'Selbstgehostet',
                'description' => 'Für deinen eigenen Server (deine WordPress-Seite). Installation und Updates per Paket, Support inklusive, 0 € je Ticket.',
                'prices' => ['yearly' => 699], 'default_interval' => 'yearly', 'price_note' => 'je Website', 'term' => '12 Monate je Website', 'benefits' => ['daten_eigener_server', 'null_je_ticket', 'daten_gehoeren_dir', 'updates']]),
            $i(['key' => 'domain', 'type' => 'service', 'name' => 'Eigene Domain für deine Veranstalter-Seite', 'description' => 'Seite, Events und Kasse unter deiner Domain.',
                'price_note' => 'auf Anfrage']),
            // Optionen fürs Angebot (AN2, User 14.11.: je Option ein Häkchen, jeder Preis frei) – ohne Listenpreis = Preis im Angebot setzen
            $i(['key' => 'veranstalter_app', 'name' => 'Veranstalter-App', 'description' => 'Einlass, Kasse, Statistik und Push für dein Team – auf iOS und Android.']),
            $i(['key' => 'einrichtung', 'type' => 'service', 'name' => 'Rundum-Einrichtung durch evendis', 'short' => 'Ohne technisches Wissen',
                'description' => 'Du brauchst kein technisches Wissen – wir richten alles für dich ein: Server, Domain, Zahlungsanbieter, Website, Rechtstexte-Vorlagen, erste Events, Einlass und Kasse, Einweisung.',
                // Einziger Einrichtungsposten (User 14.11.): eigener Shop immer inklusive (0 €), selbstgehostet 190 € (erlassbar), Plattform ohne
                'prices' => ['once' => 190], 'default_interval' => 'once', 'modes' => self::OWN_MODES]),
            $i(['key' => 'hosting', 'type' => 'service', 'name' => 'Hosting', 'description' => 'Betrieb deiner Website auf unseren Servern in Deutschland, mit Updates und Sicherung (nur beim eigenen Shop).', 'modes' => ['own_shop']]),
            $i(['key' => 'api', 'name' => 'API-Modul', 'description' => 'Schnittstelle für deine eigene Website, Kasse oder Warenwirtschaft.']),
            $i(['key' => 'seating', 'name' => 'Modul Saalplan & Tische', 'description' => 'Sitzplätze, Tische und Bereiche im Saalplan buchbar.']),
            $i(['key' => 'wallet', 'name' => 'Modul Guthaben', 'description' => 'Guthaben aufladen und vor Ort bezahlen.']),
            $i(['key' => 'giftcards', 'name' => 'Modul Geschenkgutscheine', 'description' => 'Gutscheine verkaufen, verschenken und einlösen.']),
            $i(['key' => 'loyalty', 'name' => 'Modul Prämien', 'description' => 'Punkte sammeln und Prämien einlösen.']),
            $i(['key' => 'music', 'name' => 'Modul Musikwunsch', 'description' => 'Gäste wünschen sich Songs, das DJ-Pult sieht die Liste.']),
            $i(['key' => 'raffles', 'name' => 'Modul Gewinnspiele', 'description' => 'Gewinnspiele zu deinen Events.']),
            $i(['key' => 'marketing', 'name' => 'Modul Marketing', 'description' => 'Kampagnen, Newsletter, Werbung und Verkaufsprognose.']),
            $i(['key' => 'awareness', 'name' => 'Modul Awareness', 'description' => 'Codewort, Awareness-Punkt und „Hilfe vor Ort“.', 'prices' => ['monthly' => 0], 'price_note' => 'kostenlos in jedem Paket']),
            $i(['key' => 'datenumzug', 'type' => 'service', 'name' => 'Datenumzug', 'description' => 'Wir übernehmen Gäste, Events und Gutscheine aus deinem bisherigen System.', 'default_interval' => 'once', 'price_note' => 'auf Anfrage']),
            $i(['key' => 'schulung', 'type' => 'service', 'name' => 'Schulung', 'description' => 'Persönliche Einweisung für dich und dein Team, vor Ort oder online.', 'default_interval' => 'once', 'price_note' => 'auf Anfrage']),
        ];
        $c = function ($id, $name, array $ex) { return ['id' => $id, 'name' => $name, 'examples' => $ex]; };
        return [
            'version' => 2,
            'source' => 'veranstalter.evendis.de/preise/ und Startseite (Stand 06.11.2026)',
            'currency' => 'EUR',
            'tax_rate' => 19,
            'valid_days' => 30,
            'number_prefix' => 'AN',
            'intro' => 'vielen Dank für das gute Gespräch. Wie besprochen schicken wir dir hier unser Angebot. Von Veranstaltern für Veranstalter: Wir stehen selbst seit über 20 Jahren hinter Events.',
            'conditions' => "Alle Preise zzgl. MwSt., „Tickets über evendis“ inkl. MwSt. und Zahlung.\nBeim eigenen Shop zahlst du die Gebühr deines Zahlungsanbieters direkt an ihn (z. B. Mollie Karte 1,8 % + 0,25 € je Zahlung).\nDie eigene App setzt den eigenen Shop oder die Lizenz voraus.",
            'general_benefits' => ['designs', 'dein_link', 'kalender_abo', 'csv_export', 'awareness'],
            // Hochrechnung je Event (Nachtrag 06.11.): Sätze der Zahlungsdienstleister bei „Selbstgehostet“, aus den öffentlichen Preisseiten
            'payment_rates' => self::default_payment_rates(),
            'payment_note' => 'Die Gebühren deines Zahlungsdienstleisters (z. B. PayPal, Mollie für Karte/Klarna) zahlst du direkt an ihn – sie gehen nicht an evendis. Du schließt dein eigenes Konto an, dafür landet das Geld aus dem Ticketverkauf sofort und direkt bei dir.',
            'projection_defaults' => ['paypal_share' => 30, 'klarna_within' => 50, 'tickets_per_order' => 1, 'events_year' => 12],
            // Planrechnung (AN1, 1.38.816): Staffel eigener Shop je Jahr (F-AN1 B) und Vorgaben der Annahmen (F-AN2, immer änderbar)
            'shop_tiers' => class_exists('TIX_Offer_Plan') ? TIX_Offer_Plan::default_tiers() : [],
            'plan_defaults' => class_exists('TIX_Offer_Plan') ? TIX_Offer_Plan::default_settings() : [],
            // Betriebsarten (User 14.11.: „wie eigener shop plus 20 server“): Staffel selbstgehostet = Staffel eigener Shop, Server-Kosten beim Kunden ca. je Monat (eigene Zeile, frei)
            'self_hosted_tiers' => class_exists('TIX_Offer_Plan') ? TIX_Offer_Plan::default_tiers() : [],
            'server_month' => 20.0,
            // Rundum-Einrichtung (Position „einrichtung“): Schritte und was beim Kunden bleibt, je Betriebsart (modes) bzw. nur mit App (needs app)
            'setup' => self::default_setup(),
            // Referenzen (User 14.11.): bestehende eigene Shops bzw. selbstgehostete Seiten; nur mit approved = true wählbar (Vorgabe aus)
            'references' => [],
            'benefits' => $benefits,
            'items' => $items,
            // Nur als Gesprächshilfe im Studio – nie im PDF und nie auf der Kundenseite (User-Entscheid 06.11.)
            'comparison_examples' => ['Club 12 € · 300 Tickets', 'Konzert 30 € · 500 Tickets', 'Festival 60 € · 3.000 Tickets'],
            'comparison_note' => 'Gebühr je Ticket, wenn der Veranstalter sie trägt. Quelle: Wettbewerbsübersicht evendis, Stand 16.10.2026. Nur zur Gesprächsvorbereitung, erscheint nicht im Angebot.',
            'competitors' => [
                $c('eventfrog_plus', 'Eventfrog Plus', [0.93, 1.44, 2.30]),
                $c('eventim_light', 'EVENTIM.Light', [1.41, 2.04, 3.09]),
                $c('rausgegangen', 'Rausgegangen', [1.32, 2.40, 4.20]),
                $c('eventbrite', 'Eventbrite Professional', [1.65, 2.64, 4.29]),
                $c('pretix', 'pretix Hosted', [0.68, 1.48, 2.81]),
                $c('ticket_tailor', 'Ticket Tailor', [1.21, 1.56, 2.15]),
                $c('weeztix', 'Weeztix', [0.78, 0.90, 1.08]),
                $c('billetto', 'Billetto Essential', [1.64, 2.50, 3.92]),
                $c('dice', 'DICE', [1.58, 3.82, 7.64]),
                $c('ticketpay', 'TicketPAY', [1.91, 2.80, 4.28]),
                $c('eveeno', 'eveeno', [1.18, 1.75, 3.50]),
                $c('ticketino', 'TICKETINO', [1.13, 2.82, 4.93]),
            ],
        ];
    }

    /** Zahlungs-Sätze (Online, Deutschland, Standardtarif) mit Stand und Quelle; fehlt ein Satz, steht percent/fixed null. */
    public static function default_payment_rates() {
        return [
            'paypal' => ['label' => 'PayPal', 'provider' => 'PayPal', 'detail' => 'PayPal Checkout, Inlandszahlung (direkt bei PayPal; über Mollie zusätzlich 0,10 € je Zahlung)',
                'percent' => 2.99, 'fixed' => 0.39, 'as_of' => '2026-11-04', 'retrieved' => '2026-11-06', 'source' => 'https://www.paypal.com/de/business/paypal-business-fees'],
            'card' => ['label' => 'Kreditkarte', 'provider' => 'Mollie', 'detail' => 'Verbraucherkarte aus dem EWR (auch Apple Pay)',
                'percent' => 1.80, 'fixed' => 0.25, 'as_of' => '2026-11-05', 'retrieved' => '2026-11-06', 'source' => 'https://www.mollie.com/de/pricing'],
            'klarna' => ['label' => 'Klarna', 'provider' => 'Mollie', 'detail' => 'Deutschland, Österreich und Schweiz',
                'percent' => 2.99, 'fixed' => 0.35, 'as_of' => '2026-11-05', 'retrieved' => '2026-11-06', 'source' => 'https://www.mollie.com/de/pricing'],
            // AN1 (1.38.816): PayPal über Mollie und Stripe – Mollie und Stripe gleichwertig nebeneinander (User 14.11.)
            'paypal_mollie' => ['label' => 'PayPal über Mollie', 'provider' => 'Mollie', 'detail' => 'PayPal-Gebühr (Checkout, Inland) zzgl. 0,10 € je Zahlung an Mollie',
                'percent' => 2.99, 'fixed' => 0.49, 'as_of' => '2026-11-14', 'retrieved' => '2026-11-14', 'source' => 'https://www.mollie.com/payments/paypal'],
            'card_stripe' => ['label' => 'Kreditkarte (Stripe)', 'provider' => 'Stripe', 'detail' => 'EWR-Standardkarten; Premiumkarten 2,8 % + 0,25 € – Satz auf der deutschen Preisseite prüfen',
                'percent' => 1.50, 'fixed' => 0.25, 'as_of' => '2026-11-14', 'retrieved' => '2026-11-14', 'source' => 'https://stripe.com/de/pricing'],
        ];
    }

    const MODES = ['platform', 'own_shop', 'self_hosted'];
    const OWN_MODES = ['own_shop', 'self_hosted'];
    /** Betriebsart des Angebots ↔ Lizenz-Betriebsart (TIX_License::MODES): own_shop = managed (unsere Server), self_hosted = selfhosted (Server des Kunden) */
    const MODE_LICENSE = ['platform' => null, 'own_shop' => 'managed', 'self_hosted' => 'selfhosted'];
    const MODE_TEXTS = [
        'platform' => ['label' => 'Plattform über evendis.de', 'title' => 'Angebot', 'sub' => 'Verkauf über evendis.de und die evendis-App, Zahlung inklusive.'],
        'own_shop' => ['label' => 'Eigener Shop', 'title' => 'Dein eigener Ticketshop', 'sub' => 'Auf unseren Servern – Hosting und Betrieb durch evendis.'],
        'self_hosted' => ['label' => 'Selbstgehostet', 'title' => 'Dein Ticketshop auf deinem Server', 'sub' => 'Auf deinem eigenen Server – Installation und Updates per Paket.'],
    ];

    public static function default_setup() {
        $s = function ($text, $modes = ['own_shop', 'self_hosted'], $needs = null) { return ['text' => $text, 'modes' => $modes, 'needs' => $needs]; };
        return [
            'headline' => 'Du brauchst kein technisches Wissen – wir richten alles für dich ein.',
            'steps' => [
                $s('Server und Hosting bei uns einrichten', ['own_shop']),
                $s('Server bei deinem Hoster einrichten, absichern und installieren', ['self_hosted']),
                $s('Domain und DNS einrichten'),
                $s('Zahlungsanbieter verbinden (Mollie oder Stripe)'),
                $s('Website und Marke einrichten (Logo, Farben, Seiten)'),
                $s('Rechtstexte-Vorlagen einspielen (Impressum, AGB, Datenschutz)'),
                $s('Erste Events anlegen'),
                $s('Einlass und Kasse einrichten'),
                $s('App-Store-Einträge anlegen (App Store und Google Play)', ['own_shop', 'self_hosted'], 'app'),
                $s('Einweisung für dich und dein Team'),
            ],
            'customer_tasks' => [
                $s('Vertrag mit deinem Zahlungsanbieter (Mollie oder Stripe) selbst abschließen – wir helfen beim Ausfüllen'),
                $s('Inhalte liefern: Logo, Farben, Texte, Bilder und Event-Daten'),
                $s('Rechtstexte prüfen und freigeben'),
                $s('Zugang zu deiner Domain bzw. DNS geben'),
                $s('Server bei deinem Hoster mieten und bezahlen, Zugang für uns einrichten', ['self_hosted']),
                $s('Entwickler-Konten bei Apple und Google anlegen', ['own_shop', 'self_hosted'], 'app'),
            ],
        ];
    }

    public static function clean_setup($in) {
        $def = self::default_setup();
        $in = is_array($in) ? $in : $def;
        $list = function ($rows) {
            $out = [];
            foreach (array_slice((array) $rows, 0, 20) as $r) {
                $t = self::txt(is_array($r) ? ($r['text'] ?? '') : $r, 200, false);
                if ($t === '') continue;
                $m = array_values(array_intersect((array) (is_array($r) ? ($r['modes'] ?? ['own_shop', 'self_hosted']) : ['own_shop', 'self_hosted']), ['own_shop', 'self_hosted']));
                $out[] = ['text' => $t, 'modes' => $m ?: ['own_shop', 'self_hosted'], 'needs' => (is_array($r) && ($r['needs'] ?? null) === 'app') ? 'app' : null];
            }
            return $out;
        };
        return ['headline' => self::txt($in['headline'] ?? $def['headline'], 200, false) ?: $def['headline'], 'steps' => $list($in['steps'] ?? $def['steps']), 'customer_tasks' => $list($in['customer_tasks'] ?? $def['customer_tasks'])];
    }

    /** [{max, price}] → sortiert | WP_Error */
    public static function clean_tiers($in) {
        $out = [];
        foreach (array_slice((array) $in, 0, 10) as $t) {
            $mx = intval($t['max'] ?? 0); $pr = self::num($t['price'] ?? null, 0);
            if ($mx < 1 || $pr === null) return new WP_Error('tix_invalid_field', 'Staffel: Tickets und Preis angeben.', ['status' => 400]);
            $out[] = ['max' => $mx, 'price' => $pr];
        }
        if (!$out) return new WP_Error('tix_invalid_field', 'Staffel: mindestens eine Stufe.', ['status' => 400]);
        usort($out, function ($a, $b) { return $a['max'] <=> $b['max']; });
        return $out;
    }

    /**
     * Referenzen im Katalog: [{key, customer_key?, name, url, logo_url, image_url, app_store_url, play_store_url, text, mode own_shop|self_hosted, approved}]
     * approved = „darf als Referenz gezeigt werden“ (Vorgabe aus).
     */
    public static function clean_references($in) {
        $out = []; $seen = [];
        foreach (array_slice((array) $in, 0, 30) as $i => $r) {
            if (!is_array($r)) continue;
            $name = self::txt($r['name'] ?? '', 120, false);
            $key = sanitize_key((string) ($r['key'] ?? '')) ?: sanitize_key((string) ($r['customer_key'] ?? '')) ?: sanitize_title($name);
            if ($name === '' || $key === '') return new WP_Error('tix_catalog_invalid', 'Referenz: Name angeben.', ['status' => 400, 'field' => "references.$i.name"]);
            if (isset($seen[$key])) return new WP_Error('tix_catalog_invalid', "Referenz „{$key}“ ist doppelt.", ['status' => 400, 'field' => "references.$i.key"]);
            $seen[$key] = true;
            $url = function ($k) use ($r) { $u = esc_url_raw(trim((string) ($r[$k] ?? ''))); return preg_match('#^https://#', $u) ? $u : ''; };
            $out[] = ['key' => $key, 'customer_key' => sanitize_key((string) ($r['customer_key'] ?? '')) ?: null, 'name' => $name, 'url' => $url('url'), 'logo_url' => $url('logo_url'), 'image_url' => $url('image_url'),
                'app_store_url' => $url('app_store_url'), 'play_store_url' => $url('play_store_url'), 'text' => self::txt($r['text'] ?? '', 160, false),
                'mode' => in_array($r['mode'] ?? '', ['own_shop', 'self_hosted'], true) ? $r['mode'] : 'own_shop', 'approved' => !empty($r['approved'])];
        }
        return $out;
    }

    public static function catalog() {
        $c = get_option(self::OPT_CATALOG, null);
        if (!is_array($c) || empty($c['items'])) return self::default_catalog();
        $def = self::default_catalog();
        foreach (['payment_rates', 'payment_note', 'projection_defaults', 'shop_tiers', 'plan_defaults', 'self_hosted_tiers', 'server_month', 'setup', 'references'] as $k) if (!array_key_exists($k, $c)) $c[$k] = $def[$k]; // ältere gespeicherte Kataloge
        foreach ($def['payment_rates'] as $k => $r) if (!isset($c['payment_rates'][$k])) $c['payment_rates'][$k] = $r; // neue Zahlarten (1.38.816)
        return $c;
    }

    public static function catalog_item($key) {
        foreach ((array) self::catalog()['items'] as $it) if ((string) ($it['key'] ?? '') === (string) $key) return $it;
        return null;
    }

    private static function num($v, $min = null) {
        if ($v === null || $v === '') return null;
        $n = round(floatval(str_replace(',', '.', (string) $v)), 2);
        return ($min !== null && $n < $min) ? $min : $n;
    }

    private static function txt($v, $len = 2000, $multi = true) {
        $s = $multi ? sanitize_textarea_field((string) $v) : sanitize_text_field((string) $v);
        return mb_substr($s, 0, $len);
    }

    /** Katalog prüfen und bereinigen. → array | WP_Error */
    public static function clean_catalog($in) {
        if (!is_array($in)) return new WP_Error('tix_catalog_invalid', 'Der Katalog fehlt.', ['status' => 400]);
        $def = self::default_catalog();
        $out = ['version' => intval($in['version'] ?? 1) ?: 1, 'source' => self::txt($in['source'] ?? '', 300, false), 'currency' => 'EUR',
            'tax_rate' => max(0, min(30, floatval($in['tax_rate'] ?? 19))), 'valid_days' => max(1, min(365, intval($in['valid_days'] ?? 30))),
            'number_prefix' => strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($in['number_prefix'] ?? 'AN'))) ?: 'AN',
            'intro' => self::txt($in['intro'] ?? '', 3000), 'conditions' => self::txt($in['conditions'] ?? '', 3000),
            'comparison_note' => self::txt($in['comparison_note'] ?? '', 300, false)];
        $out['number_prefix'] = substr($out['number_prefix'], 0, 6);
        $bkeys = [];
        $out['benefits'] = [];
        foreach ((array) ($in['benefits'] ?? []) as $b) {
            $k = sanitize_key((string) ($b['key'] ?? ''));
            $t = self::txt($b['title'] ?? '', 120, false);
            if ($k === '' || $t === '' || isset($bkeys[$k])) continue;
            $bkeys[$k] = true;
            $out['benefits'][] = ['key' => $k, 'title' => $t, 'text' => self::txt($b['text'] ?? '', 400), 'group' => sanitize_key((string) ($b['group'] ?? 'shop')) ?: 'shop'];
        }
        $ikeys = [];
        $out['items'] = [];
        foreach ((array) ($in['items'] ?? []) as $it) {
            $k = sanitize_key((string) ($it['key'] ?? ''));
            $n = self::txt($it['name'] ?? '', 120, false);
            if ($k === '' || $n === '') continue;
            if (isset($ikeys[$k])) return new WP_Error('tix_catalog_invalid', "Der Schlüssel „{$k}“ ist doppelt.", ['status' => 400, 'field' => 'items']);
            $ikeys[$k] = true;
            $type = in_array($it['type'] ?? '', self::TYPES, true) && $it['type'] !== 'custom' ? $it['type'] : 'module';
            $prices = [];
            foreach (['monthly', 'yearly', 'once', 'per_event'] as $iv) { $p = self::num(($it['prices'] ?? [])[$iv] ?? null, 0); if ($p !== null) $prices[$iv] = $p; }
            $di = in_array($it['default_interval'] ?? '', self::INTERVALS, true) ? $it['default_interval'] : ($prices ? array_key_first($prices) : 'monthly');
            $out['items'][] = ['key' => $k, 'type' => $type, 'name' => $n, 'short' => self::txt($it['short'] ?? '', 120, false), 'description' => self::txt($it['description'] ?? '', 1000),
                'prices' => $prices, 'default_interval' => $di, 'fee_percent' => self::num($it['fee_percent'] ?? null, 0), 'fee_fixed' => self::num($it['fee_fixed'] ?? null, 0),
                'price_note' => self::txt($it['price_note'] ?? '', 160, false), 'tax_note' => self::txt($it['tax_note'] ?? '', 60, false), 'term' => self::txt($it['term'] ?? '', 80, false),
                'benefits' => array_values(array_filter(array_map('sanitize_key', (array) ($it['benefits'] ?? [])), function ($x) use ($bkeys) { return isset($bkeys[$x]); })),
                'adds' => array_values(array_filter(array_map('sanitize_key', (array) ($it['adds'] ?? [])))),
                'recommended' => !empty($it['recommended']), 'active' => !array_key_exists('active', $it) || !empty($it['active']),
                'modes' => array_values(array_intersect((array) ($it['modes'] ?? []), self::MODES))];
        }
        if (!$out['items']) return new WP_Error('tix_catalog_invalid', 'Der Katalog braucht mindestens eine Position.', ['status' => 400, 'field' => 'items']);
        $out['general_benefits'] = array_values(array_filter(array_map('sanitize_key', (array) ($in['general_benefits'] ?? [])), function ($x) use ($bkeys) { return isset($bkeys[$x]); }));
        $ex = array_values(array_map(function ($s) { return self::txt($s, 60, false); }, array_slice((array) ($in['comparison_examples'] ?? $def['comparison_examples']), 0, 3)));
        $out['comparison_examples'] = $ex;
        $out['competitors'] = [];
        foreach ((array) ($in['competitors'] ?? []) as $c) {
            $id = sanitize_key((string) ($c['id'] ?? ''));
            $n = self::txt($c['name'] ?? '', 80, false);
            if ($id === '' || $n === '') continue;
            $out['competitors'][] = ['id' => $id, 'name' => $n, 'examples' => array_map(function ($v) { return self::num($v, 0); }, array_slice(array_pad((array) ($c['examples'] ?? []), count($ex), null), 0, count($ex)))];
        }
        // Zahlungs-Sätze {paypal, card, klarna} (Objekt; die Liste [{key, …}] wird auch angenommen); fehlt ein Satz: percent/fixed null
        $out['payment_rates'] = [];
        $in_rates = self::rates_by_key($in['payment_rates'] ?? $def['payment_rates']);
        foreach ($def['payment_rates'] as $k => $d0) {
            $r = $in_rates[$k] ?? $d0;
            $asof = (string) ($r['as_of'] ?? ''); $ret = (string) ($r['retrieved'] ?? '');
            $out['payment_rates'][$k] = ['label' => self::txt($r['label'] ?? $d0['label'], 60, false) ?: $d0['label'], 'provider' => self::txt($r['provider'] ?? '', 60, false),
                'detail' => self::txt($r['detail'] ?? '', 200, false), 'percent' => self::num($r['percent'] ?? null, 0), 'fixed' => self::num($r['fixed'] ?? null, 0),
                'as_of' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $asof) ? $asof : '', 'retrieved' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $ret) ? $ret : '',
                'source' => esc_url_raw((string) ($r['source'] ?? ''))];
        }
        $out['payment_note'] = self::txt($in['payment_note'] ?? $def['payment_note'], 600);
        // Staffel eigener Shop (AN1): [{max Tickets/Jahr, price €/Jahr}], aufsteigend, höchstens 10
        $tiers = [];
        foreach (array_slice((array) ($in['shop_tiers'] ?? (self::catalog()['shop_tiers'] ?? $def['shop_tiers'])), 0, 10) as $t) {
            $mx = intval($t['max'] ?? 0); $pr = self::num($t['price'] ?? null, 0);
            if ($mx < 1 || $pr === null) return new WP_Error('tix_catalog_invalid', 'Staffel: Tickets und Preis angeben.', ['status' => 400, 'field' => 'shop_tiers']);
            $tiers[] = ['max' => $mx, 'price' => $pr];
        }
        usort($tiers, function ($a, $b) { return $a['max'] <=> $b['max']; });
        $out['shop_tiers'] = $tiers;
        $st = self::clean_tiers($in['self_hosted_tiers'] ?? (self::catalog()['self_hosted_tiers'] ?? $def['self_hosted_tiers']));
        if (is_wp_error($st)) return new WP_Error('tix_catalog_invalid', 'Staffel selbstgehostet: Tickets und Preis angeben.', ['status' => 400, 'field' => 'self_hosted_tiers']);
        $out['self_hosted_tiers'] = $st;
        $sm = self::num($in['server_month'] ?? (self::catalog()['server_month'] ?? $def['server_month']), 0);
        if ($sm === null || $sm > 10000) return new WP_Error('tix_catalog_invalid', 'Server-Kosten je Monat 0 bis 10.000 €.', ['status' => 400, 'field' => 'server_month']);
        $out['server_month'] = $sm;
        $out['setup'] = self::clean_setup(array_key_exists('setup', $in) ? $in['setup'] : (self::catalog()['setup'] ?? $def['setup']));
        $refs = self::clean_references(array_key_exists('references', $in) ? $in['references'] : (self::catalog()['references'] ?? [])); // Studio ohne Feld löscht nichts
        if (is_wp_error($refs)) return $refs;
        $out['references'] = $refs;
        // Vorgaben der Planrechnung (AN1) – geprüft wie eine Eingabe
        $pd = class_exists('TIX_Offer_Plan') ? TIX_Offer_Plan::clean((array) ($in['plan_defaults'] ?? $def['plan_defaults']), ['payment_rates' => $out['payment_rates'], 'plan_defaults' => $def['plan_defaults']]) : null;
        if (is_wp_error($pd)) return new WP_Error('tix_catalog_invalid', $pd->get_error_message(), ['status' => 400, 'field' => 'plan_defaults']);
        $out['plan_defaults'] = $pd ? array_intersect_key($pd, array_flip(['avg_price', 'service_fee', 'tickets_per_order', 'mix', 'steps'])) : $def['plan_defaults'];
        $fd = (array) ($in['projection_defaults'] ?? []);
        $out['projection_defaults'] = ['paypal_share' => max(0, min(100, intval($fd['paypal_share'] ?? 30))), 'klarna_within' => max(0, min(100, intval($fd['klarna_within'] ?? 50))),
            'tickets_per_order' => max(1, min(20, intval($fd['tickets_per_order'] ?? 1))), 'events_year' => max(1, min(365, intval($fd['events_year'] ?? 12)))];
        return $out;
    }

    // ═══════════════ Angebot: Daten, Summen ═══════════════

    private static function short_id() {
        return substr(md5(wp_generate_password(12, false) . microtime()), 0, 8);
    }

    /** Position aus dem Katalog (für das Studio: „Tarif hinzufügen“). */
    public static function item_from_catalog($key, $interval = null) {
        $c = self::catalog_item($key);
        if (!$c) return null;
        $iv = in_array($interval, self::INTERVALS, true) ? $interval : $c['default_interval'];
        return ['id' => self::short_id(), 'catalog_key' => $c['key'], 'type' => $c['type'], 'name' => $c['name'], 'description' => $c['description'], 'qty' => 1,
            'interval' => $iv, 'list_price' => array_key_exists($iv, (array) $c['prices']) ? floatval($c['prices'][$iv]) : null, 'price' => null,
            'fee_percent' => $c['fee_percent'], 'fee_fixed' => $c['fee_fixed'], 'note' => $c['price_note']];
    }

    /** Eingaben bereinigen; Listenpreise kommen bei Katalog-Positionen immer aus dem Katalog. */
    public static function clean_data(array $in, array $old = []) {
        $cat = self::catalog();
        $g = function ($k, $d = null) use ($in, $old) { return array_key_exists($k, $in) ? $in[$k] : ($old[$k] ?? $d); };
        $cu = (array) $g('customer', []);
        $customer = [];
        foreach (['company' => 190, 'name' => 190, 'phone' => 60, 'street' => 190, 'zip' => 20, 'city' => 120, 'vat_id' => 40] as $k => $l) $customer[$k] = self::txt($cu[$k] ?? '', $l, false);
        $customer['email'] = sanitize_email((string) ($cu['email'] ?? ''));
        $customer['country'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($cu['country'] ?? 'DE')), 0, 2)) ?: 'DE';
        $customer['provider'] = self::txt($cu['provider'] ?? '', 120, false);

        $items = [];
        $seen = [];
        foreach (array_slice((array) $g('items', []), 0, 40) as $it) {
            if (!is_array($it)) continue;
            $key = sanitize_key((string) ($it['catalog_key'] ?? ''));
            $c = $key !== '' ? self::catalog_item($key) : null;
            $iv = in_array($it['interval'] ?? '', self::INTERVALS, true) ? $it['interval'] : ($c['default_interval'] ?? 'once');
            $name = self::txt($it['name'] ?? '', 160, false) ?: (string) ($c['name'] ?? '');
            if ($name === '') continue;
            $id = preg_match('/^[a-z0-9]{4,12}$/', (string) ($it['id'] ?? '')) && !isset($seen[$it['id']]) ? $it['id'] : self::short_id();
            $seen[$id] = true;
            $list = $c ? (array_key_exists($iv, (array) $c['prices']) ? floatval($c['prices'][$iv]) : null) : self::num($it['list_price'] ?? null, 0);
            $items[] = ['id' => $id, 'catalog_key' => $c ? $c['key'] : null, 'type' => $c ? $c['type'] : (in_array($it['type'] ?? '', self::TYPES, true) ? $it['type'] : 'custom'),
                'name' => $name, 'description' => array_key_exists('description', $it) ? self::txt($it['description'], 1000) : (string) ($c['description'] ?? ''),
                'qty' => max(1, min(9999, intval($it['qty'] ?? 1))), 'interval' => $iv, 'list_price' => $list, 'price' => self::num($it['price'] ?? null, 0),
                'fee_percent' => $iv === 'per_ticket' ? (self::num($it['fee_percent'] ?? null, 0) ?? ($c['fee_percent'] ?? null)) : null,
                'fee_fixed' => $iv === 'per_ticket' ? (self::num($it['fee_fixed'] ?? null, 0) ?? ($c['fee_fixed'] ?? null)) : null,
                'note' => array_key_exists('note', $it) ? self::txt($it['note'], 160, false) : (string) ($c['price_note'] ?? ''),
                'on' => !array_key_exists('on', $it) || !empty($it['on'])]; // AN2: Häkchen – nur angehakte Positionen zählen (Angebot, Summen, Planrechnung, PDF)
        }
        // Preise frei (AN2): Staffel, Kanal-Gebühr und Positions-Preise; Häkchen über options[] (beides nur aus der Eingabe, nie aus alten Daten nachgespielt)
        $po = self::clean_price_overrides(array_key_exists('price_overrides', $in) ? $in['price_overrides'] : ($old['price_overrides'] ?? null));
        if (is_wp_error($po)) $po = ['shop_tiers' => null, 'self_hosted_tiers' => null, 'server_month' => null, 'channel_fee' => null];
        foreach ((array) ($po['items'] ?? []) as $ref => $price) foreach ($items as &$x) if ($x['id'] === $ref || $x['catalog_key'] === $ref) $x['price'] = $price;
        unset($x);
        unset($po['items']);
        $tiers = $po['shop_tiers'] ?: ((array) ($cat['shop_tiers'] ?? []) ?: (class_exists('TIX_Offer_Plan') ? TIX_Offer_Plan::default_tiers() : []));
        usort($tiers, function ($a, $b) { return $a['max'] <=> $b['max']; });
        $sht = $po['self_hosted_tiers'] ?: ((array) ($cat['self_hosted_tiers'] ?? []) ?: $tiers);
        usort($sht, function ($a, $b) { return $a['max'] <=> $b['max']; });
        // Referenzen: nur freigegebene aus dem Katalog (Schlüssel-Liste)
        $refs = [];
        foreach ((array) (array_key_exists('references', $in) ? $in['references'] : ($old['references'] ?? [])) as $rk) {
            $rk = sanitize_key(is_array($rk) ? (string) ($rk['key'] ?? '') : (string) $rk);
            if ($rk !== '' && ($ref = self::reference($rk)) && $ref['approved'] && !in_array($rk, $refs, true)) $refs[] = $rk;
        }
        $extras = array_merge(['evendis_channel' => false], array_intersect_key((array) ($old['extras'] ?? []), self::EXTRAS));
        if (array_key_exists('options', $in) && is_array($in['options'])) {
            $set = [];
            $strings = true;
            foreach ($in['options'] as $o) {
                if (is_string($o)) $set[sanitize_key($o)] = true;
                elseif (is_array($o) && isset($o['key'])) { $strings = false; $set[sanitize_key((string) $o['key'])] = !empty($o['on']); }
            }
            foreach ($items as &$x) {
                $hit = array_key_exists($x['id'], $set) ? $x['id'] : (($x['catalog_key'] && array_key_exists($x['catalog_key'], $set)) ? $x['catalog_key'] : null);
                if ($hit !== null) $x['on'] = $set[$hit]; elseif ($strings) $x['on'] = false; // Liste von Schlüsseln = genau diese sind angehakt
            }
            unset($x);
            foreach (self::EXTRAS as $k => $label) if (array_key_exists($k, $set)) $extras[$k] = $set[$k]; elseif ($strings) $extras[$k] = false;
        }
        if (array_key_exists('extras', $in) && is_array($in['extras'])) foreach (self::EXTRAS as $k => $label) if (array_key_exists($k, $in['extras'])) $extras[$k] = !empty($in['extras'][$k]);
        // Betriebsart (Pflichtwahl im Studio; ohne Wahl aus den Positionen abgeleitet): platform | own_shop | self_hosted
        $mode_in = array_key_exists('mode', $in) ? (string) $in['mode'] : (!empty($old['mode_chosen']) ? (string) ($old['mode'] ?? '') : '');
        $mode_chosen = in_array($mode_in, self::MODES, true);
        $mode = $mode_chosen ? $mode_in : self::derive_mode(array_values(array_filter($items, function ($x) { return !empty($x['on']); })));
        // Unvollständig angelegt (1.38.821): gewählte Betriebsart ohne jede Position → Grundpaket aus den Vorgaben
        if ($mode_chosen && !array_filter($items, function ($x) { return !empty($x['on']); })) {
            $bk = ['platform' => ['tickets', null], 'own_shop' => ['shop', 'yearly'], 'self_hosted' => ['lizenz', 'yearly']][$mode];
            if ($n = self::item_from_catalog($bk[0], $bk[1])) { $n['on'] = true; $items[] = $n; }
        }
        // Ein Einrichtungsposten (User 14.11.): eigener Betrieb → Position „einrichtung“ immer im Angebot; eigener Shop 0 € inklusive, selbstgehostet Listenpreis (190 €) bzw. 0 € „erlassen“
        if (in_array($mode, self::OWN_MODES, true)) {
            $si = null;
            foreach ($items as $k => $x) if ($x['catalog_key'] === 'einrichtung') { $si = $k; break; }
            if ($si === null && ($n = self::item_from_catalog('einrichtung'))) { $n['on'] = true; $items[] = $n; $si = count($items) - 1; }
            if ($si !== null) {
                $c = self::catalog_item('einrichtung');
                $items[$si]['on'] = true;
                $items[$si]['interval'] = 'once';
                if ($mode === 'own_shop') { $items[$si]['list_price'] = 0.0; $items[$si]['price'] = 0.0; $items[$si]['note'] = 'inklusive beim eigenen Shop'; }
                else {
                    $items[$si]['list_price'] = isset($c['prices']['once']) ? floatval($c['prices']['once']) : null;
                    if (!empty($extras['setup_free'])) { $items[$si]['price'] = 0.0; $items[$si]['note'] = 'erlassen'; }
                    elseif ($items[$si]['note'] === 'erlassen' || $items[$si]['note'] === 'inklusive beim eigenen Shop') { $items[$si]['note'] = (string) ($c['price_note'] ?? ''); $items[$si]['price'] = null; } // erzwungene 0 € zurücknehmen
                }
            }
        }
        // Jahresvertrag nach Staffel (1.38.823, User 14.11.: „Beim Jahresvertrag macht der trotzdem 12 x 79“): Eigener Shop und Lizenz sind
        // immer jährlich; Listenpreis = Staffel bei der führenden Ticketzahl (plan.lead → eigene Zahl → Hochrechnung → Vorgabe), über der
        // höchsten Stufe kein Listenpreis („auf Anfrage“, Preis eintragen). Ein frei gesetzter Preis der Position bleibt.
        $plan = class_exists('TIX_Offer_Plan') ? TIX_Offer_Plan::clean($g('plan', null), $cat) : null;
        if (!is_array($plan)) $plan = null;
        $projection = self::clean_projection($g('projection', null), $items, $cat);
        $lt = self::tier_tickets($plan, $projection, $cat);
        foreach ($items as &$x) {
            if (!in_array($x['catalog_key'], self::TIERED_KEYS, true)) continue;
            $tl = $x['catalog_key'] === 'lizenz' ? $sht : $tiers;
            $x['interval'] = 'yearly';
            if ($tl) $x['list_price'] = $lt === null ? floatval($tl[0]['price']) : TIX_Offer_Plan::shop_price($lt, $tl);
        }
        unset($x);
        $layout = (string) $g('layout', 'auto');
        if (!in_array($layout, ['auto', 'own', 'classic'], true)) $layout = 'auto';

        $d = (array) $g('discount', []);
        $dt = in_array($d['type'] ?? '', ['percent', 'amount'], true) ? $d['type'] : 'none';
        $discount = ['type' => $dt, 'value' => $dt === 'none' ? 0 : (self::num($d['value'] ?? 0, 0) ?? 0), 'on' => in_array($d['on'] ?? '', ['all', 'recurring', 'once'], true) ? $d['on'] : 'all',
            'label' => self::txt($d['label'] ?? '', 80, false)];
        if ($dt === 'percent') $discount['value'] = min(100, $discount['value']);

        $benefits = [];
        $raw = $g('benefits', null);
        if ($raw === null) $raw = self::suggest_benefits(array_values(array_filter($items, function ($x) { return !empty($x['on']); })));
        foreach (array_slice((array) $raw, 0, 30) as $b) {
            if (!is_array($b)) continue;
            $t = self::txt($b['title'] ?? '', 120, false);
            if ($t === '') continue;
            $benefits[] = ['key' => sanitize_key((string) ($b['key'] ?? '')) ?: null, 'title' => $t, 'text' => self::txt($b['text'] ?? '', 400), 'on' => !array_key_exists('on', $b) || !empty($b['on'])];
        }

        $comparison = null;
        $cmp = $g('comparison', null);
        if (is_array($cmp) && !empty($cmp['competitor_id'])) {
            $cid = sanitize_key((string) $cmp['competitor_id']);
            foreach ((array) $cat['competitors'] as $co) {
                if ($co['id'] !== $cid) continue;
                $us = null;
                foreach ((array) $cat['competitors'] as $e) if (strpos($e['id'], 'evendis') === 0) { $us = $e; break; }
                $comparison = ['competitor_id' => $cid, 'name' => $co['name'], 'us' => $us ? $us['name'] : 'evendis', 'examples' => []];
                foreach ((array) $cat['comparison_examples'] as $i => $label) {
                    $comparison['examples'][] = ['label' => $label, 'them' => $co['examples'][$i] ?? null, 'us' => $us ? ($us['examples'][$i] ?? null) : null];
                }
                $comparison['note'] = (string) ($cat['comparison_note'] ?? '');
            }
        }

        $vu = (string) $g('valid_until', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vu)) $vu = wp_date('Y-m-d', time() + intval($cat['valid_days'] ?? 30) * DAY_IN_SECONDS);
        $ct = (array) $g('contact', []);
        $me = wp_get_current_user();
        $contact = ['name' => self::txt($ct['name'] ?? '', 120, false) ?: (string) ($me->display_name ?? ''), 'email' => sanitize_email((string) ($ct['email'] ?? '')) ?: (string) ($me->user_email ?? ''),
            'phone' => self::txt($ct['phone'] ?? '', 60, false)];
        $intro = $g('intro', null);
        $cond = $g('conditions', null);
        return [
            'customer' => $customer, 'items' => $items, 'discount' => $discount,
            'tax_rate' => max(0, min(30, floatval($g('tax_rate', $cat['tax_rate'] ?? 19)))),
            'term_months' => ($tm = intval($g('term_months', 0))) > 0 ? min(60, $tm) : null,
            'benefits' => $benefits, 'comparison' => $comparison, 'valid_until' => $vu, 'projection' => $projection,
            // Planrechnung (AN1): Eingaben; ungültige verwirft clean_data (die Routen prüfen vorher mit plan_error)
            'plan' => $plan,
            // Vergleich mit der Plattform nur auf ausdrücklichen Wunsch (1.38.823, User 14.11.: „nicht relevant“) – Standard aus
            'compare_platform' => $g('compare_platform', false) === true,
            // AN2: Häkchen (abgeleitete Liste fürs Studio), Zusatz-Optionen, frei gesetzte Preise, Gliederung des PDFs
            'options' => self::options_list($items, $extras), 'extras' => $extras, 'price_overrides' => $po, 'layout' => $layout,
            'mode' => $mode, 'mode_chosen' => $mode_chosen, 'references' => array_slice($refs, 0, 6),
            'intro' => $intro === null ? (string) $cat['intro'] : self::txt($intro, 3000),
            'conditions' => $cond === null ? (string) $cat['conditions'] : self::txt($cond, 3000),
            'contact' => $contact, 'accept_link' => (bool) $g('accept_link', true), 'internal_note' => self::txt($g('internal_note', ''), 2000),
        ];
    }

    const SELF_HOSTED_KEYS = ['shop', 'shop_monatlich', 'lizenz', 'app', 'app_modul', 'app_alle_module'];
    /** Grundpakete mit Jahresvertrag nach Staffel (1.38.823): Eigener Shop (Staffel eigener Shop) und Lizenz (Staffel selbstgehostet) */
    const TIERED_KEYS = ['shop', 'lizenz'];
    /** Einrichtung des Grundpakets – zählt zum Grundpaket, nicht zu den Optionen (wie FULL_SETUP_KEYS im Studio) */
    const FULL_SETUP_KEYS = ['einrichtung', 'rundum_einrichtung'];

    /** Tickets im Jahr, nach denen der Jahrespreis gilt: führende Stufe der Planrechnung (mit Vorgaben, wenn keine gespeichert ist) */
    public static function tier_tickets($plan, $projection, array $cat) {
        if (!class_exists('TIX_Offer_Plan')) return null;
        $p = is_array($plan) ? $plan : TIX_Offer_Plan::clean([], $cat);
        if (!is_array($p)) return null;
        $hint = (is_array($projection) && !empty($projection['tickets'])) ? intval($projection['tickets']) * max(1, intval($projection['events_year'] ?? 1)) : null;
        return TIX_Offer_Plan::lead_of($p, $hint);
    }

    /** Optional = Module und Zusatzleistungen (nicht die Einrichtung des Grundpakets); Grundpaket = Tarif, Einrichtung, eigene Positionen */
    public static function is_optional(array $it) {
        $type = (string) ($it['type'] ?? '');
        return $type === 'module' || ($type === 'service' && !in_array((string) ($it['catalog_key'] ?? ''), self::FULL_SETUP_KEYS, true));
    }

    /** Angebot nur mit dem Grundpaket (optionale Positionen abgehakt) – Faktenblock und Summe „Grundpaket“ */
    public static function base_data(array $d) {
        foreach ((array) ($d['items'] ?? []) as $i => $it) if (self::is_optional($it)) $d['items'][$i]['on'] = false;
        return $d;
    }

    public static function has_options(array $d) {
        foreach (self::active_items($d) as $it) if (self::is_optional($it)) return true;
        return false;
    }

    /** Zusatz-Optionen ohne eigene Position (AN2) */
    const EXTRAS = ['evendis_channel' => 'Zusätzlich über evendis.de verkaufen', 'setup_free' => 'Einrichtung kostenlos (selbstgehostet)'];
    /** Grundpaket des eigenen Betriebs (Planrechnung „an evendis“) */
    const BASE_KEYS = ['shop', 'shop_monatlich', 'lizenz'];

    /** Häkchen-Liste fürs Studio: jede Position (key = Positions-ID) und die Zusatz-Optionen */
    public static function options_list(array $items, array $extras) {
        $out = [];
        foreach ($items as $x) $out[] = ['key' => $x['id'], 'kind' => 'item', 'item_id' => $x['id'], 'catalog_key' => $x['catalog_key'], 'label' => $x['name'], 'on' => !empty($x['on'])];
        foreach (self::EXTRAS as $k => $label) $out[] = ['key' => $k, 'kind' => 'extra', 'item_id' => null, 'catalog_key' => null, 'label' => $label, 'on' => !empty($extras[$k])];
        return $out;
    }

    /** Nur angehakte Positionen */
    public static function active_items(array $d) {
        return array_values(array_filter((array) ($d['items'] ?? []), function ($x) { return !array_key_exists('on', $x) || !empty($x['on']); }));
    }

    /**
     * price_overrides {shop_tiers: [{max, price}] | null, channel_fee: {percent, fixed} | null, items: {<Positions-ID oder catalog_key>: Preis}}
     * → array | WP_Error (Feld price_overrides.*). null = keine.
     */
    public static function clean_price_overrides($in) {
        $out = ['shop_tiers' => null, 'self_hosted_tiers' => null, 'server_month' => null, 'channel_fee' => null, 'items' => []];
        if ($in === null || $in === '' || $in === false) return $out;
        $bad = function ($f, $m) { return new WP_Error('tix_invalid_field', $m, ['status' => 400, 'field' => 'price_overrides' . ($f !== '' ? '.' . $f : '')]); };
        if (!is_array($in)) return $bad('', 'Preise: Objekt erwartet.');
        $num = function ($v, $mn, $mx) { if (!is_numeric($v)) return null; $v = floatval($v); return ($v < $mn || $v > $mx) ? null : $v; };
        foreach (['shop_tiers', 'self_hosted_tiers'] as $tk) {
            if (!isset($in[$tk]) || $in[$tk] === null) continue;
            if (!is_array($in[$tk]) || !$in[$tk] || count($in[$tk]) > 8) return $bad($tk, 'Staffel: 1 bis 8 Stufen.');
            $tiers = []; $seen = [];
            foreach (array_values($in[$tk]) as $i => $x) {
                $mx = is_array($x) ? intval($x['max'] ?? 0) : 0;
                $pr = is_array($x) ? $num($x['price'] ?? null, 0, 1000000) : null;
                if ($mx < 1 || $mx > 10000000 || isset($seen[$mx])) return $bad("$tk.$i.max", 'Bis-Tickets 1 bis 10.000.000, jede Stufe einmal.');
                if ($pr === null) return $bad("$tk.$i.price", 'Preis 0 bis 1.000.000 €.');
                $seen[$mx] = true; $tiers[] = ['max' => $mx, 'price' => round($pr, 2)];
            }
            usort($tiers, function ($a, $b) { return $a['max'] <=> $b['max']; });
            $out[$tk] = $tiers;
        }
        if (isset($in['server_month']) && $in['server_month'] !== null) {
            $sm = $num($in['server_month'], 0, 10000);
            if ($sm === null) return $bad('server_month', 'Server-Kosten je Monat 0 bis 10.000 €.');
            $out['server_month'] = round($sm, 2);
        }
        if (isset($in['channel_fee']) && $in['channel_fee'] !== null) {
            $cf = (array) $in['channel_fee'];
            $pc = $num($cf['percent'] ?? null, 0, 30); $fx = $num($cf['fixed'] ?? null, 0, 10);
            if ($pc === null) return $bad('channel_fee.percent', 'Prozent 0 bis 30.');
            if ($fx === null) return $bad('channel_fee.fixed', 'Fester Betrag 0 bis 10 €.');
            $out['channel_fee'] = ['percent' => round($pc, 2), 'fixed' => round($fx, 2)];
        }
        if (isset($in['items'])) {
            if (!is_array($in['items'])) return $bad('items', 'Positions-Preise: Objekt {Position: Preis}.');
            foreach ($in['items'] as $ref => $pr) {
                $ref = sanitize_key((string) $ref);
                $v = $pr === null ? null : $num($pr, 0, 1000000);
                if ($pr !== null && $v === null) return $bad("items.$ref", 'Preis 0 bis 1.000.000 €.');
                if ($ref !== '') $out['items'][$ref] = $v === null ? null : round($v, 2);
            }
        }
        return $out;
    }

    /** Kanal-Gebühr „zusätzlich über evendis.de“ (frei gesetzt oder Seiten-Einstellung) */
    public static function channel_fee(array $d) {
        $o = $d['price_overrides']['channel_fee'] ?? null;
        if (is_array($o)) return $o;
        $s = class_exists('TIX_Channel_Fee') ? TIX_Channel_Fee::settings() : ['percent' => 2.9, 'fixed' => 0.49];
        return ['percent' => floatval($s['percent']), 'fixed' => floatval($s['fixed'])];
    }

    /** Gliederung: own (eigener Betrieb) oder classic (bisher); auto = own bei Betriebsart eigener Shop bzw. selbstgehostet */
    public static function layout_of(array $d) {
        $l = (string) ($d['layout'] ?? 'auto');
        if ($l === 'own' || $l === 'classic') return $l;
        return in_array(self::mode_of($d), ['own_shop', 'self_hosted'], true) ? 'own' : 'classic';
    }

    /** Betriebsart aus den angehakten Positionen: Lizenz → self_hosted, Shop → own_shop, sonst platform */
    public static function derive_mode(array $active_items) {
        $keys = array_column($active_items, 'catalog_key');
        if (in_array('lizenz', $keys, true)) return 'self_hosted';
        if (array_intersect($keys, ['shop', 'shop_monatlich'])) return 'own_shop';
        return 'platform';
    }

    public static function mode_of(array $d) {
        $m = (string) ($d['mode'] ?? '');
        return in_array($m, self::MODES, true) ? $m : self::derive_mode(self::active_items($d));
    }

    /** Grundpaket je Betriebsart */
    public static function base_keys($mode) {
        return $mode === 'self_hosted' ? ['lizenz'] : ($mode === 'own_shop' ? ['shop', 'shop_monatlich'] : []);
    }

    /** Hinweise, wenn Positionen nicht zur Betriebsart passen (kein Fehler) */
    public static function mode_hints(array $d) {
        $m = self::mode_of($d);
        $keys = array_column(self::active_items($d), 'catalog_key');
        $h = [];
        if ($m === 'own_shop' && in_array('lizenz', $keys, true)) $h[] = ['code' => 'tix_offer_mode_mismatch', 'message' => 'Die Lizenz gehört zu „Selbstgehostet“, nicht zum eigenen Shop.'];
        if ($m === 'self_hosted' && array_intersect($keys, ['shop', 'shop_monatlich'])) $h[] = ['code' => 'tix_offer_mode_mismatch', 'message' => 'Der eigene Shop läuft auf unseren Servern – bei „Selbstgehostet“ gehört die Lizenz ins Angebot.'];
        if ($m === 'platform' && array_intersect($keys, ['shop', 'shop_monatlich', 'lizenz'])) $h[] = ['code' => 'tix_offer_mode_mismatch', 'message' => 'Im Angebot steht ein eigener Betrieb – Betriebsart prüfen.'];
        return $h;
    }

    /** Positionen, die es in der Betriebsart nicht gibt (Katalog „modes“, z. B. App nur beim eigenen Betrieb, Hosting nur beim eigenen Shop) → WP_Error | null */
    public static function mode_error(array $d) {
        $m = self::mode_of($d);
        foreach ((array) $d['items'] as $i => $x) {
            if (array_key_exists('on', $x) && empty($x['on'])) continue;
            $c = !empty($x['catalog_key']) ? self::catalog_item($x['catalog_key']) : null;
            $ms = (array) ($c['modes'] ?? []);
            if ($ms && !in_array($m, $ms, true)) {
                $label = implode(' bzw. ', array_map(function ($k) { return self::MODE_TEXTS[$k]['label']; }, $ms));
                return new WP_Error('tix_offer_item_mode', '„' . $x['name'] . '“ gibt es nur bei ' . $label . '.', ['status' => 400, 'field' => "items.$i", 'mode' => $m, 'allowed' => $ms]);
            }
        }
        return null;
    }

    /**
     * Was zum Senden fehlt (1.38.821) → [{field, code, message}]. Leer = vollständig.
     * Geprüft nur beim Versenden (User 14.11.: PDF immer exportierbar). Gültigkeit hat einen eigenen Fehler (409).
     */
    public static function missing(array $d, $to = null) {
        $m = [];
        $cu = (array) ($d['customer'] ?? []);
        if (trim((string) ($cu['company'] ?? '')) === '' && trim((string) ($cu['name'] ?? '')) === '') $m[] = ['field' => 'customer.company', 'code' => 'name', 'message' => 'Name bzw. Firma des Kunden fehlt.'];
        $mail = $to !== null ? $to : (string) ($cu['email'] ?? '');
        if (!is_email($mail)) $m[] = ['field' => 'customer.email', 'code' => 'email', 'message' => 'E-Mail-Adresse des Kunden fehlt oder ist ungültig.'];
        if (empty($d['mode_chosen'])) $m[] = ['field' => 'mode', 'code' => 'mode', 'message' => 'Bitte die Betriebsart wählen (Plattform, eigener Shop oder selbstgehostet).'];
        if (!self::active_items($d)) $m[] = ['field' => 'items', 'code' => 'items', 'message' => 'Das Angebot hat noch keine Positionen.'];
        else {
            $t = self::totals($d);
            foreach ($t['missing_prices'] as $id) foreach ((array) $d['items'] as $i => $x) if ($x['id'] === $id) $m[] = ['field' => "items.$i.price", 'code' => 'price', 'message' => '„' . $x['name'] . '“: Preis fehlt.', 'item_id' => $id];
        }
        return $m;
    }

    /** 422 tix_offer_incomplete mit data.missing[] (und data.field = erstes fehlendes Feld) | null */
    public static function incomplete_error(array $d, $to = null) {
        $m = self::missing($d, $to);
        if (!$m) return null;
        return new WP_Error('tix_offer_incomplete', 'Bitte noch ergänzen: ' . implode(' ', array_column($m, 'message')), ['status' => 422, 'field' => $m[0]['field'], 'missing' => $m]);
    }

    public static function reference($key) {
        foreach ((array) (self::catalog()['references'] ?? []) as $r) if (($r['key'] ?? '') === $key) return $r;
        return null;
    }
    const MODELS = ['commission', 'selfhosted'];

    /**
     * Eingaben der Hochrechnung je Event (Feldnamen wie im Studio) → array | null (keine Prognose).
     * projection {model commission|selfhosted, tickets, avg_price, events_year, tickets_per_order, payment paypal|card|both,
     *             paypal_share, card_klarna_share, bearer guest|split|organizer, in_pdf}
     */
    public static function clean_projection($in, array $items, array $cat) {
        if (!is_array($in) || $in === []) return null;
        $tickets = max(0, min(1000000, intval($in['tickets'] ?? 0)));
        if ($tickets <= 0) return null;
        $fd = (array) ($cat['projection_defaults'] ?? []);
        $keys = array_filter(array_column($items, 'catalog_key'));
        $auto = array_intersect($keys, self::SELF_HOSTED_KEYS) ? 'selfhosted' : 'commission';
        $pp = array_key_exists('paypal_share', $in) ? intval($in['paypal_share']) : (array_key_exists('card_klarna_share', $in) ? 100 - intval($in['card_klarna_share']) : intval($fd['paypal_share'] ?? 30));
        $pp = max(0, min(100, $pp));
        return [
            'model' => in_array($in['model'] ?? '', self::MODELS, true) ? $in['model'] : $auto,
            'tickets' => $tickets,
            'avg_price' => self::num($in['avg_price'] ?? 0, 0) ?? 0.0,
            'events_year' => max(1, min(365, intval($in['events_year'] ?? ($fd['events_year'] ?? 12)))),
            'tickets_per_order' => max(1, min(20, intval($in['tickets_per_order'] ?? ($fd['tickets_per_order'] ?? 1)))),
            'payment' => in_array($in['payment'] ?? '', ['paypal', 'card', 'both'], true) ? $in['payment'] : 'both',
            'paypal_share' => $pp,
            'card_klarna_share' => 100 - $pp,
            'bearer' => in_array($in['bearer'] ?? '', ['guest', 'split', 'organizer'], true) ? $in['bearer'] : 'organizer',
            'in_pdf' => !array_key_exists('in_pdf', $in) || !empty($in['in_pdf']),
        ];
    }

    /**
     * Hochrechnung je Event (Nachtrag 06.11., Rechnung wie projectionCalc im Studio): Provisionsmodell gegen Selbstgehostet.
     *  - Provisionsmodell: Gebühr je verkauftem Ticket inkl. MwSt. und Zahlung (Position `tickets`), Freitickets 0 €, keine weiteren Zahlungskosten.
     *  - Selbstgehostet: wiederkehrende Summe des Angebots inkl. MwSt. (ohne Shop/Lizenz/App im Angebot: Eigener Shop × 12 inkl. MwSt.)
     *    ÷ Events im Jahr, dazu der Zahlungsdienstleister je Zahlung: Fixbetrag ÷ Tickets je Bestellung; „Karte & Klarna“ je zur Hälfte.
     *  - Vergleich inkl. MwSt.; lohnt sich ab = Paket im Monat ÷ (Provision je Ticket − Zahlungskosten je Ticket), aufgerundet.
     */
    public static function projection(array $d, $yearly = null) {
        $f = $d['projection'] ?? null;
        if (!is_array($f) || empty($f['tickets'])) return null;
        $cat = self::catalog();
        $vat = 1 + floatval($cat['tax_rate'] ?? 19) / 100;
        $T = intval($f['tickets']); $P = floatval($f['avg_price']); $E = max(1, intval($f['events_year']));
        $rev = round($T * $P, 2);
        // Provisionsmodell
        $fp = null; $ff = null;
        foreach (self::active_items($d) as $it) if (($it['catalog_key'] ?? '') === 'tickets' && $it['interval'] === 'per_ticket') { $fp = $it['fee_percent']; $ff = $it['fee_fixed']; break; }
        if ($fp === null) { $pl = self::catalog_item('tickets'); $fp = $pl['fee_percent'] ?? 2.9; $ff = $pl['fee_fixed'] ?? 0.49; }
        $fp = floatval($fp); $ff = floatval($ff);
        $fee_t = $P > 0 ? $P * $fp / 100 + $ff : 0.0;
        $pg = round($fee_t * $T, 2);
        $share = ['organizer' => 1.0, 'split' => 0.5, 'guest' => 0.0][$f['bearer']];
        $com = ['fee_percent' => $fp, 'fee_fixed' => $ff, 'fee_per_ticket' => round($fee_t, 2), 'to_evendis_event' => $pg, 'to_psp_event' => 0.0, 'total_event' => $pg,
            'bearer' => $f['bearer'], 'organizer_pays' => round($pg * $share, 2), 'guests_pay' => round($pg * (1 - $share), 2),
            'per_ticket' => $T > 0 ? round($pg / $T, 2) : null, 'percent_of_revenue' => $rev > 0 ? round($pg / $rev * 100, 2) : null, 'per_year' => round($pg * $E, 2),
            'tax_note' => 'inkl. MwSt. und Zahlung'];
        // Selbstgehostet: wiederkehrende Summe des Angebots (brutto, nach Rabatt), sonst Katalog „Eigener Shop“ × 12
        $names = [];
        foreach (self::active_items($d) as $it) if (in_array($it['catalog_key'] ?? '', self::SELF_HOSTED_KEYS, true) && in_array($it['interval'], ['monthly', 'yearly'], true)) $names[] = $it['name'];
        $src = 'offer';
        $pkg = ($names && is_array($yearly)) ? floatval($yearly['gross']) : 0.0;
        if ($pkg <= 0) {
            $sh = self::catalog_item('shop');
            $pkg = floatval($sh['prices']['monthly'] ?? 0) * 12 + floatval($sh['prices']['yearly'] ?? 0);
            if ($pkg <= 0) { $st = (array) ($cat['shop_tiers'] ?? []) ?: (class_exists('TIX_Offer_Plan') ? TIX_Offer_Plan::default_tiers() : []); usort($st, function ($a, $b) { return $a['max'] <=> $b['max']; }); $pkg = floatval($st[0]['price'] ?? 0); } // Jahresvertrag: 1. Staffel-Stufe (1.38.823)
            $pkg *= $vat;
            $names = [$sh['name'] ?? 'Eigener Shop'];
            $src = 'catalog';
        }
        $pkg = round($pkg, 2);
        $rates = self::rates_by_key($cat['payment_rates'] ?? []);
        $pp = $f['payment'] === 'paypal' ? 1.0 : ($f['payment'] === 'card' ? 0.0 : $f['paypal_share'] / 100);
        $rest = 1 - $pp;
        $kw = max(0, min(100, intval($cat['projection_defaults']['klarna_within'] ?? 50))) / 100; // „Karte & Klarna“ je zur Hälfte
        $mix = ['paypal' => $pp, 'card' => $rest * (1 - $kw), 'klarna' => $rest * $kw];
        $orders = $T / max(1, intval($f['tickets_per_order']));
        $methods = []; $missing = []; $psp_raw = 0.0;
        foreach ($mix as $k => $sh) {
            if ($sh <= 0) continue;
            $r = ($rates[$k] ?? []) + ['label' => $k, 'provider' => '', 'detail' => '', 'percent' => null, 'fixed' => null, 'as_of' => '', 'retrieved' => '', 'source' => ''];
            $ok = $r['percent'] !== null && $r['fixed'] !== null;
            if (!$ok) $missing[] = $k;
            $raw = $ok ? $sh * ($rev * floatval($r['percent']) / 100 + $orders * floatval($r['fixed'])) : null;
            if ($ok) $psp_raw += $raw;
            $methods[] = ['key' => $k, 'label' => $r['label'], 'provider' => $r['provider'], 'detail' => $r['detail'], 'share' => round($sh * 100, 1),
                'percent' => $r['percent'], 'fixed' => $r['fixed'], 'as_of' => $r['as_of'], 'source' => $r['source'], 'cost_event' => $raw === null ? null : round($raw, 2), 'rate_missing' => !$ok];
        }
        $ev = round($pkg / $E, 2);
        $psp = $missing ? null : round($psp_raw, 2);
        $tot = $missing ? null : round($ev + $psp, 2); // Summe der gerundeten Teile (wie das Studio)
        $self = ['package' => implode(' + ', $names), 'package_source' => $src, 'package_year' => $pkg, 'to_evendis_event' => $ev, 'to_psp_event' => $psp,
            'total_event' => $tot, 'per_ticket' => $tot !== null && $T > 0 ? round($tot / $T, 2) : null, 'percent_of_revenue' => $tot !== null && $rev > 0 ? round($tot / $rev * 100, 2) : null,
            'per_year' => $tot !== null ? round($tot * $E, 2) : null, 'methods' => $methods, 'missing_rates' => $missing,
            'tax_note' => 'evendis inkl. MwSt.; Zahlungsgebühren wie beim Anbieter'];
        $cmp = ['cheaper' => null, 'difference_event' => null, 'difference_year' => null, 'break_even_tickets_month' => null];
        if ($tot !== null) {
            $diff = round($pg - $tot, 2);
            $cmp['cheaper'] = abs($diff) < 0.005 ? 'equal' : ($diff > 0 ? 'selfhosted' : 'commission');
            $cmp['difference_event'] = abs($diff); $cmp['difference_year'] = round(abs($diff) * $E, 2);
            $q = $T > 0 ? $psp_raw / $T : 0.0;  // Zahlungskosten je Ticket
            if ($fee_t - $q > 0.0001) $cmp['break_even_tickets_month'] = intval(ceil(($pkg / 12) / ($fee_t - $q)));
        }
        $asof = array_filter(array_column($methods, 'as_of'));
        return ['input' => $f, 'revenue_event' => $rev, 'revenue_year' => round($rev * $E, 2), 'commission' => $com, 'selfhosted' => $self, 'comparison' => $cmp,
            'payment_note' => (string) ($cat['payment_note'] ?? ''),
            'rates_note' => $asof ? 'Sätze Stand ' . wp_date('d.m.Y', strtotime(min($asof))) . ', können sich beim Anbieter ändern.' : 'Sätze können sich beim Anbieter ändern.',
            'basis_note' => 'Alle Beträge inkl. MwSt. Zahlungskosten je Zahlung bei ' . intval($f['tickets_per_order']) . ' Ticket(s) je Bestellung; Kreditkarte und Klarna je zur Hälfte.'];
    }

    /** payment_rates als Objekt {paypal: {...}, card: {...}, klarna: {...}}; nimmt auch die Liste [{key, ...}] an. */
    public static function rates_by_key($rates) {
        $out = [];
        foreach ((array) $rates as $k => $r) {
            if (!is_array($r)) continue;
            $key = is_string($k) && !is_numeric($k) ? $k : (string) ($r['key'] ?? '');
            if ($key !== '') $out[sanitize_key($key)] = $r;
        }
        return $out;
    }

    /** Vorteile passend zu den Positionen (Katalog-Reihenfolge), dazu die allgemeinen. */
    public static function suggest_benefits(array $items) {
        $cat = self::catalog();
        $want = [];
        foreach ($items as $it) {
            $c = !empty($it['catalog_key']) ? self::catalog_item($it['catalog_key']) : null;
            if ($c) foreach ((array) $c['benefits'] as $k) $want[$k] = true;
        }
        foreach ((array) $cat['general_benefits'] as $k) $want[$k] = true;
        $out = [];
        foreach ((array) $cat['benefits'] as $b) if (isset($want[$b['key']])) $out[] = ['key' => $b['key'], 'title' => $b['title'], 'text' => $b['text'], 'on' => true];
        return $out;
    }

    /**
     * Summen: wiederkehrend aufs Jahr gerechnet, einmalig getrennt; Gebühren je Ticket stehen extra.
     * 1.38.823 (User 14.11.: „muss klar sein dass es optional ist“): je Zeile optional; base = nur Grundpaket (Tarif, Einrichtung, eigene
     * Positionen), with_options = mit den gewählten Optionen (= yearly/once wie bisher), options = Differenz, has_options.
     */
    public static function totals(array $d) {
        $t = self::sum_items($d);
        $items = (array) ($d['items'] ?? []);
        foreach ($t['lines'] as $i => $ln) $t['lines'][$i]['optional'] = isset($items[$i]) && self::is_optional($items[$i]);
        $has = self::has_options($d);
        $tb = $has ? self::sum_items(self::base_data($d)) : $t;
        $part = function ($x) { return ['yearly' => $x['yearly'], 'once' => $x['once'], 'monthly_net' => $x['monthly_net'], 'first_year_net' => $x['first_year_net']]; };
        $t['base'] = $part($tb);
        $t['with_options'] = $part($t);
        $t['has_options'] = $has;
        $t['options'] = ['yearly_net' => round($t['yearly']['net_after'] - $tb['yearly']['net_after'], 2), 'yearly_gross' => round($t['yearly']['gross'] - $tb['yearly']['gross'], 2),
            'once_net' => round($t['once']['net_after'] - $tb['once']['net_after'], 2), 'once_gross' => round($t['once']['gross'] - $tb['once']['gross'], 2)];
        $t['projection'] = self::projection($d, $t['yearly']);
        return $t;
    }

    private static function sum_items(array $d) {
        $rate = floatval($d['tax_rate'] ?? 19);
        $lines = [];
        $year = 0.0; $once = 0.0; $missing = []; $fees = [];
        foreach ((array) $d['items'] as $it) {
            if (array_key_exists('on', $it) && empty($it['on'])) { $lines[] = ['id' => $it['id'], 'on' => false, 'unit' => null, 'net' => null, 'year' => null, 'once' => null, 'overridden' => false]; continue; } // nicht angehakt (AN2)
            $unit = $it['price'] !== null ? floatval($it['price']) : ($it['list_price'] !== null ? floatval($it['list_price']) : null);
            if ($it['interval'] === 'per_ticket') {
                $fees[] = ['id' => $it['id'], 'name' => $it['name'], 'fee_percent' => $it['fee_percent'], 'fee_fixed' => $it['fee_fixed']];
                $lines[] = ['id' => $it['id'], 'on' => true, 'unit' => null, 'net' => null, 'year' => null, 'once' => null, 'overridden' => false];
                continue;
            }
            if ($unit === null) { $missing[] = $it['id']; $lines[] = ['id' => $it['id'], 'on' => true, 'unit' => null, 'net' => null, 'year' => null, 'once' => null, 'overridden' => false]; continue; }
            $net = round($unit * intval($it['qty']), 2);
            $y = $it['interval'] === 'monthly' ? round($net * 12, 2) : ($it['interval'] === 'yearly' ? $net : 0.0);
            $o = in_array($it['interval'], ['once', 'per_event'], true) ? $net : 0.0;
            $year += $y; $once += $o;
            $lines[] = ['id' => $it['id'], 'on' => true, 'unit' => $unit, 'net' => $net, 'year' => $y, 'once' => $o,
                'overridden' => $it['price'] !== null && $it['list_price'] !== null && abs(floatval($it['price']) - floatval($it['list_price'])) > 0.004];
        }
        $dc = (array) ($d['discount'] ?? []);
        $dy = 0.0; $do = 0.0;
        $type = $dc['type'] ?? 'none'; $val = floatval($dc['value'] ?? 0); $on = $dc['on'] ?? 'all';
        if ($type === 'percent' && $val > 0) {
            if ($on !== 'once') $dy = round($year * $val / 100, 2);
            if ($on !== 'recurring') $do = round($once * $val / 100, 2);
        } elseif ($type === 'amount' && $val > 0) {
            if ($on === 'recurring') $dy = min($year, $val);
            elseif ($on === 'once') $do = min($once, $val);
            else { $sum = $year + $once; if ($sum > 0) { $dy = min($year, round($val * $year / $sum, 2)); $do = min($once, round(min($val, $sum) - $dy, 2)); } }
        }
        $b = function ($net, $disc) use ($rate) {
            $after = round(max(0, $net - $disc), 2);
            $tax = round($after * $rate / 100, 2);
            return ['net' => round($net, 2), 'discount' => round($disc, 2), 'net_after' => $after, 'tax' => $tax, 'gross' => round($after + $tax, 2)];
        };
        $yb = $b($year, $dy);
        return ['lines' => $lines, 'yearly' => $yb, 'once' => $b($once, $do), 'monthly_net' => round($yb['net_after'] / 12, 2),
            'first_year_net' => round($yb['net_after'] + max(0, $once - $do), 2), 'tax_rate' => $rate, 'missing_prices' => $missing, 'fees' => $fees];
    }

    public static function plan_name(array $d) {
        $act = self::active_items($d) ?: (array) ($d['items'] ?? []);
        foreach ($act as $it) if (($it['type'] ?? '') === 'plan') return (string) $it['name'];
        return (string) (($act[0]['name'] ?? ''));
    }

    // ═══════════════ Speicher ═══════════════

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', intval($id)));
    }

    public static function by_token($token) {
        global $wpdb;
        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $token);
        if (strlen($token) < 20) return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE token = %s', $token));
    }

    public static function data($row) {
        $d = json_decode((string) $row->data, true);
        return is_array($d) ? $d : [];
    }

    /** Nächste Nummer AN-JJJJ-NNNN (je Jahr fortlaufend, unter Sperre). */
    public static function next_number() {
        global $wpdb;
        $lock = 'tix_offer_seq_' . $wpdb->prefix;
        $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock));
        wp_cache_delete(self::OPT_SEQ, 'options');
        $seq = (array) get_option(self::OPT_SEQ, []);
        $y = wp_date('Y');
        $n = intval($seq[$y] ?? 0) + 1;
        // Nie hinter vorhandene Nummern zurückfallen (z. B. nach Zurücksetzen der Option)
        $prefix = (string) (self::catalog()['number_prefix'] ?? 'AN');
        $max = $wpdb->get_var($wpdb->prepare('SELECT MAX(CAST(SUBSTRING_INDEX(base_number, \'-\', -1) AS UNSIGNED)) FROM ' . self::table() . ' WHERE base_number LIKE %s', $wpdb->esc_like("$prefix-$y-") . '%'));
        $n = max($n, intval($max) + 1);
        $seq[$y] = $n;
        update_option(self::OPT_SEQ, $seq, false);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        return sprintf('%s-%s-%04d', $prefix, $y, $n);
    }

    private static function columns(array $d) {
        $t = self::totals($d);
        return ['company' => mb_substr($d['customer']['company'] ?: $d['customer']['name'], 0, 190), 'contact_name' => mb_substr($d['customer']['name'], 0, 190),
            'contact_email' => $d['customer']['email'], 'plan' => mb_substr(self::plan_name($d), 0, 190), 'total_once' => $t['once']['gross'], 'total_year' => $t['yearly']['gross'],
            'valid_until' => $d['valid_until'], 'data' => wp_json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => current_time('mysql')];
    }

    public static function insert(array $d, array $extra = []) {
        global $wpdb;
        $base = $extra['base_number'] ?? self::next_number();
        $ver = intval($extra['version'] ?? 1);
        $row = self::columns($d) + ['number' => $ver > 1 ? "$base-$ver" : $base, 'base_number' => $base, 'version' => $ver, 'status' => 'draft',
            'owner_id' => intval($extra['owner_id'] ?? get_current_user_id()), 'created_at' => current_time('mysql')];
        $wpdb->insert(self::table(), $row);
        return intval($wpdb->insert_id);
    }

    public static function update_data($id, array $d) {
        global $wpdb;
        $wpdb->update(self::table(), self::columns($d), ['id' => intval($id)]);
    }

    private static function set($id, array $cols) {
        global $wpdb;
        $cols['updated_at'] = current_time('mysql');
        $wpdb->update(self::table(), $cols, ['id' => intval($id)]);
    }

    /** Ist das Angebot abgelaufen (Gültigkeit vorbei)? */
    public static function is_expired($row) {
        return $row->valid_until && $row->valid_until < wp_date('Y-m-d');
    }

    public static function expire_due() {
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . " SET status = 'expired', updated_at = %s WHERE status IN ('sent','viewed') AND valid_until IS NOT NULL AND valid_until < %s", current_time('mysql'), wp_date('Y-m-d')));
    }

    // ═══════════════ Ausgabe ═══════════════

    public static function accept_url($token) {
        $url = add_query_arg('tix_offer', $token, home_url('/'));
        return (string) apply_filters('tix_offer_accept_url', $url, $token);
    }

    private static function iso($mysql) {
        return $mysql ? mysql2date('c', $mysql, false) : null;
    }

    public static function summary($row) {
        $d = self::data($row);
        $owner = get_userdata(intval($row->owner_id));
        $status = $row->status;
        if (in_array($status, ['sent', 'viewed'], true) && self::is_expired($row)) $status = 'expired';
        return ['id' => intval($row->id), 'number' => (string) $row->number, 'base_number' => (string) $row->base_number, 'version' => intval($row->version), 'status' => $status,
            'company' => (string) $row->company, 'contact_name' => (string) $row->contact_name, 'contact_email' => (string) $row->contact_email, 'plan' => (string) $row->plan,
            'total_once' => floatval($row->total_once), 'total_year' => floatval($row->total_year), 'valid_until' => $row->valid_until,
            'views' => intval($row->views), 'viewed_at' => self::iso($row->viewed_at), 'sent_at' => self::iso($row->sent_at), 'decided_at' => self::iso($row->decided_at),
            'created_at' => self::iso($row->created_at), 'updated_at' => self::iso($row->updated_at),
            'owner' => $owner ? ['id' => intval($owner->ID), 'name' => (string) $owner->display_name] : null,
            // Vollständigkeit (1.38.821): Anlegen geht mit einem Namen, geprüft wird erst beim Senden bzw. Kunden-PDF
            'complete' => !($mi = self::missing($d)), 'missing' => array_column($mi, 'code'),
            'editable' => $row->status === 'draft', 'accept_link' => !empty($d['accept_link']), 'customer_key' => !empty($d['customer_key']) ? (string) $d['customer_key'] : null];
    }

    public static function full($row) {
        $d = self::data($row);
        $s = self::summary($row);
        $s['data'] = $d;
        $s['totals'] = self::totals($d);
        $s['plan_calc'] = self::plan_result($d); // Planrechnung (AN1); 'plan' bleibt der Tarifname
        $s['mode'] = self::mode_of($d); // Betriebsart (1.38.818)
        $s['mode_hints'] = self::mode_hints($d);
        $s['decision'] = $d['decision'] ?? null;
        $s['history'] = array_values((array) ($d['history'] ?? []));
        $s['link'] = ($row->token && !empty($d['accept_link'])) ? self::accept_url($row->token) : null;
        $s['link_expires'] = self::iso($row->token_expires);
        $s['pdf_filename'] = self::filename($row);
        return $s;
    }

    private static function history(array &$d, $what, array $extra = []) {
        $d['history'] = array_slice(array_merge((array) ($d['history'] ?? []), [['at' => gmdate('c'), 'what' => $what, 'by' => get_current_user_id()] + $extra]), -50);
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $p = ['permission_callback' => [__CLASS__, 'perm']];
        $pub = ['permission_callback' => [__CLASS__, 'perm_public']];
        $id = '(?P<id>\d+)';
        register_rest_route(self::NS, '/platform/offer-catalog', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_catalog']] + $p,
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_catalog_save'], 'permission_callback' => [__CLASS__, 'perm_admin']],
        ]);
        register_rest_route(self::NS, '/platform/offers', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list']] + $p,
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_create']] + $p,
        ]);
        register_rest_route(self::NS, '/platform/offers/calc', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_calc']] + $p);
        register_rest_route(self::NS, '/platform/offers/plan', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_plan']] + $p); // Planrechnung (AN1, 1.38.816)
        register_rest_route(self::NS, '/platform/offer-references', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_references']] + $p); // Referenzen + Vorschläge aus der Kundenliste (1.38.818)
        register_rest_route(self::NS, '/platform/offers/preview', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_preview_draft']] + $p);
        register_rest_route(self::NS, '/platform/offers/intro', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_intro']] + $p);
        register_rest_route(self::NS, "/platform/offers/$id", [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get']] + $p,
            ['methods' => 'POST,PATCH', 'callback' => [__CLASS__, 'rest_update']] + $p,
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_delete']] + $p,
        ]);
        register_rest_route(self::NS, "/platform/offers/$id/pdf", ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_pdf']] + $p);
        register_rest_route(self::NS, "/platform/offers/$id/send", ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_send']] + $p);
        register_rest_route(self::NS, "/platform/offers/$id/duplicate", ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_duplicate']] + $p);
        register_rest_route(self::NS, "/platform/offers/$id/version", ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_version']] + $p);
        register_rest_route(self::NS, "/platform/offers/$id/status", ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_status']] + $p);
        $tk = '(?P<token>[A-Za-z0-9]{20,64})';
        register_rest_route(self::NS, "/public/offers/$tk", ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_public_get']] + $pub);
        register_rest_route(self::NS, "/public/offers/$tk/pdf", ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_public_pdf']] + $pub);
        register_rest_route(self::NS, "/public/offers/$tk/interest", ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_public_accept']] + $pub);
        register_rest_route(self::NS, "/public/offers/$tk/accept", ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_public_accept']] + $pub); // gleiche Wirkung wie /interest
        register_rest_route(self::NS, "/public/offers/$tk/decline", ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_public_decline']] + $pub);
    }

    private static function body(WP_REST_Request $req) {
        $b = $req->get_json_params();
        return is_array($b) ? $b : $req->get_params();
    }

    private static function load(WP_REST_Request $req) {
        $row = self::get($req['id']);
        if (!$row || !self::can_see($row)) return new WP_Error('tix_offer_not_found', 'Angebot nicht gefunden.', ['status' => 404]);
        return $row;
    }

    public static function rest_catalog(WP_REST_Request $req) {
        $stored = get_option(self::OPT_CATALOG, null);
        return rest_ensure_response(['ok' => true, 'catalog' => self::catalog(), 'custom' => is_array($stored) && !empty($stored['items']), 'can_edit' => self::is_admin_user(),
            'intervals' => self::INTERVALS, 'statuses' => self::STATUSES]);
    }

    /** POST {catalog} | {reset: true} */
    public static function rest_catalog_save(WP_REST_Request $req) {
        $b = self::body($req);
        if (!empty($b['reset'])) { delete_option(self::OPT_CATALOG); return self::rest_catalog($req); }
        $c = self::clean_catalog($b['catalog'] ?? null);
        if (is_wp_error($c)) return $c;
        update_option(self::OPT_CATALOG, $c, false);
        return self::rest_catalog($req);
    }

    public static function rest_list(WP_REST_Request $req) {
        global $wpdb;
        $t = self::table();
        $where = ['1=1']; $args = [];
        if (!self::is_admin_user()) { $where[] = 'owner_id = %d'; $args[] = get_current_user_id(); }
        elseif (intval($req['owner'])) { $where[] = 'owner_id = %d'; $args[] = intval($req['owner']); }
        $today = wp_date('Y-m-d');
        $st = (string) $req['status'];
        if ($st === 'expired') { $where[] = "(status = 'expired' OR (status IN ('sent','viewed') AND valid_until < %s))"; $args[] = $today; }
        elseif (in_array($st, ['sent', 'viewed'], true)) { $where[] = 'status = %s AND (valid_until IS NULL OR valid_until >= %s)'; $args[] = $st; $args[] = $today; }
        elseif ($st === 'open') { $where[] = "status IN ('sent','viewed') AND (valid_until IS NULL OR valid_until >= %s)"; $args[] = $today; }
        elseif (in_array($st, self::STATUSES, true)) { $where[] = 'status = %s'; $args[] = $st; }
        elseif (!$req['all']) { $where[] = "status <> 'replaced'"; }
        $cust = null;
        if ((string) $req['customer'] !== '' && !self::is_admin_user()) {
            return new WP_Error('rest_forbidden', 'Den Kundenfilter nutzen nur Plattform-Admins.', ['status' => 403, 'field' => 'customer']);
        }
        if ((string) $req['customer'] !== '' && class_exists('TIX_Platform_Customers')) {
            // Kundenakte (§100): fest verknüpft, Hosting-Kurzname oder E-Mail des Kunden
            $cust = TIX_Platform_Customers::filter_customer($req['customer']);
            if (is_wp_error($cust)) return $cust;
            $where[] = TIX_Platform_Customers::offer_where($cust);
        }
        $q = trim((string) $req['q']);
        if ($q !== '') { $like = '%' . $wpdb->esc_like($q) . '%'; $where[] = '(number LIKE %s OR company LIKE %s OR contact_name LIKE %s OR contact_email LIKE %s OR plan LIKE %s)'; array_push($args, $like, $like, $like, $like, $like); }
        $per = max(1, min(100, intval($req['per_page']) ?: 25));
        $page = max(1, intval($req['page']) ?: 1);
        $sql_where = implode(' AND ', $where);
        $prep = function ($sql) use ($wpdb, $args) { return $args ? $wpdb->prepare($sql, $args) : $sql; };
        $total = intval($wpdb->get_var($prep("SELECT COUNT(*) FROM $t WHERE $sql_where")));
        $rows = $wpdb->get_results($prep("SELECT * FROM $t WHERE $sql_where ORDER BY id DESC LIMIT " . $per . ' OFFSET ' . (($page - 1) * $per)));
        // Zähler je Status (für Reiter), im eigenen Bereich
        $own = self::is_admin_user() ? '' : $wpdb->prepare(' AND owner_id = %d', get_current_user_id());
        $counts = array_fill_keys(['draft', 'sent', 'viewed', 'accepted', 'rejected', 'expired'], 0);
        foreach ($wpdb->get_results($wpdb->prepare("SELECT (CASE WHEN status IN ('sent','viewed') AND valid_until < %s THEN 'expired' ELSE status END) s, COUNT(*) n FROM $t WHERE status <> 'replaced'$own GROUP BY s", $today)) as $r) {
            if (isset($counts[$r->s])) $counts[$r->s] = intval($r->n);
        }
        $res = ['ok' => true, 'offers' => array_map([__CLASS__, 'summary'], $rows), 'total' => $total, 'page' => $page, 'per_page' => $per,
            'has_more' => $page * $per < $total, 'counts' => $counts];
        if ($cust) $res['customer'] = TIX_Platform_Customers::filter_head($cust);
        return rest_ensure_response($res);
    }

    /** POST {customer, items[], discount, benefits?, comparison?, valid_until?, intro?, conditions?, contact?, accept_link?, tax_rate?, term_months?}
     *  items[] auch kurz als {catalog_key, interval?, qty?, price?}. */
    public static function rest_create(WP_REST_Request $req) {
        $b = self::body($req);
        $items = [];
        foreach ((array) ($b['items'] ?? []) as $it) {
            if (is_string($it)) $it = ['catalog_key' => $it];
            if (!is_array($it)) continue;
            if (!empty($it['catalog_key']) && empty($it['name'])) {
                $base = self::item_from_catalog($it['catalog_key'], $it['interval'] ?? null);
                if (!$base) return new WP_Error('tix_offer_unknown_item', 'Diese Position gibt es im Katalog nicht.', ['status' => 400, 'field' => 'items']);
                $it = array_merge($base, array_intersect_key($it, array_flip(['qty', 'price', 'note', 'description', 'id', 'on'])));
            }
            $items[] = $it;
        }
        // „adds“: z. B. App → App-Einrichtung automatisch dazu (nur beim Anlegen, nur wenn nicht schon drin)
        if (!array_key_exists('auto_adds', $b) || !empty($b['auto_adds'])) {
            $keys = array_filter(array_column($items, 'catalog_key'));
            foreach ($items as $it) {
                $c = !empty($it['catalog_key']) ? self::catalog_item($it['catalog_key']) : null;
                foreach ((array) ($c['adds'] ?? []) as $k) if (!in_array($k, $keys, true) && ($n = self::item_from_catalog($k))) { $items[] = $n; $keys[] = $k; }
            }
        }
        $b['items'] = $items;
        // Schnell anlegen (1.38.821): nur ein Name → Kunde (Firma), alles andere aus den Vorgaben, Status Entwurf
        $nm = isset($b['name']) && is_string($b['name']) ? trim($b['name']) : '';
        if ($nm !== '') { $cu = (array) ($b['customer'] ?? []); if (trim((string) ($cu['company'] ?? '')) === '' && trim((string) ($cu['name'] ?? '')) === '') { $cu['company'] = $nm; $b['customer'] = $cu; } }
        if ($e = self::plan_error($b)) return $e;
        $d = self::clean_data($b);
        if ($e = self::mode_error($d)) return $e; // Betriebsart (1.38.819)
        // Neues Angebot aus der Kundenakte (§100)
        if (!empty($b['customer_key']) && class_exists('TIX_Platform_Customers')) {
            $ck = self::is_admin_user() ? TIX_Platform_Customers::canonical($b['customer_key']) : null;
            if (!$ck) return new WP_Error('tix_invalid_field', 'Diesen Kunden gibt es nicht.', ['status' => 400, 'field' => 'customer_key']);
            $d['customer_key'] = $ck;
        }
        self::history($d, 'created');
        $id = self::insert($d);
        if (!$id) return new WP_Error('tix_offer_failed', 'Das Angebot konnte nicht gespeichert werden.', ['status' => 500]);
        $res = rest_ensure_response(['ok' => true, 'offer' => self::full(self::get($id))]);
        $res->set_status(201);
        return $res;
    }

    public static function rest_get(WP_REST_Request $req) {
        $row = self::load($req);
        if (is_wp_error($row)) return $row;
        return rest_ensure_response(['ok' => true, 'offer' => self::full($row)]);
    }

    public static function rest_update(WP_REST_Request $req) {
        $row = self::load($req);
        if (is_wp_error($row)) return $row;
        $b = self::body($req);
        $old = self::data($row);
        if ($row->status !== 'draft') {
            // Nach dem Senden nur noch die interne Notiz; alles andere über eine neue Version
            if (array_keys(array_diff_key($b, ['id' => 1, 'internal_note' => 1]))) {
                return new WP_Error('tix_offer_locked', 'Das Angebot wurde schon gesendet. Für Änderungen bitte eine neue Version anlegen.', ['status' => 409]);
            }
            $old['internal_note'] = self::txt($b['internal_note'] ?? '', 2000);
            self::update_data($row->id, $old);
            return self::rest_get($req);
        }
        if ($e = self::plan_error($b)) return $e;
        $d = self::clean_data($b, $old);
        if ($e = self::mode_error($d)) return $e; // Betriebsart (1.38.819)
        $d['history'] = $old['history'] ?? [];
        if (isset($old['hosting'])) $d['hosting'] = $old['hosting']; // Verknüpfung „Aus Angebot anlegen“ (§99) bleibt
        if (isset($old['customer_key'])) $d['customer_key'] = $old['customer_key']; // Kundenakte (§100) bleibt
        self::history($d, 'updated');
        self::update_data($row->id, $d);
        return self::rest_get($req);
    }

    public static function rest_delete(WP_REST_Request $req) {
        global $wpdb;
        $row = self::load($req);
        if (is_wp_error($row)) return $row;
        if ($row->status !== 'draft') return new WP_Error('tix_offer_locked', 'Gesendete Angebote bleiben erhalten. Du kannst sie ablehnen oder ablaufen lassen.', ['status' => 409]);
        $wpdb->delete(self::table(), ['id' => intval($row->id)]);
        return rest_ensure_response(['ok' => true, 'deleted' => intval($row->id)]);
    }

    /** POST {…wie Anlegen} → Summen und vorgeschlagene Vorteile, ohne zu speichern (Live-Summe im Formular). */
    /** Ungültige Planrechnungs-Eingaben → WP_Error (400 mit Feld plan.*), sonst null */
    public static function plan_error(array $b) {
        if (array_key_exists('price_overrides', $b) && is_wp_error($e = self::clean_price_overrides($b['price_overrides']))) return $e; // AN2
        if (array_key_exists('mode', $b) && $b['mode'] !== null && !in_array($b['mode'], self::MODES, true)) return new WP_Error('tix_invalid_field', 'Betriebsart platform, own_shop oder self_hosted.', ['status' => 400, 'field' => 'mode']);
        if (array_key_exists('references', $b)) foreach (array_values((array) $b['references']) as $i => $rk) {
            $r = self::reference(sanitize_key(is_array($rk) ? (string) ($rk['key'] ?? '') : (string) $rk));
            if (!$r || !$r['approved']) return new WP_Error('tix_invalid_field', 'Diese Referenz gibt es nicht oder sie ist nicht freigegeben.', ['status' => 400, 'field' => "references.$i"]);
        }
        if (array_key_exists('layout', $b) && !in_array($b['layout'], ['auto', 'own', 'classic'], true)) return new WP_Error('tix_invalid_field', 'Gliederung auto, own oder classic.', ['status' => 400, 'field' => 'layout']);
        if (!class_exists('TIX_Offer_Plan') || !array_key_exists('plan', $b)) return null;
        $r = TIX_Offer_Plan::clean($b['plan'], self::catalog());
        return is_wp_error($r) ? $r : null;
    }

    /** Ergebnis der Planrechnung eines Angebots (oder null) */
    public static function plan_result(array $d) {
        if (!class_exists('TIX_Offer_Plan') || empty($d['plan']) || !is_array($d['plan'])) return null;
        return self::with_base_lead(TIX_Offer_Plan::compute($d['plan'], self::catalog(), self::plan_context($d)), $d);
    }

    /** plan_calc + lead_base (Faktenblock nur mit dem Grundpaket, 1.38.823) und compare_platform (Vergleich mit der Plattform nur auf Wunsch) */
    private static function with_base_lead($pc, array $d) {
        if (!is_array($pc)) return $pc;
        $pc['compare_platform'] = !empty($d['compare_platform']);
        if (self::has_options($d)) {
            $bd = self::base_data($d);
            $pb = TIX_Offer_Plan::compute($pc['input'], self::catalog(), self::plan_context($bd));
            $pc['lead_base'] = $pb['lead'];
        } else $pc['lead_base'] = $pc['lead'];
        return $pc;
    }

    /**
     * Angebot → Kontext der Planrechnung (AN2): nur angehakte Positionen, gewählte Preise.
     * Grundpaket „Eigener Shop“ jährlich zum Listenpreis → Staffel; sonst fester Jahrespreis der Position. Weitere wiederkehrende Positionen = Optionen.
     */
    public static function plan_context(array $d) {
        $t = self::totals($d);
        $cat = self::catalog();
        $om = self::mode_of($d);
        $mode = $om === 'self_hosted' ? 'self_hosted' : 'own_shop'; // Plattform-Angebote rechnen den eigenen Shop als Vergleich
        $po = (array) ($d['price_overrides'] ?? []);
        $sm = isset($po['server_month']) && $po['server_month'] !== null ? floatval($po['server_month']) : floatval($cat['server_month'] ?? 20);
        $ctx = ['mode' => $mode, 'offer_mode' => $om, 'tiers' => null,
            'tiers_by_mode' => ['own_shop' => $po['shop_tiers'] ?? null, 'self_hosted' => $po['self_hosted_tiers'] ?? null],
            'server_year' => $sm * 12, 'fixed' => null, 'base' => null, 'extras_year' => 0.0, 'extras' => [], 'once' => 0.0,
            'channel' => !empty($d['extras']['evendis_channel']) ? self::channel_fee($d) : null, 'factor' => 1.0];
        $bases = self::base_keys($mode);
        $setup_now = 0.0;
        foreach ((array) ($d['items'] ?? []) as $i => $it) {
            $ln = $t['lines'][$i] ?? null;
            if (!$ln || empty($ln['on'])) continue;
            $ctx['once'] += floatval($ln['once'] ?? 0);
            $k = (string) ($it['catalog_key'] ?? '');
            if ($k === 'einrichtung') $setup_now += floatval($ln['once'] ?? 0);
            if ($ctx['base'] === null && in_array($k, $bases, true)) {
                $ctx['base'] = ['name' => $it['name'], 'catalog_key' => $k, 'interval' => $it['interval']];
                $tiered = in_array($k, ['shop', 'lizenz'], true) && $it['interval'] === 'yearly' && !$ln['overridden'] && $it['price'] === null;
                if (!$tiered && $ln['year'] !== null) $ctx['fixed'] = floatval($ln['year']);
                $ctx['base']['tiered'] = $tiered || $ln['year'] === null;
                continue;
            }
            if (in_array($k, array_merge(self::BASE_KEYS, ['tickets', 'kostenlos']), true) || empty($ln['year'])) continue;
            $ctx['extras_year'] += floatval($ln['year']);
            $ctx['extras'][] = ['name' => $it['name'], 'year' => round(floatval($ln['year']), 2)];
        }
        if ($t['yearly']['net'] > 0 && $t['yearly']['discount'] > 0) $ctx['factor'] = $t['yearly']['net_after'] / $t['yearly']['net'];
        // Einmalig je Betriebsart (Summen-Zusammenfassung): Einrichtung eigener Shop 0 €, selbstgehostet Angebotspreis bzw. Katalog (190 €) oder 0 € erlassen
        $ce = self::catalog_item('einrichtung');
        $sh_setup = !empty($d['extras']['setup_free']) ? 0.0 : ($om === 'self_hosted' ? $setup_now : floatval($ce['prices']['once'] ?? 0));
        $of = ($t['once']['net'] > 0 && $t['once']['discount'] > 0) ? $t['once']['net_after'] / $t['once']['net'] : 1.0;
        $rest = max(0, $ctx['once'] - $setup_now);
        $ctx['once_by_mode'] = ['own_shop' => round($rest * $of, 2), 'self_hosted' => round(($rest + $sh_setup) * $of, 2)];
        // Vorgabe der führenden Stufe (1.38.822): Tickets im Jahr aus der Hochrechnung des Angebots (Tickets je Event × Events im Jahr)
        $pj = $d['projection'] ?? null;
        if (is_array($pj) && !empty($pj['tickets'])) $ctx['lead_hint'] = intval($pj['tickets']) * max(1, intval($pj['events_year'] ?? 1));
        return $ctx;
    }

    public static function rest_calc(WP_REST_Request $req) {
        $b = self::body($req);
        if ($e = self::plan_error($b)) return $e;
        $d = self::clean_data($b);
        if ($e = self::mode_error($d)) return $e; // Betriebsart (1.38.819)
        return rest_ensure_response(['ok' => true, 'data' => $d, 'totals' => self::totals($d), 'plan_calc' => self::plan_result($d), 'mode_hints' => self::mode_hints($d), 'benefits_suggested' => self::suggest_benefits(self::active_items($d))]);
    }

    /** POST /platform/offers/plan {…Annahmen} → nur die Planrechnung (Studio-Vorschau, Rechner); fehlende Annahmen aus den Vorgaben */
    /**
     * Referenzen (Katalog) und Vorschläge aus der Kundenliste: eigene Shops (Hosting) und Lizenzen, mit Betriebsart und „live“.
     * Vorschläge sind nie freigegeben – erst im Katalog mit approved = true wählbar.
     */
    public static function rest_references(WP_REST_Request $req) {
        $cat = self::catalog();
        $have = array_filter(array_column((array) ($cat['references'] ?? []), 'customer_key'));
        $cand = [];
        if (class_exists('TIX_Platform_Customers')) {
            foreach (TIX_Platform_Customers::index()['customers'] as $c) {
                if (!in_array($c['art'], ['hosted', 'license', 'bestand'], true)) continue;
                $row = TIX_Platform_Customers::row($c);
                $mode = 'own_shop';
                if ($c['art'] === 'license' && !empty($c['_lic']) && class_exists('TIX_License_Server')) $mode = TIX_License_Server::reported_mode($c['_lic']['row']) === 'managed' ? 'own_shop' : 'self_hosted';
                $live = in_array($row['status'], ['active', 'approved'], true) && !empty($row['domain']);
                $cand[] = ['customer_key' => $row['key'], 'name' => $row['name'], 'url' => $row['domain'] ? 'https://' . $row['domain'] : '', 'logo_url' => (string) $row['logo'],
                    'mode' => $mode, 'mode_label' => self::MODE_TEXTS[$mode]['label'], 'art' => $row['art'], 'live' => $live, 'in_catalog' => in_array($row['key'], $have, true)];
            }
        }
        return rest_ensure_response(['ok' => true, 'references' => array_values((array) ($cat['references'] ?? [])), 'candidates' => $cand,
            'note' => 'Nur freigegebene Referenzen (approved) sind im Angebot wählbar. Vorschläge erst nach Rückfrage beim Kunden freigeben.']);
    }

    public static function rest_plan(WP_REST_Request $req) {
        $b = self::body($req);
        $p = TIX_Offer_Plan::clean(isset($b['plan']) && is_array($b['plan']) ? $b['plan'] : $b, self::catalog());
        if (is_wp_error($p)) return $p;
        return rest_ensure_response(['ok' => true, 'plan_calc' => TIX_Offer_Plan::compute($p, self::catalog())]);
    }

    private static function pdf_response($bytes, $filename) {
        return rest_ensure_response(['ok' => true, 'filename' => $filename, 'mime' => 'application/pdf', 'data' => base64_encode($bytes)]);
    }

    /** POST {…wie Anlegen} → PDF-Vorschau ohne Speichern (Nummer „Entwurf“). */
    public static function rest_preview_draft(WP_REST_Request $req) {
        $d = self::clean_data(self::body($req));
        $bytes = self::render_pdf($d, ['number' => '', 'date' => wp_date('Y-m-d')]); // ohne Entwurf-Hinweis (User 14.11.)
        if (!$bytes) return new WP_Error('tix_pdf_unavailable', 'PDF gerade nicht möglich.', ['status' => 500]);
        return self::pdf_response($bytes, 'Angebot.pdf');
    }

    public static function filename($row) {
        $c = preg_replace('/[^A-Za-z0-9äöüÄÖÜß_-]+/u', '-', (string) $row->company);
        return 'Angebot-' . $row->number . ($c ? '-' . trim(mb_substr($c, 0, 40), '-') : '') . '.pdf';
    }

    public static function pdf_of($row) {
        $d = self::data($row);
        return self::render_pdf($d, ['number' => (string) $row->number, 'date' => substr((string) ($row->sent_at ?: $row->created_at), 0, 10),
            'link' => ($row->token && !empty($d['accept_link'])) ? self::accept_url($row->token) : '']);
    }

    public static function rest_pdf(WP_REST_Request $req) {
        $row = self::load($req);
        if (is_wp_error($row)) return $row;
        $bytes = self::pdf_of($row);
        if (!$bytes) return new WP_Error('tix_pdf_unavailable', 'PDF gerade nicht möglich.', ['status' => 500]);
        return self::pdf_response($bytes, self::filename($row));
    }

    /** POST {confirm: true, to?, cc?[], cc_me? (Standard true), subject?, message?} */
    public static function rest_send(WP_REST_Request $req) {
        $row = self::load($req);
        if (is_wp_error($row)) return $row;
        $b = self::body($req);
        if (empty($b['confirm'])) return new WP_Error('tix_confirm_required', 'Bitte das Senden bestätigen.', ['status' => 400, 'field' => 'confirm']);
        if (in_array($row->status, ['accepted', 'rejected', 'replaced'], true)) return new WP_Error('tix_offer_locked', 'Dieses Angebot ist abgeschlossen.', ['status' => 409]);
        $d = self::data($row);
        $to = sanitize_email((string) ($b['to'] ?? '')) ?: $d['customer']['email'];
        if ($e = self::incomplete_error($d, $to)) return $e; // 422 mit Liste fehlender Felder (1.38.821)
        $t = self::totals($d);
        if ($d['valid_until'] < wp_date('Y-m-d') || self::is_expired($row)) return new WP_Error('tix_offer_expired', 'Das Angebot ist schon abgelaufen. Bitte „Gültig bis“ anpassen (neue Version).', ['status' => 409, 'field' => 'valid_until']);
        $token = (string) $row->token;
        $cols = [];
        if (!empty($d['accept_link'])) {
            if ($token === '') { $token = wp_generate_password(40, false, false); $cols['token'] = $token; }
            $cols['token_expires'] = $d['valid_until'] . ' 23:59:59';
        }
        if ($cols) { self::set($row->id, $cols); $row = self::get($row->id); }
        $sent_at = current_time('mysql');
        if (!$row->sent_at) { self::set($row->id, ['sent_at' => $sent_at]); $row = self::get($row->id); }
        $bytes = self::pdf_of($row);
        if (!$bytes) return new WP_Error('tix_pdf_unavailable', 'PDF gerade nicht möglich.', ['status' => 500]);
        $dir = trailingslashit(get_temp_dir()) . 'tix-offer-' . wp_generate_password(8, false);
        wp_mkdir_p($dir);
        $file = $dir . '/' . self::filename($row);
        file_put_contents($file, $bytes);
        $brand = class_exists('TIX_Mail_Layout') ? (string) (TIX_Mail_Layout::brand()['name'] ?? 'evendis') : 'evendis';
        $subject = self::txt($b['subject'] ?? '', 160, false) ?: "Dein Angebot {$row->number} von {$brand}";
        $hello = $d['customer']['name'] !== '' ? 'Hallo ' . esc_html(strtok($d['customer']['name'], ' ')) . ',' : 'Hallo,';
        $msg = trim((string) ($b['message'] ?? '')) !== '' ? self::txt($b['message'], 3000) : 'anbei findest du unser Angebot ' . $row->number . '. Es gilt bis ' . wp_date('d.m.Y', strtotime($d['valid_until'])) . '.';
        $html = '<p>' . $hello . '</p><p>' . nl2br(esc_html($msg)) . '</p>';
        if (!empty($d['accept_link']) && $token) {
            $html .= (class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::button('Angebot ansehen', self::accept_url($token)) : '<p><a href="' . esc_url(self::accept_url($token)) . '">Angebot ansehen</a></p>');
        }
        $html .= '<p>Bei Fragen erreichst du ' . esc_html($d['contact']['name'] ?: $brand) . ($d['contact']['email'] ? ' unter <a href="mailto:' . esc_attr($d['contact']['email']) . '">' . esc_html($d['contact']['email']) . '</a>' : '')
            . ($d['contact']['phone'] ? ' oder telefonisch unter ' . esc_html($d['contact']['phone']) : '') . '.</p>';
        $headers = [];
        if (is_email($d['contact']['email'])) $headers[] = 'Reply-To: ' . $d['contact']['email'];
        $cc = [];
        foreach ((array) ($b['cc'] ?? []) as $c) if (is_email($c)) $cc[] = sanitize_email($c);
        $cc_me = !array_key_exists('cc_me', $b) || !empty($b['cc_me']);
        $me = wp_get_current_user();
        $m = ['title' => 'Dein Angebot ' . $row->number, 'blocks' => [['type' => 'html', 'html' => $html]], 'kind' => 'account'];
        $ok = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::send($to, $subject, $m, ['customer' => false, 'headers' => $headers, 'attachments' => [$file]])
            : wp_mail($to, $subject, $html, array_merge(['Content-Type: text/html; charset=UTF-8'], $headers), [$file]);
        $copies = [];
        if ($ok) {
            $targets = $cc;
            if ($cc_me && $me && is_email($me->user_email) && strcasecmp($me->user_email, $to) !== 0) $targets[] = $me->user_email;
            foreach (array_unique($targets) as $c) {
                $mc = $m; $mc['title'] = 'Kopie: Angebot ' . $row->number . ' an ' . ($d['customer']['company'] ?: $to);
                $sent = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::send($c, 'Kopie: ' . $subject, $mc, ['customer' => false, 'attachments' => [$file]]) : wp_mail($c, 'Kopie: ' . $subject, $html, ['Content-Type: text/html; charset=UTF-8'], [$file]);
                if ($sent) $copies[] = $c;
            }
        }
        @unlink($file); @rmdir($dir);
        if (!$ok) return new WP_Error('tix_mail_failed', 'Die Mail konnte nicht gesendet werden.', ['status' => 502]);
        self::history($d, 'sent', ['to' => $to, 'copies' => $copies]);
        $d['last_sent_to'] = $to;
        self::update_data($row->id, $d);
        if (in_array($row->status, ['draft', 'expired'], true)) self::set($row->id, ['status' => 'sent']);
        $res = self::rest_get($req);
        $data = $res->get_data();
        $data['mail'] = ['to' => $to, 'copies' => $copies];
        return rest_ensure_response($data);
    }

    /** Kopie als neuer Entwurf mit neuer Nummer (anderer Kunde o. ä.). */
    public static function rest_duplicate(WP_REST_Request $req) {
        $row = self::load($req);
        if (is_wp_error($row)) return $row;
        $d = self::data($row);
        unset($d['history'], $d['decision'], $d['last_sent_to'], $d['hosting'], $d['customer_key']);
        $d['valid_until'] = wp_date('Y-m-d', time() + intval(self::catalog()['valid_days'] ?? 30) * DAY_IN_SECONDS);
        $d = self::clean_data($d);
        self::history($d, 'duplicated', ['from' => (string) $row->number]);
        $id = self::insert($d);
        $res = rest_ensure_response(['ok' => true, 'offer' => self::full(self::get($id))]);
        $res->set_status(201);
        return $res;
    }

    /** Neue Version (gleiche Grundnummer, -2, -3 …); die alte gilt dann als „ersetzt“ und ihr Annahme-Link endet. */
    public static function rest_version(WP_REST_Request $req) {
        global $wpdb;
        $row = self::load($req);
        if (is_wp_error($row)) return $row;
        if ($row->status === 'accepted') return new WP_Error('tix_offer_locked', 'Angenommene Angebote bleiben, wie sie sind. Für Neues bitte duplizieren.', ['status' => 409]);
        $max = intval($wpdb->get_var($wpdb->prepare('SELECT MAX(version) FROM ' . self::table() . ' WHERE base_number = %s', $row->base_number)));
        $d = self::data($row);
        $ck = $d['customer_key'] ?? null;
        unset($d['history'], $d['decision'], $d['last_sent_to'], $d['hosting']);
        if ($d['valid_until'] < wp_date('Y-m-d')) $d['valid_until'] = wp_date('Y-m-d', time() + intval(self::catalog()['valid_days'] ?? 30) * DAY_IN_SECONDS);
        $d = self::clean_data($d);
        if ($ck) $d['customer_key'] = $ck; // neue Version bleibt beim Kunden (§100)
        self::history($d, 'version', ['from' => (string) $row->number]);
        $id = self::insert($d, ['base_number' => $row->base_number, 'version' => $max + 1, 'owner_id' => intval($row->owner_id)]);
        if ($row->status !== 'draft') {
            $od = self::data($row); self::history($od, 'replaced', ['by' => get_current_user_id(), 'new' => $id]); self::update_data($row->id, $od);
            self::set($row->id, ['status' => 'replaced', 'token_expires' => current_time('mysql')]);
        }
        $res = rest_ensure_response(['ok' => true, 'offer' => self::full(self::get($id))]);
        $res->set_status(201);
        return $res;
    }

    /** POST {status: accepted|rejected|sent, note?} – von Hand (z. B. Zusage am Telefon); accepted = „Interesse bestätigt“, interested wird als accepted gelesen. */
    public static function rest_status(WP_REST_Request $req) {
        $row = self::load($req);
        if (is_wp_error($row)) return $row;
        $b = self::body($req);
        $st = (string) ($b['status'] ?? '');
        if ($st === 'interested') $st = 'accepted';
        if (!in_array($st, ['accepted', 'rejected', 'sent'], true)) return new WP_Error('tix_offer_status', 'Erlaubt sind accepted, rejected und sent.', ['status' => 400, 'field' => 'status']);
        if ($row->status === 'draft' && $st === 'sent') return new WP_Error('tix_offer_status', 'Zum Senden bitte /send nutzen.', ['status' => 400]);
        if ($row->status === 'replaced') return new WP_Error('tix_offer_locked', 'Dieses Angebot wurde durch eine neue Version ersetzt.', ['status' => 409]);
        $d = self::data($row);
        $me = wp_get_current_user();
        if ($st === 'sent') { unset($d['decision']); $cols = ['status' => $row->viewed_at ? 'viewed' : 'sent', 'decided_at' => null]; }
        else { $d['decision'] = ['status' => $st, 'by' => 'team', 'name' => (string) $me->display_name, 'note' => self::txt($b['note'] ?? '', 1000), 'at' => gmdate('c')]; $cols = ['status' => $st, 'decided_at' => current_time('mysql')]; }
        self::history($d, 'status', ['status' => $st]);
        self::update_data($row->id, $d);
        self::set($row->id, $cols);
        return self::rest_get($req);
    }

    /** POST {offer_id? | …Angebotsdaten} → {intro} Vorschlag der KI für die persönliche Einleitung. */
    public static function rest_intro(WP_REST_Request $req) {
        $b = self::body($req);
        if (!empty($b['offer_id'])) {
            $row = self::get($b['offer_id']);
            if (!self::can_see($row)) return new WP_Error('tix_offer_not_found', 'Angebot nicht gefunden.', ['status' => 404]);
            $d = self::data($row);
        } else {
            $d = self::clean_data($b);
        }
        if (!class_exists('TIX_AI_Provider') || !TIX_AI_Provider::ready()) return new WP_Error('ai_unavailable', 'Die KI ist hier nicht eingerichtet.', ['status' => 503]);
        $facts = ['kunde' => $d['customer']['company'] ?: $d['customer']['name'], 'ansprechpartner' => $d['customer']['name'], 'bisheriger_anbieter' => $d['customer']['provider'] ?? '',
            'positionen' => array_column(self::active_items($d), 'name'), 'vorteile' => array_column(array_filter((array) $d['benefits'], function ($x) { return !empty($x['on']); }), 'title'),
            'hinweis_des_vertriebs' => (string) ($b['hint'] ?? '')];
        $prompt = 'Schreibe eine persönliche Einleitung (2–4 Sätze, Deutsch, du-Form, freundlich, ehrlich, ohne Übertreibungen, ohne erfundene Fakten, ohne Anrede-Zeile und ohne Gruß, kein Wort „Ballermann“) '
            . 'für ein Angebot von evendis an einen Veranstalter. evendis: Ticketshop mit eigener Marke, Geld sofort aufs eigene Konto, keine Gebühr je Ticket, von Veranstaltern für Veranstalter. Fakten: '
            . wp_json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $res = TIX_AI_Provider::send(['max_tokens' => 400, 'messages' => [['role' => 'user', 'content' => $prompt]]], 30);
        if (is_wp_error($res)) return $res;
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        $text = '';
        foreach ((array) ($body['content'] ?? []) as $c) if (($c['type'] ?? '') === 'text') $text .= (string) $c['text'];
        $text = trim(preg_replace('/^(hallo|liebe[rs]?|guten tag)[^\n]*\n+/iu', '', trim($text)));
        if ($text === '' || intval(wp_remote_retrieve_response_code($res)) !== 200) return new WP_Error('ai_failed', 'Die KI hat gerade nicht geantwortet. Bitte später noch einmal.', ['status' => 502]);
        if (preg_match('/ballermann/iu', $text)) $text = (string) self::catalog()['intro'];
        return rest_ensure_response(['ok' => true, 'intro' => mb_substr($text, 0, 3000)]);
    }

    // ═══════════════ Öffentlich: Ansehen und Interesse bestätigen (unverbindlich, User-Entscheid 06.11.) ═══════════════

    /** → $row | WP_Error (unbekannt 404, Link beendet 410) */
    private static function public_row($token, $for_decision = false) {
        $row = self::by_token($token);
        $d = $row ? self::data($row) : [];
        if (!$row || empty($d['accept_link']) || $row->status === 'draft') return new WP_Error('tix_offer_not_found', 'Dieses Angebot gibt es nicht (mehr).', ['status' => 404]);
        if ($row->status === 'replaced') return new WP_Error('tix_offer_replaced', 'Zu diesem Angebot gibt es eine neuere Fassung. Bitte nimm die aktuelle Mail.', ['status' => 410]);
        $ended = ($row->token_expires && $row->token_expires < current_time('mysql')) || self::is_expired($row);
        if ($ended && !in_array($row->status, self::DECIDED, true)) return new WP_Error('tix_offer_expired', 'Dieses Angebot ist abgelaufen. Melde dich gern bei uns, dann schicken wir dir ein neues.', ['status' => 410]);
        if ($for_decision && in_array($row->status, self::DECIDED, true)) return new WP_Error('tix_offer_decided', $row->status === 'rejected' ? 'Dieses Angebot wurde abgelehnt.' : 'Du hast dein Interesse an diesem Angebot schon bestätigt.', ['status' => 409]);
        return $row;
    }

    private static function track_view($row) {
        if (class_exists('TIX_Shadow') && TIX_Shadow::active()) return; // Schatten-Abruf
        if (is_user_logged_in() && (self::is_admin_user() || self::is_sales())) return; // das Team zählt nicht
        $key = 'tix_offer_v_' . md5($row->id . '|' . (class_exists('TIX_App_Account') && method_exists('TIX_App_Account', 'client_ip') ? TIX_App_Account::client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '')));
        if (get_transient($key)) return;
        set_transient($key, 1, 30 * MINUTE_IN_SECONDS);
        global $wpdb;
        $first = !$row->viewed_at;
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET views = views + 1, viewed_at = COALESCE(viewed_at, %s)' . ($row->status === 'sent' ? ", status = 'viewed'" : '') . ' WHERE id = %d', current_time('mysql'), $row->id));
        if ($first) do_action('tix_offer_viewed', intval($row->id));
    }

    public static function public_view($row) {
        $d = self::data($row);
        $t = self::totals($d);
        $items = [];
        foreach ((array) $d['items'] as $i => $it) {
            if (array_key_exists('on', $it) && empty($it['on'])) continue; // nicht angehakt (AN2)
            $items[] = ['name' => $it['name'], 'description' => $it['description'], 'qty' => $it['qty'], 'interval' => $it['interval'], 'unit' => $t['lines'][$i]['unit'], 'net' => $t['lines'][$i]['net'], 'optional' => self::is_optional($it),
                'list_price' => $t['lines'][$i]['overridden'] ? $it['list_price'] : null, 'fee_percent' => $it['fee_percent'], 'fee_fixed' => $it['fee_fixed'], 'note' => $it['note']];
        }
        return ['number' => (string) $row->number, 'status' => $row->status, 'valid_until' => $row->valid_until, 'customer' => ['company' => $d['customer']['company'], 'name' => $d['customer']['name']],
            'intro' => $d['intro'], 'items' => $items, 'discount' => $d['discount'], 'totals' => ['yearly' => $t['yearly'], 'once' => $t['once'], 'monthly_net' => $t['monthly_net'], 'tax_rate' => $t['tax_rate'],
                'base' => $t['base'], 'with_options' => $t['with_options'], 'options' => $t['options'], 'has_options' => $t['has_options']],
            'compare_platform' => !empty($d['compare_platform']),
            // Hochrechnung je Event vergleicht mit dem Provisionsmodell der Plattform → beim eigenen Betrieb nur mit compare_platform
            'projection' => ($t['projection'] && !empty($d['projection']['in_pdf']) && (self::layout_of($d) !== 'own' || !empty($d['compare_platform']))) ? $t['projection'] : null,
            'benefits' => array_values(array_map(function ($b) { return ['title' => $b['title'], 'text' => $b['text']]; }, array_filter((array) $d['benefits'], function ($b) { return !empty($b['on']); }))),
            'conditions' => $d['conditions'], 'contact' => $d['contact'], 'term_months' => $d['term_months'],
            'decision' => isset($d['decision']) ? ['status' => $d['decision']['status'], 'name' => $d['decision']['by'] === 'customer' ? $d['decision']['name'] : '', 'at' => $d['decision']['at']] : null,
            'can_decide' => in_array($row->status, ['sent', 'viewed'], true), 'pdf_filename' => self::filename($row),
            // AN2: Gliederung „Dein eigener Ticketshop“ – Kacheln und Blöcke 1–4 (nur bei layout own)
            'layout' => self::layout_of($d), 'own' => self::layout_of($d) === 'own' ? (function ($bl) { if (!$bl) return null; unset($bl['totals']); $bl['plan_calc'] = $bl['compare_platform'] ? $bl['plan'] : self::strip_platform($bl['plan']); unset($bl['plan']); return $bl; })(self::own_blocks($d)) : null];
    }

    /** Planrechnung ohne Plattform-Vergleich (Kundenseite, compare_platform aus, 1.38.823) */
    public static function strip_platform($pc) {
        if (!is_array($pc)) return $pc;
        $drop = ['platform_guests_pay', 'platform_you_pay', 'advantage', 'advantage_without_fee'];
        foreach ((array) $pc['rows'] as $i => $r) $pc['rows'][$i] = array_diff_key($r, array_flip($drop));
        foreach ((array) $pc['totals'] as $i => $r) unset($pc['totals'][$i]['modes']['platform']);
        foreach (['lead', 'lead_base'] as $k) if (is_array($pc[$k] ?? null)) $pc[$k] = array_diff_key($pc[$k], array_flip(['platform', 'savings_vs_platform', 'savings_vs_platform_guests']));
        unset($pc['break_even'], $pc['by_mode'], $pc['platform']);
        if (isset($pc['guest'])) $pc['guest'] = array_diff_key($pc['guest'], array_flip(['platform_fee', 'pays_platform']));
        return $pc;
    }

    public static function rest_public_get(WP_REST_Request $req) {
        $row = self::public_row($req['token']);
        if (is_wp_error($row)) return $row;
        self::track_view($row);
        return rest_ensure_response(['ok' => true, 'offer' => self::public_view(self::get($row->id))]);
    }

    public static function rest_public_pdf(WP_REST_Request $req) {
        $row = self::public_row($req['token']);
        if (is_wp_error($row)) return $row;
        $bytes = self::pdf_of($row);
        return $bytes ? self::pdf_response($bytes, self::filename($row)) : new WP_Error('tix_pdf_unavailable', 'PDF gerade nicht möglich.', ['status' => 500]);
    }

    private static function rate_ok($bucket) {
        if (!class_exists('TIX_App_Account') || !method_exists('TIX_App_Account', 'rate_limited')) return true;
        return !TIX_App_Account::rate_limited($bucket, 10, HOUR_IN_SECONDS);
    }

    /** POST {name, note?} – unverbindliches Interesse (auch unter /accept, gleiche Wirkung); der Vertrag kommt danach separat. */
    public static function rest_public_accept(WP_REST_Request $req) {
        $b = self::body($req);
        return self::decide($req['token'], 'accepted', (string) ($b['name'] ?? ''), true, (string) ($b['note'] ?? ''));
    }

    /** POST {reason?, name?} */
    public static function rest_public_decline(WP_REST_Request $req) {
        $b = self::body($req);
        return self::decide($req['token'], 'rejected', (string) ($b['name'] ?? ''), true, (string) ($b['reason'] ?? ''));
    }

    public static function decide($token, $status, $name, $confirm, $note) {
        if (!self::rate_ok('offer_decide')) return new WP_Error('tix_rate_limited', 'Zu viele Versuche. Bitte später noch einmal.', ['status' => 429]);
        $row = self::public_row($token, true);
        if (is_wp_error($row)) return $row;
        $name = self::txt($name, 120, false);
        if ($status === 'accepted' && mb_strlen($name) < 3) return new WP_Error('tix_offer_name', 'Bitte deinen vollständigen Namen angeben.', ['status' => 400, 'field' => 'name']);
        $d = self::data($row);
        $ip = class_exists('TIX_App_Account') && method_exists('TIX_App_Account', 'client_ip') ? TIX_App_Account::client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
        $d['decision'] = ['status' => $status, 'by' => 'customer', 'name' => $name, 'note' => self::txt($note, 1000), 'at' => gmdate('c'),
            'ip_hash' => substr(hash('sha256', $ip . wp_salt('auth')), 0, 16), 'number' => (string) $row->number, 'totals' => self::totals($d)['yearly']];
        self::history($d, $status === 'accepted' ? 'interest_online' : 'rejected_online');
        self::update_data($row->id, $d);
        self::set($row->id, ['status' => $status, 'decided_at' => current_time('mysql')]);
        self::notify_team(self::get($row->id));
        do_action('tix_offer_decided', intval($row->id), $status);
        return rest_ensure_response(['ok' => true, 'offer' => self::public_view(self::get($row->id))]);
    }

    /** Hinweis an den Ersteller (und den Plattform-Kontakt), wenn der Kunde online entscheidet. */
    public static function notify_team($row) {
        $d = self::data($row);
        $dec = (array) ($d['decision'] ?? []);
        $owner = get_userdata(intval($row->owner_id));
        $to = [];
        if ($owner && is_email($owner->user_email)) $to[] = $owner->user_email;
        $pl = trim((string) (function_exists('tix_get_settings') ? tix_get_settings('invoice_email') : '')) ?: (string) get_option('admin_email');
        if (is_email($pl) && !in_array($pl, $to, true)) $to[] = $pl;
        if (!$to) return;
        $ok = $dec['status'] !== 'rejected';
        $title = ($ok ? 'Interesse bestätigt: ' : 'Angebot abgelehnt: ') . $row->number;
        $html = '<p><strong>' . esc_html($row->company ?: $row->contact_name) . '</strong> hat ' . ($ok ? 'Interesse am Angebot ' . esc_html($row->number) . ' bestätigt (unverbindlich). Bitte den Vertrag separat schicken.' : 'das Angebot ' . esc_html($row->number) . ' abgelehnt.') . '</p>'
            . ($dec['name'] ? '<p>Name: ' . esc_html($dec['name']) . '</p>' : '') . ($dec['note'] ? '<p>Nachricht: ' . nl2br(esc_html($dec['note'])) . '</p>' : '')
            . '<p>Jährlich ' . esc_html(self::eur($row->total_year)) . ' brutto' . (floatval($row->total_once) > 0 ? ', einmalig ' . esc_html(self::eur($row->total_once)) . ' brutto' : '') . '.</p>';
        $studio = class_exists('TIX_Studio') && method_exists('TIX_Studio', 'url') ? (string) TIX_Studio::url('/plattform/angebote/?id=' . intval($row->id)) : '';
        if ($studio && class_exists('TIX_Mail_Layout')) $html .= TIX_Mail_Layout::button('Im Studio öffnen', $studio);
        foreach ($to as $addr) {
            if (class_exists('TIX_Mail_Layout')) TIX_Mail_Layout::send($addr, $title, ['title' => $title, 'blocks' => [['type' => 'html', 'html' => $html]], 'kind' => 'account'], ['customer' => false]);
            else wp_mail($addr, $title, $html, ['Content-Type: text/html; charset=UTF-8']);
        }
    }

    /** Seite ?tix_offer=<token> (eigenständig, ohne Theme): Übersicht, PDF, Annehmen/Ablehnen. */
    public static function public_page() {
        if (empty($_GET['tix_offer']) || !self::multi()) return;
        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $_GET['tix_offer']);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        header('X-LiteSpeed-Cache-Control: no-cache');
        $row = self::public_row($token);
        if (!is_wp_error($row) && !empty($_GET['pdf'])) {
            $bytes = self::pdf_of($row);
            if ($bytes) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="' . self::filename($row) . '"');
                echo $bytes; exit;
            }
        }
        $msg = '';
        if (!is_wp_error($row) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tix_offer_nonce']) && wp_verify_nonce($_POST['tix_offer_nonce'], 'tix_offer_' . $token)) {
            $act = ($_POST['decision'] ?? '') === 'decline' ? 'rejected' : 'accepted';
            $r = self::decide($token, $act, wp_unslash((string) ($_POST['name'] ?? '')), !empty($_POST['confirm']), wp_unslash((string) ($_POST['note'] ?? '')));
            $msg = is_wp_error($r) ? $r->get_error_message() : '';
            $row = self::get($row->id);
        } elseif (!is_wp_error($row)) {
            self::track_view($row);
            $row = self::get($row->id);
        }
        status_header(is_wp_error($row) ? intval($row->get_error_data()['status'] ?? 404) : 200);
        header('Content-Type: text/html; charset=UTF-8');
        echo self::page_html($row, $token, $msg);
        exit;
    }

    private static function page_html($row, $token, $msg) {
        $brand = class_exists('TIX_Mail_Layout') ? (string) (TIX_Mail_Layout::brand()['name'] ?? 'evendis') : 'evendis';
        $e = 'esc_html';
        $css = 'body{margin:0;background:#F4F5F8;font-family:Figtree,Manrope,system-ui,sans-serif;color:#2C2F3F}.w{max-width:720px;margin:0 auto;padding:16px}'
            . '.hd{background:#2C2F3F;color:#fff;border-radius:20px;padding:28px 24px;margin-top:8px}.hd b{color:#FC2F55}.hd h1{margin:8px 0 4px;font-size:24px}.mu{color:#5D6175;font-size:14px}'
            . '.hd .mu{color:#C9CCDA}.c{background:#fff;border-radius:16px;padding:20px;margin-top:16px}h2{font-size:17px;margin:0 0 12px}table{width:100%;border-collapse:collapse;font-size:14px}'
            . 'td{padding:8px 0;border-bottom:1px solid #E3E5EC;vertical-align:top}td.r{text-align:right;white-space:nowrap;padding-left:12px}.s{font-weight:700}.b{display:flex;gap:10px;margin:8px 0}'
            . '.b i{flex:0 0 8px;height:8px;border-radius:4px;background:#FC2F55;margin-top:7px}.btn{display:inline-block;background:#FC2F55;color:#fff;border:0;border-radius:12px;padding:14px 20px;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none}'
            . '.ghost{background:none;color:#5D6175;border:1px solid #E3E5EC}input[type=text],textarea{width:100%;box-sizing:border-box;border:1px solid #C9CCDA;border-radius:12px;padding:12px;font-size:16px;font-family:inherit;margin:6px 0 12px}'
            . '.ok{background:#E6F4EC;color:#1F6B47;border-radius:12px;padding:14px;margin-top:16px}.er{background:#FFE3E9;color:#9D1B33;border-radius:12px;padding:14px;margin-top:16px}label{font-size:14px}'
            . '.tl{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:16px}.tl div{background:#fff;border-radius:16px;padding:14px}.tl .be{background:#FFF5F7}.tl small{display:block;font-size:11px;font-weight:700;letter-spacing:.04em;color:#5D6175;text-transform:uppercase}'
            . '.tl strong{display:block;font-size:19px;margin:6px 0 4px}.tl .be strong{color:#FC2F55}.tl span{font-size:12px;color:#5D6175}h2 em{font-style:normal;color:#FC2F55;margin-right:8px}.red{color:#FC2F55}'
            . '.opt{display:inline-block;font-size:11px;font-weight:700;color:#5D6175;border:1px solid #C9CCDA;border-radius:6px;padding:1px 6px;margin-left:6px;vertical-align:1px}.sc{overflow-x:auto}.sc table{min-width:560px}.sc td{font-size:13px}tr.hl td{background:#FFF5F7;font-weight:700}tr.cmp td{color:#5D6175}.box{background:#FFF5F7;border-radius:12px;padding:12px;margin:12px 0;font-size:14px}'
            . '@media(max-width:560px){.tl{grid-template-columns:1fr}}';
        $out = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Angebot · ' . $e($brand) . '</title><style>' . $css . '</style></head><body><div class="w">';
        if (is_wp_error($row)) {
            return $out . '<div class="hd"><b>' . $e($brand) . '</b><h1>Angebot</h1></div><div class="c"><p>' . $e($row->get_error_message()) . '</p></div></div></body></html>';
        }
        $v = self::public_view($row);
        $fmt = function ($n) { return self::eur($n); };
        $iv = ['monthly' => 'monatlich', 'yearly' => 'jährlich', 'once' => 'einmalig', 'per_event' => 'je Event', 'per_ticket' => 'je Ticket'];
        $out .= '<div class="hd"><b>' . $e($brand) . '</b>' . (!empty($v['own']) ? '<h1>' . $e($v['own']['title']) . '</h1><div class="mu">Angebot mit Planrechnung für ' . $e($v['customer']['company'] ?: $v['customer']['name']) . '</div><div class="mu">' . $e($v['own']['mode_label'] . ': ' . $v['own']['subtitle']) . '</div>' : '<h1>Angebot für ' . $e($v['customer']['company'] ?: $v['customer']['name']) . '</h1>') . '<div class="mu">Nr. ' . $e($v['number']) . ' · gültig bis ' . $e(wp_date('d.m.Y', strtotime($v['valid_until']))) . '</div></div>';
        if ($msg) $out .= '<div class="er">' . $e($msg) . '</div>';
        if ($v['decision'] && $v['decision']['status'] === 'accepted') $out .= '<div class="ok">Vielen Dank' . ($v['decision']['name'] ? ', ' . $e($v['decision']['name']) : '') . '! Dein Interesse ist bestätigt. Wir melden uns in Kürze und schicken dir den Vertrag separat.</div>';
        if ($v['decision'] && $v['decision']['status'] === 'rejected') $out .= '<div class="ok">Danke für deine Rückmeldung. Das Angebot ist abgelehnt.</div>';
        if (!empty($v['own'])) $out .= (!empty($v['own']['lead']) && empty($v['own']['lead']['on_request'])) ? self::page_lead($v['own']['lead'], $v['own']['fixed_price'] ?? null) : self::page_tiles($v['own']);
        if ($v['intro']) $out .= '<div class="c"><p>' . nl2br($e($v['intro'])) . '</p></div>';
        if ($v['benefits'] && empty($v['own'])) {
            $out .= '<div class="c"><h2>Deine Vorteile</h2>';
            foreach ($v['benefits'] as $b) $out .= '<div class="b"><i></i><div><strong>' . $e($b['title']) . '</strong>' . ($b['text'] ? '<div class="mu">' . $e($b['text']) . '</div>' : '') . '</div></div>';
            $out .= '</div>';
        }
        if (!empty($v['own'])) {
            $out .= self::page_own($v, $token);
            if ($v['own']['benefits']) {
                $out .= '<div class="c"><h2>Deine Vorteile</h2>';
                foreach ($v['own']['benefits'] as $b) $out .= '<div class="b"><i></i><div><strong>' . $e($b['title']) . '</strong>' . ($b['text'] ? '<div class="mu">' . $e($b['text']) . '</div>' : '') . '</div></div>';
                $out .= '</div>';
            }
        } else {
        $out .= '<div class="c"><h2>Leistungen und Preise</h2><table>';
        foreach ($v['items'] as $it) {
            $price = $it['interval'] === 'per_ticket' ? self::fee_label($it['fee_percent'], $it['fee_fixed']) : ($it['net'] !== null ? $fmt($it['net']) . ' ' . $iv[$it['interval']] : '');
            $out .= '<tr><td><strong>' . $e(($it['qty'] > 1 ? $it['qty'] . ' × ' : '') . $it['name']) . '</strong>' . ($it['note'] ? '<div class="mu">' . $e($it['note']) . '</div>' : '') . '</td><td class="r">' . $e($price)
                . ($it['list_price'] !== null ? '<div class="mu"><s>' . $e($fmt($it['list_price'] * $it['qty'])) . '</s></div>' : '') . '</td></tr>';
        }
        $t = $v['totals'];
        if ($t['yearly']['net'] > 0) {
            if ($t['yearly']['discount'] > 0) $out .= '<tr><td>Rabatt (jährlich)</td><td class="r">−' . $e($fmt($t['yearly']['discount'])) . '</td></tr>';
            $out .= '<tr><td class="s">Jährlich (brutto, inkl. ' . $e(rtrim(rtrim(number_format($t['tax_rate'], 1, ',', ''), '0'), ',')) . ' % MwSt.)</td><td class="r s">' . $e($fmt($t['yearly']['gross'])) . '</td></tr>';
        }
        if ($t['once']['net'] > 0) {
            if ($t['once']['discount'] > 0) $out .= '<tr><td>Rabatt (einmalig)</td><td class="r">−' . $e($fmt($t['once']['discount'])) . '</td></tr>';
            $out .= '<tr><td class="s">Einmalig (brutto)</td><td class="r s">' . $e($fmt($t['once']['gross'])) . '</td></tr>';
        }
        $out .= '</table><p><a class="btn ghost" href="' . esc_url(add_query_arg(['tix_offer' => $token, 'pdf' => 1], home_url('/'))) . '">Angebot als PDF</a></p></div>';
        }
        if ($v['conditions']) $out .= '<div class="c"><h2>Bedingungen</h2><p class="mu">' . nl2br($e($v['conditions'])) . '</p></div>';
        if ($v['can_decide']) {
            $n = wp_nonce_field('tix_offer_' . $token, 'tix_offer_nonce', false, false);
            $out .= '<div class="c"><h2>Interesse bestätigen</h2><p class="mu">Unverbindlich: Damit sagst du uns, dass du das Angebot ' . $e($v['number']) . ' wahrnehmen möchtest. Den Vertrag schicken wir dir danach separat.</p><form method="post">' . $n . '<label>Dein vollständiger Name<input type="text" name="name" required minlength="3" autocomplete="name"></label>'
                . '<label>Nachricht (optional)<textarea name="note" rows="2"></textarea></label><button class="btn" name="decision" value="accept">Interesse bestätigen</button></form>'
                . '<form method="post" style="margin-top:12px">' . $n . '<button class="btn ghost" name="decision" value="decline" formnovalidate>Ablehnen</button></form></div>';
        }
        $c = $v['contact'];
        $out .= '<div class="c"><h2>Fragen?</h2><p>' . $e($c['name']) . ($c['email'] ? '<br><a href="mailto:' . esc_attr($c['email']) . '">' . $e($c['email']) . '</a>' : '') . ($c['phone'] ? '<br>' . $e($c['phone']) : '') . '</p></div>';
        return $out . '</div></body></html>';
    }

    /** Online: Faktenblock der führenden Stufe und Schritt-für-Schritt-Erklärung (1.38.822), mobil lesbar */
    private static function page_lead(array $L, $fp = null) {
        $e = 'esc_html';
        $out = '<div class="c" style="background:#FFF5F7;border-left:4px solid #FC2F55"><h2 style="font-size:20px">' . $e($L['headline'] . ' – das zahlst du, das bleibt dir:') . '</h2><div class="tl" style="margin-top:8px">';
        foreach ($L['facts'] as $f) $out .= '<div style="background:#fff"><small>' . $e($f['label']) . '</small><strong style="font-size:' . ($f['strong'] ? '28px;color:#FC2F55' : '22px') . '">' . $e($f['value']) . '</strong>' . (!empty($f['per_ticket']) ? '<span>' . $e($f['per_ticket']) . '</span>' : '') . '</div>';
        $out .= '</div><p style="margin:12px 0 0">' . $e($L['note']) . '</p>'
            . ($fp ? '<div class="box" style="background:#fff;border-left:4px solid #E8445A;margin:12px 0 0"><strong>' . $e($fp['title'] . ': ' . $fp['text']) . '</strong><div class="mu" style="margin-top:4px">' . $e($fp['tiers']) . '</div></div>' : '') . '</div>';
        $out .= '<div class="c"><h2>So kommen die Zahlen zustande</h2>';
        foreach ($L['steps'] as $k => $st) {
            $out .= '<div style="margin:0 0 14px"><strong><span class="red">' . ($k + 1) . '</span> ' . $e($st['title']) . '</strong><div>' . $e($st['text']) . '</div>';
            if ($st['lines']) $out .= '<ul style="margin:6px 0 0;padding-left:20px">' . implode('', array_map(function ($l) use ($e) { return '<li>' . $e($l) . '</li>'; }, $st['lines'])) . '</ul>';
            $out .= '</div>';
        }
        return $out . '<p class="mu">' . $e($L['glossary']) . '</p></div>';
    }

    private static function page_tiles(array $B) {
        $e = 'esc_html';
        $out = '<div class="tl">';
        foreach ($B['tiles'] as $i => $tl) $out .= '<div' . ($i === 2 ? ' class="be"' : '') . '><small>' . $e($tl['title']) . '</small><strong>' . $e($tl['value']) . '</strong><span>' . $e($tl['note']) . '</span></div>';
        return $out . '</div>';
    }

    /** Online-Ansicht „Dein eigener Ticketshop“ (AN2): Blöcke 1–4 wie im PDF (Kacheln über page_tiles) */
    private static function page_own(array $v, $token) {
        $e = 'esc_html';
        $B = $v['own'];
        $t = $v['totals'];
        $out = '';
        // 1
        $rate = rtrim(rtrim(number_format($t['tax_rate'], 1, ',', ''), '0'), ',');
        $trs = function (array $list) use ($e, $B) {
            $h = '';
            foreach ($list as $r) {
                $h .= '<tr' . (!empty($r['info']) ? ' class="cmp"' : '') . '><td><strong>' . $e($r['name']) . '</strong>' . (!empty($r['optional']) ? ' <span class="opt">optional</span>' : '') . ($r['detail'] !== '' ? '<div class="mu">' . $e($r['detail']) . '</div>' : '')
                    . ($r['tiered'] && $B['block1']['tier_line'] ? '<div class="mu red">' . $e($B['block1']['tier_line']) . '</div>' : '') . '</td><td class="r">' . $e($r['price'])
                    . ($r['list_price'] ? '<div class="mu"><s>' . $e($r['list_price']) . '</s></div>' : '') . '</td></tr>';
            }
            return $h;
        };
        $sums = function (array $p, $label) use ($e, $rate) {
            $h = '';
            if ($p['yearly']['net'] > 0) {
                if ($p['yearly']['discount'] > 0) $h .= '<tr><td>Rabatt (jährlich)</td><td class="r">−' . $e(self::eur($p['yearly']['discount'])) . '</td></tr>';
                $h .= '<tr><td class="s">' . $e($label) . ' im Jahr (netto ' . self::eur($p['yearly']['net_after']) . ', brutto inkl. ' . $rate . ' % MwSt.)</td><td class="r s">' . $e(self::eur($p['yearly']['gross'])) . '</td></tr>';
            }
            if ($p['once']['net'] > 0) {
                if ($p['once']['discount'] > 0) $h .= '<tr><td>Rabatt (einmalig)</td><td class="r">−' . $e(self::eur($p['once']['discount'])) . '</td></tr>';
                $h .= '<tr><td class="s">' . $e($label) . ' einmalig (brutto)</td><td class="r s">' . $e(self::eur($p['once']['gross'])) . '</td></tr>';
            }
            return $h;
        };
        $has_opt = !empty($t['has_options']) && $B['block1']['option_rows'];
        $out .= '<div class="c"><h2><em>1</em>Grundpaket – das zahlst du an evendis</h2><table>' . $trs($B['block1']['base_rows']) . $sums($t['base'] ?? $t, 'Grundpaket') . '</table>';
        if ($has_opt) {
            $out .= '<h3 style="font-size:15px;margin:20px 0 4px">Optional dazubuchbar</h3><p class="mu" style="margin:0 0 8px">Nicht im Grundpaket enthalten – nur, wenn du es dazunimmst.</p><table>'
                . $trs($B['block1']['option_rows']) . $sums($t['with_options'] ?? $t, 'Mit gewählten Optionen') . '</table>';
        }
        $out .= '<p><a class="btn ghost" href="' . esc_url(add_query_arg(['tix_offer' => $token, 'pdf' => 1], home_url('/'))) . '">Angebot als PDF</a></p></div>';
        if ($B['setup']) {
            $su = $B['setup'];
            $out .= '<div class="c"><h2>' . $e($su['name']) . '</h2><div class="box"><strong>' . $e($su['headline']) . '</strong></div><div class="tl" style="grid-template-columns:1fr 1fr;margin-top:0">'
                . '<div style="padding:0"><small>Das machen wir</small>' . implode('', array_map(function ($x) use ($e) { return '<div class="b"><i></i><div>' . $e($x) . '</div></div>'; }, $su['steps'])) . '</div>'
                . '<div style="padding:0"><small>Das bleibt bei dir</small>' . implode('', array_map(function ($x) use ($e) { return '<div class="b"><i style="background:#C9CCDA"></i><div>' . $e($x) . '</div></div>'; }, $su['customer_tasks'])) . '</div></div></div>';
        }
        // 2
        $out .= '<div class="c"><h2><em>2</em>Was du an deinen Zahlungsdienstleister zahlst</h2><p class="mu">' . $e($B['block2']['note']) . '</p><table>';
        foreach ($B['block2']['rates'] as $r) $out .= '<tr><td><strong>' . $e($r['label']) . '</strong><div class="mu">' . $e($r['source'] . ($r['as_of'] ? ' · Stand ' . $r['as_of'] : '')) . '</div></td><td class="r">' . $e($r['fee']) . '</td></tr>';
        $out .= '</table><p class="mu">' . $e($B['block2']['mix']) . ' ' . $e($B['block2']['providers_note']) . '</p></div>';
        // 3
        $g = $B['block3'];
        $gc = !empty($g['compare']);
        $out .= '<div class="c"><h2><em>3</em>Was dein Gast zahlt</h2><p class="mu">In deinem Shop legst du deine Service- bzw. VVK-Gebühr selbst fest. Sie gehört dir.</p><table>'
            . '<tr><td></td><td class="r mu">Dein Shop</td>' . ($gc ? '<td class="r mu">Plattform evendis.de</td>' : '') . '</tr>'
            . '<tr><td>Ticketpreis</td><td class="r">' . $e(self::eur($g['ticket_price'])) . '</td>' . ($gc ? '<td class="r">' . $e(self::eur($g['ticket_price'])) . '</td>' : '') . '</tr>'
            . '<tr><td>Service-/VVK-Gebühr</td><td class="r">' . $e(self::eur($g['own_fee'])) . ' (an dich)</td>' . ($gc ? '<td class="r">' . $e(self::eur($g['platform_fee'])) . '</td>' : '') . '</tr>'
            . '<tr><td class="s">Gast zahlt</td><td class="r s">' . $e(self::eur($g['pays_own_shop'])) . '</td>' . ($gc ? '<td class="r s">' . $e(self::eur($g['pays_platform'])) . '</td>' : '') . '</tr></table></div>';
        // 4
        $b4 = $B['block4'];
        $rows = $b4['rows'];
        $ev = $B['plan_calc']['to_evendis'];
        // Ergebnis im Überblick (alle Betriebsarten, Angebots-Betriebsart zuerst)
        $tt = $b4['totals'];
        $cell = function ($m, $key, $sign = '') use ($tt, $e) {
            $h = '';
            foreach ($tt as $r) { $x = $r['modes'][$m] ?? null;
                $v = (!$x || !empty($x['on_request'])) ? 'auf Anfrage' : ($key === 'pt' ? number_format($x['kept_per_ticket'], 2, ',', '.') . ' €' : ($key === 'pc' ? number_format($x['kept_percent'], 1, ',', '.') . ' %'
                    : (($sign === '-' && $x[$key] != 0 ? '− ' : ($sign === '+' ? '+ ' : '')) . self::eur0($x[$key]))));
                $h .= '<td class="r">' . $e($v) . '</td>'; }
            return $h;
        };
        $labels = ['own_shop' => self::MODE_TEXTS['own_shop']['label'], 'self_hosted' => self::MODE_TEXTS['self_hosted']['label'], 'platform' => 'Plattform evendis.de (du trägst die Gebühr)'];
        $out .= '<div class="c"><h2><em>4</em>Planrechnung</h2><h3 style="font-size:15px;margin:4px 0 8px">Ergebnis im Überblick – was insgesamt übrig bleibt</h3><div class="sc"><table><tr><td class="mu">Tickets im Jahr</td>';
        foreach ($tt as $r) $out .= '<td class="r mu">' . $e(number_format($r['tickets'], 0, ',', '.') . ($r['custom'] ? '*' : '')) . '</td>';
        $out .= '</tr><tr><td>Ticketumsatz gesamt (brutto)</td>' . $cell('own_shop', 'revenue') . '</tr>';
        foreach ($b4['totals_order'] as $j => $m) {
            $out .= '<tr class="cmp"><td colspan="' . (count($tt) + 1) . '" style="background:#F4F5F8;color:#2C2F3F"><strong>' . $e($labels[$m] . ($j === 0 ? ' – dieses Angebot' : '')) . '</strong></td></tr>';
            if ($m !== 'platform') $out .= '<tr><td>+ Einnahmen eigene Servicegebühr</td>' . $cell($m, 'own_fee', '+') . '</tr>';
            $out .= '<tr><td>− an evendis gesamt' . ($m === 'platform' ? ' (Plattformgebühr, netto)' : ' (netto, inkl. einmalig)') . '</td>' . $cell($m, 'to_evendis', '-') . '</tr>';
            if ($m !== 'platform') $out .= '<tr><td>− an Zahlungsdienstleister gesamt</td>' . $cell($m, 'to_psp', '-') . '</tr>';
            if ($m === 'self_hosted') $out .= '<tr><td>− an deinen Hoster (Server, ca.)</td>' . $cell($m, 'to_hoster', '-') . '</tr>';
            $out .= '<tr class="hl"><td>= bleibt dir insgesamt</td>' . $cell($m, 'kept') . '</tr><tr class="cmp"><td>je Ticket</td>' . $cell($m, 'pt') . '</tr><tr class="cmp"><td>in % vom Ticketumsatz</td>' . $cell($m, 'pc') . '</tr>';
        }
        $out .= '</table></div><p class="mu">' . $e($b4['totals_note'] . (!empty($b4['compare']) ? ' Plattform, wenn Gäste die Gebühr zahlen: dir bleibt der volle Ticketumsatz.' : '')) . '</p>'
            . '<h3 style="font-size:15px;margin:16px 0 8px">Rechnung im Detail (' . $e($B['mode_label']) . ', je Jahr)</h3><div class="sc"><table><tr><td class="mu">Tickets im Jahr</td>';
        foreach ($rows as $r) $out .= '<td class="r mu">' . $e(number_format($r['tickets'], 0, ',', '.') . ($r['custom'] ? '*' : '')) . '</td>';
        $out .= '</tr>';
        $line = function ($label, $key, $sign = '', $cls = '') use ($rows, $e) {
            $h = '<tr' . ($cls ? ' class="' . $cls . '"' : '') . '><td>' . $e($label) . '</td>';
            foreach ($rows as $r) { $x = $r[$key]; $h .= '<td class="r">' . $e($x === null ? 'auf Anfrage' : ($sign === '-' && $x != 0 ? '− ' . self::eur0($x) : ($sign === '+' ? '+ ' . self::eur0($x) : self::eur0($x)))) . '</td>'; }
            return $h . '</tr>';
        };
        $out .= $line('Ticketumsatz (inkl. MwSt.)', 'revenue') . $line('+ eigene Service-/VVK-Gebühr', 'own_fee', '+') . $line('an deinen Zahlungsdienstleister', 'psp', '-')
            . $line('an evendis (' . (($ev['base']['name'] ?? '') ?: 'Eigener Shop') . ')', 'evendis', '-') . ($b4['options'] ? $line('an evendis (Optionen)', 'options', '-') : '')
            . ($b4['channel'] ? $line('evendis.de-Gebühr (netto)', 'channel_fee', '-') : '') . ($b4['server'] ? $line('an deinen Hoster (Server, ca.)', 'server', '-') : '')
            . $line('Bei dir bleibt (' . $B['mode_label'] . ')', 'kept', '', 'hl') . $line('Vergleich ' . $B['other_label'], 'other_kept', '', 'cmp')
            . (!empty($b4['compare']) ? $line('Vergleich Plattform, Gäste zahlen', 'platform_guests_pay', '', 'cmp') . $line('Vergleich Plattform, du trägst', 'platform_you_pay', '', 'cmp') . $line('Vorteil ' . $B['mode_label'] . ' (Gäste zahlen)', 'advantage', '', 'hl') : '');
        $out .= '</table></div>' . (array_filter(array_column($rows, 'custom')) ? '<p class="mu">* deine eigene Zahl</p>' : '')
            . ($b4['reading'] ? '<div class="box">' . $e($b4['reading']) . '</div>' : '')
            . '<p class="mu">' . $e($b4['basis']) . '</p><p class="mu">' . $e($b4['assumptions']) . '</p><p class="mu">' . $e($b4['disclaimer']) . '</p></div>';
        if ($B['references']) {
            $out .= '<div class="c"><h2>So sieht es bei anderen aus</h2>';
            foreach ($B['references'] as $r) {
                $img = $r['image_url'] ?: $r['logo_url'];
                $links = array_filter([$r['url'] ? '<a href="' . esc_url($r['url']) . '" rel="noopener">' . $e(preg_replace('#^https://(www\.)?#', '', rtrim($r['url'], '/'))) . '</a>' : '',
                    $r['app_store_url'] ? '<a href="' . esc_url($r['app_store_url']) . '" rel="noopener">App Store</a>' : '', $r['play_store_url'] ? '<a href="' . esc_url($r['play_store_url']) . '" rel="noopener">Google Play</a>' : '']);
                $out .= '<div class="b" style="align-items:center">' . ($img ? '<img src="' . esc_url($img) . '" alt="' . esc_attr($r['name']) . '" style="width:72px;height:72px;object-fit:cover;border-radius:12px;flex:0 0 72px">' : '<i></i>')
                    . '<div><strong>' . $e($r['name']) . '</strong> <span class="mu">· ' . $e($r['mode_label']) . '</span>' . ($r['text'] !== '' ? '<div>' . $e($r['text']) . '</div>' : '') . ($links ? '<div class="mu">' . implode(' · ', $links) . '</div>' : '') . '</div></div>';
            }
            $out .= '</div>';
        }
        return $out;
    }

    // ═══════════════ PDF ═══════════════

    private static $euro = '€';

    public static function eur($v) {
        return number_format(floatval($v), 2, ',', '.') . ' ' . self::$euro;
    }

    public static function fee_label($pct, $fix) {
        $p = rtrim(rtrim(number_format(floatval($pct), 2, ',', '.'), '0'), ',');
        return $p . ' % + ' . number_format(floatval($fix), 2, ',', '.') . ' ' . self::$euro . ' je Ticket';
    }

    private static function wrap($text, $max) {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $para) {
            $line = '';
            foreach (preg_split('/\s+/', trim($para)) as $w) {
                if ($w === '') continue;
                if (mb_strlen($line . ' ' . $w) > $max && $line !== '') { $out[] = $line; $line = $w; } else $line = $line === '' ? $w : "$line $w";
            }
            $out[] = $line;
        }
        while ($out && end($out) === '') array_pop($out);
        return $out;
    }

    /**
     * Angebot als PDF im Stil der Werbe-PDF (Nachtblau, Signal-Rot, Manrope): Kopf mit Kundenname, Einleitung, Vorteile,
     * Leistungs- und Preistabelle, Summen, Vergleich, Bedingungen, Gültigkeit, Annahme-Link, Kontakt. → bytes | null
     */
    public static function render_pdf(array $d, array $meta) {
        if (!class_exists('TIX_Simple_PDF') && defined('TIXOMAT_PATH')) require_once TIXOMAT_PATH . 'includes/class-tix-event-report.php';
        if (self::layout_of($d) === 'own' && class_exists('TIX_Offer_Plan')) return self::render_pdf_own($d, $meta); // AN2; classic = bisherige Gliederung
        $W = 595; $H = 842; $M = 48;
        $pdfa = class_exists('TIX_PDFA') && TIX_PDFA::available();
        if (!$pdfa && !class_exists('TIX_Simple_PDF')) return null;
        self::$euro = $pdfa ? '€' : 'EUR';
        $pdf = $pdfa ? new TIX_PDFA($W, $H, $M, 40) : new TIX_Simple_PDF($W, $H, $M, 40);
        $brand = class_exists('TIX_Mail_Layout') ? (string) (TIX_Mail_Layout::brand()['name'] ?? 'evendis') : 'evendis';
        $nr = trim((string) ($meta['number'] ?? '')); // leer = Vorschau ohne Nummer (kein Entwurf-Hinweis)
        if ($pdfa) $pdf->set_meta(['title' => trim('Angebot ' . $nr), 'author' => $brand]);
        $col = function ($c) use ($pdf) { $pdf->set_color($c[0], $c[1], $c[2]); };
        $font = function ($bold, $size) use ($pdf) { $pdf->set_font($bold ? 'Helvetica-Bold' : 'Helvetica', $size); };
        $width = function ($s, $size) use ($pdf, $pdfa) { return $pdfa ? $pdf->width($s, $size) : mb_strlen($s) * $size * 0.5; };
        $rt = function ($x, $y, $s, $size) use ($pdf, $width) { $pdf->text(round($x - $width($s, $size), 2), $y, $s); };
        $issuer = [];
        $g = function ($k) { return function_exists('tix_get_settings') ? trim((string) tix_get_settings($k)) : ''; };
        $issuer[] = $g('invoice_company_name') ?: $brand;
        foreach (preg_split('/\r\n|\r|\n/', $g('invoice_company_address')) as $ln) if (trim($ln) !== '') $issuer[] = trim($ln);
        $foot = implode(' · ', array_filter([$issuer[0], $g('invoice_managing_director') ? 'Geschäftsführung: ' . $g('invoice_managing_director') : '', trim($g('invoice_register_court') . ' ' . $g('invoice_register_number')),
            $g('invoice_company_ust_id') ? 'USt-IdNr. ' . $g('invoice_company_ust_id') : ''])) . ' · Seite {p}/{n}';
        $pdf->set_footer($foot);
        $date = !empty($meta['date']) ? wp_date('d.m.Y', strtotime($meta['date'])) : wp_date('d.m.Y');
        $valid = wp_date('d.m.Y', strtotime($d['valid_until']));
        $cust = $d['customer'];
        $who = $cust['company'] ?: $cust['name'];
        $t = self::totals($d);

        $pdf->add_page();
        // ── Kopf: Nachtblau mit roter Kante, wie der Titel der Werbe-PDF
        $col(self::C_NIGHT); $pdf->rect(0, $H - 250, $W, 250, true);
        $col(self::C_RED); $pdf->rect($M, $H - 64, 28, 4, true);
        $col([1, 1, 1]); $font(true, 20); $pdf->text($M, $H - 92, $brand);
        $col([0.79, 0.80, 0.85]); $font(false, 10); if ($nr !== '') $rt($W - $M, $H - 92, 'Angebot ' . $nr, 10);
        $col([1, 1, 1]); $font(true, 26);
        $y = $H - 150;
        foreach (self::wrap($who !== '' ? 'Angebot für ' . $who : 'Angebot', 32) as $ln) { $pdf->text($M, $y, $ln); $y -= 32; }
        $col([0.79, 0.80, 0.85]); $font(false, 11);
        $pdf->text($M, $H - 222, ($nr !== '' ? 'Nr. ' . $nr . ' · ' : '') . $date . ' · gültig bis ' . $valid);

        // ── Empfänger und Kontakt
        $y = $H - 285;
        $rl = array_values(array_filter([$cust['company'], $cust['name'], $cust['street'], trim($cust['zip'] . ' ' . $cust['city']), $cust['country'] !== 'DE' ? $cust['country'] : '', $cust['vat_id'] ? 'USt-IdNr. ' . $cust['vat_id'] : '']));
        $col(self::C_MUTED); $font(true, 8); if ($rl) $pdf->text($M, $y, 'FÜR'); $pdf->text(330, $y, 'DEIN KONTAKT'); // ohne Kundendaten kein leeres „FÜR“
        $y -= 15;
        $cl = array_values(array_filter([$d['contact']['name'], $d['contact']['email'], $d['contact']['phone']]));
        $col(self::C_NIGHT);
        for ($i = 0; $i < max(count($rl), count($cl)); $i++) {
            $font($i === 0, 10);
            if (isset($rl[$i])) $pdf->text($M, $y, mb_substr($rl[$i], 0, 48));
            if (isset($cl[$i])) $pdf->text(330, $y, mb_substr($cl[$i], 0, 42));
            $y -= 14;
        }
        $y -= 14;
        $ensure = function ($need) use (&$y, $pdf, $M) { if ($y - $need < 70) { $pdf->add_page(); $y = 842 - $M - 10; } };

        // ── Einleitung
        if (trim((string) $d['intro']) !== '') {
            $col(self::C_NIGHT); $font(false, 11);
            $salut = $cust['name'] !== '' ? 'Hallo ' . strtok($cust['name'], ' ') . ',' : 'Hallo,';
            $pdf->text($M, $y, $salut); $y -= 20;
            foreach (self::wrap($d['intro'], 92) as $ln) { $ensure(16); $pdf->text($M, $y, $ln); $y -= 15; }
            $y -= 12;
        }

        // ── Vorteile (zweispaltig, rote Punkte)
        $ben = array_values(array_filter((array) $d['benefits'], function ($b) { return !empty($b['on']); }));
        if ($ben) {
            $ensure(60);
            $col(self::C_NIGHT); $font(true, 16); $pdf->text($M, $y, 'Deine Vorteile'); $y -= 8;
            $col(self::C_RED); $pdf->rect($M, $y, 28, 3, true); $y -= 22;
            $colw = ($W - 2 * $M - 20) / 2;
            for ($i = 0; $i < count($ben); $i += 2) {
                $pair = array_slice($ben, $i, 2);
                $hs = [];
                foreach ($pair as $b) $hs[] = 14 + 11 * count(self::wrap($b['text'], 44));
                $h = max($hs);
                $ensure($h + 6);
                foreach ($pair as $j => $b) {
                    $x = $M + $j * ($colw + 20);
                    $col(self::C_RED); $pdf->rect($x, $y + 2, 6, 6, true);
                    $col(self::C_NIGHT); $font(true, 10); $pdf->text($x + 14, $y, mb_substr($b['title'], 0, 46));
                    $col(self::C_MUTED); $font(false, 9);
                    $yy = $y - 13;
                    foreach (self::wrap($b['text'], 44) as $ln) { $pdf->text($x + 14, $yy, $ln); $yy -= 11; }
                }
                $y -= $h + 8;
            }
            $y -= 6;
        }

        // ── Leistungen und Preise (ohne angehakte Position weggelassen – kein leerer Block)
        $xS = $W - $M;
        if (self::active_items($d)) {
        $ensure(90);
        $col(self::C_NIGHT); $font(true, 16); $pdf->text($M, $y, 'Leistungen und Preise'); $y -= 8;
        $col(self::C_RED); $pdf->rect($M, $y, 28, 3, true); $y -= 24;
        $iv = ['monthly' => 'monatlich', 'yearly' => 'jährlich', 'once' => 'einmalig', 'per_event' => 'je Event', 'per_ticket' => 'je Ticket'];
        $xQ = 330; $xU = 430; $xS = $W - $M;
        $col(self::C_SOFT); $pdf->rect($M, $y - 6, $W - 2 * $M, 20, true);
        $col(self::C_MUTED); $font(true, 8);
        $pdf->text($M + 8, $y, 'LEISTUNG'); $pdf->text($xQ, $y, 'ABRECHNUNG'); $rt($xU + 50, $y, 'EINZELPREIS', 8); $rt($xS - 8, $y, 'BETRAG', 8);
        $y -= 24;
        foreach ((array) $d['items'] as $i => $it) {
            $ln = $t['lines'][$i];
            $desc = self::wrap((string) $it['description'], 60);
            $note = (string) $it['note'];
            $ensure(18 + 11 * count($desc) + ($note !== '' ? 11 : 0));
            $col(self::C_NIGHT); $font(true, 10);
            $pdf->text($M + 8, $y, mb_substr(($it['qty'] > 1 ? $it['qty'] . ' × ' : '') . $it['name'], 0, 50));
            $font(false, 10);
            $pdf->text($xQ, $y, $iv[$it['interval']] ?? '');
            if ($it['interval'] === 'per_ticket') {
                $font(true, 10); $rt($xS - 8, $y, self::fee_label($it['fee_percent'], $it['fee_fixed']), 10);
            } elseif ($ln['unit'] === null) {
                $col(self::C_MUTED); $rt($xS - 8, $y, 'auf Anfrage', 10);
            } else {
                $rt($xU + 50, $y, self::eur($ln['unit']), 10);
                $font(true, 10); $rt($xS - 8, $y, $ln['unit'] == 0 ? 'kostenlos' : self::eur($ln['net']), 10);
            }
            $y -= 13;
            if ($ln['overridden'] && $it['list_price'] !== null) {
                $col(self::C_MUTED); $font(false, 8); $s = 'statt ' . self::eur($it['list_price']); $rt($xU + 50, $y, $s, 8);
                $col(self::C_MUTED); $pdf->rect($xU + 50 - $width($s, 8) + $width('statt ', 8), $y + 3, $width(self::eur($it['list_price']), 8), 0.6, true);
            }
            $col(self::C_MUTED); $font(false, 9);
            foreach ($desc as $dl) { $pdf->text($M + 8, $y, $dl); $y -= 11; }
            if ($note !== '') { $col(self::C_RED); $font(false, 9); $pdf->text($M + 8, $y, mb_substr($note, 0, 80)); $y -= 11; }
            $y -= 4;
            $col(self::C_LINE); $pdf->rect($M, $y + 4, $W - 2 * $M, 0.8, true);
            $y -= 10;
        }

        // ── Summen
        $sum = function ($label, $amount, $bold = false, $muted = false) use (&$y, $pdf, $col, $font, $rt, $xS, $ensure) {
            $ensure(16);
            $col($muted ? self::C_MUTED : self::C_NIGHT); $font($bold, $bold ? 11 : 10);
            $pdf->text(300, $y, $label); $rt($xS - 8, $y, $amount, $bold ? 11 : 10);
            $y -= $bold ? 18 : 14;
        };
        $rate = rtrim(rtrim(number_format($t['tax_rate'], 1, ',', ''), '0'), ',');
        $dl = (string) ($d['discount']['label'] ?? '') ?: 'Rabatt' . ($d['discount']['type'] === 'percent' ? ' ' . rtrim(rtrim(number_format($d['discount']['value'], 2, ',', ''), '0'), ',') . ' %' : '');
        $ensure(20 + ($t['yearly']['net'] > 0 ? 92 : 0) + ($t['once']['net'] > 0 ? 64 : 0) + 14 * count($t['fees']));
        if ($t['yearly']['net'] > 0) {
            $y -= 4;
            $sum('Wiederkehrend im Jahr, netto', self::eur($t['yearly']['net']));
            if ($t['yearly']['discount'] > 0) $sum($dl, '– ' . self::eur($t['yearly']['discount']));
            $sum("zzgl. $rate % MwSt.", self::eur($t['yearly']['tax']), false, true);
            $y -= 4;
            $col(self::C_PINK); $pdf->rect(290, $y - 5, $xS - 290, 19, true);
            $sum('Im Jahr, brutto', self::eur($t['yearly']['gross']), true);
            $sum('entspricht im Monat, netto', self::eur($t['monthly_net']), false, true);
        }
        if ($t['once']['net'] > 0) {
            $y -= 4;
            $sum('Einmalig, netto', self::eur($t['once']['net']));
            if ($t['once']['discount'] > 0) $sum($dl, '– ' . self::eur($t['once']['discount']));
            $sum("zzgl. $rate % MwSt.", self::eur($t['once']['tax']), false, true);
            $sum('Einmalig, brutto', self::eur($t['once']['gross']), true);
        }
        foreach ($t['fees'] as $f) $sum($f['name'] . ' (inkl. MwSt.)', self::fee_label($f['fee_percent'], $f['fee_fixed']), false, true);
        if (!empty($d['term_months'])) $sum('Laufzeit', $d['term_months'] . ' Monate', false, true);
        $y -= 12;

        } // Ende Leistungen und Preise
        // Vergleich mit dem bisherigen Anbieter: nie im PDF (User-Entscheid 06.11.), nur Gesprächshilfe im Studio

        // ── Hochrechnung je Event (abschaltbar über projection.in_pdf)
        $fc = $t['projection'];
        if ($fc && !empty($d['projection']['in_pdf'])) {
            $in = $fc['input']; $pv = $fc['commission']; $sh = $fc['selfhosted']; $cm = $fc['comparison'];
            $ownl = self::mode_of($d) === 'self_hosted' ? 'Selbstgehostet' : 'Eigener Shop'; // Begriffe (User 14.11.): eigener Shop ≠ selbstgehostet
            $na = function ($v) { return $v === null ? 'Satz fehlt' : self::eur($v); };
            $pc = function ($v) { return $v === null ? '–' : rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',') . ' %'; };
            $ensure(260);
            $col(self::C_NIGHT); $font(true, 14); $pdf->text($M, $y, 'Hochrechnung je Event'); $y -= 18;
            $col(self::C_MUTED); $font(false, 9);
            $pay = ['paypal' => 'PayPal', 'card' => 'Kreditkarte & Klarna', 'both' => 'PayPal ' . $in['paypal_share'] . ' % · Kreditkarte & Klarna ' . $in['card_klarna_share'] . ' %'][$in['payment']];
            $pdf->text($M, $y, number_format($in['tickets'], 0, ',', '.') . ' Tickets je Event · Ø ' . self::eur($in['avg_price']) . ' · ' . $in['events_year'] . ' Events im Jahr · Umsatz je Event ' . self::eur($fc['revenue_event'])); $y -= 12;
            $pdf->text($M, $y, 'Zahlarten bei ' . $ownl . ': ' . $pay . ' · ' . $in['tickets_per_order'] . ' Ticket(s) je Bestellung'); $y -= 18;
            $x1 = 360; $x2 = $xS - 8;
            $col(self::C_MUTED); $font(true, 8); $rt($x1, $y, 'PROVISIONSMODELL', 8); $rt($x2, $y, mb_strtoupper($ownl), 8); $y -= 14;
            $row = function ($label, $a, $b, $bold = false) use (&$y, $pdf, $col, $font, $rt, $x1, $x2, $M, $W, $ensure) {
                $ensure(16);
                $col(self::C_NIGHT); $font($bold, 10); $pdf->text($M + 8, $y, $label); $rt($x1, $y, $a, 10); $rt($x2, $y, $b, 10);
                $y -= 6; $col(self::C_LINE); $pdf->rect($M, $y, $W - 2 * $M, 0.6, true); $y -= 10;
            };
            $row('An evendis je Event', self::eur($pv['to_evendis_event']), self::eur($sh['to_evendis_event']));
            $row('An deinen Zahlungsdienstleister', self::eur(0) . ' (inklusive)', $na($sh['to_psp_event']));
            $row('Summe je Event', self::eur($pv['total_event']), $na($sh['total_event']), true);
            $row('Je Ticket', $pv['per_ticket'] === null ? '–' : self::eur($pv['per_ticket']), $na($sh['per_ticket']));
            $row('In % vom Umsatz', $pc($pv['percent_of_revenue']), $pc($sh['percent_of_revenue']));
            $row('Aufs Jahr', self::eur($pv['per_year']), $na($sh['per_year']));
            $bear = ['organizer' => 'Du trägst die Gebühr.', 'split' => 'Gäste und du teilt die Gebühr: du ' . self::eur($pv['organizer_pays']) . ' je Event.', 'guest' => 'Deine Gäste zahlen die Gebühr als Vorverkaufsgebühr.'][$pv['bearer']];
            $col(self::C_MUTED); $font(false, 8);
            $pdf->text($M + 8, $y, 'Provisionsmodell: ' . self::fee_label($pv['fee_percent'], $pv['fee_fixed']) . ', inkl. MwSt. und Zahlung, Freitickets 0 €. ' . $bear); $y -= 11;
            $pdf->text($M + 8, $y, $ownl . ': ' . $sh['package'] . ' ' . self::eur($sh['package_year']) . ' im Jahr inkl. MwSt., umgelegt auf ' . $in['events_year'] . ' Events, 0 € je Ticket.'); $y -= 11;
            foreach ($sh['methods'] as $m) {
                $pdf->text($M + 8, $y, sprintf('%s (%s, %s %%): %s', $m['label'], $m['provider'], rtrim(rtrim(number_format($m['share'], 1, ',', ''), '0'), ','),
                    $m['rate_missing'] ? 'Satz bitte im Katalog eintragen' : rtrim(rtrim(number_format(floatval($m['percent']), 2, ',', '.'), '0'), ',') . ' % + ' . self::eur($m['fixed']) . ' je Zahlung = ' . self::eur($m['cost_event']) . ' je Event')); $y -= 11;
            }
            $y -= 4;
            if ($cm['cheaper']) {
                $ensure(24);
                $col(self::C_PINK); $pdf->rect($M, $y - 6, $W - 2 * $M, 20, true);
                $col(self::C_NIGHT); $font(true, 10);
                $txt = $cm['cheaper'] === 'equal' ? 'Beide Modelle kosten bei dieser Prognose gleich viel.'
                    : 'Bei dieser Prognose ist ' . ($cm['cheaper'] === 'selfhosted' ? ($ownl === 'Eigener Shop' ? 'der eigene Shop' : 'Selbstgehostet') : 'das Provisionsmodell') . ' um ' . self::eur($cm['difference_event']) . ' je Event günstiger (' . self::eur($cm['difference_year']) . ' im Jahr).';
                $pdf->text($M + 10, $y, $txt); $y -= 22;
                if ($cm['break_even_tickets_month']) { $col(self::C_MUTED); $font(false, 9); $pdf->text($M + 8, $y, $ownl . ' lohnt sich ab ' . number_format($cm['break_even_tickets_month'], 0, ',', '.') . ' Tickets im Monat.'); $y -= 13; }
            }
            $col(self::C_MUTED); $font(false, 8);
            foreach (array_merge(self::wrap($fc['payment_note'], 120), [$fc['rates_note'] . ' ' . $fc['basis_note']]) as $ln) foreach (self::wrap($ln, 120) as $l2) { $ensure(11); $pdf->text($M + 8, $y, $l2); $y -= 10; }
            $y -= 12;
        }

        // ── Bedingungen, Gültigkeit, Annahme
        $ensure(70);
        $col(self::C_NIGHT); $font(true, 14); $pdf->text($M, $y, 'Bedingungen'); $y -= 18;
        $col(self::C_MUTED); $font(false, 9);
        $cond = trim((string) $d['conditions']);
        $lines = array_merge($cond !== '' ? self::wrap($cond, 105) : [], ['Dieses Angebot gilt bis ' . $valid . '.']);
        foreach ($lines as $ln) { $ensure(12); $pdf->text($M, $y, $ln); $y -= 12; }
        $y -= 10;
        if (!empty($meta['link'])) {
            $ensure(56);
            $col(self::C_PINK); $pdf->rect($M, $y - 34, $W - 2 * $M, 50, true);
            $col(self::C_NIGHT); $font(true, 11); $pdf->text($M + 12, $y, 'Interesse bestätigen – unverbindlich');
            $col(self::C_MUTED); $font(false, 9); $pdf->text($M + 12, $y - 14, 'Mit einem Klick unter diesem Link, den Vertrag schicken wir dir danach separat:');
            $col(self::C_RED); $font(false, 8); $pdf->text($M + 12, $y - 27, mb_substr($meta['link'], 0, 120));
            $y -= 56;
        } else {
            $ensure(30);
            $col(self::C_NIGHT); $font(false, 9);
            $pdf->text($M, $y, 'Interesse? Antworte einfach an ' . ($d['contact']['email'] ?: 'uns') . ' – den Vertrag schicken wir dir danach separat.'); $y -= 20;
        }
        $ensure(30);
        $col(self::C_NIGHT); $font(true, 10); $pdf->text($M, $y, 'Lass uns sprechen: ' . implode(' · ', array_filter([$d['contact']['name'], $d['contact']['email'], $d['contact']['phone']])));
        return $pdf->output();
    }

    // ═══════════════ AN2: Gliederung „Dein eigener Ticketshop“ (PDF und Online-Ansicht) ═══════════════

    /** Planrechnung des Angebots; ohne eigene Annahmen mit den Vorgaben (Gliederung own zeigt sie immer) */
    public static function plan_for_offer(array $d) {
        if (!class_exists('TIX_Offer_Plan')) return null;
        $p = !empty($d['plan']) && is_array($d['plan']) ? $d['plan'] : TIX_Offer_Plan::clean([], self::catalog());
        return is_array($p) ? self::with_base_lead(TIX_Offer_Plan::compute($p, self::catalog(), self::plan_context($d)), $d) : null;
    }

    public static function pct($v) { return rtrim(rtrim(number_format(floatval($v), 2, ',', '.'), '0'), ','); }

    public static function eur0($v) { $v = round(floatval($v)); return ($v < 0 ? '−' : '') . number_format(abs($v), 0, ',', '.') . ' ' . self::$euro; }

    /** Inhalte der drei Kacheln und der Blöcke 1–4 (gemeinsam für PDF und Online-Ansicht) */
    public static function own_blocks(array $d) {
        $pc = self::plan_for_offer($d);
        if (!$pc) return null;
        $t = self::totals($d);
        $cmp = !empty($d['compare_platform']);
        $ev = $pc['to_evendis'];
        $tiers = $ev['tiers'];
        $base = $ev['base'];
        $iv = ['monthly' => 'monatlich', 'yearly' => 'jährlich', 'once' => 'einmalig', 'per_event' => 'je Event', 'per_ticket' => 'je Ticket'];
        // Block 1: angehakte Positionen mit gewähltem Preis – Grundpaket und „Optional dazubuchbar“ getrennt (1.38.823)
        $rows = []; $opt_rows = [];
        foreach ((array) $d['items'] as $i => $it) {
            $ln = $t['lines'][$i] ?? null;
            if (!$ln || empty($ln['on'])) continue;
            $tiered_row = in_array($it['catalog_key'] ?? '', self::TIERED_KEYS, true);
            if ($it['interval'] === 'per_ticket') { $price = self::fee_label($it['fee_percent'], $it['fee_fixed']); }
            elseif ($ln['unit'] === null) { $price = 'auf Anfrage'; }
            elseif ($ln['unit'] == 0) { $price = ($it['catalog_key'] ?? '') !== 'einrichtung' ? 'kostenlos' : (($it['note'] ?? '') === 'erlassen' ? self::eur(0) : 'inklusive'); }
            else { $price = self::eur($ln['net']) . ' ' . ['monthly' => '/ Monat', 'yearly' => '/ Jahr', 'once' => 'einmalig', 'per_event' => 'je Event'][$it['interval']]; }
            $det = [(string) $it['note']];
            if ($tiered_row) $det = ['Jahresvertrag', $ln['unit'] !== null && $ln['unit'] > 0 && !$ln['overridden'] && $it['price'] === null ? 'Preisstufe für ' . number_format(intval($pc['lead']['tickets'] ?? 0), 0, ',', '.') . ' Tickets im Jahr' : ''];
            elseif ($it['interval'] !== 'once' || ($it['note'] ?? '') === '') $det[] = $iv[$it['interval']] ?? '';
            if (($it['catalog_key'] ?? '') === 'shop_monatlich') $det = ['bei monatlicher Zahlung', 'monatlich kündbar']; // Monatspreis nur hier (1.38.823)
            if ($it['interval'] === 'monthly' && $ln['net'] !== null && $ln['net'] > 0) $det[] = '= ' . self::eur($ln['net'] * 12) . ' im Jahr';
            $r = ['name' => ($it['qty'] > 1 ? $it['qty'] . ' × ' : '') . $it['name'], 'detail' => trim(implode(' · ', array_values(array_unique(array_filter($det))))),
                'price' => $price, 'list_price' => $ln['overridden'] && $it['list_price'] !== null ? self::eur($it['list_price'] * $it['qty']) : null,
                'tiered' => $base && !empty($base['tiered']) && ($it['catalog_key'] ?? '') === $base['catalog_key'], 'optional' => self::is_optional($it)];
            if ($r['optional']) $opt_rows[] = $r; else $rows[] = $r;
        }
        $tier_txt = [];
        foreach ($tiers as $x) $tier_txt[] = 'bis ' . number_format($x['max'], 0, ',', '.') . ' Tickets ' . self::eur0($x['price']);
        $tier_line = $tier_txt ? 'Staffel je Jahr: ' . implode(' · ', $tier_txt) . ', darüber auf Anfrage' : '';
        if (count($tiers) === 1 && intval($tiers[0]['max']) >= TIX_Offer_Plan::MAX_T) $tier_line = 'Pauschal je Jahr, ohne Grenze bei der Ticketzahl';
        $rows[] = ['name' => '0 € je verkauftem Ticket in deinem Shop', 'detail' => '', 'price' => self::eur(0), 'list_price' => null, 'tiered' => false, 'optional' => false];
        $mode = $pc['mode'];
        $mt = self::MODE_TEXTS[$mode];
        if ($mode === 'self_hosted') $rows[] = ['name' => 'Server bei deinem Hoster (nicht an evendis)', 'detail' => 'Server-Miete und Verantwortung bei dir; Installation und Updates per Paket – nicht in der Summe',
            'price' => 'ca. ' . self::eur($pc['server_month']) . ' / Monat', 'list_price' => null, 'tiered' => false, 'info' => true, 'optional' => false];
        $ch = $ev['channel'];
        if ($ch) $rows[] = ['name' => 'Zusätzlich über evendis.de verkaufen', 'detail' => 'je Ticket, trägst du, zzgl. Zahlungskosten deines Anbieters', 'price' => self::fee_label($ch['percent'], $ch['fixed']) . ' inkl. MwSt.', 'list_price' => null, 'tiered' => false, 'optional' => false];
        $base_rows = $rows;
        $rows = array_merge($rows, $opt_rows);
        // Kachel „an evendis“
        $first = $ev['fixed_price'] !== null ? $ev['fixed_price'] : (empty($pc['lead_base']['on_request']) ? ($pc['lead_base']['base_price'] ?? ($tiers[0]['price'] ?? null)) : null);
        $tile1 = $first === null ? 'auf Anfrage' : self::eur0($first) . ' / Jahr';
        $tile1n = '0 € je Ticket · zzgl. MwSt.' . ($ev['options_year'] > 0 ? ' · Optionen + ' . self::eur0($ev['options_year']) . ' / Jahr' : '') . ($mode === 'self_hosted' ? ' · Server ca. ' . self::eur0($pc['server_month']) . ' / Monat beim Hoster' : '');
        $m = $pc['to_psp'];
        $mix = [];
        foreach ($m['methods'] as $x) $mix[] = self::pct($x['share']) . ' % ' . $x['label'] . ($x['provider'] && stripos($x['label'], $x['provider']) === false ? ' (' . $x['provider'] . ')' : '');
        $be = $pc['break_even'];
        $in = $pc['input'];
        $other = $mode === 'self_hosted' ? 'own_shop' : 'self_hosted';
        $prow = array_map(function ($r) use ($other, $cmp) { $r['other_kept'] = $r['compare'][$other]['kept'] ?? null;
            return $cmp ? $r : array_diff_key($r, array_flip(['platform_guests_pay', 'platform_you_pay', 'advantage', 'advantage_without_fee'])); }, $pc['rows']);
        // Rundum-Einrichtung (Position „einrichtung“ angehakt)
        $setup = null;
        $act = self::active_items($d);
        $keys = array_column($act, 'catalog_key');
        if (in_array('einrichtung', $keys, true)) {
            $su = (array) (self::catalog()['setup'] ?? self::default_setup());
            $has_app = (bool) array_intersect($keys, ['app', 'app_modul', 'app_alle_module', 'app_einrichtung']);
            $fit = function ($r) use ($mode, $has_app) { return in_array($mode, (array) $r['modes'], true) && ($r['needs'] !== 'app' || $has_app); };
            $si = null; foreach ($act as $x) if ($x['catalog_key'] === 'einrichtung') { $si = $x; break; }
            $setup = ['headline' => (string) ($su['headline'] ?? ''), 'name' => $si['name'],
                'steps' => array_values(array_column(array_filter((array) $su['steps'], $fit), 'text')), 'customer_tasks' => array_values(array_column(array_filter((array) $su['customer_tasks'], $fit), 'text'))];
        }
        $refs = [];
        foreach ((array) ($d['references'] ?? []) as $rk) if (($r = self::reference($rk)) && $r['approved']) $refs[] = array_diff_key($r, ['approved' => 1, 'customer_key' => 1]) + ['mode_label' => self::MODE_TEXTS[$r['mode']]['label']];
        // Vorteile: angehakte; selbstgehostet immer mit „Deine Daten liegen allein bei dir …“ (User 14.11.)
        $ben = array_values(array_map(function ($b) { return ['title' => $b['title'], 'text' => $b['text']]; }, array_filter((array) $d['benefits'], function ($b) { return !empty($b['on']); })));
        if ($mode === 'self_hosted' && !in_array('Deine Daten liegen allein bei dir', array_column($ben, 'title'), true)) array_unshift($ben, ['title' => 'Deine Daten liegen allein bei dir', 'text' => 'Deine Daten liegen allein bei dir auf deinem eigenen Server.']);
        $pcl = $pc; $pcl['lead'] = $pc['lead_base'] ?? $pc['lead']; // Faktenblock mit dem Grundpaket (1.38.823)
        // Festpreis (1.38.824, User: „Es muss klar sein, dass bis 5000 verkaufte Tickets nicht mehr gezahlt wird“) – Stufe der führenden Ticketzahl
        $fixed_note = null;
        $ltk = intval($pcl['lead']['tickets'] ?? 0);
        if ($ev['fixed_price'] !== null) $fixed_note = ['title' => 'Festpreis', 'text' => 'Du zahlst ' . self::eur0($ev['fixed_price']) . ' im Jahr – egal wie viele Tickets du verkaufst, keine Gebühr je Ticket an evendis.', 'tiers' => 'Preis zzgl. MwSt.'];
        elseif (empty($pcl['lead']['on_request'])) foreach ($tiers as $x) if ($ltk <= intval($x['max'])) {
            $fixed_note = ['title' => 'Festpreis', 'text' => 'Bis ' . number_format($x['max'], 0, ',', '.') . ' verkaufte Tickets im Jahr zahlst du nie mehr als ' . self::eur0($x['price']) . ' – egal wie viel du verkaufst, keine Gebühr je Ticket an evendis.',
                'tiers' => ($tier_line !== '' ? $tier_line . '. ' : '') . 'Preise zzgl. MwSt.', 'max' => intval($x['max']), 'price' => round(floatval($x['price']), 2)];
            break;
        }
        $lead = self::lead_texts($pcl, $mode, $cmp);
        $LB = $pcl['lead'];
        return [
            'plan' => $pc, 'totals' => $t, 'compare_platform' => $cmp, 'fixed_price' => $fixed_note, 'mode' => $mode, 'mode_label' => $mt['label'], 'benefits' => $ben, 'lead' => $lead, 'title' => $mt['title'], 'subtitle' => $mt['sub'],
            'other_mode' => $other, 'other_label' => self::MODE_TEXTS[$other]['label'], 'setup' => $setup, 'references' => $refs,
            'tiles' => [
                ['title' => 'An evendis', 'value' => $tile1, 'note' => $tile1n],
                ['title' => 'An deinen Zahlungsanbieter', 'value' => self::pct($m['blended_percent']) . ' % + ' . number_format($m['blended_fixed'], 2, ',', '.') . ' ' . self::$euro, 'note' => 'je Zahlung, direkt an ihn – nicht an evendis'],
                $cmp ? ['title' => 'Lohnt sich ab', 'value' => $be['with_fee'] ? number_format($be['with_fee'], 0, ',', '.') . ' Tickets/Jahr' : 'individuell',
                    'note' => 'mit eigener Gebühr ' . self::eur($in['service_fee']) . ($be['without_fee'] ? ' · ohne: ab ' . number_format($be['without_fee'], 0, ',', '.') : '')]
                : ['title' => 'Dir bleiben je Ticket', 'value' => empty($LB['on_request']) && isset($LB['kept_per_ticket']) ? self::eur($LB['kept_per_ticket']) : 'auf Anfrage',
                    'note' => 'bei ' . number_format(intval($LB['tickets'] ?? 0), 0, ',', '.') . ' Tickets im Jahr, Grundpaket'],
            ],
            'block1' => ['rows' => $rows, 'base_rows' => $base_rows, 'option_rows' => $opt_rows, 'tier_line' => $tier_line, 'once' => $ev['once']],
            'block2' => ['note' => (string) $m['note'] ?: 'Du schließt dein eigenes Konto an (z. B. Mollie, Stripe oder PayPal). Dessen Gebühren zahlst du direkt an ihn, sie gehen nicht an evendis. Dafür landet das Geld aus dem Ticketverkauf sofort auf deinem Konto – nicht erst nach dem Event.',
                'rates' => array_map(function ($r) { return ['label' => $r['label'], 'provider' => $r['provider'], 'fee' => $r['percent'] === null ? 'Satz fehlt' : self::pct($r['percent']) . ' % + ' . number_format(floatval($r['fixed']), 2, ',', '.') . ' ' . self::$euro, 'source' => preg_replace('#^https?://(www\.)?#', '', (string) $r['source']), 'as_of' => $r['as_of'] ? wp_date('d.m.Y', strtotime($r['as_of'])) : '']; }, $m['catalog']),
                'mix' => 'Eine Zahlung ist eine Bestellung (Annahme: ' . self::pct($in['tickets_per_order']) . ' Tickets je Bestellung). In dieser Rechnung: ' . implode(', ', $mix) . ', also im Mittel ' . self::pct($m['blended_percent']) . ' % + ' . number_format($m['blended_fixed'], 3, ',', '.') . ' ' . self::$euro . ' je Zahlung.',
                'providers_note' => $m['providers_note']],
            'block3' => ($cmp ? $pc['guest'] : array_diff_key($pc['guest'], array_flip(['platform_fee', 'pays_platform']))) + ['compare' => $cmp],
            'block4' => ['rows' => $prow, 'options' => $ev['options_year'] > 0, 'channel' => (bool) $ch, 'server' => $mode === 'self_hosted',
                // Ergebnis im Überblick (User 14.11.): je Stufe und Betriebsart, Angebots-Betriebsart zuerst
                'totals' => $pc['totals'], 'totals_order' => $cmp ? [$mode, $other, 'platform'] : [$mode, $other], 'totals_note' => $cmp ? $pc['totals_note'] : str_replace('; Plattformgebühr netto (÷ ' . number_format(1 + $t['tax_rate'] / 100, 2, ',', '') . ')', '', $pc['totals_note']),
                'compare' => $cmp,
                'reading' => $cmp && $be['with_fee'] ? 'Mit eigener Gebühr von ' . self::eur($in['service_fee']) . ' lohnt sich ' . ($mode === 'self_hosted' ? 'dein Shop auf deinem Server' : 'der eigene Shop') . ' gegenüber der Ticketplattform ab rund ' . number_format($be['with_fee'], 0, ',', '.') . ' Tickets im Jahr. '
                    . ($be['without_fee'] ? 'Ohne eigene Gebühr ab rund ' . number_format($be['without_fee'], 0, ',', '.') . ' Tickets. ' : '') . 'Darunter ist die Ticketplattform für dich günstiger – dort ist die Zahlung schon enthalten.' : '',
                'assumptions' => 'Annahmen: Ø Ticketpreis ' . self::eur($in['avg_price']) . ' inkl. MwSt., ' . self::pct($in['tickets_per_order']) . ' Tickets je Bestellung, Zahlarten ' . implode(', ', $mix) . ($ch && $in['channel_share'] > 0 ? ', ' . self::pct($in['channel_share']) . ' % der Tickets über evendis.de' : '') . ($mode === 'self_hosted' ? ', Server beim Hoster ca. ' . self::eur($pc['server_month']) . ' im Monat' : '') . ', keine Erstattungen.',
                'basis' => 'Ticketumsatz und eigene Gebühr wie vom Gast bezahlt (inkl. MwSt.); evendis-Preise zzgl. MwSt' . ($cmp ? '; die Plattformgebühr ist ohne MwSt. gerechnet (÷ ' . number_format(1 + $t['tax_rate'] / 100, 2, ',', '') . '), weil du die MwSt. als Vorsteuer zurückbekommst.' : '.' . ($t['has_options'] ? ' Gewählte Optionen sind in der Planrechnung enthalten, im Faktenblock oben nur das Grundpaket.' : '')),
                'disclaimer' => 'Ohne Gewähr: Gebühren der Zahlungsdienstleister laut deren öffentlichen Preisseiten; sie können sich beim Anbieter ändern und hängen von Kartenart und Vertrag ab. Alle evendis-Preise zzgl. MwSt.' . ($ev['once'] > 0 ? ' Einmalige Kosten (' . self::eur0($ev['once']) . ') sind in der Jahresrechnung nicht enthalten.' : '')],
        ];
    }

    /**
     * Faktenblock und Schritt-für-Schritt-Erklärung der führenden Stufe (1.38.822, User: „ganz oben als Fakt … darunter alles Idiotensicher erklärt“).
     * → {tickets, on_request, headline, facts[{label, value, strong}], note, steps[{title, text, lines[]}], glossary}
     */
    public static function lead_texts(array $pc, $mode, $compare = false) {
        $L = $pc['lead'] ?? null;
        if (!$L) return null;
        $n = function ($v) { return number_format(floatval($v), 0, ',', '.'); };
        $e2 = function ($v) { return self::eur($v); };
        $e0 = function ($v) { return self::eur0($v); };
        $t = $L['tickets'];
        if (!empty($L['on_request'])) {
            return ['tickets' => $t, 'on_request' => true, 'headline' => 'Bei ' . $n($t) . ' Tickets im Jahr', 'facts' => [],
                'note' => 'Ab dieser Menge erstellen wir dir ein individuelles Angebot – sprich uns an.', 'steps' => [], 'glossary' => ''];
        }
        $in = $pc['input'];
        $ev = $pc['to_evendis'];
        $pt = function ($v) use ($t, $e2) { return $t > 0 ? $e2($v / $t) . ' je Ticket' : ''; };
        $facts = [['label' => 'an evendis', 'value' => $e0($L['to_evendis']), 'strong' => false, 'per_ticket' => $pt($L['to_evendis'])],
            ['label' => 'an deinen Zahlungsanbieter', 'value' => $e0($L['to_psp']), 'strong' => false, 'per_ticket' => $pt($L['to_psp'])]];
        if ($mode === 'self_hosted') $facts[] = ['label' => 'an deinen Hoster (ca.)', 'value' => $e0($L['to_hoster']), 'strong' => false, 'per_ticket' => $pt($L['to_hoster'])];
        $facts[] = ['label' => 'dir bleiben', 'value' => $e0($L['kept']), 'strong' => true, 'per_ticket' => $e2($L['kept_per_ticket']) . ' je Ticket'];
        $sv = $L['savings_vs_platform'];
        // Vergleich mit der Plattform nur auf Wunsch (1.38.823, compare_platform)
        $note = implode(' · ', array_filter(['Gerechnet mit dem Grundpaket im ersten Jahr',
            $compare ? ($sv >= 0 ? $e0($sv) . ' mehr als auf der Ticketplattform (wenn du dort die Gebühr trägst)' : 'auf der Ticketplattform blieben dir ' . $e0(-$sv) . ' mehr (wenn du dort die Gebühr trägst)') : '',
            $L['to_evendis_once'] > 0 ? 'an evendis inkl. ' . $e0($L['to_evendis_once']) . ' einmalig' : '']));
        $steps = [];
        $steps[] = ['title' => 'Deine Ticketeinnahmen', 'text' => $n($t) . ' Tickets × ' . $e2($in['avg_price']) . ' Ø Ticketpreis = ' . $e0($L['revenue']) . '. So viel zahlen deine Gäste für die Tickets (inklusive Mehrwertsteuer).', 'lines' => []];
        $steps[] = $in['service_fee'] > 0
            ? ['title' => 'Deine eigene Servicegebühr', 'text' => 'Im eigenen Shop legst du deine Servicegebühr selbst fest, hier ' . $e2($in['service_fee']) . ' je Ticket: ' . $n($t) . ' × ' . $e2($in['service_fee']) . ' = ' . $e0($L['own_fee']) . '. Diese Gebühr gehört komplett dir.', 'lines' => []]
            : ['title' => 'Deine eigene Servicegebühr', 'text' => 'Du nimmst in dieser Rechnung keine eigene Servicegebühr. Du kannst jederzeit eine festlegen – sie gehört dann dir.', 'lines' => []];
        $ox = $L['order_example'];
        $lines = [];
        foreach ($ox['methods'] as $m) $lines[] = $m['label'] . ($m['provider'] && stripos($m['label'], $m['provider']) === false ? ' (' . $m['provider'] . ')' : '') . ': ' . self::pct($m['percent']) . ' % von ' . $e2($ox['gross']) . ' + ' . $e2($m['fixed']) . ' = ' . $e2($m['fee']);
        $mixt = implode(' und ', array_map(function ($m) { return self::pct($m['share']) . ' % ' . $m['label']; }, $ox['methods']));
        $steps[] = ['title' => 'Beispiel: eine einzelne Bestellung', 'text' => 'Eine Bestellung hat im Schnitt ' . self::pct($ox['tickets']) . ' Tickets. Dein Gast zahlt ' . self::pct($ox['tickets']) . ' × (' . $e2($ox['ticket_price']) . ' + ' . $e2($ox['own_fee']) . ') = ' . $e2($ox['gross']) . '. Davon behält der Zahlungsanbieter je nach Zahlart:',
            'lines' => array_merge($lines, ['Bei ' . $mixt . ' sind das im Schnitt ' . $e2($ox['blended_fee']) . ' je Bestellung.'])];
        $steps[] = ['title' => 'An deinen Zahlungsanbieter', 'text' => $n($L['orders']) . ' Bestellungen × ' . $e2($ox['blended_fee']) . ' = rund ' . $e0($L['to_psp']) . ' im Jahr. Das zahlst du direkt an deinen Zahlungsanbieter (z. B. Mollie, Stripe oder PayPal) – nicht an evendis. Dafür ist das Geld sofort auf deinem Konto.', 'lines' => []];
        $el = [];
        $tier = null; foreach ((array) $ev['tiers'] as $x) if ($t <= $x['max']) { $tier = $x; break; }
        $el[] = ($ev['base']['name'] ?? self::MODE_TEXTS[$mode]['label']) . ': ' . $e0($L['base_price']) . ' im Jahr' . ($ev['fixed_price'] === null && $tier ? ' (Preisstufe bis ' . $n($tier['max']) . ' Tickets)' : '');
        if (floatval($L['options_year'] ?? 0) > 0) foreach ((array) $ev['options'] as $o) $el[] = '+ ' . $o['name'] . ': ' . $e0($o['year']) . ' im Jahr';
        if ($L['channel_fee'] > 0) $el[] = '+ Verkäufe über evendis.de: ' . $e0($L['channel_fee']) . ' (ohne Mehrwertsteuer)';
        if ($L['to_evendis_once'] > 0) $el[] = '+ einmalig (z. B. Einrichtung): ' . $e0($L['to_evendis_once']);
        $el[] = '= ' . $e0($L['to_evendis']) . ' an evendis im ersten Jahr – und 0 € je verkauftem Ticket.';
        $steps[] = ['title' => 'An evendis', 'text' => ($mode === 'own_shop' ? 'Dein Shop läuft auf unseren Servern, Betrieb und Updates sind inklusive.' : 'Dein Shop läuft auf deinem eigenen Server, Installation und Updates kommen per Paket.') . ' Alle evendis-Preise sind ohne Mehrwertsteuer – die bekommst du als Vorsteuer zurück.', 'lines' => $el];
        if ($mode === 'self_hosted') $steps[] = ['title' => 'Dein Server', 'text' => 'Dein Server beim Hoster kostet ca. ' . $e2($pc['server_month']) . ' im Monat = ' . $e0($L['to_hoster']) . ' im Jahr. Den zahlst du direkt an deinen Hoster. Dafür liegen deine Daten allein bei dir.', 'lines' => []];
        $calc = $e0($L['revenue']) . ' + ' . $e0($L['own_fee']) . ' − ' . $e0($L['to_psp']) . ' − ' . $e0($L['to_evendis']) . ($mode === 'self_hosted' ? ' − ' . $e0($L['to_hoster']) : '') . ' = ' . $e0($L['kept']);
        $steps[] = ['title' => 'Was dir bleibt', 'text' => $calc . '. Das sind ' . $e2($L['kept_per_ticket']) . ' je verkauftem Ticket.', 'lines' => []];
        if (!$compare) return ['tickets' => $t, 'on_request' => false, 'headline' => 'Bei ' . $n($t) . ' Tickets im Jahr', 'facts' => $facts, 'note' => $note, 'steps' => $steps,
            'glossary' => 'Netto = ohne Mehrwertsteuer. Brutto = mit Mehrwertsteuer. Zahlungsanbieter = der Dienst, über den deine Gäste mit Karte oder PayPal bezahlen. Alle Angaben ohne Gewähr.'];
        $P = $L['platform'];
        $cmp = 'Auf der Ticketplattform evendis.de kostet jedes Ticket ' . $e2($P['fee_per_ticket_gross']) . ' Gebühr (2,9 % + 0,49 €; ohne Mehrwertsteuer ' . $e2($P['fee_per_ticket_net']) . '), die Zahlung ist dort schon enthalten. '
            . 'Trägst du die Gebühr selbst, bleiben dir dort ' . $e0($P['kept_you_pay']) . '. Mit deinem eigenen Shop bleiben dir ' . $e0($L['kept']) . ' – '
            . ($sv >= 0 ? 'also ' . $e0($sv) . ' mehr.' : 'die Plattform ist hier um ' . $e0(-$sv) . ' günstiger.');
        $sg = $L['savings_vs_platform_guests'];
        $steps[] = ['title' => 'Zum Vergleich: die Ticketplattform', 'text' => $cmp, 'lines' => ['Zahlen deine Gäste die Plattformgebühr, bleibt dir dort der volle Ticketumsatz von ' . $e0($P['kept_guests_pay']) . ' – im eigenen Shop ' . ($sg >= 0 ? $e0($sg) . ' mehr' : $e0(-$sg) . ' weniger') . '.']];
        return ['tickets' => $t, 'on_request' => false, 'headline' => 'Bei ' . $n($t) . ' Tickets im Jahr', 'facts' => $facts, 'note' => $note, 'steps' => $steps,
            'glossary' => 'Netto = ohne Mehrwertsteuer. Brutto = mit Mehrwertsteuer. Zahlungsanbieter = der Dienst, über den deine Gäste mit Karte oder PayPal bezahlen. Alle Angaben ohne Gewähr.'];
    }

    // Farben des Angebots-PDFs im evendis-Stil (1.38.823): dunkle Schrift, viel Weiß, Rot sparsam als Akzent
    const K_INK = [0.122, 0.141, 0.188];    // #1F2430
    const K_MUTED = [0.420, 0.439, 0.502];  // #6B7080
    const K_LINE = [0.894, 0.902, 0.922];   // #E4E6EB
    const K_SOFT = [0.965, 0.969, 0.976];   // #F6F7F9
    const K_RED = [0.910, 0.267, 0.353];    // #E8445A
    const K_TINT = [0.992, 0.945, 0.953];   // #FDF1F3

    /**
     * PDF „Dein eigener Ticketshop“ (1.38.823 neu gesetzt, User 14.11.: „voll im evendis Stil, aber professionell sauber und schick“):
     * Kopf mit Wortmarke, Faktenblock (Grundpaket, je Ticket), So kommen die Zahlen zustande, Grundpaket, Optional dazubuchbar,
     * Zahlungsanbieter, Gast, Planrechnung, Referenzen, Vorteile, Bedingungen. Plattform-Vergleich nur mit compare_platform.
     * Text wird nach Breite umbrochen (nie abgeschnitten); Kopf auf Folgeseiten, Fuß mit Kontakt, Gültigkeit und Seitenzahl.
     */
    public static function render_pdf_own(array $d, array $meta) {
        $W = 595; $H = 842; $M = 50; $CW = $W - 2 * $M; $BOTTOM = 64;
        $pdfa = class_exists('TIX_PDFA') && TIX_PDFA::available();
        if (!$pdfa && !class_exists('TIX_Simple_PDF')) return null;
        self::$euro = $pdfa ? '€' : 'EUR';
        $B = self::own_blocks($d);
        if (!$B) return null;
        $pdf = $pdfa ? new TIX_PDFA($W, $H, $M, 40) : new TIX_Simple_PDF($W, $H, $M, 40);
        $brand = 'evendis'; // Angebote verschickt immer evendis (nie „Tixomat“)
        $nr = trim((string) ($meta['number'] ?? ''));
        if ($pdfa) $pdf->set_meta(['title' => trim('Angebot ' . $nr) . ' – ' . $B['title'], 'author' => $brand]);
        $cmp = !empty($B['compare_platform']);
        $g = function ($k) { return function_exists('tix_get_settings') ? trim((string) tix_get_settings($k)) : ''; };
        $date = !empty($meta['date']) ? wp_date('d.m.Y', strtotime($meta['date'])) : wp_date('d.m.Y');
        $valid = wp_date('d.m.Y', strtotime($d['valid_until']));
        $cust = $d['customer'];
        $who = $cust['company'] ?: $cust['name'];
        $ct = $d['contact'];
        $t = $B['totals'];
        $xR = $W - $M;
        $pdf->set_footer(implode(' · ', array_filter([$brand, $nr !== '' ? 'Angebot ' . $nr : 'Angebot', 'gültig bis ' . $valid, $ct['email'] ?: '', $ct['phone'] ?: ''])) . ' · Seite {p}/{n}');

        // ── Zeichen-Helfer
        $cur = ['bold' => false, 'size' => 10];
        $col = function ($c) use ($pdf) { $pdf->set_color($c[0], $c[1], $c[2]); };
        $font = function ($bold, $size) use ($pdf, &$cur) { $cur = ['bold' => $bold, 'size' => $size]; $pdf->set_font($bold ? 'Helvetica-Bold' : 'Helvetica', $size); };
        $fix = function ($s) { return str_replace(['−', '≈'], ['–', 'ca.'], (string) $s); };
        $wd = function ($s, $size = null, $bold = null) use ($pdf, $pdfa, &$cur, $fix) {
            $size = $size ?? $cur['size']; $bold = $bold ?? $cur['bold'];
            return $pdfa ? $pdf->width($fix($s), $size, $bold ? 'Helvetica-Bold' : 'Helvetica') : mb_strlen($s) * $size * 0.5;
        };
        $tx = function ($x, $y, $s) use ($pdf, $fix) { $pdf->text(round($x, 2), round($y, 2), $fix($s)); };
        $rt = function ($x, $y, $s) use ($tx, $wd) { $tx($x - $wd($s), $y, $s); };
        // Umbruch nach Breite (aktuelle Schrift); überlange Wörter werden hart geteilt, nie abgeschnitten
        $wrap = function ($text, $maxw) use ($wd) {
            $out = [];
            foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $para) {
                $line = '';
                foreach (preg_split('/\s+/u', trim($para)) as $w) {
                    if ($w === '') continue;
                    while ($wd($w) > $maxw && mb_strlen($w) > 1) {
                        $k = mb_strlen($w); while ($k > 1 && $wd(mb_substr($w, 0, $k)) > $maxw) $k--;
                        if ($line !== '') { $out[] = $line; $line = ''; }
                        $out[] = mb_substr($w, 0, $k); $w = mb_substr($w, $k);
                    }
                    $try = $line === '' ? $w : "$line $w";
                    if ($line !== '' && $wd($try) > $maxw) { $out[] = $line; $line = $w; } else $line = $try;
                }
                $out[] = $line;
            }
            while ($out && end($out) === '') array_pop($out);
            return $out;
        };
        // Schriftgröße so wählen, dass ein Wert in die Breite passt
        $fit = function ($s, $bold, $size, $maxw, $min = 9) use ($wd) { while ($size > $min && $wd($s, $size, $bold) > $maxw) $size -= 0.5; return $size; };
        $eu = function ($v) { return self::eur($v); };
        $eu0 = function ($v) { $v = round(floatval($v)); return ($v < 0 ? '– ' : '') . number_format(abs($v), 0, ',', '.') . ' ' . self::$euro; };
        $num = function ($v) { return number_format(floatval($v), 0, ',', '.'); };

        // ── Seiten: Folgeseiten mit schmalem Kopf
        $y = 0;
        // Original-Logo (1.38.824, User: „das Logo ist nicht original“): helle Variante, auf das Kopf-Rot gelegt (das PDF trägt ein Bild je Datei)
        $logo = false;
        $lp = (defined('TIXOMAT_PATH') ? TIXOMAT_PATH : dirname(__DIR__) . '/') . 'assets/img/evendis-logo-hell.png';
        if (function_exists('imagecreatefrompng') && is_readable($lp) && ($src = @imagecreatefrompng($lp))) {
            $lw0 = imagesx($src); $lh0 = imagesy($src);
            $bgi = imagecreatetruecolor($lw0, $lh0);
            imagefill($bgi, 0, 0, imagecolorallocate($bgi, (int) round(self::K_RED[0] * 255), (int) round(self::K_RED[1] * 255), (int) round(self::K_RED[2] * 255)));
            imagealphablending($bgi, true); imagecopy($bgi, $src, 0, 0, 0, 0, $lw0, $lh0);
            $tmp = tempnam(function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir(), 'evl');
            if ($tmp && @imagejpeg($bgi, $tmp, 95)) $logo = (bool) $pdf->load_logo($tmp);
            if ($tmp) @unlink($tmp);
            imagedestroy($src); imagedestroy($bgi);
            if ($logo) $logo = $lh0 / max(1, $lw0); // Seitenverhältnis
        }
        $mark = function ($x, $y, $h) use ($pdf, $logo, $col, $font, $tx) { // Logo, Grundlinie unten bei $y
            if ($logo) { $w = $h / $logo; $pdf->draw_logo($x, $y, $w, $h); return $w; }
            $col([1, 1, 1]); $font(true, $h * 0.9); $tx($x, $y + $h * 0.15, 'evendis'); return 0;
        };
        $newpage = function ($first = false) use (&$y, $pdf, $col, $font, $tx, $rt, $mark, $M, $W, $H, $xR, $nr, $who) {
            $pdf->add_page();
            if ($first) { $y = $H; return; }
            $col(self::K_RED); $pdf->rect(0, $H - 46, $W, 46, true);
            $mark($M, $H - 31, 17);
            $col([1, 1, 1]); $font(false, 8); $rt($xR, $H - 26, trim(($nr !== '' ? 'Angebot ' . $nr : 'Angebot') . ($who !== '' ? ' · ' . $who : '')));
            $y = $H - 78;
        };
        $ensure = function ($need) use (&$y, $newpage, $BOTTOM) { if ($y - $need < $BOTTOM) $newpage(); };
        $para = function ($text, $size = 9.5, $color = null, $x = null, $maxw = null, $lh = null) use (&$y, $col, $font, $tx, $wrap, $ensure, $M, $CW) {
            $x = $x ?? $M; $maxw = $maxw ?? $CW; $lh = $lh ?? round($size * 1.42, 1);
            $font(false, $size); $col($color ?? self::K_INK);
            foreach ($wrap($text, $maxw) as $ln) { $ensure($lh); $font(false, $size); $col($color ?? self::K_INK); $tx($x, $y, $ln); $y -= $lh; }
        };
        $section = function ($title, $sub = '', $keep = 90) use (&$y, $col, $font, $tx, $ensure, $pdf, $M, $para) {
            $y -= 20;
            $ensure($keep); // Überschrift nie allein am Seitenende
            $col(self::K_INK); $font(true, 15); $tx($M, $y, $title);
            $col(self::K_RED); $pdf->rect($M, $y - 8, 26, 2.2, true);
            $y -= 26;
            if ($sub !== '') { $para($sub, 9, self::K_MUTED); $y -= 4; }
        };
        $hair = function ($x1 = null, $x2 = null, $c = null) use (&$y, $pdf, $col, $M, $xR) { $x1 = $x1 ?? $M; $x2 = $x2 ?? $xR; $col($c ?? self::K_LINE); $pdf->rect($x1, $y, $x2 - $x1, 0.5, true); };
        $thead = function (array $cols) use (&$y, $pdf, $col, $font, $tx, $rt, $M, $CW) {
            $col(self::K_SOFT); $pdf->rect($M, $y - 7, $CW, 20, true);
            $col(self::K_MUTED); $font(true, 7);
            foreach ($cols as [$x, $txt, $right]) { if ($right) $rt($x, $y, mb_strtoupper($txt)); else $tx($x, $y, mb_strtoupper($txt)); }
            $y -= 24;
        };

        // ═══ Seite 1: Kopf auf evendis-Rot (1.38.824, User: „es soll mehr rot sein … oben auf der ersten Seite“)
        $newpage(true);
        $WHITE = [1, 1, 1]; $SOFTW = [1, 0.87, 0.89]; $DEEP = [0.80, 0.21, 0.30];
        $font(true, 26); $tl = $wrap($B['title'], $CW);
        $font(false, 13); $wl = $who !== '' ? $wrap('Angebot für ' . $who, $CW) : [];
        $font(true, 7.5); $lab = mb_strtoupper($B['mode_label']); $lw = $wd($lab) + 14;
        $font(false, 9); $sl = array_slice($wrap($B['subtitle'], $CW - $lw - 10), 0, 2);
        $hh = 128 + 31 * count($tl) + 17 * count($wl) + 12 * count($sl) + 22;
        $col(self::K_RED); $pdf->rect(0, $H - $hh, $W, $hh, true);
        $mark($M, $H - 70, 30);
        $col($SOFTW); $font(true, 7.5); $rt($xR, $H - 46, 'ANGEBOT');
        $col($WHITE); $font(true, 11); if ($nr !== '') $rt($xR, $H - 60, 'Nr. ' . $nr);
        $col($SOFTW); $font(false, 8.5); $rt($xR, $H - ($nr !== '' ? 73 : 60), 'vom ' . $date . ' · gültig bis ' . $valid);
        $y = $H - 128;
        $col($WHITE); $font(true, 26); foreach ($tl as $ln) { $tx($M, $y, $ln); $y -= 31; }
        $y += 9;
        $col($WHITE); $font(false, 13); foreach ($wl as $ln) { $tx($M, $y, $ln); $y -= 17; }
        $y -= 10;
        $col($DEEP); $pdf->rect($M, $y - 5, $lw, 16, true);
        $col($WHITE); $font(true, 7.5); $tx($M + 7, $y, $lab);
        $col($SOFTW); $font(false, 9); foreach ($sl as $k => $ln) $tx($M + $lw + 10, $y - 12 * $k, $ln);
        $y = $H - $hh - 34;
        // Für / Dein Kontakt
        $rl = array_values(array_filter([$cust['company'], $cust['company'] !== '' ? $cust['name'] : '', $cust['company'] === '' ? $cust['name'] : '', $cust['street'], trim($cust['zip'] . ' ' . $cust['city']), $cust['country'] !== 'DE' ? $cust['country'] : '', $cust['vat_id'] ? 'USt-IdNr. ' . $cust['vat_id'] : '']));
        $rl = array_values(array_unique($rl));
        $cl = array_values(array_filter([$ct['name'], $ct['email'], $ct['phone']]));
        $cx2 = $M + $CW / 2 + 10;
        $col(self::K_MUTED); $font(true, 7.5);
        if ($rl) $tx($M, $y, 'FÜR');
        if ($cl) $tx($rl ? $cx2 : $M, $y, 'DEIN KONTAKT BEI EVENDIS');
        $y -= 15;
        $yy = $y;
        foreach ($rl as $i => $ln) { $col(self::K_INK); $font($i === 0, 10); foreach ($wrap($ln, $CW / 2 - 20) as $wl) { $tx($M, $yy, $wl); $yy -= 13.5; } }
        $yc = $y;
        foreach ($cl as $i => $ln) { $col(self::K_INK); $font($i === 0, 10); foreach ($wrap($ln, $CW / 2 - 20) as $wl) { $tx($rl ? $cx2 : $M, $yc, $wl); $yc -= 13.5; } }
        $y = min($yy, $yc) - 18;

        // ═══ Faktenblock (Grundpaket, führende Stufe)
        $LD = $B['lead'];
        if ($LD && empty($LD['on_request'])) {
            $nf = count($LD['facts']);
            $pad = 18;
            $fw = ($CW - 2 * $pad) / max(1, $nf);
            $font(false, 9); $notes = $wrap($LD['note'], $CW - 2 * $pad);
            $font(true, 7); $ll_max = max(array_map(function ($f) use ($wrap, $fw) { return count($wrap(mb_strtoupper($f['label']), $fw - 14)); }, $LD['facts']));
            $lx = 9 * max(0, $ll_max - 1); // zweizeilige Etiketten schieben die Werte nach unten
            $bh = 112 + $lx + 12 * count($notes);
            $ensure($bh + (!empty($B['fixed_price']) ? 80 : 10));
            $top = $y + 6;
            $col(self::K_SOFT); $pdf->rect($M, $top - $bh, $CW, $bh, true);
            $col(self::K_RED); $pdf->rect($M, $top - $bh, 3, $bh, true);
            $col(self::K_INK); $font(true, 14); $tx($M + $pad, $top - 26, $LD['headline'] . ' – das zahlst du, das bleibt dir');
            $yv = $top - 52;
            foreach ($LD['facts'] as $i => $f) {
                $x = $M + $pad + $i * $fw;
                if ($i > 0) { $col(self::K_LINE); $pdf->rect($x - 10, $yv - 34 - $lx, 0.6, 46 + $lx, true); }
                $col(self::K_MUTED); $font(true, 7);
                foreach ($wrap(mb_strtoupper($f['label']), $fw - 14) as $k => $ln) $tx($x, $yv + 4 - 9 * $k, $ln);
                $sz = $fit($f['value'], true, $f['strong'] ? 22 : 17, $fw - 14);
                $col($f['strong'] ? self::K_RED : self::K_INK); $font(true, $sz); $tx($x, $yv - 22 - $lx, $f['value']);
                if (!empty($f['per_ticket'])) { $col(self::K_MUTED); $font(false, 8); $tx($x, $yv - 36 - $lx, $f['per_ticket']); }
            }
            $col(self::K_INK); $font(false, 9);
            $yn = $top - 108 - $lx; foreach ($notes as $ln) { $tx($M + $pad, $yn, $ln); $yn -= 12; }
            $y = $top - $bh;
            // Festpreis direkt unter dem Faktenblock (1.38.824)
            if (!empty($B['fixed_price'])) {
                $fp = $B['fixed_price'];
                $font(true, 10.5); $fl = $wrap($fp['title'] . ': ' . $fp['text'], $CW - 2 * $pad);
                $font(false, 8.5); $fs2 = $wrap($fp['tiers'], $CW - 2 * $pad);
                $fh = 22 + 14 * count($fl) + 11.5 * count($fs2);
                $col(self::K_TINT); $pdf->rect($M, $y - $fh, $CW, $fh, true);
                $col(self::K_RED); $pdf->rect($M, $y - $fh, 3, $fh, true);
                $yf = $y - 18;
                $col(self::K_INK); $font(true, 10.5); foreach ($fl as $ln) { $tx($M + $pad, $yf, $ln); $yf -= 14; }
                $col(self::K_MUTED); $font(false, 8.5); foreach ($fs2 as $ln) { $tx($M + $pad, $yf, $ln); $yf -= 11.5; }
                $y -= $fh;
            }
            $y -= 22;
        } elseif ($LD && !empty($LD['on_request'])) {
            $ensure(60);
            $col(self::K_SOFT); $pdf->rect($M, $y - 34, $CW, 46, true);
            $col(self::K_RED); $pdf->rect($M, $y - 34, 3, 46, true);
            $col(self::K_INK); $font(true, 13); $tx($M + 18, $y - 6, $LD['headline']);
            $col(self::K_MUTED); $font(false, 9.5); $tx($M + 18, $y - 22, $LD['note']);
            $y -= 60;
        } else {
            $tw = ($CW - 20) / 3;
            $ensure(90);
            foreach ($B['tiles'] as $i => $tl) {
                $x = $M + $i * ($tw + 10);
                $col(self::K_SOFT); $pdf->rect($x, $y - 66, $tw, 74, true);
                $col(self::K_MUTED); $font(true, 7); $tx($x + 10, $y - 6, mb_strtoupper($tl['title']));
                $col($i === 2 ? self::K_RED : self::K_INK); $font(true, $fit($tl['value'], true, 13, $tw - 20)); $tx($x + 10, $y - 26, $tl['value']);
                $col(self::K_MUTED); $font(false, 7.5); $yy = $y - 40; foreach (array_slice($wrap($tl['note'], $tw - 20), 0, 3) as $ln) { $tx($x + 10, $yy, $ln); $yy -= 9; }
            }
            $y -= 90;
        }

        // ═══ Anrede
        if (trim((string) $d['intro']) !== '') {
            $ensure(40);
            $col(self::K_INK); $font(false, 10.5);
            $tx($M, $y, $cust['name'] !== '' ? 'Hallo ' . strtok($cust['name'], ' ') . ',' : 'Hallo,'); $y -= 17;
            $para($d['intro'], 10, self::K_INK, null, null, 14.5);
            $y -= 6;
        }

        // ═══ So kommen die Zahlen zustande
        if ($LD && empty($LD['on_request']) && $LD['steps']) {
            $section('So kommen die Zahlen zustande', $LD['headline'] . ', nur Grundpaket – Schritt für Schritt.');
            foreach ($LD['steps'] as $k => $st) {
                $font(false, 9.5); $tl = $wrap($st['text'], $CW - 28);
                $font(false, 9); $bl = []; foreach ($st['lines'] as $l) $bl[] = $wrap($l, $CW - 40);
                $ensure(18 + 13 * count($tl) + 12 * count($bl, COUNT_RECURSIVE));
                $col(self::K_TINT); $pdf->rect($M, $y - 4, 16, 16, true);
                $col(self::K_RED); $font(true, 9); $s = (string) ($k + 1); $tx($M + 8 - $wd($s) / 2, $y, $s);
                $col(self::K_INK); $font(true, 10.5); $tx($M + 28, $y, $st['title']); $y -= 15;
                $font(false, 9.5); foreach ($tl as $ln) { $tx($M + 28, $y, $ln); $y -= 13; }
                foreach ($bl as $lines) foreach ($lines as $j => $ln) {
                    if ($j === 0) { $col(self::K_RED); $pdf->rect($M + 30, $y + 2.5, 3, 3, true); }
                    $col(self::K_INK); $font(false, 9); $tx($M + 40, $y, $ln); $y -= 12;
                }
                $y -= 8;
            }
            $col(self::K_MUTED); $para($LD['glossary'], 8, self::K_MUTED);
        }

        // ═══ Tabellen-Zeilen (Grundpaket, Optionen)
        $xP = $xR - 8; $nameW = $CW - 170;
        $item_rows = function (array $rows) use (&$y, $pdf, $col, $font, $tx, $rt, $wd, $wrap, $ensure, $hair, $B, $M, $xP, $nameW) {
            foreach ($rows as $r) {
                $font(true, 10); $nl = $wrap($r['name'], $nameW - (!empty($r['optional']) ? 52 : 0));
                $font(false, 8.5); $det = $r['detail'] !== '' ? $wrap($r['detail'], $nameW) : [];
                $tier = ($r['tiered'] && $B['block1']['tier_line']) ? $wrap($B['block1']['tier_line'], $nameW) : [];
                $ensure(14 * count($nl) + 11 * (count($det) + count($tier)) + 14);
                $info = !empty($r['info']);
                $y0 = $y;
                foreach ($nl as $k => $ln) {
                    $col($info ? self::K_MUTED : self::K_INK); $font(!$info, 10); $tx($M + 8, $y, $ln);
                    if ($k === count($nl) - 1 && !empty($r['optional'])) {
                        $x = $M + 8 + $wd($ln, 10, !$info) + 8; $font(true, 6.5); $lb = 'OPTIONAL'; $pw = $wd($lb) + 10;
                        $col(self::K_MUTED); $pdf->rect($x, $y - 2.5, $pw, 11, false); $tx($x + 5, $y + 0.5, $lb);
                    }
                    $y -= 14;
                }
                $col($info ? self::K_MUTED : self::K_INK); $font(!$info, 10); $rt($xP, $y0, $r['price']);
                if ($r['list_price']) { $col(self::K_MUTED); $font(false, 8); $rt($xP, $y0 - 12, 'statt ' . $r['list_price']); }
                $col(self::K_MUTED); $font(false, 8.5); foreach ($det as $dl) { $tx($M + 8, $y + 2, $dl); $y -= 11; }
                $col(self::K_RED); $font(false, 8); foreach ($tier as $tl2) { $tx($M + 8, $y + 2, $tl2); $y -= 11; }
                if ($r['list_price'] && $y > $y0 - 26) $y = $y0 - 26;
                $y -= 2; $hair(); $y -= 14;
            }
        };
        $rate = rtrim(rtrim(number_format($t['tax_rate'], 1, ',', ''), '0'), ',');
        $xL = $M + $CW * 0.45;
        $sumline = function ($label, $amount, $style = '') use (&$y, $pdf, $col, $font, $tx, $rt, $ensure, $xL, $xR, $xP) {
            $ensure(18);
            if ($style === 'total') { $col(self::K_TINT); $pdf->rect($xL - 8, $y - 6, $xR - $xL + 8, 20, true); }
            $col($style === 'muted' ? self::K_MUTED : ($style === 'total' ? self::K_INK : self::K_INK)); $font($style === 'total', $style === 'total' ? 10.5 : 9.5);
            $tx($xL, $y, $label); $rt($xP, $y, $amount);
            $y -= $style === 'total' ? 22 : 15;
        };
        $sums = function (array $p, $label) use ($sumline, $eu, $rate, $d) {
            $dl = (string) ($d['discount']['label'] ?? '') ?: 'Rabatt';
            if ($p['yearly']['net'] > 0) {
                $sumline($label . ' im Jahr, netto', $eu($p['yearly']['net']));
                if ($p['yearly']['discount'] > 0) $sumline($dl, '– ' . $eu($p['yearly']['discount']));
                $sumline('zzgl. ' . $rate . ' % MwSt.', $eu($p['yearly']['tax']), 'muted');
                $sumline($label . ' im Jahr, brutto', $eu($p['yearly']['gross']), 'total');
            }
            if ($p['once']['net'] > 0) {
                $sumline('Einmalig, netto', $eu($p['once']['net']));
                if ($p['once']['discount'] > 0) $sumline($dl, '– ' . $eu($p['once']['discount']));
                $sumline('Einmalig, brutto', $eu($p['once']['gross']), 'total');
            }
        };

        // ═══ Grundpaket
        $section('Grundpaket', 'Das zahlst du an evendis – 0 € je verkauftem Ticket. Alle Preise zzgl. MwSt.');
        $thead([[$M + 8, 'Leistung', false], [$xP, 'Preis', true]]);
        $item_rows($B['block1']['base_rows']);
        $y -= 2;
        $sums($t['base'] ?? $t, 'Grundpaket');
        if (!empty($d['term_months'])) $sumline('Laufzeit', $d['term_months'] . ' Monate', 'muted');

        // ═══ Optional dazubuchbar
        if (!empty($t['has_options']) && $B['block1']['option_rows']) {
            $section('Optional dazubuchbar', 'Nicht im Grundpaket enthalten – nur, wenn du es dazunimmst. Alle Preise zzgl. MwSt.');
            $thead([[$M + 8, 'Option', false], [$xP, 'Preis', true]]);
            $item_rows($B['block1']['option_rows']);
            $y -= 2;
            $op = $t['options'];
            if ($op['yearly_net'] > 0) $sumline('Optionen im Jahr, netto', '+ ' . $eu($op['yearly_net']));
            if ($op['once_net'] > 0) $sumline('Optionen einmalig, netto', '+ ' . $eu($op['once_net']));
            $y -= 4;
            $sums($t['with_options'], 'Mit gewählten Optionen');
        }

        // ═══ Rundum-Einrichtung
        if ($B['setup']) {
            $su = $B['setup'];
            $cw2 = ($CW - 24) / 2;
            $font(false, 9); $lists = [];
            foreach ([[$su['steps'], 'DAS MACHEN WIR'], [$su['customer_tasks'], 'DAS BLEIBT BEI DIR']] as [$list, $ttl]) { $w = []; foreach ($list as $ln) $w[] = $wrap($ln, $cw2 - 14); $lists[] = [$w, $ttl]; }
            $need = 20 + max(array_map(function ($l) { $n = 0; foreach ($l[0] as $x) $n += 11.5 * count($x) + 3; return $n; }, $lists));
            $section($su['name'], '', min(420, $need + 90)); // Überschrift, Kernsatz und Listen zusammen
            if ($su['headline'] !== '') {
                $font(true, 10.5); $hl = $wrap($su['headline'], $CW - 28);
                $ensure(14 * count($hl) + 20);
                $col(self::K_TINT); $pdf->rect($M, $y - 14 * count($hl) + 4, $CW, 14 * count($hl) + 12, true);
                $col(self::K_INK); foreach ($hl as $ln) { $tx($M + 14, $y, $ln); $y -= 14; }
                $y -= 14;
            }
            $ensure(min($need, 400));
            $y0 = $y; $ylow = [];
            foreach ($lists as $j => [$w, $ttl]) {
                $x = $M + $j * ($cw2 + 24); $yy = $y0;
                $col(self::K_MUTED); $font(true, 7.5); $tx($x, $yy, $ttl); $yy -= 15;
                foreach ($w as $lines) {
                    $col($j === 0 ? self::K_RED : self::K_MUTED); $pdf->rect($x, $yy + 2.5, 4, 4, true);
                    $col(self::K_INK); $font(false, 9); foreach ($lines as $wl) { $tx($x + 12, $yy, $wl); $yy -= 11.5; }
                    $yy -= 3;
                }
                $ylow[] = $yy;
            }
            $y = min($ylow) - 6;
        }

        // ═══ Zahlungsanbieter
        $section('An deinen Zahlungsanbieter', $B['block2']['note']);
        $thead([[$M + 8, 'Zahlart', false], [$M + 190, 'Gebühr je Zahlung', false], [$M + 310, 'Quelle (Stand)', false]]);
        foreach ($B['block2']['rates'] as $r) {
            $font(false, 7.5); $src = $wrap($r['source'] . ($r['as_of'] ? ' (Stand ' . $r['as_of'] . ')' : ''), $CW - 318);
            $font(true, 9.5); $lab = $wrap($r['label'] . ($r['provider'] && stripos($r['label'], $r['provider']) === false ? ' (' . $r['provider'] . ')' : ''), 175);
            $hgt = max(12 * count($lab), 9.5 * count($src));
            $ensure($hgt + 14);
            $y0 = $y;
            $col(self::K_INK); $font(true, 9.5); foreach ($lab as $k => $ln) $tx($M + 8, $y0 - 12 * $k, $ln);
            $font(false, 9.5); $tx($M + 190, $y0, $r['fee']);
            $col(self::K_MUTED); $font(false, 7.5); foreach ($src as $k => $ln) $tx($M + 310, $y0 - 9.5 * $k, $ln);
            $y = $y0 - $hgt - 2; $hair(); $y -= 14;
        }
        $y -= 2;
        $para($B['block2']['mix'], 8.5, self::K_MUTED);
        $para($B['block2']['providers_note'], 8.5, self::K_MUTED);

        // ═══ Was dein Gast zahlt
        $gb = $B['block3'];
        $section('Was dein Gast zahlt', 'In deinem Shop legst du deine Service- bzw. VVK-Gebühr selbst fest. Sie gehört dir.');
        $x1 = $cmp ? $xR - 140 : $xP;
        $thead(array_merge([[$M + 8, 'Je Ticket', false], [$x1, 'Dein Shop', true]], $cmp ? [[$xP, 'Plattform evendis.de', true]] : []));
        $grows = [['Ticketpreis', $eu($gb['ticket_price']), $cmp ? $eu($gb['ticket_price']) : ''],
                  ['Service-/VVK-Gebühr', $eu($gb['own_fee']) . ' (an dich)', $cmp ? $eu($gb['platform_fee']) . ' (Plattform)' : ''],
                  ['Gast zahlt', $eu($gb['pays_own_shop']), $cmp ? $eu($gb['pays_platform']) : '']];
        foreach ($grows as $j => $r) {
            $ensure(18);
            $col(self::K_INK); $font($j === 2, 9.5); $tx($M + 8, $y, $r[0]); $rt($x1, $y, $r[1]); if ($cmp) $rt($xP, $y, $r[2]);
            $y -= 6; $hair(); $y -= 13;
        }

        // ═══ Planrechnung
        $b4 = $B['block4'];
        $rows = $b4['rows'];
        $tt = $b4['totals'];
        $n = count($rows);
        $lw = 196;
        $cwv = ($CW - $lw) / max(1, $n);
        $fs = $n > 5 ? 7.5 : 8.5;
        $cx = function ($k) use ($M, $lw, $cwv) { return $M + $lw + ($k + 1) * $cwv - 6; };
        $lead_t = intval($B['plan']['lead']['tickets'] ?? 0);
        $section('Planrechnung', 'Was im Jahr insgesamt übrig bleibt – je nach Ticketzahl. Die markierte Spalte (' . $num($lead_t) . ' Tickets) gehört zum Faktenblock'
            . (!empty($t['has_options']) ? '; hier sind die gewählten Optionen mit eingerechnet.' : '.'));
        $y -= 6;
        $vhead = function ($list) use (&$y, $pdf, $col, $font, $tx, $rt, $cx, $M, $CW, $num, $lead_t, $cwv, $lw) {
            $col(self::K_SOFT); $pdf->rect($M, $y - 7, $CW, 20, true);
            foreach ($list as $k => $r) if ($r['tickets'] === $lead_t) { $col(self::K_TINT); $pdf->rect($M + $lw + $k * $cwv, $y - 7, $cwv, 20, true); }
            $col(self::K_MUTED); $font(true, 7); $tx($M + 8, $y, 'TICKETS IM JAHR');
            foreach ($list as $k => $r) { $col($r['tickets'] === $lead_t ? self::K_RED : self::K_MUTED); $rt($cx($k), $y, $num($r['tickets']) . ($r['custom'] ? '*' : '')); }
            $y -= 22;
        };
        $vrow = function ($label, array $vals, $style = '') use (&$y, $pdf, $col, $font, $tx, $rt, $cx, $M, $CW, $fs, $ensure, $hair, $wrap) {
            $font(in_array($style, ['hl', 'grp'], true), $style === 'sub' ? $fs - 0.5 : $fs + 0.5);
            $ll = $wrap($label, 186);
            $ensure(10 + 11 * count($ll) + 6);
            $h = 11 * (count($ll) - 1);
            if ($style === 'hl') { $col(self::K_TINT); $pdf->rect($M, $y - 6 - $h, $CW, 17 + $h, true); }
            if ($style === 'grp') { $col(self::K_SOFT); $pdf->rect($M, $y - 6 - $h, $CW, 17 + $h, true); }
            $col($style === 'sub' ? self::K_MUTED : self::K_INK);
            foreach ($ll as $k => $ln) { $font(in_array($style, ['hl', 'grp'], true), $style === 'sub' ? $fs - 0.5 : $fs + 0.5); $tx($M + 8, $y - 11 * $k, $ln); }
            $font($style === 'hl', $style === 'sub' ? $fs - 0.5 : $fs);
            foreach ($vals as $k => $v) $rt($cx($k), $y, $v);
            $y -= 6 + $h; if ($style !== 'grp') $hair(); $y -= 10;
        };
        $pick = function ($m, $key, $fmt = 'eur', $sign = '') use ($tt, $eu0) {
            return array_map(function ($r) use ($m, $key, $fmt, $sign, $eu0) { $x = $r['modes'][$m] ?? null; if (!$x || !empty($x['on_request'])) return 'auf Anfrage'; $v = $x[$key];
                if ($v === null) return '–'; if ($fmt === 'pt') return number_format($v, 2, ',', '.') . ' ' . self::$euro; if ($fmt === 'pc') return number_format($v, 1, ',', '.') . ' %';
                return ($sign === '-' && $v != 0 ? '– ' : ($sign === '+' ? '+ ' : '')) . $eu0($v); }, $tt);
        };
        $ensure(40 + 18 * 10);
        $col(self::K_INK); $font(true, 11); $tx($M, $y, 'Ergebnis im Überblick'); $y -= 18;
        $vhead($tt);
        $vrow('Ticketumsatz gesamt (brutto)', $pick('own_shop', 'revenue'));
        $labels = ['own_shop' => self::MODE_TEXTS['own_shop']['label'], 'self_hosted' => self::MODE_TEXTS['self_hosted']['label'], 'platform' => 'Plattform evendis.de (du trägst die Gebühr)'];
        foreach ($b4['totals_order'] as $j => $m) {
            $vrow($labels[$m] . ($j === 0 ? ' – dieses Angebot' : ' – zum Vergleich'), array_fill(0, count($tt), ''), 'grp');
            if ($m !== 'platform') $vrow('+ eigene Servicegebühr', $pick($m, 'own_fee', 'eur', '+'));
            $vrow('– an evendis' . ($m === 'platform' ? ' (Plattformgebühr, netto)' : ' (netto)'), $pick($m, 'to_evendis', 'eur', '-'));
            if ($m !== 'platform') $vrow('– an den Zahlungsanbieter', $pick($m, 'to_psp', 'eur', '-'));
            if ($m === 'self_hosted') $vrow('– an deinen Hoster (Server, ca.)', $pick($m, 'to_hoster', 'eur', '-'));
            $vrow('= bleibt dir', $pick($m, 'kept'), 'hl');
            $vrow('je Ticket', $pick($m, 'kept_per_ticket', 'pt'), 'sub');
        }
        $para($b4['totals_note'] . (!empty($t['has_options']) ? ' An evendis inkl. der gewählten Optionen.' : '') . ($cmp ? ' Plattform, wenn Gäste die Gebühr zahlen: dir bleibt der volle Ticketumsatz.' : ''), 7.5, self::K_MUTED);
        $y -= 16;
        $ensure(40 + 18 * 9);
        $col(self::K_INK); $font(true, 11); $tx($M, $y, 'Rechnung im Detail (' . $B['mode_label'] . ', je Jahr)'); $y -= 18;
        $vhead($rows);
        $prow = function ($label, $key, $sign = '', $style = '') use ($vrow, $rows, $eu0) {
            $vrow($label, array_map(function ($r) use ($key, $sign, $eu0) { $v = $r[$key] ?? null; return $v === null ? 'auf Anfrage' : ($sign === '-' && $v != 0 ? '– ' . $eu0($v) : ($sign === '+' ? '+ ' . $eu0($v) : $eu0($v))); }, $rows), $style);
        };
        $prow('Ticketumsatz (inkl. MwSt.)', 'revenue');
        $prow('+ eigene Service-/VVK-Gebühr', 'own_fee', '+');
        $prow('– an den Zahlungsanbieter', 'psp', '-');
        $prow('– an evendis (' . (($B['plan']['to_evendis']['base']['name'] ?? '') ?: 'Grundpaket') . ')', 'evendis', '-');
        if ($b4['options']) $prow('– an evendis (gewählte Optionen)', 'options', '-');
        if ($b4['channel']) $prow('– evendis.de-Gebühr (netto)', 'channel_fee', '-');
        if ($b4['server']) $prow('– an deinen Hoster (Server, ca.)', 'server', '-');
        $prow('= bleibt dir (' . $B['mode_label'] . ')', 'kept', '', 'hl');
        $prow('zum Vergleich: ' . $B['other_label'], 'other_kept', '', 'sub');
        if ($cmp) {
            $prow('zum Vergleich: Plattform, Gäste zahlen', 'platform_guests_pay', '', 'sub');
            $prow('zum Vergleich: Plattform, du trägst', 'platform_you_pay', '', 'sub');
            $prow('Vorteil ' . $B['mode_label'] . ' (Gäste zahlen)', 'advantage', '', 'hl');
        }
        if (array_filter(array_column($rows, 'custom'))) $para('* deine eigene Zahl', 7.5, self::K_MUTED);
        $y -= 2;
        $para($b4['basis'], 7.5, self::K_MUTED);
        if ($b4['reading']) {
            $font(false, 9); $rl2 = $wrap('So liest du die Rechnung: ' . $b4['reading'], $CW - 24);
            $ensure(13 * count($rl2) + 18); $y -= 4;
            $col(self::K_TINT); $pdf->rect($M, $y - 13 * count($rl2) + 6, $CW, 13 * count($rl2) + 10, true);
            $col(self::K_INK); foreach ($rl2 as $ln) { $tx($M + 12, $y, $ln); $y -= 13; }
            $y -= 8;
        }
        $para($b4['assumptions'], 7.5, self::K_MUTED);
        $para($b4['disclaimer'], 7.5, self::K_MUTED);

        // ═══ Hochrechnung je Event (vergleicht mit dem Provisionsmodell → nur mit compare_platform)
        $fc = $t['projection'];
        if ($cmp && $fc && !empty($d['projection']['in_pdf'])) {
            $in = $fc['input']; $pv = $fc['commission']; $sh = $fc['selfhosted']; $cm = $fc['comparison'];
            $ownl = self::mode_of($d) === 'self_hosted' ? 'Selbstgehostet' : 'Eigener Shop';
            $na = function ($v) use ($eu) { return $v === null ? 'Satz fehlt' : $eu($v); };
            $pc = function ($v) { return $v === null ? '–' : rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',') . ' %'; };
            $section('Hochrechnung je Event', $num($in['tickets']) . ' Tickets je Event · Ø ' . $eu($in['avg_price']) . ' · ' . $in['events_year'] . ' Events im Jahr · Umsatz je Event ' . $eu($fc['revenue_event']));
            $xa = $xR - 150;
            $thead([[$M + 8, '', false], [$xa, 'Provisionsmodell', true], [$xP, $ownl, true]]);
            foreach ([['An evendis je Event', $eu($pv['to_evendis_event']), $eu($sh['to_evendis_event'])], ['An den Zahlungsanbieter', $eu(0) . ' (inklusive)', $na($sh['to_psp_event'])],
                      ['Summe je Event', $eu($pv['total_event']), $na($sh['total_event']), true], ['Je Ticket', $pv['per_ticket'] === null ? '–' : $eu($pv['per_ticket']), $na($sh['per_ticket'])],
                      ['In % vom Umsatz', $pc($pv['percent_of_revenue']), $pc($sh['percent_of_revenue'])], ['Aufs Jahr', $eu($pv['per_year']), $na($sh['per_year'])]] as $r) {
                $ensure(18); $col(self::K_INK); $font(!empty($r[3]), 9.5); $tx($M + 8, $y, $r[0]); $rt($xa, $y, $r[1]); $rt($xP, $y, $r[2]); $y -= 6; $hair(); $y -= 13;
            }
            if ($cm['break_even_tickets_month']) $para($ownl . ' lohnt sich ab ' . $num($cm['break_even_tickets_month']) . ' Tickets im Monat.', 9, self::K_MUTED);
            if ($cm['cheaper']) $para($cm['cheaper'] === 'equal' ? 'Beide Modelle kosten bei dieser Prognose gleich viel.' : 'Bei dieser Prognose ist ' . ($cm['cheaper'] === 'selfhosted' ? $ownl : 'das Provisionsmodell') . ' um ' . $eu($cm['difference_event']) . ' je Event günstiger (' . $eu($cm['difference_year']) . ' im Jahr).', 9.5);
            $para(trim($fc['payment_note'] . ' ' . $fc['rates_note'] . ' ' . $fc['basis_note']), 7.5, self::K_MUTED);
        }

        // ═══ Referenzen
        if ($B['references']) {
            $section('So sieht es bei anderen aus');
            foreach ($B['references'] as $r) {
                $links = array_filter([$r['url'] ? preg_replace('#^https://(www\.)?#', '', rtrim($r['url'], '/')) : '', $r['app_store_url'] ? 'App Store' : '', $r['play_store_url'] ? 'Google Play' : '']);
                $font(false, 9); $rt2 = $r['text'] !== '' ? $wrap($r['text'], $CW - 14) : [];
                $ensure(30 + 12 * count($rt2));
                $col(self::K_RED); $pdf->rect($M, $y + 2.5, 4, 4, true);
                $col(self::K_INK); $font(true, 10); $tx($M + 14, $y, $r['name']);
                $col(self::K_MUTED); $font(false, 8); $rt($xR, $y, $r['mode_label']); $y -= 13;
                $col(self::K_INK); $font(false, 9); foreach ($rt2 as $ln) { $tx($M + 14, $y, $ln); $y -= 12; }
                if ($links) { $col(self::K_RED); $font(false, 8.5); $tx($M + 14, $y, implode(' · ', $links)); $y -= 12; }
                $y -= 8;
            }
        }

        // ═══ Vorteile (zweispaltig, umbrochen)
        $ben = $B['benefits'];
        if ($ben) {
            $section('Deine Vorteile', '', min(300, 70 + 18 * intval(ceil(count($ben) / 2)))); // Liste möglichst zusammen
            $colw = ($CW - 24) / 2;
            for ($i = 0; $i < count($ben); $i += 2) {
                $pair = array_slice($ben, $i, 2);
                $font(true, 9.5); $wl = array_map(function ($b) use ($wrap, $colw) { return $wrap($b['title'], $colw - 14); }, $pair);
                $hgt = 12 * max(array_map('count', $wl));
                $ensure($hgt + 8);
                foreach ($pair as $j => $b) {
                    $x = $M + $j * ($colw + 24);
                    $col(self::K_RED); $pdf->rect($x, $y + 2.5, 4, 4, true);
                    $col(self::K_INK); $font(true, 9.5); foreach ($wl[$j] as $k => $ln) $tx($x + 14, $y - 12 * $k, $ln);
                }
                $y -= $hgt + 6;
            }
        }

        // ═══ Bedingungen, Annahme, Kontakt
        $section('Bedingungen');
        $cond = trim((string) $d['conditions']);
        if ($cond !== '') $para($cond, 9, self::K_INK);
        $para('Dieses Angebot gilt bis ' . $valid . '.', 9, self::K_INK);
        $y -= 10;
        if (!empty($meta['link'])) {
            $font(false, 8); $ll = $wrap($meta['link'], $CW - 28);
            $bh = 44 + 11 * count($ll);
            $ensure($bh + 10);
            $col(self::K_TINT); $pdf->rect($M, $y - $bh + 14, $CW, $bh, true);
            $col(self::K_RED); $pdf->rect($M, $y - $bh + 14, 3, $bh, true);
            $col(self::K_INK); $font(true, 11); $tx($M + 14, $y - 2, 'Interesse bestätigen – unverbindlich');
            $col(self::K_MUTED); $font(false, 9); $tx($M + 14, $y - 17, 'Mit einem Klick unter diesem Link. Den Vertrag schicken wir dir danach separat.');
            $col(self::K_RED); $font(false, 8); foreach ($ll as $k => $ln) $tx($M + 14, $y - 31 - 11 * $k, $ln);
            $y -= $bh + 8;
        } else {
            $para('Interesse? Antworte einfach an ' . ($ct['email'] ?: 'uns') . ' – den Vertrag schicken wir dir danach separat.', 9.5);
            $y -= 6;
        }
        $ensure(40);
        $col(self::K_INK); $font(true, 10.5); $tx($M, $y, 'Lass uns sprechen'); $y -= 15;
        $para(implode(' · ', array_filter([$ct['name'], $ct['email'], $ct['phone']])), 10);
        // Anbieter (Pflichtangaben klein am Ende)
        $issuer = array_filter([$g('invoice_company_name') ?: 'evendis', $g('invoice_managing_director') ? 'Geschäftsführung: ' . $g('invoice_managing_director') : '',
            trim($g('invoice_register_court') . ' ' . $g('invoice_register_number')), $g('invoice_company_ust_id') ? 'USt-IdNr. ' . $g('invoice_company_ust_id') : '']);
        $issuer = array_map(function ($s) { return preg_replace('/tixomat/i', 'evendis', $s); }, $issuer);
        if (count($issuer) > 1) { $y -= 10; $para('Anbieter: ' . implode(' · ', $issuer), 7.5, self::K_MUTED); }
        return $pdf->output();
    }
}

TIX_Platform_Offers::init();
