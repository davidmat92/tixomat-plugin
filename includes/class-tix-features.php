<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Features – App-Funktionen an/aus an einer Stelle (1.38.498, User: „Alle App-Funktionen sollen im Studio und in der App
 * an- und ausschaltbar sein, mit Status-Anzeige“). Liest und schreibt die bestehenden Schalter der Module; neue Schalter nur,
 * wo es keinen gab (App-Gutscheine, Tipps, Mitteilungen, Gewinnspiele global, Muttizettel auf Einzel-Seiten).
 *
 *  - Studio: GET /studio/config → `app_features`, POST /studio/config {app_features: {<key>: bool | {enabled: bool}}};
 *    eigene Route GET/POST /studio/features (gleiches Format, ohne Zwischenspeicher). Mehr-Veranstalter-Modus: Admin mit
 *    ?organizer= bzw. organizer_id bearbeitet die Module eines Veranstalters.
 *  - App/Website: GET /app/config → `features {<key>: bool}` (Seite; evendis mit ?organizer= für Veranstalter-Module).
 *  - „Aus“: Apps und Website blenden aus, öffentliche Routen melden aus; Daten bleiben erhalten.
 *  - Rechte (wie Marke): Admin; Einzel-Seite Inhaber (Rolle Veranstalter); evendis: Plattform-Schalter nur Admin,
 *    Veranstalter-Module (music, loyalty, wallet, giftcards, muttizettel) Inhaber/Team-Admin für den eigenen Veranstalter.
 *    Awareness Stufe 2/3 auf evendis nur Admin (Datenschutz/Rechtsprüfung offen).
 */
class TIX_Features {

    const NS = 'tixomat/v1';

    /** key => [Bezeichnung, Veranstalter-Modul auf evendis (Key in _tix_org_modules) oder null] */
    const FEATURES = [
        'awareness_report' => ['Awareness: Meldungen (Stufe 2)', null],
        'awareness_alarm'  => ['Awareness: Live-Alarm (Stufe 3)', null],
        'app_coupons'      => ['Gutscheine in der App (Rabatt-Codes)', null],
        'music'            => ['Musikwunsch', 'music'],
        'tips'             => ['Tipps des Tages', null],
        'notifications'    => ['Mitteilungen (Broadcast)', null],
        'loyalty'          => ['Prämien (Stempelkarte)', 'loyalty'],
        'wallet'           => ['Guthaben', 'wallet'],
        'giftcards'        => ['Geschenkgutscheine', 'giftcards'],
        'party_game'       => ['Partyspiel', null],
        'sos'              => ['SOS', null],
        'muttizettel'      => ['Muttizettel', 'muttizettel'],
        'raffles'          => ['Gewinnspiele', null],
        'live_now'         => ['Jetzt & gleich (Startseite)', null],
    ];

