<?php
/**
 * TIX_Shadow – Schatten-Kennung (WordPress-Ablösung Phase 1, Paket 1b, Vertrag V3; ab 1.38.808).
 *
 * Der neue Dienst „evendis-api“ holt im Schattenbetrieb dieselbe Anfrage zum Vergleich bei WordPress
 * (WP_INTERNAL_URL) mit `X-Evendis-Shadow-Fetch: 1`. Solche Abrufe
 *  - zählen nicht in Statistik, Web-Trichter, Kampagnen-Aufrufen, Kurzlink-, Sponsor- und Angebots-Klicks,
 *  - zählen nicht in Ratenlimits (und werden von ihnen nicht gebremst),
 *  - lösen keine Mails aus (pre_wp_mail → true: „erledigt“ ohne Versand, damit sich die Antwort nicht ändert)
 *    und keine Pushes (FCM, APNs, Wallet-Updates, Pushover),
 *  - schreiben keine Aufruf-Zähler bzw. „zuletzt angesehen“ (Angebots-Aufrufe, Kampagnen, Klicks).
 * Antwort und Cache bleiben unverändert.
 * Doppelte Sicherung (Nachtrag Strategie, S1): Eine Anfrage mit `X-Evendis-Shadow-Fetch: 1` und nicht lesender Methode
 * (alles außer GET/HEAD/OPTIONS) wird immer mit 403 `tix_shadow_readonly` abgelehnt – auch ohne Schlüssel.
 *
 * Schutz: Die Kennung gilt nur zusammen mit `X-Evendis-Shadow-Key: <Schlüssel>`. Der Schlüssel steht in wp-config
 * (EVENDIS_SHADOW_KEY) oder in der Option `tix_shadow_key` (mindestens 32 Zeichen) und im Dienst. Ohne eingerichteten
 * Schlüssel wird die Kennung ignoriert – sonst könnte jeder mit dem Kopf die Ratenlimits umgehen.
 */
if (!defined('ABSPATH')) exit;

class TIX_Shadow {

    private static $v = null;

    public static function init() {
        add_filter('pre_wp_mail', [__CLASS__, 'block_mail'], 0, 2);
        add_filter('rest_pre_dispatch', [__CLASS__, 'rest_guard'], -1000, 3);
        add_action('plugins_loaded', [__CLASS__, 'guard_request'], 0);
    }

    /** Kennung gesetzt (mit oder ohne Schlüssel)? */
    public static function flagged() { return (string) ($_SERVER['HTTP_X_EVENDIS_SHADOW_FETCH'] ?? '') === '1'; }

    private static function unsafe($method) { return !in_array(strtoupper((string) $method), ['GET', 'HEAD', 'OPTIONS'], true); }

    /** REST: schreibende Methode mit Kennung → 403 (vor jeder anderen Prüfung, auch vor Sperren und Durchgriff) */
    public static function rest_guard($result, $server, $request) {
        if (!self::flagged() || !self::unsafe($request->get_method())) return $result;
        return new WP_Error('tix_shadow_readonly', 'Schatten-Abrufe dürfen nur lesen.', ['status' => 403]);
    }

    /** Alles außerhalb von REST (admin-ajax, admin-post, Formulare): schreibende Methode mit Kennung → 403 */
    public static function guard_request() {
        if (!self::flagged() || !self::unsafe($_SERVER['REQUEST_METHOD'] ?? 'GET')) return;
        if (defined('WP_CLI') && WP_CLI) return;
        status_header(403);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode(['code' => 'tix_shadow_readonly', 'message' => 'Schatten-Abrufe dürfen nur lesen.', 'data' => ['status' => 403]]);
        exit;
    }

    public static function key() {
        $k = defined('EVENDIS_SHADOW_KEY') ? (string) EVENDIS_SHADOW_KEY : (string) get_option('tix_shadow_key', '');
        return strlen($k) >= 32 ? $k : '';
    }

    /** Ist diese Anfrage ein Schatten-Abruf des Dienstes? */
    public static function active() {
        if (self::$v !== null) return self::$v;
        if ((string) ($_SERVER['HTTP_X_EVENDIS_SHADOW_FETCH'] ?? '') !== '1') return self::$v = false;
        $key = self::key();
        $got = (string) ($_SERVER['HTTP_X_EVENDIS_SHADOW_KEY'] ?? '');
        return self::$v = ($key !== '' && $got !== '' && hash_equals($key, $got));
    }

    /** Für Tests: Kennung neu bestimmen */
    public static function reset() { self::$v = null; }

    public static function block_mail($return, $atts = []) {
        return self::active() ? true : $return;
    }
}

TIX_Shadow::init();
