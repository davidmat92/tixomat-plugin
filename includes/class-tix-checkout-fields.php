<?php
/**
 * TIX_Checkout_Fields – einstellbare Kassenfelder, erstes Feld Geburtsdatum (1.38.875, Vertrag `uebergaben/plan-kassenfelder-2026-11-17.md`, DOCUMENTATION §237).
 *
 * Standard: Kassen-Einstellungen `checkout.birthdate_field` (TIX_Sale_Settings, je Veranstalter auf evendis).
 * Je Event: Meta `_tix_checkout_fields` {birthdate: off|optional|required}, fehlt der Schlüssel = Standard (inherit).
 * Kassen: App (`/customer/cart/quote` meldet, `/customer/orders` prüft `extra_fields`), Web (`tix_extra[...]`), Kasse vor Ort (nie Pflicht).
 * Ablage: TIX_Object_Data Art order_fields – Geburtsdatum verschlüsselt, dazu das Alter am Event-Tag (Statistik, bleibt beim Anonymisieren).
 * Alles aus (Vorgabe) = keine Änderung an irgendeiner Kasse.
 */
if (!defined('ABSPATH')) exit;

class TIX_Checkout_Fields {

    const META = '_tix_checkout_fields';
    const STORE = '_tix_order_fields';
    const MODES = ['off', 'optional', 'required'];
    /** Feld → Typ, Bezeichnung, Zweck-Hinweis */
    const FIELDS = [
        'birthdate' => ['type' => 'date', 'label' => 'Geburtsdatum', 'hint' => 'Fragt der Veranstalter für die Alterskontrolle und eine anonyme Altersstatistik ab.'],
    ];
    const MAX_AGE = 120;
    /** Altersgruppen der Statistik: Schlüssel → [ab Alter, Bezeichnung] */
    const AGE_GROUPS = ['u18' => [0, 'unter 18'], '18-20' => [18, '18–20'], '21-24' => [21, '21–24'], '25-29' => [25, '25–29'], '30-39' => [30, '30–39'], '40+' => [40, '40 und älter']];

    public static function init() {
        // Löschen/Anonymisieren: TIX_Account_Delete::anonymize_email ruft forget() je Bestellung
    }

    // ═══════════════ Reine Funktionen ═══════════════

    /** Modus gültig? (rein) */
    public static function mode_ok($m) { return in_array((string) $m, self::MODES, true); }

    /** Strengster Modus mehrerer Events (rein): required > optional > off */
    public static function strictest(array $modes) {
        $rank = array_flip(self::MODES);
        $best = 'off';
        foreach ($modes as $m) if (isset($rank[$m]) && $rank[$m] > $rank[$best]) $best = $m;
        return $best;
    }

    /** Wirksamer Modus (rein): Event-Wert, sonst Standard */
    public static function effective($event_mode, $default) {
        if (self::mode_ok($event_mode)) return (string) $event_mode;
        return self::mode_ok($default) ? (string) $default : 'off';
    }

