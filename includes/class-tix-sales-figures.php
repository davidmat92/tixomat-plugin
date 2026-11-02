<?php
if (!defined('ABSPATH')) exit;
/**
 * TIX_Sales_Figures – eine Rechnung für alle Verkaufszahlen (1.38.606).
 *
 * Vorher zählten /events, /events/{id}/statistics, /organizer/stats, /events/{id}/report, /pos/report,
 * /staff/overview und das Studio (aus Bestelllisten) je anders. Jetzt gilt überall:
 *  - Tickets  = gültige Ticket-Posts (nicht storniert) mit echter Kategorie des Events (Index vorhanden,
 *               kein Gutschein) – wie `ticket_counts`/`total_sold`. Extras, Kombis und Gutscheine sind keine Tickets.
 *               Im Zeitraum zählt das Bestelldatum des Tickets (ohne Bestellung das Ticket-Datum).
 *  - Umsatz   = bezahlte Bestellungen (completed/processing/refunded), Anteil des Events an der Bestellung:
 *               gezahlt − Kundengebühr + Geschenkgutschein (= Zahlung) − Erstattungen. Rabatt-Gutscheine sind
 *               damit abgezogen, Gebühren stehen separat (`fees`). Wie die Abrechnung (TIX_Settlement::order_line).
 *  - Kapazität = Summe der Kategorie-Kontingente wie `category_numbers` (ohne Gutschein-Kategorien), `unlimited`,
 *               sobald eine Kategorie unbegrenzt ist.
 *  - Bestellungen = bezahlte Bestellungen (completed/processing) mit Positionen des Events.
 * Keine Obergrenzen (alle Bestellungen, in Blöcken gelesen).
 *
 * figures: {tickets, checked_in, capacity, unlimited, revenue, revenue_gross, refunds, discounts, fees, orders,
 *           by_category [{index|null, name, tickets, items, revenue}], by_day {Y-m-d: {revenue, tickets, orders}}}
 */
class TIX_Sales_Figures {
    const PAID   = ['completed', 'processing'];
    const COUNTS = ['completed', 'processing', 'refunded']; // refunded: Umsatz 0, Erstattung sichtbar

    /** Cache für Gesamtzahlen (ohne Zeitraum) je Event, gefüllt über prime(). */
    private static $cache = [];

    public static function reset() { self::$cache = []; }

    /** Gesamtzahlen vieler Events auf einmal vorberechnen (z. B. für die Eventliste). */
    public static function prime(array $ids) {
        $ids = array_values(array_diff(array_unique(array_map('intval', $ids)), array_keys(self::$cache)));
        if (!$ids) return;
        $res = self::for_events($ids);
        foreach ($ids as $id) self::$cache[$id] = $res['events'][$id] ?? self::empty_figures($id);
    }

    /** Gesamtzahlen eines Events (gecacht). */
    public static function event($event_id) {
        $event_id = intval($event_id);
        if (!isset(self::$cache[$event_id])) self::prime([$event_id]);
        return self::$cache[$event_id];
    }

    public static function empty_figures($event_id = 0) {
        $f = ['tickets' => 0, 'checked_in' => 0, 'capacity' => 0, 'unlimited' => false, 'revenue' => 0.0, 'revenue_gross' => 0.0,
            'refunds' => 0.0, 'discounts' => 0.0, 'fees' => 0.0, 'orders' => 0, 'by_category' => [], 'by_day' => []];
        if ($event_id) {
            foreach (self::categories($event_id) as $i => $c) {
                $f['by_category'][] = ['index' => $i, 'name' => $c['name'], 'tickets' => 0, 'items' => 0, 'revenue' => 0.0];
            }
            list($f['capacity'], $f['unlimited']) = self::capacity($event_id, []);
        }
        return $f;
    }

    // ═══════════════ Bausteine ═══════════════

