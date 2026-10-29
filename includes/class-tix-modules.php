<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Modules – Freischaltung der Module über die Lizenz (Modul-Verkauf, 1.38.587).
 *
 * Ein Schlüsselsatz für Lizenz und TIX_Plan: Pakete (website, app), Extras (seating, wallet, giftcards,
 * loyalty, music, awareness, muttizettel, raffles, assistant, degaso) und App-Extras (tips, party_game, sos).
 * Die „Immer dabei“-Module (TIX_Plan::ALWAYS) sind nie Teil der Lizenz-Liste.
 *
 * Quelle der Freischaltung:
 *   1. Lizenz (Selbstbetrieb bzw. von uns betriebene Kundenseite): Module aus der Lizenz (Spalte `modules`; null = alle)
 *   2. Von uns betriebene Plattform ohne Lizenz: Option `tix_modules_entitled` (Liste), per WP-CLI `wp tixomat modules`
 *   3. Nichts gesetzt = alle Module frei (KitchenKlub, Mallorca, tixomat.de, evendis – keine Änderung)
 * Lizenziert heißt „darf“; ob ein Modul genutzt wird, entscheiden darunter die Betriebsschalter (TIX_Features,
 * TIX_Plan). Nicht lizenziert: Schalter liefern „aus“, Routen 403 `tix_module_disabled`, Einschalten 409.
 * Ältere Lizenz-Listen (vor 1.38.587, nur loyalty/music/giftcards/muttizettel/seating/tips) sperren die neuen
 * Schlüssel nicht (`LEGACY`): was bisher frei war, bleibt frei, bis die Lizenz neu gespeichert wird.
 * `GET /info` → `modules` {key: {label, allowed, group}} + `modules_source` all|platform|license.
 */
class TIX_Modules {

    const OPTION = 'tix_modules_entitled';

    /** Schlüssel der Listen vor 1.38.587 (Migration: neue Schlüssel bleiben bei alten Listen frei) */
    const LEGACY = ['loyalty', 'music', 'giftcards', 'muttizettel', 'seating', 'tips'];

    /** Version des Schlüsselsatzes (Lizenz-Antwort `modules_v`) */
    const VERSION = 2;

    /** key => [Bezeichnung, REST-Muster (ohne Namespace) oder null, Gruppe package|extra|app] */
    const MODULES = [
        'website'     => ['Eigene Website', '#^/(studio/website|organizer/domain)(/|$)#', 'package'],
        'app'         => ['Eigene App', null, 'package'],
        'seating'     => ['Saalplan & Tische', '#^/(customer/seats|customer/table-reservations|public/events/\d+/(seatmap|tables))(/|$)#', 'extra'],
        'wallet'      => ['Guthaben', '#^/wallet(/|$)#', 'extra'],
        'giftcards'   => ['Geschenkgutscheine', '#^/(giftcards|customer/giftcards|pos/giftcard)(/|$)#', 'extra'],
        'loyalty'     => ['Prämien', '#^/loyalty(/|$)#', 'extra'],
        'music'       => ['Musikwunsch', '#^/music(/|$)#', 'extra'],
        'awareness'   => ['Awareness', '#^/awareness(/|$)#', 'extra'],
        'muttizettel' => ['Muttizettel', null, 'extra'],
        'raffles'     => ['Gewinnspiele', '#^/(raffles|public/events/\d+/raffle)(/|$)#', 'extra'],
        'assistant'   => ['KI-Assistent', '#^/assistant(/|$)#', 'extra'],
        'degaso'      => ['Degaso-Kasse', '#^/degaso(/|$)#', 'extra'],
        'tips'        => ['Tipps des Tages', '#^/(app|public)/tips(/|$)#', 'app'],
        'party_game'  => ['Partyspiel', '#^/party(/|$)#', 'app'],
        'sos'         => ['SOS', '#^/app/sos(/|$)#', 'app'],
    ];

