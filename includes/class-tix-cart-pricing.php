<?php
/**
 * Tixomat – Preisdienst der nativen Kasse (Web-Checkout + App-Kasse)
 *
 * Eine Stelle für alle Stückpreise im Warenkorb, damit Website und App
 * immer denselben Betrag berechnen:
 *  - Basispreis: Preisphase / Sale-Preis (TIX_Dynamic_Pricing), Paket („kaufe X, zahle Y“),
 *    Special, fester Preis (locked_price, z. B. Gutschein mit Wunschbetrag)
 *  - Mengenrabatt (_tix_group_discount) wird in die Stückpreise eingerechnet
 *    (meta.list_price = Preis vor Rabatt, meta.group_discount = Prozent)
 *
 * reprice() ist idempotent: Preise werden immer aus den Event-Daten neu berechnet.
 */
if (!defined('ABSPATH')) exit;

class TIX_Cart_Pricing {

    /** Ticket-Kategorien je Event (pro Request) */
    private static $cats = [];

    private static function cats($event_id) {
        $event_id = intval($event_id);
        if (!array_key_exists($event_id, self::$cats)) {
            $c = get_post_meta($event_id, '_tix_ticket_categories', true);
            self::$cats[$event_id] = is_array($c) ? $c : [];
        }
        return self::$cats[$event_id];
    }

    /** Nach dem Abziehen von Bestand o. Ä. neu lesen */
    public static function flush() {
        self::$cats = [];
    }

    /** Preis einer Kategorie inkl. aktiver Preisphase / Sale-Preis */
    public static function category_price($event_id, $cat_index) {
        $cats = self::cats($event_id);
        if (!isset($cats[$cat_index]) || !is_array($cats[$cat_index])) return null;
        if (class_exists('TIX_Dynamic_Pricing')) {
            $dyn = TIX_Dynamic_Pricing::get_dynamic_price(intval($event_id), intval($cat_index));
            if ($dyn !== null) return floatval($dyn);
        }
        return floatval($cats[$cat_index]['price'] ?? 0);
    }

    /** Aktive Preisphase einer Kategorie oder null */
    public static function active_phase($event_id, $cat_index) {
        $cats = self::cats($event_id);
        if (empty($cats[$cat_index]['phases']) || !class_exists('TIX_Metabox')) return null;
        return TIX_Metabox::get_active_phase($cats[$cat_index]['phases']);
    }

    /** Paket-Angebot einer Kategorie: {buy, pay, label} oder null */
    public static function bundle($event_id, $cat_index) {
        $cats = self::cats($event_id);
        $cat  = $cats[$cat_index] ?? null;
        if (!is_array($cat)) return null;
        $buy = intval($cat['bundle_buy'] ?? 0);
        $pay = intval($cat['bundle_pay'] ?? 0);
        if ($buy < 2 || $pay < 1 || $pay >= $buy) return null;
        return ['buy' => $buy, 'pay' => $pay, 'label' => (string) ($cat['bundle_label'] ?? '')];
    }

    /**
     * Stückpreis eines Warenkorb-Eintrags ohne Mengenrabatt.
     * null = Preis nicht bestimmbar (Eintrag unverändert lassen).
     */
    public static function base_price(array $it) {
        $event_id = intval($it['event_id'] ?? 0);
        $meta     = (array) ($it['meta'] ?? []);

        if (!empty($it['locked_price'])) return floatval($it['price'] ?? 0);

        if (!empty($meta['special'])) {
            $sid = intval($meta['special_id'] ?? 0);
            if (!$sid || !class_exists('TIX_Specials')) return null;
            return floatval(TIX_Specials::get_effective_price($sid, $event_id));
        }

        // Sitzplatz: Preis des Saalplan-Bereichs
        if (!empty($meta['seats']) && !empty($meta['section']) && class_exists('TIX_Seatmap')) {
            $data = TIX_Seatmap::data(intval($meta['seatmap_id'] ?? 0));
            foreach ((array) ($data['sections'] ?? []) as $sec) {
                if (($sec['id'] ?? '') === $meta['section']) return floatval($sec['price'] ?? 0);
            }
            return null;
        }

        $idx   = intval($it['cat_index'] ?? 0);
        $price = self::category_price($event_id, $idx);
        if ($price === null) return null;

        if (!empty($meta['bundle'])) {
            $b = self::bundle($event_id, $idx);
            // UNROUNDED — sonst Rundungsfehler beim Total (34.90 × 10/11 × 11 ≠ 349.00)
            if ($b) $price = $price * $b['pay'] / $b['buy'];
        }
        return $price;
    }

    /** Mengenrabatt-Einstellungen eines Events oder null */
    public static function group_discount($event_id) {
        $gd = get_post_meta(intval($event_id), '_tix_group_discount', true);
        if (!is_array($gd) || empty($gd['enabled']) || empty($gd['tiers']) || !is_array($gd['tiers'])) return null;
        $tiers = [];
        foreach ($gd['tiers'] as $t) {
            $min = intval($t['min_qty'] ?? 0);
            $pct = floatval($t['percent'] ?? 0);
            if ($min > 0 && $pct > 0 && $pct < 100) $tiers[] = ['min_qty' => $min, 'percent' => $pct];
        }
        if (!$tiers) return null;
        usort($tiers, function ($a, $b) { return $a['min_qty'] - $b['min_qty']; });
        return [
            'tiers'          => $tiers,
            'combine_bundle' => !empty($gd['combine_bundle']),
            'combine_combo'  => !empty($gd['combine_combo']),
            'combine_phase'  => !empty($gd['combine_phase']),
        ];
    }

