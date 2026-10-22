<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Invoices – eigener Rechnungs-Kern (1.38.485, Plan „Bestellnummern und Rechnungen“, Phase 2).
 *
 * Schalter je Seite `tix_invoices_enabled` (Vorgabe aus; einschalten erst nach Klärung mit dem Steuerberater, macht der User).
 * Solange er an ist, legt der alte Anbieter-Weg (TIX_Invoicing/Lexoffice) keine eigenen Rechnungen mehr an (keine zwei Kreise).
 *
 * Belege (Tabelle `tix_invoices`, nie geändert oder gelöscht, auch nicht bei „Konto löschen“):
 *   R  Rechnung            – Tickets und Gebühren, bei bestätigter Zahlung (tix_order_completed), Betrag > 0
 *   RK Rechnungskorrektur  – Erstattung (tix_order_refund_recorded), Storno, Rückbuchung; Bezug auf die Rechnung
 *   B  Beleg ohne USt      – Guthaben-Aufladung und Gutschein-Kauf (Mehrzweck-Gutschein, USt erst bei Einlösung)
 *   BK Belegkorrektur
 * Nummer `<Serie>-<Kürzel>-<Jahr>-<00001>`, lückenlos je Aussteller/Serie/Jahr (Tabelle `tix_invoice_counters`, Vergabe in
 * derselben Transaktion wie der Beleg, Zeile mit SELECT … FOR UPDATE gesperrt).
 * Seit 1.38.526 je Aussteller und Serie einstellbar (`numbering` in der Konfiguration): Präfix, Jahr ja/nein, Stellen, nächste
 * Nummer; mit Jahr zählt der Kreis je Kalenderjahr, ohne Jahr durchlaufend (Zähler-Jahr 0). Nächste Nummer nur ≥ der zuletzt
 * vergebenen, Präfixe dürfen keine Nummern anderer Kreise erzeugen können (Spalte counter_year, DB-Version 4).
 *
 * Aussteller (issuer_key):
 *   site       Einzel-Seite: Firmendaten aus den Einstellungen (invoice_company_* …), Kürzel/Steuersatz/Kleinunternehmer
 *              in `tix_invoice_config`
 *   platform   evendis selbst (Modell B oder Servicegebühr an den Kunden im Modell A), Daten wie „site“
 *   org:<id>   evendis-Veranstalter (Modell A, Vorgabe): Daten aus den Auszahlungsdaten (TIX_Payout_Details), Kürzel und
 *              Steuersatz in `_tix_invoice_config` am Veranstalter; Rechnung im Namen des Veranstalters (Rechnungsstellung durch Dritte)
 * Modell auf evendis: Option `tix_invoice_issuer_model` = organizer (Vorgabe) | platform.
 *
 * Steuersatz: Kategorie (`tax_rate` in `_tix_ticket_categories`) → Event (`_tix_tax_rate`) → Aussteller → Seite (tax_rate).
 * Kleinunternehmer: alle Sätze 0, Hinweis § 19 UStG. Geschenkgutschein-Einlösung (Gutschein-Typ giftcard) ist Zahlung, kein Rabatt.
 * Abendkasse (payment_method pos_*) bekommt keine Rechnung, außer mit Schalter `tix_invoice_pos` (Kassenbeleg/TSE: Phase 5).
 * Automatisch entsteht eine Rechnung nur, wenn eine Bestellung bei eingeschaltetem Schalter bezahlt wird (echter Wechsel nach
 * „completed“) und die Rechnungsdaten vollständig sind (bzw. „Nummer folgt“). Fehlen sie, merkt sich die Bestellung das
 * (Option `tix_invoice_pending`). Seit 1.38.526 holt nichts automatisch nach: weder diese Warteschlange noch Bestellungen
 * vor dem Einschalten – nur per Klick („Rechnung jetzt erstellen“, POST /orders/{id}/invoices/issue). Zähler: missing_stats().
 * „Nummer folgt“ (1.38.525): `tax_id_follows` (vat_id|tax_number) in der Aussteller-Konfiguration – ohne Steuernummer und
 * USt-IdNr. gilt der Aussteller trotzdem als vollständig, der Beleg zeigt „USt-IdNr.: folgt“ bzw. „Steuernummer: folgt“ und
 * trägt `tax_id_missing` = 1 (ohne E-Rechnungs-XML, EN 16931 verlangt die Nummer). Nach dem Nachtrag: reissue() stellt neu
 * aus (neue Rechnung mit `replaces_id` + Storno der alten, Grund reissue).
 *
 * PDF: TIX_Invoice_PDF, Ablage im geschützten Ordner (TIX_Support::private_base()/invoices/<Jahr>/), SHA-256 in der Zeile.
 */
class TIX_Invoices {

    const NS          = 'tixomat/v1';
    const DB_VERSION  = '4'; // 2 = Übergabe an die Buchhaltung (export_*), 1.38.500; 3 = tax_id_missing/replaces_id, 1.38.525; 4 = counter_year (Nummernkreis einstellbar), 1.38.526
    const SERIES      = ['R', 'RK', 'B', 'BK'];
    const OPT_DB      = 'tix_invoices_db';
    const OPT_ENABLED = 'tix_invoices_enabled';
    const OPT_MODEL   = 'tix_invoice_issuer_model';
    const OPT_CONFIG  = 'tix_invoice_config';
    const OPT_PENDING = 'tix_invoice_pending';
    const OPT_ENABLED_AT = 'tix_invoices_enabled_at';
    const META_CONFIG = '_tix_invoice_config';
    const CRON        = 'tix_invoices_retry';
    const EPS         = 0.005;

    /** In diesem Aufruf angelegte Belege je Bestellung (für Mail-Anhänge). */
    private static $created = [];

    public static function init() {
        add_action('plugins_loaded', [__CLASS__, 'maybe_install'], 20);
        add_action('tix_order_completed', [__CLASS__, 'on_completed'], 5, 1); // vor der Bestellmail (Prio 10): PDF hängt an (1.38.487)
        add_action('tix_order_refund_recorded', [__CLASS__, 'on_refund'], 20, 3);
        add_action('tix_order_status_changed', [__CLASS__, 'on_status'], 5, 3); // vor der Status-Mail: Korrektur hängt an (1.38.487)
        add_action('tix_order_chargeback', [__CLASS__, 'on_chargeback'], 20, 1);
        add_action('tix_order_status_changed', [__CLASS__, 'track_status'], 1, 3);
        add_action('init', [__CLASS__, 'schedule']);
        add_action('add_option_' . self::OPT_ENABLED, [__CLASS__, 'on_enabled_added'], 10, 2);
        add_action('update_option_' . self::OPT_ENABLED, [__CLASS__, 'on_enabled_changed'], 10, 2);
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function enabled() {
        return get_option(self::OPT_ENABLED, '0') === '1';
    }

    public static function table()          { global $wpdb; return $wpdb->prefix . 'tix_invoices'; }
    public static function counters_table() { global $wpdb; return $wpdb->prefix . 'tix_invoice_counters'; }

    public static function maybe_install() {
        if (get_option(self::OPT_DB) === self::DB_VERSION) return;
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $t = self::table();
        $k = self::counters_table();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // 1.38.526: Zähler-Jahr je Beleg (0 = durchlaufend); Eindeutigkeit der laufenden Nummer je Zähler statt je Kalenderjahr
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t && !$wpdb->get_var("SHOW COLUMNS FROM $t LIKE 'counter_year'")) {
            $wpdb->query("ALTER TABLE $t ADD COLUMN counter_year SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER year");
            $wpdb->query("UPDATE $t SET counter_year = year");
            $wpdb->query("ALTER TABLE $t DROP INDEX issuer_seq, ADD UNIQUE KEY issuer_seq (issuer_key,series,counter_year,seq)");
        }
        dbDelta("CREATE TABLE $t (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            number VARCHAR(40) NOT NULL,
            kind VARCHAR(12) NOT NULL DEFAULT 'invoice',
            series VARCHAR(4) NOT NULL DEFAULT 'R',
            issuer_key VARCHAR(40) NOT NULL,
            organizer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            code VARCHAR(8) NOT NULL DEFAULT '',
            year SMALLINT UNSIGNED NOT NULL,
            counter_year SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            seq INT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            order_number VARCHAR(50) NOT NULL DEFAULT '',
            ref_invoice_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            reason VARCHAR(20) NOT NULL DEFAULT '',
            currency CHAR(3) NOT NULL DEFAULT 'EUR',
            net DECIMAL(12,2) NOT NULL DEFAULT 0,
            tax DECIMAL(12,2) NOT NULL DEFAULT 0,
            gross DECIMAL(12,2) NOT NULL DEFAULT 0,
            small_business TINYINT(1) NOT NULL DEFAULT 0,
            recipient_name VARCHAR(200) NOT NULL DEFAULT '',
            recipient_email VARCHAR(200) NOT NULL DEFAULT '',
            service_date DATE NULL,
            data LONGTEXT NOT NULL,
            pdf_path VARCHAR(255) NOT NULL DEFAULT '',
            pdf_sha256 CHAR(64) NOT NULL DEFAULT '',
            export_provider VARCHAR(20) NOT NULL DEFAULT '',
            export_status VARCHAR(12) NOT NULL DEFAULT '',
            export_ref VARCHAR(80) NOT NULL DEFAULT '',
            export_error VARCHAR(255) NOT NULL DEFAULT '',
            export_tries TINYINT UNSIGNED NOT NULL DEFAULT 0,
            export_at DATETIME NULL,
            tax_id_missing TINYINT(1) NOT NULL DEFAULT 0,
            replaces_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created DATETIME NOT NULL,
            created_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY number (number),
            UNIQUE KEY issuer_seq (issuer_key,series,counter_year,seq),
            KEY order_id (order_id),
            KEY issuer_created (issuer_key,created),
            KEY ref_invoice_id (ref_invoice_id),
            KEY export_status (export_status),
            KEY replaces_id (replaces_id),
            KEY tax_id_missing (issuer_key,tax_id_missing)
        ) $c;");
        dbDelta("CREATE TABLE $k (
            issuer_key VARCHAR(40) NOT NULL,
            series VARCHAR(4) NOT NULL,
            year SMALLINT UNSIGNED NOT NULL,
            last INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (issuer_key,series,year)
        ) $c;");
        update_option(self::OPT_DB, self::DB_VERSION, false);
    }

    /** Kein stündliches Nachholen mehr (1.38.526): alten Termin entfernen; Einschalt-Zeitpunkt bei Bedarf nachtragen. */
    public static function schedule() {
        if (get_option('tix_invoices_cron_off') !== '1') {
            wp_clear_scheduled_hook(self::CRON);
            wp_unschedule_hook('tix_invoices_retry_one');
            update_option('tix_invoices_cron_off', '1', false);
        }
        if (self::enabled() && get_option(self::OPT_ENABLED_AT, '') === '') self::backfill_enabled_at();
    }

    /** Zeitpunkt des Einschaltens (Ortszeit) merken – auch beim Setzen per wp option. */
    public static function on_enabled_added($option, $value) {
        if ((string) $value === '1') update_option(self::OPT_ENABLED_AT, current_time('mysql'), false);
    }

    public static function on_enabled_changed($old, $value) {
        if ((string) $value === '1' && (string) $old !== '1') update_option(self::OPT_ENABLED_AT, current_time('mysql'), false);
    }

    /** Schon eingeschaltet ohne Zeitpunkt (Seiten vor 1.38.526): früheste wartende Bestellung, sonst jetzt. */
    private static function backfill_enabled_at() {
        global $wpdb;
        $ids = self::pending_orders();
        $first = $ids ? (string) $wpdb->get_var("SELECT MIN(date_created) FROM {$wpdb->prefix}tix_orders WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")") : '';
        $now = current_time('mysql');
        update_option(self::OPT_ENABLED_AT, ($first !== '' && $first < $now) ? $first : $now, false);
    }

    public static function enabled_at() {
        return (string) get_option(self::OPT_ENABLED_AT, '');
    }

    // ═══════════════ Aussteller ═══════════════

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function model() {
        return get_option(self::OPT_MODEL, 'organizer') === 'platform' ? 'platform' : 'organizer';
    }

    /** Gültiger Steuersatz (0–30, 2 Stellen) oder null für „erben“. */
    public static function clean_rate($v) {
        if ($v === null || $v === '' || !is_numeric($v)) return null;
        $r = round(floatval($v), 2);
        return ($r < 0 || $r > 30) ? null : $r;
    }

    private static function site_settings() {
        $s = get_option('tix_settings', []);
        return is_array($s) ? $s : [];
    }

    /** Konfiguration eines Ausstellers: {code, tax_rate, small_business, tax_id_follows, datev{}}. */
    public static function config($issuer_key) {
        if (strpos($issuer_key, 'org:') === 0) {
            $c = get_post_meta(intval(substr($issuer_key, 4)), self::META_CONFIG, true);
        } else {
            $all = get_option(self::OPT_CONFIG, []);
            $c = is_array($all) ? ($all[$issuer_key] ?? []) : [];
        }
        return is_array($c) ? $c : [];
    }

    public static function save_config($issuer_key, array $c) {
        if (strpos($issuer_key, 'org:') === 0) {
            update_post_meta(intval(substr($issuer_key, 4)), self::META_CONFIG, $c);
            return;
        }
        $all = get_option(self::OPT_CONFIG, []);
        if (!is_array($all)) $all = [];
        $all[$issuer_key] = $c;
        update_option(self::OPT_CONFIG, $all, false);
    }

    /** Standard-Steuersatz der Seite (Einstellung tax_rate, sonst 19). */
    private static function site_rate() {
        $s = self::site_settings();
        $r = self::clean_rate($s['tax_rate'] ?? null);
        return ($r !== null && $r > 0) ? $r : 19.0;
    }

