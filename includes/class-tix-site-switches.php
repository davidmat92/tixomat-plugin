<?php
/**
 * TIX_Site_Switches – Funktionen der ganzen Seite im Studio (1.38.882, WP-Admin-Restliste W2 = V2, V2b, V13, V15).
 * Vertrag: uebergaben/vertrag-funktionen-der-seite.md, DOCUMENTATION.md §242.
 *
 * Eine Liste aller Schlüssel (DEFS), ein reiner Prüfer (clean), ein Schreiber in tix_settings (save, nur geänderte Schlüssel).
 * Ausgespielt über bestehende Routen:
 *   /studio/checkout-settings → features{<name>: {enabled, can_edit, hint, …}}, low_stock_threshold (V2, V2b; Form laut Studio-Vertrag)
 *   /studio/invoice-config   → tax{enabled, inclusive, rate} – rate = bestehendes tax_rate (eine Quelle) (V13)
 *   /support/mode            → switches{enabled, chat_enabled, categories[]} (V15)
 * Rechte: Werte gelten für die ganze Seite. evendis: nur Plattform-Admin schreibt; Einzel-Seite: Admin und Inhaber (tix_organizer).
 */
if (!defined('ABSPATH')) exit;

class TIX_Site_Switches {

    /** key => [Bereich, Typ, Vorgabe, Bezeichnung, Gruppe, Extra] */
    const DEFS = [
        'ticket_transfer_enabled'   => ['checkout', 'bool', 0, 'Ticket umschreiben', 'verkauf', ['hint' => 'Käufer dürfen Tickets auf eine andere Person umschreiben. Je Event zusätzlich einschalten.']],
        'express_checkout_enabled'  => ['checkout', 'bool', 0, '1-Klick-Kauf', 'verkauf', ['hint' => 'Angemeldete Kunden kaufen mit einem Klick (gespeicherte Daten).']],
        'group_booking'             => ['checkout', 'bool', 1, 'Gemeinsam buchen', 'verkauf', ['hint' => 'Knopf „Gemeinsam buchen“ in der Ticketauswahl der Website (alte WordPress-Seiten; entfällt mit der neuen Marken-Website).']],
        'specials_enabled'          => ['checkout', 'bool', 0, 'Extras', 'verkauf', ['hint' => 'Zusatzartikel wie Getränkemarken oder Parkplatz zum Ticket.']],
        'table_reservation_enabled' => ['checkout', 'bool', 0, 'Tischreservierung', 'verkauf', ['hint' => 'Tische mit Mindestverzehr. Je Event einstellen.']],
        'waitlist_enabled'          => ['checkout', 'bool', 1, 'Warteliste', 'event_seite', ['hint' => 'Erinnerung zum Vorverkaufsstart und Warteliste bei ausverkauften Events.']],
        'charity_enabled'           => ['checkout', 'bool', 0, 'Spenden-Hinweis', 'event_seite', ['hint' => 'Hinweis auf einen guten Zweck. Je Event einstellen.']],
        'show_upsell'               => ['checkout', 'bool', 1, 'Ähnliche Events nach dem Kauf', 'nach_kauf', []],
        'upsell_count'              => ['checkout', 'int', 3, 'Anzahl ähnlicher Events', 'nach_kauf', ['min' => 1, 'max' => 6]],
        'upsell_heading'            => ['checkout', 'text', 'Das könnte dich auch interessieren', 'Überschrift ähnliche Events', 'nach_kauf', ['max_length' => 80]],
        'feedback_enabled'          => ['checkout', 'bool', 1, 'Bewertung nach dem Event', 'nach_kauf', ['hint' => 'Die Mail nach dem Event enthält eine Sterne-Bewertung mit Kommentar; Ergebnisse unter Statistik.']],
        'abandoned_cart_enabled'    => ['checkout', 'bool', 0, 'Erinnerung bei abgebrochenem Kauf', 'nach_kauf', ['hint' => 'Mail an Kunden, die die Kasse mit E-Mail-Adresse verlassen haben.']],
        'abandoned_cart_delay'      => ['checkout', 'int', 30, 'Erinnerung nach (Minuten)', 'nach_kauf', ['min' => 5, 'max' => 1440]],
        'abandoned_cart_subject'    => ['checkout', 'text', '', 'Betreff der Erinnerung', 'nach_kauf', ['max_length' => 150, 'hint' => 'Leer = Standard.']],
        'low_stock_threshold'       => ['checkout', 'int', 10, '„Nur noch wenige Tickets“ ab', 'kasse', ['min' => 0, 'max' => 999, 'hint' => '0 = nie anzeigen. Je Event im Editor änderbar.']],
        'tax_enabled'               => ['tax', 'bool', 0, 'MwSt. in Bestellungen ausweisen', 'steuer', []],
        'tax_rate'                  => ['tax', 'float', 19, 'MwSt.-Satz (%)', 'steuer', ['min' => 0, 'max' => 30]],
        'tax_inclusive'             => ['tax', 'bool', 1, 'Preise enthalten MwSt.', 'steuer', ['hint' => 'Normalfall: Bruttopreise.']],
        'support_enabled'           => ['support', 'bool', 0, 'Kundenanfragen', 'support', []],
        'support_chat_enabled'      => ['support', 'bool', 0, 'Chat', 'support', []],
        'support_categories'        => ['support', 'list', '', 'Themen', 'support', ['max_items' => 12, 'max_length' => 40, 'hint' => 'Leer = Standard-Themen.']],
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

    /** /studio/checkout-settings features: Name => [API-Feld => Schlüssel] (Studio-Vertrag 17.11.) */
    const FEATURE_MAP = [
        'ticket_transfer'   => ['enabled' => 'ticket_transfer_enabled'],
        'table_reservation' => ['enabled' => 'table_reservation_enabled'],
        'specials'          => ['enabled' => 'specials_enabled'],
        'charity'           => ['enabled' => 'charity_enabled'],
        'express_checkout'  => ['enabled' => 'express_checkout_enabled'],
        'upsell'            => ['enabled' => 'show_upsell', 'count' => 'upsell_count', 'heading' => 'upsell_heading'],
        'waitlist'          => ['enabled' => 'waitlist_enabled'],
        'feedback'          => ['enabled' => 'feedback_enabled'],
        'group_booking'     => ['enabled' => 'group_booking'],
        'abandoned_cart'    => ['enabled' => 'abandoned_cart_enabled', 'delay_minutes' => 'abandoned_cart_delay', 'subject' => 'abandoned_cart_subject'],
    ];

    public static function features_payload() {
        $s = self::raw(); $edit = self::can_write(); $out = [];
        foreach (self::FEATURE_MAP as $name => $map) {
            $main = self::DEFS[$map['enabled']];
            $f = ['enabled' => self::value($map['enabled'], $s), 'can_edit' => $edit, 'label' => $main[3], 'group' => $main[4], 'group_label' => self::GROUPS[$main[4]] ?? $main[4]];
            if (!empty($main[5]['hint'])) $f['hint'] = $main[5]['hint'];
            foreach ($map as $api => $k) if ($api !== 'enabled') $f[$api] = self::value($k, $s);
            if ($name === 'abandoned_cart') $f['delay_hours'] = round(self::value('abandoned_cart_delay', $s) / 60, 1);
            if ($name === 'upsell') $f['limits'] = ['count' => [1, 6], 'heading' => 80];
            if ($name === 'abandoned_cart') $f['limits'] = ['delay_minutes' => [5, 1440], 'delay_hours' => [1, 24], 'subject' => 150];
            $out[$name] = $f;
        }
        return $out;
    }

    /** {name: {enabled?, count?, heading?, delay_minutes?|delay_hours?, subject?}} → true | WP_Error (Feld features.<name>.<api>) */
    public static function features_save($in) {
        if (!is_array($in)) return new WP_Error('tix_invalid_field', 'features: Objekt erwartet.', ['status' => 400, 'field' => 'features']);
        $keyed = []; $where = [];
        foreach ($in as $name => $vals) {
            if (!isset(self::FEATURE_MAP[$name])) return new WP_Error('tix_invalid_field', 'Unbekannte Funktion: ' . $name . '.', ['status' => 400, 'field' => 'features.' . $name]);
            if (!is_array($vals)) return new WP_Error('tix_invalid_field', 'features.' . $name . ': Objekt erwartet.', ['status' => 400, 'field' => 'features.' . $name]);
            foreach ($vals as $api => $v) {
                if ($name === 'abandoned_cart' && $api === 'delay_hours') {
                    if (!is_numeric($v) || intval($v) != $v || intval($v) < 1 || intval($v) > 24) return new WP_Error('tix_invalid_field', 'Erinnerung nach 1 bis 24 Stunden.', ['status' => 400, 'field' => 'features.abandoned_cart.delay_hours']);
                    $api = 'delay_minutes'; $v = intval($v) * 60;
                }
                if (in_array($api, ['can_edit', 'label', 'hint', 'group', 'group_label', 'limits'], true)) continue; // nur lesend, still übergehen
                if (!isset(self::FEATURE_MAP[$name][$api])) return new WP_Error('tix_invalid_field', 'Unbekanntes Feld: ' . $name . '.' . $api . '.', ['status' => 400, 'field' => 'features.' . $name . '.' . $api]);
                $k = self::FEATURE_MAP[$name][$api];
                $keyed[$k] = $v; $where[$k] = 'features.' . $name . '.' . $api;
            }
        }
        $r = self::save($keyed, 'checkout', '');
        if (is_wp_error($r)) {
            $d = (array) $r->get_error_data();
            if (isset($d['field'], $where[$d['field']])) $r = new WP_Error($r->get_error_code(), $r->get_error_message(), ['field' => $where[$d['field']]] + $d);
        }
        return $r;
    }

    /** /support/mode: {enabled, chat_enabled, categories} ↔ Schlüssel */
    const SUPPORT_MAP = ['enabled' => 'support_enabled', 'chat_enabled' => 'support_chat_enabled', 'categories' => 'support_categories'];
    /** /studio/invoice-config tax: {enabled, inclusive, rate} ↔ Schlüssel. Einzel-Seite: rate und tax_rate des Ausstellers „site“ sind dieselbe Zahl (eine Quelle, beide Wege halten sie gleich) */
    const TAX_MAP = ['enabled' => 'tax_enabled', 'inclusive' => 'tax_inclusive', 'rate' => 'tax_rate'];

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
