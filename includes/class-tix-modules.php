<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Modules – Freischaltung der Module je Plattform (Grundlage für Modul-Abos, Paket 3b).
 *
 * Quelle der Freischaltung:
 *   1. Selbstbetrieb mit Lizenz: Module aus der Lizenz (Lizenz-Server, Spalte `modules`; null = alle)
 *   2. Von uns betriebene Plattform: Option `tix_modules_entitled` (Liste), gesetzt per WP-CLI `wp tixomat modules`
 *   3. Nichts gesetzt = alle Module frei (Bestand: KitchenKlub, Mallorca, tixomat.de, evendis – keine Änderung)
 * Nicht freigeschaltete Module: Ein-Schalter des Moduls liefert „aus“, REST-Routen antworten 403
 * `tix_module_disabled`, Veranstalter-Module (Mehr-Veranstalter-Modus) werden ausgeblendet.
 * `GET /info` → `modules` {key: {label, allowed}} + `modules_source` all|platform|license.
 */
class TIX_Modules {

    const OPTION = 'tix_modules_entitled';

    /** key => [Bezeichnung, REST-Muster (ohne Namespace)] */
    const MODULES = [
        'loyalty'     => ['Prämien (Stempelkarte)', '#^/loyalty(/|$)#'],
        'music'       => ['Musikwunsch + DJ', '#^/music(/|$)#'],
        'giftcards'   => ['Gutscheine', '#^/giftcards(/|$)#'],
        'muttizettel' => ['Muttizettel', null],
        'seating'     => ['Saalplan / Tische', '#^/(customer/seats|customer/table-reservations|public/events/\d+/(seatmap|tables))(/|$)#'],
        'tips'        => ['Tipps / Inhalte', '#^/(app|public)/tips(/|$)#'],
    ];

    public static function init() {
        add_filter('rest_pre_dispatch', [__CLASS__, 'guard_rest'], 5, 3);
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
            if (array_key_exists('modules', $s)) return is_array($s['modules']) ? array_values(array_intersect(self::keys(), $s['modules'])) : null;
        }
        $opt = get_option(self::OPTION, null);
        return is_array($opt) ? array_values(array_intersect(self::keys(), $opt)) : null;
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
        foreach (self::MODULES as $key => $m) {
            if ($m[1] && preg_match($m[1], $rel) && !self::allowed($key)) {
                return new WP_Error('tix_module_disabled', 'Dieses Modul ist für diese Plattform nicht freigeschaltet.', ['status' => 403, 'module' => $key]);
            }
        }
        return $result;
    }

    public static function info_payload() {
        $out = [];
        foreach (self::MODULES as $key => $m) $out[$key] = ['label' => $m[0], 'allowed' => self::allowed($key)];
        return $out;
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
     * : Bei „set“: Komma-Liste, z. B. loyalty,music (leer = keine)
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