    /** Wird ein Kürzel schon von einem anderen Aussteller genutzt (Belege oder Einstellung)? */
    public static function code_taken($code, $issuer_key) {
        global $wpdb;
        $code = strtoupper((string) $code);
        if ($code === '') return false;
        $t = self::table();
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE code = %s AND issuer_key <> %s LIMIT 1", $code, $issuer_key))) return true;
        if (self::multi()) {
            if ($issuer_key !== 'platform' && $code === self::code('platform')) return true;
            $metas = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_CONFIG));
            foreach ((array) $metas as $m) {
                if ('org:' . intval($m->post_id) === $issuer_key) continue;
                $c = maybe_unserialize($m->meta_value);
                if (is_array($c) && strtoupper((string) ($c['code'] ?? '')) === $code) return true;
            }
        }
        return false;
    }

    // ═══════════════ Nummernkreis (1.38.526) ═══════════════

    /** Aufbau eines Kreises: {prefix, year, digits, custom}. Vorgabe = <Serie>-<Kürzel>-<Jahr>-<00001>. */
    public static function numbering($issuer_key, $series) {
        $n = (array) ((self::config($issuer_key)['numbering'] ?? [])[$series] ?? []);
        if (!$n) return ['prefix' => $series . '-' . self::code($issuer_key) . '-', 'year' => true, 'digits' => 5, 'custom' => false];
        return ['prefix' => (string) ($n['prefix'] ?? ''), 'year' => !empty($n['year']), 'digits' => max(1, min(10, intval($n['digits'] ?? 5))), 'custom' => true];
    }

    /** Zähler-Jahr eines Aufbaus: Kalenderjahr oder 0 (durchlaufend). */
    private static function counter_year(array $fmt, $year) {
        return !empty($fmt['year']) ? intval($year) : 0;
    }

    public static function format_number(array $fmt, $year, $seq) {
        return $fmt['prefix'] . (!empty($fmt['year']) ? intval($year) . '-' : '') . str_pad((string) intval($seq), max(1, intval($fmt['digits'])), '0', STR_PAD_LEFT);
    }

    /** Zuletzt vergebene laufende Nummer eines Zählers. */
    private static function counter_last($issuer_key, $series, $cy) {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare("SELECT last FROM " . self::counters_table() . " WHERE issuer_key = %s AND series = %s AND year = %d", $issuer_key, $series, intval($cy))));
    }

    /** Können zwei Präfixe dieselbe Nummer erzeugen? (gleich, oder einer verlängert den anderen nur um Ziffern/Bindestriche) */
    public static function prefixes_clash($a, $b) {
        $a = strtoupper((string) $a); $b = strtoupper((string) $b);
        if ($a === $b) return true;
        if (strlen($a) > strlen($b)) { $x = $a; $a = $b; $b = $x; }
        return strpos($b, $a) === 0 && preg_match('/^[0-9-]*$/', substr($b, strlen($a)));
    }

    /** Aussteller, deren Kreise auf dieser Seite vorkommen können (Belege, Konfiguration, Plattform). */
    private static function known_issuers() {
        global $wpdb;
        $keys = array_map('strval', (array) $wpdb->get_col("SELECT DISTINCT issuer_key FROM " . self::table()));
        $keys[] = self::multi() ? 'platform' : 'site';
        if (self::multi()) foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_CONFIG)) as $pid) $keys[] = 'org:' . intval($pid);
        else { $all = get_option(self::OPT_CONFIG, []); if (is_array($all)) foreach (array_keys($all) as $k) $keys[] = (string) $k; }
        return array_values(array_unique($keys));
    }

    /** Kleinste erlaubte nächste Nummer für einen Aufbau (über Zähler und schon vergebenen Nummern mit diesem Präfix). */
    private static function min_next($issuer_key, $series, array $fmt) {
        global $wpdb;
        $year = intval(wp_date('Y'));
        $max = self::counter_last($issuer_key, $series, self::counter_year($fmt, $year));
        $rows = (array) $wpdb->get_col($wpdb->prepare("SELECT number FROM " . self::table() . " WHERE issuer_key = %s AND series = %s AND number LIKE %s",
            $issuer_key, $series, $wpdb->esc_like($fmt['prefix']) . '%'));
        foreach ($rows as $num) {
            $rest = substr((string) $num, strlen($fmt['prefix']));
            if (!empty($fmt['year'])) { if (preg_match('/^(\d{4})-(\d+)$/', $rest, $m) && intval($m[1]) === $year) $max = max($max, intval($m[2])); }
            elseif (preg_match('/^\d+$/', $rest)) $max = max($max, intval($rest));
        }
        return $max + 1;
    }

    /**
     * Neuen Aufbau prüfen: Präfix (Zeichen, Länge; im Mehr-Veranstalter-Modus Pflicht), keine Überschneidung mit anderen Kreisen
     * (aktuelle Aufbauten aller Aussteller und schon vergebene Nummern). → true | WP_Error
     */
    private static function check_numbering($issuer_key, $series, array $fmt, array $own_new) {
        global $wpdb;
        $field = 'numbering.' . $series;
        if (strlen($fmt['prefix']) > 20 || !preg_match('/^[A-Za-z0-9\-_\/.]*$/', $fmt['prefix'])) return self::err('tix_invalid', 'Präfix: bis 20 Zeichen, nur A–Z, 0–9 und - _ / .', 400, $field . '.prefix');
        if ($fmt['prefix'] === '' && self::multi()) return self::err('tix_invalid', 'Bitte ein Präfix angeben (z. B. RE-).', 400, $field . '.prefix');
        if (preg_match('/\d$/', $fmt['prefix']) && empty($fmt['year'])) return self::err('tix_invalid', 'Das Präfix muss mit einem Buchstaben oder Zeichen wie - enden, sonst verschmilzt es mit der Nummer.', 400, $field . '.prefix');
        foreach (self::known_issuers() as $ik) {
            foreach (self::SERIES as $sr) {
                if ($ik === $issuer_key && $sr === $series) continue;
                $other = $ik === $issuer_key && isset($own_new[$sr]) ? $own_new[$sr] : self::numbering($ik, $sr);
                if (self::prefixes_clash($fmt['prefix'], $other['prefix'])) {
                    return self::err('tix_number_prefix_taken', $ik === $issuer_key ? 'Dieses Präfix überschneidet sich mit deinem Kreis ' . $sr . ' („' . $other['prefix'] . '“).' : 'Dieses Präfix nutzt schon ein anderer Aussteller.', 409, $field . '.prefix');
                }
            }
        }
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT number FROM " . self::table() . " WHERE NOT (issuer_key = %s AND series = %s) AND number LIKE %s LIMIT 200",
            $issuer_key, $series, $wpdb->esc_like($fmt['prefix']) . '%'));
        foreach ($rows as $r) {
            if (preg_match('/^[0-9-]+$/', substr((string) $r->number, strlen($fmt['prefix'])))) return self::err('tix_number_prefix_taken', 'Mit diesem Präfix gibt es schon Belege eines anderen Kreises (z. B. ' . $r->number . ').', 409, $field . '.prefix');
        }
        return true;
    }

    /** Für Studio/Verwaltung: je Serie {prefix, year, digits, custom, next, min_next, preview}. */
    public static function numbering_payload($issuer_key) {
        $year = intval(wp_date('Y'));
        $out = [];
        foreach (self::SERIES as $sr) {
            $f = self::numbering($issuer_key, $sr);
            $next = self::counter_last($issuer_key, $sr, self::counter_year($f, $year)) + 1;
            $out[$sr] = ['prefix' => $f['prefix'], 'year' => $f['year'], 'digits' => $f['digits'], 'custom' => $f['custom'],
                'next' => $next, 'min_next' => max($next, self::min_next($issuer_key, $sr, $f)), 'preview' => self::format_number($f, $year, max($next, self::min_next($issuer_key, $sr, $f)))];
        }
        return $out;
    }

    /** Kürzel im Nummernkreis (2–6 Zeichen A–Z/0–9). */
    public static function code($issuer_key) {
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) (self::config($issuer_key)['code'] ?? '')));
        if ($c !== '') return substr($c, 0, 6);
        if ($issuer_key === 'platform') return 'EV';
        if ($issuer_key === 'site') return class_exists('TIX_Order_Number') ? TIX_Order_Number::code() : 'TIX';
        $oid = intval(substr($issuer_key, 4));
        $name = remove_accents(html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8'));
        $ini = '';
        foreach (preg_split('/\s+/', trim(preg_replace('/[^A-Za-z0-9 ]/', ' ', $name))) as $w) if ($w !== '') $ini .= strtoupper($w[0]);
        $ini = substr($ini, 0, 4);
        if (strlen($ini) < 2) $ini = 'V';
        return $ini . $oid; // eindeutig über die Veranstalter-ID
    }

    /**
     * Daten des Ausstellers für den Beleg.
     * → {key, organizer_id, name, address[], street, zip, city, country, tax_id, vat_id, tax_id_follows, small_business, email, phone,
     *    managing_director, register, footer, logo, accent, on_behalf, complete, missing[]}
     * tax_id_follows: 'vat_id' | 'tax_number' nur solange beide Nummern fehlen und „Nummer folgt“ gewählt ist, sonst ''.
     */
    public static function issuer($issuer_key) {
        $cfg = self::config($issuer_key);
        $brand = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand() : ['name' => get_bloginfo('name'), 'accent' => '#111111', 'logo' => ''];
        if (strpos($issuer_key, 'org:') === 0) {
            $oid = intval(substr($issuer_key, 4));
            $d = class_exists('TIX_Payout_Details') ? TIX_Payout_Details::data($oid) : ['billing' => [], 'tax_status' => '', 'vat_id' => '', 'tax_number' => '', 'email' => ''];
            $b = (array) ($d['billing'] ?? []);
            $name = trim((string) ($b['company'] ?? '')) ?: trim((string) ($b['name'] ?? ''));
            $sb = ($d['tax_status'] ?? '') === 'small_business';
            $img = intval(get_post_meta($oid, '_tix_org_image_id', true));
            $i = [
                'key' => $issuer_key, 'organizer_id' => $oid, 'name' => $name,
                'owner' => (trim((string) ($b['company'] ?? '')) !== '' && trim((string) ($b['name'] ?? '')) !== '') ? trim((string) $b['name']) : '',
                'street' => trim((string) ($b['street'] ?? '')), 'zip' => trim((string) ($b['zip'] ?? '')), 'city' => trim((string) ($b['city'] ?? '')),
                'country' => strtoupper((string) (($b['country'] ?? '') ?: 'DE')),
                'tax_id' => (string) ($d['tax_number'] ?? ''), 'vat_id' => (string) ($d['vat_id'] ?? ''), 'small_business' => $sb,
                'email' => class_exists('TIX_Payout_Details') ? (string) TIX_Payout_Details::billing_email($oid) : '', 'phone' => '',
                'managing_director' => '', 'register' => '', 'footer' => '',
                'logo' => $img ? (string) (wp_get_attachment_image_url($img, 'medium') ?: '') : '',
                'accent' => (string) ($brand['accent'] ?? '#111111'),
                'on_behalf' => trim((string) (self::site_settings()['invoice_company_name'] ?? '')) ?: (string) ($brand['name'] ?? ''),
                'tax_rate' => self::clean_rate($cfg['tax_rate'] ?? null) ?? self::site_rate(),
            ];
        } else {
            $s = self::site_settings();
            $g = function ($k) use ($s) { return trim((string) ($s[$k] ?? '')); };
            $addr = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $g('invoice_company_address')))));
            $i = [
                'key' => $issuer_key, 'organizer_id' => 0, 'name' => $g('invoice_company_name'), 'owner' => '',
                'street' => $addr[0] ?? '', 'zip' => '', 'city' => implode(', ', array_slice($addr, 1)), 'country' => 'DE',
                'address_lines' => $addr,
                'tax_id' => $g('invoice_company_tax_id'), 'vat_id' => $g('invoice_company_ust_id'), 'small_business' => !empty($cfg['small_business']),
                'email' => $g('invoice_email'), 'phone' => $g('invoice_phone'),
                'managing_director' => $g('invoice_managing_director'),
                'register' => trim($g('invoice_register_court') . ' ' . $g('invoice_register_number')),
                'footer' => $g('invoice_footer_text'),
                'logo' => (string) ($brand['logo'] ?? ''), 'accent' => (string) ($brand['accent'] ?? '#111111'), 'on_behalf' => '',
                'tax_rate' => self::clean_rate($cfg['tax_rate'] ?? null) ?? self::site_rate(),
            ];
        }
        if (!isset($i['address_lines'])) {
            $i['address_lines'] = array_values(array_filter([$i['owner'], $i['street'], trim($i['zip'] . ' ' . $i['city']), $i['country'] !== 'DE' ? $i['country'] : '']));
        }
        $no_number = $i['tax_id'] === '' && $i['vat_id'] === '';
        $i['tax_id_follows'] = $no_number ? self::follows_setting($issuer_key) : '';
        $missing = [];
        if ($i['name'] === '') $missing[] = 'name';
        if (count(array_filter($i['address_lines'], function ($l) use ($i) { return $l !== $i['owner']; })) < 2) $missing[] = 'address';
        if ($no_number && $i['tax_id_follows'] === '') $missing[] = 'tax_id';
        $i['missing'] = $missing;
        $i['complete'] = !$missing;
        $i['code'] = self::code($issuer_key);
        return $i;
    }

    /** Gewähltes „Nummer folgt“ des Ausstellers: 'vat_id' | 'tax_number' | '' (1.38.525). */
    public static function follows_setting($issuer_key) {
        $f = (string) (self::config($issuer_key)['tax_id_follows'] ?? '');
        return in_array($f, ['vat_id', 'tax_number'], true) ? $f : '';
    }

    /** Hinweis (i) zu „Nummer folgt“ für Studio und Verwaltung. */
    const TAX_ID_INFO = 'Nach § 14 UStG gehört deine Steuernummer oder USt-IdNr. auf jede Rechnung. Mit „folgt“ entstehen Rechnungen trotzdem, dort steht dann „USt-IdNr.: folgt“ bzw. „Steuernummer: folgt“. Trag die Nummer nach, sobald du sie hast, und stelle die Rechnungen ohne Nummer dann neu aus.';

    // ═══════════════ Bestellung → Positionen ═══════════════

    private static function order_row($order_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tix_orders WHERE id = %d", intval($order_id)));
    }

    private static function item_rows($order_id) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d ORDER BY id ASC", intval($order_id)));
    }

    /** Art einer Position: voucher (Guthaben/Gutschein, ohne USt) oder ticket. */
    private static function item_type($row) {
        $eid = intval($row->event_id);
        $meta = json_decode((string) $row->meta, true);
        if (is_array($meta) && !empty($meta['wallet_topup'])) return 'voucher';
        if ($eid && class_exists('TIX_Wallet_Balance') && method_exists('TIX_Wallet_Balance', 'is_wallet_event') && TIX_Wallet_Balance::is_wallet_event($eid)) return 'voucher';
        if ($eid && get_post_meta($eid, '_tix_system_giftcard_event', true) === '1') return 'voucher';
        if ($eid) {
            $cat = self::category_of($row);
            if (is_array($cat) && !empty($cat['gift_card'])) return 'voucher';
        }
        return 'ticket';
    }

    private static function category_of($row) {
        $cats = get_post_meta(intval($row->event_id), '_tix_ticket_categories', true);
        if (!is_array($cats)) return null;
        $pid = intval($row->product_id);
        foreach ($cats as $c) if ($pid && intval($c['product_id'] ?? 0) === $pid) return $c;
        foreach ($cats as $c) if ((string) ($c['name'] ?? '') !== '' && (string) ($c['name'] ?? '') === (string) $row->cat_name) return $c;
        return null;
    }

    /** Steuersatz einer Position: Kategorie → Event → Aussteller. */
    private static function rate_for($row, array $issuer) {
        if (!empty($issuer['small_business'])) return 0.0;
        $cat = intval($row->event_id) ? self::category_of($row) : null;
        $r = is_array($cat) ? self::clean_rate($cat['tax_rate'] ?? null) : null;
        if ($r === null && intval($row->event_id)) $r = self::clean_rate(get_post_meta(intval($row->event_id), '_tix_tax_rate', true));
        return $r ?? floatval($issuer['tax_rate']);
    }

    /** Aussteller einer Ticket-Position. */
    private static function issuer_key_for_event($event_id) {
        if (!self::multi()) return 'site';
        if (self::model() === 'platform') return 'platform';
        $oid = intval(get_post_meta(intval($event_id), '_tix_organizer_id', true));
        return $oid ? 'org:' . $oid : 'platform';
    }

    private static function event_detail($event_id) {
        $eid = intval($event_id);
        if (!$eid) return ['', null];
        $date = (string) get_post_meta($eid, '_tix_date_start', true);
        $time = (string) get_post_meta($eid, '_tix_time_start', true);
        $loc  = trim((string) get_post_meta($eid, '_tix_location', true));
        $parts = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $ts = strtotime($date . ' 12:00:00');
            $wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][intval(gmdate('w', $ts))];
            $parts[] = $wd . ', ' . gmdate('d.m.Y', $ts) . ($time !== '' ? ', ' . substr($time, 0, 5) . ' Uhr' : '');
        }
        if ($loc !== '') $parts[] = $loc;
        return [implode(' · ', $parts), preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null];
    }

    /** Gutschein der Bestellung: {code, amount, giftcard(bool)} oder null. */
    private static function coupon_of($o) {
        $c = get_option('_tix_order_coupon_' . intval($o->id));
        $amount = round(floatval($o->discount), 2);
        if ($amount <= 0) return null;
        $code = is_array($c) ? (string) ($c['code'] ?? '') : '';
        $type = is_array($c) ? (string) ($c['type'] ?? '') : '';
        if ($type === '' && $code !== '' && class_exists('TIX_Native_Checkout')) {
            $def = TIX_Native_Checkout::resolve_coupon($code);
            $type = is_array($def) ? (string) ($def['discount_type'] ?? '') : '';
        }
        return ['code' => $code, 'amount' => $amount, 'giftcard' => $type === 'giftcard'];
    }

    /**
     * Positionen einer bezahlten Bestellung, gruppiert nach Beleg: [ "issuer|series" => [issuer_key, series, lines[], service_date, payments[]] ].
     * Beträge brutto; bei „Preise netto“ (tax_enabled + !tax_inclusive) aus netto hochgerechnet.
     */
    public static function plan($order_id) {
        $o = self::order_row($order_id);
        if (!$o) return [];
        $s = self::site_settings();
        $net_prices = !empty($s['tax_enabled']) && empty($s['tax_inclusive']) && floatval($s['tax_rate'] ?? 0) > 0;
        $gross_of = function ($amount, $rate) use ($net_prices) { return $net_prices ? round($amount * (1 + $rate / 100), 2) : round($amount, 2); };
        $groups = [];
        $issuers = [];
        $iss = function ($key) use (&$issuers) { if (!isset($issuers[$key])) $issuers[$key] = self::issuer($key); return $issuers[$key]; };
        $add = function ($key, $series, array $line, $sdate = null) use (&$groups) {
            $g = $key . '|' . $series;
            if (!isset($groups[$g])) $groups[$g] = ['issuer_key' => $key, 'series' => $series, 'lines' => [], 'service_date' => null, 'payments' => []];
            $groups[$g]['lines'][] = $line;
            if ($sdate && ($groups[$g]['service_date'] === null || $sdate < $groups[$g]['service_date'])) $groups[$g]['service_date'] = $sdate;
        };
        foreach (self::item_rows($order_id) as $row) {
            $qty = max(1, intval($row->quantity));
            $type = self::item_type($row);
            $key = self::issuer_key_for_event($row->event_id);
            list($detail, $sdate) = self::event_detail($row->event_id);
            $title = $row->event_id ? html_entity_decode((string) get_the_title(intval($row->event_id)), ENT_QUOTES, 'UTF-8') : '';
            $text = trim((string) $row->name) !== '' ? (string) $row->name : (string) $row->cat_name;
            if ($title !== '' && stripos($text, $title) === false) $text = $title . ' – ' . $text;
            if ($type === 'voucher') {
                $g = round(floatval($row->total), 2);
                $add($key, 'B', ['type' => 'voucher', 'text' => $text, 'detail' => '', 'qty' => $qty, 'unit' => round($g / $qty, 2), 'gross' => $g, 'rate' => null, 'event_id' => intval($row->event_id)]);
                continue;
            }
            $rate = self::rate_for($row, $iss($key));
            $g = $gross_of(floatval($row->total), $rate);
            $add($key, 'R', ['type' => 'ticket', 'text' => $text, 'detail' => $detail, 'qty' => $qty, 'unit' => round($g / $qty, 2), 'gross' => $g, 'rate' => $rate, 'event_id' => intval($row->event_id)], $sdate);
        }
        if (!$groups) return [];

        // Rabatt (kein Geschenkgutschein) anteilig auf die Positionen, je Satz eine Zeile; Geschenkgutschein = Zahlung
        $coupon = self::coupon_of($o);
        if ($coupon && !$coupon['giftcard']) {
            $pool = array_filter(array_keys($groups), function ($g) use ($groups) { return $groups[$g]['series'] === 'R'; }) ?: array_keys($groups);
            $base = 0.0;
            foreach ($pool as $g) foreach ($groups[$g]['lines'] as $l) $base += $l['gross'];
            if ($base > 0) {
                $left = $coupon['amount'];
                $shares = [];
                foreach ($pool as $g) {
                    $by = [];
                    foreach ($groups[$g]['lines'] as $l) { $rk = $l['rate'] === null ? 'x' : (string) $l['rate']; $by[$rk] = ($by[$rk] ?? 0) + $l['gross']; }
                    foreach ($by as $rk => $sum) $shares[] = [$g, $rk === 'x' ? null : floatval($rk), $sum];
                }
                foreach ($shares as $n => $sh) {
                    $part = $n === count($shares) - 1 ? round($left, 2) : round($coupon['amount'] * $sh[2] / $base, 2);
                    $left -= $part;
                    if (abs($part) < self::EPS) continue;
                    $gp = $sh[1] === null ? $part : $gross_of($part, $sh[1]);
                    $groups[$sh[0]]['lines'][] = ['type' => 'discount', 'text' => 'Rabatt' . ($coupon['code'] !== '' ? ' (Code ' . $coupon['code'] . ')' : ''), 'detail' => '', 'qty' => 1, 'unit' => -$gp, 'gross' => -$gp, 'rate' => $sh[1], 'event_id' => 0];
                }
            }
        }

        // Servicegebühr an den Kunden: Modell A auf evendis = eigene Rechnung von evendis, sonst beim Aussteller der Tickets
        $fees = class_exists('TIX_Settlement') ? TIX_Settlement::order_fees($order_id) : ['customer_fee_line' => 0];
        $fee = round(floatval($fees['customer_fee_line'] ?? 0), 2);
        if ($fee > 0) {
            $fkey = self::multi() ? 'platform' : 'site';
            if (!self::multi()) {
                foreach ($groups as $g) if ($g['series'] === 'R') { $fkey = $g['issuer_key']; break; }
            }
            $fi = $iss($fkey);
            $frate = !empty($fi['small_business']) ? 0.0 : floatval($fi['tax_rate']);
            $fg = $gross_of($fee, $frate);
            $f = get_option('_tix_order_fees_' . intval($order_id));
            $label = is_array($f) && trim((string) ($f['fee_label'] ?? '')) !== '' ? trim((string) $f['fee_label']) : 'Servicegebühr';
            $sdate = null;
            foreach ($groups as $g) if ($g['service_date'] && ($sdate === null || $g['service_date'] < $sdate)) $sdate = $g['service_date'];
            $add($fkey, 'R', ['type' => 'fee', 'text' => $label, 'detail' => '', 'qty' => 1, 'unit' => $fg, 'gross' => $fg, 'rate' => $frate, 'event_id' => 0], $sdate);
        }

        // Abgleich mit dem bezahlten Betrag (+ Geschenkgutschein): Rundung bis 5 Cent in die größte Position
        $target = round(floatval($o->total) + ($coupon && $coupon['giftcard'] ? $coupon['amount'] : 0), 2);
        $sum = 0.0;
        foreach ($groups as $g) foreach ($g['lines'] as $l) $sum += $l['gross'];
        $diff = round($target - $sum, 2);
        if (abs($diff) >= 0.01) {
            if (abs($diff) <= 0.05) {
                $best = null;
                foreach ($groups as $gk => $g) foreach ($g['lines'] as $li => $l) if ($best === null || $l['gross'] > $groups[$best[0]]['lines'][$best[1]]['gross']) $best = [$gk, $li];
                $groups[$best[0]]['lines'][$best[1]]['gross'] = round($groups[$best[0]]['lines'][$best[1]]['gross'] + $diff, 2);
            } else {
                foreach ($groups as $gk => $g) $groups[$gk]['diff'] = $diff;
                error_log('TIX Invoices: Bestellung ' . intval($order_id) . ' weicht um ' . $diff . ' EUR von den Positionen ab.');
            }
        }

        // Geschenkgutschein als Zahlung auf die Belege verteilen
        if ($coupon && $coupon['giftcard']) {
            $left = $coupon['amount'];
            foreach ($groups as $gk => $g) {
                $gs = 0.0; foreach ($g['lines'] as $l) $gs += $l['gross'];
                $p = round(min($left, max(0, $gs)), 2);
                if ($p > 0) { $groups[$gk]['payments'][] = ['label' => 'Bezahlt mit Gutschein' . ($coupon['code'] !== '' ? ' ' . $coupon['code'] : ''), 'amount' => $p]; $left -= $p; }
            }
        }
        foreach ($groups as $gk => $g) {
            $groups[$gk]['issuer'] = $iss($g['issuer_key']);
        }
        return $groups;
    }

    /** Steueraufstellung aus Positionen: [{rate, net, tax, gross}] (Kleinunternehmer/Beleg: Satz 0 bzw. ohne). */
    public static function taxes(array $lines) {
        $by = [];
        foreach ($lines as $l) {
            $rk = $l['rate'] === null ? 'x' : number_format(floatval($l['rate']), 2, '.', '');
            $by[$rk] = round(($by[$rk] ?? 0) + floatval($l['gross']), 2);
        }
        ksort($by);
        $out = [];
        foreach ($by as $rk => $gross) {
            $rate = $rk === 'x' ? null : floatval($rk);
            $net = $rate ? round($gross / (1 + $rate / 100), 2) : $gross;
            $out[] = ['rate' => $rate, 'net' => $net, 'tax' => round($gross - $net, 2), 'gross' => $gross];
        }
        return $out;
    }

    private static function recipient($o) {
        $name = trim($o->billing_first_name . ' ' . $o->billing_last_name);
        $lines = array_values(array_filter([
            (string) $o->billing_company, $name, (string) $o->billing_address_1, (string) $o->billing_address_2,
            trim($o->billing_postcode . ' ' . $o->billing_city), (strtoupper((string) $o->billing_country) !== 'DE' && $o->billing_country !== '') ? strtoupper((string) $o->billing_country) : '',
        ], function ($v) { return trim((string) $v) !== ''; }));
        return ['name' => $name, 'company' => (string) $o->billing_company, 'email' => (string) $o->billing_email, 'lines' => $lines];
    }

    // ═══════════════ Vergabe ═══════════════

    /**
     * Beleg anlegen (Nummer + Zeile in einer Transaktion). $doc = [kind, series, issuer(array), order(row), lines, payments,
     * service_date, ref(row|null), reason, diff]. → int id | WP_Error
     */
    private static function create(array $doc) {
        global $wpdb;
        self::maybe_install();
        $issuer = $doc['issuer'];
        $key = $issuer['key'];
        $series = $doc['series'];
        $code = $issuer['code'];
        $year = intval(wp_date('Y'));
        $lines = $doc['lines'];
        $sb = !empty($issuer['small_business']) && $doc['kind'] !== 'receipt' && !in_array($series, ['B', 'BK'], true);
        $taxes = in_array($series, ['B', 'BK'], true) ? [] : self::taxes($lines);
        $gross = 0.0; foreach ($lines as $l) $gross += floatval($l['gross']);
        $gross = round($gross, 2);
        $tax = 0.0; foreach ($taxes as $x) $tax += $x['tax'];
        $tax = round($tax, 2);
        $o = $doc['order'];
        $rcp = self::recipient($o);
        $data = [
            'v' => 1,
            'issuer' => array_diff_key($issuer, array_flip(['complete', 'missing'])),
            'recipient' => $rcp,
            'lines' => array_values($lines),
            'taxes' => $taxes,
            'payments' => array_values($doc['payments'] ?? []),
            'payment_method' => (string) $o->payment_method_title,
            'order_date' => (string) $o->date_created,
            'ref' => $doc['ref'] ? ['id' => intval($doc['ref']->id), 'number' => (string) $doc['ref']->number, 'date' => substr((string) $doc['ref']->created, 0, 10)] : null,
            'diff' => $doc['diff'] ?? null,
        ];
        // Neuausstellung (1.38.525): neue Rechnung nennt die ersetzte, das Storno die neue
        if (!empty($doc['replaces'])) $data['replaces'] = ['id' => intval($doc['replaces']->id), 'number' => (string) $doc['replaces']->number, 'date' => substr((string) $doc['replaces']->created, 0, 10)];
        if (!empty($doc['replaced_by'])) $data['replaced_by'] = $doc['replaced_by'];
        $no_tax_id = in_array($series, ['R', 'RK'], true) && trim((string) ($issuer['tax_id'] ?? '')) === '' && trim((string) ($issuer['vat_id'] ?? '')) === '';
        $t = self::table(); $c = self::counters_table();
        $fmt = self::numbering($key, $series);
        $cy = self::counter_year($fmt, $year);
        $wpdb->query('START TRANSACTION');
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $c (issuer_key, series, year, last) VALUES (%s, %s, %d, 0)", $key, $series, $cy));
        $last = $wpdb->get_var($wpdb->prepare("SELECT last FROM $c WHERE issuer_key = %s AND series = %s AND year = %d FOR UPDATE", $key, $series, $cy));
        if ($last === null) { $wpdb->query('ROLLBACK'); return new WP_Error('tix_invoice_counter', 'Nummernkreis nicht verfügbar.'); }
        $seq = intval($last) + 1;
        $number = self::format_number($fmt, $year, $seq);
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE number = %s", $number))) {
            $wpdb->query('ROLLBACK');
            error_log('TIX Invoices: Nummer ' . $number . ' schon vergeben (Kreis ' . $key . '/' . $series . ') – Nummernkreis prüfen.');
            return new WP_Error('tix_invoice_number_taken', 'Nummer schon vergeben.');
        }
        $ok = $wpdb->insert($t, [
            'number' => $number, 'kind' => $doc['kind'], 'series' => $series, 'issuer_key' => $key, 'organizer_id' => intval($issuer['organizer_id']),
            'code' => $code, 'year' => $year, 'counter_year' => $cy, 'seq' => $seq, 'order_id' => intval($o->id), 'order_number' => (string) $o->order_number,
            'ref_invoice_id' => $doc['ref'] ? intval($doc['ref']->id) : 0, 'reason' => (string) $doc['reason'], 'currency' => 'EUR',
            'net' => round($gross - $tax, 2), 'tax' => $tax, 'gross' => $gross, 'small_business' => $sb ? 1 : 0,
            'recipient_name' => mb_substr($rcp['company'] !== '' ? $rcp['company'] : $rcp['name'], 0, 200), 'recipient_email' => mb_substr($rcp['email'], 0, 200),
            'service_date' => $doc['service_date'] ?: null, 'data' => wp_json_encode($data),
            'tax_id_missing' => $no_tax_id ? 1 : 0, 'replaces_id' => !empty($doc['replaces']) ? intval($doc['replaces']->id) : 0,
            'created' => current_time('mysql'), 'created_gmt' => current_time('mysql', true),
        ]);
        if (!$ok) {
            $err = $wpdb->last_error;
            $wpdb->query('ROLLBACK');
            error_log('TIX Invoices: Beleg ' . $number . ' nicht angelegt: ' . $err);
            return new WP_Error('tix_invoice_insert', 'Beleg konnte nicht angelegt werden.');
        }
        $id = intval($wpdb->insert_id);
        $wpdb->query($wpdb->prepare("UPDATE $c SET last = %d WHERE issuer_key = %s AND series = %s AND year = %d", $seq, $key, $series, $cy));
        $wpdb->query('COMMIT');
        // Kürzel festhalten, sobald der Kreis benutzt wird
        $cfg = self::config($key);
        if (strtoupper((string) ($cfg['code'] ?? '')) !== $code) { $cfg['code'] = $code; self::save_config($key, $cfg); }
        self::store_pdf($id);
        self::$created[intval($o->id)][] = $id;
        do_action('tix_invoice_created', $id, $doc['kind'], intval($o->id));
        return $id;
    }

    private static function lock($order_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', 'tix_inv_' . intval($order_id)));
    }

    private static function unlock($order_id) {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'tix_inv_' . intval($order_id)));
    }

    public static function for_order($order_id) {
        global $wpdb;
        self::maybe_install();
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE order_id = %d ORDER BY id ASC", intval($order_id)));
    }

    public static function get($id) {
        global $wpdb;
        self::maybe_install();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", intval($id)));
    }

    /** Statuswechsel dieses Aufrufs (vorher → jetzt), damit on_completed echte Zahlungen erkennt. */
    private static $transition = [];

    public static function track_status($order_id, $status, $old) {
        self::$transition[intval($order_id)] = [(string) $old, (string) $status];
    }

    /**
     * Bezahlt → Rechnung, aber nur bei einem echten Wechsel nach „completed“ in diesem Aufruf (1.38.526). Ein erneutes
     * Auslösen an einer schon bezahlten Bestellung (z. B. Status noch einmal gespeichert) legt nichts nach.
     */
    public static function on_completed($order_id) {
        if (!self::enabled()) return;
        $t = self::$transition[intval($order_id)] ?? null;
        if (!$t || $t[1] !== 'completed' || $t[0] === 'completed') return;
        self::issue_for_order(intval($order_id));
    }

    /** Rechnungen/Belege einer bezahlten Bestellung anlegen (idempotent). → ['created' => [ids], 'pending' => [issuer_keys]] */
    public static function issue_for_order($order_id) {
        $out = ['created' => [], 'pending' => []];
        $o = self::order_row($order_id);
        if (!$o || !in_array($o->status, ['completed', 'processing'], true)) return $out;
        // Abendkasse (pos_cash/pos_card): Kassenbeleg/TSE kommt in Phase 5 – Rechnungen nur mit Schalter tix_invoice_pos
        if (strpos((string) $o->payment_method, 'pos_') === 0 && get_option('tix_invoice_pos', '0') !== '1') return $out;
        // Demo Club / Vorschau: nie Rechnungen, kein Nummernkreis (1.38.508)
        if (class_exists('TIX_Sales') && TIX_Sales::is_sandbox_order($order_id)) return $out;
        if (!self::lock($order_id)) return $out;
        try {
            $have = [];
            foreach (self::for_order($order_id) as $r) if (in_array($r->series, ['R', 'B'], true)) $have[$r->issuer_key . '|' . $r->series] = true;
            $pending = [];
            foreach (self::plan($order_id) as $gk => $g) {
                if (isset($have[$gk])) continue;
                $sum = 0.0; foreach ($g['lines'] as $l) $sum += $l['gross'];
                if ($sum <= self::EPS) continue;
                if (!$g['issuer']['complete']) { $pending[] = $g['issuer_key']; continue; }
                $id = self::create(['kind' => $g['series'] === 'B' ? 'receipt' : 'invoice', 'series' => $g['series'], 'issuer' => $g['issuer'], 'order' => $o,
                    'lines' => $g['lines'], 'payments' => $g['payments'], 'service_date' => $g['service_date'], 'ref' => null, 'reason' => 'paid', 'diff' => $g['diff'] ?? null]);
                if (is_wp_error($id)) { $pending[] = $g['issuer_key']; continue; }
                $out['created'][] = $id;
            }
            self::set_pending($order_id, array_values(array_unique($pending)));
            $out['pending'] = $pending;
            // Was vor der Rechnung schon erstattet wurde, sofort korrigieren
            if ($out['created']) self::correct_to_refunded($order_id);
        } finally {
            self::unlock($order_id);
        }
        return $out;
    }

    private static function set_pending($order_id, array $keys) {
        $p = get_option(self::OPT_PENDING, []);
        if (!is_array($p)) $p = [];
        if ($keys) $p[intval($order_id)] = $keys; else unset($p[intval($order_id)]);
        update_option(self::OPT_PENDING, $p, false);
    }

    public static function pending_orders($issuer_key = '') {
        $p = get_option(self::OPT_PENDING, []);
        if (!is_array($p)) return [];
        if ($issuer_key === '') return array_map('intval', array_keys($p));
        return array_map('intval', array_keys(array_filter($p, function ($keys) use ($issuer_key) { return in_array($issuer_key, (array) $keys, true); })));
    }

    /**
     * Bezahlte Bestellungen ohne Rechnung bzw. Beleg (R/B) eines Ausstellers – nur Zähler, nichts wird angelegt (1.38.526).
     * Ohne Abendkasse (außer Schalter tix_invoice_pos), ohne 0-€-Bestellungen, ohne Sandbox (Zahlart demo, Demo-/Vorschau-Veranstalter).
     * → {count, waiting (wartet auf Rechnungsdaten), since_enabled, before_enabled, enabled_at, order_ids[] (neueste, ≤ $limit)}
     */
    public static function missing_stats($issuer_key, $limit = 20) {
        global $wpdb;
        self::maybe_install();
        $o = $wpdb->prefix . 'tix_orders';
        $i = $wpdb->prefix . 'tix_order_items';
        $t = self::table();
        $where = ["o.status IN ('completed','processing')", 'o.total > 0', "o.payment_method <> 'demo'",
            "NOT EXISTS (SELECT 1 FROM $t v WHERE v.order_id = o.id AND v.series IN ('R','B'))"];
        if (get_option('tix_invoice_pos', '0') !== '1') $where[] = "o.payment_method NOT LIKE 'pos\\_%'";
        $sandbox = class_exists('TIX_Sales') && method_exists('TIX_Sales', 'sandbox_ids') ? array_map('intval', TIX_Sales::sandbox_ids()) : [];
        if ($sandbox) $where[] = "NOT EXISTS (SELECT 1 FROM $i x JOIN {$wpdb->postmeta} m ON m.post_id = x.event_id AND m.meta_key = '_tix_organizer_id' WHERE x.order_id = o.id AND m.meta_value IN ('" . implode("','", $sandbox) . "'))";
        if (self::multi() && strpos($issuer_key, 'org:') === 0) {
            $where[] = $wpdb->prepare("EXISTS (SELECT 1 FROM $i x JOIN {$wpdb->postmeta} m ON m.post_id = x.event_id AND m.meta_key = '_tix_organizer_id' WHERE x.order_id = o.id AND m.meta_value = %s)", (string) intval(substr($issuer_key, 4)));
        }
        $w = implode(' AND ', $where);
        $at = self::enabled_at();
        $since = $at !== '' ? intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $o o WHERE $w AND o.date_created >= %s", $at))) : 0;
        $all = intval($wpdb->get_var("SELECT COUNT(*) FROM $o o WHERE $w"));
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT o.id FROM $o o WHERE $w ORDER BY o.date_created DESC, o.id DESC LIMIT %d", max(1, intval($limit)))));
        $waiting = count(array_filter($issuer_key === '' ? self::pending_orders() : self::pending_orders($issuer_key), function ($oid) use ($wpdb, $t) {
            return !$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE order_id = %d AND series IN ('R','B') LIMIT 1", $oid));
        }));
        return ['count' => $all, 'waiting' => $waiting, 'since_enabled' => $at !== '' ? $since : $all, 'before_enabled' => $at !== '' ? $all - $since : 0,
            'enabled_at' => $at !== '' ? $at : null, 'order_ids' => $ids];
    }

    /** Darf für die Bestellung per Klick eine Rechnung erstellt werden (bezahlt, > 0 €, noch ohne R/B, keine Abendkasse/Sandbox)? */
    public static function can_issue($order_id) {
        if (!self::enabled()) return false;
        $o = self::order_row($order_id);
        if (!$o || !in_array($o->status, ['completed', 'processing'], true) || floatval($o->total) <= 0) return false;
        if (strpos((string) $o->payment_method, 'pos_') === 0 && get_option('tix_invoice_pos', '0') !== '1') return false;
        if (class_exists('TIX_Sales') && TIX_Sales::is_sandbox_order($order_id)) return false;
        foreach (self::for_order($order_id) as $r) if (in_array($r->series, ['R', 'B'], true)) return false;
        return true;
    }

    /** Studio-Block „Bestellungen ohne Rechnung“ (nur Hinweis, keine Automatik). */
    public static function missing_payload($issuer_key) {
        $m = self::missing_stats($issuer_key);
        $m['message'] = $m['count'] > 0 ? ($m['count'] === 1 ? '1 Bestellung ohne Rechnung' : $m['count'] . ' Bestellungen ohne Rechnung') : null;
        return $m;
    }

    /** Nur noch manuell (z. B. WP-CLI); kein Cron, kein Auslöser beim Speichern (1.38.526). */
    public static function retry_pending($issuer_key = '') {
        if (!self::enabled()) return 0;
        $n = 0;
        foreach (array_slice(self::pending_orders(is_string($issuer_key) ? $issuer_key : ''), 0, 200) as $oid) {
            $r = self::issue_for_order($oid);
            $n += count($r['created']);
        }
        return $n;
    }

    // ═══════════════ Korrekturen ═══════════════

    /** Verbleibender Betrag je Satz eines Belegs (Ursprung minus Korrekturen). */
    private static function remaining($inv) {
        global $wpdb;
        $d = json_decode((string) $inv->data, true);
        $by = [];
        foreach ((array) ($d['lines'] ?? []) as $l) { $rk = $l['rate'] === null ? 'x' : number_format(floatval($l['rate']), 2, '.', ''); $by[$rk] = round(($by[$rk] ?? 0) + floatval($l['gross']), 2); }
        $corr = (array) $wpdb->get_results($wpdb->prepare("SELECT data FROM " . self::table() . " WHERE ref_invoice_id = %d", intval($inv->id)));
        foreach ($corr as $c) {
            $cd = json_decode((string) $c->data, true);
            foreach ((array) ($cd['lines'] ?? []) as $l) { $rk = $l['rate'] === null ? 'x' : number_format(floatval($l['rate']), 2, '.', ''); $by[$rk] = round(($by[$rk] ?? 0) + floatval($l['gross']), 2); }
        }
        return ['by_rate' => $by, 'total' => round(array_sum($by), 2), 'corrected' => count($corr) > 0, 'lines' => (array) ($d['lines'] ?? [])];
    }

    /** Korrektur über $amount (positiv, Geld zurück) zu einem Beleg; $full = Rest komplett. */
    private static function correct($inv, $amount, $full, $reason, $replaced_by = null) {
        $rem = self::remaining($inv);
        if ($rem['total'] <= self::EPS) return 0;
        $amount = $full ? $rem['total'] : round(min($amount, $rem['total']), 2);
        if ($amount <= self::EPS) return 0;
        $o = self::order_row($inv->order_id);
        if (!$o) return 0;
        $series = $inv->series === 'B' ? 'BK' : 'RK';
        $lines = [];
        if ($full && !$rem['corrected']) {
            foreach ($rem['lines'] as $l) { $l['gross'] = -round(floatval($l['gross']), 2); $l['unit'] = -round(floatval($l['unit']), 2); $l['text'] = 'Storno: ' . $l['text']; $lines[] = $l; }
        } else {
            $left = $amount;
            $keys = array_keys(array_filter($rem['by_rate'], function ($v) { return $v > self::EPS; }));
            foreach ($keys as $n => $rk) {
                $part = $n === count($keys) - 1 ? round($left, 2) : round($amount * $rem['by_rate'][$rk] / $rem['total'], 2);
                $left -= $part;
                if ($part <= self::EPS) continue;
                $rate = $rk === 'x' ? null : floatval($rk);
                $lines[] = ['type' => 'refund', 'text' => ($full ? 'Storno (Restbetrag)' : 'Erstattung') . ($rate !== null ? ' · ' . self::fmt_rate($rate) . ' USt' : ''), 'detail' => 'zu ' . $inv->number, 'qty' => 1, 'unit' => -$part, 'gross' => -$part, 'rate' => $rate, 'event_id' => 0];
            }
        }
        if (!$lines) return 0;
        $issuer = self::issuer($inv->issuer_key);
        if (!$issuer['complete']) {
            $snap = json_decode((string) $inv->data, true);
            $issuer = array_merge((array) ($snap['issuer'] ?? []), ['key' => $inv->issuer_key, 'code' => $issuer['code'], 'organizer_id' => intval($inv->organizer_id)]);
        }
        $id = self::create(['kind' => 'correction', 'series' => $series, 'issuer' => $issuer, 'order' => $o, 'lines' => $lines, 'payments' => [],
            'service_date' => $inv->service_date, 'ref' => $inv, 'reason' => $reason, 'replaced_by' => $replaced_by]);
        return is_wp_error($id) ? 0 : $id;
    }

    public static function fmt_rate($r) {
        return rtrim(rtrim(number_format(floatval($r), 2, ',', ''), '0'), ',') . ' %';
    }

    /** Erstattung verteilen: anteilig auf die offenen Belege der Bestellung. */
    public static function on_refund($order_id, $amount, $is_full) {
        if (!self::enabled()) return;
        if (!self::lock($order_id)) return;
        try {
            self::distribute($order_id, round(floatval($amount), 2), (bool) $is_full, 'refund');
        } finally {
            self::unlock($order_id);
        }
    }

    private static function distribute($order_id, $amount, $full, $reason) {
        $open = [];
        $total = 0.0;
        foreach (self::for_order($order_id) as $inv) {
            if (!in_array($inv->series, ['R', 'B'], true)) continue;
            $rem = self::remaining($inv);
            if ($rem['total'] > self::EPS) { $open[] = [$inv, $rem['total']]; $total += $rem['total']; }
        }
        if (!$open) return [];
        $ids = [];
        $left = $amount;
        foreach ($open as $n => $x) {
            if ($full) { $ids[] = self::correct($x[0], 0, true, $reason); continue; }
            $part = $n === count($open) - 1 ? round($left, 2) : round($amount * $x[1] / $total, 2);
            $left -= $part;
            if ($part > self::EPS) $ids[] = self::correct($x[0], $part, false, $reason);
        }
        return array_values(array_filter($ids));
    }

    /** Nach der (späten) Rechnung: bereits erstattete Beträge korrigieren. */
    private static function correct_to_refunded($order_id) {
        $o = self::order_row($order_id);
        if (!$o) return;
        $refunded = class_exists('TIX_Settlement') ? TIX_Settlement::refunded_amount($o) : 0;
        if ($o->status === 'refunded' || $o->status === 'cancelled') { self::distribute($order_id, 0, true, $o->status === 'refunded' ? 'refund' : 'cancel'); return; }
        if ($refunded <= self::EPS) return;
        $done = 0.0;
        foreach (self::for_order($order_id) as $inv) if (in_array($inv->series, ['RK', 'BK'], true)) $done += -floatval($inv->gross);
        $todo = round($refunded - $done, 2);
        if ($todo > self::EPS) self::distribute($order_id, $todo, false, 'refund');
    }

    public static function on_status($order_id, $status, $old) {
        if (!self::enabled() || !in_array($status, ['refunded', 'cancelled'], true)) return;
        if (!self::lock($order_id)) return;
        try {
            self::distribute(intval($order_id), 0, true, $status === 'refunded' ? 'refund' : 'cancel');
        } finally {
            self::unlock($order_id);
        }
    }

    public static function on_chargeback($order_id) {
        if (!self::enabled()) return;
        if (!self::lock($order_id)) return;
        try {
            self::distribute(intval($order_id), 0, true, 'chargeback');
        } finally {
            self::unlock($order_id);
        }
    }

    // ═══════════════ Neuausstellung ohne Steuernummer (1.38.525) ═══════════════

    /** Neue Rechnung, die diese ersetzt (oder null). */
    public static function replacement_of($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE replaces_id = %d ORDER BY id ASC LIMIT 1", intval($id)));
    }

    /**
     * Rechnungen eines Ausstellers ohne Steuernummer/USt-IdNr., die noch nicht neu ausgestellt sind.
     * → {open, open_gross, cancelled, ids[]}  (open = noch offener Betrag > 0, cancelled = komplett storniert/erstattet)
     */
    public static function tax_id_missing_stats($issuer_key, $limit = 0) {
        global $wpdb;
        self::maybe_install();
        $t = self::table();
        $where = "i.series = 'R' AND i.tax_id_missing = 1 AND NOT EXISTS (SELECT 1 FROM $t x WHERE x.replaces_id = i.id)";
        if ($issuer_key !== '') $where .= $wpdb->prepare(' AND i.issuer_key = %s', $issuer_key);
        $rows = (array) $wpdb->get_results("SELECT i.id, i.gross + COALESCE((SELECT SUM(c.gross) FROM $t c WHERE c.ref_invoice_id = i.id), 0) AS rest FROM $t i WHERE $where ORDER BY i.id ASC");
        $out = ['open' => 0, 'open_gross' => 0.0, 'cancelled' => 0, 'ids' => []];
        foreach ($rows as $r) {
            if (floatval($r->rest) > self::EPS) {
                $out['open']++;
                $out['open_gross'] += floatval($r->rest);
                if (!$limit || count($out['ids']) < $limit) $out['ids'][] = intval($r->id);
            } else {
                $out['cancelled']++;
            }
        }
        $out['open_gross'] = round($out['open_gross'], 2);
        return $out;
    }

    /**
     * Rechnung ohne Steuernummer neu ausstellen, sobald der Aussteller sie nachgetragen hat: neue Rechnung mit den aktuellen
     * Angaben über den noch offenen Betrag (Bezug „ersetzt …“), danach Storno der alten (Grund reissue, nennt die neue).
     * Eine schon teilweise erstattete Rechnung wird je Steuersatz mit dem Restbetrag neu ausgestellt.
     * → ['new' => row, 'storno' => row|null] | WP_Error
     */
    public static function reissue($inv) {
        if (!$inv || $inv->series !== 'R') return self::err('tix_invoice_reissue_kind', 'Nur Rechnungen lassen sich neu ausstellen.', 409);
        if (empty($inv->tax_id_missing)) return self::err('tix_invoice_reissue_not_needed', 'Diese Rechnung trägt schon eine Steuernummer bzw. USt-IdNr.', 409);
        $issuer = self::issuer($inv->issuer_key);
        if ($issuer['tax_id'] === '' && $issuer['vat_id'] === '') return self::err('tix_tax_id_missing', 'Bitte zuerst die Steuernummer bzw. USt-IdNr. eintragen.', 409, 'tax_id');
        if (!$issuer['complete']) return self::err('tix_issuer_incomplete', 'Die Rechnungsdaten sind unvollständig.', 409);
        $o = self::order_row($inv->order_id);
        if (!$o) return self::err('tix_order_missing', 'Bestellung nicht gefunden.', 404);
        if (!self::lock($inv->order_id)) return self::err('tix_busy', 'Die Bestellung wird gerade bearbeitet. Bitte gleich noch einmal versuchen.', 409);
        try {
            if (self::replacement_of($inv->id)) return self::err('tix_invoice_reissued', 'Diese Rechnung wurde schon neu ausgestellt.', 409);
            $rem = self::remaining($inv);
            if ($rem['total'] <= self::EPS) return self::err('tix_invoice_reissue_cancelled', 'Die Rechnung ist vollständig storniert bzw. erstattet – eine Neuausstellung ist nicht nötig.', 409);
            $d = json_decode((string) $inv->data, true);
            if (!$rem['corrected']) {
                $lines = $rem['lines'];
                $payments = (array) ($d['payments'] ?? []);
                $diff = $d['diff'] ?? null;
            } else {
                $lines = [];
                foreach ($rem['by_rate'] as $rk => $part) {
                    if ($part <= self::EPS) continue;
                    $rate = $rk === 'x' ? null : floatval($rk);
                    $lines[] = ['type' => 'ticket', 'text' => 'Leistungen lt. Rechnung ' . $inv->number . ' (nach Erstattung)' . ($rate !== null ? ' · ' . self::fmt_rate($rate) . ' USt' : ''),
                        'detail' => '', 'qty' => 1, 'unit' => round($part, 2), 'gross' => round($part, 2), 'rate' => $rate, 'event_id' => 0];
                }
                $payments = [];
                $diff = null;
            }
            $new_id = self::create(['kind' => 'invoice', 'series' => 'R', 'issuer' => $issuer, 'order' => $o, 'lines' => $lines, 'payments' => $payments,
                'service_date' => $inv->service_date, 'ref' => null, 'reason' => 'reissue', 'diff' => $diff, 'replaces' => $inv]);
            if (is_wp_error($new_id)) return $new_id;
            $new = self::get($new_id);
            $storno_id = self::correct($inv, 0, true, 'reissue', ['id' => intval($new->id), 'number' => (string) $new->number]);
            if (!$storno_id) error_log('TIX Invoices: Storno zu ' . $inv->number . ' nach Neuausstellung ' . $new->number . ' fehlgeschlagen.');
            if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) TIX_Order_Admin::add_note(intval($inv->order_id), '🧾 ' . $inv->number . ' neu ausgestellt als ' . $new->number . ' (Steuernummer nachgetragen)', 'system');
            return ['new' => $new, 'storno' => $storno_id ? self::get($storno_id) : null];
        } finally {
            self::unlock($inv->order_id);
        }
    }

    // ═══════════════ PDF ═══════════════

    public static function pdf_dir($year) {
        $base = class_exists('TIX_Support') ? TIX_Support::private_base() : (wp_upload_dir()['basedir'] . '/tix-private');
        if (class_exists('TIX_Support')) TIX_Support::private_dir(); // Sperrdatei im Grundordner
        $dir = $base . '/invoices/' . intval($year);
        if (!file_exists($dir)) wp_mkdir_p($dir);
        if (!file_exists($base . '/invoices/index.php')) @file_put_contents($base . '/invoices/index.php', "<?php // Silence is golden.\n");
        if (!file_exists($dir . '/index.php')) @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        return $dir;
    }

    private static function store_pdf($id) {
        global $wpdb;
        $inv = self::get($id);
        if (!$inv || !class_exists('TIX_Invoice_PDF')) return;
        $bytes = TIX_Invoice_PDF::render($inv);
        if (!$bytes) return;
        $dir = self::pdf_dir($inv->year);
        $file = $dir . '/' . sanitize_file_name($inv->number) . '.pdf';
        // Nie überschreiben: liegt schon eine Datei mit dem Namen (z. B. nach Datenbank-Wiederherstellung), eigener Name mit ID
        if (file_exists($file)) $file = $dir . '/' . sanitize_file_name($inv->number) . '-' . intval($inv->id) . '-' . wp_generate_password(6, false, false) . '.pdf';
        if (@file_put_contents($file, $bytes) === false) {
            error_log('TIX Invoices: PDF ' . $inv->number . ' nicht gespeichert.');
            $wpdb->update(self::table(), ['pdf_sha256' => hash('sha256', $bytes)], ['id' => intval($id)]);
            return;
        }
        $rel = 'invoices/' . intval($inv->year) . '/' . basename($file);
        $wpdb->update(self::table(), ['pdf_path' => $rel, 'pdf_sha256' => hash('sha256', $bytes)], ['id' => intval($id)]);
    }

    /** PDF eines Belegs: gespeicherte Datei (Prüfsumme stimmt), sonst neu aus dem Schnappschuss. → [bytes, filename, stored] */
    public static function pdf($inv) {
        $name = (in_array($inv->series, ['B', 'BK'], true) ? 'Beleg-' : ($inv->kind === 'correction' ? 'Rechnungskorrektur-' : 'Rechnung-')) . $inv->number . '.pdf';
        if ($inv->pdf_path !== '' && class_exists('TIX_Support')) {
            $file = TIX_Support::private_base() . '/' . $inv->pdf_path;
            $bytes = @is_readable($file) ? @file_get_contents($file) : false;
            if ($bytes !== false && ($inv->pdf_sha256 === '' || hash_equals($inv->pdf_sha256, hash('sha256', $bytes)))) return [$bytes, $name, true];
        }
        $bytes = class_exists('TIX_Invoice_PDF') ? TIX_Invoice_PDF::render($inv) : '';
        return [$bytes, $name, false];
    }

    // ═══════════════ Zustellung (Phase 3, 1.38.487) ═══════════════

    /** Darf der Aufrufer (Inhaber/Team/Admin) diesen Beleg verwalten (senden)? */
    private static function manages($inv) {
        if (current_user_can('manage_options') || !self::multi()) return true;
        $oid = TIX_App_Scope::organizer_id_for_user();
        if ($oid && $inv->issuer_key === 'org:' . $oid) return true;
        $o = class_exists('TIX_Order') ? TIX_Order::get(intval($inv->order_id)) : null;
        return $o && TIX_App_Scope::order_allowed($o) && $inv->issuer_key !== 'platform';
    }

    /** Download-Link für Kunden (über den Bestell-Schlüssel, ohne Anmeldung). */
    public static function download_url($inv, $order_key = '') {
        if ($order_key === '') {
            global $wpdb;
            $order_key = (string) $wpdb->get_var($wpdb->prepare("SELECT order_key FROM {$wpdb->prefix}tix_orders WHERE id = %d", intval($inv->order_id)));
        }
        $url = add_query_arg(['action' => 'tix_invoice_download', 'order_id' => intval($inv->order_id), 'order_key' => $order_key, 'invoice_id' => intval($inv->id)], admin_url('admin-post.php'));
        return class_exists('TIX_Customer_URL') ? TIX_Customer_URL::to($url) : $url;
    }

    /** Kurzliste für Konto/App/Studio: [{id, number, kind, series, gross, issued_at, ref, pdf, download_url}] */
    public static function order_summary($order_id, $order_key = '') {
        $out = [];
        foreach (self::for_order($order_id) as $r) {
            $d = json_decode((string) $r->data, true);
            $out[] = ['id' => intval($r->id), 'number' => (string) $r->number, 'kind' => (string) $r->kind, 'series' => (string) $r->series,
                'gross' => round(floatval($r->gross), 2), 'issued_at' => (string) $r->created, 'ref' => !empty($d['ref']['number']) ? (string) $d['ref']['number'] : null,
                'pdf' => rest_url(self::NS . '/invoices/' . intval($r->id) . '/pdf'), 'download_url' => self::download_url($r, $order_key),
                'xml' => (in_array($r->series, ['R', 'RK'], true) && empty($r->tax_id_missing)) ? rest_url(self::NS . '/invoices/' . intval($r->id) . '/xml') : null];
        }
        return $out;
    }

    /** Erste Rechnung (R, sonst B) einer Bestellung oder null – für den bisherigen Feldnamen invoice_url. */
    public static function primary($order_id) {
        $b = null;
        foreach (self::for_order($order_id) as $r) {
            if ($r->series === 'R') return $r;
            if ($r->series === 'B' && !$b) $b = $r;
        }
        return $b;
    }

    /** Belege als Dateien für einen Mail-Anhang (temporär, nach dem Versand cleanup()). */
    public static function mail_files(array $invoices) {
        $files = [];
        if (!$invoices) return $files;
        $base = class_exists('TIX_Support') ? TIX_Support::private_base() : (wp_upload_dir()['basedir'] . '/tix-private');
        if (class_exists('TIX_Support')) TIX_Support::private_dir();
        $dir = $base . '/invoices/mail-tmp-' . wp_generate_password(10, false, false);
        if (!wp_mkdir_p($dir)) return $files;
        @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        foreach ($invoices as $inv) {
            list($bytes, $name) = self::pdf($inv);
            if (!$bytes) continue;
            $f = $dir . '/' . sanitize_file_name($name);
            if (@file_put_contents($f, $bytes) !== false) $files[] = $f;
        }
        return $files;
    }

    public static function cleanup(array $files) {
        $dirs = [];
        foreach ($files as $f) { $dirs[dirname($f)] = true; @unlink($f); }
        foreach (array_keys($dirs) as $d) { @unlink($d . '/index.php'); @rmdir($d); }
    }

    /** Anhänge zur Bestellmail: Rechnung(en) und Belege der Bestellung (nur mit Schalter). */
    public static function completed_mail_files($order_id) {
        if (!self::enabled()) return [];
        return self::mail_files(array_values(array_filter(self::for_order($order_id), function ($r) { return in_array($r->series, ['R', 'B'], true); })));
    }

    /** Anhänge zur Storno-/Erstattungs-Mail: in diesem Aufruf angelegte Korrekturen. */
    public static function status_mail_files($order_id) {
        if (!self::enabled() || empty(self::$created[intval($order_id)])) return [];
        $list = [];
        foreach (self::$created[intval($order_id)] as $id) { $r = self::get($id); if ($r && in_array($r->series, ['RK', 'BK'], true)) $list[] = $r; }
        return self::mail_files($list);
    }

    /** Beleg per Mail senden (Studio „Rechnung senden“). */
    public static function send($inv, $email) {
        $files = self::mail_files([$inv]);
        if (!$files) return false;
        $title = in_array($inv->series, ['B', 'BK'], true) ? ($inv->series === 'BK' ? 'Belegkorrektur' : 'Beleg') : ($inv->kind === 'correction' ? 'Rechnungskorrektur' : 'Rechnung');
        $brand = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand()['name'] : get_bloginfo('name');
        $ok = class_exists('TIX_Mail_Layout')
            ? TIX_Mail_Layout::send($email, $brand . ' – ' . $title . ' ' . $inv->number, ['title' => $title . ' ' . $inv->number, 'kind' => 'customer', 'blocks' => [
                ['type' => 'text', 'html' => 'Hallo,<br>anbei ' . ($title === 'Rechnung' ? 'deine Rechnung' : 'dein Dokument') . ' <strong>' . esc_html($inv->number) . '</strong> zur Bestellung ' . esc_html($inv->order_number) . ' als PDF.'],
                ['type' => 'info', 'rows' => [['Nummer', esc_html($inv->number)], ['Datum', esc_html(wp_date('d.m.Y', strtotime($inv->created)))], ['Betrag', esc_html(number_format(floatval($inv->gross), 2, ',', '.') . ' €')]]],
            ]], ['attachments' => $files, 'customer' => true])
            : wp_mail($email, $title . ' ' . $inv->number, 'Anbei ' . $title . ' ' . $inv->number, [], $files);
        self::cleanup($files);
        return (bool) $ok;
    }

    /** POST /invoices/{id}/send {email?} → {ok, email} (Inhaber/Team/Admin; Empfänger Vorgabe = Rechnungs-E-Mail) */
    public static function rest_send(WP_REST_Request $req) {
        $inv = self::get(intval($req['id']));
        if (!$inv) return self::err('tix_invoice_missing', 'Beleg nicht gefunden.', 404);
        if (!self::manages($inv)) return self::err('rest_forbidden', 'Keine Berechtigung für diesen Beleg.', 403);
        $raw = trim((string) ($req->get_param('email') ?? ''));
        $email = $raw === '' ? (string) $inv->recipient_email : sanitize_email($raw);
        if (!is_email($email)) return self::err('tix_invalid', 'Bitte eine gültige E-Mail-Adresse angeben.', 400, 'email');
        if (!self::send($inv, $email)) return self::err('tix_invoice_mail', 'Die Mail konnte nicht gesendet werden.', 500);
        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) TIX_Order_Admin::add_note(intval($inv->order_id), '🧾 ' . $inv->number . ' gesendet an ' . $email, 'email');
        return rest_ensure_response(['ok' => true, 'email' => $email]);
    }

    /** POST /invoices/{id}/export → {ok, export} – Übergabe an die Buchhaltung jetzt (erneut) ausführen (1.38.500) */
    public static function rest_export(WP_REST_Request $req) {
        global $wpdb;
        $inv = self::get(intval($req['id']));
        if (!$inv) return self::err('tix_invoice_missing', 'Beleg nicht gefunden.', 404);
        if (!self::manages($inv)) return self::err('rest_forbidden', 'Keine Berechtigung für diesen Beleg.', 403);
        if (!class_exists('TIX_Invoice_Accounting') || TIX_Invoice_Accounting::settings($inv->issuer_key)['provider'] === 'none') return self::err('tix_accounting_off', 'Keine Buchhaltung angebunden.', 409);
        if (!in_array($inv->series, ['R', 'RK'], true)) return self::err('tix_accounting_skip', 'Belege ohne Umsatzsteuer werden nicht übergeben.', 409);
        if (empty($inv->export_provider)) $wpdb->update(self::table(), ['export_provider' => TIX_Invoice_Accounting::settings($inv->issuer_key)['provider'], 'export_status' => 'pending'], ['id' => intval($inv->id)]);
        $wpdb->update(self::table(), ['export_tries' => 0], ['id' => intval($inv->id)]);
        $r = TIX_Invoice_Accounting::export(intval($inv->id));
        $row = self::row(self::get(intval($inv->id)), false);
        if (is_wp_error($r)) return new WP_Error('tix_export_failed', $r->get_error_message(), ['status' => 502, 'export' => $row['export']]);
        return rest_ensure_response(['ok' => true, 'export' => $row['export']]);
    }

    /** POST /orders/{id}/invoices/issue → {ok, created [number], pending [issuer], invoices} (bezahlte Bestellung, auch vor dem Einschalten) */
    public static function rest_issue(WP_REST_Request $req) {
        if (!self::enabled()) return self::err('tix_invoices_off', 'Rechnungen sind auf dieser Seite nicht eingeschaltet.', 409);
        $o = class_exists('TIX_Order') ? TIX_Order::get(intval($req['id'])) : null;
        if (!$o) return self::err('tix_order_missing', 'Bestellung nicht gefunden.', 404);
        if (self::multi() && !current_user_can('manage_options') && !TIX_App_Scope::order_allowed($o)) return self::err('rest_forbidden', 'Kein Zugriff auf diese Bestellung.', 403);
        if (!in_array($o->get_status(), ['completed', 'processing'], true)) return self::err('tix_order_unpaid', 'Rechnungen gibt es nur für bezahlte Bestellungen.', 409);
        if (strpos((string) $o->get_payment_method(), 'pos_') === 0 && get_option('tix_invoice_pos', '0') !== '1') return self::err('tix_invoice_pos', 'Verkäufe an der Abendkasse bekommen keine Rechnung.', 409);
        if (class_exists('TIX_Sales') && TIX_Sales::is_sandbox_order($o->get_id())) return self::err('tix_invoice_sandbox', 'Demo- und Vorschau-Bestellungen bekommen keine Rechnung.', 409);
        $r = self::issue_for_order($o->get_id());
        $nums = [];
        foreach ($r['created'] as $id) { $x = self::get($id); if ($x) $nums[] = $x->number; }
        $pending = array_values(array_unique($r['pending']));
        $msg = $nums ? (count($nums) === 1 ? 'Rechnung ' . $nums[0] . ' erstellt.' : count($nums) . ' Belege erstellt.')
            : ($pending ? 'Die Rechnungsdaten sind unvollständig. Bitte ergänzen (oder „Nummer folgt“ wählen) und dann noch einmal erstellen.'
            : (self::for_order($o->get_id()) ? 'Die Bestellung hat schon ihre Rechnung.' : 'Für diese Bestellung ist keine Rechnung nötig (Betrag 0 €).'));
        return rest_ensure_response(['ok' => true, 'created' => $nums, 'pending' => $pending, 'message' => $msg, 'invoices' => self::order_summary($o->get_id())]);
    }

    /** Neuausstellung + optional Versand an die Rechnungs-E-Mail der Bestellung. → [result, sent] | WP_Error */
    private static function reissue_one($inv, $send) {
        $r = self::reissue($inv);
        if (is_wp_error($r)) return $r;
        $sent = false;
        if ($send && is_email((string) $r['new']->recipient_email)) $sent = self::send($r['new'], (string) $r['new']->recipient_email);
        return [$r, $sent];
    }

    /** POST /invoices/{id}/reissue {send?} → {ok, replaced, invoice, storno, sent, tax_id_missing{}} (1.38.525) */
    public static function rest_reissue(WP_REST_Request $req) {
        $inv = self::get(intval($req['id']));
        if (!$inv) return self::err('tix_invoice_missing', 'Beleg nicht gefunden.', 404);
        if (!self::manages($inv)) return self::err('rest_forbidden', 'Keine Berechtigung für diesen Beleg.', 403);
        $x = self::reissue_one($inv, filter_var($req->get_param('send'), FILTER_VALIDATE_BOOLEAN));
        if (is_wp_error($x)) return $x;
        list($r, $sent) = $x;
        return rest_ensure_response(['ok' => true, 'replaced' => (string) $inv->number, 'invoice' => self::row($r['new']), 'storno' => $r['storno'] ? self::row($r['storno']) : null,
            'sent' => $sent, 'tax_id_missing' => self::tax_id_payload($inv->issuer_key)]);
    }

    /**
     * POST /studio/invoices/reissue {send?, limit? (1–100, Vorgabe 50), dry_run?} → {ok, reissued[{replaced, number, storno, sent}], failed[{number, code, message}],
     * tax_id_missing{}} – alle offenen Rechnungen ohne Steuernummer des eigenen Ausstellers (Admin: ?issuer= bzw. ?organizer=).
     */
    public static function rest_reissue_all(WP_REST_Request $req) {
        $issuer = self::scope_issuer($req);
        if ($issuer === null) return self::err('rest_forbidden', 'Dieses Konto ist keinem Veranstalter zugeordnet.', 403);
        $i = self::issuer($issuer);
        if ($i['tax_id'] === '' && $i['vat_id'] === '') return self::err('tix_tax_id_missing', 'Bitte zuerst die Steuernummer bzw. USt-IdNr. eintragen.', 409, 'tax_id');
        $limit = max(1, min(100, intval($req->get_param('limit') ?: 50)));
        $stats = self::tax_id_missing_stats($issuer, $limit);
        $out = ['ok' => true, 'dry_run' => false, 'reissued' => [], 'failed' => []];
        if (filter_var($req->get_param('dry_run'), FILTER_VALIDATE_BOOLEAN)) {
            $out['dry_run'] = true;
            $out['would_reissue'] = array_values(array_map(function ($id) { return (string) self::get($id)->number; }, $stats['ids']));
            $out['tax_id_missing'] = self::tax_id_payload($issuer);
            return rest_ensure_response($out);
        }
        $send = filter_var($req->get_param('send'), FILTER_VALIDATE_BOOLEAN);
        foreach ($stats['ids'] as $id) {
            $inv = self::get($id);
            $x = self::reissue_one($inv, $send);
            if (is_wp_error($x)) { $out['failed'][] = ['number' => (string) $inv->number, 'code' => $x->get_error_code(), 'message' => $x->get_error_message()]; continue; }
            list($r, $sent) = $x;
            $out['reissued'][] = ['replaced' => (string) $inv->number, 'number' => (string) $r['new']->number, 'storno' => $r['storno'] ? (string) $r['storno']->number : null, 'sent' => $sent];
        }
        $out['tax_id_missing'] = self::tax_id_payload($issuer);
        return rest_ensure_response($out);
    }

    /** Studio-Block „Rechnungen ohne Steuernummer“: {open, open_gross, cancelled, can_reissue, message} */
    public static function tax_id_payload($issuer_key) {
        $st = self::tax_id_missing_stats($issuer_key);
        $i = self::issuer($issuer_key);
        $can = $i['tax_id'] !== '' || $i['vat_id'] !== '';
        $msg = null;
        if ($st['open'] > 0) {
            $msg = $st['open'] === 1 ? '1 Rechnung ohne Steuernummer' : $st['open'] . ' Rechnungen ohne Steuernummer';
            $msg .= $can ? ' – jetzt korrigieren' : ' – trag die Nummer ein, dann kannst du sie neu ausstellen';
        }
        return ['open' => $st['open'], 'open_gross' => $st['open_gross'], 'cancelled' => $st['cancelled'], 'can_reissue' => $can && $st['open'] > 0, 'message' => $msg];
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $open = ['permission_callback' => '__return_true'];
        register_rest_route(self::NS, '/customer/orders/(?P<id>\d+)/invoices', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_order_invoices']] + $open);
        register_rest_route(self::NS, '/invoices/(?P<id>\d+)/pdf', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_pdf']] + $open);
        register_rest_route(self::NS, '/invoices/(?P<id>\d+)/xml', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_xml']] + $open);
        register_rest_route(self::NS, '/organizer/invoices', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => [__CLASS__, 'perm_manager']]);
        register_rest_route(self::NS, '/invoices/(?P<id>\d+)/send', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_send'], 'permission_callback' => [__CLASS__, 'perm_manager']]);
        register_rest_route(self::NS, '/invoices/(?P<id>\d+)/export', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_export'], 'permission_callback' => [__CLASS__, 'perm_manager']]);
        register_rest_route(self::NS, '/orders/(?P<id>\d+)/invoices/issue', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_issue'], 'permission_callback' => [__CLASS__, 'perm_manager']]);
        register_rest_route(self::NS, '/invoices/(?P<id>\d+)/reissue', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_reissue'], 'permission_callback' => [__CLASS__, 'perm_manager']]);
        register_rest_route(self::NS, '/studio/invoices/reissue', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_reissue_all'], 'permission_callback' => [__CLASS__, 'perm_manager']]);
        register_rest_route(self::NS, '/studio/invoice-config', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_config'], 'permission_callback' => [__CLASS__, 'perm_manager']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save_config'], 'permission_callback' => [__CLASS__, 'perm_manager']],
        ]);
    }

    private static function err($code, $msg, $status, $field = '') {
        return new WP_Error($code, $msg, ['status' => $status] + ($field !== '' ? ['field' => $field] : []));
    }

    public static function perm_manager(WP_REST_Request $req) {
        if (class_exists('TIX_REST_API') && method_exists('TIX_REST_API', 'check_authenticated')) {
            $r = TIX_REST_API::check_authenticated($req);
            if ($r !== true) return $r;
        } elseif (!is_user_logged_in()) {
            return self::err('rest_not_logged_in', 'Authentifizierung erforderlich.', 401);
        }
        $u = wp_get_current_user();
        if ($u->has_cap('manage_options')) return true;
        if (self::multi()) return TIX_App_Scope::is_org_manager() ? true : self::err('rest_forbidden', 'Nur der Veranstalter selbst sieht seine Rechnungen.', 403);
        return in_array('tix_organizer', (array) $u->roles, true) ? true : self::err('rest_forbidden', 'Keine Berechtigung.', 403);
    }

    /** Aussteller, den der Aufrufer verwaltet (Admin: ?issuer=site|platform|org:<id> bzw. ?organizer=). */
    private static function scope_issuer(WP_REST_Request $req, $allow_all = false) {
        $admin = current_user_can('manage_options');
        if (!self::multi()) return 'site';
        if ($admin) {
            $i = (string) ($req->get_param('issuer') ?? '');
            $org = intval($req->get_param('organizer') ?? ($req->get_param('organizer_id') ?? 0));
            if ($org) return 'org:' . $org;
            if ($i === 'platform' || preg_match('/^org:\d+$/', $i)) return $i;
            return $allow_all ? '' : 'platform';
        }
        $oid = TIX_App_Scope::organizer_id_for_user();
        return $oid ? 'org:' . $oid : null;
    }

    /** Darf der Aufrufer den Beleg sehen? (eigene Bestellung, Bestell-Schlüssel, Aussteller, Admin) */
    private static function can_view($inv, WP_REST_Request $req) {
        $user = is_user_logged_in() ? wp_get_current_user() : null;
        if ($user && $user->has_cap('manage_options')) return true;
        $o = self::order_row($inv->order_id);
        if ($o && $user) {
            $trusted = class_exists('TIX_App_Account') ? (string) TIX_App_Account::trusted_email($user) : '';
            if ((intval($o->customer_id) > 0 && intval($o->customer_id) === intval($user->ID)) || ($trusted !== '' && strcasecmp((string) $o->billing_email, $trusted) === 0)) return true;
            if (self::multi() && strpos($inv->issuer_key, 'org:') === 0 && TIX_App_Scope::is_org_manager() && 'org:' . TIX_App_Scope::organizer_id_for_user() === $inv->issuer_key) return true;
            // Studio: Inhaber/Team sieht die Belege von Bestellungen seiner Events (Liste je Bestellung)
            if ($inv->issuer_key === '' && self::multi() && TIX_App_Scope::is_org_manager() && class_exists('TIX_Order') && ($ord = TIX_Order::get(intval($o->id))) && TIX_App_Scope::order_allowed($ord)) return true;
            if (!self::multi() && in_array('tix_organizer', (array) $user->roles, true)) return true;
        }
        $key = sanitize_text_field((string) ($req->get_param('key') ?? ''));
        return $o && $key !== '' && hash_equals((string) $o->order_key, $key);
    }

    public static function row($inv, $with_lines = true) {
        $d = json_decode((string) $inv->data, true);
        $out = [
            'id' => intval($inv->id), 'number' => (string) $inv->number, 'kind' => (string) $inv->kind, 'series' => (string) $inv->series,
            'issuer' => ['key' => (string) $inv->issuer_key, 'name' => (string) ($d['issuer']['name'] ?? ''), 'organizer_id' => intval($inv->organizer_id) ?: null],
            'order_id' => intval($inv->order_id), 'order_number' => (string) $inv->order_number,
            'ref' => !empty($d['ref']) ? ['id' => intval($d['ref']['id']), 'number' => (string) $d['ref']['number']] : null,
            'reason' => (string) $inv->reason,
            'issued_at' => (string) $inv->created, 'service_date' => $inv->service_date ? (string) $inv->service_date : null,
            'recipient' => ['name' => (string) ($d['recipient']['name'] ?? ''), 'company' => (string) ($d['recipient']['company'] ?? ''), 'email' => (string) $inv->recipient_email],
            'currency' => 'EUR', 'net' => round(floatval($inv->net), 2), 'tax' => round(floatval($inv->tax), 2), 'gross' => round(floatval($inv->gross), 2),
            'small_business' => (bool) $inv->small_business,
            'taxes' => (array) ($d['taxes'] ?? []),
            'payments' => (array) ($d['payments'] ?? []),
            'pdf' => rest_url(self::NS . '/invoices/' . intval($inv->id) . '/pdf'),
            'xml' => (in_array($inv->series, ['R', 'RK'], true) && empty($inv->tax_id_missing)) ? rest_url(self::NS . '/invoices/' . intval($inv->id) . '/xml') : null,
            // ohne Steuernummer/USt-IdNr. ausgestellt (1.38.525); replaces = ersetzte Rechnung, replaced_by = neue Rechnung
            'tax_id_missing' => !empty($inv->tax_id_missing),
            'replaces' => !empty($d['replaces']) ? ['id' => intval($d['replaces']['id']), 'number' => (string) $d['replaces']['number']] : null,
            'replaced_by' => (!empty($inv->tax_id_missing) && $inv->series === 'R' && ($rb = self::replacement_of($inv->id))) ? ['id' => intval($rb->id), 'number' => (string) $rb->number] : (!empty($d['replaced_by']) ? ['id' => intval($d['replaced_by']['id']), 'number' => (string) $d['replaced_by']['number']] : null),
            // Übergabe an die Buchhaltung (1.38.500): pending | done | error | skipped
            'export' => !empty($inv->export_provider) ? ['provider' => (string) $inv->export_provider, 'status' => (string) $inv->export_status, 'ref' => (string) $inv->export_ref ?: null,
                'error' => (string) $inv->export_error ?: null, 'at' => !empty($inv->export_at) ? (string) $inv->export_at : null] : null,
        ];
        if ($with_lines) $out['lines'] = array_map(function ($l) { return ['type' => $l['type'], 'text' => $l['text'], 'detail' => $l['detail'], 'qty' => intval($l['qty']), 'unit' => round(floatval($l['unit']), 2), 'gross' => round(floatval($l['gross']), 2), 'rate' => $l['rate'] === null ? null : floatval($l['rate'])]; }, (array) ($d['lines'] ?? []));
        return $out;
    }

    /** GET /customer/orders/{id}/invoices → {ok, order_id, enabled, pending, invoices[]} (Konto oder ?key=order_key) */
    public static function rest_order_invoices(WP_REST_Request $req) {
        $o = self::order_row(intval($req['id']));
        if (!$o) return self::err('tix_order_missing', 'Bestellung nicht gefunden.', 404);
        $probe = (object) ['order_id' => intval($o->id), 'issuer_key' => ''];
        if (!self::can_view($probe, $req)) return self::err(is_user_logged_in() ? 'rest_forbidden' : 'rest_not_logged_in', is_user_logged_in() ? 'Keine Berechtigung.' : 'Authentifizierung erforderlich.', is_user_logged_in() ? 403 : 401);
        $list = array_map(function ($r) { return self::row($r); }, self::for_order(intval($o->id)));
        return rest_ensure_response(['ok' => true, 'order_id' => intval($o->id), 'enabled' => self::enabled(),
            'pending' => in_array(intval($o->id), self::pending_orders(), true), 'invoices' => $list]);
    }

    /** GET /invoices/{id}/pdf → {ok, filename, mime, data (base64)} */
    public static function rest_pdf(WP_REST_Request $req) {
        $inv = self::get(intval($req['id']));
        if (!$inv) return self::err('tix_invoice_missing', 'Beleg nicht gefunden.', 404);
        if (!self::can_view($inv, $req)) return self::err(is_user_logged_in() ? 'rest_forbidden' : 'rest_not_logged_in', is_user_logged_in() ? 'Keine Berechtigung.' : 'Authentifizierung erforderlich.', is_user_logged_in() ? 403 : 401);
        list($bytes, $name, $stored) = self::pdf($inv);
        if (!$bytes) return self::err('tix_invoice_pdf', 'Das PDF ist gerade nicht verfügbar.', 500);
        return rest_ensure_response(['ok' => true, 'filename' => $name, 'mime' => 'application/pdf', 'stored' => $stored, 'data' => base64_encode($bytes)]);
    }

    /** GET /invoices/{id}/xml → {ok, filename, mime, profile, data} – E-Rechnung (EN 16931, CII); Belege ohne USt: 404 tix_invoice_no_xml */
    public static function rest_xml(WP_REST_Request $req) {
        $inv = self::get(intval($req['id']));
        if (!$inv) return self::err('tix_invoice_missing', 'Beleg nicht gefunden.', 404);
        if (!self::can_view($inv, $req)) return self::err(is_user_logged_in() ? 'rest_forbidden' : 'rest_not_logged_in', is_user_logged_in() ? 'Keine Berechtigung.' : 'Authentifizierung erforderlich.', is_user_logged_in() ? 403 : 401);
        if (!empty($inv->tax_id_missing)) return self::err('tix_invoice_no_xml_tax_id', 'Ohne Steuernummer bzw. USt-IdNr. gibt es keine E-Rechnung. Nach dem Nachtrag die Rechnung neu ausstellen.', 409);
        if (!class_exists('TIX_EInvoice') || !TIX_EInvoice::supported($inv)) return self::err('tix_invoice_no_xml', 'Für diesen Beleg gibt es keine E-Rechnung (Beleg ohne Umsatzsteuer).', 404);
        $xml = TIX_EInvoice::xml($inv);
        return rest_ensure_response(['ok' => true, 'filename' => TIX_EInvoice::filename($inv), 'mime' => 'application/xml', 'profile' => 'EN 16931 (ZUGFeRD/Factur-X, CII)', 'data' => base64_encode($xml)]);
    }

    /** GET /organizer/invoices?from&to&q&kind&page&per_page&format=csv|datev */
    public static function rest_list(WP_REST_Request $req) {
        global $wpdb;
        self::maybe_install();
        $issuer = self::scope_issuer($req, true);
        if ($issuer === null) return self::err('rest_forbidden', 'Dieses Konto ist keinem Veranstalter zugeordnet.', 403);
        $t = self::table();
        $where = ['1=1'];
        if ($issuer !== '' && self::multi()) $where[] = $wpdb->prepare('issuer_key = %s', $issuer);
        $from = (string) $req->get_param('from'); $to = (string) $req->get_param('to');
        if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) return self::err('tix_invalid', 'from: JJJJ-MM-TT', 400, 'from');
        if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) return self::err('tix_invalid', 'to: JJJJ-MM-TT', 400, 'to');
        if ($from !== '') $where[] = $wpdb->prepare('created >= %s', $from . ' 00:00:00');
        if ($to !== '') $where[] = $wpdb->prepare('created <= %s', $to . ' 23:59:59');
        $kind = (string) $req->get_param('kind');
        if ($kind !== '') {
            if (!in_array($kind, ['invoice', 'correction', 'receipt'], true)) return self::err('tix_invalid', 'kind: invoice|correction|receipt', 400, 'kind');
            $where[] = $wpdb->prepare('kind = %s', $kind);
        }
        if (filter_var($req->get_param('tax_id_missing'), FILTER_VALIDATE_BOOLEAN)) $where[] = 'tax_id_missing = 1';
        $q = trim((string) $req->get_param('q'));
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $norm = '%' . $wpdb->esc_like(class_exists('TIX_Order_Number') ? TIX_Order_Number::normalize($q) : strtoupper($q)) . '%';
            $where[] = $wpdb->prepare("(number LIKE %s OR order_number LIKE %s OR REPLACE(REPLACE(UPPER(order_number),'-',''),' ','') LIKE %s OR recipient_name LIKE %s OR recipient_email LIKE %s)", $like, $like, $norm, $like, $like);
        }
        $w = implode(' AND ', $where);
        $format = (string) $req->get_param('format');
        if ($format === 'csv' || $format === 'datev') {
            $rows = (array) $wpdb->get_results("SELECT * FROM $t WHERE $w ORDER BY created ASC, id ASC LIMIT 20000");
            $file = $format === 'csv' ? TIX_Invoice_Export::csv($rows) : TIX_Invoice_Export::datev($rows, $issuer ?: 'site', $from, $to);
            return rest_ensure_response(['ok' => true, 'filename' => $file['filename'], 'mime' => 'text/csv', 'rows' => count($rows), 'data' => base64_encode($file['data'])]);
        }
        $per = max(1, min(200, intval($req->get_param('per_page') ?: 50)));
        $page = max(1, intval($req->get_param('page') ?: 1));
        $tot = $wpdb->get_row("SELECT COUNT(*) n, COALESCE(SUM(net),0) net, COALESCE(SUM(tax),0) tax, COALESCE(SUM(gross),0) gross FROM $t WHERE $w");
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE $w ORDER BY created DESC, id DESC LIMIT %d OFFSET %d", $per, ($page - 1) * $per));
        return rest_ensure_response([
            'ok' => true, 'issuer' => $issuer === '' ? null : $issuer, 'enabled' => self::enabled(),
            'totals' => ['count' => intval($tot->n), 'net' => round(floatval($tot->net), 2), 'tax' => round(floatval($tot->tax), 2), 'gross' => round(floatval($tot->gross), 2)],
            'pending' => count(self::pending_orders($issuer ?: '')),
            'tax_id_missing_open' => self::tax_id_missing_stats($issuer ?: '')['open'],
            'without_invoice' => self::missing_payload($issuer ?: ''),
            'page' => $page, 'per_page' => $per, 'total' => intval($tot->n), 'has_more' => intval($tot->n) > $page * $per,
            'invoices' => array_map(function ($r) { return self::row($r, false); }, $rows),
        ]);
    }

    const COMPANY_KEYS = ['name' => 'invoice_company_name', 'address' => 'invoice_company_address', 'tax_id' => 'invoice_company_tax_id',
        'vat_id' => 'invoice_company_ust_id', 'email' => 'invoice_email', 'phone' => 'invoice_phone', 'managing_director' => 'invoice_managing_director',
        'register_court' => 'invoice_register_court', 'register_number' => 'invoice_register_number', 'footer' => 'invoice_footer_text'];
    const DATEV_KEYS = ['consultant', 'client', 'account_length', 'debitor', 'revenue_19', 'revenue_7', 'revenue_0', 'voucher'];

    public static function datev_config($issuer_key) {
        $d = (array) (self::config($issuer_key)['datev'] ?? []);
        return [
            'consultant' => (string) ($d['consultant'] ?? ''), 'client' => (string) ($d['client'] ?? ''),
            'account_length' => intval($d['account_length'] ?? 4) ?: 4, 'debitor' => (string) ($d['debitor'] ?? '10000'),
            'revenue_19' => (string) ($d['revenue_19'] ?? '8400'), 'revenue_7' => (string) ($d['revenue_7'] ?? '8300'),
            'revenue_0' => (string) ($d['revenue_0'] ?? '8200'), 'voucher' => (string) ($d['voucher'] ?? '1700'),
        ];
    }

    private static function config_payload($issuer_key) {
        global $wpdb;
        self::maybe_install();
        $i = self::issuer($issuer_key);
        $cfg = self::config($issuer_key);
        $s = self::site_settings();
        $year = intval(wp_date('Y'));
        $counters = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT series, last FROM " . self::counters_table() . " WHERE issuer_key = %s AND year = %d", $issuer_key, $year)) as $r) $counters[$r->series] = intval($r->last);
        $company = [];
        if (strpos($issuer_key, 'org:') !== 0) foreach (self::COMPANY_KEYS as $k => $sk) $company[$k] = (string) ($s[$sk] ?? '');
        $num = self::numbering_payload($issuer_key);
        return [
            'ok' => true,
            'enabled' => self::enabled(),
            'issuer_model' => self::multi() ? self::model() : null,
            'issuer' => [
                'key' => $issuer_key, 'name' => $i['name'], 'address_lines' => $i['address_lines'], 'tax_id' => $i['tax_id'], 'vat_id' => $i['vat_id'],
                'tax_id_follows' => $i['tax_id_follows'] ?: null,
                'small_business' => (bool) $i['small_business'], 'complete' => $i['complete'], 'missing' => $i['missing'],
                'source' => strpos($issuer_key, 'org:') === 0 ? 'payout_details' : 'settings',
            ],
            'code' => $i['code'], 'code_set' => (string) ($cfg['code'] ?? '') !== '',
            'tax_rate' => floatval($i['tax_rate']), 'tax_rate_set' => self::clean_rate($cfg['tax_rate'] ?? null) !== null,
            // „Nummer folgt“ (1.38.525): gewählt (vat_id|tax_number|null), wirksam solange beide Nummern fehlen, (i)-Text, offene Rechnungen ohne Nummer
            'tax_id_follows' => self::follows_setting($issuer_key) ?: null,
            'tax_id_follows_active' => $i['tax_id_follows'] !== '',
            'tax_id_info' => self::TAX_ID_INFO,
            'tax_id_missing' => self::tax_id_payload($issuer_key),
            // Bestellungen ohne Rechnung (1.38.526): nur Hinweis, Erstellen per Klick über POST /orders/{id}/invoices/issue
            'without_invoice' => self::missing_payload($issuer_key),
            'company' => $company ?: null,
            'datev' => self::datev_config($issuer_key),
            // Übergabe an die Buchhaltung (1.38.500): {provider none|lexoffice, key_set, key_last4, use_site_key, site_key_available, category_id, since}
            'accounting' => class_exists('TIX_Invoice_Accounting') ? TIX_Invoice_Accounting::payload($issuer_key) : null,
            // Nummernkreis (1.38.526): next = Vorschau der nächsten Nummer je Serie; numbering = Aufbau zum Bearbeiten
            'next' => ['R' => $num['R']['preview'], 'RK' => $num['RK']['preview'], 'B' => $num['B']['preview']],
            'numbering' => $num,
            'counters' => (object) $counters,
            'pending' => count(self::pending_orders($issuer_key)),
        ];
    }

    /** GET /studio/invoice-config (Admin: ?issuer=platform|org:<id> bzw. ?organizer=) */
    public static function rest_config(WP_REST_Request $req) {
        $issuer = self::scope_issuer($req);
        if ($issuer === null) return self::err('rest_forbidden', 'Dieses Konto ist keinem Veranstalter zugeordnet.', 403);
        return rest_ensure_response(self::config_payload($issuer));
    }

    /**
     * POST /studio/invoice-config {code?, tax_rate?, small_business?, tax_id_follows?, numbering{R|RK|B|BK: {prefix?, year?, digits?, next?} | null}?,
     *   company{}?, datev{}?, enabled?, issuer_model?}
     * company/small_business nur Seite/Plattform (Veranstalter: Auszahlungsdaten); enabled/issuer_model nur Admin.
     */
    public static function rest_save_config(WP_REST_Request $req) {
        $issuer = self::scope_issuer($req);
        if ($issuer === null) return self::err('rest_forbidden', 'Dieses Konto ist keinem Veranstalter zugeordnet.', 403);
        $p = $req->get_json_params();
        if (!is_array($p) || !$p) $p = $req->get_body_params() ?: $req->get_params();
        $admin = current_user_can('manage_options');
        $is_org = strpos($issuer, 'org:') === 0;
        $cfg = self::config($issuer);
        if (array_key_exists('code', $p)) {
            $code = strtoupper(trim((string) $p['code']));
            if ($code !== '' && !preg_match('/^[A-Z0-9]{2,6}$/', $code)) return self::err('tix_invalid', 'Kürzel: 2–6 Zeichen A–Z/0–9.', 400, 'code');
            if ($code !== '' && self::code_taken($code, $issuer)) return self::err('tix_code_taken', 'Dieses Kürzel nutzt schon ein anderer Aussteller.', 409, 'code');
            if ($code === '') unset($cfg['code']); else $cfg['code'] = $code;
        }
        if (array_key_exists('tax_rate', $p)) {
            if ($p['tax_rate'] === null || $p['tax_rate'] === '') unset($cfg['tax_rate']);
            else {
                $r = self::clean_rate($p['tax_rate']);
                if ($r === null) return self::err('tix_invalid', 'Steuersatz: Zahl von 0 bis 30.', 400, 'tax_rate');
                $cfg['tax_rate'] = $r;
            }
        }
        if (array_key_exists('small_business', $p)) {
            if ($is_org) return self::err('tix_invalid', 'Kleinunternehmer stellst du in den Auszahlungsdaten ein (Steuer-Status).', 400, 'small_business');
            $cfg['small_business'] = filter_var($p['small_business'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        $num_apply = [];
        if (array_key_exists('numbering', $p)) {
            if (!is_array($p['numbering'])) return self::err('tix_invalid', 'numbering: Objekt je Serie erwartet (R, RK, B, BK).', 400, 'numbering');
            $cur = (array) ($cfg['numbering'] ?? []);
            $code_now = isset($cfg['code']) ? (string) $cfg['code'] : self::code($issuer);
            $new = []; $nexts = [];
            foreach ($p['numbering'] as $sr => $in) {
                $sr = (string) $sr;
                if (!in_array($sr, self::SERIES, true)) return self::err('tix_invalid', 'numbering: Serie ' . $sr . ' unbekannt (R, RK, B, BK).', 400, 'numbering.' . $sr);
                if ($in === null || $in === 'default' || (is_array($in) && !empty($in['reset']))) {
                    unset($cur[$sr]);
                    $new[$sr] = ['prefix' => $sr . '-' . $code_now . '-', 'year' => true, 'digits' => 5];
                } elseif (is_array($in)) {
                    $f = self::numbering($issuer, $sr);
                    if (array_key_exists('prefix', $in)) $f['prefix'] = trim((string) $in['prefix']);
                    if (array_key_exists('year', $in)) $f['year'] = filter_var($in['year'], FILTER_VALIDATE_BOOLEAN);
                    if (array_key_exists('digits', $in)) {
                        $dg = filter_var($in['digits'], FILTER_VALIDATE_INT);
                        if ($dg === false || $dg < 3 || $dg > 10) return self::err('tix_invalid', 'Stellen: 3 bis 10.', 400, 'numbering.' . $sr . '.digits');
                        $f['digits'] = $dg;
                    }
                    $cur[$sr] = ['prefix' => $f['prefix'], 'year' => $f['year'] ? 1 : 0, 'digits' => intval($f['digits'])];
                    $new[$sr] = $f;
                } else {
                    return self::err('tix_invalid', 'numbering.' . $sr . ': Objekt oder null erwartet.', 400, 'numbering.' . $sr);
                }
                if (is_array($in) && array_key_exists('next', $in) && $in['next'] !== null && $in['next'] !== '') {
                    $nx = filter_var($in['next'], FILTER_VALIDATE_INT);
                    if ($nx === false || $nx < 1 || $nx > 999999999) return self::err('tix_invalid', 'Nächste Nummer: ganze Zahl ab 1.', 400, 'numbering.' . $sr . '.next');
                    $nexts[$sr] = $nx;
                }
            }
            foreach ($new as $sr => $f) {
                $ok = self::check_numbering($issuer, $sr, $f, $new);
                if (is_wp_error($ok)) return $ok;
            }
            foreach ($nexts as $sr => $nx) {
                $min = self::min_next($issuer, $sr, $new[$sr]);
                if ($nx < $min) return new WP_Error('tix_number_too_low', 'Die nächste Nummer muss mindestens ' . $min . ' sein – kleinere Nummern sind schon vergeben (GoBD: lückenlos, keine Wiederholung).', ['status' => 409, 'field' => 'numbering.' . $sr . '.next', 'min_next' => $min]);
            }
            foreach ($new as $sr => $f) $num_apply[$sr] = $nexts[$sr] ?? self::min_next($issuer, $sr, $f);
            if ($cur) $cfg['numbering'] = $cur; else unset($cfg['numbering']);
        }
        if (array_key_exists('tax_id_follows', $p)) {
            $f = $p['tax_id_follows'];
            if ($f === true || $f === 'true' || $f === '1' || $f === 1) {
                // ohne Angabe: was der Steuer-Status nahelegt, sonst USt-IdNr.
                $ts = $is_org && class_exists('TIX_Payout_Details') ? (string) (TIX_Payout_Details::data(intval(substr($issuer, 4)))['tax_status'] ?? '') : '';
                $f = $ts === 'tax_number' ? 'tax_number' : 'vat_id';
            }
            if ($f === null || $f === false || $f === '' || $f === 'false' || $f === '0' || $f === 0) unset($cfg['tax_id_follows']);
            elseif (in_array($f, ['vat_id', 'tax_number'], true)) $cfg['tax_id_follows'] = $f;
            else return self::err('tix_invalid', 'tax_id_follows: vat_id, tax_number oder leer.', 400, 'tax_id_follows');
            if (!$is_org && !$admin && self::multi()) return self::err('rest_forbidden', 'Nur Admins.', 403, 'tax_id_follows');
        }
        if (array_key_exists('datev', $p)) {
            if (!is_array($p['datev'])) return self::err('tix_invalid', 'datev: Objekt erwartet.', 400, 'datev');
            $d = (array) ($cfg['datev'] ?? []);
            foreach (self::DATEV_KEYS as $k) {
                if (!array_key_exists($k, $p['datev'])) continue;
                $v = trim((string) $p['datev'][$k]);
                if ($v !== '' && !preg_match('/^\d{1,9}$/', $v)) return self::err('tix_invalid', 'datev.' . $k . ': nur Ziffern.', 400, 'datev.' . $k);
                if ($k === 'account_length' && $v !== '' && (intval($v) < 4 || intval($v) > 8)) return self::err('tix_invalid', 'datev.account_length: 4–8.', 400, 'datev.account_length');
                $d[$k] = $v;
            }
            $cfg['datev'] = $d;
        }
        if (array_key_exists('accounting', $p) && class_exists('TIX_Invoice_Accounting')) {
            $cfg = TIX_Invoice_Accounting::apply($issuer, $cfg, $p['accounting']);
            if (is_wp_error($cfg)) return $cfg;
        }
        $company = null;
        if (array_key_exists('company', $p)) {
            if ($is_org) return self::err('tix_invalid', 'Firmendaten kommen aus den Auszahlungsdaten.', 400, 'company');
            if (!$admin && self::multi()) return self::err('rest_forbidden', 'Nur Admins.', 403);
            if (!is_array($p['company'])) return self::err('tix_invalid', 'company: Objekt erwartet.', 400, 'company');
            $company = $p['company'];
            if (isset($company['vat_id']) && trim((string) $company['vat_id']) !== '' && !preg_match('/^[A-Z]{2}[A-Za-z0-9]{2,13}$/', strtoupper(str_replace(' ', '', (string) $company['vat_id'])))) {
                return self::err('tix_invalid', 'USt-IdNr. ungültig (z. B. DE123456789).', 400, 'company.vat_id');
            }
        }
        foreach (['enabled', 'issuer_model'] as $k) {
            if (array_key_exists($k, $p) && !$admin) return self::err('rest_forbidden', 'Nur Admins dürfen ' . $k . ' ändern.', 403, $k);
        }
        if (array_key_exists('issuer_model', $p) && !in_array((string) $p['issuer_model'], ['organizer', 'platform'], true)) return self::err('tix_invalid', 'issuer_model: organizer|platform', 400, 'issuer_model');

        self::save_config($issuer, $cfg);
        // Nummernkreis: Zähler nur vorwärts stellen (nie unter bereits vergebene Nummern)
        global $wpdb;
        foreach ($num_apply as $sr => $nx) {
            $cy = self::counter_year(self::numbering($issuer, $sr), intval(wp_date('Y')));
            $wpdb->query($wpdb->prepare("INSERT INTO " . self::counters_table() . " (issuer_key, series, year, last) VALUES (%s, %s, %d, %d) ON DUPLICATE KEY UPDATE last = GREATEST(last, VALUES(last))",
                $issuer, $sr, $cy, max(0, intval($nx) - 1)));
        }
        if ($company !== null) {
            $s = self::site_settings();
            foreach (self::COMPANY_KEYS as $k => $sk) {
                if (!array_key_exists($k, $company)) continue;
                $v = (string) $company[$k];
                $s[$sk] = in_array($k, ['address', 'footer'], true) ? sanitize_textarea_field($v) : sanitize_text_field($k === 'vat_id' ? strtoupper(str_replace(' ', '', $v)) : $v);
            }
            update_option('tix_settings', $s);
        }
        if (array_key_exists('issuer_model', $p)) update_option(self::OPT_MODEL, (string) $p['issuer_model'], false);
        if (array_key_exists('enabled', $p)) {
            update_option(self::OPT_ENABLED, filter_var($p['enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0', false);
            if (self::enabled()) self::maybe_install();
        }
        return rest_ensure_response(self::config_payload($issuer));
    }
}

