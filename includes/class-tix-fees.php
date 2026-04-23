<?php
/**
 * TIX_Fees – Zentrale Gebührenberechnung
 *
 * Berechnet Plattform-Provision und Gateway-Gebühren.
 * Unterstützt globale Einstellungen mit Per-Organizer-Override.
 *
 * @since 1.34.183
 */
class TIX_Fees {

    /**
     * WooCommerce-Integration: Gebühren als WC Fee in den Warenkorb einhängen.
     */
    public static function init() {
        if (class_exists('WooCommerce')) {
            add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'wc_add_platform_fee'], 25);
            add_action('woocommerce_checkout_order_processed', [__CLASS__, 'wc_save_fee_meta'], 10, 1);
        }
    }

    /**
     * Fügt Plattform-Provision + Gateway-Fee als WC Cart Fee hinzu (nur wenn Kunde zahlt).
     */
    public static function wc_add_platform_fee($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        // KEIN did_action-Guard: WC leert Fees vor jedem Calc automatisch.

        // Items aus WC Cart sammeln
        $items = [];
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $event_id = $cart_item['tix_event_id'] ?? ($product ? get_post_meta($product->get_id(), '_tix_event_id', true) : 0);
            $items[] = [
                'price'    => floatval($cart_item['line_subtotal'] / max(1, $cart_item['quantity'])),
                'qty'      => intval($cart_item['quantity']),
                'event_id' => intval($event_id),
            ];
        }

        if (empty($items)) return;

        $fee_data = self::calc_order_fees($items);

        // Nur Kunden-seitige Gebühren als WC Fee hinzufügen
        if ($fee_data['customer_fee_line'] > 0) {
            $label = $fee_data['fee_label'] ?: 'Servicegebühr';
            $cart->add_fee($label, $fee_data['customer_fee_line'], true);
        }
    }

    /**
     * Speichert Gebühren-Meta bei WC Order Erstellung.
     */
    public static function wc_save_fee_meta($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $event_id = $product ? get_post_meta($product->get_id(), '_tix_event_id', true) : 0;
            $items[] = [
                'price'    => floatval($item->get_subtotal() / max(1, $item->get_quantity())),
                'qty'      => intval($item->get_quantity()),
                'event_id' => intval($event_id),
            ];
        }

        if (empty($items)) return;

        $fee_data = self::calc_order_fees($items);
        $order->update_meta_data('_tix_platform_fee', $fee_data['platform_fee']);
        $order->update_meta_data('_tix_gateway_fee', $fee_data['gateway_fee']);
        $order->update_meta_data('_tix_organizer_payout', $fee_data['organizer_payout']);
        $order->update_meta_data('_tix_fee_data', $fee_data);
        $order->save();
    }

    /**
     * Gibt die effektiven Gebühren-Einstellungen zurück.
     * Prüft zuerst Organizer-Override, fällt auf Global zurück.
     *
     * @param int|null $organizer_id  Organizer CPT Post-ID (optional)
     * @return array {
     *   fee_fixed, fee_percent, fee_mode, fee_label,
     *   gateway_fee_fixed, gateway_fee_percent, gateway_fee_mode
     * }
     */
    public static function get_fee_config($organizer_id = null): array {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];

        $config = [
            'fee_fixed'           => floatval($s['fee_fixed'] ?? 0),
            'fee_percent'         => floatval($s['fee_percent'] ?? 0),
            'fee_mode'            => $s['fee_mode'] ?? 'organizer',
            'fee_label'           => $s['fee_label'] ?? 'Servicegebühr',
            'gateway_fee_fixed'   => floatval($s['gateway_fee_fixed'] ?? 0),
            'gateway_fee_percent' => floatval($s['gateway_fee_percent'] ?? 0),
            'gateway_fee_mode'    => $s['gateway_fee_mode'] ?? 'organizer',
            'fee_rounding'        => $s['fee_rounding'] ?? 'none',
            'fee_rounding_custom' => floatval($s['fee_rounding_custom'] ?? 0),
            'fee_max_per_ticket'  => floatval($s['fee_max_per_ticket'] ?? 0),
            'fee_max_per_order'   => floatval($s['fee_max_per_order'] ?? 0),
            'fee_show_in_selector'=> intval($s['fee_show_in_selector'] ?? 0),
        ];

        // Per-Organizer Override
        if ($organizer_id && get_post_meta($organizer_id, '_tix_fee_override', true)) {
            $config['fee_fixed']   = floatval(get_post_meta($organizer_id, '_tix_fee_fixed', true));
            $config['fee_percent'] = floatval(get_post_meta($organizer_id, '_tix_fee_percent', true));
            $config['fee_mode']    = get_post_meta($organizer_id, '_tix_fee_mode', true) ?: 'organizer';
            $label = get_post_meta($organizer_id, '_tix_fee_label', true);
            if (!empty($label)) $config['fee_label'] = $label;
            $org_max_ticket = floatval(get_post_meta($organizer_id, '_tix_fee_max_per_ticket', true));
            $org_max_order  = floatval(get_post_meta($organizer_id, '_tix_fee_max_per_order', true));
            if ($org_max_ticket > 0) $config['fee_max_per_ticket'] = $org_max_ticket;
            if ($org_max_order > 0)  $config['fee_max_per_order']  = $org_max_order;
        }

        return $config;
    }

    /**
     * Ermittelt die Organizer-ID für ein Event.
     *
     * @param int $event_id  Event Post-ID
     * @return int|null  Organizer CPT Post-ID oder null
     */
    public static function get_organizer_for_event($event_id) {
        $org_id = get_post_meta($event_id, '_tix_organizer_id', true);
        return $org_id ? intval($org_id) : null;
    }

    /**
     * Berechnet die Plattform-Provision für ein einzelnes Ticket.
     *
     * @param float    $ticket_price  Ticketpreis (netto, ohne Gebühren)
     * @param int|null $organizer_id  Organizer CPT Post-ID
     * @return float  Provisions-Betrag
     */
    public static function calc_platform_fee($ticket_price, $organizer_id = null, $cfg = null): float {
        if (!$cfg) $cfg = self::get_fee_config($organizer_id);
        $fee = $cfg['fee_fixed'] + ($ticket_price * $cfg['fee_percent'] / 100);
        // Max pro Ticket (wird ggf. von Max pro Bestellung in calc_order_fees überschrieben)
        if (!empty($cfg['fee_max_per_ticket']) && $cfg['fee_max_per_ticket'] > 0) {
            $fee = min($fee, $cfg['fee_max_per_ticket']);
        }
        return round($fee, 2);
    }

    /**
     * Berechnet die Gateway-Gebühr für einen Gesamtbetrag.
     * Löst das Zirkularitäts-Problem wenn Kunde zahlt.
     *
     * @param float  $charge_amount  Betrag der über Gateway geht
     * @param string $gw_mode        'organizer' oder 'customer'
     * @param array  $cfg            Fee-Config (optional, sonst global)
     * @return float  Gateway-Gebühr
     */
    public static function calc_gateway_fee($charge_amount, $gw_mode = null, $cfg = null): float {
        if (!$cfg) $cfg = self::get_fee_config();
        if (!$gw_mode) $gw_mode = $cfg['gateway_fee_mode'];

        $gw_fixed = $cfg['gateway_fee_fixed'];
        $gw_pct   = $cfg['gateway_fee_percent'];

        if ($gw_pct <= 0 && $gw_fixed <= 0) return 0;

        if ($gw_mode === 'customer') {
            // Zirkularitäts-Auflösung: Gateway berechnet auf den Gesamtbetrag inkl. Gateway-Fee
            $total = ($charge_amount + $gw_fixed) / (1 - $gw_pct / 100);
            return round($total - $charge_amount, 2);
        }

        // Organizer zahlt: einfache Berechnung
        return round($gw_fixed + ($charge_amount * $gw_pct / 100), 2);
    }

    /**
     * Rundet einen Betrag auf den nächsten passenden Nachkomma-Wert auf.
     *
     * Beispiel: round_up_to_target(52.37, '0.90') → 52.90
     *           round_up_to_target(52.95, '0.90') → 53.90
     *           round_up_to_target(52.90, '0.90') → 52.90
     *
     * @param float  $amount          Der zu rundende Betrag
     * @param string $rounding_mode   '0.90', '0.99', '0.50', '0.00', 'custom'
     * @param float  $custom_target   Eigener Nachkomma-Wert (nur bei 'custom')
     * @return float  Der aufgerundete Betrag
     */
    public static function round_up_to_target($amount, $rounding_mode, $custom_target = 0): float {
        if ($rounding_mode === 'none') return $amount;

        $target = match ($rounding_mode) {
            '0.90'   => 0.90,
            '0.99'   => 0.99,
            '0.50'   => 0.50,
            '0.00'   => 0.00,
            'custom' => max(0, min(0.99, floatval($custom_target))),
            default  => 0,
        };

        $floor = floor($amount);
        $target_amount = $floor + $target;

        // Wenn der Betrag bereits kleiner/gleich dem Ziel ist → Ziel nehmen
        // Wenn der Betrag größer ist → nächste volle Einheit + Ziel
        if ($amount <= $target_amount + 0.001) { // 0.001 Toleranz für Floating-Point
            return round($target_amount, 2);
        }
        return round($floor + 1 + $target, 2);
    }

    /**
     * Berechnet die vollständige Gebühren-Aufstellung für einen Warenkorb.
     *
     * @param array $items Array von ['price' => float, 'qty' => int, 'event_id' => int]
     * @return array {
     *   subtotal, platform_fee, platform_fee_mode, fee_label,
     *   gateway_fee, gateway_fee_mode,
     *   customer_total, organizer_payout, platform_revenue,
     *   customer_fee_line (Betrag der dem Kunden angezeigt wird, 0 wenn Veranstalter zahlt)
     * }
     */
    public static function calc_order_fees(array $items): array {
        if (empty($items)) {
            return [
                'subtotal'          => 0,
                'platform_fee'      => 0,
                'platform_fee_mode' => 'organizer',
                'fee_label'         => 'Servicegebühr',
                'gateway_fee'       => 0,
                'gateway_fee_mode'  => 'organizer',
                'customer_total'    => 0,
                'customer_fee_line' => 0,
                'organizer_payout'  => 0,
                'platform_revenue'  => 0,
            ];
        }

        // Ermittle Organizer (alle Items eines Warenkorbs gehören typischerweise einem Veranstalter)
        $first_event = $items[0]['event_id'] ?? null;
        $organizer_id = $first_event ? self::get_organizer_for_event($first_event) : null;
        $cfg = self::get_fee_config($organizer_id);

        // Plattform-Fee pro Ticket (mit Max pro Ticket)
        $subtotal     = 0;
        $platform_fee = 0;
        foreach ($items as $item) {
            $price = floatval($item['price']);
            $qty   = intval($item['qty']);
            $subtotal     += $price * $qty;
            $platform_fee += self::calc_platform_fee($price, $organizer_id, $cfg) * $qty;
        }
        $platform_fee = round($platform_fee, 2);

        // Max pro Bestellung (überschreibt pro Ticket)
        if (!empty($cfg['fee_max_per_order']) && $cfg['fee_max_per_order'] > 0) {
            $platform_fee = min($platform_fee, round($cfg['fee_max_per_order'], 2));
        }

        // Charge-Base für Gateway
        $charge_base = $subtotal;
        if ($cfg['fee_mode'] === 'customer') {
            $charge_base += $platform_fee;
        }

        // Gateway-Fee
        $gateway_fee = self::calc_gateway_fee($charge_base, $cfg['gateway_fee_mode'], $cfg);

        // Endbeträge
        $customer_fee_line = 0;
        if ($cfg['fee_mode'] === 'customer') {
            $customer_fee_line += $platform_fee;
        }
        if ($cfg['gateway_fee_mode'] === 'customer') {
            $customer_fee_line += $gateway_fee;
        }
        $customer_fee_line = round($customer_fee_line, 2);

        $customer_total = round($subtotal + $customer_fee_line, 2);

        // ── Rundung (nur wenn Kunde Gebühren trägt) ──
        $rounding_surplus = 0;
        if ($customer_fee_line > 0 && $cfg['fee_rounding'] !== 'none') {
            $rounded_total = self::round_up_to_target($customer_total, $cfg['fee_rounding'], $cfg['fee_rounding_custom']);
            if ($rounded_total > $customer_total) {
                $rounding_surplus = round($rounded_total - $customer_total, 2);
                $customer_fee_line = round($customer_fee_line + $rounding_surplus, 2);
                $customer_total = $rounded_total;
            }
        }

        $organizer_deductions = 0;
        if ($cfg['fee_mode'] === 'organizer') {
            $organizer_deductions += $platform_fee;
        }
        if ($cfg['gateway_fee_mode'] === 'organizer') {
            $organizer_deductions += $gateway_fee;
        }
        $organizer_payout = round($subtotal - $organizer_deductions, 2);

        return [
            'subtotal'          => round($subtotal, 2),
            'platform_fee'      => $platform_fee,
            'platform_fee_mode' => $cfg['fee_mode'],
            'fee_label'         => $cfg['fee_label'],
            'gateway_fee'       => $gateway_fee,
            'gateway_fee_mode'  => $cfg['gateway_fee_mode'],
            'customer_total'    => $customer_total,
            'customer_fee_line' => $customer_fee_line,
            'rounding_surplus'  => $rounding_surplus,
            'organizer_payout'  => $organizer_payout,
            'platform_revenue'  => round($platform_fee + $rounding_surplus, 2),
        ];
    }
}
