<?php
if (!defined('ABSPATH')) exit;

/**
 * Co-Veranstalter für Events von Lizenzseiten (1.38.697, Teil B, Vertrag `uebergaben/vertrag-co-veranstalter.md`).
 *
 * Das Event liegt auf der Lizenzseite (z. B. kitchenklub.de) und ist per Event-Verteilung auf evendis.de geteilt.
 * Der Co-Veranstalter arbeitet in studio.evendis.de. Die Daten bleiben auf der Lizenzseite („voller Durchgriff“).
 *
 * Lizenzseite (Quelle, Einzel-Seite):
 *   - Editor wie Teil A, `co_organizer_mode: remote` (nur per E-Mail einladen, keine Suche).
 *   - Einladen/Zugriff/Entfernen meldet sie an evendis.de: POST {syndication_api_url}/syndicate/{remote_id}/co-organizer
 *     (X-Tix-Syndication-Key). Beim Einladen erzeugt sie einen Event-Schlüssel (nur dieses Event), gespeichert als Hash.
 *   - POST /partner/co/{event_id} (Partner-Zugang + X-Tix-Co-Token): führt eine freigegebene Veranstalter-Route NUR für
 *     dieses Event aus (Liste unten), als technisches Konto ohne Rolle (Rechte nur während der Anfrage).
 * evendis.de (Plattform):
 *   - POST /syndicate/{id}/co-organizer (Partner-Schlüssel der Quelle, nur eigene Spiegel-Events): Profil finden/anlegen,
 *     Mail, Meta am Spiegel `_tix_co_remote_oid`, `_tix_co_remote_access`, `_tix_co_remote_token`.
 *   - GET /co-remote/events: gemeinsame Lizenz-Events des eigenen Veranstalters.
 *   - GET|POST|DELETE /co-remote/{id}/{route}: leitet an die Lizenzseite weiter (Event-ID in der Route = ID auf der Lizenzseite).
 */
class TIX_Co_Remote {
    const NS          = 'tixomat/v1';
    const META        = '_tix_co_remote';        // Lizenzseite: {organizer_id, name, logo, slug, email, access, status, since, consent}
    const META_TOKEN  = '_tix_co_token_hash';    // Lizenzseite: sha256 des Event-Schlüssels
    const M_OID       = '_tix_co_remote_oid';    // evendis-Spiegel
    const M_ACCESS    = '_tix_co_remote_access';
    const M_TOKEN     = '_tix_co_remote_token';
    const USER_META   = '_tix_co_remote_user';   // technisches Konto auf der Lizenzseite (evendis-Veranstalter-ID)

    private static $acting = 0;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    /** Lizenzseite: Schalter „Co-Veranstalter über die Plattform erlauben“ (co_remote_enabled, Vorgabe aus). */
    public static function enabled() {
        return !empty(tix_get_settings('co_remote_enabled'));
    }

    // ═══════════════ Lizenzseite ═══════════════

    /** Grund, warum es hier keinen Co-Veranstalter gibt, oder '' (verfügbar). */
    public static function unavailable_reason($event_id) {
        if (self::multi()) return 'platform';
        if (!self::enabled()) return 'disabled';
        if (!class_exists('TIX_Syndication_Push') || !TIX_Syndication_Push::is_configured()) return 'not_connected';
        if (!class_exists('TIX_Partners') || !TIX_Partners::source_api_enabled()) return 'no_partner_api';
        $event_id = intval($event_id);
        if (!$event_id || get_post_meta($event_id, '_tix_syndicate', true) !== '1' || !intval(get_post_meta($event_id, '_tix_syndicate_remote_id', true))) return 'not_shared';
        return '';
    }

    private static function data($event_id) {
        $d = get_post_meta(intval($event_id), self::META, true);
        if (is_string($d) && $d !== '') $d = json_decode($d, true); // von Hand als JSON gesetzt (1.38.709)
        if (is_object($d)) $d = json_decode(wp_json_encode($d), true);
        return is_array($d) && intval($d['organizer_id'] ?? 0) > 0 ? $d : null;
    }

    public static function payload($event_id) {
        $d = self::data($event_id);
        if (!$d) return null;
        return ['id' => intval($d['organizer_id']), 'name' => (string) ($d['name'] ?? ''), 'logo' => (string) ($d['logo'] ?? ''), 'slug' => (string) ($d['slug'] ?? ''),
            'access' => !empty($d['access']), 'status' => (string) ($d['status'] ?? 'active'), 'invited_email' => ($d['status'] ?? '') === 'invited' ? (string) ($d['email'] ?? '') : '',
            'since' => (string) ($d['since'] ?? '') ?: null, 'consent' => !empty($d['consent']['at']) ? $d['consent'] : null, 'remote' => true];
    }