    /**
     * Geburtsdatum prüfen (rein). Nimmt JJJJ-MM-TT oder TT.MM.JJJJ.
     * → ['ok' => 'JJJJ-MM-TT'] | ['error' => 'format'|'range']
     */
    public static function parse_birthdate($s, $today) {
        $s = trim((string) $s);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) { $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3]; }
        elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m)) { $d = (int) $m[1]; $mo = (int) $m[2]; $y = (int) $m[3]; }
        else return ['error' => 'format'];
        if (!checkdate($mo, $d, $y)) return ['error' => 'format'];
        $iso = sprintf('%04d-%02d-%02d', $y, $mo, $d);
        if ($iso > $today) return ['error' => 'range'];
        if ($iso < self::bounds($today)[0]) return ['error' => 'range']; // älter als 120 Jahre
        return ['ok' => $iso];
    }

    /** Alter in vollen Jahren an einem Tag (rein, beide JJJJ-MM-TT) */
    public static function age_on($birth, $date) {
        list($by, $bm, $bd) = array_map('intval', explode('-', $birth));
        list($y, $m, $d) = array_map('intval', explode('-', substr($date, 0, 10)));
        $age = $y - $by;
        if ($m < $bm || ($m === $bm && $d < $bd)) $age--;
        return max(0, $age);
    }

    /** Altersgruppe (rein) */
    public static function age_group($age) {
        $key = 'u18';
        foreach (self::AGE_GROUPS as $k => $g) if (intval($age) >= $g[0]) $key = $k;
        return $key;
    }

    /** Frühestes/spätestes erlaubtes Datum (rein) */
    public static function bounds($today) {
        $y = intval(substr($today, 0, 4)) - self::MAX_AGE;
        return [sprintf('%04d', $y) . substr($today, 4, 6), $today];
    }

    // ═══════════════ Einstellung ═══════════════

    private static function today() { return current_time('Y-m-d'); }

    /** Standard der Seite bzw. des Veranstalters (Kassen-Einstellungen) */
    public static function default_for_event($event_id) {
        if (!class_exists('TIX_Sale_Settings')) return ['birthdate' => 'off'];
        $c = intval($event_id) ? TIX_Sale_Settings::for_event(intval($event_id))['checkout'] : TIX_Sale_Settings::get(0)['checkout'];
        return ['birthdate' => self::mode_ok($c['birthdate_field'] ?? 'off') ? (string) $c['birthdate_field'] : 'off'];
    }

    /** Eigene Einstellung des Events (nur gesetzte Felder) */
    public static function event_own($event_id) {
        $v = get_post_meta(intval($event_id), self::META, true);
        if (is_string($v) && $v !== '') { $d = json_decode($v, true); $v = is_array($d) ? $d : []; }
        $out = [];
        if (is_array($v)) foreach (self::FIELDS as $k => $_) if (isset($v[$k]) && self::mode_ok($v[$k])) $out[$k] = (string) $v[$k];
        return $out;
    }

    /** Wirksame Modi eines Events {birthdate: off|optional|required} */
    public static function for_event($event_id) {
        $own = intval($event_id) ? self::event_own($event_id) : [];
        $def = self::default_for_event($event_id);
        $out = [];
        foreach (self::FIELDS as $k => $_) $out[$k] = self::effective($own[$k] ?? null, $def[$k] ?? 'off');
        return $out;
    }

    /** Wirksame Modi für mehrere Events (Web-Warenkorb): das Strengste gilt */
    public static function for_events(array $event_ids) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $event_ids))));
        if (!$ids) $ids = [0];
        $per = array_map([__CLASS__, 'for_event'], $ids);
        $out = [];
        foreach (self::FIELDS as $k => $_) $out[$k] = self::strictest(array_column($per, $k));
        return $out;
    }

    public static function cart_event_ids(array $items) {
        return array_map(function ($it) { return intval($it['event_id'] ?? 0); }, $items);
    }

    /** Feld-Beschreibungen der eingeschalteten Felder */
    public static function definitions(array $modes) {
        $out = [];
        list($min, $max) = self::bounds(self::today());
        foreach (self::FIELDS as $k => $f) {
            $m = $modes[$k] ?? 'off';
            if ($m === 'off') continue;
            $out[] = ['key' => $k, 'type' => $f['type'], 'mode' => $m, 'label' => $f['label'], 'hint' => $f['hint'], 'min' => $min, 'max' => $max, 'format' => 'YYYY-MM-DD'];
        }
        return $out;
    }

    /** Eingabe für POST /events/{id}/extras {checkout_fields: {...}} prüfen → [feld => modus|'inherit'] | WP_Error */
    public static function clean_event_input($in) {
        if (!is_array($in)) return new WP_Error('tix_invalid_field', 'checkout_fields als Objekt.', ['status' => 400, 'field' => 'checkout_fields']);
        $out = [];
        foreach ($in as $k => $v) {
            if (!isset(self::FIELDS[$k])) return new WP_Error('tix_invalid_field', 'Unbekanntes Kassenfeld „' . sanitize_key((string) $k) . '“.', ['status' => 400, 'field' => 'checkout_fields.' . sanitize_key((string) $k)]);
            $v = (string) $v;
            if ($v !== 'inherit' && !self::mode_ok($v)) return new WP_Error('tix_invalid_field', 'Erlaubt sind inherit, off, optional oder required.', ['status' => 400, 'field' => 'checkout_fields.' . $k]);
            $out[$k] = $v;
        }
        return $out;
    }

    /** Event-Einstellung speichern (inherit entfernt den Schlüssel) */
    public static function save_event($event_id, array $clean) {
        $cur = self::event_own($event_id);
        foreach ($clean as $k => $v) { if ($v === 'inherit') unset($cur[$k]); else $cur[$k] = $v; }
        if ($cur) update_post_meta(intval($event_id), self::META, $cur);
        else delete_post_meta(intval($event_id), self::META);
    }

    /** Für GET /events/{id}/extras */
    public static function editor_payload($event_id) {
        $own = self::event_own($event_id);
        $set = [];
        foreach (self::FIELDS as $k => $_) $set[$k] = $own[$k] ?? 'inherit';
        return ['checkout_fields' => $set, 'checkout_fields_effective' => self::for_event($event_id), 'checkout_fields_default' => self::default_for_event($event_id)];
    }

    // ═══════════════ Kasse ═══════════════

    /**
     * Werte prüfen. $modes = wirksame Modi, $in = [feld => wert]. $pos = Kasse vor Ort (nie Pflicht).
     * → bereinigte Werte [feld => wert] (nur eingeschaltete, nicht leere) | WP_Error 400 tix_extra_field
     */
    public static function validate(array $modes, $in, $pos = false, $field_name = null) {
        $in = is_array($in) ? $in : [];
        $out = [];
        foreach (self::FIELDS as $k => $f) {
            $m = $modes[$k] ?? 'off';
            if ($m === 'off') continue; // ausgeschaltet: nie speichern
            $raw = isset($in[$k]) && is_scalar($in[$k]) ? trim((string) $in[$k]) : '';
            $fname = $field_name ? sprintf($field_name, $k) : $k;
            if ($raw === '') {
                if ($m === 'required' && !$pos) return new WP_Error('tix_extra_field', 'Bitte ' . $f['label'] . ' angeben.', ['status' => 400, 'field' => $fname]);
                continue;
            }
            $r = self::parse_birthdate(mb_substr($raw, 0, 20), self::today());
            if (isset($r['error'])) {
                return new WP_Error('tix_extra_field', $r['error'] === 'format' ? 'Bitte ein gültiges Geburtsdatum angeben (TT.MM.JJJJ).' : 'Bitte ein gültiges Geburtsdatum angeben.', ['status' => 400, 'field' => $fname]);
            }
            $out[$k] = $r['ok'];
        }
        return $out;
    }

    /** Werte an der Bestellung ablegen. $items = Warenkorb-Zeilen (für den Event-Tag des Alters) */
    public static function store($order_id, array $values, array $items = []) {
        $order_id = intval($order_id);
        if (!$order_id || empty($values['birthdate'])) return;
        $on = self::today();
        foreach ($items as $it) {
            $eid = intval($it['event_id'] ?? 0);
            $d = $eid ? substr((string) get_post_meta($eid, '_tix_date_start', true), 0, 10) : '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $on = $d; break; }
        }
        $enc = class_exists('TIX_Ad_Accounts') ? TIX_Ad_Accounts::enc($values['birthdate']) : null;
        if ($enc === null) return; // ohne Verschlüsselung nichts speichern
        TIX_Object_Data::write(self::STORE, $order_id, ['birthdate_enc' => $enc, 'age' => self::age_on($values['birthdate'], max($on, $values['birthdate'])), 'age_on' => $on, 'at' => gmdate('c')]);
    }

    /** Angaben einer Bestellung für Detail/Export → {birthdate, age} | null */
    public static function for_order($order_id) {
        $d = TIX_Object_Data::read(self::STORE, intval($order_id), null);
        if (!is_array($d)) return null;
        $b = !empty($d['birthdate_enc']) && class_exists('TIX_Ad_Accounts') ? TIX_Ad_Accounts::dec((string) $d['birthdate_enc']) : '';
        return ['birthdate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $b) ? $b : null, 'age' => isset($d['age']) ? intval($d['age']) : null];
    }

    /** Geburtsdatum für viele Bestellungen (Export) [order_id => JJJJ-MM-TT] */
    public static function birthdates(array $order_ids) {
        $out = [];
        foreach (TIX_Object_Data::read_many(self::STORE, $order_ids) as $oid => $d) {
            if (!is_array($d) || empty($d['birthdate_enc'])) continue;
            $b = TIX_Ad_Accounts::dec((string) $d['birthdate_enc']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $b)) $out[intval($oid)] = $b;
        }
        return $out;
    }

    /** Anonymisieren: Geburtsdatum weg, Alter (ohne Personenbezug) bleibt für die Statistik */
    public static function forget($order_id) {
        $d = TIX_Object_Data::read(self::STORE, intval($order_id), null);
        if (!is_array($d) || !isset($d['birthdate_enc'])) return;
        unset($d['birthdate_enc']);
        $d['forgotten'] = gmdate('c');
        TIX_Object_Data::write(self::STORE, intval($order_id), $d);
    }

    // ═══════════════ Statistik ═══════════════

    /** Altersgruppen bezahlter Bestellungen → {known, orders, groups[], average} */
    public static function age_stats(array $paid_order_ids) {
        $ids = array_values(array_unique(array_map('intval', $paid_order_ids)));
        $counts = array_fill_keys(array_keys(self::AGE_GROUPS), 0);
        $sum = 0; $known = 0;
        if ($ids) foreach (TIX_Object_Data::read_many(self::STORE, $ids) as $d) {
            if (!is_array($d) || !isset($d['age'])) continue;
            $a = intval($d['age']);
            $counts[self::age_group($a)]++;
            $sum += $a; $known++;
        }
        $groups = [];
        foreach (self::AGE_GROUPS as $k => $g) $groups[] = ['key' => $k, 'label' => $g[1], 'n' => $counts[$k], 'share' => $known ? round($counts[$k] / $known * 100, 1) : 0.0];
        return ['known' => $known, 'orders' => count($ids), 'groups' => $groups, 'average' => $known ? round($sum / $known, 1) : null];
    }

    /** Regionen nach PLZ (zwei Ziffern, DE) bzw. Land → {known, items[]}; kleine Gruppen (< $min) = „weitere“ */
    public static function region_stats(array $paid_order_ids, $min = 3) {
        global $wpdb;
        $ids = array_values(array_unique(array_map('intval', $paid_order_ids)));
        $by = []; $known = 0;
        foreach (array_chunk($ids, 500) as $ch) {
            foreach ((array) $wpdb->get_results("SELECT billing_postcode AS plz, billing_city AS city, billing_country AS cc FROM " . TIX_Order::table_name() . " WHERE id IN (" . implode(',', $ch) . ")") as $r) {
                list($key, $label) = self::region_key((string) $r->plz, (string) $r->cc);
                if ($key === '') continue;
                $known++;
                if (!isset($by[$key])) $by[$key] = ['n' => 0, 'label' => $label, 'cities' => []];
                $by[$key]['n']++;
                $c = trim((string) $r->city);
                if ($c !== '') { $ck = mb_strtolower($c); $by[$key]['cities'][$ck] = ($by[$key]['cities'][$ck] ?? 0) + 1; $by[$key]['names'][$ck] = $c; }
            }
        }
        $items = []; $other = 0;
        foreach ($by as $k => $b) {
            if ($b['n'] < $min) { $other += $b['n']; continue; }
            $label = $b['label'];
            if ($b['cities']) { arsort($b['cities']); $label .= ' ' . $b['names'][array_key_first($b['cities'])]; }
            $items[] = ['key' => (string) $k, 'label' => $label, 'n' => $b['n'], 'share' => $known ? round($b['n'] / $known * 100, 1) : 0.0];
        }
        usort($items, function ($a, $b) { return $b['n'] <=> $a['n'] ?: strcmp($a['key'], $b['key']); });
        if ($other) $items[] = ['key' => 'other', 'label' => 'weitere', 'n' => $other, 'share' => $known ? round($other / $known * 100, 1) : 0.0];
        return ['known' => $known, 'items' => $items];
    }

    /** Regionsschlüssel (rein): DE-PLZ → erste zwei Ziffern („40…“), sonst Ländercode */
    public static function region_key($plz, $cc) {
        $cc = strtoupper(trim($cc)) ?: 'DE';
        $p = preg_replace('/[^0-9]/', '', (string) $plz);
        if ($cc === 'DE') return strlen($p) === 5 ? [substr($p, 0, 2), substr($p, 0, 2) . '…'] : ['', ''];
        return preg_match('/^[A-Z]{2}$/', $cc) ? [$cc, $cc] : ['', ''];
    }
}

TIX_Checkout_Fields::init();
