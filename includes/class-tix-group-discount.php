<?php
/**
 * Tixomat – Gruppenrabatt (WooCommerce Integration)
 *
 * Prüft Warenkorb-Items pro Event, findet die passende Rabattstaffel
 * und fügt den Rabatt als negative Fee hinzu.
 */
if (!defined('ABSPATH')) exit;

class TIX_Group_Discount {

    public static function init() {
        add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'apply_combo_deals'], 10);
        add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'apply_bundle_deals'], 15);
        add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'apply_group_discount'], 20);
        add_filter('woocommerce_get_item_data',       [__CLASS__, 'display_cart_item_badge'], 10, 2);
        add_filter('woocommerce_cart_item_name',       [__CLASS__, 'bundle_cart_item_name'], 10, 3);
        add_filter('woocommerce_cart_item_name',       [__CLASS__, 'combo_cart_item_name'], 10, 3);
    }

    /**
     * Kombi-Ticket-Rabatt: Differenz zwischen Einzelpreisen und Kombi-Preis
     */
    public static function apply_combo_deals($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (did_action('woocommerce_cart_calculate_fees') > 1) return;

        // Cart-Items nach Kombi-Gruppen sammeln
        $groups = [];
        foreach ($cart->get_cart() as $key => $item) {
            if (empty($item['_tix_combo'])) continue;
            $gid = $item['_tix_combo']['group_id'];
            if (!isset($groups[$gid])) {
                $groups[$gid] = [
                    'label'       => $item['_tix_combo']['label'],
                    'total_price' => floatval($item['_tix_combo']['total_price']),
                    'item_count'  => intval($item['_tix_combo']['item_count']),
                    'items'       => [],
                    'qty'         => $item['quantity'], // Alle Items einer Gruppe haben gleiche qty
                ];
            }
            $groups[$gid]['items'][] = $item;
        }

        foreach ($groups as $gid => $group) {
            // Summe der Einzelpreise
            $sum_regular = 0;
            foreach ($group['items'] as $item) {
                $sum_regular += floatval($item['data']->get_price()) * $item['quantity'];
            }

            $combo_total = $group['total_price'] * $group['qty'];
            $discount = round($sum_regular - $combo_total, 2);

            if ($discount <= 0) continue;

            $fee_label = sprintf('🎫 Kombi-Rabatt: %s', $group['label']);
            $cart->add_fee($fee_label, -$discount, true);
        }
    }

    /**
     * Cart-Item-Name für Kombi-Items anpassen
     */
    public static function combo_cart_item_name($name, $cart_item, $cart_item_key) {
        if (!empty($cart_item['_tix_combo']['label'])) {
            $label = esc_html($cart_item['_tix_combo']['label']);
            if (strpos($name, '<a') !== false) {
                $name = preg_replace('/>(.*?)<\/a>/', '>🎫 ' . $label . ' – $1</a>', $name);
            } else {
                $name = '🎫 ' . $label . ' – ' . $name;
            }
        }
        return $name;
    }

    /**
     * Bundle Deal: "Kaufe X, zahle Y" – berechnet aus Cart-Item-Meta
     */
    public static function apply_bundle_deals($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (did_action('woocommerce_cart_calculate_fees') > 1) return;

        foreach ($cart->get_cart() as $key => $item) {
            if (empty($item['_tix_bundle'])) continue;

            $bundle = $item['_tix_bundle'];
            $buy    = intval($bundle['buy'] ?? 0);
            $pay    = intval($bundle['pay'] ?? 0);
            $label  = $bundle['label'] ?? '';
            $qty    = $item['quantity'];

            if ($buy < 2 || $pay < 1 || $pay >= $buy || $qty < $buy) continue;

            // Gratis-Tickets berechnen
            $free = floor($qty / $buy) * ($buy - $pay);
            if ($free <= 0) continue;

            $price_per = floatval($item['data']->get_price());
            $discount  = round($free * $price_per, 2);

            if ($discount <= 0) continue;

            $fee_name = $label ?: $item['data']->get_name();
            $fee_label = sprintf('🎁 %s (%d für %d)', $fee_name, $buy, $pay);

            $cart->add_fee($fee_label, -$discount, true);
        }
    }

    /**
     * Cart-Item-Name für Bundle-Items anpassen
     */
    public static function bundle_cart_item_name($name, $cart_item, $cart_item_key) {
        if (!empty($cart_item['_tix_bundle']['label'])) {
            $label = esc_html($cart_item['_tix_bundle']['label']);
            $buy   = intval($cart_item['_tix_bundle']['buy'] ?? 0);
            // Link beibehalten falls vorhanden
            if (strpos($name, '<a') !== false) {
                $name = preg_replace('/>(.*?)<\/a>/', '>🎁 ' . $label . '</a>', $name);
            } else {
                $name = '🎁 ' . $label;
            }
        }
        return $name;
    }

    /**
     * Gruppenrabatt als negative Fee im Warenkorb
     */
    public static function apply_group_discount($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (did_action('woocommerce_cart_calculate_fees') > 1) return;

        // 1. Warenkorb-Items nach Event gruppieren
        $events = [];
        $combo_groups_seen = []; // Pro Event: welche Kombi-Gruppen wurden schon gezählt?
        foreach ($cart->get_cart() as $key => $item) {
            $product_id = $item['product_id'];
            $event_id   = get_post_meta($product_id, '_tix_parent_event_id', true);
            if (!$event_id) continue;

            $is_bundle = !empty($item['_tix_bundle']);
            $is_combo  = !empty($item['_tix_combo']);

            if (!isset($events[$event_id])) {
                $events[$event_id] = [
                    'qty'               => 0,       // Gesamt-Einzeltickets (alle Typen)
                    'subtotal'          => 0,
                    'bundle_qty'        => 0,       // Anzahl Bundle-PAKETE
                    'bundle_subtotal'   => 0,
                    'raw_bundle_tickets'=> 0,       // Bundle-Einzeltickets (für Subtraktion)
                    'combo_qty'         => 0,       // Anzahl Kombi-PAKETE
                    'combo_subtotal'    => 0,
                    'raw_combo_tickets' => 0,       // Kombi-Einzeltickets (für Subtraktion)
                    'items'             => [],
                ];
                $combo_groups_seen[$event_id] = [];
            }

            $events[$event_id]['qty']      += $item['quantity'];
            $events[$event_id]['subtotal'] += $item['line_subtotal'];
            $events[$event_id]['items'][]   = $key;

            if ($is_bundle) {
                // Bundle-Pakete: Anzahl PAKETE zählen, nicht Einzeltickets
                // quantity=6 bei "Kaufe 6, zahle 5" → 1 Paket
                $bundle_buy = intval($item['_tix_bundle']['buy'] ?? 0);
                $bundle_pkgs = ($bundle_buy > 0) ? floor($item['quantity'] / $bundle_buy) : $item['quantity'];
                $events[$event_id]['bundle_qty']        += $bundle_pkgs;
                $events[$event_id]['bundle_subtotal']   += $item['line_subtotal'];
                $events[$event_id]['raw_bundle_tickets']+= $item['quantity'];
            }
            if ($is_combo) {
                // Kombi-Pakete zählen: Jede group_id nur EINMAL pro Event zählen
                // Ein Kombi-Paket erzeugt mehrere Cart-Items (eins pro Event im Paket),
                // aber für den Mengenrabatt zählt die Anzahl der PAKETE, nicht der Einzeltickets.
                $gid = $item['_tix_combo']['group_id'] ?? '';
                if ($gid && !isset($combo_groups_seen[$event_id][$gid])) {
                    $combo_groups_seen[$event_id][$gid] = true;
                    $events[$event_id]['combo_qty'] += $item['quantity']; // = Paket-Anzahl
                }
                $events[$event_id]['combo_subtotal']    += $item['line_subtotal'];
                $events[$event_id]['raw_combo_tickets'] += $item['quantity'];
            }
        }

        // 2. Für jedes Event prüfen ob Gruppenrabatt aktiv
        foreach ($events as $event_id => $data) {
            $gd = get_post_meta($event_id, '_tix_group_discount', true);
            if (empty($gd['enabled']) || empty($gd['tiers'])) continue;

            $combine_bundle = !empty($gd['combine_bundle']);
            $combine_combo  = !empty($gd['combine_combo']);
            $combine_phase  = !empty($gd['combine_phase']);

            // Phase-Check: wenn nicht kombinierbar, Mengenrabatt überspringen wenn Phase aktiv
            if (!$combine_phase && class_exists('TIX_Metabox')) {
                $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
                if (is_array($cats)) {
                    foreach ($cats as $cat) {
                        $ap = TIX_Metabox::get_active_phase($cat['phases'] ?? []);
                        if ($ap) continue 2; // Phase aktiv → Mengenrabatt überspringen
                    }
                }
            }

            // Relevante Menge und Betrag bestimmen (je nach Kombinierbarkeit)
            // Mengenrabatt zählt PAKETE, nicht Einzeltickets:
            // - Normale Tickets: qty = Anzahl Tickets
            // - Bundle-Pakete: bundle_qty = Anzahl PAKETE (nicht × buy)
            // - Kombi-Pakete: combo_qty = Anzahl PAKETE (nicht × enthaltene Events)
            $normal_qty = $data['qty'] - $data['raw_bundle_tickets'] - $data['raw_combo_tickets'];
            $relevant_qty      = $normal_qty;
            $relevant_subtotal = $data['subtotal'] - $data['bundle_subtotal'] - $data['combo_subtotal'];

            // Bundle-Pakete hinzufügen wenn kombinierbar
            if ($combine_bundle) {
                $relevant_qty      += $data['bundle_qty'];  // Paket-Anzahl
                $relevant_subtotal += $data['bundle_subtotal'];
            }

            // Kombi-Pakete hinzufügen wenn kombinierbar
            if ($combine_combo) {
                $relevant_qty      += $data['combo_qty'];   // Paket-Anzahl
                $relevant_subtotal += $data['combo_subtotal'];
            }

            if ($relevant_qty <= 0 || $relevant_subtotal <= 0) continue;

            // Passende Staffel finden (höchste die matched)
            $tiers = $gd['tiers'];
            usort($tiers, fn($a, $b) => $b['min_qty'] - $a['min_qty']);

            $matched = null;
            foreach ($tiers as $tier) {
                if ($relevant_qty >= $tier['min_qty']) {
                    $matched = $tier;
                    break;
                }
            }

            if (!$matched) continue;

            // 3. Rabatt berechnen (nur auf relevanten Betrag)
            $discount = $relevant_subtotal * ($matched['percent'] / 100);
            $discount = round($discount, 2);

            if ($discount <= 0) continue;

            // Event-Titel für die Beschreibung
            $event_title = get_the_title($event_id);
            $label = sprintf(
                'Mengenrabatt %d%% – %s (ab %d Tickets)',
                $matched['percent'],
                $event_title,
                $matched['min_qty']
            );

            // Negative Fee = Rabatt
            $cart->add_fee($label, -$discount, true);
        }
    }

    /**
     * Badge am Warenkorb-Item: Bundle-Info / Gruppenrabatt
     */
    public static function display_cart_item_badge($item_data, $cart_item) {
        // ── Kombi-Ticket Badge ──
        if (!empty($cart_item['_tix_combo'])) {
            $combo = $cart_item['_tix_combo'];
            $item_data[] = [
                'key'   => '🎫 Kombi',
                'value' => esc_html($combo['label']),
            ];
            return $item_data; // Kombi-Items brauchen keinen Mengenrabatt-Badge
        }

        // ── Bundle Deal Badge (aus Cart-Item-Meta) ──
        if (!empty($cart_item['_tix_bundle'])) {
            $bundle = $cart_item['_tix_bundle'];
            $buy    = intval($bundle['buy'] ?? 0);
            $pay    = intval($bundle['pay'] ?? 0);
            $qty    = $cart_item['quantity'];
            $free   = ($qty >= $buy) ? floor($qty / $buy) * ($buy - $pay) : 0;

            if ($free > 0) {
                $item_data[] = [
                    'key'   => '🎁 Paket',
                    'value' => sprintf('%d× zum Preis von %d – %d× gratis', $buy, $pay, $free),
                ];
            }
        }

        // ── Gruppenrabatt Badge ──
        $product_id = $cart_item['product_id'];
        $event_id   = get_post_meta($product_id, '_tix_parent_event_id', true);
        if (!$event_id) return $item_data;

        $gd = get_post_meta($event_id, '_tix_group_discount', true);
        if (empty($gd['enabled']) || empty($gd['tiers'])) return $item_data;

        // Gesamtmenge für dieses Event im Warenkorb
        // Pakete (Bundle + Kombi) als PAKETE zählen, nicht als Einzeltickets
        $total_qty = 0;
        $badge_combo_groups = [];
        foreach (WC()->cart->get_cart() as $item) {
            $pid = $item['product_id'];
            $eid = get_post_meta($pid, '_tix_parent_event_id', true);
            if ($eid != $event_id) continue;

            if (!empty($item['_tix_combo'])) {
                // Kombi: pro group_id nur einmal zählen = Paket-Anzahl
                $gid = $item['_tix_combo']['group_id'] ?? '';
                if ($gid && !isset($badge_combo_groups[$gid])) {
                    $badge_combo_groups[$gid] = true;
                    $total_qty += $item['quantity'];
                }
            } elseif (!empty($item['_tix_bundle'])) {
                // Bundle: Pakete zählen (quantity/buy), nicht Einzeltickets
                $b_buy = intval($item['_tix_bundle']['buy'] ?? 0);
                $total_qty += ($b_buy > 0) ? floor($item['quantity'] / $b_buy) : $item['quantity'];
            } else {
                $total_qty += $item['quantity'];
            }
        }

        // Passende Staffel
        $tiers = $gd['tiers'];
        usort($tiers, fn($a, $b) => $b['min_qty'] - $a['min_qty']);

        $matched = null;
        foreach ($tiers as $tier) {
            if ($total_qty >= $tier['min_qty']) {
                $matched = $tier;
                break;
            }
        }

        if ($matched) {
            $item_data[] = [
                'key'   => 'Mengenrabatt',
                'value' => '-' . $matched['percent'] . '%',
            ];
        } else {
            // Nächste Staffel anzeigen als Hinweis
            $next = null;
            $tiers_asc = $gd['tiers'];
            usort($tiers_asc, fn($a, $b) => $a['min_qty'] - $b['min_qty']);
            foreach ($tiers_asc as $tier) {
                if ($total_qty < $tier['min_qty']) {
                    $next = $tier;
                    break;
                }
            }
            if ($next) {
                $remaining = $next['min_qty'] - $total_qty;
                $item_data[] = [
                    'key'   => 'Mengenrabatt',
                    'value' => sprintf('Noch %d Ticket%s bis %d%%', $remaining, $remaining > 1 ? 's' : '', $next['percent']),
                ];
            }
        }

        return $item_data;
    }

    /**
     * Hilfsfunktion: Aktive Staffel für ein Event und Menge ermitteln
     */
    public static function get_active_tier($event_id, $qty) {
        $gd = get_post_meta($event_id, '_tix_group_discount', true);
        if (empty($gd['enabled']) || empty($gd['tiers'])) return null;

        $tiers = $gd['tiers'];
        usort($tiers, fn($a, $b) => $b['min_qty'] - $a['min_qty']);

        foreach ($tiers as $tier) {
            if ($qty >= $tier['min_qty']) return $tier;
        }
        return null;
    }
}
