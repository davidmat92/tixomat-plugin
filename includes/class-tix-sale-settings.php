<?php
/**
 * Kasse & Einlass einstellen (P3 Expertenmodus, 1.38.799, Vertrag `uebergaben/vertrag-kasse-einlass-einstellungen.md`; Namen mit dem Apps-Chat abgestimmt).
 *
 * Eine Route zum Pflegen (Studio, App-Veranstalterbereich), zwei zum Lesen (App-Kasse /pos/config, Scanner /checkin/config).
 * Einzel-Seite: bisherige Tixomat-Einstellungen (alte Web-Kasse liest dieselben Werte) + Option tix_sale_settings für neue Felder.
 * evendis: je Veranstalter in TIX_Object_Data (Art org_sale_settings), Rückfall auf die Plattform-Werte.
 * Durchgesetzt wird im Plugin (Pflichtfelder, Kostenlos an der Kasse, doppelter Scan).
 */
if (!defined('ABSPATH')) exit;

class TIX_Sale_Settings {

    const NS = 'tixomat/v1';
    const OPT = 'tix_sale_settings';
    const OBJ = 'tix_org_sale_settings';
    /** Bereich → Feld → [Typ, Vorgabe, alter Schlüssel in tix_settings|null, Werte/Grenzen] */
    const FIELDS = [
        'checkout' => [
            'company_field'     => ['bool', false, 'show_company_field'],
            'coupon_field'      => ['bool', true, 'show_coupon_selector'],
            'newsletter_optin'  => ['bool', false, 'newsletter_enabled'],
            'address_required'  => ['bool', true, null],
            'phone_field'       => ['enum', 'off', null, ['off', 'optional', 'required']],
            'account_offer'     => ['bool', true, null],
            'countdown_minutes' => ['int', 0, null, [0, 60]],
        ],
        'pos' => [
            'require_name'       => ['bool', false, 'pos_require_name'],
            'require_email'      => ['bool', false, 'pos_require_email'],
            'allow_free'         => ['bool', true, 'pos_allow_free'],
            'default_payment'    => ['enum', 'cash', 'pos_default_payment', ['cash', 'card']],
            'auto_reset_seconds' => ['int', 10, 'pos_auto_reset_seconds', [0, 60]],
        ],
        'checkin' => [
            // Vorgaben = heutiges App-Verhalten (Apps-Chat 13.11.): warn = Status „already“ wie bisher
            'duplicate'      => ['enum', 'warn', null, ['warn', 'block', 'reentry']],
            'sound'          => ['bool', true, null],
            'vibration'      => ['bool', true, null],
            'result_seconds' => ['int', 2, null, [1, 10]],
        ],
    ];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    // ═══════════════ Reine Prüfung ═══════════════

    /** Ein Wert prüfen (rein) → [ok, wert] */
    public static function clean_value(array $def, $v) {
        switch ($def[0]) {
            case 'bool': return is_bool($v) || in_array($v, [0, 1, '0', '1'], true) ? [true, (bool) $v] : [false, null];
            case 'int':
                if (!is_numeric($v)) return [false, null];
                $i = intval($v);
                return ($i >= $def[3][0] && $i <= $def[3][1]) ? [true, $i] : [false, null];
            case 'enum': return in_array((string) $v, $def[3], true) ? [true, (string) $v] : [false, null];
        }
        return [false, null];
    }

    /** Eingabe prüfen (rein) → [bereich => [feld => wert]] | ['error' => feld] */
    public static function validate($in) {
        if (!is_array($in)) return ['error' => 'body'];
        $out = [];
        foreach (self::FIELDS as $area => $fields) {
            if (!array_key_exists($area, $in)) continue;
            if (!is_array($in[$area])) return ['error' => $area];
            foreach ($in[$area] as $k => $v) {
                if (!isset($fields[$k])) return ['error' => $area . '.' . $k];
                [$ok, $val] = self::clean_value($fields[$k], $v);
                if (!$ok) return ['error' => $area . '.' . $k];
                $out[$area][$k] = $val;
            }
        }
        return $out;
    }

    // ═══════════════ Lesen / Schreiben ═══════════════