    /** Nur schreibende Zugriffe gesperrt (die App liest weiter den letzten Stand) */
    const WRITE_ROUTES = [
        'app' => '#^/app/config(/|$)#',
    ];

    public static function init() {
        add_filter('rest_pre_dispatch', [__CLASS__, 'guard_rest'], 5, 3);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('tixomat modules', [__CLASS__, 'cli']);
        }
    }

    public static function keys() {
        return array_keys(self::MODULES);
    }

    /** Freigeschaltete Module (Liste) oder null = alle. */
    public static function entitled() {
        if (class_exists('TIX_License') && TIX_License::active()) {
            $s = TIX_License::status();
            if (array_key_exists('modules', $s)) {
                return is_array($s['modules']) ? self::normalize($s['modules'], intval($s['modules_v'] ?? 1)) : null;
            }
        }
        $opt = get_option(self::OPTION, null);
        return is_array($opt) ? self::normalize($opt, intval(get_option(self::OPTION . '_v', 1))) : null;
    }

    /**
     * Liste auf bekannte Schlüssel begrenzen. Alte Listen (Version 1) kannten nur LEGACY: die übrigen
     * Schlüssel waren dort nie einschränkbar und bleiben deshalb frei.
     */
    public static function normalize(array $list, $version = self::VERSION) {
        $list = array_map('sanitize_key', $list);
        if (intval($version) < self::VERSION) $list = array_merge($list, array_diff(self::keys(), self::LEGACY));
        return array_values(array_intersect(self::keys(), $list));
    }

    /** Bezeichnung eines Schlüssels */
    public static function label($key) {
        return self::MODULES[$key][0] ?? $key;
    }

    public static function source() {
        if (class_exists('TIX_License') && TIX_License::active() && array_key_exists('modules', TIX_License::status())) {
            return is_array(TIX_License::status()['modules']) ? 'license' : 'all';
        }
        return is_array(get_option(self::OPTION, null)) ? 'platform' : 'all';
    }

    public static function allowed($key) {
        $e = self::entitled();
        return $e === null || !isset(self::MODULES[$key]) || in_array($key, $e, true);
    }

    /** Veranstalter-Module (TIX_Public_Platform::modules) auf die Freischaltung der Plattform begrenzen. */
    public static function mask(array $mods) {
        foreach ($mods as $k => $v) {
            if ($v && isset(self::MODULES[$k]) && !self::allowed($k)) $mods[$k] = false;
        }
        return $mods;
    }

    public static function guard_rest($result, $server, $request) {
        if ($result !== null || !($request instanceof WP_REST_Request) || self::entitled() === null) return $result;
        $route = (string) $request->get_route();
        if (strpos($route, '/tixomat/v1/') !== 0) return $result;
        $rel = substr($route, strlen('/tixomat/v1'));
        $hit = null;
        foreach (self::MODULES as $key => $m) {
            if ($m[1] && preg_match($m[1], $rel)) { $hit = $key; break; }
        }
        $method = strtoupper($request->get_method());
        if (!$hit && $method !== 'GET' && $method !== 'HEAD') {
            foreach (self::WRITE_ROUTES as $key => $re) {
                if (preg_match($re, $rel)) { $hit = $key; break; }
            }
        }
        if ($hit && !self::allowed($hit)) {
            return new WP_Error('tix_module_disabled', 'Dieses Modul ist nicht in deinem Paket. Du kannst es im Studio anfragen.', ['status' => 403, 'module' => $hit]);
        }
        return $result;
    }

    public static function info_payload() {
        $out = [];
        foreach (self::MODULES as $key => $m) $out[$key] = ['label' => $m[0], 'allowed' => self::allowed($key), 'group' => $m[2]];
        return $out;
    }

    /** Nicht lizenzierte Schlüssel (leer = alles frei) */
    public static function locked() {
        $e = self::entitled();
        return $e === null ? [] : array_values(array_diff(self::keys(), $e));
    }

    // ═══════════════ „Jetzt anfragen“ ═══════════════

    public static function routes() {
        register_rest_route('tixomat/v1', '/studio/module-request', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_request'], 'permission_callback' => [__CLASS__, 'perm_request'],
        ]);
    }

    /** Admins, Veranstalter-Konten und auf der Plattform Inhaber/Team-Admins */
    public static function perm_request() {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]);
        $u = wp_get_current_user();
        if (current_user_can('manage_options') || in_array('tix_organizer', (array) $u->roles, true)) return true;
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && TIX_App_Scope::is_org_manager()) return true;
        return new WP_Error('rest_forbidden', 'Module fragt der Inhaber bzw. ein Admin an.', ['status' => 403]);
    }

    /**
     * POST /studio/module-request {module, message?} → {ok, ticket?}
     * Plattform (evendis.de): Anfrage im Betreiber-Support für den eigenen Veranstalter.
     * Lizenz (Kundenseite): an den Lizenz-Server, der daraus eine Anfrage im Betreiber-Support macht.
     */
    public static function rest_request(WP_REST_Request $req) {
        $key = sanitize_key((string) $req->get_param('module'));
        if (!isset(self::MODULES[$key])) return new WP_Error('tix_invalid_field', 'Unbekanntes Modul.', ['status' => 400, 'field' => 'module']);
        $u = wp_get_current_user();
        // erst zählen, wenn wirklich angefragt wird (Ablehnungen kosten nichts)
        $limited = function () {
            return class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('mod_req', 5, 600, (string) get_current_user_id());
        };
        $too_many = new WP_Error('tix_rate_limit', 'Du hast gerade schon angefragt. Wir melden uns.', ['status' => 429]);
        $note = mb_substr(sanitize_textarea_field((string) $req->get_param('message')), 0, 1000);
        $site = preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
        $multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
        if ($multi) {
            $oid = class_exists('TIX_Plan') ? TIX_Plan::current_org() : 0;
            if (!$oid) return new WP_Error('tix_module_request_unavailable', 'Als Plattform-Admin schaltest du Module selbst.', ['status' => 409]);
            if (class_exists('TIX_Plan') && TIX_Plan::org_on($key, $oid)) return new WP_Error('tix_module_already', 'Dieses Modul ist schon freigeschaltet.', ['status' => 409]);
            if ($limited()) return $too_many;
            $org = get_the_title($oid);
            $tid = self::create_request_ticket($key, $org, $site, $u->display_name, $u->user_email, $note, ['_tix_sp_help_org' => $oid]);
            return is_wp_error($tid) ? $tid : rest_ensure_response(['ok' => true, 'ticket' => $tid]);
        }
        if (!class_exists('TIX_License') || !TIX_License::active() || (string) get_option(TIX_License::OPT_KEY, '') === '') {
            return new WP_Error('tix_module_request_unavailable', 'Für diese Seite sind alle Module frei.', ['status' => 409]);
        }
        if (self::allowed($key)) return new WP_Error('tix_module_already', 'Dieses Modul ist schon freigeschaltet.', ['status' => 409]);
        if ($limited()) return $too_many;
        $res = wp_remote_post(TIX_License::server() . '/wp-json/tixomat/v1/license/module-request', [
            'timeout' => 15, 'headers' => ['Accept' => 'application/json'],
            'body' => ['key' => (string) get_option(TIX_License::OPT_KEY, ''), 'site' => home_url(), 'module' => $key,
                       'name' => $u->display_name, 'email' => $u->user_email, 'message' => $note],
        ]);
        $code = is_wp_error($res) ? 0 : intval(wp_remote_retrieve_response_code($res));
        if ($code < 200 || $code >= 300) {
            return new WP_Error('tix_module_request_failed', 'Die Anfrage kam nicht an. Bitte versuche es gleich noch einmal oder schreib uns.', ['status' => 502]);
        }
        $j = json_decode((string) wp_remote_retrieve_body($res), true);
        return rest_ensure_response(['ok' => true, 'ticket' => intval($j['ticket'] ?? 0) ?: null]);
    }

    /** Anfrage im Betreiber-Support (Plattform-Studio „Support“) anlegen. → Ticket-ID oder WP_Error */
    public static function create_request_ticket($key, $customer, $site, $name, $email, $note = '', array $meta = []) {
        $label = self::label($key);
        $subject = mb_substr('Modul anfragen: ' . $label . ($customer !== '' ? ' – ' . $customer : ''), 0, 120);
        $message = 'Bitte ' . $label . ' freischalten.' . "\n\nKunde: " . ($customer ?: '–') . "\nSeite: " . ($site ?: '–') . "\nModul: " . $label . ' (' . $key . ')'
            . ($note !== '' ? "\n\nNachricht: " . $note : '');
        $tid = wp_insert_post(['post_type' => 'tix_support_ticket', 'post_title' => $subject, 'post_status' => 'tix_open'], true);
        if (is_wp_error($tid) || !$tid) return new WP_Error('tix_support_failed', 'Anfrage konnte nicht angelegt werden.', ['status' => 500]);
        $help = class_exists('TIX_Platform_Admin') ? TIX_Platform_Admin::HELP_META : '_tix_sp_help';
        $base = ['_tix_sp_email' => sanitize_email((string) $email), '_tix_sp_name' => sanitize_text_field((string) $name), '_tix_sp_category' => 'beratung',
            '_tix_sp_priority' => 'normal', '_tix_sp_source' => 'module_request', '_tix_sp_module' => $key, '_tix_sp_last_reply' => current_time('c'),
            $help => 1, '_tix_sp_help_site' => (string) $site, '_tix_sp_help_site_name' => (string) $customer, '_tix_sp_help_unread' => 1];
        foreach ($meta + $base as $k => $v) update_post_meta($tid, $k, $v);
        update_post_meta($tid, '_tix_sp_messages', [['id' => 'msg_' . bin2hex(random_bytes(8)), 'type' => 'customer', 'author' => $name ?: $email,
            'email' => $email, 'content' => $message, 'date' => current_time('c')]]);
        $to = (string) get_option('admin_email');
        if ($to !== '') wp_mail($to, $subject, $message . "\n\nIm Plattform-Studio unter Support.");
        return intval($tid);
    }

    /**
     * Module der Plattform anzeigen oder setzen.
     *
     * ## OPTIONS
     *
     * [<aktion>]
     * : list (Vorgabe) | set | all
     *
     * [<module>]
     * : Bei „set“: Komma-Liste, z. B. loyalty,music,app (leer = keine)
     *
     * ## EXAMPLES
     *
     *     wp tixomat modules
     *     wp tixomat modules set loyalty,music,tips
     *     wp tixomat modules all
     */
    public static function cli($args) {
        $do = $args[0] ?? 'list';
        if ($do === 'set') {
            $want = array_filter(array_map('trim', explode(',', (string) ($args[1] ?? ''))));
            $bad = array_diff($want, self::keys());
            if ($bad) WP_CLI::error('Unbekannt: ' . implode(', ', $bad) . '. Möglich: ' . implode(', ', self::keys()));
            update_option(self::OPTION, array_values($want), false);
            update_option(self::OPTION . '_v', self::VERSION, false);
            WP_CLI::success('Freigeschaltet: ' . ($want ? implode(', ', $want) : 'keine'));
        } elseif ($do === 'all') {
            delete_option(self::OPTION);
            WP_CLI::success('Alle Module frei (keine Einschränkung).');
        }
        if (self::source() === 'license') WP_CLI::warning('Selbstbetrieb: Die Freischaltung kommt aus der Lizenz, die Option wird ignoriert.');
        $rows = [];
        foreach (self::MODULES as $key => $m) $rows[] = ['modul' => $key, 'name' => $m[0], 'frei' => self::allowed($key) ? 'ja' : 'nein'];
        WP_CLI\Utils\format_items('table', $rows, ['modul', 'name', 'frei']);
        WP_CLI::log('Quelle: ' . self::source());
    }
}
