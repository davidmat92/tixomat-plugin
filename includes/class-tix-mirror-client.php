<?php
/**
 * Tixomat – Datenspiegel: Supabase-Client (PostgREST)
 *
 * Schickt gebündelte Upserts, Löschungen und RPC-Aufrufe an das Spiegel-Projekt.
 * Der Schlüssel steht nur in wp-config (TIX_MIRROR_KEY) bzw. in der Option
 * tix_mirror und taucht nie in Fehlermeldungen oder Logs auf.
 *
 * @since Datenspiegel Paket 1 (Version vergibt die Zentrale)
 */

if (!defined('ABSPATH')) exit;

class TIX_Mirror_Client {

    const TIMEOUT = 15;

    /** Projekt-URL ohne Schrägstrich am Ende ('' = nicht eingerichtet). */
    public static function url() {
        $u = defined('TIX_MIRROR_URL') ? (string) TIX_MIRROR_URL : (string) (TIX_Mirror::settings()['url'] ?? '');
        return rtrim(trim($u), '/');
    }

    /** Service-Schlüssel (nie ausgeben). */
    private static function key() {
        return defined('TIX_MIRROR_KEY') ? (string) TIX_MIRROR_KEY : (string) (TIX_Mirror::settings()['key'] ?? '');
    }

    public static function configured() {
        return self::url() !== '' && self::key() !== '';
    }

    /** Woher die Zugangsdaten kommen: 'config' (wp-config), 'option' oder ''. */
    public static function key_source() {
        if (defined('TIX_MIRROR_KEY') && TIX_MIRROR_KEY !== '') return 'config';
        return (TIX_Mirror::settings()['key'] ?? '') !== '' ? 'option' : '';
    }

    private static function headers(array $extra = []) {
        $key = self::key();
        $h = ['apikey' => $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json'];
        // Alte JWT-Schlüssel (service_role) brauchen zusätzlich Authorization; neue sb_secret_-Schlüssel nicht.
        if (strpos($key, 'sb_') !== 0) $h['Authorization'] = 'Bearer ' . $key;
        return array_merge($h, $extra);
    }

    /**
     * Ein Aufruf. Liefert ['ok' => bool, 'code' => int, 'body' => mixed, 'error' => string].
     */
    public static function request($method, $path, $body = null, array $headers = []) {
        if (!self::configured()) return ['ok' => false, 'code' => 0, 'body' => null, 'error' => 'Spiegel nicht eingerichtet'];
        $args = [
            'method'      => $method,
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
            'headers'     => self::headers($headers),
        ];
        if ($body !== null) $args['body'] = wp_json_encode($body);
        $res = wp_remote_request(self::url() . $path, $args);
        if (is_wp_error($res)) {
            return ['ok' => false, 'code' => 0, 'body' => null, 'error' => self::scrub($res->get_error_message())];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $raw  = (string) wp_remote_retrieve_body($res);
        $json = $raw === '' ? null : json_decode($raw, true);
        if ($code >= 200 && $code < 300) return ['ok' => true, 'code' => $code, 'body' => $json, 'error' => ''];
        $msg = 'HTTP ' . $code;
        if (is_array($json)) {
            foreach (['message', 'hint', 'details', 'code'] as $f) {
                if (!empty($json[$f]) && is_string($json[$f])) $msg .= ' · ' . $json[$f];
            }
        } elseif ($raw !== '') {
            $msg .= ' · ' . substr(wp_strip_all_tags($raw), 0, 200);
        }
        return ['ok' => false, 'code' => $code, 'body' => $json, 'error' => self::scrub($msg)];
    }

    /** Upsert vieler Zeilen über (site, id). */
    public static function upsert($table, array $rows, $on_conflict = 'site,id') {
        if (!$rows) return ['ok' => true, 'code' => 204, 'body' => null, 'error' => ''];
        return self::request('POST', '/rest/v1/' . rawurlencode($table) . '?on_conflict=' . rawurlencode($on_conflict), array_values($rows), [
            'Prefer' => 'resolution=merge-duplicates,return=minimal',
        ]);
    }

    /** Löschen mit PostgREST-Filtern, z. B. ['site' => 'eq.x', 'id' => 'in.(1,2)']. */
    public static function delete($table, array $filters) {
        if (!$filters) return ['ok' => false, 'code' => 0, 'body' => null, 'error' => 'Löschen ohne Filter verweigert'];
        return self::request('DELETE', '/rest/v1/' . rawurlencode($table) . '?' . self::query($filters), null, ['Prefer' => 'return=minimal']);
    }

    /** Gespeicherte Funktion aufrufen. */
    public static function rpc($fn, array $args) {
        return self::request('POST', '/rest/v1/rpc/' . rawurlencode($fn), (object) $args);
    }

    /** Lesen (nur für Status und Tests). */
    public static function select($table, array $filters = [], $select = '*') {
        $q = self::query(array_merge(['select' => $select], $filters));
        return self::request('GET', '/rest/v1/' . rawurlencode($table) . '?' . $q);
    }

    /** Verbindung und Schema prüfen (liest die Tabelle sites). */
    public static function test() {
        if (!self::configured()) return ['success' => false, 'message' => 'Projekt-URL und Service-Schlüssel fehlen.'];
        $r = self::select('sites', ['limit' => '1'], 'site');
        if ($r['ok']) return ['success' => true, 'message' => 'Verbindung erfolgreich, Schema vorhanden.'];
        if ($r['code'] === 404 || strpos($r['error'], 'PGRST205') !== false || strpos($r['error'], '42P01') !== false) {
            return ['success' => false, 'message' => 'Verbunden, aber das Spiegel-Schema fehlt (Migrationen aus supabase/migrations einspielen).'];
        }
        return ['success' => false, 'message' => $r['error']];
    }

    private static function query(array $params) {
        $parts = [];
        foreach ($params as $k => $v) $parts[] = rawurlencode($k) . '=' . rawurlencode((string) $v);
        return implode('&', $parts);
    }

    /** Schlüssel aus Texten entfernen, falls ein Server ihn zurückspiegelt. */
    private static function scrub($text) {
        $key = self::key();
        $text = (string) $text;
        if ($key !== '' && strlen($key) > 8) $text = str_replace($key, '***', $text);
        return substr($text, 0, 500);
    }
}
