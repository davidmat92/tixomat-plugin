<?php
if (!defined('ABSPATH')) exit;

/**
 * REST für den Veranstalter-Bereich der App (evendis): Gebühren-Modus, Saldo, Abrechnungen.
 * Nur im Mehr-Veranstalter-Modus, nur Inhaber/Team-Admin, nur der eigene Veranstalter
 * (Berechtigung: TIX_Payout_Details::check_perm → 403 tix_not_multi bzw. TIX_App_Scope::check_manager).
 *
 *   GET/POST /organizer/fee-mode
 *   GET      /organizer/balance
 *   GET      /organizer/settlements?page=&per_page=
 *   GET      /organizer/settlements/{id}
 *   GET      /organizer/settlements/{id}/pdf[?doc=invoice]   → JSON {filename, mime, data(base64)}
 */
class TIX_Settlement_REST {

    const NS = 'tixomat/v1';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        $perm = ['TIX_Payout_Details', 'check_perm'];
        register_rest_route(self::NS, '/organizer/fee-mode', [
            ['methods' => 'GET',  'callback' => [__CLASS__, 'fee_mode'],     'permission_callback' => $perm],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'set_fee_mode'], 'permission_callback' => $perm],
        ]);
        register_rest_route(self::NS, '/organizer/balance', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'balance'], 'permission_callback' => $perm,
        ]);
        register_rest_route(self::NS, '/organizer/settlements', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'settlements'], 'permission_callback' => $perm,
        ]);
        register_rest_route(self::NS, '/organizer/settlements/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'settlement'], 'permission_callback' => $perm,
        ]);
        register_rest_route(self::NS, '/organizer/settlements/(?P<id>\d+)/pdf', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'pdf'], 'permission_callback' => $perm,
        ]);
    }

    private static function oid() {
        return TIX_App_Scope::organizer_id_for_user();
    }

    // ──────────────────────────────────────────
    //  Gebühren-Modus
    // ──────────────────────────────────────────

    /** Aktueller Modus des Veranstalters (eigene Wahl oder zentrale Vorgabe). */
    public static function current_mode($oid) {
        return TIX_Fees::get_fee_config($oid)['fee_mode'];
    }

    /** Beispielrechnung für einen Modus (Server rechnet, inkl. Rundung/Höchstbeträge). */
    public static function example($oid, $mode, $price) {
        $cfg = TIX_Fees::get_fee_config($oid);
        $cfg['fee_mode'] = $mode;
        $r = TIX_Fees::calc_with_config([['price' => $price, 'qty' => 1, 'event_id' => 0]], $cfg);
        $payment_fee = $cfg['gateway_fee_mode'] === 'organizer' ? floatval($r['gateway_fee']) : 0.0;
        return [
            'price'                          => round($price, 2),
            'fee'                            => round(floatval($r['platform_fee']), 2),
            'fee_customer'                   => round(floatval($r['platform_fee_customer']), 2),
            'fee_organizer'                  => round(floatval($r['platform_fee_organizer']), 2),
            'customer_pays'                  => round(floatval($r['customer_total']), 2),
            'payment_fee'                    => round($payment_fee, 2),
            'you_receive_before_payment_fee' => round(floatval($r['organizer_payout']) + $payment_fee, 2),
            'you_receive'                    => round(floatval($r['organizer_payout']), 2),
        ];
    }

    public static function fee_mode_payload($oid, $price = 20.0) {
        $cfg  = TIX_Fees::get_fee_config($oid);
        $glob = TIX_Fees::get_fee_config();
        $mode = $cfg['fee_mode'];
        $examples = [];
        foreach (TIX_Fees::MODES as $m) $examples[$m] = self::example($oid, $m, $price);
        $note = '';
        if ($cfg['gateway_fee_mode'] === 'organizer' && ($cfg['gateway_fee_fixed'] > 0 || $cfg['gateway_fee_percent'] > 0)) {
            $parts = [];
            if ($cfg['gateway_fee_fixed'] > 0) $parts[] = number_format($cfg['gateway_fee_fixed'], 2, ',', '.') . ' €';
            if ($cfg['gateway_fee_percent'] > 0) $parts[] = number_format($cfg['gateway_fee_percent'], 2, ',', '.') . ' %';
            $note = 'Zahlungsgebühren (Kartenzahlung, PayPal usw.) werden mit der Auszahlung verrechnet: die tatsächliche Gebühr des Zahlungsanbieters, sonst ca. ' . implode(' + ', $parts) . ' je Bestellung.';
        } elseif ($cfg['gateway_fee_mode'] === 'organizer') {
            $note = 'Zahlungsgebühren (Kartenzahlung, PayPal usw.) werden mit der Auszahlung verrechnet (tatsächliche Gebühr des Zahlungsanbieters).';
        }
        return [
            'mode'         => $mode,
            'default_mode' => $glob['fee_mode'],
            'own_choice'   => (bool) get_post_meta($oid, '_tix_fee_override', true),
            'choosable'    => true,
            'modes'        => TIX_Fees::MODES,
            'applies_to'   => 'new_orders',
            'fee'          => [
                'fixed'                => round(floatval($cfg['fee_fixed']), 2),
                'percent'              => round(floatval($cfg['fee_percent']), 2),
                'max_per_ticket'       => $cfg['fee_max_per_ticket'] > 0 ? round(floatval($cfg['fee_max_per_ticket']), 2) : null,
                'max_per_order'        => $cfg['fee_max_per_order'] > 0 ? round(floatval($cfg['fee_max_per_order']), 2) : null,
                'label'                => (string) $cfg['fee_label'],
                'split_customer_share' => round(floatval($cfg['fee_split_share']), 2),
                'rounding'             => (string) $cfg['fee_rounding'],
            ],
            'example'      => $examples[$mode] ?? $examples['organizer'],
            'examples'     => $examples,
            'gateway_note' => $note,
        ];
    }

    private static function price_param(WP_REST_Request $req) {
        $p = floatval($req->get_param('price'));
        return ($p > 0 && $p < 100000) ? $p : 20.0;
    }

    public static function fee_mode(WP_REST_Request $req) {
        return rest_ensure_response(self::fee_mode_payload(self::oid(), self::price_param($req)));
    }

    public static function set_fee_mode(WP_REST_Request $req) {
        $mode = (string) $req->get_param('mode');
        if (!in_array($mode, TIX_Fees::MODES, true)) {
            return new WP_Error('tix_invalid_field', 'Bitte einen gültigen Modus wählen.', ['status' => 400, 'field' => 'mode']);
        }
        self::save_fee_mode(self::oid(), $mode);
        return rest_ensure_response(self::fee_mode_payload(self::oid(), self::price_param($req)));
    }

    /** Wahl des Veranstalters speichern (nur der Modus; Beträge bleiben zentral). */
    public static function save_fee_mode($oid, $mode) {
        update_post_meta(intval($oid), '_tix_fee_override', 1);
        update_post_meta(intval($oid), '_tix_fee_mode', $mode);
        update_post_meta(intval($oid), '_tix_fee_mode_changed', ['mode' => $mode, 'by' => get_current_user_id(), 'at' => current_time('mysql')]);
    }

    // ──────────────────────────────────────────
    //  Saldo, Abrechnungen
    // ──────────────────────────────────────────

    public static function balance(WP_REST_Request $req) {
        return rest_ensure_response(TIX_Settlement::balance(self::oid()));
    }

    public static function settlements(WP_REST_Request $req) {
        if (!TIX_Settlement::enabled()) return rest_ensure_response(['items' => [], 'total' => 0, 'has_more' => false]);
        $r = TIX_Settlement::for_organizer(self::oid(), $req->get_param('page') ?: 1, $req->get_param('per_page') ?: 20);
        return rest_ensure_response([
            'items'    => array_map(['TIX_Settlement', 'list_payload'], $r['rows']),
            'total'    => $r['total'],
            'has_more' => $r['has_more'],
        ]);
    }

    private static function own($id) {
        if (!TIX_Settlement::enabled()) return null;
        $s = TIX_Settlement::get(intval($id));
        if (!$s || $s->status === 'void' || intval($s->organizer_id) !== self::oid()) return null;
        return $s;
    }

    public static function settlement(WP_REST_Request $req) {
        $s = self::own($req['id']);
        if (!$s) return new WP_Error('tix_not_found', 'Abrechnung nicht gefunden.', ['status' => 404]);
        return rest_ensure_response(TIX_Settlement::detail_payload($s));
    }

    public static function pdf(WP_REST_Request $req) {
        $s = self::own($req['id']);
        if (!$s) return new WP_Error('tix_not_found', 'Abrechnung nicht gefunden.', ['status' => 404]);
        $doc = $req->get_param('doc') === 'invoice' ? 'invoice' : 'settlement';
        $pdf = class_exists('TIX_Settlement_PDF') ? TIX_Settlement_PDF::render($s, $doc) : null;
        if (!$pdf) return new WP_Error('tix_no_pdf', 'Für diese Abrechnung gibt es (noch) kein PDF.', ['status' => 404]);
        return rest_ensure_response([
            'filename' => $pdf['filename'],
            'mime'     => 'application/pdf',
            'data'     => base64_encode($pdf['bytes']),
        ]);
    }
}