    /** Echte Ticket-Kategorien eines Events: [index => ['name', 'cat']] (ohne Gutschein-Kategorien). */
    public static function categories($event_id) {
        $raw = get_post_meta(intval($event_id), '_tix_ticket_categories', true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $i => $c) {
            if (!is_array($c) || !empty($c['gift_card'])) continue;
            $out[intval($i)] = ['name' => trim((string) ($c['name'] ?? '')) ?: 'Ticket', 'cat' => $c];
        }
        return $out;
    }

    /** [Kapazität, unbegrenzt] wie TIX_REST_API::category_numbers. $sold = [cat_index => verkauft]. */
    public static function capacity($event_id, array $sold) {
        $sum = 0; $unlimited = false;
        foreach (self::categories($event_id) as $i => $c) {
            $cat = $c['cat'];
            $qty = absint($cat['quantity'] ?? $cat['qty'] ?? 0);
            $stock = (isset($cat['stock']) && $cat['stock'] !== '') ? intval($cat['stock']) : -1;
            if ($stock >= 0) { if (!$qty) $qty = intval($sold[$i] ?? 0) + $stock; }
            elseif ($qty <= 0) $unlimited = true;
            $sum += $qty;
        }
        return [$sum, $unlimited];
    }

    /** Als Zahlung eingelöster Geschenkgutschein einer Bestellung (Online/App-Kasse oder Abendkasse). */
    public static function giftcard_credit($order_id) {
        $order_id = intval($order_id);
        if (class_exists('TIX_Settlement') && method_exists('TIX_Settlement', 'giftcard_credit')) {
            $g = TIX_Settlement::giftcard_credit($order_id);
            if ($g > 0) return $g;
        }
        $c = get_option('_tix_order_coupon_' . $order_id);
        if (is_array($c) && ($c['type'] ?? '') === 'giftcard') return round(floatval($c['discount'] ?? 0), 2);
        $pos = get_option('_tix_pos_order_' . $order_id);
        if (is_array($pos) && is_array($pos['coupon'] ?? null) && ($pos['coupon']['type'] ?? '') === 'giftcard') return round(floatval($pos['coupon']['amount'] ?? 0), 2);
        return 0.0;
    }

    private static function customer_fee($order_id) {
        if (class_exists('TIX_Settlement') && method_exists('TIX_Settlement', 'order_fees')) return floatval(TIX_Settlement::order_fees($order_id)['customer_fee_line'] ?? 0);
        $f = get_option('_tix_order_fees_' . intval($order_id));
        return is_array($f) ? round(floatval($f['customer_fee_line'] ?? 0), 2) : 0.0;
    }

    private static function refunded($row) {
        if (class_exists('TIX_Settlement') && method_exists('TIX_Settlement', 'refunded_amount')) return floatval(TIX_Settlement::refunded_amount($row));
        $acc = get_option('_tix_refund_total_' . intval($row->id), 0);
        if ($row->status === 'refunded') $acc = max(floatval($acc), floatval($row->total));
        return round(min(floatval($acc), floatval($row->total)), 2);
    }

    /**
     * Geldwerte einer Bestellung für ein Event: [gross, refund, net, fee, discount, paid].
     * $row: id, status, total, line_event (Listenpreis des Events), line_all (Listenpreis der Bestellung)
     */
    public static function money($row) {
        $line_all = floatval($row->line_all);
        $line_ev  = floatval($row->line_event);
        $share = $line_all > 0 ? min(1.0, $line_ev / $line_all) : 1.0;
        $fee = self::customer_fee($row->id);
        $amount = max(0.0, round(floatval($row->total) - $fee + self::giftcard_credit($row->id), 2));
        $refunded = self::refunded($row);
        $gross  = round($amount * $share, 2);
        $refund = round(min($refunded, $amount) * $share, 2);
        return [
            'gross'    => $gross,
            'refund'   => $refund,
            'net'      => round($gross - $refund, 2),
            'fee'      => round($fee * $share, 2),
            'discount' => round(max(0.0, $line_ev - $gross), 2),
            'paid'     => in_array($row->status, self::PAID, true),
        ];
    }

