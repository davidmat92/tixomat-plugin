<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat — Ticket-Kategorie-Reparatur
 *
 * Bei der Migration von WooCommerce/Tickera-Setups passiert es, dass Tickets
 * nur mit `_tix_ticket_cat_index` (oft 0) gespeichert werden, ohne dass der
 * korrekte `_tix_ticket_cat_name` mitgeschrieben wird. Wenn die alte Struktur
 * mehr Kategorien hatte als die neue, landen z.B. VIP-Tickets als "Standard".
 *
 * Diese Klasse holt aus der wp_tix_order_items.name die echte Kategorie
 * heraus und setzt sie via Heuristik korrekt.
 *
 * Workflow:
 *   1. analyze_event($event_id)         → Vorschau (Dry-Run): was würde geändert?
 *   2. apply_event($event_id)           → Schreibt Änderungen tatsächlich rein
 *
 * @since 1.38.169
 */
class TIX_Ticket_Category_Repair {

    /**
     * Heuristik: Kategorie-Name aus Produkt-Namen extrahieren.
     * Reihenfolge ist wichtig — spezifischere Patterns zuerst!
     *
     * @param string $product_name  Item-Name aus tix_order_items
     * @param array  $fallback_cats Aktuelle Event-Kategorien — fuer Phase-Fallback
     */
    private static function extract_category_name(string $product_name, array $fallback_cats = []): string {
        $n = strtolower($product_name);

        // Special-Variants zuerst (enthält "Standard"-Substring)
        if (preg_match('/special[-\s]+(standard|premium|vip)/i', $product_name, $m)) {
            // "Special-Standard" / "Special-VIP" beibehalten
            return ucwords(trim($m[0]), '-');
        }
        if (strpos($n, 'bierfass') !== false || strpos($n, 'bier-fass') !== false) {
            return '5-Liter-Bierfass';
        }
        // Reine Kategorie-Marker
        if (strpos($n, 'vip') !== false)      return 'VIP';
        if (strpos($n, 'premium') !== false)  return 'Premium';
        if (strpos($n, 'standard') !== false) return 'Standard';
        if (strpos($n, 'early') !== false)    return 'Early-Bird';
        if (strpos($n, 'student') !== false)  return 'Student';

        // Phase-Pattern: "PHASE 1 - …", "PHASE 2 - …", "Phase 3" — Tickera-Legacy ohne Kategorie
        // → 1. Event-Kategorie als Fallback (die "Standard"-Kategorie)
        if (preg_match('/^\s*phase\s*\d+/i', $product_name)) {
            return !empty($fallback_cats[0]['name']) ? (string) $fallback_cats[0]['name'] : 'Standard';
        }

        return ''; // unbekannt
    }