    /** Host der Plattform (evendis.de) aus der Event-Verteilung. */
    private static function platform_host() {
        $h = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url((string) tix_get_settings('syndication_api_url'), PHP_URL_HOST)));
        return $h ?: 'evendis.de';
    }

    /** Eintrag für `co_organizers` (1.38.706) oder null. */
    public static function entry($event_id, $removable) {
        $p = self::payload($event_id);
        if (!$p) return null;
        $d = self::data($event_id);
        $site = !empty($d['site']['host']) ? ['key' => (string) ($d['site']['key'] ?? ''), 'host' => (string) $d['site']['host'], 'name' => (string) ($d['site']['name'] ?? $p['name'])] : null;
        $h = self::platform_host();
        return $p + ($site ? ['kind' => 'site', 'origin' => ['type' => 'site', 'label' => 'eigene Seite ' . $site['host'], 'host' => $site['host']], 'site' => $site]
                           : ['kind' => 'remote', 'origin' => ['type' => 'evendis', 'label' => 'auf ' . $h, 'host' => $h], 'site' => null]) + ['removable' => (bool) $removable];
    }

    private static function is_editor() {
        $u = wp_get_current_user();
        return $u && $u->ID && ($u->has_cap('manage_options') || in_array('tix_organizer', (array) $u->roles, true));
    }

    public static function editor_payload($event_id) {
        if ($event_id && class_exists('TIX_Site_Move') && TIX_Site_Move::platform_co_id($event_id)) return TIX_Site_Move::co_editor_payload($event_id);
        $why = $event_id ? self::unavailable_reason($event_id) : 'not_shared';
        $role = current_user_can('manage_options') ? 'admin' : 'owner';
        $entry = $event_id ? self::entry($event_id, self::is_editor()) : null;
        return ['co_organizer_available' => $why === '', 'co_organizer_mode' => 'remote', 'co_organizer_unavailable_reason' => $why ?: null,
            'co_organizer_editable' => $why === '' && self::is_editor(), 'event_role' => $role, 'co_organizer' => $event_id ? self::payload($event_id) : null,
            'co_organizers' => $entry ? [$entry] : [], 'co_organizer_removable' => $entry !== null && self::is_editor(), 'co_organizer_site_search' => $why === '' && self::is_editor()];
    }

    public static function list_fields($event_id) {
        if (class_exists('TIX_Site_Move') && TIX_Site_Move::platform_co_id($event_id)) return TIX_Site_Move::co_list_fields($event_id);
        $d = self::data($event_id);
        return $d ? ['event_role' => current_user_can('manage_options') ? 'admin' : 'owner', 'shared_with' => ['id' => intval($d['organizer_id']), 'name' => (string) $d['name'], 'logo' => (string) ($d['logo'] ?? '')]] : [];
    }

    public static function public_ref($event_id) {
        if (class_exists('TIX_Site_Move') && TIX_Site_Move::platform_co_id($event_id)) return ['id' => 0, 'slug' => '', 'name' => (string) get_post_meta($event_id, '_tix_platform_co_by', true), 'logo' => ''];
        $d = self::data($event_id);
        return $d ? ['id' => 0, 'slug' => '', 'name' => (string) $d['name'], 'logo' => (string) ($d['logo'] ?? '')] : null;
    }

    /** POST /events/{id} mit `co_organizer` auf der Lizenzseite: Zugriff umschalten oder entfernen (Einladen nur über /invite). */
    public static function check(array $body, $post_id, $is_new) {
        if ($is_new) return new WP_Error('tix_co_remote_invite', 'Den Co-Veranstalter bitte nach dem ersten Speichern per E-Mail einladen.', ['status' => 400, 'field' => 'co_organizer']);
        if (!self::is_editor()) return new WP_Error('tix_co_forbidden', 'Den Co-Veranstalter kann nur der Veranstalter ändern.', ['status' => 403]);
        $v = $body['co_organizer'];
        $d = self::data($post_id);
        $clear = ($v === null || $v === false || $v === '' || $v === 0);
        $why = self::unavailable_reason($post_id);
        if ($clear) { // Entfernen geht immer (auch bei ausgeschaltetem Schalter oder nicht mehr geteiltem Event)
            if (!$d) return null;
            $r = self::remove($post_id, false);
            return is_wp_error($r) ? $r : ['op' => 'remote'];
        }
        if ($why !== '') return new WP_Error('tix_co_remote_unavailable', 'Co-Veranstalter gibt es nur für Events, die auf evendis.de geteilt sind.', ['status' => 409, 'reason' => $why]);
        if (!is_array($v)) return new WP_Error('tix_invalid_field', 'co_organizer: null oder {access, consent}.', ['status' => 400, 'field' => 'co_organizer']);
        if (isset($v['id']) && (!$d || intval($v['id']) !== intval($d['organizer_id']))) {
            return new WP_Error('tix_co_remote_invite', 'Auf dieser Seite den Co-Veranstalter bitte per E-Mail einladen.', ['status' => 400, 'field' => 'co_organizer']);
        }
        if (!$d) return new WP_Error('tix_invalid_field', 'Es ist noch kein Co-Veranstalter eingeladen.', ['status' => 400, 'field' => 'co_organizer']);
        if (!array_key_exists('access', $v)) return null;
        $access = filter_var($v['access'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($access === null) return new WP_Error('tix_invalid_field', 'co_organizer.access: true oder false.', ['status' => 400, 'field' => 'co_organizer.access']);
        if ($access && empty($d['consent']['at']) && empty($v['consent']) && !current_user_can('manage_options')) {
            return new WP_Error('tix_co_consent', 'Bitte bestätigen, dass Käuferdaten dieses Events an den Co-Veranstalter weitergegeben werden.', ['status' => 400, 'field' => 'co_organizer.consent']);
        }
        if ((bool) $access === !empty($d['access'])) return null;
        $r = self::send($post_id, ['action' => 'access', 'access' => $access]);
        if (is_wp_error($r)) return $r;
        $d['access'] = $access;
        if (in_array($r['co_organizer']['status'] ?? '', ['active', 'invited'], true)) $d['status'] = (string) $r['co_organizer']['status']; // Stand der Plattform (1.38.709)
        if ($access && empty($d['consent']['at'])) $d['consent'] = ['at' => gmdate('c'), 'by' => get_current_user_id()];
        update_post_meta($post_id, self::META, $d);
        return ['op' => 'remote'];
    }

    /** Aufruf an evendis.de (Event-Verteilung). → Antwort-Array | WP_Error */
    private static function send($event_id, array $body) {
        $remote = intval(get_post_meta(intval($event_id), '_tix_syndicate_remote_id', true));
        $url = rtrim((string) tix_get_settings('syndication_api_url'), '/') . '/syndicate/' . $remote . '/co-organizer';
        $body += ['source_id' => intval($event_id), 'by' => html_entity_decode((string) (tix_get_settings('syndication_site_name') ?: get_bloginfo('name')), ENT_QUOTES, 'UTF-8')];
        $resp = wp_remote_post($url, ['timeout' => 20, 'body' => wp_json_encode($body),
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Tix-Syndication-Key' => (string) tix_get_settings('syndication_api_key')]]);
        if (is_wp_error($resp)) return new WP_Error('tix_co_remote_unreachable', 'evendis.de ist gerade nicht erreichbar. Bitte gleich noch einmal versuchen.', ['status' => 502]);
        $code = intval(wp_remote_retrieve_response_code($resp));
        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        if ($code >= 200 && $code < 300 && is_array($data)) return $data;
        if (is_array($data) && !empty($data['code']) && $code >= 400 && $code < 500 && $code !== 401) {
            return new WP_Error((string) $data['code'], (string) ($data['message'] ?? 'Fehler bei evendis.de.'), ['status' => $code] + (array) ($data['data'] ?? []));
        }
        return new WP_Error('tix_co_remote_error', 'evendis.de hat die Einladung nicht angenommen (HTTP ' . $code . ').', ['status' => 502]);
    }

    /** POST /events/{id}/co-organizer/invite auf der Lizenzseite. */
    public static function rest_invite_source(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if (get_post_type($eid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!self::is_editor()) return new WP_Error('tix_co_forbidden', 'Einen Co-Veranstalter kann nur der Veranstalter einladen.', ['status' => 403]);
        $why = self::unavailable_reason($eid);
        if ($why !== '') return new WP_Error('tix_co_remote_unavailable', 'Co-Veranstalter gibt es nur für Events, die auf evendis.de geteilt sind.', ['status' => 409, 'reason' => $why]);
        $b = $req->get_json_params() ?: $req->get_params();
        $site_key = strtolower(trim(sanitize_text_field((string) ($b['site'] ?? ''))));
        $email = $site_key !== '' ? '' : sanitize_email((string) ($b['email'] ?? ''));
        if ($site_key === '' && !is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
        $access = array_key_exists('access', (array) $b) ? filter_var($b['access'], FILTER_VALIDATE_BOOLEAN) : true;
        if ($access && empty($b['consent']) && !current_user_can('manage_options')) {
            return new WP_Error('tix_co_consent', 'Bitte bestätigen, dass Käuferdaten dieses Events an den Co-Veranstalter weitergegeben werden.', ['status' => 400, 'field' => 'consent']);
        }
        $token = wp_generate_password(48, false);
        $r = self::send($eid, ['action' => 'invite', 'email' => $email, 'site' => $site_key, 'organizer' => trim(sanitize_text_field((string) ($b['organizer'] ?? ''))),
            'name' => mb_substr(trim(sanitize_text_field((string) ($b['name'] ?? ''))), 0, 120), 'access' => $access, 'token' => $token]);
        if (is_wp_error($r)) return $r;
        $co = (array) ($r['co_organizer'] ?? []);
        if (empty($co['id'])) return new WP_Error('tix_co_remote_error', 'evendis.de hat keinen Co-Veranstalter zurückgemeldet.', ['status' => 502]);
        $old = self::data($eid);
        $d = ['organizer_id' => intval($co['id']), 'name' => (string) ($co['name'] ?? ''), 'logo' => (string) ($co['logo'] ?? ''), 'slug' => (string) ($co['slug'] ?? ''),
            'email' => $email, 'access' => $access, 'status' => (string) ($co['status'] ?? 'active'),
            'since' => ($old && intval($old['organizer_id']) === intval($co['id'])) ? (string) $old['since'] : gmdate('c'),
            'consent' => $access ? ['at' => gmdate('c'), 'by' => get_current_user_id()] : null,
            'site' => !empty($co['site']['host']) ? ['key' => (string) ($co['site']['key'] ?? ''), 'host' => (string) $co['site']['host'], 'name' => (string) ($co['site']['name'] ?? '')] : null];
        update_post_meta($eid, self::META, $d);
        update_post_meta($eid, self::META_TOKEN, hash('sha256', $token));
        $res = rest_ensure_response(['ok' => true, 'created' => !empty($r['created']), 'mailed' => !empty($r['mailed']), 'co_organizer' => self::payload($eid), 'co_organizers' => [self::entry($eid, true)]]);
        $res->set_status(201);
        return $res;
    }

    /**
     * Lizenzseite: Co-Verbindung lösen (1.38.706). Meldet „clear“ an evendis.de; $force = auch ohne Antwort lokal lösen.
     * → ['platform' => true|false|null] | WP_Error
     */
    public static function remove($eid, $force) {
        if (!self::data($eid)) return ['platform' => null];
        $connected = class_exists('TIX_Syndication_Push') && TIX_Syndication_Push::is_configured() && intval(get_post_meta($eid, '_tix_syndicate_remote_id', true));
        $platform = null;
        if ($connected) {
            $r = self::send($eid, ['action' => 'clear']);
            if (is_wp_error($r) && !$force && !in_array(intval($r->get_error_data()['status'] ?? 0), [404], true)) {
                $st = intval($r->get_error_data()['status'] ?? 502);
                return new WP_Error('tix_co_remote_unreachable', 'evendis.de hat die Trennung nicht bestätigt (' . $r->get_error_message() . '). Mit „trotzdem trennen“ wird nur hier gelöst.', ['status' => $st >= 500 ? 502 : $st]);
            }
            $platform = !is_wp_error($r);
        }
        delete_post_meta($eid, self::META);
        delete_post_meta($eid, self::META_TOKEN);
        return ['platform' => $platform];
    }

    /** DELETE /events/{id}/co-organizer auf der Lizenzseite. */
    public static function rest_remove_source(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if (get_post_type($eid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!self::is_editor()) return new WP_Error('tix_co_forbidden', 'Die Verbindung kann nur der Veranstalter trennen.', ['status' => 403]);
        if (!self::data($eid)) return rest_ensure_response(['ok' => true, 'changed' => 'unchanged', 'co_organizers' => [], 'released' => ['platform' => null, 'source' => null]]);
        $r = self::remove($eid, filter_var($req->get_param('force'), FILTER_VALIDATE_BOOLEAN));
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(['ok' => true, 'changed' => 'removed', 'co_organizers' => [], 'released' => ['platform' => $r['platform'], 'source' => true]]);
    }

    /** GET /events/{id}/co-organizer/sites?q= auf der Lizenzseite → Suche bei evendis.de. */
    public static function rest_sites_source(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if (get_post_type($eid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!self::is_editor()) return new WP_Error('tix_co_forbidden', 'Nur für den Veranstalter.', ['status' => 403]);
        $why = self::unavailable_reason($eid);
        if ($why !== '') return new WP_Error('tix_co_remote_unavailable', 'Co-Veranstalter gibt es nur für Events, die auf evendis.de geteilt sind.', ['status' => 409, 'reason' => $why]);
        $q = trim(sanitize_text_field((string) $req->get_param('q')));
        if (mb_strlen($q) < 2) return new WP_Error('tix_invalid_field', 'Bitte mindestens 2 Zeichen eingeben.', ['status' => 400, 'field' => 'q']);
        $url = rtrim((string) tix_get_settings('syndication_api_url'), '/') . '/syndicate/co-sites?q=' . rawurlencode($q);
        $resp = wp_remote_get($url, ['timeout' => 15, 'headers' => ['Accept' => 'application/json', 'X-Tix-Syndication-Key' => (string) tix_get_settings('syndication_api_key')]]);
        if (is_wp_error($resp)) return new WP_Error('tix_co_remote_unreachable', 'evendis.de ist gerade nicht erreichbar. Bitte gleich noch einmal versuchen.', ['status' => 502]);
        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (intval(wp_remote_retrieve_response_code($resp)) !== 200 || !is_array($data)) {
            return new WP_Error('tix_co_remote_error', is_array($data) && !empty($data['message']) ? (string) $data['message'] : 'evendis.de hat die Suche abgelehnt.', ['status' => 502]);
        }
        return rest_ensure_response(['ok' => true, 'items' => array_values((array) ($data['items'] ?? []))]);
    }

    /** POST /partner/co/{id}/release (Plattform → Lizenzseite): Verbindung wurde auf der Plattform gelöst. */
    public static function perm_release(WP_REST_Request $req) {
        if (self::multi() || !class_exists('TIX_Partner_API')) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        $a = TIX_Partner_API::check_auth($req);
        if ($a !== true) return $a;
        $sid = absint($req['id']);
        $hash = (string) get_post_meta($sid, self::META_TOKEN, true);
        $tok = (string) $req->get_header('X-Tix-Co-Token');
        if (!self::data($sid) || $hash === '' || $tok === '' || !hash_equals($hash, hash('sha256', $tok))) return new WP_Error('tix_co_token', 'Ungültiger Event-Schlüssel.', ['status' => 401]);
        return true;
    }

    public static function rest_release(WP_REST_Request $req) {
        $sid = absint($req['id']);
        delete_post_meta($sid, self::META);
        delete_post_meta($sid, self::META_TOKEN);
        return rest_ensure_response(['ok' => true]);
    }

    // ── Durchgriff: freigegebene Routen ──

    /**
     * Darf die Route für Event $sid ausgeführt werden? → true | WP_Error
     * $path ohne /tixomat/v1, $q Query, $b JSON-Body.
     */
    public static function allowed($method, $path, array $q, array $b, $sid, $storno = false) {
        $sid = intval($sid);
        $no = function ($m = 'Diese Funktion gibt es für Co-Veranstalter nicht.') { return new WP_Error('tix_co_forbidden', $m, ['status' => 403]); };
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) return $no();
        $id = '(' . $sid . ')';
        // Event-Routen
        if (preg_match('#^/events/' . $id . '$#', $path)) {
            if ($method === 'DELETE') return $no('Ein gemeinsames Event kann nur der Veranstalter löschen.');
            if ($method === 'POST' && array_key_exists('co_organizer', $b)) return $no('Den Co-Veranstalter kann nur der Veranstalter ändern.');
            return true;
        }
        $ev_sub = '(edit|statistics|orders|guestlist|guestlist/[A-Za-z0-9-]+|tickets|report|report/export|raffle|raffle/entries|raffle/draw|raffle/notify|raffle/reset|ticket-fields|attendees)';
        if (preg_match('#^/events/' . $id . '/' . $ev_sub . '$#', $path, $m)) {
            if ($method === 'DELETE' && strpos($m[2], 'guestlist/') !== 0) return $no();
            return true;
        }
        if (preg_match('#^/(checkin/' . $id . '/(list|offline|guest/[A-Za-z0-9-]+)|pos/events/' . $id . '/categories|studio/events/' . $id . '/seatmap(/best|/block)?)$#', $path)) return $method !== 'DELETE' ? true : $no();
        // Routen mit event_id
        $param_routes = ['GET /orders', 'POST /pos/orders', 'POST /pos/quote', 'GET /pos/report', 'GET /pos/transactions', 'GET /music/dj/requests', 'POST /notifications/broadcast', 'GET /organizer/export', 'POST /checkin/scan'];
        if (in_array($method . ' ' . $path, $param_routes, true)) {
            $ev = intval($method === 'GET' ? ($q['event_id'] ?? 0) : ($b['event_id'] ?? 0));
            if ($ev !== $sid) return $no('Für Co-Veranstalter nur mit diesem Event (event_id).');
            if ($path === '/organizer/export' && !in_array((string) ($q['type'] ?? ''), ['guests', 'checkins'], true)) return $no('Käuferdaten eines gemeinsamen Events kann nur der Veranstalter exportieren.');
            if ($path === '/notifications/broadcast' && ($b['target'] ?? 'event') !== 'event') return $no('Nachrichten nur an die Käufer dieses Events.');
            return true;
        }
        // Storno und Erstattung nie über den Durchgriff (User 09.11.: Lizenz-Ersteller → Co darf weder stornieren noch erstatten, auch nicht an der Kasse)
        if (!$storno && preg_match('#^/(orders/\d+/(refund|cancel)|pos/orders/\d+/void)$#', $path)) return $no('Stornieren und Erstatten kann bei diesem Event nur der Veranstalter.');
        // Bestellungen und Tickets dieses Events
        if (preg_match('#^/(orders/(\d+)(/detail|/resend-email|/refund|/cancel)?|pos/orders/(\d+)/(email|void))$#', $path, $m)) {
            if ($method === 'DELETE') return $no();
            $oid = intval($m[2] ?: $m[4]);
            return self::order_of_event($oid, $sid) ? true : $no('Diese Bestellung gehört nicht zu diesem Event.');
        }
        if (preg_match('#^/(tickets/(\d+)/(resend-email|holder|transfer)|checkin/ticket/(\d+)/toggle)$#', $path, $m)) {
            if ($method !== 'POST') return $no();
            $tid = intval($m[2] ?: $m[4]);
            return (get_post_type($tid) === 'tix_ticket' && intval(get_post_meta($tid, '_tix_ticket_event_id', true)) === $sid) ? true : $no('Dieses Ticket gehört nicht zu diesem Event.');
        }
        return $no();
    }

    private static function order_of_event($order_id, $sid) {
        $order = class_exists('TIX_Order') ? TIX_Order::get($order_id) : null;
        if (!$order && function_exists('wc_get_order')) $order = wc_get_order($order_id);
        if (!$order || !class_exists('TIX_App_Scope')) return false;
        $ids = TIX_App_Scope::order_event_ids($order);
        return $ids && count(array_diff(array_map('intval', $ids), [intval($sid)])) === 0;
    }

    public static function perm_partner_co(WP_REST_Request $req) {
        if (self::multi() || !self::enabled() || !class_exists('TIX_Partner_API')) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        $a = TIX_Partner_API::check_auth($req);
        if ($a !== true) return $a;
        $sid = absint($req['id']);
        $d = self::data($sid);
        $hash = (string) get_post_meta($sid, self::META_TOKEN, true);
        $tok = (string) $req->get_header('X-Tix-Co-Token');
        if (!$d || $hash === '' || $tok === '' || !hash_equals($hash, hash('sha256', $tok))) return new WP_Error('tix_co_token', 'Ungültiger Event-Schlüssel.', ['status' => 401]);
        if (empty($d['access'])) return new WP_Error('tix_co_no_access', 'Der Veranstalter hat den Zugriff auf dieses Event ausgeschaltet.', ['status' => 403]);
        return true;
    }

    /** Technisches Konto je evendis-Co-Veranstalter (ohne Rolle; Rechte nur während der Anfrage). */
    private static function actor_user($oid, $name) {
        $ids = get_users(['meta_key' => self::USER_META, 'meta_value' => intval($oid), 'fields' => 'ID', 'number' => 1]);
        if ($ids) return intval($ids[0]);
        $login = 'co-evendis-' . intval($oid);
        for ($i = 2; username_exists($login) && $i < 50; $i++) $login = 'co-evendis-' . intval($oid) . '-' . $i;
        $uid = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(40, true, true), 'user_email' => $login . '@co.invalid',
            'display_name' => 'Co-Veranstalter: ' . ($name ?: $oid), 'role' => '']);
        if (is_wp_error($uid)) return 0;
        update_user_meta($uid, self::USER_META, intval($oid));
        return intval($uid);
    }

    public static function caps_filter($value, $object_id, $meta_key, $single) {
        global $wpdb;
        if (self::$acting && intval($object_id) === self::$acting && $meta_key === $wpdb->get_blog_prefix() . 'capabilities') {
            return [['tix_organizer' => true]]; // get_metadata nimmt bei $single den ersten Eintrag
        }
        return $value;
    }

    /** POST /partner/co/{id} {method, path, query, body} → {ok, status, data}. */
    public static function rest_partner_co(WP_REST_Request $req) {
        $sid = absint($req['id']);
        $b = $req->get_json_params();
        if (!is_array($b)) $b = [];
        $method = strtoupper((string) ($b['method'] ?? 'GET'));
        $path = '/' . ltrim((string) ($b['path'] ?? ''), '/');
        $q = is_array($b['query'] ?? null) ? $b['query'] : [];
        $body = is_array($b['body'] ?? null) ? $b['body'] : [];
        if (preg_match('#[^A-Za-z0-9/_-]#', $path)) return new WP_Error('tix_invalid_field', 'Ungültige Route.', ['status' => 400, 'field' => 'path']);
        $ok = self::allowed($method, $path, $q, $body, $sid);
        if (is_wp_error($ok)) return rest_ensure_response(['ok' => false, 'status' => intval($ok->get_error_data()['status'] ?? 403), 'data' => ['code' => $ok->get_error_code(), 'message' => $ok->get_error_message(), 'data' => $ok->get_error_data()]]);
        $d = self::data($sid);
        // Der Co-Veranstalter arbeitet schon auf der Plattform → hier nicht mehr „eingeladen“ (1.38.709)
        if (($d['status'] ?? '') === 'invited') { $d['status'] = 'active'; update_post_meta($sid, self::META, $d); }
        $uid = self::actor_user(intval($d['organizer_id']), (string) $d['name']);
        if (!$uid) return new WP_Error('tix_co_actor', 'Technisches Konto fehlt.', ['status' => 500]);
        self::$acting = $uid;
        add_filter('get_user_metadata', [__CLASS__, 'caps_filter'], 1, 4);
        clean_user_cache($uid);
        wp_set_current_user($uid);
        try {
            $sub = new WP_REST_Request($method, '/' . self::NS . $path);
            $sub->set_query_params($q);
            if ($method !== 'GET' && $body) { $sub->set_header('content-type', 'application/json'); $sub->set_body(wp_json_encode($body)); }
            $res = rest_do_request($sub);
            $data = rest_get_server()->response_to_data($res, false);
            $status = $res->get_status();
            $headers = $res->get_headers();
        } finally {
            remove_filter('get_user_metadata', [__CLASS__, 'caps_filter'], 1);
            self::$acting = 0;
            clean_user_cache($uid);
            wp_set_current_user(0);
        }
        $out = ['ok' => $status < 400, 'status' => $status, 'data' => $data];
        // CSV-Export u. ä.: Inhaltstyp mitgeben
        foreach (['Content-Type', 'Content-Disposition'] as $h) if (!empty($headers[$h])) $out['headers'][$h] = (string) $headers[$h];
        return rest_ensure_response($out);
    }

    // ═══════════════ evendis.de ═══════════════

    private static function partner_of_request(WP_REST_Request $req) {
        if (!self::multi() || !class_exists('TIX_Partners')) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        $p = TIX_Partners::find_by_key((string) $req->get_header('X-Tix-Syndication-Key'));
        if (!$p) return new WP_Error('unauthorized', 'Ungültiger Partner-Schlüssel.', ['status' => 401]);
        return $p;
    }

    public static function perm_syndicate(WP_REST_Request $req) {
        $p = self::partner_of_request($req);
        if (is_wp_error($p)) return $p;
        $eid = absint($req['id']);
        if (get_post_type($eid) !== 'event' || get_post_meta($eid, '_tix_syndicated', true) !== '1' || (string) get_post_meta($eid, '_tix_source_partner', true) !== (string) $p['id']) {
            return new WP_Error('not_found', 'Geteiltes Event nicht gefunden.', ['status' => 404]);
        }
        wp_set_current_user(0);
        $req->set_param('_partner', $p);
        return true;
    }

    /** POST /syndicate/{id}/co-organizer {action: invite|access|clear, …} (Lizenzseite → evendis). */
    public static function rest_syndicate_co(WP_REST_Request $req) {
        $eid = absint($req['id']);
        $b = $req->get_json_params() ?: [];
        $action = sanitize_key((string) ($b['action'] ?? ''));
        if ($action === 'clear') {
            $old = intval(get_post_meta($eid, self::M_OID, true));
            foreach ([self::M_OID, self::M_ACCESS, self::M_TOKEN] as $k) delete_post_meta($eid, $k);
            if ($old) { TIX_Co_Organizer::log_change($eid, 'removed', $old, false, 'creator'); TIX_Co_Organizer::notify_removed_co($eid, $old); }
            return rest_ensure_response(['ok' => true, 'co_organizer' => null]);
        }
        if ($action === 'access') {
            if (!intval(get_post_meta($eid, self::M_OID, true))) return new WP_Error('tix_invalid_field', 'Kein Co-Veranstalter gesetzt.', ['status' => 400]);
            update_post_meta($eid, self::M_ACCESS, !empty($b['access']) ? '1' : '0');
            return rest_ensure_response(['ok' => true, 'co_organizer' => self::mirror_payload($eid)]);
        }
        if ($action !== 'invite') return new WP_Error('tix_invalid_field', 'action: invite, access oder clear.', ['status' => 400, 'field' => 'action']);
        if (!empty($b['site'])) return self::syndicate_site($req, $eid, $b);
        $email = sanitize_email((string) ($b['email'] ?? ''));
        if (!is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
        $token = (string) ($b['token'] ?? '');
        if (strlen($token) < 32) return new WP_Error('tix_invalid_field', 'Event-Schlüssel fehlt.', ['status' => 400, 'field' => 'token']);
        $main = intval(get_post_meta($eid, '_tix_organizer_id', true));
        $r = TIX_Co_Organizer::find_or_create($email, trim(sanitize_text_field((string) ($b['organizer'] ?? ''))), mb_substr(sanitize_text_field((string) ($b['name'] ?? '')), 0, 120),
            ['event_id' => $eid, 'organizer_id' => $main, 'source' => (string) get_post_meta($eid, '_tix_source_site', true)]);
        if (is_wp_error($r)) return $r;
        [$org, $created, $user, $new_user] = $r;
        if ($main && intval($org->ID) === $main) return new WP_Error('tix_co_self', 'Diese E-Mail gehört zum Veranstalter des Events.', ['status' => 409, 'field' => 'email']);
        $changed = intval(get_post_meta($eid, self::M_OID, true)) !== intval($org->ID);
        update_post_meta($eid, self::M_OID, intval($org->ID));
        update_post_meta($eid, self::M_ACCESS, !empty($b['access']) ? '1' : '0');
        update_post_meta($eid, self::M_TOKEN, $token);
        $mailed = ($changed || $new_user) ? TIX_Co_Organizer::notify($eid, intval($org->ID), $new_user ? $user : null, (string) ($b['name'] ?? '')) : false;
        return rest_ensure_response(['ok' => true, 'created' => $created, 'mailed' => (bool) $mailed, 'co_organizer' => self::mirror_payload($eid)]);
    }

    /** Lizenzseite lädt eine Kunden-Seite als Co-Veranstalter ein (1.38.706). */
    private static function syndicate_site(WP_REST_Request $req, $eid, array $b) {
        $token = (string) ($b['token'] ?? '');
        if (strlen($token) < 32) return new WP_Error('tix_invalid_field', 'Event-Schlüssel fehlt.', ['status' => 400, 'field' => 'token']);
        $p = (array) $req->get_param('_partner');
        $r = TIX_Co_Sites::organizer_for(sanitize_text_field((string) $b['site']));
        if (is_wp_error($r)) return $r;
        [$oid, $created] = $r;
        $main = intval(get_post_meta($eid, '_tix_organizer_id', true));
        if (($main && $oid === $main) || ($oid && intval($p['organizer_id'] ?? 0) === $oid)) return new WP_Error('tix_co_self', 'Diese Seite gehört zum Veranstalter des Events.', ['status' => 409, 'field' => 'site']);
        $old = intval(get_post_meta($eid, self::M_OID, true));
        $changed = $old !== $oid;
        update_post_meta($eid, self::M_OID, $oid);
        update_post_meta($eid, self::M_ACCESS, !empty($b['access']) ? '1' : '0');
        update_post_meta($eid, self::M_TOKEN, $token);
        if ($changed && $old) { TIX_Co_Organizer::log_change($eid, 'removed', $old, false, 'creator'); TIX_Co_Organizer::notify_removed_co($eid, $old); }
        if ($changed) TIX_Co_Organizer::log_change($eid, 'added', $oid, !empty($b['access']), 'creator');
        $mailed = $changed ? TIX_Co_Sites::notify($eid, $oid, (string) ($b['by'] ?? '')) : false;
        return rest_ensure_response(['ok' => true, 'created' => $created, 'mailed' => (bool) $mailed, 'co_organizer' => self::mirror_payload($eid)]);
    }

    /**
     * Plattform: Teil-B-Verbindung am Spiegel lösen und die Quelle informieren (1.38.706).
     * → true (Quelle hat bestätigt) | false (nicht erreichbar) | null (kein Partner/Schlüssel)
     */
    public static function release_mirror($eid, $via) {
        $old = intval(get_post_meta($eid, self::M_OID, true));
        $tok = (string) get_post_meta($eid, self::M_TOKEN, true);
        foreach ([self::M_OID, self::M_ACCESS, self::M_TOKEN] as $k) delete_post_meta($eid, $k);
        if ($old) {
            TIX_Co_Organizer::log_change($eid, 'removed', $old, false, $via);
            TIX_Co_Organizer::notify_removed_co($eid, $old);
        }
        $p = class_exists('TIX_Partners') ? TIX_Partners::get((string) get_post_meta($eid, '_tix_source_partner', true)) : null;
        $sid = intval(get_post_meta($eid, '_tix_source_id', true));
        if (!$p || empty($p['api_base']) || strlen((string) ($p['key_out'] ?? '')) < 20 || $tok === '' || !$sid) return null;
        $resp = wp_remote_post(untrailingslashit($p['api_base']) . '/partner/co/' . $sid . '/release', ['timeout' => 15, 'body' => '{}',
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Tix-Partner-Id' => TIX_Partners::platform_id(),
                'X-Tix-Partner-Key' => (string) $p['key_out'], 'X-Tix-Co-Token' => $tok]]);
        return !is_wp_error($resp) && intval(wp_remote_retrieve_response_code($resp)) === 200;
    }

    private static function mirror_payload($eid) {
        $oid = intval(get_post_meta($eid, self::M_OID, true));
        if (!$oid) return null;
        $r = (class_exists('TIX_Public_Platform') ? TIX_Public_Platform::organizer_ref($oid) : null) ?: ['id' => $oid, 'slug' => '', 'name' => get_the_title($oid), 'logo' => ''];
        $uid = intval(get_post_meta($oid, '_tix_org_user_id', true));
        $invited = $uid && get_user_meta($uid, '_tix_invited_at', true) && !(get_user_meta($uid, '_tix_last_login', true) ?: get_user_meta($uid, 'last_login', true));
        $site = class_exists('TIX_Co_Sites') ? TIX_Co_Sites::site_of($oid) : null;
        return ['id' => $oid, 'name' => html_entity_decode((string) $r['name'], ENT_QUOTES, 'UTF-8'), 'logo' => (string) $r['logo'], 'slug' => (string) $r['slug'],
            'access' => get_post_meta($eid, self::M_ACCESS, true) === '1', 'status' => $site ? 'active' : ($invited ? 'invited' : 'active'), 'site' => $site];
    }

    /** Spiegel-Events, bei denen der Veranstalter des Nutzers Co-Veranstalter mit Zugriff ist. */
    private static function my_mirrors() {
        $oid = TIX_App_Scope::organizer_id_for_user();
        if (!$oid) return [];
        return array_map('intval', get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => 200, 'fields' => 'ids',
            'meta_query' => [['key' => self::M_OID, 'value' => strval($oid)], ['key' => self::M_ACCESS, 'value' => '1'], ['key' => '_tix_syndicated', 'value' => '1']]]));
    }

    public static function rest_remote_events(WP_REST_Request $req) {
        $items = [];
        foreach (self::my_mirrors() as $eid) {
            $p = TIX_Partners::get((string) get_post_meta($eid, '_tix_source_partner', true));
            $main = intval(get_post_meta($eid, '_tix_organizer_id', true));
            $thumb = get_post_thumbnail_id($eid);
            $items[] = ['id' => $eid, 'source_id' => intval(get_post_meta($eid, '_tix_source_id', true)), 'site' => $p ? (string) $p['name'] : (string) get_post_meta($eid, '_tix_source_site', true),
                'title' => html_entity_decode((string) get_the_title($eid), ENT_QUOTES, 'UTF-8'), 'date_start' => (string) get_post_meta($eid, '_tix_date_start', true),
                'time_start' => (string) get_post_meta($eid, '_tix_time_start', true), 'image' => $thumb ? (string) wp_get_attachment_image_url($thumb, 'large') : (string) get_post_meta($eid, '_tix_syndicated_image_url', true),
                'event_role' => 'co', 'remote' => true, 'ready' => self::ready($eid) === true,
                'shared_with' => $main ? ['id' => $main, 'name' => html_entity_decode((string) get_the_title($main), ENT_QUOTES, 'UTF-8')] : ($p ? ['id' => 0, 'name' => (string) $p['name']] : null),
                'base' => '/co-remote/' . $eid];
        }
        return rest_ensure_response(['ok' => true, 'items' => $items]);
    }

    /** Partner + Schlüssel für einen Spiegel → array | WP_Error */
    private static function ready($eid) {
        $p = TIX_Partners::get((string) get_post_meta($eid, '_tix_source_partner', true));
        if (!$p || empty($p['api_base']) || strlen((string) ($p['key_out'] ?? '')) < 20) return new WP_Error('tix_co_remote_unavailable', 'Die Verbindung zur Seite des Veranstalters ist noch nicht eingerichtet.', ['status' => 409]);
        $tok = (string) get_post_meta($eid, self::M_TOKEN, true);
        if ($tok === '') return new WP_Error('tix_co_remote_unavailable', 'Für dieses Event fehlt der Schlüssel. Bitte den Veranstalter, dich erneut einzuladen.', ['status' => 409]);
        return true;
    }

    public static function perm_remote(WP_REST_Request $req) {
        $c = TIX_REST_API::check_organizer($req);
        if ($c !== true) return $c;
        $eid = absint($req['id']);
        if (!in_array($eid, self::my_mirrors(), true)) return new WP_Error('tix_co_forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        return true;
    }

    /** GET|POST|DELETE /co-remote/{id}/{route} → Antwort der Lizenzseite (gleiches Format wie die Route dort). */
    public static function rest_remote_proxy(WP_REST_Request $req) {
        $eid = absint($req['id']);
        $u = wp_get_current_user();
        $body = $req->get_json_params();
        $env = self::forward($eid, $req->get_method(), '/' . ltrim((string) $req['path'], '/'), $req->get_query_params(), is_array($body) ? $body : [],
            ['email' => (string) $u->user_email, 'name' => (string) $u->display_name, 'organizer_id' => TIX_App_Scope::organizer_id_for_user()]);
        if (is_wp_error($env)) return $env;
        $res = new WP_REST_Response($env['data'], intval($env['status']));
        foreach ((array) ($env['headers'] ?? []) as $h => $v) if (in_array($h, ['Content-Type', 'Content-Disposition'], true)) $res->header($h, (string) $v);
        return $res;
    }

    /** Spiegel-Events, bei denen die Kunden-Seite des Veranstalters $oid Co-Veranstalter ist (mit und ohne Zugriff). */
    public static function mirrors_of($oid) {
        if (!intval($oid)) return [];
        return array_map('intval', get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => 200, 'fields' => 'ids',
            'meta_query' => [['key' => self::M_OID, 'value' => strval(intval($oid))], ['key' => '_tix_syndicated', 'value' => '1']]]));
    }

    /** Anfrage an die Lizenzseite weiterleiten → Umschlag {ok, status, data, headers?} | WP_Error */
    public static function forward($eid, $method, $path, array $q, array $body, array $actor) {
        $ok = self::ready($eid);
        if (is_wp_error($ok)) return $ok;
        $p = TIX_Partners::get((string) get_post_meta($eid, '_tix_source_partner', true));
        $sid = intval(get_post_meta($eid, '_tix_source_id', true));
        $resp = wp_remote_post(untrailingslashit($p['api_base']) . '/partner/co/' . $sid, ['timeout' => 25,
            'body' => wp_json_encode(['method' => $method, 'path' => $path, 'query' => $q ?: new stdClass(), 'body' => $body ?: new stdClass(), 'actor' => $actor]),
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Tix-Partner-Id' => TIX_Partners::platform_id(),
                'X-Tix-Partner-Key' => (string) $p['key_out'], 'X-Tix-Co-Token' => (string) get_post_meta($eid, self::M_TOKEN, true)]]);
        if (is_wp_error($resp)) return new WP_Error('tix_partner_unreachable', 'Die Seite des Veranstalters ist gerade nicht erreichbar.', ['status' => 502]);
        $code = intval(wp_remote_retrieve_response_code($resp));
        $env = json_decode((string) wp_remote_retrieve_body($resp), true);
        if ($code !== 200 || !is_array($env) || !array_key_exists('status', $env)) {
            $msg = is_array($env) && !empty($env['message']) ? (string) $env['message'] : 'Die Seite des Veranstalters hat die Anfrage abgelehnt.';
            return new WP_Error(is_array($env) && !empty($env['code']) ? (string) $env['code'] : 'tix_co_remote_error', $msg, ['status' => in_array($code, [401, 403, 404, 409, 429], true) ? $code : 502]);
        }
        return $env;
    }

    // ═══════════════ Routen ═══════════════

    public static function routes() {
        if (self::multi()) {
            register_rest_route(self::NS, '/syndicate/(?P<id>\d+)/co-organizer', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_syndicate_co'], 'permission_callback' => [__CLASS__, 'perm_syndicate']]);
            register_rest_route(self::NS, '/co-remote/events', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_remote_events'], 'permission_callback' => ['TIX_REST_API', 'check_organizer']]);
            register_rest_route(self::NS, '/co-remote/(?P<id>\d+)/(?P<path>[A-Za-z0-9/_-]+)', ['methods' => ['GET', 'POST', 'DELETE'], 'callback' => [__CLASS__, 'rest_remote_proxy'], 'permission_callback' => [__CLASS__, 'perm_remote']]);
            return;
        }
        register_rest_route(self::NS, '/events/(?P<id>\d+)/co-organizer/invite', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_invite_source'], 'permission_callback' => ['TIX_REST_API', 'check_organizer']]);
        register_rest_route(self::NS, '/partner/co/(?P<id>\d+)', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_partner_co'], 'permission_callback' => [__CLASS__, 'perm_partner_co']]);
        register_rest_route(self::NS, '/partner/co/(?P<id>\d+)/release', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_release'], 'permission_callback' => [__CLASS__, 'perm_release']]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/co-organizer', ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_remove_source'], 'permission_callback' => ['TIX_REST_API', 'check_organizer']]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/co-organizer/sites', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_sites_source'], 'permission_callback' => ['TIX_REST_API', 'check_organizer']]);
    }
}
