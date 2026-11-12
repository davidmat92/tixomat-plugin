<?php
/**
 * Geteilte Events: Inhalte gehören dem Ersteller (1.38.787, Fund 12.11.: auf evendis „Zeiten öffentlich“ am Spiegel #6453 ausgeschaltet –
 * nur der Spiegel war aus, KK #1171 und die KMT-Kopie zeigten weiter Zeiten, die nächste Verteilung hätte es zurückgesetzt).
 *
 * Regel (User: „bei geteilten Events immer synchron“): Auf einem Spiegel (`_tix_syndicated`, evendis) bzw. einer Kopie auf einer Kunden-Seite
 * (`_tix_platform_co`) werden Inhalte nicht lokal geändert. Schreibende Event-Routen geben `409 tix_source_owned` mit der Seite des Erstellers;
 * Co-Veranstalter mit Zugriff ändern über den Durchgriff ohnehin direkt an der Quelle, die dann an alle Kopien verteilt.
 * Erlaubt bleiben plattform-eigene Angaben (Sparte, Hervorheben) und Verwaltung (Co-Veranstalter, Papierkorb).
 * Der Editor liefert `source_owned` {site, reason}, damit Studio/App die Felder sperren und „wird beim Ersteller geändert“ zeigen.
 */
if (!defined('ABSPATH')) exit;

class TIX_Source_Lock {

    /** Felder in POST /events/{id}, die auf Spiegel/Kopie lokal bleiben dürfen */
    const LOCAL_KEYS = ['event_category', 'category', 'promoted', 'featured'];
    /** Unter-Routen, die auf Spiegel/Kopie lokal bleiben (Verwaltung, nicht Inhalt; Verlauf meldet selbst tix_history_copy) */
    const LOCAL_SUB = '#^(co-organizer(/.*)?|co-money(/.*)?|archive|restore|guard|history(/\d+(/restore)?)?)$#';

    public static function init() {
        add_filter('rest_pre_dispatch', [__CLASS__, 'guard'], 7, 3);
    }

    /** Spiegel/Kopie? → ['site' => Name der Quelle] oder null */
    public static function source_of($event_id) {
        $event_id = intval($event_id);
        if (!$event_id || get_post_type($event_id) !== 'event') return null;
        if (get_post_meta($event_id, '_tix_syndicated', true) === '1') {
            return ['site' => (string) (get_post_meta($event_id, '_tix_source_site', true) ?: 'der Partnerseite'), 'url' => (string) get_post_meta($event_id, '_tix_source_url', true)];
        }
        if ((string) get_post_meta($event_id, '_tix_platform_co', true) !== '') {
            return ['site' => (string) (get_post_meta($event_id, '_tix_platform_co_source', true) ?: get_post_meta($event_id, '_tix_platform_co_by', true) ?: 'dem Ersteller'), 'url' => ''];
        }
        return null;
    }

    public static function message($site) {
        return 'Dieses Event gehört ' . $site . '. Inhalte (Texte, Termin, Tickets, Line-up, Programm, Bilder) werden dort geändert und automatisch an alle Seiten verteilt.';
    }

    /** Für den Editor: `source_owned` {site, reason} oder null */
    public static function editor_field($event_id) {
        $s = self::source_of($event_id);
        return $s ? ['site' => $s['site'], 'reason' => self::message($s['site'])] : null;
    }

    public static function guard($result, $server, $request) {
        if ($result !== null) return $result;
        $method = $request->get_method();
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return $result;
        if (!preg_match('#^/tixomat/v1/(?:studio/)?events/(\d+)(?:/(.+))?$#', $request->get_route(), $m)) return $result;
        $src = self::source_of($m[1]);
        if (!$src) return $result;
        $sub = $m[2] ?? '';
        if ($sub !== '' && preg_match(self::LOCAL_SUB, $sub)) return $result;
        if ($sub === '' && $method === 'DELETE') return $result; // vom Spiegel nehmen (Papierkorb) bleibt lokal
        if ($sub === '' && $method === 'POST') {
            $b = $request->get_json_params();
            if (!is_array($b)) $b = (array) $request->get_body_params();
            $keys = array_diff(array_keys($b), self::LOCAL_KEYS);
            if (!$keys) return $result;
            return new WP_Error('tix_source_owned', self::message($src['site']), ['status' => 409, 'source_site' => $src['site'], 'fields' => array_values($keys)]);
        }
        return new WP_Error('tix_source_owned', self::message($src['site']), ['status' => 409, 'source_site' => $src['site']]);
    }
}
