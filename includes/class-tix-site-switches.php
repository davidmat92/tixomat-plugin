<?php
/**
 * TIX_Site_Switches – Funktionen der ganzen Seite im Studio (1.38.882, WP-Admin-Restliste W2 = V2, V2b, V13, V15).
 * Vertrag: uebergaben/vertrag-funktionen-der-seite.md, DOCUMENTATION.md §242.
 *
 * Eine Liste aller Schlüssel (DEFS), ein reiner Prüfer (clean), ein Schreiber in tix_settings (save, nur geänderte Schlüssel).
 * Ausgespielt über bestehende Routen:
 *   /studio/features         → site{…}, site_editable            (V2, Bereich 'features')
 *   /studio/checkout-settings → site{low_stock_threshold}        (V2b, Bereich 'checkout')
 *   /studio/invoice-config   → order_tax{enabled, rate, inclusive} (V13)
 *   /support/mode            → switches{enabled, chat_enabled, categories[]} (V15)
 * Rechte: Werte gelten für die ganze Seite. evendis: nur Plattform-Admin schreibt; Einzel-Seite: Admin und Inhaber (tix_organizer).
 */
if (!defined('ABSPATH')) exit;

class TIX_Site_Switches {

    /** key => [Bereich, Typ, Vorgabe, Bezeichnung, Gruppe, Extra] */
    const DEFS = [
        'ticket_transfer_enabled'   => ['features', 'bool', 0, 'Ticket umschreiben', 'verkauf', ['hint' => 'Käufer dürfen Tickets auf eine andere Person umschreiben. Je Event zusätzlich einschalten.']],
        'express_checkout_enabled'  => ['features', 'bool', 0, '1-Klick-Kauf', 'verkauf', ['hint' => 'Angemeldete Kunden kaufen mit einem Klick (gespeicherte Daten).']],
        'group_booking'             => ['features', 'bool', 1, 'Gemeinsam buchen', 'verkauf', ['hint' => 'Eine Person bestellt, die Gruppe bezahlt einzeln.']],
        'specials_enabled'          => ['features', 'bool', 0, 'Extras', 'verkauf', ['hint' => 'Zusatzartikel wie Getränkemarken oder Parkplatz zum Ticket.']],
        'table_reservation_enabled' => ['features', 'bool', 0, 'Tischreservierung', 'verkauf', ['hint' => 'Tische mit Mindestverzehr. Je Event einstellen.']],
        'waitlist_enabled'          => ['features', 'bool', 1, 'Warteliste', 'event_seite', ['hint' => 'Erinnerung zum Vorverkaufsstart und Warteliste bei ausverkauften Events.']],
        'charity_enabled'           => ['features', 'bool', 0, 'Spenden-Hinweis', 'event_seite', ['hint' => 'Hinweis auf einen guten Zweck. Je Event einstellen.']],
        'barcode_enabled'           => ['features', 'bool', 0, 'Barcode auf dem Ticket', 'event_seite', ['hint' => 'Zusätzlich zum QR-Code, für ältere Scanner.']],
        'show_upsell'               => ['features', 'bool', 1, 'Ähnliche Events nach dem Kauf', 'nach_kauf', []],
        'upsell_count'              => ['features', 'int', 3, 'Anzahl ähnlicher Events', 'nach_kauf', ['min' => 1, 'max' => 6]],
        'upsell_heading'            => ['features', 'text', 'Das könnte dich auch interessieren', 'Überschrift ähnliche Events', 'nach_kauf', ['max_length' => 120]],
        'feedback_enabled'          => ['features', 'bool', 1, 'Bewertung nach dem Event', 'nach_kauf', ['hint' => 'Sterne und Kommentar in der Mail nach dem Event.']],
        'abandoned_cart_enabled'    => ['features', 'bool', 0, 'Erinnerung bei abgebrochenem Kauf', 'nach_kauf', ['hint' => 'Mail an Kunden, die die Kasse mit E-Mail-Adresse verlassen haben.']],
        'abandoned_cart_delay'      => ['features', 'int', 30, 'Erinnerung nach (Minuten)', 'nach_kauf', ['min' => 5, 'max' => 1440]],
        'abandoned_cart_subject'    => ['features', 'text', '', 'Betreff der Erinnerung', 'nach_kauf', ['max_length' => 150, 'hint' => 'Leer = Standard.']],
        'low_stock_threshold'       => ['checkout', 'int', 10, '„Nur noch wenige Tickets“ ab', 'kasse', ['min' => 0, 'max' => 999, 'hint' => '0 = nie anzeigen. Je Event im Editor änderbar.']],
        'tax_enabled'               => ['tax', 'bool', 0, 'MwSt. in Bestellungen ausweisen', 'steuer', []],
        'tax_rate'                  => ['tax', 'float', 19, 'MwSt.-Satz (%)', 'steuer', ['min' => 0, 'max' => 100]],
        'tax_inclusive'             => ['tax', 'bool', 1, 'Preise enthalten MwSt.', 'steuer', ['hint' => 'Normalfall: Bruttopreise.']],
        'support_enabled'           => ['support', 'bool', 0, 'Kundenanfragen', 'support', []],
        'support_chat_enabled'      => ['support', 'bool', 0, 'Chat', 'support', []],
        'support_categories'        => ['support', 'list', '', 'Themen', 'support', ['max_items' => 20, 'max_length' => 60, 'hint' => 'Leer = Standard-Themen.']],
    ];

