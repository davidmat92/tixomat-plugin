<?php
/**
 * Geteilte Events überall änderbar – Änderungen gehen an den Ersteller (1.38.788; ersetzt die Sperre aus 1.38.787, User 13.11.:
 * „Es soll sich überall ändern lassen, also auch beim Co-Veranstalter und auch über den evendis-Betreiber.“).
 *
 * Fund 12.11.: Auf evendis wurde am Spiegel #6453 „Zeiten öffentlich“ ausgeschaltet – nur lokal; KK #1171 und die KMT-Kopie blieben an.
 *
 * Schreibende Event-Routen auf einem Spiegel (evendis, `_tix_syndicated`) bzw. einer Kopie auf einer Kunden-Seite (`_tix_platform_co`)
 * werden an die Quelle weitergereicht und von dort an alle Kopien verteilt (Verteilung, Abgleich ~20 s):
 *  - evendis-Spiegel, Admin (Betreiber) → `/partner/source/{id}` der Lizenzseite (Partner-Zugang; Seite kann es abschalten).
 *  - evendis-Spiegel, Co-Veranstalter mit Zugriff → bestehender Co-Durchgriff `/partner/co/{id}`.
 *  - Kopie auf Kunden-Seite mit Zugriff → `/partner/co-events/{id}/call` der Plattform (diese ggf. weiter an die Lizenzseite).
 * Person und Seite reisen im `actor` mit (Event-Verlauf an der Quelle). Verkaufsrelevante Regeln prüft die Quelle wie bei eigenen Änderungen.
 * Gleichzeitig bearbeiten: ab 1.38.789 Bearbeitungssperre an der Quelle (TIX_Edit_Lock), Token reist im actor mit. Ohne Recht bzw. bei Datei-Uploads: 403/409 mit Hinweis.
 * Lokal bleiben plattform-eigene Angaben (Sparte, Hervorheben) und Verwaltung (Co, Archiv, Papierkorb, Verlauf).
 */
if (!defined('ABSPATH')) exit;

class TIX_Source_Lock {

    const LOCAL_KEYS = ['event_category', 'category', 'promoted', 'featured'];
    const LOCAL_SUB = '#^(co-organizer(/.*)?|co-money(/.*)?|archive|restore|guard|history(/\d+(/restore)?)?)$#';

    private static $forwarding = 0;

