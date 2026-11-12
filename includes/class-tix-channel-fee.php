<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Channel_Fee – Gebühr im evendis-Kanal für geteilte Events (Strategie evendis, Paket 2/3).
 *
 * Plattform (evendis): Schalter + Satz je Partner im Partner-Verzeichnis (`channel_fee`, optional
 * `channel_fee_percent`/`channel_fee_fixed`, sonst Option `tix_channel_fee_settings`). Bestandspartner
 * ohne Feld = aus (KitchenKlub, Mallorca: „keine Provision“). Die Vermittlung schickt den Satz bei
 * Angebot und Bestellung als `channel_fee {percent, fixed, label}` an die Partner-API der Quelle.
 *
 * Quelle (ab 1.38.760, User-Entscheid 11.11.): Der Käufer zahlt bei vermittelten Quell-Events NUR die eigene Service-/
 * Vorverkaufsgebühr der Quelle (Regel des Erstellers). Die Kanal-Gebühr trägt die Quelle intern – unabhängig von der früheren
 * Einstellung `channel_fee_mode` (wird ignoriert). Sie wird je Bestellung aus den Positionen berechnet (Prozent vom Ticketpreis
 * + Fixbetrag je Ticket, ohne Freitickets/gebührenfreie Kategorien/Gutscheine) und als Option `_tix_order_channel_fee_{id}`
 * festgehalten (fee_customer 0, fee_organizer = fee); Status-Antwort und Webhooks tragen `channel_fee`.
 * Kassiert wird per Monatsrechnung der Plattform (TIX_Channel_Invoice).
 */
class TIX_Channel_Fee {

    const OPTION = 'tix_channel_fee_settings';
    const MODES  = ['customer', 'split', 'organizer'];
    /** Vorgabe = Plattformgebühr evendis, inkl. MwSt. (User-Entscheid 11.11.2026: 2,9 % + 0,49 €, vorher 2,5 % + 0,59 €) */
    const DEFAULT_PERCENT = 2.9;
    const DEFAULT_FIXED   = 0.49;

    /** Aktuelle Kanal-Gebühr der laufenden Partner-Anfrage (Quelle) oder null. */
    private static $current = null;

    // ── Plattform ──

    public static function settings() {
        $s = get_option(self::OPTION, []);
        $s = is_array($s) ? $s : [];
        return [
            'percent'      => isset($s['percent']) ? floatval($s['percent']) : self::DEFAULT_PERCENT,
            'fixed'        => isset($s['fixed']) ? floatval($s['fixed']) : self::DEFAULT_FIXED,
            'label'        => (string) ($s['label'] ?? 'Servicegebühr'),
            'new_partners' => array_key_exists('new_partners', $s) ? !empty($s['new_partners']) : true,
            'due_days'     => max(0, intval($s['due_days'] ?? 14)),
            'payment'      => in_array($s['payment'] ?? '', ['sepa', 'transfer'], true) ? $s['payment'] : 'sepa',
        ];
    }

    /** Satz für einen Partner (Verzeichnis-Eintrag) oder null = keine Kanal-Gebühr. */
    public static function for_partner($p) {
        if (!is_array($p) || empty($p['channel_fee'])) return null;
        $s = self::settings();
        $pct = ($p['channel_fee_percent'] ?? '') !== '' ? floatval($p['channel_fee_percent']) : $s['percent'];
        $fix = ($p['channel_fee_fixed'] ?? '') !== '' ? floatval($p['channel_fee_fixed']) : $s['fixed'];
        if ($pct <= 0 && $fix <= 0) return null;
        return ['percent' => round($pct, 4), 'fixed' => round($fix, 2), 'label' => $s['label']];
    }

    // ── Quelle ──

