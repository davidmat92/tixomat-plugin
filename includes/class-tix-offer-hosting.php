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
        // Kurzname schon vergeben (Kunde h-<kurz> oder anderes Angebot)? Dann frei machen (Audit 08.11.)
        if (self::kurz_taken($kurz, intval($row->id))) {
            $base = substr($kurz, 0, 14);
            for ($n = 2; $n < 100 && self::kurz_taken($base . $n, intval($row->id)); $n++);
            $warnings[] = ['code' => 'tix_offer_kurz_taken', 'message' => "Den Kurznamen „{$kurz}“ gibt es schon – Vorschlag „{$base}{$n}“."];
            $kurz = $base . $n;
        }

        // Module der Lizenz vollständig ableiten (Audit 08.11.): Positionen, deren „adds“, Modul-Positionen mit Namen,
        // Vorteile und die immer kostenlosen Module (Awareness, „kostenlos in jedem Paket“)
        list($modules, $pick, $named) = self::derive_modules($d, $items);
        if ($pick > $named) $warnings[] = ['code' => 'tix_offer_pick_modules', 'message' => 'Im Angebot sind ' . $pick . ' App-Module' . ($named ? ", davon $named mit Namen" : '') . ' – bitte die übrigen auswählen.'];

        $self_hosted = (bool) array_intersect($keys, TIX_Platform_Offers::SELF_HOSTED_KEYS);
        if (!$self_hosted) $warnings[] = ['code' => 'tix_offer_not_selfhosted', 'message' => 'Das Angebot enthält weder Shop noch Lizenz – gehostet wird normalerweise nur „Eigener Shop“ oder „Lizenz“.'];
        if ($row->status !== 'accepted') $warnings[] = ['code' => 'tix_offer_not_accepted', 'message' => 'Der Kunde hat noch kein Interesse bestätigt (Status „' . $row->status . '“).'];
        if (!empty($d['hosting']['kurz'])) $warnings[] = ['code' => 'tix_offer_already_hosted', 'message' => 'Aus diesem Angebot wurde schon „' . $d['hosting']['kurz'] . '“ angelegt.'];
        if (empty($c['email'])) $warnings[] = ['code' => 'tix_offer_no_email', 'message' => 'Im Angebot fehlt die E-Mail des Kunden (Login-Mail).'];

        // Abrechnung aus der Plan-Position, Laufzeit aus term_months, sonst aus der Abrechnung
        $billing = '';
        foreach ($items as $i) {
            $k = (string) ($i['catalog_key'] ?? '');
            if (!in_array($k, ['shop', 'shop_monatlich', 'lizenz'], true)) continue;
            // Jahresvertrag (Shop, Lizenz) = jährlich, auch wenn der Preis monatlich angegeben ist
            $billing = $k === 'shop_monatlich' ? 'monthly' : 'yearly';
            break;
        }
        $term = intval($d['term_months'] ?? 0);
        if ($term <= 0) $term = $billing === 'monthly' && $has('shop_monatlich') ? 1 : 12;
        $valid = gmdate('Y-m-d', strtotime('+' . $term . ' months'));

        return [
            'offer' => ['id' => intval($row->id), 'number' => (string) $row->number, 'status' => (string) $row->status],
            'name' => $name,
            'kurz' => $kurz,
            'profile' => null, // aus dem Angebot nicht ableitbar – im Formular wählen
            'address' => $has('domain') ? ['mode' => 'domain', 'host' => null, 'transit' => "$kurz.evendis.de"] : ['mode' => 'transit', 'host' => "$kurz.evendis.de"],
            'accent' => null,
            'homepage_now' => true,
            'modules' => $modules,
            'modules_pick' => max(0, $pick - $named),
            'term_months' => $term,
            'license' => ['customer' => $name, 'email' => (string) ($c['email'] ?? ''), 'valid_until' => $valid, 'modules' => $modules, 'billing' => $billing],
            'contact' => ['name' => (string) ($c['name'] ?? ''), 'email' => (string) ($c['email'] ?? ''), 'phone' => (string) ($c['phone'] ?? '')],
            'company' => array_intersect_key($c, array_flip(['company', 'street', 'zip', 'city', 'country', 'vat_id'])),
            'items' => array_map(function ($i) { return ['key' => (string) ($i['catalog_key'] ?? ''), 'name' => (string) ($i['name'] ?? ''), 'qty' => intval($i['qty'] ?? 1), 'interval' => (string) ($i['interval'] ?? '')]; }, $items),
            'hosting' => $d['hosting'] ?? null,
            'warnings' => $warnings,
        ];
    }

    /** Immer kostenlose Lizenz-Module (Katalog-Vorteil „awareness“: kostenlos in jedem Paket). */
    const FREE_MODULES = ['awareness'];
    /** Vorteil → Modul */
    const BENEFIT_MODULES = ['awareness' => 'awareness'];

    /** [module|null (alle), Anzahl Modul-Positionen, davon mit Namen erkannt] */
    public static function derive_modules(array $d, array $items) {
        $known = class_exists('TIX_Modules') ? TIX_Modules::keys() : [];
        $labels = [];
        if (class_exists('TIX_Modules')) foreach (TIX_Modules::MODULES as $k => $m) $labels[$k] = mb_strtolower($m[0]);
        $keys = [];
        foreach ($items as $i) {
            $k = (string) ($i['catalog_key'] ?? '');
            if ($k !== '') $keys[] = $k;
            $c = $k !== '' ? TIX_Platform_Offers::catalog_item($k) : null;
            foreach ((array) ($c['adds'] ?? []) as $a) $keys[] = (string) $a;
        }
        if (in_array('app_alle_module', $keys, true)) return [null, 0, 0];
        $mods = ['website'];
        foreach ($keys as $k) {
            if ($k === 'app') $mods[] = 'app';
            elseif (in_array($k, $known, true)) $mods[] = $k; // Katalog-Position heißt wie ein Modul
        }
        $pick = 0; $named = 0;
        foreach ($items as $i) {
            if (($i['catalog_key'] ?? '') !== 'app_modul') continue;
            $qty = max(1, intval($i['qty'] ?? 1));
            $pick += $qty;
            // „App-Modul: Prämien“ bzw. Notiz „Musikwunsch, Gewinnspiele“ → Schlüssel
            // nur Name und Notiz der Position (die Katalog-Beschreibung nennt Beispiele)
            $text = mb_strtolower(implode(' ', [(string) ($i['name'] ?? ''), (string) ($i['note'] ?? '')]));
            $found = 0;
            foreach ($labels as $k => $l) {
                if (in_array($k, ['website', 'app'], true) || mb_strpos($text, $l) === false) continue;
                $mods[] = $k; $found++;
            }
            $named += min($qty, $found);
        }
        foreach ((array) ($d['benefits'] ?? []) as $b) {
            $bk = (string) ($b['key'] ?? '');
            if (!empty($b['on']) && isset(self::BENEFIT_MODULES[$bk])) $mods[] = self::BENEFIT_MODULES[$bk];
        }
        $mods = array_merge($mods, self::FREE_MODULES);
        $mods = array_values(array_intersect($known ?: $mods, array_unique($mods)));
        return [$mods, $pick, $named];
    }

    private static function kurz_taken_by_offer($kurz, $except_offer) {
        global $wpdb;
        $t = TIX_Platform_Offers::table();
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE id <> %d AND data LIKE %s LIMIT 1", intval($except_offer), '%"hosting":{"kurz":"' . $wpdb->esc_like($kurz) . '"%'));
    }

    /** Kurzname belegt: als Kunde (h-<kurz>, auch Alias) oder an einem anderen Angebot verknüpft. */
    public static function kurz_taken($kurz, $except_offer = 0) {
        global $wpdb;
        if (class_exists('TIX_Platform_Customers')) {
            TIX_Platform_Customers::flush();
            if (TIX_Platform_Customers::canonical('h-' . $kurz)) return true;
        }
        $t = TIX_Platform_Offers::table();
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE id <> %d AND data LIKE %s LIMIT 1", intval($except_offer), '%"hosting":{"kurz":"' . $wpdb->esc_like($kurz) . '"%'));
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
        if (self::kurz_taken_by_offer($kurz, intval($row->id))) {
            return new WP_Error('tix_kurz_taken', 'Dieser Kurzname ist schon mit einem anderen Angebot verknüpft.', ['status' => 409, 'field' => 'kurz']);
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
