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
            $b('zahlung_inklusive', 'Zahlung inklusive', 'Kartenzahlung, Apple Pay & Co. sind enthalten – keine zweite Rechnung vom Zahlungsanbieter.', 'plattform'),
            $b('gebuehr_weitergeben', 'Du entscheidest, wer die Gebühr trägt', 'Deine Gäste, du – oder ihr teilt sie euch.', 'plattform'),
            $b('einlass_kasse', 'Einlass, Abendkasse und Statistik', 'Einlass-Scanner, Abendkasse, Gästeliste und Statistik inklusive.', 'plattform'),
            $b('abrechnung', 'Abrechnung je Event', 'Umsatz, Gebühren, Erstattungen und Auszahlung je Event als PDF.', 'plattform'),
            // Eigener Shop
            $b('shop_domain', 'Shop unter deiner Domain', 'In deinem Look, Betrieb durch uns.', 'shop'),
            $b('daten_gehoeren_dir', 'Gäste und Bestellungen gehören dir', 'Deine Gäste kaufen direkt bei dir.', 'shop'),
            $b('null_je_ticket', '0 € je Ticket', 'Keine Gebühr je verkauftem Ticket.', 'shop'),
            $b('einrichtung_inklusive', 'Einrichtung inklusive', 'Beim Jahresvertrag richten wir deinen Shop für dich ein.', 'shop'),
            $b('updates', 'Updates und Support inklusive', 'Neue Funktionen kommen automatisch dazu.', 'shop'),
            // Eigene App
            $b('app_stores', 'In App Store und Google Play', 'Unter deinem Namen.', 'app'),
            $b('app_module', 'Module nach Wahl', 'Module wie Prämien und Musikwunsch.', 'app'),
            $b('app_betreuung', 'Einrichtung und Updates', 'Wir richten die App ein und kümmern uns um Updates.', 'app'),
        ];
        $i = function (array $a) {
            return $a + ['type' => 'module', 'short' => '', 'description' => '', 'prices' => [], 'default_interval' => 'monthly', 'fee_percent' => null, 'fee_fixed' => null,
                'price_note' => '', 'tax_note' => '', 'term' => '', 'benefits' => [], 'adds' => [], 'recommended' => false, 'active' => true];
        };
        $shop = ['shop_domain', 'daten_gehoeren_dir', 'null_je_ticket', 'updates'];
        $items = [
            $i(['key' => 'kostenlos', 'type' => 'plan', 'name' => 'Kostenlos eintragen', 'short' => 'Ohne Ticketverkauf',
                'description' => 'Event auf evendis.de und in der evendis-App, ohne Ticketverkauf. Eintritt frei, Abendkasse oder Link zu deinem eigenen Ticketshop.',
                'prices' => ['once' => 0], 'default_interval' => 'once', 'price_note' => 'ohne Gebühr', 'benefits' => ['reichweite', 'eintritt_frei', 'follower']]),
            $i(['key' => 'tickets', 'type' => 'plan', 'name' => 'Tickets über evendis', 'short' => 'Mit Ticketverkauf',
                'description' => 'Verkauf über evendis.de und die evendis-App. Keine Grundgebühr, keine Kosten ohne Verkauf, Freitickets kostenlos. Verkauf startet nach Freigabe deines Kontos.',
                'default_interval' => 'per_ticket', 'fee_percent' => 2.9, 'fee_fixed' => 0.30, // 11.11.2026 (vorher 2,5 % + 0,59 €) 'price_note' => 'je verkauftem Ticket, inkl. MwSt. und Zahlung',
                'tax_note' => 'inkl. MwSt.', 'term' => 'keine Bindung', 'benefits' => ['eigene_seite', 'keine_grundgebuehr', 'zahlung_inklusive', 'gebuehr_weitergeben', 'einlass_kasse', 'abrechnung'], 'recommended' => true]),
            $i(['key' => 'shop', 'type' => 'plan', 'name' => 'Eigener Shop', 'short' => 'Jahresvertrag',
                'description' => 'Shop unter deiner Domain, Betrieb durch uns. Gäste und Bestellungen gehören dir, 0 € je Ticket. Einrichtung inklusive.',
                'prices' => ['monthly' => 79], 'price_note' => 'bei Jahresvertrag, Einrichtung inklusive', 'term' => 'Jahresvertrag', 'benefits' => array_merge($shop, ['einrichtung_inklusive'])]),
            $i(['key' => 'shop_monatlich', 'type' => 'plan', 'name' => 'Eigener Shop – monatlich kündbar', 'short' => 'Ohne Jahresbindung',
                'description' => 'Shop unter deiner Domain, Betrieb durch uns. Gäste und Bestellungen gehören dir, 0 € je Ticket.',
                'prices' => ['monthly' => 99], 'price_note' => 'monatlich kündbar', 'term' => 'monatlich kündbar', 'benefits' => $shop]),
            $i(['key' => 'app', 'name' => 'Eigene App – Basis', 'short' => 'In App Store und Google Play',
                'description' => 'Deine App unter deinem Namen in App Store und Google Play. Setzt eigenen Shop oder Lizenz voraus.',
                'prices' => ['monthly' => 149], 'adds' => ['app_einrichtung'], 'benefits' => ['app_stores', 'app_module', 'app_betreuung']]),
            $i(['key' => 'app_einrichtung', 'type' => 'service', 'name' => 'App-Einrichtung', 'description' => 'Einmalig für die Einrichtung der App.',
                'prices' => ['once' => 1490], 'default_interval' => 'once']),
            $i(['key' => 'app_modul', 'name' => 'App-Modul', 'description' => 'Je Modul, z. B. Prämien oder Musikwunsch.', 'prices' => ['monthly' => 29]]),
            $i(['key' => 'app_alle_module', 'name' => 'Alle App-Module', 'description' => 'Alle Module der App zusammen.', 'prices' => ['monthly' => 249]]),
            $i(['key' => 'lizenz', 'type' => 'plan', 'name' => 'Lizenz', 'short' => 'Selbstbetrieb',
                'description' => 'Für deine eigene WordPress-Seite. Updates und Support inklusive, 0 € je Ticket.',
                'prices' => ['yearly' => 699], 'default_interval' => 'yearly', 'price_note' => 'je Website', 'term' => '12 Monate je Website', 'benefits' => ['null_je_ticket', 'daten_gehoeren_dir', 'updates']]),
            $i(['key' => 'domain', 'type' => 'service', 'name' => 'Eigene Domain für deine Veranstalter-Seite', 'description' => 'Seite, Events und Kasse unter deiner Domain.',
                'price_note' => 'auf Anfrage']),
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
        ];
    }

    public static function catalog() {
        $c = get_option(self::OPT_CATALOG, null);
        if (!is_array($c) || empty($c['items'])) return self::default_catalog();
        $def = self::default_catalog();
        foreach (['payment_rates', 'payment_note', 'projection_defaults'] as $k) if (!array_key_exists($k, $c)) $c[$k] = $def[$k]; // ältere gespeicherte Kataloge
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
                'recommended' => !empty($it['recommended']), 'active' => !array_key_exists('active', $it) || !empty($it['active'])];
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
                'note' => array_key_exists('note', $it) ? self::txt($it['note'], 160, false) : (string) ($c['price_note'] ?? '')];
        }

        $d = (array) $g('discount', []);
        $dt = in_array($d['type'] ?? '', ['percent', 'amount'], true) ? $d['type'] : 'none';
        $discount = ['type' => $dt, 'value' => $dt === 'none' ? 0 : (self::num($d['value'] ?? 0, 0) ?? 0), 'on' => in_array($d['on'] ?? '', ['all', 'recurring', 'once'], true) ? $d['on'] : 'all',
            'label' => self::txt($d['label'] ?? '', 80, false)];
        if ($dt === 'percent') $discount['value'] = min(100, $discount['value']);

        $benefits = [];
        $raw = $g('benefits', null);
        if ($raw === null) $raw = self::suggest_benefits($items);
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
        $projection = self::clean_projection($g('projection', null), $items, $cat);
        return [
            'customer' => $customer, 'items' => $items, 'discount' => $discount,
            'tax_rate' => max(0, min(30, floatval($g('tax_rate', $cat['tax_rate'] ?? 19)))),
            'term_months' => ($tm = intval($g('term_months', 0))) > 0 ? min(60, $tm) : null,
            'benefits' => $benefits, 'comparison' => $comparison, 'valid_until' => $vu, 'projection' => $projection,
            'intro' => $intro === null ? (string) $cat['intro'] : self::txt($intro, 3000),
            'conditions' => $cond === null ? (string) $cat['conditions'] : self::txt($cond, 3000),
            'contact' => $contact, 'accept_link' => (bool) $g('accept_link', true), 'internal_note' => self::txt($g('internal_note', ''), 2000),
        ];
    }

    const SELF_HOSTED_KEYS = ['shop', 'shop_monatlich', 'lizenz', 'app', 'app_modul', 'app_alle_module'];
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
        foreach ((array) $d['items'] as $it) if (($it['catalog_key'] ?? '') === 'tickets' && $it['interval'] === 'per_ticket') { $fp = $it['fee_percent']; $ff = $it['fee_fixed']; break; }
        if ($fp === null) { $pl = self::catalog_item('tickets'); $fp = $pl['fee_percent'] ?? 2.9; $ff = $pl['fee_fixed'] ?? 0.30; }
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
        foreach ((array) $d['items'] as $it) if (in_array($it['catalog_key'] ?? '', self::SELF_HOSTED_KEYS, true) && in_array($it['interval'], ['monthly', 'yearly'], true)) $names[] = $it['name'];
        $src = 'offer';
        $pkg = ($names && is_array($yearly)) ? floatval($yearly['gross']) : 0.0;
        if ($pkg <= 0) {
            $sh = self::catalog_item('shop');
            $pkg = (floatval($sh['prices']['monthly'] ?? 0) * 12 + floatval($sh['prices']['yearly'] ?? 0)) * $vat;
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

    /** Summen: wiederkehrend aufs Jahr gerechnet, einmalig getrennt; Gebühren je Ticket stehen extra. */
    public static function totals(array $d) {
        $rate = floatval($d['tax_rate'] ?? 19);
        $lines = [];
        $year = 0.0; $once = 0.0; $missing = []; $fees = [];
        foreach ((array) $d['items'] as $it) {
            $unit = $it['price'] !== null ? floatval($it['price']) : ($it['list_price'] !== null ? floatval($it['list_price']) : null);
            if ($it['interval'] === 'per_ticket') {
                $fees[] = ['id' => $it['id'], 'name' => $it['name'], 'fee_percent' => $it['fee_percent'], 'fee_fixed' => $it['fee_fixed']];
                $lines[] = ['id' => $it['id'], 'unit' => null, 'net' => null, 'year' => null, 'once' => null, 'overridden' => false];
                continue;
            }
            if ($unit === null) { $missing[] = $it['id']; $lines[] = ['id' => $it['id'], 'unit' => null, 'net' => null, 'year' => null, 'once' => null, 'overridden' => false]; continue; }
            $net = round($unit * intval($it['qty']), 2);
            $y = $it['interval'] === 'monthly' ? round($net * 12, 2) : ($it['interval'] === 'yearly' ? $net : 0.0);
            $o = in_array($it['interval'], ['once', 'per_event'], true) ? $net : 0.0;
            $year += $y; $once += $o;
            $lines[] = ['id' => $it['id'], 'unit' => $unit, 'net' => $net, 'year' => $y, 'once' => $o,
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
            'first_year_net' => round($yb['net_after'] + max(0, $once - $do), 2), 'tax_rate' => $rate, 'missing_prices' => $missing, 'fees' => $fees,
            'projection' => self::projection($d, $yb)];
    }

    public static function plan_name(array $d) {
        foreach ((array) $d['items'] as $it) if (($it['type'] ?? '') === 'plan') return (string) $it['name'];
        return (string) (($d['items'][0]['name'] ?? ''));
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
            'editable' => $row->status === 'draft', 'accept_link' => !empty($d['accept_link']), 'customer_key' => !empty($d['customer_key']) ? (string) $d['customer_key'] : null];
    }

    public static function full($row) {
        $d = self::data($row);
        $s = self::summary($row);
        $s['data'] = $d;
        $s['totals'] = self::totals($d);
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
                $it = array_merge($base, array_intersect_key($it, array_flip(['qty', 'price', 'note', 'description', 'id'])));
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
        $d = self::clean_data($b);
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
        $d = self::clean_data($b, $old);
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
    public static function rest_calc(WP_REST_Request $req) {
        $d = self::clean_data(self::body($req));
        return rest_ensure_response(['ok' => true, 'data' => $d, 'totals' => self::totals($d), 'benefits_suggested' => self::suggest_benefits($d['items'])]);
    }

    private static function pdf_response($bytes, $filename) {
        return rest_ensure_response(['ok' => true, 'filename' => $filename, 'mime' => 'application/pdf', 'data' => base64_encode($bytes)]);
    }

    /** POST {…wie Anlegen} → PDF-Vorschau ohne Speichern (Nummer „Entwurf“). */
    public static function rest_preview_draft(WP_REST_Request $req) {
        $d = self::clean_data(self::body($req));
        $bytes = self::render_pdf($d, ['number' => 'Entwurf', 'date' => wp_date('Y-m-d')]);
        if (!$bytes) return new WP_Error('tix_pdf_unavailable', 'PDF gerade nicht möglich.', ['status' => 500]);
        return self::pdf_response($bytes, 'Angebot-Entwurf.pdf');
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
        if (!is_email($to)) return new WP_Error('tix_offer_email', 'Bitte eine gültige E-Mail-Adresse des Kunden angeben.', ['status' => 400, 'field' => 'customer.email']);
        if (!$d['items']) return new WP_Error('tix_offer_empty', 'Das Angebot hat noch keine Positionen.', ['status' => 400, 'field' => 'items']);
        $t = self::totals($d);
        if ($t['missing_prices']) return new WP_Error('tix_offer_price_missing', 'Bei einer Position fehlt der Preis.', ['status' => 400, 'field' => 'items', 'items' => $t['missing_prices']]);
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
            'positionen' => array_column((array) $d['items'], 'name'), 'vorteile' => array_column(array_filter((array) $d['benefits'], function ($x) { return !empty($x['on']); }), 'title'),
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
            $items[] = ['name' => $it['name'], 'description' => $it['description'], 'qty' => $it['qty'], 'interval' => $it['interval'], 'unit' => $t['lines'][$i]['unit'], 'net' => $t['lines'][$i]['net'],
                'list_price' => $t['lines'][$i]['overridden'] ? $it['list_price'] : null, 'fee_percent' => $it['fee_percent'], 'fee_fixed' => $it['fee_fixed'], 'note' => $it['note']];
        }
        return ['number' => (string) $row->number, 'status' => $row->status, 'valid_until' => $row->valid_until, 'customer' => ['company' => $d['customer']['company'], 'name' => $d['customer']['name']],
            'intro' => $d['intro'], 'items' => $items, 'discount' => $d['discount'], 'totals' => ['yearly' => $t['yearly'], 'once' => $t['once'], 'monthly_net' => $t['monthly_net'], 'tax_rate' => $t['tax_rate']],
            'projection' => ($t['projection'] && !empty($d['projection']['in_pdf'])) ? $t['projection'] : null,
            'benefits' => array_values(array_map(function ($b) { return ['title' => $b['title'], 'text' => $b['text']]; }, array_filter((array) $d['benefits'], function ($b) { return !empty($b['on']); }))),
            'conditions' => $d['conditions'], 'contact' => $d['contact'], 'term_months' => $d['term_months'],
            'decision' => isset($d['decision']) ? ['status' => $d['decision']['status'], 'name' => $d['decision']['by'] === 'customer' ? $d['decision']['name'] : '', 'at' => $d['decision']['at']] : null,
            'can_decide' => in_array($row->status, ['sent', 'viewed'], true), 'pdf_filename' => self::filename($row)];
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
            . '.ok{background:#E6F4EC;color:#1F6B47;border-radius:12px;padding:14px;margin-top:16px}.er{background:#FFE3E9;color:#9D1B33;border-radius:12px;padding:14px;margin-top:16px}label{font-size:14px}';
        $out = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Angebot · ' . $e($brand) . '</title><style>' . $css . '</style></head><body><div class="w">';
        if (is_wp_error($row)) {
            return $out . '<div class="hd"><b>' . $e($brand) . '</b><h1>Angebot</h1></div><div class="c"><p>' . $e($row->get_error_message()) . '</p></div></div></body></html>';
        }
        $v = self::public_view($row);
        $fmt = function ($n) { return self::eur($n); };
        $iv = ['monthly' => 'monatlich', 'yearly' => 'jährlich', 'once' => 'einmalig', 'per_event' => 'je Event', 'per_ticket' => 'je Ticket'];
        $out .= '<div class="hd"><b>' . $e($brand) . '</b><h1>Angebot für ' . $e($v['customer']['company'] ?: $v['customer']['name']) . '</h1><div class="mu">Nr. ' . $e($v['number']) . ' · gültig bis ' . $e(wp_date('d.m.Y', strtotime($v['valid_until']))) . '</div></div>';
        if ($msg) $out .= '<div class="er">' . $e($msg) . '</div>';
        if ($v['decision'] && $v['decision']['status'] === 'accepted') $out .= '<div class="ok">Vielen Dank' . ($v['decision']['name'] ? ', ' . $e($v['decision']['name']) : '') . '! Dein Interesse ist bestätigt. Wir melden uns in Kürze und schicken dir den Vertrag separat.</div>';
        if ($v['decision'] && $v['decision']['status'] === 'rejected') $out .= '<div class="ok">Danke für deine Rückmeldung. Das Angebot ist abgelehnt.</div>';
        if ($v['intro']) $out .= '<div class="c"><p>' . nl2br($e($v['intro'])) . '</p></div>';
        if ($v['benefits']) {
            $out .= '<div class="c"><h2>Deine Vorteile</h2>';
            foreach ($v['benefits'] as $b) $out .= '<div class="b"><i></i><div><strong>' . $e($b['title']) . '</strong>' . ($b['text'] ? '<div class="mu">' . $e($b['text']) . '</div>' : '') . '</div></div>';
            $out .= '</div>';
        }
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
        $W = 595; $H = 842; $M = 48;
        $pdfa = class_exists('TIX_PDFA') && TIX_PDFA::available();
        if (!$pdfa && !class_exists('TIX_Simple_PDF')) return null;
        self::$euro = $pdfa ? '€' : 'EUR';
        $pdf = $pdfa ? new TIX_PDFA($W, $H, $M, 40) : new TIX_Simple_PDF($W, $H, $M, 40);
        $brand = class_exists('TIX_Mail_Layout') ? (string) (TIX_Mail_Layout::brand()['name'] ?? 'evendis') : 'evendis';
        if ($pdfa) $pdf->set_meta(['title' => 'Angebot ' . $meta['number'], 'author' => $brand]);
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
        $col([0.79, 0.80, 0.85]); $font(false, 10); $rt($W - $M, $H - 92, 'Angebot ' . $meta['number'], 10);
        $col([1, 1, 1]); $font(true, 26);
        $y = $H - 150;
        foreach (self::wrap('Angebot für ' . $who, 32) as $ln) { $pdf->text($M, $y, $ln); $y -= 32; }
        $col([0.79, 0.80, 0.85]); $font(false, 11);
        $pdf->text($M, $H - 222, 'Nr. ' . $meta['number'] . ' · ' . $date . ' · gültig bis ' . $valid);

        // ── Empfänger und Kontakt
        $y = $H - 285;
        $col(self::C_MUTED); $font(true, 8); $pdf->text($M, $y, 'FÜR'); $pdf->text(330, $y, 'DEIN KONTAKT');
        $y -= 15;
        $rl = array_values(array_filter([$cust['company'], $cust['name'], $cust['street'], trim($cust['zip'] . ' ' . $cust['city']), $cust['country'] !== 'DE' ? $cust['country'] : '', $cust['vat_id'] ? 'USt-IdNr. ' . $cust['vat_id'] : '']));
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

        // ── Leistungen und Preise
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

        // Vergleich mit dem bisherigen Anbieter: nie im PDF (User-Entscheid 06.11.), nur Gesprächshilfe im Studio

        // ── Hochrechnung je Event (abschaltbar über projection.in_pdf)
        $fc = $t['projection'];
        if ($fc && !empty($d['projection']['in_pdf'])) {
            $in = $fc['input']; $pv = $fc['commission']; $sh = $fc['selfhosted']; $cm = $fc['comparison'];
            $na = function ($v) { return $v === null ? 'Satz fehlt' : self::eur($v); };
            $pc = function ($v) { return $v === null ? '–' : rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',') . ' %'; };
            $ensure(260);
            $col(self::C_NIGHT); $font(true, 14); $pdf->text($M, $y, 'Hochrechnung je Event'); $y -= 18;
            $col(self::C_MUTED); $font(false, 9);
            $pay = ['paypal' => 'PayPal', 'card' => 'Kreditkarte & Klarna', 'both' => 'PayPal ' . $in['paypal_share'] . ' % · Kreditkarte & Klarna ' . $in['card_klarna_share'] . ' %'][$in['payment']];
            $pdf->text($M, $y, number_format($in['tickets'], 0, ',', '.') . ' Tickets je Event · Ø ' . self::eur($in['avg_price']) . ' · ' . $in['events_year'] . ' Events im Jahr · Umsatz je Event ' . self::eur($fc['revenue_event'])); $y -= 12;
            $pdf->text($M, $y, 'Zahlarten bei Selbstgehostet: ' . $pay . ' · ' . $in['tickets_per_order'] . ' Ticket(s) je Bestellung'); $y -= 18;
            $x1 = 360; $x2 = $xS - 8;
            $col(self::C_MUTED); $font(true, 8); $rt($x1, $y, 'PROVISIONSMODELL', 8); $rt($x2, $y, 'SELBSTGEHOSTET', 8); $y -= 14;
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
            $pdf->text($M + 8, $y, 'Selbstgehostet: ' . $sh['package'] . ' ' . self::eur($sh['package_year']) . ' im Jahr inkl. MwSt., umgelegt auf ' . $in['events_year'] . ' Events, 0 € je Ticket.'); $y -= 11;
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
                    : 'Bei dieser Prognose ist ' . ($cm['cheaper'] === 'selfhosted' ? 'Selbstgehostet' : 'das Provisionsmodell') . ' um ' . self::eur($cm['difference_event']) . ' je Event günstiger (' . self::eur($cm['difference_year']) . ' im Jahr).';
                $pdf->text($M + 10, $y, $txt); $y -= 22;
                if ($cm['break_even_tickets_month']) { $col(self::C_MUTED); $font(false, 9); $pdf->text($M + 8, $y, 'Selbstgehostet lohnt sich ab ' . number_format($cm['break_even_tickets_month'], 0, ',', '.') . ' Tickets im Monat.'); $y -= 13; }
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
}

TIX_Platform_Offers::init();