    const GROUPS = ['verkauf' => 'Verkauf', 'event_seite' => 'Event-Seite & Ticket', 'nach_kauf' => 'Nach dem Kauf', 'kasse' => 'Kasse', 'steuer' => 'Steuer', 'support' => 'Kundenservice'];

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }

    /** Darf der aktuelle Nutzer Seiten-Werte schreiben? */
    public static function can_write() {
        if (current_user_can('manage_options')) return true;
        if (self::multi()) return false;
        return in_array('tix_organizer', (array) wp_get_current_user()->roles, true);
    }

    public static function keys($area) {
        return array_keys(array_filter(self::DEFS, function ($d) use ($area) { return $d[0] === $area; }));
    }

    private static function raw() {
        $s = get_option('tix_settings', []); // direkt, nicht tix_get_settings() (statischer Zwischenspeicher)
        return is_array($s) ? $s : [];
    }

    /** Wert eines Schlüssels in API-Form */
    public static function value($key, array $s = null) {
        $d = self::DEFS[$key];
        $s = $s ?? self::raw();
        $v = array_key_exists($key, $s) ? $s[$key] : $d[2];
        switch ($d[1]) {
            case 'bool': return !empty($v);
            case 'int': return intval($v);
            case 'float': return round(floatval($v), 2);
            case 'list': return array_values(array_column(self::categories_from_raw((string) $v), 'label'));
            default: return (string) $v;
        }
    }

    /** Themen aus dem gespeicherten Text (JSON-Liste oder je Zeile eine Bezeichnung) */
    public static function categories_from_raw($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') return [];
        $j = json_decode($raw, true);
        if (is_array($j)) return array_values(array_filter(array_map(function ($c) { return is_array($c) && isset($c['label']) ? ['slug' => (string) ($c['slug'] ?? sanitize_title($c['label'])), 'label' => (string) $c['label']] : null; }, $j)));
        return array_values(array_map(function ($l) { return ['slug' => sanitize_title($l), 'label' => $l]; }, array_filter(array_map('trim', explode("\n", $raw)), 'strlen')));
    }

    public static function categories_custom() {
        $raw = trim((string) (self::raw()['support_categories'] ?? ''));
        return $raw !== '' && is_array(json_decode($raw, true));
    }

    /** Beschreibung + Wert je Schlüssel eines Bereichs */
    public static function payload($area) {
        $s = self::raw();
        $out = [];
        foreach (self::keys($area) as $k) {
            $d = self::DEFS[$k];
            $out[$k] = ['value' => self::value($k, $s), 'type' => $d[1] === 'float' ? 'number' : $d[1], 'label' => $d[3], 'group' => $d[4], 'group_label' => self::GROUPS[$d[4]] ?? $d[4]] + $d[5];
        }
        return $out;
    }

    /** Rein: Eingabe {key: value} für einen Bereich prüfen → [sauber, null] | [null, feldname] */
    public static function clean(array $in, $area) {
        $out = [];
        foreach ($in as $k => $v) {
            if (!isset(self::DEFS[$k]) || self::DEFS[$k][0] !== $area) return [null, (string) $k];
            $d = self::DEFS[$k]; $x = $d[5];
            switch ($d[1]) {
                case 'bool':
                    if (!is_bool($v) && !in_array($v, [0, 1, '0', '1'], true)) return [null, $k];
                    $out[$k] = $v ? 1 : 0; break;
                case 'int':
                    if (!is_numeric($v) || intval($v) != $v || intval($v) < $x['min'] || intval($v) > $x['max']) return [null, $k];
                    $out[$k] = intval($v); break;
                case 'float':
                    if (!is_numeric($v) || floatval($v) < $x['min'] || floatval($v) > $x['max']) return [null, $k];
                    $out[$k] = round(floatval($v), 2); break;
                case 'text':
                    if (!is_string($v) || mb_strlen(trim($v)) > $x['max_length']) return [null, $k];
                    $out[$k] = sanitize_text_field($v); break;
                case 'list':
                    if (!is_array($v) || count($v) > $x['max_items']) return [null, $k];
                    $labels = [];
                    foreach ($v as $l) {
                        if (!is_string($l)) return [null, $k];
                        $l = sanitize_text_field($l);
                        if ($l === '' || mb_strlen($l) > $x['max_length']) return [null, $k];
                        if (!in_array($l, $labels, true)) $labels[] = $l;
                    }
                    $out[$k] = implode("\n", $labels); break;
            }
        }
        return [$out, null];
    }

    /** Prüfen + schreiben (nur mitgeschickte Schlüssel) → true | WP_Error. $prefix für die Feldangabe (z. B. „site.“). */
    public static function save(array $in, $area, $prefix = '') {
        if (!$in) return true;
        if (!self::can_write()) return new WP_Error('tix_platform_only', self::multi() ? 'Das gilt für die ganze Plattform und stellt evendis ein.' : 'Nur Inhaber bzw. Admin können das einstellen.', ['status' => 403]);
        [$clean, $bad] = self::clean($in, $area);
        if ($clean === null) {
            $known = isset(self::DEFS[$bad]) && self::DEFS[$bad][0] === $area;
            return new WP_Error('tix_invalid_field', $known ? 'Ungültiger Wert für ' . self::DEFS[$bad][3] . '.' : 'Unbekannte Einstellung: ' . $bad . '.', ['status' => 400, 'field' => $prefix . $bad]);
        }
        $s = self::raw();
        $old = [];
        foreach ($clean as $k => $v) { $old[$k] = $s[$k] ?? null; $s[$k] = $v; }
        update_option('tix_settings', $s);
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
        if (class_exists('TIX_Customers') && method_exists('TIX_Customers', 'audit')) {
            TIX_Customers::audit('site_settings', $area, ['scope' => self::multi() ? 'platform' : 'site', 'oid' => 0], ['old' => $old, 'new' => $clean]);
        }
        return true;
    }

    // ── Anbindung an die Routen ──

    /** Eingabe eines Bereichs aus dem Body holen (Objekt erwartet) → array | WP_Error | null (nicht mitgeschickt) */
    public static function input($body, $key) {
        if (!is_array($body) || !array_key_exists($key, $body)) return null;
        $v = $body[$key];
        if (is_string($v)) { $d = json_decode($v, true); $v = is_array($d) ? $d : $v; }
        if (!is_array($v)) return new WP_Error('tix_invalid_field', $key . ': Objekt erwartet.', ['status' => 400, 'field' => $key]);
        return $v;
    }

    /** /support/mode: {enabled, chat_enabled, categories} ↔ Schlüssel */
    const SUPPORT_MAP = ['enabled' => 'support_enabled', 'chat_enabled' => 'support_chat_enabled', 'categories' => 'support_categories'];
    /** /studio/invoice-config order_tax: {enabled, rate, inclusive} ↔ Schlüssel */
    const TAX_MAP = ['enabled' => 'tax_enabled', 'rate' => 'tax_rate', 'inclusive' => 'tax_inclusive'];

    public static function mapped_payload(array $map) {
        $s = self::raw(); $out = [];
        foreach ($map as $api => $k) $out[$api] = self::value($k, $s);
        return $out;
    }

    /** Eingabe mit API-Namen → Schlüssel; unbekannt → WP_Error */
    public static function mapped_save(array $in, array $map, $area, $prefix) {
        $keyed = [];
        foreach ($in as $api => $v) {
            if (!isset($map[$api])) return new WP_Error('tix_invalid_field', 'Unbekannte Einstellung: ' . $api . '.', ['status' => 400, 'field' => $prefix . $api]);
            $keyed[$map[$api]] = $v;
        }
        $r = self::save($keyed, $area, $prefix);
        if (is_wp_error($r)) {
            $d = $r->get_error_data();
            if (is_array($d) && isset($d['field'])) { $f = substr($d['field'], strlen($prefix)); $api = array_search($f, $map, true); if ($api !== false) $r = new WP_Error($r->get_error_code(), $r->get_error_message(), ['field' => $prefix . $api] + $d); }
        }
        return $r;
    }
}