    /** Werte der Seite (Einzel-Seite bzw. Plattform-Vorgabe) */
    private static function site_values() {
        // frisch aus der Option (tix_get_settings hält einen Zwischenspeicher je Anfrage), Vorgaben aus tix_get_settings
        $s = wp_parse_args((array) get_option('tix_settings', []), function_exists('tix_get_settings') ? (array) tix_get_settings() : []);
        $own = get_option(self::OPT, []);
        $own = is_array($own) ? $own : [];
        $out = [];
        foreach (self::FIELDS as $area => $fields) foreach ($fields as $k => $def) {
            $v = $def[1];
            if ($def[2] !== null && array_key_exists($def[2], $s)) { [$ok, $cv] = self::clean_value($def, $def[0] === 'bool' ? !empty($s[$def[2]]) : $s[$def[2]]); if ($ok) $v = $cv; }
            if (isset($own[$area][$k])) { [$ok, $cv] = self::clean_value($def, $own[$area][$k]); if ($ok) $v = $cv; }
            $out[$area][$k] = $v;
        }
        // Countdown der Website-Kasse: aus = 0
        if (isset($s['checkout_countdown']) && empty($s['checkout_countdown']) && !isset($own['checkout']['countdown_minutes'])) $out['checkout']['countdown_minutes'] = 0;
        elseif (!empty($s['checkout_countdown']) && !isset($own['checkout']['countdown_minutes'])) $out['checkout']['countdown_minutes'] = max(1, min(60, intval($s['checkout_countdown_minutes'] ?? 10)));
        return $out;
    }

    /** Wirksame Werte für einen Veranstalter (evendis) bzw. die Seite */
    public static function get($org = 0) {
        $v = self::site_values();
        if (self::multi() && intval($org) > 0 && class_exists('TIX_Object_Data')) {
            $o = TIX_Object_Data::read(self::OBJ, intval($org), []);
            if (is_array($o)) foreach (self::FIELDS as $area => $fields) foreach ($fields as $k => $def) {
                if (isset($o[$area][$k])) { [$ok, $cv] = self::clean_value($def, $o[$area][$k]); if ($ok) $v[$area][$k] = $cv; }
            }
        }
        return $v;
    }

    /** Werte für ein Event (Veranstalter des Events auf evendis) */
    public static function for_event($event_id) {
        return self::get(self::multi() ? intval(get_post_meta(intval($event_id), '_tix_organizer_id', true)) : 0);
    }

    /** Muss die Adresse angegeben werden? (E-Rechnung an → immer) */
    public static function address_forced() {
        return get_option('tix_invoices_enabled', '0') === '1';
    }

    private static function save($org, array $clean) {
        if (self::multi() && $org > 0) {
            $cur = TIX_Object_Data::read(self::OBJ, $org, []);
            $cur = is_array($cur) ? $cur : [];
            foreach ($clean as $area => $vals) $cur[$area] = array_merge((array) ($cur[$area] ?? []), $vals);
            TIX_Object_Data::write(self::OBJ, $org, $cur);
            return;
        }
        // Einzel-Seite bzw. Plattform-Vorgabe: alte Schlüssel in tix_settings (für alte Leser), neue in der eigenen Option
        $s = (array) get_option('tix_settings', []);
        $own = get_option(self::OPT, []);
        $own = is_array($own) ? $own : [];
        foreach ($clean as $area => $vals) foreach ($vals as $k => $v) {
            $legacy = self::FIELDS[$area][$k][2];
            if ($legacy !== null) $s[$legacy] = is_bool($v) ? ($v ? 1 : 0) : $v;
            $own[$area][$k] = $v;
            if ($area === 'checkout' && $k === 'countdown_minutes') { $s['checkout_countdown'] = $v > 0 ? 1 : 0; if ($v > 0) $s['checkout_countdown_minutes'] = $v; }
        }
        update_option('tix_settings', $s);
        update_option(self::OPT, $own, false);
    }

    // ═══════════════ Routen ═══════════════