    /** Kanal-Gebühr aus der Partner-Anfrage übernehmen (Grenzen gegen Fehleingaben). */
    public static function begin(WP_REST_Request $req) {
        self::$current = null;
        $cf = $req->get_param('channel_fee');
        if (!is_array($cf)) return;
        $pct = floatval($cf['percent'] ?? 0);
        $fix = floatval($cf['fixed'] ?? 0);
        if ($pct < 0 || $pct > 20 || $fix < 0 || $fix > 5 || ($pct <= 0 && $fix <= 0)) return;
        self::$current = [
            'percent' => round($pct, 4),
            'fixed'   => round($fix, 2),
            'label'   => mb_substr(sanitize_text_field((string) ($cf['label'] ?? 'Servicegebühr')), 0, 60) ?: 'Servicegebühr',
            'mode'    => 'organizer', // trägt die Quelle (1.38.760)
        ];
    }

    public static function end() {
        self::$current = null;
    }

    public static function current() {
        return self::$current;
    }

    /** Wer trägt die Kanal-Gebühr? Seit 1.38.760 immer die Quelle (Veranstalter), nie der Käufer. */
    public static function mode() {
        return 'organizer';
    }

    /**
     * Gebühren-Konfiguration während der Partner-Anfrage: seit 1.38.760 unverändert – der Käufer zahlt die eigene Gebühr der Quelle
     * (Regel des Erstellers); die Kanal-Gebühr berechnet record() separat zu Lasten der Quelle.
     */
    public static function override(array $config) {
        return $config;
    }

    /** Kanal-Gebühr einer Bestellung aus den Positionen (Prozent + Fixbetrag je Ticket), ohne Freitickets/Ausnahmen. */
    public static function amount_for_order($order_id, array $cf) {
        $fd = TIX_Object_Data::read('_tix_order_fees', intval($order_id), false);
        $lines = is_array($fd) ? (array) ($fd['lines'] ?? []) : [];
        if (!$lines) { // ältere Kasse ohne `lines`: Positionen der Bestellung
            global $wpdb;
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT event_id, quantity, total FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d", intval($order_id))) as $r) {
                $q = max(0, intval($r->quantity));
                if ($q) $lines[] = ['event_id' => intval($r->event_id), 'cat_index' => -1, 'price' => round(floatval($r->total) / $q, 2), 'qty' => $q];
            }
        }
        $fee = 0.0;
        foreach ($lines as $l) {
            $p = floatval($l['price'] ?? 0); $q = intval($l['qty'] ?? 0);
            if ($p <= 0 || $q <= 0) continue;
            if (class_exists('TIX_Fees') && TIX_Fees::is_item_fee_exempt(['event_id' => intval($l['event_id'] ?? 0), 'cat_index' => intval($l['cat_index'] ?? -1), 'meta' => (array) ($l['meta'] ?? [])])) continue;
            $fee += ($p * floatval($cf['percent']) / 100 + floatval($cf['fixed'])) * $q;
        }
        return round($fee, 2);
    }

    /** Je Bestellung festhalten: Kanal-Gebühr zu Lasten der Quelle (1.38.760). */
    public static function record($order_id) {
        $cf = self::$current;
        if (!$cf) return;
        $fee = self::amount_for_order($order_id, $cf);
        update_option('_tix_order_channel_fee_' . intval($order_id), [
            'partner'       => class_exists('TIX_App_Checkout') && !empty(TIX_App_Checkout::$partner['id']) ? (string) TIX_App_Checkout::$partner['id'] : '',
            'percent'       => $cf['percent'],
            'fixed'         => $cf['fixed'],
            'mode'          => 'organizer',
            'fee'           => $fee,
            'fee_customer'  => 0.0,
            'fee_organizer' => $fee,
        ], false);
    }

    /** Für Status-Antwort/Webhook der Partner-API. */
    public static function for_order($order_id) {
        $c = get_option('_tix_order_channel_fee_' . intval($order_id));
        if (!is_array($c)) return null;
        return [
            'fee'           => round(floatval($c['fee'] ?? 0), 2),
            'mode'          => (string) ($c['mode'] ?? ''),
            'percent'       => floatval($c['percent'] ?? 0),
            'fixed'         => floatval($c['fixed'] ?? 0),
            'fee_customer'  => round(floatval($c['fee_customer'] ?? 0), 2),
            'fee_organizer' => round(floatval($c['fee_organizer'] ?? 0), 2),
        ];
    }
}
