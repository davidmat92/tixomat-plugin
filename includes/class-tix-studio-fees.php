<?php
if (!defined('ABSPATH')) exit;

/**
 * Servicegebühr im Studio/App für eigene Seiten (1.38.748, Doku §154, Vertrag uebergaben/vertrag-servicegebuehr.md).
 *
 * Dieselben Felder wie im WP-Admin (Option tix_settings) – kein zweiter Speicher. Website-Kasse und App-Kasse rechnen
 * unverändert über TIX_Fees (calc_order_fees → calc_with_config).
 *   GET  /studio/fees[?price=&qty=]   Stand + Vorschau
 *   POST /studio/fees                 Teil-Update der Felder, Antwort wie GET
 *   POST /studio/fees/preview         Vorschau mit ungespeicherten Werten (speichert nichts)
 * Rechte wie Marke/Zahlungsanbieter (TIX_Studio::perm_brand). Auf der Plattform (Mehr-Veranstalter-Modus) gesperrt.
 */
class TIX_Studio_Fees {

    const NS = 'tixomat/v1';
    const MODES = ['customer', 'split', 'organizer'];
    const ROUNDINGS = ['none', '0.90', '0.99', '0.50', '0.00', 'custom'];
    /** Feld im Studio → Schlüssel in tix_settings */
    const MAP = [
        'mode' => 'fee_mode', 'fixed' => 'fee_fixed', 'percent' => 'fee_percent', 'label' => 'fee_label',
        'split_customer_share' => 'fee_split_customer_share', 'max_per_ticket' => 'fee_max_per_ticket', 'max_per_order' => 'fee_max_per_order',
        'rounding' => 'fee_rounding', 'rounding_custom' => 'fee_rounding_custom', 'show_in_selector' => 'fee_show_in_selector',
    ];
    /** Grenzen der Beträge [min, max] */
    const LIMITS = ['fixed' => [0, 50], 'percent' => [0, 50], 'max_per_ticket' => [0, 500], 'max_per_order' => [0, 5000], 'split_customer_share' => [0, 100], 'rounding_custom' => [0, 0.99]];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        $perm = [__CLASS__, 'perm'];
        register_rest_route(self::NS, '/studio/fees', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $perm],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $perm],
        ]);
        register_rest_route(self::NS, '/studio/fees/preview', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_preview'], 'permission_callback' => $perm]);
    }

    public static function perm(WP_REST_Request $req) {
        if (class_exists('TIX_Studio') && method_exists('TIX_Studio', 'perm_brand')) return TIX_Studio::perm_brand($req);
        return current_user_can('manage_options') ? true : new WP_Error('rest_forbidden', 'Keine Berechtigung.', ['status' => 403]);
    }

    public static function locked() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    /** Für /me: {available (eigene Seite, kein Mehr-Veranstalter-Modus), can_edit (Rechte wie perm_brand)} */
    public static function me_payload($user = null) {
        $u = $user instanceof WP_User ? $user : wp_get_current_user();
        $available = !self::locked();
        $can = $available && $u && $u->ID && ($u->has_cap('manage_options') || in_array('tix_organizer', (array) $u->roles, true));
        return ['available' => $available, 'can_edit' => (bool) $can];
    }

    private static function locked_error() {
        return new WP_Error('tix_fees_locked', 'Auf evendis gilt die Plattformgebühr. Wer sie trägt, stellst du unter „Gebühren“ deines Veranstalters ein.', ['status' => 403]);
    }

    private static function invalid($field, $msg) {
        return new WP_Error('tix_invalid_field', $msg, ['status' => 400, 'field' => $field]);
    }

    /** Frische Einstellungen (tix_get_settings hält einen statischen Zwischenspeicher je Aufruf). */
    private static function settings() {
        $s = get_option('tix_settings', []);
        $d = class_exists('TIX_Settings') && method_exists('TIX_Settings', 'defaults') ? TIX_Settings::defaults() : [];
        return wp_parse_args(is_array($s) ? $s : [], $d);
    }

    /** Studio-Felder aus tix_settings. */
    private static function fields(array $s) {
        $mode = in_array($s['fee_mode'] ?? '', self::MODES, true) ? $s['fee_mode'] : 'organizer';
        $rnd = in_array((string) ($s['fee_rounding'] ?? 'none'), self::ROUNDINGS, true) ? (string) $s['fee_rounding'] : 'none';
        $f = [
            'mode' => $mode,
            'fixed' => round(floatval($s['fee_fixed'] ?? 0), 2),
            'percent' => round(floatval($s['fee_percent'] ?? 0), 2),
            'label' => (string) (($s['fee_label'] ?? '') ?: 'Servicegebühr'),
            'split_customer_share' => round(floatval($s['fee_split_customer_share'] ?? 50), 2),
            'max_per_ticket' => round(floatval($s['fee_max_per_ticket'] ?? 0), 2),
            'max_per_order' => round(floatval($s['fee_max_per_order'] ?? 0), 2),
            'rounding' => $rnd,
            'rounding_custom' => round(floatval($s['fee_rounding_custom'] ?? 0), 2),
            'show_in_selector' => !empty($s['fee_show_in_selector']),
        ];
        return ['active' => $mode !== 'organizer' && ($f['fixed'] > 0 || $f['percent'] > 0)] + $f;
    }

    private static function num($v) {
        if (is_bool($v) || is_array($v) || $v === null) return null;
        $v = str_replace(',', '.', trim((string) $v));
        return is_numeric($v) ? floatval($v) : null;
    }

    /** Body prüfen → [Studio-Feld => Wert] | WP_Error */
    private static function clean(array $b) {
        $out = [];
        foreach (array_keys(self::MAP) as $k) {
            if (!array_key_exists($k, $b)) continue;
            $v = $b[$k];
            if ($k === 'mode') {
                if (!in_array($v, self::MODES, true)) return self::invalid('mode', 'Unbekannter Modus.');
                $out[$k] = $v;
            } elseif ($k === 'label') {
                $l = trim(sanitize_text_field((string) $v));
                if ($l === '' || mb_strlen($l) > 40) return self::invalid('label', 'Die Bezeichnung braucht 1 bis 40 Zeichen.');
                $out[$k] = $l;
            } elseif ($k === 'rounding') {
                if (!in_array((string) $v, self::ROUNDINGS, true)) return self::invalid('rounding', 'Unbekannte Rundung.');
                $out[$k] = (string) $v;
            } elseif ($k === 'show_in_selector') {
                $out[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($out[$k] === null) return self::invalid('show_in_selector', 'Bitte an oder aus.');
            } else {
                $n = self::num($v);
                [$min, $max] = self::LIMITS[$k];
                if ($n === null || $n < $min || $n > $max) return self::invalid($k, 'Bitte einen Wert von ' . self::fmt_plain($min) . ' bis ' . self::fmt_plain($max) . ' eingeben.');
                $out[$k] = round($n, 2);
            }
        }
        return $out;
    }

    private static function fmt_plain($n) {
        return rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    }

    private static function eur($n) {
        return number_format((float) $n, 2, ',', '.') . ' €';
    }

    /** Studio-Felder auf eine Kassen-Konfiguration legen (wie TIX_Fees::get_fee_config, aber aus frisch gelesenen Einstellungen). */
    private static function config(array $fields) {
        $s = self::settings();
        $cfg = [
            'gateway_fee_fixed' => floatval($s['gateway_fee_fixed'] ?? 0), 'gateway_fee_percent' => floatval($s['gateway_fee_percent'] ?? 0),
            'gateway_fee_mode' => ($s['gateway_fee_mode'] ?? '') === 'customer' ? 'customer' : 'organizer', 'gateway_fee_included' => !empty($s['gateway_fee_included']),
        ];
        $cfg['fee_mode'] = $fields['mode'];
        $cfg['fee_fixed'] = $fields['fixed'];
        $cfg['fee_percent'] = $fields['percent'];
        $cfg['fee_label'] = $fields['label'];
        $cfg['fee_split_share'] = $fields['split_customer_share'];
        $cfg['fee_max_per_ticket'] = $fields['max_per_ticket'];
        $cfg['fee_max_per_order'] = $fields['max_per_order'];
        $cfg['fee_rounding'] = $fields['rounding'];
        $cfg['fee_rounding_custom'] = $fields['rounding_custom'];
        return $cfg;
    }

    /** Beispielrechnung wie die Kassen (TIX_Fees::calc_with_config). */
    public static function preview(array $fields, $price = 20, $qty = 1) {
        $price = max(0.0, min(10000.0, round(floatval($price), 2)));
        $qty = max(1, min(50, intval($qty)));
        $r = TIX_Fees::calc_with_config([['price' => $price, 'qty' => $qty, 'event_id' => 0]], self::config($fields));
        $fee = round(floatval($r['customer_fee_line']), 2);
        $pays = round(floatval($r['customer_total']), 2);
        $head = $qty > 1 ? $qty . ' Tickets à ' . self::eur($price) : 'Ticket ' . self::eur($price);
        $tail = $fee > 0 ? '(inkl. ' . self::eur($fee) . ' ' . $fields['label'] . ')' : '(keine ' . $fields['label'] . ')';
        return [
            'price' => $price, 'qty' => $qty, 'subtotal' => round(floatval($r['subtotal']), 2),
            'service_fee' => $fee, 'buyer_pays' => $pays, 'rounding_surplus' => round(floatval($r['rounding_surplus'] ?? 0), 2),
            'text' => $head . ' → Käufer zahlt ' . self::eur($pays) . ' ' . $tail,
        ];
    }

    private static function overrides() {
        global $wpdb;
        return intval($wpdb->get_var("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_tix_fee_override' AND m.meta_value NOT IN ('', '0')
            WHERE p.post_type = 'tix_organizer' AND p.post_status NOT IN ('trash', 'auto-draft')"));
    }

    private static function payload($price = 20, $qty = 1) {
        $s = self::settings();
        $f = self::fields($s);
        return [
            'ok' => true,
            'available' => true, // auf Mehr-Veranstalter-Seiten antwortet die Route 403 tix_fees_locked; Apps lesen /me.service_fee
            'fees' => $f,
            'payment' => [
                'fixed' => round(floatval($s['gateway_fee_fixed'] ?? 0), 2), 'percent' => round(floatval($s['gateway_fee_percent'] ?? 0), 2),
                'mode' => ($s['gateway_fee_mode'] ?? '') === 'customer' ? 'customer' : 'organizer', 'included' => !empty($s['gateway_fee_included']),
            ],
            'overrides' => self::overrides(),
            'preview' => self::preview($f, $price, $qty),
            'modes' => [
                ['key' => 'customer', 'label' => 'Käufer zahlen', 'text' => 'Die Servicegebühr kommt auf den Ticketpreis drauf.'],
                ['key' => 'split', 'label' => 'Geteilt', 'text' => 'Käufer zahlen den eingestellten Anteil, den Rest trägst du.'],
                ['key' => 'organizer', 'label' => 'Keine Servicegebühr für Käufer', 'text' => 'Käufer zahlen nur den Ticketpreis.'],
            ],
            'roundings' => self::ROUNDINGS,
        ];
    }

    private static function pq(WP_REST_Request $req, array $b = []) {
        $p = $b['price'] ?? $req->get_param('price');
        $q = $b['qty'] ?? $req->get_param('qty');
        $pn = self::num($p);
        return [$pn === null ? 20 : $pn, ($q === null || $q === '') ? 1 : intval($q)];
    }

    private static function body(WP_REST_Request $req) {
        $b = $req->get_json_params();
        if (!is_array($b) || !$b) $b = $req->get_body_params();
        return is_array($b) ? $b : [];
    }

    public static function rest_get(WP_REST_Request $req) {
        if (self::locked()) return self::locked_error();
        [$p, $q] = self::pq($req);
        return rest_ensure_response(self::payload($p, $q));
    }

    public static function rest_save(WP_REST_Request $req) {
        if (self::locked()) return self::locked_error();
        $b = self::body($req);
        $c = self::clean($b);
        if (is_wp_error($c)) return $c;
        if ($c) {
            $s = get_option('tix_settings', []);
            $s = is_array($s) ? $s : [];
            $before = self::fields(self::settings());
            foreach ($c as $k => $v) $s[self::MAP[$k]] = $k === 'show_in_selector' ? ($v ? 1 : 0) : $v;
            update_option('tix_settings', $s);
            $after = self::fields(self::settings());
            $diff = [];
            foreach ($c as $k => $v) if ($before[$k] !== $after[$k]) $diff[$k] = [$before[$k], $after[$k]];
            if ($diff && class_exists('TIX_Customers') && method_exists('TIX_Customers', 'audit')) TIX_Customers::audit('fees_update', '', ['scope' => 'site', 'oid' => 0], $diff);
        }
        [$p, $q] = self::pq($req, $b);
        return rest_ensure_response(self::payload($p, $q));
    }

    public static function rest_preview(WP_REST_Request $req) {
        if (self::locked()) return self::locked_error();
        $b = self::body($req);
        $c = self::clean($b);
        if (is_wp_error($c)) return $c;
        $f = array_merge(self::fields(self::settings()), $c);
        [$p, $q] = self::pq($req, $b);
        return rest_ensure_response(['ok' => true, 'preview' => self::preview($f, $p, $q)]);
    }
}

TIX_Studio_Fees::init();