    /**
     * Findet das passende Order-Item zu einem Ticket via Order-ID + Preis-Match.
     */
    private static function find_matching_item(int $order_id, int $event_id, float $ticket_price, ?int $special_id = null) {
        global $wpdb;
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT id, quantity, total, name, cat_name, meta
             FROM {$wpdb->prefix}tix_order_items
             WHERE order_id = %d AND event_id = %d",
            $order_id, $event_id
        ));
        if (empty($items)) return null;

        // Special-Match: wenn Ticket einen special_id hat, finde Item mit gleichem special_id im meta
        if ($special_id) {
            foreach ($items as $it) {
                $meta = $it->meta ? json_decode($it->meta, true) : [];
                if (intval($meta['special_id'] ?? 0) === $special_id) return $it;
            }
        }

        // Bei nur einem Item: triviales Match
        if (count($items) === 1) return $items[0];

        // Preis-Match (auf 1 Cent genau)
        foreach ($items as $it) {
            $unit = $it->quantity > 0 ? floatval($it->total) / floatval($it->quantity) : 0;
            if (abs($unit - $ticket_price) < 0.01) return $it;
        }

        // Toleranter: nächst-passender Preis
        $best = null;
        $best_diff = PHP_FLOAT_MAX;
        foreach ($items as $it) {
            $unit = $it->quantity > 0 ? floatval($it->total) / floatval($it->quantity) : 0;
            $diff = abs($unit - $ticket_price);
            if ($diff < $best_diff) { $best_diff = $diff; $best = $it; }
        }
        return $best; // bestes match — auch wenn nicht exakt
    }

    /**
     * Liest Tickets eines Events + ermittelt die Soll-Kategorie pro Ticket.
     *
     * @return array {
     *   'changes':   [{ticket_id, code, old_cat_name, old_cat_idx, new_cat_name, source, item_name, ticket_price}, ...],
     *   'unchanged': int (Anzahl Tickets die nicht angefasst werden),
     *   'no_match':  [{ticket_id, code, reason}, ...] (kein Order-Item gefunden),
     *   'new_cats':  [name, ...]  (Kategorien die im Event noch nicht existieren — werden bei apply auto-angelegt),
     *   'event_cats': [{name, qty}, ...]  (aktueller Event-Kategorien-Stand)
     * }
     */
    public static function analyze_event(int $event_id): array {
        global $wpdb;

        // Aktuelle Event-Kategorien (Soll-Stand)
        $event_cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($event_cats)) $event_cats = [];
        $cat_name_to_idx = [];
        foreach ($event_cats as $i => $c) {
            $cat_name_to_idx[strtolower(trim($c['name'] ?? ''))] = $i;
        }

        // Alle Tickets des Events laden
        $ticket_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_tix_ticket_event_id'
             WHERE p.post_type = 'tix_ticket' AND p.post_status = 'publish' AND m.meta_value = %d",
            $event_id
        ));

        $changes   = [];
        $unchanged = 0;
        $no_match  = [];
        $new_cats  = [];

        // Helper: Fallback ueber cat_index, wenn das Event diesen Index hat.
        $resolve_via_index = function($cur_idx) use ($event_cats) {
            if ($cur_idx === '' || $cur_idx === false || $cur_idx === null) return '';
            $idx = intval($cur_idx);
            if ($idx < 0) return '';
            return isset($event_cats[$idx]['name']) ? (string) $event_cats[$idx]['name'] : '';
        };

        foreach ($ticket_ids as $tid) {
            $tid = intval($tid);
            $code        = get_post_meta($tid, '_tix_ticket_code', true);
            $order_id    = intval(get_post_meta($tid, '_tix_ticket_order_id', true));
            $cur_name    = get_post_meta($tid, '_tix_ticket_cat_name', true);
            $cur_idx     = get_post_meta($tid, '_tix_ticket_cat_index', true);
            $price       = floatval(get_post_meta($tid, '_tix_ticket_price', true));
            $special_id  = intval(get_post_meta($tid, '_tix_ticket_special_id', true)) ?: null;
            $status      = get_post_meta($tid, '_tix_ticket_status', true);

            // Stornierte Tickets ueberspringen — werden eh nicht als verkauft gezaehlt
            if ($status === 'cancelled') {
                $unchanged++;
                continue;
            }

            $item = $order_id
                ? self::find_matching_item($order_id, $event_id, $price, $special_id)
                : null;

            // Detected Category — primaer aus Item, sonst aus cat_index-Fallback
            $detected = '';
            $source   = '';

            if ($item) {
                $detected = self::extract_category_name($item->name, $event_cats);
                $source   = $detected ? 'item-name' : '';
            }

            // Fallback 1: kein Item → cat_index-Lookup im Event
            if (!$detected) {
                $via_idx = $resolve_via_index($cur_idx);
                if ($via_idx) {
                    $detected = $via_idx;
                    $source   = 'cat-index';
                }
            }

            // Fallback 2: noch immer nichts → 1. Kategorie als Default
            if (!$detected && !empty($event_cats[0]['name'])) {
                $detected = (string) $event_cats[0]['name'];
                $source   = 'default-1st-category';
            }

            if (!$detected) {
                if (!$cur_name) {
                    $reason = $item
                        ? ('Kategorie nicht erkennbar aus "' . $item->name . '"')
                        : 'kein Order-Item gefunden';
                    $no_match[] = ['ticket_id' => $tid, 'code' => $code, 'reason' => $reason];
                } else {
                    $unchanged++;
                }
                continue;
            }

            // Vergleich mit aktueller Zuordnung — case-insensitive
            $detected_lc = strtolower(trim($detected));
            $current_lc  = strtolower(trim((string) $cur_name));
            if ($detected_lc === $current_lc) {
                $unchanged++;
                continue;
            }

            // Existiert die Kategorie im Event?
            $new_idx = $cat_name_to_idx[$detected_lc] ?? null;
            if ($new_idx === null) {
                // Wird bei Apply auto-angelegt
                if (!in_array($detected, $new_cats, true)) $new_cats[] = $detected;
            }

            $changes[] = [
                'ticket_id'    => $tid,
                'code'         => $code,
                'old_cat_name' => $cur_name ?: '(leer)',
                'old_cat_idx'  => $cur_idx === '' ? '(leer)' : $cur_idx,
                'new_cat_name' => $detected,
                'new_cat_idx'  => $new_idx,
                'source'       => $source,
                'item_name'    => $item ? $item->name : '(via ' . $source . ')',
                'ticket_price' => $price,
            ];
        }

        // Event-Cats fuer Vorschau aufbereiten
        $event_cats_disp = [];
        foreach ($event_cats as $c) {
            $event_cats_disp[] = ['name' => $c['name'] ?? '', 'qty' => intval($c['qty'] ?? 0)];
        }

        return [
            'event_id'   => $event_id,
            'changes'    => $changes,
            'unchanged'  => $unchanged,
            'no_match'   => $no_match,
            'new_cats'   => $new_cats,
            'event_cats' => $event_cats_disp,
            'total'      => count($ticket_ids),
        ];
    }

    /**
     * Wendet die Reparatur tatsaechlich an.
     *
     * @return array { 'updated' => int, 'created_cats' => [name, ...], 'errors' => [...] }
     */
    public static function apply_event(int $event_id): array {
        $analysis = self::analyze_event($event_id);

        // Neue Kategorien anlegen
        $event_cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($event_cats)) $event_cats = [];
        $cat_name_to_idx = [];
        foreach ($event_cats as $i => $c) {
            $cat_name_to_idx[strtolower(trim($c['name'] ?? ''))] = $i;
        }

        $created_cats = [];
        foreach ($analysis['new_cats'] as $new_name) {
            $lc = strtolower(trim($new_name));
            if (isset($cat_name_to_idx[$lc])) continue;

            // Defaults aus erstem bestehenden Cat uebernehmen (Felder-Schema), price = 0 (Admin justiert)
            $tpl = !empty($event_cats[0]) && is_array($event_cats[0]) ? $event_cats[0] : [];
            $new_cat = array_merge($tpl, [
                'name'           => $new_name,
                'price'          => 0,
                'sale_price'     => '',
                'qty'            => 0,
                'desc'           => '',
                'image_id'       => 0,
                'online'         => '0', // standardmaessig OFFLINE — Admin entscheidet ob aktivieren
                'offline_ticket' => '1', // legacy/migrated
                'phases'         => [],
            ]);
            $event_cats[] = $new_cat;
            $cat_name_to_idx[$lc] = count($event_cats) - 1;
            $created_cats[] = $new_name;
        }
        if ($created_cats) {
            update_post_meta($event_id, '_tix_ticket_categories', $event_cats);
        }

        // Tickets aktualisieren
        $updated = 0;
        $errors  = [];
        foreach ($analysis['changes'] as $ch) {
            $lc = strtolower(trim($ch['new_cat_name']));
            $idx = $cat_name_to_idx[$lc] ?? null;
            if ($idx === null) {
                $errors[] = 'Ticket ' . $ch['code'] . ': Kategorie-Index nicht gefunden';
                continue;
            }
            update_post_meta($ch['ticket_id'], '_tix_ticket_cat_name',  $ch['new_cat_name']);
            update_post_meta($ch['ticket_id'], '_tix_ticket_cat_index', $idx);
            $updated++;
        }

        return [
            'event_id'     => $event_id,
            'updated'      => $updated,
            'created_cats' => $created_cats,
            'errors'       => $errors,
        ];
    }
}
