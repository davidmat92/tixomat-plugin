<?php
if (!defined('ABSPATH')) exit;
/**
 * TIX_Plan – ein Schlüsselsatz für alle Module (1.38.563, Plan docs/plan-module-website-app.md
 * im Repo tixomat-apps, Auftrag „so bauen“ 2026-10-28).
 *
 * Drei Gruppen:
 *   ALWAYS   immer dabei, nicht abschaltbar (Ticketverkauf, Einlass, Abendkasse, Gästeliste,
 *            Rabattcodes, Kundenanfragen, Statistik, Team, Rechnungen)
 *   PACKAGES website (Eigene Website), app (Eigene App)
 *   EXTRAS   seating, wallet, giftcards, loyalty, music, awareness, muttizettel, raffles, assistant, degaso
 *
 * Zwei Ebenen, ein Satz:
 *   Seite (Einzelseite wie KitchenKlub/Mallorca, eigener Shop): Freischaltung aus Lizenz bzw.
 *     Option `tix_modules_entitled` (TIX_Modules). Nichts gesetzt = alles an. Pakete lesen die
 *     vorhandenen Optionen (`tix_website_enabled`, `tix_studio_has_app`). Am Verhalten einer
 *     Einzelseite ändert diese Klasse nichts: Sie setzt dort nur durch, was TIX_Modules schon sperrt.
 *   Veranstalter (Mehr-Veranstalter-Modus, evendis.de): `_tix_org_modules` je Veranstalter,
 *     geschaltet im Plattform-Studio. Ist ein Modul aus, antworten Verwaltungs- und Gast-Routen
 *     des Moduls mit 403 `tix_module_disabled` (Daten bleiben).
 *
 * Ausgabe: `/me.modules` {key: bool} für das angemeldete Konto, `/info.plan` (Seite).
 */
class TIX_Plan {
    const ALWAYS   = ['tickets', 'checkin', 'pos', 'guestlist', 'coupons', 'support', 'stats', 'team', 'invoices'];
    const PACKAGES = ['website', 'app'];
    const EXTRAS   = ['seating', 'wallet', 'giftcards', 'loyalty', 'music', 'awareness', 'muttizettel', 'raffles', 'assistant', 'degaso'];

    /** Vorgaben für Veranstalter ohne gespeicherten Wert (Bestand: was bisher galt). */
    const ORG_DEFAULTS = [
        'website'     => false,
        'app'         => false,
        'seating'     => true,  // bisher ohne Veranstalter-Schalter verfügbar
        'wallet'      => false,
        'giftcards'   => false,
        'loyalty'     => false,
        'music'       => false,
        'awareness'   => true,  // bisher immer verfügbar
        'muttizettel' => false,
        'raffles'     => true,  // bisher ohne Veranstalter-Schalter verfügbar
        'assistant'   => false, // auf evendis bisher nur Admin
        'degaso'      => false, // evendis nutzt Degaso nicht
    ];

    const META    = '_tix_org_modules';
    const MIGRATE = 'tix_plan_migrated';

    /**
     * Routen je Modul (ohne Namespace). Lesende Gast-Routen einer ausgelieferten App gehören
     * nur dazu, wenn das Modul sie wirklich braucht (z. B. Musikwunsch) – nie /app/config, /info.
     */
    const ROUTES = [
        'seating'   => '#^/(customer/seats|customer/table-reservations|public/events/\d+/(seatmap|tables))(/|$)#',
        'wallet'    => '#^/wallet(/|$)#',
        'giftcards' => '#^/(giftcards|customer/giftcards|pos/giftcard)(/|$)#',
        'loyalty'   => '#^/loyalty(/|$)#',
        'music'     => '#^/music(/|$)#',
        'awareness' => '#^/(awareness|app/sos)(/|$)#',
        'raffles'   => '#^/(raffles|public/events/\d+/raffle)(/|$)#',
        'assistant' => '#^/assistant(/|$)#',
        'degaso'    => '#^/degaso(/|$)#',
        'website'   => '#^/(studio/website|organizer/domain)(/|$)#',
    ];

    /** Nur schreibende Zugriffe gesperrt (Gäste lesen weiter den letzten Stand). */
    const WRITE_ROUTES = [
        'app' => '#^/(app/config|app/tips)(/|$)#',
    ];

