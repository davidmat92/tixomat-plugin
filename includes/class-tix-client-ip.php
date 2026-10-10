<?php
/**
 * Besucher-IP für Rate-Limits, Statistik und Meta Conversions-API.
 *
 * Kopfzeilen eines Proxys (`X-Forwarded-For`, `X-Real-IP`, `CF-Connecting-IP`) zählen nur,
 * wenn die Anfrage von einem vertrauenswürdigen Proxy kommt (`REMOTE_ADDR` in der Option
 * `tix_trusted_proxies`; Vorgabe: 127.0.0.1, ::1 und die eigene Server-IP). Sonst gilt
 * `REMOTE_ADDR` – gefälschte Kopfzeilen eines Besuchers bleiben wirkungslos.
 *
 * Beispiel evendis.de: Die Next.js-Website läuft auf demselben Server und ruft die
 * Schnittstelle mit `X-Forwarded-For: <Besucher-IP>` auf (OpenLiteSpeed reicht die
 * Kopfzeile unverändert durch, „Use Client IP in Header“ ist aus).
 *
 * Option `tix_trusted_proxies`: Liste von IPs bzw. CIDR-Bereichen (Array oder Text mit
 * Komma/Zeilenumbruch), z. B. `wp option update tix_trusted_proxies '["127.0.0.1","10.0.0.0/8"]' --format=json`.
 */
if (!defined('ABSPATH')) exit;

class TIX_Client_IP {

    const OPTION = 'tix_trusted_proxies';

    /** Vertrauenswürdige Proxys (IPs/CIDR). */
    public static function trusted_proxies() {
        $raw = get_option(self::OPTION, null);
        if ($raw === null || $raw === false || $raw === '' || $raw === []) {
            $list = ['127.0.0.1', '::1'];
            $own = isset($_SERVER['SERVER_ADDR']) ? trim((string) $_SERVER['SERVER_ADDR']) : '';
            if ($own !== '' && filter_var($own, FILTER_VALIDATE_IP)) $list[] = $own;
        } else {
            $list = is_array($raw) ? $raw : preg_split('/[\s,]+/', (string) $raw);
        }
        $list = array_values(array_filter(array_map('trim', array_map('strval', (array) $list))));
        return (array) apply_filters('tix_trusted_proxies', $list);
    }

    /** Liegt $ip in einer der Angaben (IP oder CIDR, IPv4/IPv6)? */
    public static function in_list($ip, array $list) {
        $bin = @inet_pton($ip);
        if ($bin === false) return false;
        foreach ($list as $entry) {
            if (strpos($entry, '/') === false) {
                $e = @inet_pton($entry);
                if ($e !== false && $e === $bin) return true;
                continue;
            }
            list($net, $bits) = explode('/', $entry, 2);
            $nb = @inet_pton($net);
            $bits = intval($bits);
            if ($nb === false || strlen($nb) !== strlen($bin) || $bits < 0 || $bits > strlen($bin) * 8) continue;
            $bytes = intdiv($bits, 8);
            $rest  = $bits % 8;
            if ($bytes && substr($bin, 0, $bytes) !== substr($nb, 0, $bytes)) continue;
            if ($rest) {
                $mask = chr((0xFF << (8 - $rest)) & 0xFF);
                if ((substr($bin, $bytes, 1) & $mask) !== (substr($nb, $bytes, 1) & $mask)) continue;
            }
            return true;
        }
        return false;
    }

    /** Besucher-IP ('' wenn unbekannt). */
    public static function get() {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        if (!filter_var($remote, FILTER_VALIDATE_IP)) return '';
        $trusted = self::trusted_proxies();
        if (!self::in_list($remote, $trusted)) return $remote;

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $h) {
            $v = isset($_SERVER[$h]) ? trim((string) $_SERVER[$h]) : '';
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP)) return $v;
        }
        // X-Forwarded-For von rechts lesen: erste Adresse, die kein eigener Proxy ist
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $hops = array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));
            foreach ($hops as $hop) {
                if (!filter_var($hop, FILTER_VALIDATE_IP)) break;
                if (!self::in_list($hop, $trusted)) return $hop;
            }
        }
        return $remote;
    }
}
