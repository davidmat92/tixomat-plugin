<?php
/**
 * Soll-Einstellungen für die Wache des Kunden-Dienstes (Phase 0, 1.38.767).
 * Vertrag: uebergaben/vertrag-soll-einstellungen.md.
 *
 * Der Kunden-Dienst (`kd-run wache`, alle 15 min) führt die Soll-Liste je Instanz.
 * Das Plugin liefert die Ist-Werte, vergleicht sie mit einer übergebenen Soll-Liste
 * und setzt nur Werte aus einer festen Liste. Lesen ist immer ohne Nebenwirkung.
 *
 *   wp tixomat soll ist
 *   wp tixomat soll pruefen --soll='{"timezone_string":"Europe/Berlin","multi":false}'
 *   wp tixomat soll setzen <schluessel> <wert>
 *
 * Ausgabe immer eine Zeile `ERGEBNIS <json>`; Fehler `Error: <code> …` mit Exit-Code 1.
 * Läuft auf jeder Installation (evendis wie Kundenserver), ohne Netz und ohne evendis.
 */
if (!defined('ABSPATH')) exit;

class TIX_Soll {

    public static function init() {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat soll', [__CLASS__, 'cli']);
    }

    /**
     * Alle bekannten Werte. Jeder Eintrag: [Ist-Wert, setzbar].
     * Bei den Schlüsseln geht es um Bedeutung, nicht um den WP-Optionsnamen.
     */
    public static function values() {
        $cron_next = self::cron_next_due();
        $mail = class_exists('TIX_Mail_Transport') ? (array) TIX_Mail_Transport::stored() : [];
        $mig = class_exists('TIX_Migrations') ? TIX_Migrations::status() : null;
        $stock = class_exists('TIX_Stock') ? TIX_Stock::health() : null;
        $od = class_exists('TIX_Object_Data') ? TIX_Object_Data::health() : null;
        return [
            // setzbar
            'timezone_string'     => [(string) get_option('timezone_string', ''), true],
            'multi'               => [get_option('tix_multi_organizer', '0') === '1', true],
            'blog_public'         => [(string) get_option('blog_public', '1') === '1', true],
            // nur lesen (Server-Konfiguration oder geheime Zugänge)
            'display_errors'      => [self::ini_on('display_errors'), false],
            'wp_debug_display'    => [defined('WP_DEBUG') && WP_DEBUG && (!defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY), false],
            'disable_wp_cron'     => [defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, false],
            'cron_rueckstand_min' => [$cron_next === null ? 0 : max(0, intval(floor((time() - $cron_next) / 60))), false],
            'mail_mode'           => [(string) ($mail['mode'] ?? 'wp'), false],
            'mail_from'           => [(string) apply_filters('wp_mail_from', (string) get_option('admin_email')), false],
            'php_version'         => [PHP_VERSION, false],
            'wp_version'          => [(string) get_bloginfo('version'), false],
            'plugin_version'      => [TIXOMAT_VERSION, false],
            'db_version'          => [(string) get_option('tix_db_version', '0'), false],
            'migration_offen'     => [$mig ? (bool) $mig['pending'] : null, false],
            'migration_ok'        => [$mig && $mig['last'] ? (bool) $mig['last']['ok'] : null, false],
            'bestand_tabelle'     => [$stock ? (bool) $stock['table'] : false, false],
            'bestand_abweichungen'=> [$stock && !empty($stock['table']) ? count($stock['mismatch']) : null, false],
            'objekt_tabelle'      => [$od ? (bool) $od['table'] : false, false],
            'objekt_optionen_alt' => [$od ? intval($od['legacy']) : null, false],
            'plugin_ordner_link'  => [is_link(untrailingslashit(WP_PLUGIN_DIR) . '/' . dirname(plugin_basename(TIXOMAT_PATH . 'tixomat.php'))), false],
            'plugin_ordner_echt'  => [(string) realpath(TIXOMAT_PATH), false],
            'plugin_verwaltet'    => [class_exists('TIX_License') && method_exists('TIX_License', 'managed_deploy') ? TIX_License::managed_deploy() : false, false],
            'opcache'             => [function_exists('opcache_get_status') && (bool) ini_get('opcache.enable'), false],
            'lizenz_modus'        => [(string) get_option('tix_license_mode', ''), false],
            'home_host'           => [strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)), false],
        ];
    }

    private static function ini_on($k) {
        $v = strtolower(trim((string) ini_get($k)));
        return in_array($v, ['1', 'on', 'yes', 'true', 'stdout', 'stderr'], true);
    }

    /** Ältester fälliger Cron-Zeitpunkt (Unix) oder null. */
    private static function cron_next_due() {
        $c = function_exists('_get_cron_array') ? _get_cron_array() : [];
        if (!is_array($c) || !$c) return null;
        $first = min(array_map('intval', array_keys($c)));
        return $first <= time() ? $first : null;
    }

    public static function ist() {
        $out = [];
        foreach (self::values() as $k => [$v, $set]) $out[$k] = ['ist' => $v, 'setzbar' => $set];
        return ['plugin' => TIXOMAT_VERSION, 'werte' => $out];
    }

    /** Vergleich mit einer Soll-Liste {schluessel: wert}. Unbekannte Schlüssel kommen nach `unbekannt`. */
    public static function pruefen(array $soll) {
        $vals = self::values();
        $ab = []; $unk = [];
        foreach ($soll as $k => $want) {
            if (!array_key_exists($k, $vals)) { $unk[] = (string) $k; continue; }
            [$is, $set] = $vals[$k];
            if (!self::same($is, $want)) $ab[] = ['schluessel' => $k, 'ist' => $is, 'soll' => $want, 'setzbar' => $set];
        }
        return ['ok' => !$ab, 'abweichungen' => $ab, 'unbekannt' => $unk];
    }

    private static function same($a, $b) {
        if (is_bool($a) || is_bool($b)) return self::to_bool($a) === self::to_bool($b);
        if (is_int($a) && is_numeric($b)) return $a === intval($b);
        return (string) $a === (string) $b;
    }

    private static function to_bool($v) {
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes', 'ja'], true);
    }

    /** Setzt einen erlaubten Wert. Rückgabe: [ok, schluessel, alt, neu] oder WP_Error. */
    public static function setzen($key, $value) {
        $vals = self::values();
        if (!array_key_exists($key, $vals)) return new WP_Error('tix_not_found', 'Unbekannter Schlüssel: ' . $key);
        if (!$vals[$key][1]) return new WP_Error('tix_not_settable', 'Nicht vom Plugin setzbar (Server-Konfiguration): ' . $key);
        $old = $vals[$key][0];
        switch ($key) {
            case 'timezone_string':
                $value = (string) $value;
                if (!in_array($value, timezone_identifiers_list(), true)) return new WP_Error('tix_invalid_field', 'Unbekannte Zeitzone: ' . $value);
                update_option('timezone_string', $value);
                update_option('gmt_offset', '');
                break;
            case 'multi':
                update_option('tix_multi_organizer', self::to_bool($value) ? '1' : '0');
                break;
            case 'blog_public':
                update_option('blog_public', self::to_bool($value) ? '1' : '0');
                break;
        }
        $new = self::values()[$key][0];
        if (class_exists('TIX_Customers') && method_exists('TIX_Customers', 'audit')) {
            TIX_Customers::audit('soll_setzen', $key, ['scope' => 'site', 'oid' => 0], ['key' => $key, 'old' => $old, 'new' => $new, 'by' => 'kunden-dienst']);
        }
        return ['ok' => true, 'schluessel' => $key, 'alt' => $old, 'neu' => $new];
    }

    /**
     * Soll-Einstellungen für die Wache.
     *
     * ## OPTIONS
     *
     * <aktion>
     * : ist | pruefen | setzen
     *
     * [<schluessel>]
     * : Bei setzen: Schlüssel aus `ist` mit setzbar = true.
     *
     * [<wert>]
     * : Bei setzen: neuer Wert.
     *
     * [--soll=<json>]
     * : Bei pruefen: Soll-Liste als JSON-Objekt.
     */
    public static function cli($args, $assoc) {
        $a = $args[0] ?? 'ist';
        $out = null;
        if ($a === 'ist') $out = self::ist();
        elseif ($a === 'pruefen') {
            $soll = json_decode((string) ($assoc['soll'] ?? ''), true);
            if (!is_array($soll)) WP_CLI::error('tix_invalid_field --soll muss ein JSON-Objekt sein');
            $out = self::pruefen($soll);
        } elseif ($a === 'setzen') {
            if (!isset($args[1], $args[2])) WP_CLI::error('tix_invalid_field setzen <schluessel> <wert>');
            $out = self::setzen((string) $args[1], (string) $args[2]);
            if (is_wp_error($out)) WP_CLI::error($out->get_error_code() . ' ' . $out->get_error_message());
        } else {
            WP_CLI::error('tix_invalid_field Aktion: ist | pruefen | setzen');
        }
        WP_CLI::line('ERGEBNIS ' . wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
