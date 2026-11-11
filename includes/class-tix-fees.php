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

    /** Wer trägt die Plattformgebühr: Veranstalter allein, geteilt mit dem Kunden, Kunde. */
    const MODES = ['organizer', 'split', 'customer'];

    /** Mehr-Veranstalter-Modus (evendis.de): nur der Modus ist je Veranstalter wählbar. */
    /** Bezeichnung der Gebühr: höchstens 30 Zeichen, nicht irreführend (Rechtsprüfung servicegebuehr.md Nr. 4, 1.38.752). */
    const LABEL_MAX = 30;
    const LABEL_DEFAULT = 'Servicegebühr';
    const LABEL_SUGGESTIONS = ['Servicegebühr', 'Vorverkaufsgebühr', 'Systemgebühr'];
    /** Wortteile, die eine Zahlungs-, Bank-, Karten- oder Versandgebühr oder eine staatliche Abgabe nahelegen */
    const LABEL_BLOCKED = ['zahlung', 'karte', 'kredit', 'transaktion', 'bank', 'paypal', 'sepa', 'lastschrift', 'überweis', 'ueberweis', 'klarna', 'apple pay', 'google pay',
        'versand', 'porto', 'liefer', 'steuer', 'abgabe', 'mwst', 'ust', 'gema', 'kultur', 'staat', 'behörd', 'behoerd', 'pflicht'];

    /** Fehlertext oder null, wenn die Bezeichnung zulässig ist. */
    public static function label_error($label) {
        $l = trim((string) $label);
        if ($l === '') return 'Bitte eine Bezeichnung angeben, z. B. „Servicegebühr“.';
        if (mb_strlen($l) > self::LABEL_MAX) return 'Die Bezeichnung darf höchstens ' . self::LABEL_MAX . ' Zeichen haben.';
        $low = mb_strtolower($l);
        foreach (self::LABEL_BLOCKED as $w) {
            if ($w === 'ust' ? preg_match('/\bust\b/u', $low) : mb_strpos($low, $w) !== false) {
                return 'Diese Bezeichnung ist nicht erlaubt: Sie klingt nach einer Zahlungs-, Versandgebühr oder einer Abgabe. Möglich sind z. B. „Servicegebühr“, „Vorverkaufsgebühr“ oder „Systemgebühr“.';
            }
        }
        return null;
    }

    /** Zulässige Bezeichnung (unzulässige oder leere → „Servicegebühr“). */
    public static function safe_label($label) {
        $l = trim((string) $label);
        return self::label_error($l) === null ? $l : self::LABEL_DEFAULT;
    }

    public static function multi(): bool {
        return function_exists('get_option') && get_option('tix_multi_organizer', '0') === '1';
    }

    /** Kundenanteil im Modus „geteilt“ in Prozent (zentral eingestellt, Vorgabe 50). */
    public static function split_share($settings = null): float {
        if ($settings === null) $settings = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $v = $settings['fee_split_customer_share'] ?? 50;
        if ($v === '' || $v === null) $v = 50;
        return max(0.0, min(100.0, floatval($v)));
    }

    /** Anteil (0–1) der Plattformgebühr, den der Kunde trägt. */
    public static function customer_share(array $cfg): float {
        $mode = $cfg['fee_mode'] ?? 'organizer';
        if ($mode === 'customer') return 1.0;
        if ($mode === 'split') return floatval($cfg['fee_split_share'] ?? 50) / 100;
        return 0.0;
    }

    /** Trägt der Kunde (ganz oder teilweise) die Plattformgebühr? */
    public static function customer_pays(array $cfg): bool {
        return self::customer_share($cfg) > 0;
    }

    /**
     * Gebühren-Konfiguration für clientseitige Anzeige (Modal-/Express-Checkout):
     * Beträge bereits auf den Kundenanteil umgerechnet; null, wenn der Kunde nichts trägt.
     */
    public static function client_fee_config(array $cfg) {
        $share = self::customer_share($cfg);
        if ($share <= 0) return null;
        return [
            'fixed'     => $cfg['fee_fixed'],
            'percent'   => $cfg['fee_percent'],
            'label'     => $cfg['fee_label'],
            'maxTicket' => $cfg['fee_max_per_ticket'],
            'maxOrder'  => $cfg['fee_max_per_order'],
            'share'     => round($share, 4),
        ];
    }

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
     * Mehr-Veranstalter-Modus: neu angelegte Veranstalter bekommen den Vorgabe-Modus
     * (Einstellung `fee_mode_new_org`, Vorgabe „Gäste zahlen“). Bestehende bleiben unverändert;
     * ein beim Anlegen ausdrücklich gesetzter Modus (`_tix_fee_override`) hat Vorrang.
     * `_tix_fee_mode_default=1` markiert „nicht selbst gewählt“ (REST `own_choice: false`).
     */
    public static function default_mode_for_new_organizer($post_id, $post, $update) {
        if ($update || !$post || $post->post_type !== 'tix_organizer' || !self::multi()) return;
        if (metadata_exists('post', $post_id, '_tix_fee_override')) return;
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $mode = (string) ($s['fee_mode_new_org'] ?? 'customer');
        if (!in_array($mode, self::MODES, true)) return;
        update_post_meta($post_id, '_tix_fee_override', 1);
        update_post_meta($post_id, '_tix_fee_mode', $mode);
        update_post_meta($post_id, '_tix_fee_mode_default', 1);
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
                'price'      => floatval($cart_item['line_subtotal'] / max(1, $cart_item['quantity'])),
                'qty'        => intval($cart_item['quantity']),
                'event_id'   => intval($event_id),
                'product_id' => $product ? intval($product->get_id()) : 0,
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
                'price'      => floatval($item->get_subtotal() / max(1, $item->get_quantity())),
                'qty'        => intval($item->get_quantity()),
                'event_id'   => intval($event_id),
                'product_id' => $product ? intval($product->get_id()) : 0,
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
            'gateway_fee_included'=> !empty($s['gateway_fee_included']),
            'fee_rounding'        => $s['fee_rounding'] ?? 'none',
            'fee_rounding_custom' => floatval($s['fee_rounding_custom'] ?? 0),
            'fee_max_per_ticket'  => floatval($s['fee_max_per_ticket'] ?? 0),
            'fee_max_per_order'   => floatval($s['fee_max_per_order'] ?? 0),
            'fee_show_in_selector'=> intval($s['fee_show_in_selector'] ?? 0),
        ];

        if (!in_array($config['fee_mode'], self::MODES, true)) $config['fee_mode'] = 'organizer';
        $config['fee_label'] = self::safe_label($config['fee_label']);
        $config['fee_split_share'] = self::split_share($s);

        // Partner-Anfrage mit Gebühr im evendis-Kanal: seit 1.38.760 zahlt der Käufer die eigene Gebühr der Quelle; die Kanal-Gebühr
        // trägt die Quelle (TIX_Channel_Fee::record) – die Konfiguration bleibt also die eigene (inkl. Veranstalter-Gebühr).

        // Mehr-Veranstalter-Modus (evendis): zentrale Gebühr; eigene Höhe je Veranstalter nur durch
        // Plattform-Admins (`_tix_fee_custom`), „wer trägt sie“ wählt der Veranstalter selbst (`_tix_fee_override`).
        if ($organizer_id && self::multi()) {
            $config['fee_custom'] = false;
            if (get_post_meta($organizer_id, '_tix_fee_custom', true)) {
                $config['fee_fixed']   = max(0.0, floatval(get_post_meta($organizer_id, '_tix_fee_fixed', true)));
                $config['fee_percent'] = max(0.0, min(100.0, floatval(get_post_meta($organizer_id, '_tix_fee_percent', true))));
                $config['fee_custom']  = true;
            }
            if (get_post_meta($organizer_id, '_tix_fee_override', true)) {
                $org_mode = (string) get_post_meta($organizer_id, '_tix_fee_mode', true);
                if (in_array($org_mode, self::MODES, true)) $config['fee_mode'] = $org_mode;
            }
            return $config;
        }

        // Per-Organizer Override
        if ($organizer_id && get_post_meta($organizer_id, '_tix_fee_override', true)) {
            $org_mode = (string) get_post_meta($organizer_id, '_tix_fee_mode', true);
            $config['fee_fixed']   = floatval(get_post_meta($organizer_id, '_tix_fee_fixed', true));
            $config['fee_percent'] = floatval(get_post_meta($organizer_id, '_tix_fee_percent', true));
            $config['fee_mode']    = in_array($org_mode, self::MODES, true) ? $org_mode : 'organizer';
            $label = get_post_meta($organizer_id, '_tix_fee_label', true);
            if (!empty($label)) $config['fee_label'] = self::safe_label($label);
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
    /**
     * Prueft, ob ein Cart-Item zu einer gebuehrenfreien Kategorie gehoert
     * (Kategorie-Flag 'no_fee' im Event-Editor).
     * Native Cart: event_id + cat_index. WC Cart: product_id → _tix_cat_index Meta.
     */
    public static function is_item_fee_exempt(array $item): bool {
        // Guthaben-Aufladung und Gutscheine tragen keine Gebühr (Rechtsprüfung servicegebuehr.md Nr. 8, 1.38.751)
        if (!empty($item['meta']['wallet_topup'])) return true;
        $event_id = intval($item['event_id'] ?? 0);
        if ($event_id <= 0) return false;
        if (get_post_meta($event_id, '_tix_system_giftcard_event', true) === '1') return true;

        $cat_index = null;
        if (isset($item['cat_index']) && intval($item['cat_index']) >= 0) {
            $cat_index = intval($item['cat_index']);
        } elseif (!empty($item['product_id'])) {
            $pi = get_post_meta(intval($item['product_id']), '_tix_cat_index', true);
            if ($pi !== '' && $pi !== null) $cat_index = intval($pi);
        }
        if ($cat_index === null) return false;

        static $cache = [];
        if (!isset($cache[$event_id])) {
            $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
            $cache[$event_id] = is_array($cats) ? $cats : [];
        }
        $cat = $cache[$event_id][$cat_index] ?? null;
        return is_array($cat) && (!empty($cat['no_fee']) || !empty($cat['gift_card']));
    }

    /**
     * Anzeige (PAngV, 1.38.750): Käuferanteil der Gebühr für EIN Ticket ohne Bestell-Effekte (Höchstbetrag je Bestellung,
     * Rundung, Zahlungskosten) – für die Summe der Ticketauswahl. 0 bei gebührenfreier Kategorie, Freiticket oder ohne Käuferanteil.
     */
    public static function ticket_fee($event_id, $cat_index, $price, $cfg = null): float {
        $price = floatval($price);
        if ($price <= 0) return 0.0;
        if ($cat_index !== null && self::is_item_fee_exempt(['event_id' => intval($event_id), 'cat_index' => intval($cat_index)])) return 0.0;
        if (!$cfg) $cfg = self::display_config($event_id);
        $share = self::customer_share($cfg);
        if ($share <= 0) return 0.0;
        return round(self::calc_platform_fee($price, null, $cfg) * $share, 2);
    }

    /**
     * Erstattung einzelner Tickets (Rechtsprüfung servicegebuehr.md Nr. 7, 1.38.752): Ticketpreis + anteilige Käufer-Gebühr.
     * Gebühr je Ticket aus der an der Bestellung gespeicherten Aufteilung (`lines`), skaliert auf die tatsächlich gezahlte Gebühr
     * (Höchstbetrag, Rundung); ältere Bestellungen: Verteilung nach der Gebührenformel, sonst gleichmäßig auf gebührenpflichtige Tickets.
     * Sind alle gültigen Tickets gewählt, gilt der volle offene Betrag (`all: true`, inkl. Rundungsrest).
     * @return array {tickets: [{id, price, fee, amount}], tickets_amount, fee, amount, all, fee_label} | WP_Error
     */
    public static function refund_quote($order_id, array $ticket_ids) {
        $order_id = intval($order_id);
        $all = get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC',
            'meta_query' => [['key' => '_tix_ticket_order_id', 'value' => (string) $order_id]]]);
        $valid = array_values(array_filter(array_map('intval', $all), function ($id) { return (string) (get_post_meta($id, '_tix_ticket_status', true) ?: 'valid') !== 'cancelled'; }));
        $want = array_values(array_unique(array_map('intval', $ticket_ids)));
        if (!$want) return new WP_Error('tix_invalid_field', 'Bitte mindestens ein Ticket wählen.', ['status' => 400, 'field' => 'tickets']);
        foreach ($want as $id) if (!in_array($id, $valid, true)) return new WP_Error('tix_invalid_field', 'Ticket ' . $id . ' gehört nicht zu dieser Bestellung oder ist schon storniert.', ['status' => 400, 'field' => 'tickets']);
        $fd = get_option('_tix_order_fees_' . $order_id, []);
        $F = round(floatval(is_array($fd) ? ($fd['customer_fee_line'] ?? 0) : 0), 2);
        $tk = function ($id) {
            $ci = get_post_meta($id, '_tix_ticket_cat_index', true);
            return ['id' => $id, 'event_id' => intval(get_post_meta($id, '_tix_ticket_event_id', true)), 'cat_index' => ($ci === '' || $ci === false) ? -1 : intval($ci),
                'price' => round(floatval(get_post_meta($id, '_tix_ticket_price', true)), 2)];
        };
        $tickets_all = array_map($tk, array_map('intval', $all));
        // Gewicht je Ticket (alle Tickets der Bestellung; schon stornierte haben ihren Anteil bereits zurückbekommen)
        $w = [];
        $lines = is_array($fd) ? (array) ($fd['lines'] ?? []) : [];
        if ($lines) {
            foreach ($tickets_all as $t) {
                $per = 0.0;
                foreach ($lines as $l) if (intval($l['event_id']) === $t['event_id'] && intval($l['cat_index']) === $t['cat_index'] && intval($l['qty']) > 0) { $per = floatval($l['fee']) / intval($l['qty']); break; }
                $w[$t['id']] = $per;
            }
        }
        if (!$lines || array_sum($w) <= 0) {
            $cfg = self::get_fee_config(self::get_organizer_for_event($tickets_all[0]['event_id'] ?? 0));
            foreach ($tickets_all as $t) $w[$t['id']] = ($t['price'] > 0 && !self::is_item_fee_exempt($t)) ? max(0.0, self::calc_platform_fee($t['price'], null, $cfg)) : 0.0;
            if (array_sum($w) <= 0) foreach ($tickets_all as $t) $w[$t['id']] = ($t['price'] > 0 && !self::is_item_fee_exempt($t)) ? 1.0 : 0.0;
        }
        $all_w = array_sum($w);
        $tickets = array_values(array_filter($tickets_all, function ($t) use ($valid) { return in_array($t['id'], $valid, true); }));
        // Rabatt/Gutschein: Ticketpreise auf den tatsächlich gezahlten Warenwert (Summe − Gebühr) skalieren
        global $wpdb;
        $paid = floatval($wpdb->get_var($wpdb->prepare("SELECT total FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id)));
        $sum_all = array_sum(array_column($tickets_all, 'price'));
        $k = ($sum_all > 0 && $paid - $F < $sum_all - 0.009) ? max(0.0, ($paid - $F) / $sum_all) : 1.0;
        $out = []; $sum_p = 0.0; $sum_f = 0.0;
        foreach ($tickets as $t) {
            if (!in_array($t['id'], $want, true)) continue;
            $fee = ($F > 0 && $all_w > 0) ? round($F * ($w[$t['id']] ?? 0) / $all_w, 2) : 0.0;
            $t['price'] = round($t['price'] * $k, 2);
            $out[] = ['id' => $t['id'], 'price' => $t['price'], 'fee' => $fee, 'amount' => round($t['price'] + $fee, 2)];
            $sum_p += $t['price']; $sum_f += $fee;
        }
        $is_all = count($want) === count($valid);
        return ['tickets' => $out, 'tickets_amount' => round($sum_p, 2), 'fee' => round($sum_f, 2), 'amount' => round($sum_p + $sum_f, 2), 'all' => $is_all,
            'fee_label' => (string) (is_array($fd) ? ($fd['fee_label'] ?? '') : '') ?: 'Servicegebühr'];
    }

    /**
     * Gebühr für die ANZEIGE eines Events (1.38.752): eigene Seite wie die Kasse (get_fee_config); Spiegel eines geteilten Events,
     * den diese Seite nicht selbst verkauft, mit der Gebühr, die der Käufer tatsächlich zahlt:
     *  - Verkauf über die Vermittlung mit Kanal-Gebühr des Partners → Kanal-Gebühr (wer sie trägt: Einstellung der Quelle),
     *  - sonst die eigene Gebühr der Quelle (`_tix_source_fee` aus der Verteilung); fehlt sie (alte Quelle) → keine Gebühr.
     */
    public static function display_config($event_id): array {
        $event_id = intval($event_id);
        $own = self::get_fee_config(self::get_organizer_for_event($event_id));
        if (!$event_id) return $own;
        // Regel des Erstellers gilt IMMER (User-Entscheide 11.11., 1.38.759/760) – auch wenn ein Co das Geld einnimmt und diese Seite
        // selbst verkauft. Kunden-Seite: Kopie eines Co-Events (`_tix_source_fee` aus dem Co-Abgleich); Plattform: Spiegel (`_tix_source_fee`
        // aus der Verteilung). Eine Kanal-Gebühr zahlt der Käufer nie (trägt die Quelle). Ohne mitgesendete Regel (ältere Gegenseite):
        // verkauft diese Seite selbst → eigene Regel, sonst keine Gebühr in der Anzeige.
        $co_copy = !self::multi() && intval(get_post_meta($event_id, '_tix_platform_co', true)) > 0;
        if (!$co_copy && get_post_meta($event_id, '_tix_syndicated', true) !== '1') return $own;
        $sells_here = $co_copy ? (class_exists('TIX_Co_Seller') && TIX_Co_Seller::sells_local($event_id)) : (class_exists('TIX_Co_Seller') && TIX_Co_Seller::sells_here($event_id));
        if (!metadata_exists('post', $event_id, '_tix_source_fee') && ($co_copy || $sells_here)) return $own;
        $src = get_post_meta($event_id, '_tix_source_fee', true);
        $src = is_array($src) ? $src : [];
        $none = ['fee_fixed' => 0.0, 'fee_percent' => 0.0, 'fee_mode' => 'organizer', 'fee_label' => self::LABEL_DEFAULT, 'gateway_fee_fixed' => 0.0, 'gateway_fee_percent' => 0.0,
            'gateway_fee_mode' => 'organizer', 'gateway_fee_included' => false, 'fee_rounding' => 'none', 'fee_rounding_custom' => 0.0, 'fee_max_per_ticket' => 0.0, 'fee_max_per_order' => 0.0,
            'fee_show_in_selector' => 0, 'fee_split_share' => 50.0, 'source_fee' => true];
        if (!$src) return $none;
        $g = (array) ($src['gateway'] ?? []);
        return array_merge($none, [
            'fee_fixed' => max(0.0, floatval($src['fixed'] ?? 0)), 'fee_percent' => max(0.0, min(100.0, floatval($src['percent'] ?? 0))),
            'fee_mode' => in_array($src['mode'] ?? '', self::MODES, true) ? $src['mode'] : 'organizer', 'fee_label' => self::safe_label($src['label'] ?? ''),
            'fee_max_per_ticket' => max(0.0, floatval($src['max_per_ticket'] ?? 0)), 'fee_max_per_order' => max(0.0, floatval($src['max_per_order'] ?? 0)),
            'fee_rounding' => in_array((string) ($src['rounding'] ?? 'none'), ['none', '0.90', '0.99', '0.50', '0.00', 'custom'], true) ? (string) $src['rounding'] : 'none',
            'fee_rounding_custom' => floatval($src['rounding_custom'] ?? 0), 'fee_split_share' => max(0.0, min(100.0, floatval($src['split_share'] ?? 50))),
            'gateway_fee_fixed' => max(0.0, floatval($g['fixed'] ?? 0)), 'gateway_fee_percent' => max(0.0, floatval($g['percent'] ?? 0)),
            'gateway_fee_mode' => ($g['mode'] ?? '') === 'customer' ? 'customer' : 'organizer', 'gateway_fee_included' => !empty($g['included']),
        ]);
    }

    /**
     * Plattform → Co-Seite (1.38.759, User-Regel „Ersteller ist Geldempfänger → seine Gebühr gilt überall“): Gebührenregel des
     * Erstellers für ein Co-Event. Spiegel einer Lizenzseite: die mitgesendete Regel der Quelle (`_tix_source_fee`, ohne Kanal-Gebühr –
     * der Verkauf über die Co-Seite läuft ohne Kanal-Gebühr); eigenes Plattform-Event: die Gebühr der Plattform für den Ersteller.
     * Seit 1.38.760 auch, wenn ein Co das Geld einnimmt (User: „immer die Regel des Erstellers“).
     */
    public static function creator_fee_rule($event_id) {
        $event_id = intval($event_id);
        if (get_post_meta($event_id, '_tix_syndicated', true) === '1') {
            $src = get_post_meta($event_id, '_tix_source_fee', true);
            return is_array($src) ? $src : ['fixed' => 0.0, 'percent' => 0.0, 'mode' => 'organizer', 'label' => self::LABEL_DEFAULT, 'gateway' => ['mode' => 'organizer']];
        }
        return self::source_fee_payload($event_id);
    }

    /** Für die Verteilung (Quelle → Plattform): eigene Gebühr dieses Events, ohne Kanal-Gebühr. */
    public static function source_fee_payload($event_id): array {
        $c = self::get_fee_config(self::get_organizer_for_event(intval($event_id)));
        return ['fixed' => round(floatval($c['fee_fixed']), 2), 'percent' => round(floatval($c['fee_percent']), 4), 'mode' => $c['fee_mode'], 'label' => (string) $c['fee_label'],
            'max_per_ticket' => round(floatval($c['fee_max_per_ticket']), 2), 'max_per_order' => round(floatval($c['fee_max_per_order']), 2), 'rounding' => (string) $c['fee_rounding'],
            'rounding_custom' => floatval($c['fee_rounding_custom']), 'split_share' => floatval($c['fee_split_share'] ?? 50),
            'gateway' => ['fixed' => round(floatval($c['gateway_fee_fixed']), 2), 'percent' => round(floatval($c['gateway_fee_percent']), 4), 'mode' => $c['gateway_fee_mode'], 'included' => !empty($c['gateway_fee_included'])],
            'channel_mode' => class_exists('TIX_Channel_Fee') ? TIX_Channel_Fee::mode() : 'customer'];
    }

    /** Endpreis „ab“/„bis“ eines Events aus den Preis-Feldern (TIX_Sync::apply_fee_display), Rückfall Ticketpreis. */
    public static function event_price_total($post_id, $which = 'min'): float {
        $t = get_post_meta(intval($post_id), $which === 'max' ? '_tix_price_max_total' : '_tix_price_min_total', true);
        if ($t !== '' && $t !== null && $t !== false) return floatval($t);
        return floatval(get_post_meta(intval($post_id), $which === 'max' ? '_tix_price_max' : '_tix_price_min', true));
    }

    /** Käuferanteil der Gebühr für eine Warenkorb-Zeile (vor Höchstbetrag je Bestellung und Rundung), 0 bei Ausnahmen. */
    public static function item_fee(array $item): float {
        $price = floatval($item['price'] ?? 0);
        $qty = max(0, intval($item['qty'] ?? 0));
        if ($price <= 0 || !$qty || self::is_item_fee_exempt($item)) return 0.0;
        $cfg = self::display_config(intval($item['event_id'] ?? 0));
        $share = self::customer_share($cfg);
        return $share > 0 ? round(self::calc_platform_fee($price, null, $cfg) * $share * $qty, 2) : 0.0;
    }

    /**
     * Anzeige (PAngV, 1.38.750): Gesamtpreis für den Kauf von $qty Tickets dieser Kategorie, genau wie die Kasse
     * (inkl. Höchstbeträge, Rundung, Zahlungskosten zu Lasten des Käufers) → {fee, total, label}.
     */
    public static function ticket_total($event_id, $cat_index, $price, $qty = 1, $cfg = null): array {
        if (!$cfg) $cfg = self::display_config($event_id);
        $item = ['price' => floatval($price), 'qty' => max(1, intval($qty)), 'event_id' => intval($event_id)];
        if ($cat_index !== null) $item['cat_index'] = intval($cat_index);
        $r = self::calc_with_config([$item], $cfg);
        return ['fee' => round(floatval($r['customer_fee_line']), 2), 'total' => round(floatval($r['customer_total']), 2), 'label' => (string) ($cfg['fee_label'] ?: 'Servicegebühr')];
    }

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
                'platform_fee_customer'  => 0,
                'platform_fee_organizer' => 0,
                'fee_customer_share'     => 0,
            ];
        }

        // Ermittle Organizer (alle Items eines Warenkorbs gehören typischerweise einem Veranstalter)
        $first_event = $items[0]['event_id'] ?? null;
        // Regel des Events: eigene bzw. bei Kopien/Spiegeln, die diese Seite verkauft, die des Erstellers (1.38.760)
        $cfg = $first_event ? self::display_config(intval($first_event)) : self::get_fee_config(null);

        return self::calc_with_config($items, $cfg);
    }

    /**
     * Gebühren-Aufstellung mit vorgegebener Konfiguration (auch für Beispielrechnungen
     * und Tests). Modus `split`: Kunde trägt `fee_split_share` % der Plattformgebühr
     * (kaufmännisch auf Cent gerundet), der Veranstalter den Rest; eine eingestellte
     * Rundung des Endbetrags gilt wie im Kunden-Modus, der Überschuss geht an die Plattform.
     */
    public static function calc_with_config(array $items, array $cfg): array {
        $cfg += [
            'fee_fixed' => 0, 'fee_percent' => 0, 'fee_mode' => 'organizer', 'fee_label' => 'Servicegebühr',
            'gateway_fee_fixed' => 0, 'gateway_fee_percent' => 0, 'gateway_fee_mode' => 'organizer',
            'fee_rounding' => 'none', 'fee_rounding_custom' => 0, 'fee_max_per_ticket' => 0,
            'fee_max_per_order' => 0, 'fee_split_share' => 50, 'gateway_fee_included' => false,
        ];
        if (!in_array($cfg['fee_mode'], self::MODES, true)) $cfg['fee_mode'] = 'organizer';

        // Plattform-Fee pro Ticket (mit Max pro Ticket)
        // Kategorien mit 'no_fee' = 1 sind gebuehrenfrei (Haken im Event-Editor)
        $subtotal     = 0;
        $platform_fee = 0;
        foreach ($items as $item) {
            $price = floatval($item['price']);
            $qty   = intval($item['qty']);
            $subtotal     += $price * $qty;
            if (self::is_item_fee_exempt($item)) continue;
            if ($price <= 0) continue; // Freitickets tragen keine Gebühr („Frei“ muss frei bleiben, 1.38.750)
            $platform_fee += self::calc_platform_fee($price, null, $cfg) * $qty;
        }
        $platform_fee = round($platform_fee, 2);

        // Max pro Bestellung (überschreibt pro Ticket)
        if (!empty($cfg['fee_max_per_order']) && $cfg['fee_max_per_order'] > 0) {
            $platform_fee = min($platform_fee, round($cfg['fee_max_per_order'], 2));
        }

        // Aufteilung Kunde / Veranstalter
        $share = self::customer_share($cfg);
        $platform_fee_customer  = round($platform_fee * $share, 2);
        $platform_fee_organizer = round($platform_fee - $platform_fee_customer, 2);

        // Charge-Base für Gateway
        $charge_base = $subtotal + $platform_fee_customer;

        // Gateway-Fee (0, wenn sie in der Plattformgebühr enthalten ist – die Plattform trägt sie)
        $included = !empty($cfg['gateway_fee_included']);
        $gateway_fee = $included ? 0.0 : self::calc_gateway_fee($charge_base, $cfg['gateway_fee_mode'], $cfg);

        // Endbeträge
        $customer_fee_line = $platform_fee_customer;
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

        $organizer_deductions = $platform_fee_organizer;
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
            'platform_fee_customer'  => $platform_fee_customer,
            'platform_fee_organizer' => $platform_fee_organizer,
            'fee_customer_share'     => round($share * 100, 2),
            'gateway_fee_included'   => $included,
        ];
    }
}