    /** Passende (höchste) Staffel für eine Menge */
    public static function tier_for(array $gd, $qty) {
        $match = null;
        foreach ($gd['tiers'] as $t) {
            if ($qty >= $t['min_qty']) $match = $t;
        }
        return $match;
    }

    /**
     * Alle Stückpreise neu berechnen und Mengenrabatt einrechnen.
     * Zählweise wie Ticketauswahl und WooCommerce-Pfad: Pakete und Kombis zählen
     * als ein Stück je Paket, und nur, wenn sie mit dem Rabatt kombinierbar sind.
     */
    public static function reprice(array $items) {
        $by_event = [];
        foreach ($items as $k => &$it) {
            if (!is_array($it)) continue;
            $meta = (array) ($it['meta'] ?? []);
            unset($meta['list_price'], $meta['group_discount']);
            $it['meta'] = $meta;
            $base = self::base_price($it);
            if ($base !== null) $it['price'] = $base;
            $eid = intval($it['event_id'] ?? 0);
            if ($eid) $by_event[$eid][] = $k;
        }
        unset($it);

        foreach ($by_event as $eid => $keys) {
            $gd = self::group_discount($eid);
            if (!$gd) continue;

            $eligible = [];
            $qty      = 0;
            $combos   = [];
            $phase    = false;
            foreach ($keys as $k) {
                $it   = $items[$k];
                $meta = $it['meta'];
                if (!empty($it['locked_price']) || !empty($meta['gift']) || !empty($meta['special'])) continue;
                $n = max(0, intval($it['qty'] ?? 0));
                if (!empty($meta['combo'])) {
                    if (!$gd['combine_combo']) continue;
                    $gid = is_array($meta['combo']) ? (string) ($meta['combo']['group_id'] ?? '') : '';
                    if ($gid === '' || !isset($combos[$gid])) $qty += $n; // Paket nur einmal zählen
                    if ($gid !== '') $combos[$gid] = true;
                } elseif (!empty($meta['bundle'])) {
                    if (!$gd['combine_bundle']) continue;
                    $b = self::bundle($eid, intval($it['cat_index'] ?? 0));
                    $qty += $b ? intdiv($n, $b['buy']) : $n;
                } else {
                    $qty += $n;
                }
                if (self::active_phase($eid, intval($it['cat_index'] ?? 0))) $phase = true;
                $eligible[] = $k;
            }
            if (!$eligible) continue;
            if ($phase && !$gd['combine_phase']) continue;

            $tier = self::tier_for($gd, $qty);
            if (!$tier) continue;
            foreach ($eligible as $k) {
                $list = floatval($items[$k]['price']);
                $items[$k]['meta']['list_price']     = $list;
                $items[$k]['meta']['group_discount'] = $tier['percent'];
                $items[$k]['price'] = $list * (1 - $tier['percent'] / 100);
            }
        }
        return $items;
    }

    /** Summe der Positionen (nach Mengenrabatt, vor Gutschein) */
    public static function items_total(array $items) {
        $sum = 0.0;
        foreach ($items as $it) {
            $sum += floatval($it['price'] ?? 0) * max(0, intval($it['qty'] ?? 0));
        }
        return round($sum, 2);
    }

    /** Ersparnis durch Mengenrabatt */
    public static function group_savings(array $items) {
        $sum = 0.0;
        foreach ($items as $it) {
            if (!isset($it['meta']['list_price'])) continue;
            $sum += (floatval($it['meta']['list_price']) - floatval($it['price'])) * max(0, intval($it['qty'] ?? 0));
        }
        return round($sum, 2);
    }

    // ══════════════════════════════════════
    //  Vorverkauf
    // ══════════════════════════════════════

    /**
     * Vorverkauf offen? Wie die Ticketauswahl (TIX_Ticket_Selector::check_presale_active
     * + Countdown vor _tix_presale_start). Gutschein-Event ist immer offen.
     * Liefert null oder WP_Error mit verständlicher Meldung.
     */
    public static function presale_error($event_id) {
        $event_id = intval($event_id);
        if (get_post_meta($event_id, '_tix_system_giftcard_event', true) === '1') return null;

        if (get_post_meta($event_id, '_tix_presale_active', true) !== '1') {
            return new WP_Error('tix_presale_closed', 'Der Vorverkauf für „' . get_the_title($event_id) . '“ ist beendet.', ['status' => 409]);
        }
        $now = current_time('timestamp');
        $end = (string) get_post_meta($event_id, '_tix_presale_end_computed', true);
        if ($end !== '') {
            $end_ts = strtotime(str_replace('T', ' ', $end));
            if ($end_ts && $end_ts <= $now) {
                return new WP_Error('tix_presale_closed', 'Der Vorverkauf für „' . get_the_title($event_id) . '“ ist beendet.', ['status' => 409]);
            }
        }
        $start = (string) get_post_meta($event_id, '_tix_presale_start', true);
        if ($start !== '') {
            $start_ts = strtotime(str_replace('T', ' ', $start));
            if ($start_ts && $start_ts > $now) {
                return new WP_Error('tix_presale_not_started', sprintf(
                    'Der Vorverkauf für „%s“ startet am %s Uhr.',
                    get_the_title($event_id),
                    date_i18n('j. F Y, H:i', $start_ts)
                ), ['status' => 409, 'starts_at' => $start]);
            }
        }
        return null;
    }
}