    public static function init() {
        add_filter('rest_pre_dispatch', [__CLASS__, 'guard'], 7, 3);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    /** Spiegel/Kopie? → ['type' => mirror|copy, 'site' => Name der Quelle, …] oder null */
    public static function source_of($event_id) {
        $event_id = intval($event_id);
        if (!$event_id || get_post_type($event_id) !== 'event') return null;
        if (get_post_meta($event_id, '_tix_syndicated', true) === '1') {
            return ['type' => 'mirror', 'site' => (string) (get_post_meta($event_id, '_tix_source_site', true) ?: 'der Partnerseite'), 'sid' => intval(get_post_meta($event_id, '_tix_source_id', true))];
        }
        if ((string) get_post_meta($event_id, '_tix_platform_co', true) !== '') {
            return ['type' => 'copy', 'site' => (string) (get_post_meta($event_id, '_tix_platform_co_source', true) ?: get_post_meta($event_id, '_tix_platform_co_by', true) ?: 'dem Ersteller'),
                'pid' => intval(get_post_meta($event_id, '_tix_platform_co', true)), 'access' => get_post_meta($event_id, '_tix_platform_co_access', true) === '1'];
        }
        return null;
    }

    public static function message($site) {
        return 'Dieses Event gehört ' . $site . '. Änderungen werden dort gespeichert und an alle Seiten verteilt.';
    }

    /** Für den Editor: `source_owned` {site, reason, editable, via} oder null */
    public static function editor_field($event_id) {
        $s = self::source_of($event_id);
        if (!$s) return null;
        $via = self::route_for($event_id, $s);
        return ['site' => $s['site'], 'reason' => self::message($s['site']), 'editable' => !is_wp_error($via), 'via' => is_wp_error($via) ? null : $via,
            'blocked_reason' => is_wp_error($via) ? $via->get_error_message() : null];
    }

    /** Weg zur Quelle für den aktuellen Nutzer: operator|co|platform oder WP_Error */
    private static function route_for($event_id, array $s) {
        if ($s['type'] === 'copy') {
            if (!$s['pid']) return new WP_Error('tix_source_owned', self::message($s['site']), ['status' => 409]);
            return $s['access'] ? 'platform' : new WP_Error('tix_co_no_access', 'Der Ersteller hat den Zugriff auf dieses Event ausgeschaltet – Änderungen nur bei ' . $s['site'] . '.', ['status' => 403]);
        }
        if (!self::multi() || !$s['sid'] || !(string) get_post_meta($event_id, '_tix_source_partner', true)) return new WP_Error('tix_source_owned', self::message($s['site']), ['status' => 409]);
        if (current_user_can('manage_options')) return 'operator';
        $oid = class_exists('TIX_App_Scope') ? intval(TIX_App_Scope::organizer_id_for_user()) : 0;
        if ($oid && class_exists('TIX_Co_Remote') && TIX_Co_Remote::mirror_has_access($event_id, $oid)) return 'co';
        return new WP_Error('tix_co_no_access', 'Änderungen an diesem geteilten Event macht ' . $s['site'] . ' bzw. ein Co-Veranstalter mit Zugriff.', ['status' => 403]);
    }

    public static function guard($result, $server, $request) {
        if ($result !== null || self::$forwarding) return $result;
        $method = $request->get_method();
        if (!preg_match('#^/tixomat/v1/(?:(studio)/)?events/(\d+)(?:/(.+))?$#', $request->get_route(), $m)) return $result;
        $sub = $m[3] ?? '';
        $is_lock = (bool) preg_match('#^lock(/release|/break|/heartbeat)?$#', $sub); // Bearbeitungssperre gilt an der Quelle (1.38.789)
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !($is_lock && $method === 'GET')) return $result;
        $eid = intval($m[2]);
        $src = self::source_of($eid);
        if (!$src) return $result;
        if ($is_lock && $method === 'DELETE') { $method = 'POST'; $sub = 'lock/release'; }
        if ($sub === 'sponsors' && $method === 'PUT') $method = 'POST'; // Durchgriff kennt nur GET/POST/DELETE (Route nimmt beides, 1.38.793)
        if ($sub !== '' && preg_match(self::LOCAL_SUB, $sub)) return $result;
        if ($sub === '' && $method === 'DELETE') return $result; // vom Angebot nehmen bleibt lokal
        $body = $request->get_json_params();
        if (!is_array($body)) $body = (array) $request->get_body_params();
        if ($is_lock && class_exists('TIX_Edit_Lock')) { $tk = TIX_Edit_Lock::token_of($request); $body = $tk !== '' ? ['token' => $tk] : []; } // auch sendBeacon (text/plain)
        if ($sub === '' && $method === 'POST' && !array_diff(array_keys($body), self::LOCAL_KEYS)) return $result;
        // Sponsoren (1.38.793): hier hochgeladene Logos kennt die Quelle nicht – als Adresse mitgeben
        if ($sub === 'sponsors') foreach (['main', 'partners'] as $tk) foreach ((array) ($body[$tk] ?? []) as $i => $sp) foreach (['logo', 'logo_dark'] as $lk) {
            $lid = is_array($sp[$lk] ?? null) ? intval($sp[$lk]['id'] ?? 0) : (is_numeric($sp[$lk] ?? null) ? intval($sp[$lk]) : 0);
            if ($lid && get_post_type($lid) === 'attachment') $body[$tk][$i][$lk] = ['url' => (string) (wp_get_attachment_image_url($lid, 'large') ?: wp_get_attachment_url($lid))];
        }
        if ($request->get_file_params()) return new WP_Error('tix_source_owned', 'Bilder für dieses geteilte Event bitte bei ' . $src['site'] . ' hochladen.', ['status' => 409, 'source_site' => $src['site']]);
        $via = self::route_for($eid, $src);
        if (is_wp_error($via)) { $d = (array) $via->get_error_data(); return new WP_Error($via->get_error_code(), $via->get_error_message(), $d + ['source_site' => $src['site']]); }
        $q = $request->get_query_params();
        $u = wp_get_current_user();
        $actor = ['name' => (string) $u->display_name, 'email' => (string) $u->user_email] + (class_exists('TIX_Co_Remote') && method_exists('TIX_Co_Remote', 'history_actor') ? TIX_Co_Remote::history_actor() : []);
        $lock = class_exists('TIX_Edit_Lock') ? TIX_Edit_Lock::request_token($request) : (string) $request->get_header('x_tix_edit_lock');
        if ($lock !== '') $actor['lock'] = $lock;
        $target = $via === 'platform' ? $src['pid'] : $src['sid'];
        $path = '/' . ($m[1] ? 'studio/' : '') . 'events/' . $target . ($sub !== '' ? '/' . $sub : '');
        foreach (['event_id'] as $k) { if (intval($q[$k] ?? 0) === $eid) $q[$k] = $target; if (intval($body[$k] ?? 0) === $eid) $body[$k] = $target; }
        self::$forwarding++;
        try {
            if ($via === 'operator') $env = TIX_Co_Remote::forward_source($eid, $method, $path, $q, $body, $actor);
            elseif ($via === 'co') $env = TIX_Co_Remote::forward($eid, $method, $path, $q, $body, $actor + ['organizer_id' => intval(TIX_App_Scope::organizer_id_for_user())]);
            else $env = TIX_Site_Move::co_call($src['pid'], $method, $path, $q, $body, $lock !== '' ? ['lock' => $lock] : []);
        } finally {
            self::$forwarding--;
        }
        if (is_wp_error($env)) return $env;
        $data = $env['data'] ?? null;
        if (class_exists('TIX_Site_Move')) {
            $data = TIX_Site_Move::source_ids_back($data, $target, $eid, $sub === '' || $sub === 'edit');
            if ($via === 'platform') $data = TIX_Site_Move::local_urls_back($data, $eid);
        }
        $res = new WP_REST_Response($data, intval($env['status'] ?? 200));
        $res->header('X-Tix-Forwarded', $via);
        return $res;
    }
}
