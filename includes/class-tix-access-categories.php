<?php
/**
 * TIX_Access_Categories – Rollstuhlplatz und Begleitperson als Kategorie-Typ (A4, Auftrag Barrierefreiheit 13.11.2026; ab 1.38.812).
 *
 * Ticket-Kategorie `access`: '' (normal) | 'wheelchair' (Rollstuhlplatz) | 'companion' (Begleitperson).
 *  - Begleitperson: nur zusammen mit einem anderen Ticket desselben Events, höchstens 1:1 (Prüfung in Web- und App-Kasse).
 *    Preis legt der Veranstalter fest (oft 0 €). Hinweis „Bitte am Einlass den Schwerbehindertenausweis mit Merkzeichen B
 *    zeigen.“ in den öffentlichen Kategorien (access_hint), Prüfung am Einlass durch das Personal.
 *  - Rollstuhlplatz: Hinweis in den Kategorien; im Saalplan gibt es Rollstuhlplätze schon (Platztyp „wheelchair“,
 *    Bestplatz-Suche mit wheelchair).
 * Barrierefreiheit am Ort (stufenlos, Rollstuhl-WC, Induktionsschleife, Parkplätze …) steht schon am Ort bzw. Event
 * (TIX_Event_Facts, Orte im Studio seit 1.38.804) und wird öffentlich ausgegeben.
 */
if (!defined('ABSPATH')) exit;

class TIX_Access_Categories {

    const TYPES = ['wheelchair' => 'Rollstuhlplatz', 'companion' => 'Begleitperson'];
    const HINTS = [
        'wheelchair' => 'Rollstuhlplatz mit stufenlosem Zugang. Bei Fragen zur Anreise hilft dir der Veranstalter.',
        'companion'  => 'Nur zusammen mit dem Ticket der begleiteten Person. Bitte am Einlass den Schwerbehindertenausweis mit Merkzeichen B zeigen.',
    ];

    public static function clean($v) {
        $v = (string) $v;
        return isset(self::TYPES[$v]) ? $v : '';
    }

    public static function of($cat) { return is_array($cat) ? self::clean($cat['access'] ?? '') : ''; }

    public static function hint($cat) { $a = self::of($cat); return $a !== '' ? self::HINTS[$a] : ''; }

    /** Warenkorb-Positionen {event_id, cat_index|index, qty} → Fehlermeldung oder '' */
    public static function cart_error(array $items) {
        $comp = []; $other = []; $names = [];
        foreach ($items as $it) {
            $eid = intval($it['event_id'] ?? 0);
            $ci = intval($it['cat_index'] ?? ($it['index'] ?? -1));
            $qty = max(0, intval($it['qty'] ?? ($it['quantity'] ?? 1)));
            if (!$eid || $ci < 0 || !$qty) continue;
            $cats = get_post_meta($eid, '_tix_ticket_categories', true);
            $cat = is_array($cats) ? ($cats[$ci] ?? null) : null;
            if (!is_array($cat) || !empty($cat['gift_card'])) continue;
            if (self::of($cat) === 'companion') { $comp[$eid] = ($comp[$eid] ?? 0) + $qty; $names[$eid] = (string) ($cat['name'] ?? 'Begleitperson'); }
            else $other[$eid] = ($other[$eid] ?? 0) + $qty;
        }
        foreach ($comp as $eid => $n) {
            if ($n > ($other[$eid] ?? 0)) {
                return '„' . $names[$eid] . '“ gibt es nur zusammen mit dem Ticket der begleiteten Person (höchstens eins je Ticket).';
            }
        }
        return '';
    }
}