    const LABELS = [
        'tickets' => 'Ticketverkauf', 'checkin' => 'Einlass-Scanner', 'pos' => 'Abendkasse', 'guestlist' => 'Gästeliste',
        'coupons' => 'Rabattcodes', 'support' => 'Kundenanfragen', 'stats' => 'Statistik', 'team' => 'Team', 'invoices' => 'Rechnungen',
        'website' => 'Eigene Website', 'app' => 'Eigene App',
        'seating' => 'Saalplan & Tische', 'wallet' => 'Guthaben', 'giftcards' => 'Geschenkgutscheine', 'loyalty' => 'Prämien',
        'music' => 'Musikwunsch', 'awareness' => 'Awareness', 'muttizettel' => 'Muttizettel', 'raffles' => 'Gewinnspiele',
        'assistant' => 'KI-Assistent', 'degaso' => 'Degaso-Kasse',
    ];

    public static function init() {
        add_filter('rest_pre_dispatch', [__CLASS__, 'guard_rest'], 6, 3);
        add_action('init', [__CLASS__, 'maybe_migrate'], 30);
    }

    /** Schaltbare Schlüssel (Pakete + Extras). */
    public static function switchable() {
        return array_merge(self::PACKAGES, self::EXTRAS);
    }

    public static function all_keys() {
        return array_merge(self::ALWAYS, self::switchable());
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    // ═══════════════ Seite ═══════════════

    /** Modul auf Ebene der Seite an? Einzelseite: Lizenz/Option + vorhandene Paket-Optionen. */
    public static function site_on($key) {
        if (in_array($key, self::ALWAYS, true)) return true;
        if (!in_array($key, self::switchable(), true)) return false;
        $entitled = !class_exists('TIX_Modules') || TIX_Modules::allowed($key);
        if (!$entitled) return false;
        if (self::multi()) return true; // Plattform: Schalter je Veranstalter
        if ($key === 'website') return class_exists('TIX_Website') ? TIX_Website::package_enabled() : false;
        if ($key === 'app') return class_exists('TIX_Studio') && method_exists('TIX_Studio', 'app_info') ? !empty(TIX_Studio::app_info()['has_app']) : false;
        return true;
    }

    // ═══════════════ Veranstalter ═══════════════

    /** Gespeicherte Module des Veranstalters mit Vorgaben (nur schaltbare Schlüssel). */
    public static function org_raw($oid) {
        $raw = get_post_meta(intval($oid), self::META, true);
        if (is_string($raw) && $raw !== '') $raw = json_decode($raw, true);
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach (self::ORG_DEFAULTS as $k => $v) {
            $out[$k] = array_key_exists($k, $raw) ? (bool) $raw[$k] : $v;
        }
        return $out;
    }

    /** Modul für Veranstalter `$oid` an? Immer-Module an; sonst Seite UND Veranstalter. */
    public static function org_on($key, $oid) {
        if (in_array($key, self::ALWAYS, true)) return true;
        if (!self::site_on($key)) return false;
        if (!$oid || !self::multi()) return true;
        $m = self::org_raw($oid);
        return !empty($m[$key]);
    }

    /** Alle Schlüssel → bool für Veranstalter `$oid` (0 = Ebene der Seite). */
    public static function modules($oid = 0) {
        $out = [];
        foreach (self::all_keys() as $k) $out[$k] = $oid ? self::org_on($k, $oid) : self::site_on($k);
        return $out;
    }

    /** Speichern (Plattform-Studio). Nur schaltbare Schlüssel; Immer-Module bleiben fest an. */
    public static function save_org($oid, array $in) {
        $raw = get_post_meta(intval($oid), self::META, true);
        if (is_string($raw) && $raw !== '') $raw = json_decode($raw, true);
        $raw = is_array($raw) ? $raw : [];
        foreach ($in as $k => $v) {
            if (in_array($k, self::switchable(), true)) $raw[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
        }
        foreach (self::ALWAYS as $k) $raw[$k] = true;
        update_post_meta(intval($oid), self::META, wp_json_encode($raw));
        // Eigene Domain gehört zum Paket „Eigene Website“ (Freigabe der Domain synchron halten; die Domain selbst bleibt gespeichert)
        if (array_key_exists('website', $in) && class_exists('TIX_Org_Domains')) {
            update_post_meta(intval($oid), TIX_Org_Domains::META_ALLOW, !empty($raw['website']) ? '1' : '0');
        }
        return self::modules($oid);
    }

    // ═══════════════ Konto, Anfrage ═══════════════

    /** Veranstalter des angemeldeten Kontos (nur Mehr-Veranstalter-Modus, nicht für Admins). */
    public static function current_org() {
        if (!self::multi() || !is_user_logged_in()) return 0;
        if (TIX_App_Scope::is_admin()) return 0;
        return intval(TIX_App_Scope::organizer_id_for_user());
    }

    /** `/me.modules`: Module des angemeldeten Kontos. */
    public static function me_payload() {
        return self::modules(self::current_org());
    }

    /** `/info.plan`: Gruppen + Zustand der Seite. */
    public static function info_payload() {
        $out = ['always' => self::ALWAYS, 'packages' => [], 'extras' => []];
        foreach (self::PACKAGES as $k) $out['packages'][$k] = self::site_on($k);
        foreach (self::EXTRAS as $k) $out['extras'][$k] = self::site_on($k);
        return $out;
    }

    /** Veranstalter, um den es in einer Anfrage geht (Konto › ?organizer › Event). */
    public static function request_org(WP_REST_Request $req, $rel) {
        $oid = self::current_org();
        if ($oid) return $oid;
        $p = $req->get_param('organizer');
        if ($p && class_exists('TIX_Features')) {
            $o = TIX_Features::org_from_param($p);
            if ($o) return $o;
        }
        $eid = intval($req->get_param('event_id'));
        if (!$eid && preg_match('#^/(public/)?events/(\d+)/#', $rel, $m)) $eid = intval($m[2]);
        if ($eid && class_exists('TIX_App_Scope')) {
            $ids = (array) TIX_App_Scope::event_org_ids($eid);
            if ($ids) return intval(reset($ids));
        }
        return 0;
    }

    /**
     * Durchsetzung: Auf der Plattform sperrt ein ausgeschaltetes Modul seine Routen für den
     * betroffenen Veranstalter. Einzelseiten bleiben unverändert (dort sperrt TIX_Modules wie bisher).
     */
    public static function guard_rest($result, $server, $request) {
        if ($result !== null || !($request instanceof WP_REST_Request) || !self::multi()) return $result;
        $route = (string) $request->get_route();
        if (strpos($route, '/tixomat/v1/') !== 0) return $result;
        $rel = substr($route, strlen('/tixomat/v1'));
        $key = null;
        foreach (self::ROUTES as $k => $re) {
            if (preg_match($re, $rel)) { $key = $k; break; }
        }
        if (!$key && strtoupper($request->get_method()) !== 'GET') {
            foreach (self::WRITE_ROUTES as $k => $re) {
                if (preg_match($re, $rel)) { $key = $k; break; }
            }
        }
        if (!$key) return $result;
        $oid = self::request_org($request, $rel);
        if (!$oid || self::org_on($key, $oid)) return $result;
        return new WP_Error('tix_module_disabled', 'Diese Funktion ist für diesen Veranstalter nicht freigeschaltet.', [
            'status' => 403, 'module' => $key,
        ]);
    }

    // ═══════════════ Migration ═══════════════

    /**
     * Einmalig (Plattform): Immer-Module fest an; „Eigene Website“ an, wo die eigene Domain
     * schon freigegeben ist. Es wird nichts gelöscht.
     */
    public static function maybe_migrate() {
        if (get_option(self::MIGRATE) === '1' || !self::multi()) return;
        $ids = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']);
        foreach ($ids as $oid) {
            $raw = get_post_meta($oid, self::META, true);
            if (is_string($raw) && $raw !== '') $raw = json_decode($raw, true);
            $raw = is_array($raw) ? $raw : [];
            foreach (self::ALWAYS as $k) $raw[$k] = true;
            if (!array_key_exists('website', $raw) && class_exists('TIX_Org_Domains')) {
                $raw['website'] = get_post_meta($oid, TIX_Org_Domains::META_ALLOW, true) === '1';
            }
            update_post_meta($oid, self::META, wp_json_encode($raw));
        }
        update_option(self::MIGRATE, '1', false);
    }
}