    /** Zeitraum-Bedingung auf o.date_created (Ortszeit, wie überall). */
    private static function date_sql($from, $to, &$args) {
        $sql = '';
        if ($from) { $sql .= ' AND o.date_created >= %s'; $args[] = $from . ' 00:00:00'; }
        if ($to)   { $sql .= ' AND o.date_created <= %s'; $args[] = $to . ' 23:59:59'; }
        return $sql;
    }

    /**
     * Bestell-Zeilen je (Bestellung, Event): id, status, total, payment_method, d, eid, line_event, qty_event, line_all.
     * $order_ids optional (z. B. Kassenbericht eines Tages).
     */
    public static function order_rows(array $event_ids, $from = '', $to = '', array $order_ids = []) {
        global $wpdb;
        if (!class_exists('TIX_Order')) return [];
        $t = TIX_Order::table_name(); $ti = TIX_Order::items_table_name();
        $out = [];
        $chunks = $order_ids ? array_chunk(array_map('intval', $order_ids), 500) : array_chunk(array_map('intval', $event_ids), 500);
        foreach ($chunks as $chunk) {
            if (!$chunk) continue;
            $args = [];
            $where = $order_ids ? 'o.id IN (' . implode(',', $chunk) . ')' . ($event_ids ? ' AND i.event_id IN (' . implode(',', array_map('intval', $event_ids)) . ')' : '')
                : 'i.event_id IN (' . implode(',', $chunk) . ')';
            $sql = "SELECT o.id, o.status, o.total, o.payment_method, o.date_created d, i.event_id eid, SUM(i.total) line_event, SUM(i.quantity) qty_event,
                    (SELECT SUM(i2.total) FROM $ti i2 WHERE i2.order_id = o.id) line_all
                FROM $t o INNER JOIN $ti i ON i.order_id = o.id
                WHERE $where AND o.status IN ('" . implode("','", self::COUNTS) . "')" . self::date_sql($from, $to, $args) . ' GROUP BY o.id, i.event_id';
            foreach ((array) $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql) as $r) $out[] = $r;
        }
        return $out;
    }

    /** Positionen zu Bestellungen: [order_id][event_id] => [[name, qty, total]] */
    private static function items(array $order_ids) {
        global $wpdb;
        $out = [];
        $ti = TIX_Order::items_table_name();
        foreach (array_chunk(array_values(array_unique(array_map('intval', $order_ids))), 500) as $chunk) {
            foreach ((array) $wpdb->get_results("SELECT order_id, event_id, cat_name, name, quantity, total FROM $ti WHERE order_id IN (" . implode(',', $chunk) . ')') as $r) {
                $n = trim((string) $r->cat_name);
                if ($n === '') { $n = trim((string) $r->name); $p = strrpos($n, ' – '); if ($p !== false) $n = trim(substr($n, $p + 5)); }
                $out[intval($r->order_id)][intval($r->event_id)][] = ['name' => $n ?: 'Ticket', 'qty' => intval($r->quantity), 'total' => floatval($r->total)];
            }
        }
        return $out;
    }

    /** Gültige Ticket-Posts der Events: [eid, cat_index, checked, order_id, post_date] */
    public static function ticket_rows(array $event_ids) {
        global $wpdb;
        $out = [];
        foreach (array_chunk(array_map('intval', $event_ids), 300) as $chunk) {
            $in = "'" . implode("','", $chunk) . "'";
            $rows = $wpdb->get_results("SELECT p.ID, p.post_date, ev.meta_value eid, st.meta_value status, ci.meta_value cat_index, ch.meta_value checked, oi.meta_value oid
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} ev ON ev.post_id = p.ID AND ev.meta_key = '_tix_ticket_event_id' AND ev.meta_value IN ($in)
                LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_tix_ticket_status'
                LEFT JOIN {$wpdb->postmeta} ci ON ci.post_id = p.ID AND ci.meta_key = '_tix_ticket_cat_index'
                LEFT JOIN {$wpdb->postmeta} ch ON ch.post_id = p.ID AND ch.meta_key = '_tix_ticket_checked_in'
                LEFT JOIN {$wpdb->postmeta} oi ON oi.post_id = p.ID AND oi.meta_key = '_tix_ticket_order_id'
                WHERE p.post_type = 'tix_ticket' AND p.post_status IN ('publish', 'private')");
            foreach ((array) $rows as $r) {
                $status = (string) ($r->status ?: 'valid');
                if ($status === 'cancelled') continue;
                $out[] = ['eid' => intval($r->eid), 'cat' => ($r->cat_index === null || $r->cat_index === '') ? -1 : intval($r->cat_index),
                    'checked' => $status === 'used' || (string) $r->checked === '1', 'oid' => intval($r->oid), 'date' => (string) $r->post_date];
            }
        }
        return $out;
    }

    /** Bestelldatum je Bestell-ID (auch alte Woo-IDs). */
    private static function order_dates(array $ids) {
        global $wpdb;
        $out = [];
        if (!class_exists('TIX_Order')) return $out;
        $t = TIX_Order::table_name();
        foreach (array_chunk(array_values(array_unique(array_filter(array_map('intval', $ids)))), 500) as $chunk) {
            $in = implode(',', $chunk);
            foreach ((array) $wpdb->get_results("SELECT id, wc_order_id, date_created FROM $t WHERE id IN ($in) OR wc_order_id IN ($in)") as $r) {
                $out[intval($r->id)] = (string) $r->date_created;
                if (intval($r->wc_order_id) > 0 && !isset($out[intval($r->wc_order_id)])) $out[intval($r->wc_order_id)] = (string) $r->date_created;
            }
        }
        return $out;
    }

    // ═══════════════ Rechnen ═══════════════

    /**
     * Zahlen je Event und gesamt im Zeitraum (leer = gesamt).
     * @return array {events: [eid => figures], totals: figures, rows: Bestell-Zeilen mit money (für Kanäle o. ä.)}
     */
    public static function for_events(array $event_ids, $from = '', $to = '') {
        $event_ids = array_values(array_unique(array_filter(array_map('intval', $event_ids))));
        $ev = [];
        foreach ($event_ids as $id) { $ev[$id] = self::empty_figures(0); $ev[$id]['_cats'] = self::categories($id); $ev[$id]['_cat'] = []; $ev[$id]['_extra'] = []; $ev[$id]['_orders'] = []; }
        $rows = $event_ids ? self::order_rows($event_ids, $from, $to) : [];
        return self::aggregate($ev, $rows, $from, $to, true);
    }

    /** Zahlen für bestimmte Bestellungen (Kassenbericht): Tickets nur aus diesen Bestellungen. */
    public static function for_orders(array $order_ids, array $event_ids = []) {
        $order_ids = array_values(array_unique(array_filter(array_map('intval', $order_ids))));
        $rows = $order_ids ? self::order_rows($event_ids, '', '', $order_ids) : [];
        $ev = [];
        foreach ($rows as $r) {
            $id = intval($r->eid);
            if (!isset($ev[$id])) { $ev[$id] = self::empty_figures(0); $ev[$id]['_cats'] = self::categories($id); $ev[$id]['_cat'] = []; $ev[$id]['_extra'] = []; $ev[$id]['_orders'] = []; }
        }
        return self::aggregate($ev, $rows, '', '', false, $order_ids);
    }

    private static function aggregate(array $ev, array $rows, $from, $to, $all_tickets, array $only_orders = []) {
        $items = $rows ? self::items(array_map(function ($r) { return $r->id; }, $rows)) : [];
        $out_rows = [];
        foreach ($rows as $r) {
            $eid = intval($r->eid);
            if (!isset($ev[$eid])) continue;
            $m = self::money($r);
            $day = substr((string) $r->d, 0, 10);
            $f = &$ev[$eid];
            $f['revenue'] += $m['net']; $f['revenue_gross'] += $m['gross']; $f['refunds'] += $m['refund'];
            $f['fees'] += $m['fee']; $f['discounts'] += $m['discount'];
            if ($m['paid']) $f['_orders'][intval($r->id)] = 1;
            if (!isset($f['by_day'][$day])) $f['by_day'][$day] = ['revenue' => 0.0, 'tickets' => 0, 'orders' => 0];
            $f['by_day'][$day]['revenue'] += $m['net'];
            if ($m['paid']) $f['by_day'][$day]['orders']++;
            // Umsatz anteilig auf die Positionen (Listenpreis) verteilen → Kategorien summieren sich zum Umsatz
            $its = $items[intval($r->id)][$eid] ?? [];
            $lt = array_sum(array_column($its, 'total'));
            $names = [];
            foreach ($f['_cats'] as $i => $c) $names[mb_strtolower($c['name'])] = $i;
            foreach ($its as $k => $it) {
                $rev = $lt > 0 ? $m['net'] * $it['total'] / $lt : ($k === 0 ? $m['net'] : 0.0);
                $key = mb_strtolower($it['name']);
                if (isset($names[$key])) {
                    $ci = $names[$key];
                    if (!isset($f['_cat'][$ci])) $f['_cat'][$ci] = ['revenue' => 0.0, 'items' => 0];
                    $f['_cat'][$ci]['revenue'] += $rev; $f['_cat'][$ci]['items'] += $it['qty'];
                } else {
                    if (!isset($f['_extra'][$it['name']])) $f['_extra'][$it['name']] = ['revenue' => 0.0, 'items' => 0];
                    $f['_extra'][$it['name']]['revenue'] += $rev; $f['_extra'][$it['name']]['items'] += $it['qty'];
                }
            }
            unset($f);
            $r->money = $m;
            $out_rows[] = $r;
        }
        // Tickets aus den Ticket-Posts
        $tickets = $ev ? self::ticket_rows(array_keys($ev)) : [];
        $dates = self::order_dates(array_column($tickets, 'oid'));
        $only = $only_orders ? array_flip($only_orders) : null;
        $sold_all = []; $order_tickets = []; $oet = [];
        foreach ($tickets as $tk) {
            $eid = $tk['eid'];
            if (!isset($ev[$eid]) || !isset($ev[$eid]['_cats'][$tk['cat']])) continue; // keine echte Kategorie
            $sold_all[$eid][$tk['cat']] = ($sold_all[$eid][$tk['cat']] ?? 0) + 1;
            if ($only !== null && !isset($only[$tk['oid']])) continue;
            $d = substr($tk['oid'] && isset($dates[$tk['oid']]) ? $dates[$tk['oid']] : $tk['date'], 0, 10);
            if (($from && $d < $from) || ($to && $d > $to)) continue;
            $f = &$ev[$eid];
            $f['tickets']++;
            if ($tk['oid']) { $order_tickets[$tk['oid']] = ($order_tickets[$tk['oid']] ?? 0) + 1; $oet[$tk['oid']][$eid] = ($oet[$tk['oid']][$eid] ?? 0) + 1; }
            if ($tk['checked']) $f['checked_in']++;
            if (!isset($f['_cat'][$tk['cat']])) $f['_cat'][$tk['cat']] = ['revenue' => 0.0, 'items' => 0];
            $f['_cat'][$tk['cat']]['tickets'] = ($f['_cat'][$tk['cat']]['tickets'] ?? 0) + 1;
            if (!isset($f['by_day'][$d])) $f['by_day'][$d] = ['revenue' => 0.0, 'tickets' => 0, 'orders' => 0];
            $f['by_day'][$d]['tickets']++;
            unset($f);
        }
        $totals = self::empty_figures(0);
        $tcat = []; $tord = [];
        foreach ($ev as $eid => &$f) {
            list($f['capacity'], $f['unlimited']) = self::capacity($eid, $sold_all[$eid] ?? []);
            $bc = [];
            foreach ($f['_cats'] as $i => $c) {
                $x = $f['_cat'][$i] ?? [];
                $bc[] = ['index' => $i, 'name' => $c['name'], 'tickets' => intval($x['tickets'] ?? 0), 'items' => intval($x['items'] ?? 0), 'revenue' => round(floatval($x['revenue'] ?? 0), 2)];
            }
            foreach ($f['_extra'] as $n => $x) $bc[] = ['index' => null, 'name' => (string) $n, 'tickets' => 0, 'items' => $x['items'], 'revenue' => round($x['revenue'], 2)];
            $f['by_category'] = $bc;
            $f['orders'] = count($f['_orders']);
            foreach ($f['_orders'] as $oid => $_) $tord[$oid] = 1;
            ksort($f['by_day']);
            foreach ($f['by_day'] as $d => &$x) $x['revenue'] = round($x['revenue'], 2);
            unset($x);
            foreach (['revenue', 'revenue_gross', 'refunds', 'fees', 'discounts'] as $k) { $f[$k] = round($f[$k], 2); $totals[$k] += $f[$k]; }
            foreach (['tickets', 'checked_in', 'capacity'] as $k) $totals[$k] += $f[$k];
            if ($f['unlimited']) $totals['unlimited'] = true;
            foreach ($f['by_day'] as $d => $x) {
                if (!isset($totals['by_day'][$d])) $totals['by_day'][$d] = ['revenue' => 0.0, 'tickets' => 0, 'orders' => 0];
                $totals['by_day'][$d]['revenue'] += $x['revenue']; $totals['by_day'][$d]['tickets'] += $x['tickets'];
            }
            foreach ($bc as $c) {
                $k = mb_strtolower($c['name']);
                if (!isset($tcat[$k])) $tcat[$k] = ['index' => $c['index'], 'name' => $c['name'], 'tickets' => 0, 'items' => 0, 'revenue' => 0.0];
                $tcat[$k]['tickets'] += $c['tickets']; $tcat[$k]['items'] += $c['items']; $tcat[$k]['revenue'] += $c['revenue'];
            }
            unset($f['_cats'], $f['_cat'], $f['_extra'], $f['_orders']);
        }
        unset($f);
        // Bestellungen je Tag gesamt: eine Bestellung über mehrere Events zählt einmal
        foreach ($out_rows as $r) { if ($r->money['paid']) $tday[substr((string) $r->d, 0, 10)][intval($r->id)] = 1; }
        foreach ($tday ?? [] as $d => $o) if (isset($totals['by_day'][$d])) $totals['by_day'][$d]['orders'] = count($o);
        ksort($totals['by_day']);
        foreach ($totals['by_day'] as &$x) $x['revenue'] = round($x['revenue'], 2);
        unset($x);
        foreach (['revenue', 'revenue_gross', 'refunds', 'fees', 'discounts'] as $k) $totals[$k] = round($totals[$k], 2);
        $totals['orders'] = count($tord);
        $totals['by_category'] = array_values(array_map(function ($c) { $c['revenue'] = round($c['revenue'], 2); return $c; }, $tcat));
        return ['events' => $ev, 'totals' => $totals, 'rows' => $out_rows, 'order_tickets' => $order_tickets, 'order_event_tickets' => $oet];
    }

    /** Kurzform für Antworten (ohne Listen). */
    public static function summary(array $f) {
        return array_intersect_key($f, array_flip(['tickets', 'checked_in', 'capacity', 'unlimited', 'revenue', 'revenue_gross', 'refunds', 'discounts', 'fees', 'orders']));
    }
}
