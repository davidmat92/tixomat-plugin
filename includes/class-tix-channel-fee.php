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
 * Quelle: Wer die Gebühr trägt, wählt die Quelle (Einstellung `channel_fee_mode`: customer | split |
 * organizer, Vorgabe customer). Für die Dauer der Partner-Anfrage ersetzt die Kanal-Gebühr die eigene
 * Gebühr der Quelle (TIX_Fees::get_fee_config), erscheint also im Partner-Angebot. Je Bestellung
 * Option `_tix_order_channel_fee_{id}`; Status-Antwort und Webhooks tragen `channel_fee`.
 * Kassiert wird per Monatsrechnung der Plattform (TIX_Channel_Invoice).
 */
class TIX_Channel_Fee {

    const OPTION = 'tix_channel_fee_settings';
    const MODES  = ['customer', 'split', 'organizer'];

    /** Aktuelle Kanal-Gebühr der laufenden Partner-Anfrage (Quelle) oder null. */
    private static $current = null;

    // ── Plattform ──

    public static function settings() {
        $s = get_option(self::OPTION, []);
        $s = is_array($s) ? $s : [];
        return [
            'percent'      => isset($s['percent']) ? floatval($s['percent']) : 2.5,
            'fixed'        => isset($s['fixed']) ? floatval($s['fixed']) : 0.59,
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
            'mode'    => self::mode(),
        ];
    }

    public static function end() {
        self::$current = null;
    }

    public static function current() {
        return self::$current;
    }

    /** Wer trägt die Kanal-Gebühr? (Einstellung der Quelle) */
    public static function mode() {
        $m = function_exists('tix_get_settings') ? (string) tix_get_settings('channel_fee_mode') : '';
        return in_array($m, self::MODES, true) ? $m : 'customer';
    }

    /** Gebühren-Konfiguration für die Dauer der Partner-Anfrage ersetzen. */
    public static function override(array $config) {
        $cf = self::$current;
        if (!$cf) return $config;
        $config['fee_percent']        = $cf['percent'];
        $config['fee_fixed']          = $cf['fixed'];
        $config['fee_mode']           = $cf['mode'];
        $config['fee_label']          = $cf['label'];
        $config['fee_max_per_ticket'] = 0.0;
        $config['fee_max_per_order']  = 0.0;
        $config['fee_rounding']       = 'none';
        $config['fee_custom']         = false;
        $config['gateway_fee_included'] = false;
        $config['channel_fee']        = true;
        return $config;
    }

    /** Je Bestellung festhalten (nach dem Gebühren-Schnappschuss der Kasse). */
    public static function record($order_id) {
        $cf = self::$current;
        if (!$cf) return;
        $f = get_option('_tix_order_fees_' . intval($order_id));
        $fee = is_array($f) ? round(floatval($f['platform_fee'] ?? 0), 2) : 0.0;
        $cust = is_array($f) && isset($f['platform_fee_customer']) ? round(floatval($f['platform_fee_customer']), 2)
            : ($cf['mode'] === 'customer' ? $fee : 0.0);
        update_option('_tix_order_channel_fee_' . intval($order_id), [
            'partner'       => class_exists('TIX_App_Checkout') && !empty(TIX_App_Checkout::$partner['id']) ? (string) TIX_App_Checkout::$partner['id'] : '',
            'percent'       => $cf['percent'],
            'fixed'         => $cf['fixed'],
            'mode'          => $cf['mode'],
            'fee'           => $fee,
            'fee_customer'  => $cust,
            'fee_organizer' => round($fee - $cust, 2),
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
