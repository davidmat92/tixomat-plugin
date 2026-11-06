<?php
if (!defined('ABSPATH')) exit;

/**
 * „Aus Angebot anlegen“ (1.38.682, Doku §99, Umbau 7 „Selbstgehostet“).
 *
 *   GET  /platform/offers/{id}/hosting           Vorbelegung für „Neuer Kunde“ (Plattform → Gehostete Kunden) aus dem Angebot
 *   POST /platform/offers/{id}/hosting {kurz, job_id?}   Angebot mit dem angelegten Kunden verknüpfen (Verlauf „hosting“)
 * Nur Plattform-Admins (gleiche Gruppe wie „Gehostete Kunden“ im Studio), nur im Mehr-Veranstalter-Modus.
 */
class TIX_Offer_Hosting {

    const NS = 'tixomat/v1';
    const RESERVED = ['neu', 'protokoll', 'auftrag'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        if (!class_exists('TIX_Platform_Offers') || !class_exists('TIX_App_Scope') || !TIX_App_Scope::multi()) return;
        register_rest_route(self::NS, '/platform/offers/(?P<id>\d+)/hosting', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => [__CLASS__, 'perm']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_link'], 'permission_callback' => [__CLASS__, 'perm']],
        ]);
    }

    public static function perm() {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        return current_user_can('manage_options') ? true : new WP_Error('rest_forbidden', 'Nur Plattform-Admins legen gehostete Kunden an.', ['status' => 403]);
    }

    /** Kurzname-Vorschlag wie im Studio/Dienst: ^[a-z][a-z0-9]{1,15}$, nicht reserviert. */
    public static function suggest_kurz($name) {
        $s = str_replace(['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'], ['ae', 'oe', 'ue', 'ae', 'oe', 'ue', 'ss'], (string) $name);
        $s = strtolower(remove_accents($s));
        // Rechtsformen und Füllwörter weg
        $s = preg_replace('/\b(gmbh|ug|haftungsbeschraenkt|ag|kg|ohg|gbr|e\.?\s?v|mbh|co|und|the|der|die|das)\b/', ' ', $s);
        $s = preg_replace('/[^a-z0-9]+/', '', $s);
        $s = ltrim($s, '0123456789');
        $s = substr($s, 0, 16);
        if (strlen($s) < 2 || in_array($s, self::RESERVED, true)) $s = 'kunde' . substr(md5((string) $name), 0, 4);
        return $s;
    }

    public static function prefill($row) {
        $d = TIX_Platform_Offers::data($row);
        $c = (array) ($d['customer'] ?? []);
        $items = array_values((array) ($d['items'] ?? []));
        $keys = array_map(function ($i) { return (string) ($i['catalog_key'] ?? ''); }, $items);
        $has = function ($k) use ($keys) { return in_array($k, $keys, true); };
        $name = trim((string) ($c['company'] ?? '')) ?: trim((string) ($c['name'] ?? ''));
        $kurz = self::suggest_kurz($name);
        $warnings = [];

        // Module der Lizenz aus den Positionen
        $modules = ['website'];
        $pick = 0;
        if ($has('app_alle_module')) {
            $modules = null; // alle
        } else {
            if ($has('app')) $modules[] = 'app';
            foreach ($items as $i) if (($i['catalog_key'] ?? '') === 'app_modul') $pick += max(1, intval($i['qty'] ?? 1));
            if ($pick) $warnings[] = ['code' => 'tix_offer_pick_modules', 'message' => "Im Angebot sind $pick App-Module – bitte die Module auswählen."];
        }

        $self_hosted = (bool) array_intersect($keys, TIX_Platform_Offers::SELF_HOSTED_KEYS);
        if (!$self_hosted) $warnings[] = ['code' => 'tix_offer_not_selfhosted', 'message' => 'Das Angebot enthält weder Shop noch Lizenz – gehostet wird normalerweise nur „Eigener Shop“ oder „Lizenz“.'];
        if ($row->status !== 'accepted') $warnings[] = ['code' => 'tix_offer_not_accepted', 'message' => 'Der Kunde hat noch kein Interesse bestätigt (Status „' . $row->status . '“).'];
        if (!empty($d['hosting']['kurz'])) $warnings[] = ['code' => 'tix_offer_already_hosted', 'message' => 'Aus diesem Angebot wurde schon „' . $d['hosting']['kurz'] . '“ angelegt.'];
        if (empty($c['email'])) $warnings[] = ['code' => 'tix_offer_no_email', 'message' => 'Im Angebot fehlt die E-Mail des Kunden (Login-Mail).'];

        $term = intval($d['term_months'] ?? 0);
        $valid = gmdate('Y-m-d', strtotime('+' . ($term > 0 ? $term : 12) . ' months'));
        $billing = $has('shop_monatlich') ? 'monthly' : ($has('shop') ? 'yearly' : ($has('lizenz') ? 'yearly' : ''));

        return [
            'offer' => ['id' => intval($row->id), 'number' => (string) $row->number, 'status' => (string) $row->status],
            'name' => $name,
            'kurz' => $kurz,
            'profile' => null, // aus dem Angebot nicht ableitbar – im Formular wählen
            'address' => $has('domain') ? ['mode' => 'domain', 'host' => null, 'transit' => "$kurz.evendis.de"] : ['mode' => 'transit', 'host' => "$kurz.evendis.de"],
            'accent' => null,
            'homepage_now' => true,
            'modules' => $modules,
            'modules_pick' => $pick,
            'license' => ['customer' => $name, 'email' => (string) ($c['email'] ?? ''), 'valid_until' => $valid, 'modules' => $modules, 'billing' => $billing],
            'contact' => ['name' => (string) ($c['name'] ?? ''), 'email' => (string) ($c['email'] ?? ''), 'phone' => (string) ($c['phone'] ?? '')],
            'company' => array_intersect_key($c, array_flip(['company', 'street', 'zip', 'city', 'country', 'vat_id'])),
            'items' => array_map(function ($i) { return ['key' => (string) ($i['catalog_key'] ?? ''), 'name' => (string) ($i['name'] ?? ''), 'qty' => intval($i['qty'] ?? 1), 'interval' => (string) ($i['interval'] ?? '')]; }, $items),
            'hosting' => $d['hosting'] ?? null,
            'warnings' => $warnings,
        ];
    }

    private static function load(WP_REST_Request $req) {
        $row = TIX_Platform_Offers::get(intval($req['id']));
        if (!$row) return new WP_Error('tix_offer_not_found', 'Angebot nicht gefunden.', ['status' => 404]);
        return $row;
    }

    public static function rest_get(WP_REST_Request $req) {
        $row = self::load($req);
        if (is_wp_error($row)) return $row;
        return rest_ensure_response(['ok' => true] + self::prefill($row));
    }

    public static function rest_link(WP_REST_Request $req) {
        $row = self::load($req);
        if (is_wp_error($row)) return $row;
        $b = $req->get_json_params() ?: $req->get_params();
        $kurz = strtolower(trim((string) ($b['kurz'] ?? '')));
        if (!preg_match('/^[a-z][a-z0-9]{1,15}$/', $kurz) || in_array($kurz, self::RESERVED, true)) {
            return new WP_Error('tix_invalid_field', 'Kurzname ungültig.', ['status' => 400, 'field' => 'kurz']);
        }
        $job = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($b['job_id'] ?? '')));
        $d = TIX_Platform_Offers::data($row);
        $d['hosting'] = ['kurz' => $kurz, 'job_id' => $job ?: null, 'at' => gmdate('c'), 'by' => get_current_user_id()];
        $d['history'] = array_slice(array_merge((array) ($d['history'] ?? []), [['at' => gmdate('c'), 'what' => 'hosting', 'by' => get_current_user_id(), 'kurz' => $kurz]]), -50);
        TIX_Platform_Offers::update_data($row->id, $d);
        return rest_ensure_response(['ok' => true, 'hosting' => $d['hosting']]);
    }
}

TIX_Offer_Hosting::init();