    public static function routes() {
        register_rest_route(self::NS, '/studio/checkout-settings', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => ['TIX_REST_API', 'check_organizer']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => [__CLASS__, 'perm_write']],
        ]);
        register_rest_route(self::NS, '/pos/config', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_pos'], 'permission_callback' => ['TIX_REST_API', 'check_organizer']]);
        register_rest_route(self::NS, '/checkin/config', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_checkin'], 'permission_callback' => ['TIX_REST_API', 'check_organizer']]);
    }

    public static function perm_write(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        $u = wp_get_current_user();
        if ($u->has_cap('manage_options')) return true;
        if (!self::multi() && in_array('tix_organizer', (array) $u->roles, true)) return true;
        if (self::multi() && class_exists('TIX_App_Scope') && TIX_App_Scope::is_org_manager() && TIX_App_Scope::organizer_id_for_user()) return true;
        return new WP_Error('rest_forbidden', 'Kasse und Einlass einstellen dürfen nur Inhaber bzw. Admins.', ['status' => 403]);
    }

    /** evendis: eigener Veranstalter bzw. (Admin) ?organizer_id=; 0 = Plattform-Vorgabe bzw. Einzel-Seite */
    private static function org_of(WP_REST_Request $req) {
        if (!self::multi()) return 0;
        if (current_user_can('manage_options')) return max(0, intval($req->get_param('organizer_id')));
        return class_exists('TIX_App_Scope') ? intval(TIX_App_Scope::organizer_id_for_user()) : 0;
    }

    private static function payload($org) {
        $defaults = [];
        foreach (self::FIELDS as $area => $fields) foreach ($fields as $k => $def) $defaults[$area][$k] = $def[1];
        return ['ok' => true, 'scope' => (self::multi() && $org > 0) ? 'organizer' : 'site', 'organizer_id' => $org ?: null] + self::get($org)
            + ['defaults' => $defaults, 'address_forced' => self::address_forced(), 'options' => ['checkout.phone_field' => self::FIELDS['checkout']['phone_field'][3],
                'pos.default_payment' => self::FIELDS['pos']['default_payment'][3], 'checkin.duplicate' => self::FIELDS['checkin']['duplicate'][3]]];
    }

    public static function rest_get(WP_REST_Request $req) {
        $r = rest_ensure_response(self::payload(self::org_of($req)));
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    public static function rest_save(WP_REST_Request $req) {
        $org = self::org_of($req);
        if (self::multi() && !$org && !current_user_can('manage_options')) return new WP_Error('tix_organizer_required', 'Kein Veranstalter.', ['status' => 400]);
        $clean = self::validate((array) $req->get_json_params());
        if (isset($clean['error'])) return new WP_Error('tix_invalid_field', 'Ungültiger Wert: ' . $clean['error'] . '.', ['status' => 400, 'field' => $clean['error']]);
        self::save($org, $clean);
        return self::rest_get($req);
    }

    private static function event_of(WP_REST_Request $req) {
        $eid = absint($req->get_param('event_id'));
        if ($eid && (get_post_type($eid) !== 'event' || !TIX_REST_API::user_can_access_event($eid))) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        return $eid;
    }

    /** GET /pos/config?event_id= – App-Kasse */
    public static function rest_pos(WP_REST_Request $req) {
        $eid = self::event_of($req);
        if (is_wp_error($eid)) return $eid;
        $v = $eid ? self::for_event($eid) : self::get(self::org_of($req));
        // enabled/pin_required: die App-Kasse kennt keinen Aus-Schalter und keine PIN (Apps-Chat 13.11.) – feste Werte, bis gewünscht
        return rest_ensure_response(['ok' => true, 'event_id' => $eid ?: null, 'pos' => ['enabled' => true, 'pin_required' => false] + $v['pos'], 'checkout' => ['coupon_field' => $v['checkout']['coupon_field']],
            'seatmap' => $eid && class_exists('TIX_Seatmap') && TIX_Seatmap::event_seatmap($eid) > 0 && TIX_Seatmap::module_on($eid)]);
    }

    /** GET /checkin/config?event_id= – Scanner */
    public static function rest_checkin(WP_REST_Request $req) {
        $eid = self::event_of($req);
        if (is_wp_error($eid)) return $eid;
        $v = $eid ? self::for_event($eid) : self::get(self::org_of($req));
        return rest_ensure_response(['ok' => true, 'event_id' => $eid ?: null, 'checkin' => $v['checkin']]);
    }
}