    /** Neue Schalter (Option, Vorgabe an). */
    const OPT_APP_COUPONS = 'tix_app_coupons_enabled';
    const OPT_TIPS = 'tix_app_tips_enabled';
    const OPT_BROADCAST = 'tix_broadcast_enabled';
    const OPT_RAFFLES = 'tix_raffles_enabled';
    const OPT_MUTTIZETTEL = 'tix_muttizettel_enabled';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        register_rest_route(self::NS, '/studio/features', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => [__CLASS__, 'perm']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => [__CLASS__, 'perm']],
        ]);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    private static function is_admin() {
        return current_user_can('manage_options');
    }

    /** Lesen/Schreiben im Studio: Admin, Einzel-Seite Rolle Veranstalter, evendis Inhaber/Team-Admin mit Veranstalter. */
    public static function perm() {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]);
        if (self::is_admin()) return true;
        if (self::multi()) {
            return (TIX_App_Scope::is_org_manager() && TIX_App_Scope::organizer_id_for_user()) ? true : new WP_Error('rest_forbidden', 'Nur der Veranstalter selbst kann das ändern.', ['status' => 403]);
        }
        return in_array('tix_organizer', (array) wp_get_current_user()->roles, true) ? true : new WP_Error('rest_forbidden', 'Nur Inhaber bzw. Admin können Funktionen schalten.', ['status' => 403]);
    }

    private static function opt_on($opt) {
        return get_option($opt, '1') !== '0';
    }

    // ═══════════════ Zustand der Seite bzw. Plattform ═══════════════

    /** Ist die Funktion auf Seiten- bzw. Plattform-Ebene an? */
    public static function site_on($key) {
        switch ($key) {
            case 'awareness_report':
            case 'awareness_alarm':
                $f = class_exists('TIX_Awareness_Live') ? (array) TIX_Awareness_Live::flags() : [];
                return !empty($f[$key === 'awareness_report' ? 'report_enabled' : 'alarm_live']);
            case 'app_coupons': return self::opt_on(self::OPT_APP_COUPONS);
            case 'tips': return self::opt_on(self::OPT_TIPS) && (!class_exists('TIX_Modules') || TIX_Modules::allowed('tips'));
            case 'notifications': return self::opt_on(self::OPT_BROADCAST);
            case 'raffles': return self::opt_on(self::OPT_RAFFLES);
            case 'music': return class_exists('TIX_Music') && TIX_Music::enabled();
            case 'loyalty':
                if (class_exists('TIX_Modules') && !TIX_Modules::allowed('loyalty')) return false;
                return self::multi() ? true : get_option('_tix_loyalty_enabled', '1') === '1';
            case 'wallet':
                return class_exists('TIX_Wallet_Balance') && TIX_Wallet_Balance::scope_enabled(0);
            case 'giftcards':
                if (class_exists('TIX_Modules') && !TIX_Modules::allowed('giftcards')) return false;
                return class_exists('TIX_Giftcards') && !empty(TIX_Giftcards::get_settings()['enabled']);
            case 'party_game': return class_exists('TIX_Party_Rooms') && TIX_Party_Rooms::enabled();
            case 'sos': return class_exists('TIX_App_Sos') && !empty(TIX_App_Sos::settings()['enabled']);
            case 'muttizettel':
                if (class_exists('TIX_Modules') && !TIX_Modules::allowed('muttizettel')) return false;
                return self::multi() ? true : self::opt_on(self::OPT_MUTTIZETTEL);
            case 'live_now':
                $c = class_exists('TIX_App_Config') ? (array) TIX_App_Config::stored() : [];
                return !isset($c['home']['now']['show']) || !empty($c['home']['now']['show']);
        }
        return false;
    }

    /** Veranstalter-Modul an? (nur Mehr-Veranstalter-Modus, Keys mit Modul) */
    public static function org_on($key, $oid) {
        $mod = self::FEATURES[$key][1] ?? null;
        if (!$mod || !$oid || !class_exists('TIX_Public_Platform')) return false;
        if ($key === 'loyalty' && class_exists('TIX_Loyalty')) {
            $c = get_post_meta($oid, '_tix_org_loyalty', true);
            if (is_array($c) && array_key_exists('enabled', $c)) return !empty($c['enabled']) && (!class_exists('TIX_Modules') || TIX_Modules::allowed('loyalty'));
        }
        return !empty(TIX_Public_Platform::modules($oid)[$mod]);
    }

    /** Wirksamer Zustand: Seite; evendis mit Veranstalter = Modul (Musikwunsch zusätzlich der Plattform-Schalter). */
    public static function on($key, $oid = 0) {
        if (!isset(self::FEATURES[$key])) return false;
        if ($oid && self::multi() && self::FEATURES[$key][1]) {
            if ($key === 'wallet') return class_exists('TIX_Wallet_Balance') && TIX_Wallet_Balance::scope_enabled($oid);
            if ($key === 'giftcards') return class_exists('TIX_Giftcards') && TIX_Giftcards::organizer_enabled($oid);
            $on = self::org_on($key, $oid);
            return $key === 'music' ? ($on && self::site_on('music')) : $on;
        }
        return self::site_on($key);
    }

    /** ?organizer=<id|slug> → Veranstalter-ID (nur Mehr-Veranstalter-Modus, veröffentlicht), sonst 0. */
    public static function org_from_param($v) {
        $v = trim((string) $v);
        if ($v === '' || $v === '0' || !self::multi()) return 0;
        if (ctype_digit($v)) $id = intval($v);
        else {
            $f = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_tix_org_landing_slug', 'meta_value' => sanitize_title($v)]);
            if (!$f) $f = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'name' => sanitize_title($v)]);
            $id = $f ? intval($f[0]) : 0;
        }
        return ($id && get_post_type($id) === 'tix_organizer' && get_post_status($id) === 'publish') ? $id : 0;
    }

    /** Schlüssel für Apps/Studio (Reihenfolge); `awareness` fasst Stufe 2/3 zusammen. */
    const ORDER = ['awareness', 'app_coupons', 'music', 'tips', 'notifications', 'loyalty', 'wallet', 'giftcards', 'party_game', 'sos', 'muttizettel', 'raffles', 'live_now'];

    /**
     * Öffentlich für /app/config und /info: {<key>: {enabled, can_edit}}, `awareness` {enabled, report, alarm, can_edit}
     * (enabled = Stufe 2 oder 3 an); zusätzlich awareness_report / awareness_alarm als eigene Schlüssel.
     */
    public static function map($oid = 0) {
        list($scope, $soid) = self::scope(null);
        $out = [];
        foreach (array_keys(self::FEATURES) as $k) {
            $out[$k] = ['enabled' => self::on($k, $oid), 'can_edit' => self::entitled($k) && self::can_edit($k, $scope, $soid) && (!$oid || $oid === $soid || !self::FEATURES[$k][1])];
        }
        $out = ['awareness' => [
            'enabled' => $out['awareness_report']['enabled'] || $out['awareness_alarm']['enabled'],
            'report' => $out['awareness_report']['enabled'], 'alarm' => $out['awareness_alarm']['enabled'],
            'can_edit' => $out['awareness_report']['can_edit'],
        ]] + $out;
        return $out;
    }

    // ═══════════════ Schreiben ═══════════════

    private static function set_site($key, $on) {
        switch ($key) {
            case 'awareness_report':
            case 'awareness_alarm':
                $f = get_option('tix_awareness_flags', []);
                $f = is_array($f) ? $f : [];
                $f = ['report_enabled' => !empty($f['report_enabled']) ? 1 : 0, 'alarm_live' => !empty($f['alarm_live']) ? 1 : 0];
                $f[$key === 'awareness_report' ? 'report_enabled' : 'alarm_live'] = $on ? 1 : 0;
                update_option('tix_awareness_flags', $f, false);
                if (class_exists('TIX_Awareness') && method_exists('TIX_Awareness', 'flush')) TIX_Awareness::flush();
                return;
            case 'app_coupons': update_option(self::OPT_APP_COUPONS, $on ? '1' : '0', false); return;
            case 'tips': update_option(self::OPT_TIPS, $on ? '1' : '0', false); return;
            case 'notifications': update_option(self::OPT_BROADCAST, $on ? '1' : '0', false); return;
            case 'raffles':
                update_option(self::OPT_RAFFLES, $on ? '1' : '0', false);
                if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
                return;
            case 'muttizettel': update_option(self::OPT_MUTTIZETTEL, $on ? '1' : '0', false); return;
            case 'music':
                $s = get_option(TIX_Music::OPT, []);
                update_option(TIX_Music::OPT, array_merge(is_array($s) ? $s : [], ['enabled' => $on ? 1 : 0]), false);
                return;
            case 'loyalty': update_option('_tix_loyalty_enabled', $on ? '1' : '0'); return;
            case 'wallet': TIX_Wallet_Balance::set_site_enabled($on); return;
            case 'giftcards':
                $s = TIX_Giftcards::get_settings();
                TIX_Giftcards::save_settings(['enabled' => $on ? 1 : 0, 'amounts' => implode(',', (array) ($s['amounts'] ?? [])), 'free_amount' => $s['free_amount'] ?? 0,
                    'free_min' => $s['free_min'] ?? null, 'free_max' => $s['free_max'] ?? null, 'validity_years' => $s['validity_years'] ?? 3]);
                return;
            case 'party_game':
                $o = get_option('tix_party', []);
                update_option('tix_party', array_merge(is_array($o) ? $o : [], ['enabled' => $on ? 1 : 0]), false);
                return;
            case 'sos': TIX_App_Sos::save(array_merge(TIX_App_Sos::settings(), ['enabled' => $on])); return;
            case 'live_now':
                $c = (array) TIX_App_Config::stored();
                $now = isset($c['home']['now']) && is_array($c['home']['now']) ? $c['home']['now'] : [];
                $c['home'] = isset($c['home']) && is_array($c['home']) ? $c['home'] : [];
                $c['home']['now'] = array_merge($now, ['show' => (bool) $on]);
                update_option('_tix_app_config', $c, false);
                return;
        }
    }

    private static function set_org($key, $oid, $on) {
        $mod = self::FEATURES[$key][1];
        $raw = get_post_meta($oid, TIX_Public_Platform::META_MODULES, true);
        if (is_string($raw) && $raw !== '') $raw = json_decode($raw, true);
        $raw = is_array($raw) ? $raw : [];
        $raw[$mod] = (bool) $on;
        $raw['tickets'] = true;
        update_post_meta($oid, TIX_Public_Platform::META_MODULES, wp_json_encode($raw));
        if ($key === 'loyalty') {
            $c = get_post_meta($oid, '_tix_org_loyalty', true);
            $c = is_array($c) ? $c : [];
            $c['enabled'] = (bool) $on;
            update_post_meta($oid, '_tix_org_loyalty', $c);
        }
        if ($key === 'giftcards' && $on && class_exists('TIX_Giftcards') && method_exists('TIX_Giftcards', 'ensure_organizer_event')) TIX_Giftcards::ensure_organizer_event($oid);
    }

    // ═══════════════ Studio-Payload ═══════════════

    /** Bearbeitungs-Bereich: [scope platform|site|organizer, organizer_id] */
    public static function scope(WP_REST_Request $req = null) {
        if (!self::multi()) return ['site', 0];
        if (!is_user_logged_in()) return ['platform', 0];
        if (self::is_admin()) {
            $want = $req ? ($req->get_param('organizer') ?? $req->get_param('organizer_id')) : null;
            $oid = absint($want);
            return ($oid && get_post_type($oid) === 'tix_organizer') ? ['organizer', $oid] : ['platform', 0];
        }
        return ['organizer', intval(TIX_App_Scope::organizer_id_for_user())];
    }

    /** Darf der Aufrufer den Schalter im Bereich setzen? */
    private static function can_edit($key, $scope, $oid) {
        if (!is_user_logged_in()) return false;
        if ($scope === 'organizer') return (bool) self::FEATURES[$key][1] && (self::is_admin() || (TIX_App_Scope::is_org_manager() && intval(TIX_App_Scope::organizer_id_for_user()) === intval($oid)));
        if ($scope === 'platform') {
            // evendis: nur der Admin; Prämien/Muttizettel gibt es nur je Veranstalter
            return self::is_admin() && !in_array($key, ['loyalty', 'muttizettel'], true);
        }
        return self::is_admin() || in_array('tix_organizer', (array) wp_get_current_user()->roles, true);
    }

    private static function entitled($key) {
        $mod = ['music' => 'music', 'loyalty' => 'loyalty', 'giftcards' => 'giftcards', 'muttizettel' => 'muttizettel', 'tips' => 'tips'][$key] ?? null;
        return !$mod || !class_exists('TIX_Modules') || TIX_Modules::allowed($mod);
    }

    private static function hint($key, $scope, $enabled, $site_on) {
        if (!self::entitled($key)) return 'Für diese Plattform nicht freigeschaltet.';
        if ($scope === 'organizer' && !self::FEATURES[$key][1]) return 'Schaltet die Plattform für alle Veranstalter.';
        if ($scope === 'organizer' && $key === 'music' && !$site_on) return 'Auf der Plattform gerade aus.';
        if ($scope === 'platform' && in_array($key, ['loyalty', 'muttizettel'], true)) return 'Schaltet jeder Veranstalter selbst.';
        if ($scope === 'platform' && in_array($key, ['music', 'giftcards', 'wallet'], true)) return 'Gilt für die Plattform; Veranstalter schalten ihr eigenes Modul.';
        if (in_array($key, ['awareness_report', 'awareness_alarm'], true) && self::multi() && !self::is_admin()) return 'Dem Plattform-Admin vorbehalten.';
        if ($key === 'awareness_alarm' && $enabled && !self::site_on('awareness_report')) return 'Live-Alarm ist an, Meldungen (Stufe 2) aber aus.';
        return null;
    }

    /** {scope, organizer_id, features {key: {key, label, enabled, status on|off|unavailable, can_edit, scope, available, hint}}, order[]} */
    public static function payload(WP_REST_Request $req = null) {
        list($scope, $oid) = self::scope($req);
        $out = [];
        foreach (self::FEATURES as $k => $def) {
            $org_level = $scope === 'organizer' && $def[1];
            $enabled = $org_level ? self::on($k, $oid) : self::site_on($k);
            $available = self::entitled($k);
            $fs = $org_level ? 'organizer' : ($scope === 'organizer' ? 'platform' : $scope);
            $out[$k] = [
                'key'       => $k,
                'label'     => $def[0],
                'enabled'   => (bool) $enabled,
                'status'    => !$available ? 'unavailable' : ($enabled ? 'on' : 'off'),
                'can_edit'  => $available && self::can_edit($k, $scope, $oid),
                'scope'     => $fs,
                'available' => $available,
                'hint'      => self::hint($k, $scope, $enabled, self::site_on($k)),
            ];
        }
        $r2 = $out['awareness_report']; $a3 = $out['awareness_alarm'];
        $out = ['awareness' => [
            'key' => 'awareness', 'label' => 'Awareness', 'enabled' => $r2['enabled'] || $a3['enabled'], 'report' => $r2['enabled'], 'alarm' => $a3['enabled'],
            'status' => $r2['enabled'] || $a3['enabled'] ? 'on' : 'off', 'can_edit' => $r2['can_edit'], 'scope' => $r2['scope'], 'available' => true, 'hint' => $r2['hint'] ?? $a3['hint'],
        ]] + $out;
        return ['scope' => $scope, 'organizer_id' => $oid ?: null, 'features' => $out, 'order' => self::ORDER];
    }

    /**
     * Schalter setzen (für POST /studio/features und /studio/config app_features). Erst alles prüfen, dann schreiben.
     * $in = {key: bool | {enabled: bool}} → true | WP_Error (field app_features.<key>)
     */
    public static function apply($in, WP_REST_Request $req = null) {
        if (!is_array($in)) return new WP_Error('tix_invalid_field', 'app_features bitte als Objekt {<key>: true|false}.', ['status' => 400, 'field' => 'app_features']);
        list($scope, $oid) = self::scope($req);
        // awareness: false = Stufe 2 und 3 aus, true = Stufe 2 an (Live-Alarm nur ausdrücklich), {report?, alarm?, enabled?}
        if (array_key_exists('awareness', $in)) {
            $a = $in['awareness'];
            unset($in['awareness']);
            if (is_array($a)) {
                if (array_key_exists('report', $a)) $in['awareness_report'] = $a['report'];
                if (array_key_exists('alarm', $a)) $in['awareness_alarm'] = $a['alarm'];
                if (array_key_exists('enabled', $a) && !array_key_exists('report', $a)) $a = $a['enabled']; else $a = null;
            }
            if ($a !== null) {
                $b = filter_var($a, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($b === null) return new WP_Error('tix_invalid_field', 'Bitte true oder false.', ['status' => 400, 'field' => 'app_features.awareness']);
                if (!array_key_exists('awareness_report', $in)) $in['awareness_report'] = $b;
                if (!$b && !array_key_exists('awareness_alarm', $in)) $in['awareness_alarm'] = false;
            }
        }
        $todo = [];
        foreach ($in as $k => $v) {
            $k = (string) $k;
            if (!isset(self::FEATURES[$k])) return new WP_Error('tix_invalid_field', 'Unbekannte Funktion: ' . $k, ['status' => 400, 'field' => 'app_features.' . $k]);
            if (is_array($v)) $v = array_key_exists('enabled', $v) ? $v['enabled'] : null;
            $b = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($b === null) return new WP_Error('tix_invalid_field', 'Bitte true oder false.', ['status' => 400, 'field' => 'app_features.' . $k]);
            if (!self::entitled($k)) return new WP_Error('tix_module_disabled', 'Diese Funktion ist für die Plattform nicht freigeschaltet.', ['status' => 409, 'field' => 'app_features.' . $k]);
            if (!self::can_edit($k, $scope, $oid)) return new WP_Error('rest_forbidden', in_array($k, ['awareness_report', 'awareness_alarm'], true) && self::multi() ? 'Awareness schaltet auf evendis nur der Plattform-Admin.' : 'Diese Funktion kannst du hier nicht schalten.', ['status' => 403, 'field' => 'app_features.' . $k]);
            $todo[$k] = $b;
        }
        foreach ($todo as $k => $b) {
            if ($scope === 'organizer' && self::FEATURES[$k][1]) self::set_org($k, $oid, $b);
            else self::set_site($k, $b);
        }
        if ($todo && function_exists('do_action')) do_action('tix_features_changed', $todo, $scope, $oid);
        return true;
    }

    public static function rest_get(WP_REST_Request $req) {
        $r = new WP_REST_Response(['ok' => true] + self::payload($req));
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /** Multipart/Formular: feature_<key>=1|0 → {key: bool}; leer, wenn keine. */
    public static function from_flat(array $params) {
        $out = [];
        foreach ($params as $k => $v) if (is_string($k) && strpos($k, 'feature_') === 0) $out[substr($k, 8)] = $v;
        return $out;
    }

    public static function rest_save(WP_REST_Request $req) {
        $b = $req->get_json_params();
        if (!is_array($b)) $b = $req->get_body_params();
        $flat = self::from_flat(array_merge((array) $req->get_body_params(), (array) $b));
        $in = isset($b['app_features']) ? $b['app_features'] : (isset($b['features']) ? $b['features'] : ($flat ?: $b));
        if (is_string($in)) { $dec = json_decode($in, true); $in = is_array($dec) ? $dec : $in; }
        if (is_array($in)) unset($in['organizer'], $in['organizer_id']);
        $res = self::apply($in, $req);
        if (is_wp_error($res)) return $res;
        return self::rest_get($req);
    }
}

TIX_Features::init();
