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
    // Mehrere Co (1.38.716): Listen sind maßgeblich, die Einzelfelder oben tragen den ersten Co (alte Leser).
    const META_LIST   = '_tix_co_remotes';       // Lizenzseite: [{…wie META…, token_hash}]
    const M_LIST      = '_tix_co_mirror';        // evendis-Spiegel: [{oid, access, token}]; _tix_co_remote_oid je Co eine Zeile

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

    private static function norm($d) {
        if (is_string($d) && $d !== '') $d = json_decode($d, true); // von Hand als JSON gesetzt (1.38.709)
        if (is_object($d)) $d = json_decode(wp_json_encode($d), true);
        return is_array($d) && intval($d['organizer_id'] ?? 0) > 0 ? $d : null;
    }

    /** Lizenzseite: alle Co des Events (mit token_hash). Alt-Events: Einzelfeld + Hash. */
    public static function entries($event_id) {
        $event_id = intval($event_id);
        if (!$event_id) return [];
        if (metadata_exists('post', $event_id, self::META_LIST)) {
            $out = [];
            foreach ((array) get_post_meta($event_id, self::META_LIST, true) as $d) {
                $d = self::norm($d);
                if ($d && !isset($out[intval($d['organizer_id'])])) $out[intval($d['organizer_id'])] = $d;
            }
            return array_values($out);
        }
        $d = self::norm(get_post_meta($event_id, self::META, true));
        if (!$d) return [];
        $d['token_hash'] = (string) get_post_meta($event_id, self::META_TOKEN, true);
        return [$d];
    }

    public static function save($event_id, array $list) {
        $event_id = intval($event_id);
        if (class_exists('TIX_Co_Money')) TIX_Co_Money::normalize($event_id);
        $list = array_values(array_filter(array_map([__CLASS__, 'norm'], $list)));
        if (!$list) { foreach ([self::META_LIST, self::META, self::META_TOKEN] as $k) delete_post_meta($event_id, $k); return; }
        update_post_meta($event_id, self::META_LIST, $list);
        $f = $list[0];
        $h = (string) ($f['token_hash'] ?? '');
        unset($f['token_hash']);
        update_post_meta($event_id, self::META, $f);
        if ($h !== '') update_post_meta($event_id, self::META_TOKEN, $h); else delete_post_meta($event_id, self::META_TOKEN);
    }

    /** Co $oid (ohne: der erste) oder null. */
    private static function data($event_id, $oid = 0) {
        foreach (self::entries($event_id) as $d) if (!$oid || intval($d['organizer_id']) === intval($oid)) return $d;
        return null;
    }

    private static function put($event_id, array $d) {
        $list = self::entries($event_id);
        $hit = false;
        foreach ($list as $i => $e) if (intval($e['organizer_id']) === intval($d['organizer_id'])) { $list[$i] = $d; $hit = true; }
        if (!$hit) $list[] = $d;
        self::save($event_id, $list);
    }

    public static function ids($event_id) {
        return array_map(function ($d) { return intval($d['organizer_id']); }, self::entries($event_id));
    }

    /** Co zum Event-Schlüssel (Durchgriff/Lösen) oder null. */
    private static function by_token($event_id, $tok) {
        if ($tok === '') return null;
        $h = hash('sha256', $tok);
        foreach (self::entries($event_id) as $d) if (($d['token_hash'] ?? '') !== '' && hash_equals((string) $d['token_hash'], $h)) return $d;
        return null;
    }

    public static function payload($event_id, $oid = 0) {
        $d = self::data($event_id, $oid);
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

    /** Alle Co als Einträge für `co_organizers`. */
    public static function entries_payload($event_id, $removable) {
        $out = [];
        foreach (self::ids($event_id) as $oid) if ($e = self::entry($event_id, $removable, $oid)) $out[] = $e;
        return $out;
    }

    /** Eintrag für `co_organizers` (1.38.706) oder null. */
    public static function entry($event_id, $removable, $oid = 0) {
        $p = self::payload($event_id, $oid);
        if (!$p) return null;
        $d = self::data($event_id, $p['id']);
        $site = !empty($d['site']['host']) ? ['key' => (string) ($d['site']['key'] ?? ''), 'host' => (string) $d['site']['host'], 'name' => (string) ($d['site']['name'] ?? $p['name'])] : null;
        $h = self::platform_host();
        return $p + ($site ? ['kind' => 'site', 'origin' => ['type' => 'site', 'label' => 'eigene Seite ' . $site['host'], 'host' => $site['host']], 'site' => $site]
                           : ['kind' => 'remote', 'origin' => ['type' => 'evendis', 'label' => 'auf ' . $h, 'host' => $h], 'site' => null]) + ['removable' => (bool) $removable];
    }

    private static function is_editor() {
        $u = wp_get_current_user();
        return $u && $u->ID && ($u->has_cap('manage_options') || in_array('tix_organizer', (array) $u->roles, true));
    }

    /** Status „eingeladen“ bei der Plattform nachfragen (1.38.715, höchstens alle 5 Minuten). */
    public static function refresh_status($event_id) {
        $inv = array_filter(self::entries($event_id), function ($d) { return ($d['status'] ?? '') === 'invited'; });
        if (!$inv || !intval(get_post_meta($event_id, '_tix_syndicate_remote_id', true))) return;
        $k = 'tix_co_st_' . intval($event_id);
        if (get_transient($k)) return;
        set_transient($k, 1, 5 * MINUTE_IN_SECONDS);
        $r = self::send($event_id, ['action' => 'status']);
        if (is_wp_error($r)) return;
        $remote = isset($r['co_organizers']) && is_array($r['co_organizers']) ? $r['co_organizers'] : (is_array($r['co_organizer'] ?? null) ? [$r['co_organizer']] : []);
        foreach ($inv as $d) {
            foreach ($remote as $c) {
                $st = (string) ($c['status'] ?? '');
                if (intval($c['id'] ?? 0) === intval($d['organizer_id']) && in_array($st, ['active', 'invited'], true) && $st !== $d['status']) { $d['status'] = $st; self::put($event_id, $d); }
            }
        }
    }

    public static function editor_payload($event_id) {
        if ($event_id && class_exists('TIX_Site_Move') && TIX_Site_Move::platform_co_id($event_id)) return TIX_Site_Move::co_editor_payload($event_id);
        if ($event_id) self::refresh_status($event_id);
        $why = $event_id ? self::unavailable_reason($event_id) : 'not_shared';
        $role = current_user_can('manage_options') ? 'admin' : 'owner';
        $list = $event_id ? self::entries_payload($event_id, self::is_editor()) : [];
        return ['co_organizer_available' => $why === '', 'co_organizer_mode' => 'remote', 'co_organizer_unavailable_reason' => $why ?: null,
            'co_organizer_editable' => $why === '' && self::is_editor(), 'co_organizer_multiple' => true, 'event_role' => $role, 'co_organizer' => $event_id ? self::payload($event_id) : null,
            'co_organizers' => $list, 'co_organizer_removable' => $list !== [] && self::is_editor(), 'co_organizer_site_search' => $why === '' && self::is_editor()];
    }

    public static function list_fields($event_id) {
        if (class_exists('TIX_Site_Move') && TIX_Site_Move::platform_co_id($event_id)) return TIX_Site_Move::co_list_fields($event_id);
        $all = array_map(function ($d) { return ['id' => intval($d['organizer_id']), 'name' => (string) $d['name'], 'logo' => (string) ($d['logo'] ?? '')]; }, self::entries($event_id));
        return $all ? ['event_role' => current_user_can('manage_options') ? 'admin' : 'owner', 'shared_with' => $all[0], 'shared_with_all' => $all] : [];
    }

    public static function public_ref($event_id) {
        return self::public_refs($event_id)[0] ?? null;
    }

    public static function public_refs($event_id) {
        if (class_exists('TIX_Site_Move') && TIX_Site_Move::platform_co_id($event_id)) return [['id' => 0, 'slug' => '', 'name' => (string) get_post_meta($event_id, '_tix_platform_co_by', true), 'logo' => '']];
        return array_map(function ($d) { return ['id' => 0, 'slug' => '', 'name' => (string) $d['name'], 'logo' => (string) ($d['logo'] ?? '')]; }, self::entries($event_id));
    }

    /** POST /events/{id} mit `co_organizer` auf der Lizenzseite: Zugriff umschalten oder entfernen (Einladen nur über /invite). */
    public static function check(array $body, $post_id, $is_new) {
        if ($is_new) return new WP_Error('tix_co_remote_invite', 'Den Co-Veranstalter bitte nach dem ersten Speichern per E-Mail einladen.', ['status' => 400, 'field' => 'co_organizer']);
        if (!self::is_editor()) return new WP_Error('tix_co_forbidden', 'Den Co-Veranstalter kann nur der Veranstalter ändern.', ['status' => 403]);
        $v = $body['co_organizer'];
        $ids = self::ids($post_id);
        $clear = ($v === null || $v === false || $v === '' || $v === 0);
        $why = self::unavailable_reason($post_id);
        if ($clear) { // alle lösen; Entfernen geht immer (auch bei ausgeschaltetem Schalter oder nicht mehr geteiltem Event)
            if (!$ids) return null;
            if (class_exists('TIX_Co_Money') && is_wp_error($cc = TIX_Co_Money::can_clear($post_id))) return $cc;
            $r = self::remove($post_id, false);
            return is_wp_error($r) ? $r : ['op' => 'remote'];
        }
        if ($why !== '') return new WP_Error('tix_co_remote_unavailable', 'Co-Veranstalter gibt es nur für Events, die auf evendis.de geteilt sind.', ['status' => 409, 'reason' => $why]);
        if (!is_array($v)) return new WP_Error('tix_invalid_field', 'co_organizer: null oder {id, access, consent}.', ['status' => 400, 'field' => 'co_organizer']);
        if (isset($v['id']) && !in_array(intval($v['id']), $ids, true)) {
            return new WP_Error('tix_co_remote_invite', 'Auf dieser Seite den Co-Veranstalter bitte per E-Mail einladen.', ['status' => 400, 'field' => 'co_organizer']);
        }
        if (!$ids) return new WP_Error('tix_invalid_field', 'Es ist noch kein Co-Veranstalter eingeladen.', ['status' => 400, 'field' => 'co_organizer']);
        if (!isset($v['id']) && count($ids) > 1) return new WP_Error('tix_invalid_field', 'Bitte angeben, welcher Co-Veranstalter gemeint ist (id).', ['status' => 400, 'field' => 'co_organizer.id']);
        $d = self::data($post_id, isset($v['id']) ? intval($v['id']) : $ids[0]);
        if (!array_key_exists('access', $v)) return null;
        $access = filter_var($v['access'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($access === null) return new WP_Error('tix_invalid_field', 'co_organizer.access: true oder false.', ['status' => 400, 'field' => 'co_organizer.access']);
        if ($access && empty($d['consent']['at']) && empty($v['consent']) && !current_user_can('manage_options')) {
            return new WP_Error('tix_co_consent', 'Bitte bestätigen, dass Käuferdaten dieses Events an den Co-Veranstalter weitergegeben werden.', ['status' => 400, 'field' => 'co_organizer.consent']);
        }
        if ((bool) $access === !empty($d['access'])) return null;
        $oid = intval($d['organizer_id']);
        if (!$access && class_exists('TIX_Co_Money') && TIX_Co_Money::owner_id($post_id) === $oid && TIX_Co_Money::sold($post_id)) {
            return new WP_Error('tix_co_money_locked', 'Dieser Co-Veranstalter nimmt das Geld ein, und es gibt schon Verkäufe. Der Zugriff bleibt an.', ['status' => 409, 'field' => 'co_organizer.access']);
        }
        $r = self::send($post_id, ['action' => 'access', 'access' => $access, 'organizer_id' => $oid]);
        if (is_wp_error($r)) return $r;
        $d['access'] = $access;
        if (in_array($r['co_organizer']['status'] ?? '', ['active', 'invited'], true)) $d['status'] = (string) $r['co_organizer']['status']; // Stand der Plattform (1.38.709)
        if ($access && empty($d['consent']['at'])) $d['consent'] = ['at' => gmdate('c'), 'by' => get_current_user_id()];
        self::put($post_id, $d);
        if (!$access && class_exists('TIX_Co_Money')) TIX_Co_Money::on_co_lost($post_id, $oid, true);
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

    /** Geldempfänger/Anteile an die Plattform melden (1.38.713; owner 'creator' | 'o:{ID}', shares je Beteiligtem seit 1.38.716) → Antwort | WP_Error */
    public static function send_money($event_id, $owner, $shares) {
        $shares = is_array($shares) ? $shares : [];
        $share = round(array_sum(array_diff_key($shares, [(string) $owner => 0])), 2);
        return self::send($event_id, ['action' => 'money', 'owner' => (string) $owner, 'shares' => (object) $shares, 'share' => $share]);
    }

    /** POST /events/{id}/co-organizer/invite auf der Lizenzseite. */
    public static function rest_invite_source(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if (get_post_type($eid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!self::is_editor()) return new WP_Error('tix_co_forbidden', 'Einen Co-Veranstalter kann nur der Veranstalter einladen.', ['status' => 403]);
        $r = self::invite_internal($eid, (array) ($req->get_json_params() ?: $req->get_params()), false);
        if (is_wp_error($r)) return $r;
        $res = rest_ensure_response($r);
        $res->set_status(201);
        return $res;
    }

    /**
     * Einladen auf der Lizenzseite (Editor oder, 1.38.715, von der Plattform weitergereicht: $by_platform, ohne Bestätigung).
     * $b: email | site | organizer_id (Plattform-Veranstalter), access, consent, organizer, name, money_owner, share → array | WP_Error
     */
    public static function invite_internal($eid, array $b, $by_platform) {
        $why = self::unavailable_reason($eid);
        if ($why !== '') return new WP_Error('tix_co_remote_unavailable', 'Co-Veranstalter gibt es nur für Events, die auf evendis.de geteilt sind.', ['status' => 409, 'reason' => $why]);
        $site_key = strtolower(trim(sanitize_text_field((string) ($b['site'] ?? ''))));
        $org_id = absint($b['organizer_id'] ?? 0);
        $email = ($site_key !== '' || $org_id) ? '' : sanitize_email((string) ($b['email'] ?? ''));
        if ($site_key === '' && !$org_id && !is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
        $access = array_key_exists('access', (array) $b) && $b['access'] !== null ? filter_var($b['access'], FILTER_VALIDATE_BOOLEAN) : true;
        if ($access && empty($b['consent']) && !$by_platform && !current_user_can('manage_options')) {
            return new WP_Error('tix_co_consent', 'Bitte bestätigen, dass Käuferdaten dieses Events an den Co-Veranstalter weitergegeben werden.', ['status' => 400, 'field' => 'consent']);
        }
        $money = null;
        if (class_exists('TIX_Co_Money') && (array_key_exists('money_owner', (array) $b) || array_key_exists('share', (array) $b) || array_key_exists('shares', (array) $b))) {
            $in = array_filter(['owner' => $b['money_owner'] ?? null, 'share' => $b['share'] ?? null, 'shares' => $b['shares'] ?? null], function ($v) { return $v !== null; });
            $money = TIX_Co_Money::check_values($eid, $in, ['new' => $org_id ?: -1, 'new_access' => (bool) $access]);
            if (is_wp_error($money)) return $money;
        }
        $token = wp_generate_password(48, false);
        $r = self::send($eid, ['action' => 'invite', 'email' => $email, 'site' => $site_key, 'organizer_id' => $org_id, 'organizer' => trim(sanitize_text_field((string) ($b['organizer'] ?? ''))),
            'name' => mb_substr(trim(sanitize_text_field((string) ($b['name'] ?? ''))), 0, 120), 'access' => $access, 'token' => $token]);
        if (is_wp_error($r)) return $r;
        $co = (array) ($r['co_organizer'] ?? []);
        if (empty($co['id'])) return new WP_Error('tix_co_remote_error', 'evendis.de hat keinen Co-Veranstalter zurückgemeldet.', ['status' => 502]);
        $oid = intval($co['id']);
        $old = self::data($eid, $oid); // gleicher Co erneut: aktualisieren, andere bleiben (1.38.716)
        $d = ['organizer_id' => $oid, 'name' => (string) ($co['name'] ?? ''), 'logo' => (string) ($co['logo'] ?? ''), 'slug' => (string) ($co['slug'] ?? ''),
            'email' => $email ?: (string) ($old['email'] ?? ''), 'access' => $access, 'status' => (string) ($co['status'] ?? 'active'),
            'since' => $old ? (string) $old['since'] : gmdate('c'),
            'consent' => $access ? (!empty($old['consent']['at']) ? $old['consent'] : ['at' => gmdate('c'), 'by' => get_current_user_id(), 'via' => $by_platform ? 'platform' : 'creator']) : null,
            'site' => !empty($co['site']['host']) ? ['key' => (string) ($co['site']['key'] ?? ''), 'host' => (string) $co['site']['host'], 'name' => (string) ($co['site']['name'] ?? '')] : null,
            'token_hash' => hash('sha256', $token)];
        self::put($eid, $d);
        if (!$access && class_exists('TIX_Co_Money')) TIX_Co_Money::on_co_lost($eid, $oid, true);
        if (is_array($money)) TIX_Co_Money::apply($eid, TIX_Co_Money::resolve_new($money, $oid));
        return ['ok' => true, 'created' => !empty($r['created']), 'mailed' => !empty($r['mailed']), 'mail_note' => (string) ($r['mail_note'] ?? ''), 'changed' => $old ? 'changed' : 'added',
            'co_organizer' => self::payload($eid, $oid), 'co_organizers' => self::entries_payload($eid, true)];
    }

    /** POST /partner/co/{id}/assign (Plattform → Lizenzseite, 1.38.715): Co auf dem Spiegel angelegt → hier wie eine Einladung. */
    public static function perm_assign(WP_REST_Request $req) {
        if (self::multi() || !class_exists('TIX_Partner_API')) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        $a = TIX_Partner_API::check_auth($req);
        if ($a !== true) return $a;
        $sid = absint($req['id']);
        if (get_post_type($sid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        return true;
    }

    public static function rest_assign(WP_REST_Request $req) {
        $b = (array) ($req->get_json_params() ?: []);
        if (!empty($b['clear'])) { // mit organizer_id nur diesen Co, sonst alle
            $sid = absint($req['id']);
            $r = self::remove($sid, true, absint($b['organizer_id'] ?? 0));
            return is_wp_error($r) ? $r : rest_ensure_response(['ok' => true, 'co_organizer' => self::payload($sid), 'co_organizers' => self::entries_payload($sid, true)]);
        }
        $r = self::invite_internal(absint($req['id']), $b, true);
        return is_wp_error($r) ? $r : rest_ensure_response($r);
    }

    /**
     * Plattform: Co auf einem Spiegel an die Quelle weiterreichen (1.38.715). $b wie invite_internal (+ organizer_id).
     * → Antwort der Quelle | WP_Error (nur Teil B; die Quelle meldet den Co per Einladung zurück)
     */
    public static function forward_to_source($eid, array $b) {
        $p = class_exists('TIX_Partners') ? TIX_Partners::get((string) get_post_meta($eid, '_tix_source_partner', true)) : null;
        $sid = intval(get_post_meta($eid, '_tix_source_id', true));
        $site = (string) (get_post_meta($eid, '_tix_source_site', true) ?: 'der Partnerseite');
        if (!$p || empty($p['api_base']) || strlen((string) ($p['key_out'] ?? '')) < 20 || !$sid) {
            return new WP_Error('tix_co_source', 'Dieses Event kommt von ' . $site . '. Den Co-Veranstalter bitte dort anlegen (keine Verbindung zur Seite eingerichtet).', ['status' => 409, 'site' => $site]);
        }
        $resp = wp_remote_post(untrailingslashit($p['api_base']) . '/partner/co/' . $sid . '/assign', ['timeout' => 30, 'body' => wp_json_encode($b),
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Tix-Partner-Id' => TIX_Partners::platform_id(), 'X-Tix-Partner-Key' => (string) $p['key_out']]]);
        if (is_wp_error($resp)) return new WP_Error('tix_partner_unreachable', $site . ' ist gerade nicht erreichbar. Bitte gleich noch einmal versuchen.', ['status' => 502]);
        $code = intval(wp_remote_retrieve_response_code($resp));
        $d = json_decode((string) wp_remote_retrieve_body($resp), true);
        if ($code >= 200 && $code < 300 && is_array($d)) return $d;
        if (is_array($d) && !empty($d['code']) && $code >= 400 && $code < 500 && $code !== 401) return new WP_Error((string) $d['code'], (string) ($d['message'] ?? 'Fehler bei ' . $site . '.'), ['status' => $code] + (array) ($d['data'] ?? []));
        return new WP_Error('tix_co_source_error', $site . ' hat den Co-Veranstalter nicht übernommen (HTTP ' . $code . ').', ['status' => 502]);
    }

    /**
     * Lizenzseite: Co-Verbindung lösen (1.38.706). Meldet „clear“ an evendis.de; $force = auch ohne Antwort lokal lösen.
     * $oid = nur diesen Co (1.38.716), 0 = alle. → ['platform' => true|false|null] | WP_Error
     */
    public static function remove($eid, $force, $oid = 0) {
        $ids = self::ids($eid);
        if (!$ids || ($oid && !in_array(intval($oid), $ids, true))) return ['platform' => null];
        if (!$force && class_exists('TIX_Co_Money') && is_wp_error($cc = TIX_Co_Money::can_clear($eid, $oid))) return $cc;
        $connected = class_exists('TIX_Syndication_Push') && TIX_Syndication_Push::is_configured() && intval(get_post_meta($eid, '_tix_syndicate_remote_id', true));
        $platform = null;
        if ($connected) {
            $r = self::send($eid, $oid ? ['action' => 'clear', 'organizer_id' => intval($oid)] : ['action' => 'clear']);
            if (is_wp_error($r) && !$force && !in_array(intval($r->get_error_data()['status'] ?? 0), [404], true)) {
                $st = intval($r->get_error_data()['status'] ?? 502);
                return new WP_Error('tix_co_remote_unreachable', 'evendis.de hat die Trennung nicht bestätigt (' . $r->get_error_message() . '). Mit „trotzdem trennen“ wird nur hier gelöst.', ['status' => $st >= 500 ? 502 : $st]);
            }
            $platform = !is_wp_error($r);
        }
        self::drop($eid, $oid);
        return ['platform' => $platform];
    }

    /** Lokal lösen (ohne Meldung) + Geld nachziehen. */
    private static function drop($eid, $oid) {
        $gone = $oid ? [intval($oid)] : self::ids($eid);
        self::save($eid, $oid ? array_values(array_filter(self::entries($eid), function ($d) use ($oid) { return intval($d['organizer_id']) !== intval($oid); })) : []);
        if (!class_exists('TIX_Co_Money')) return;
        if (!self::ids($eid)) { TIX_Co_Money::reset($eid); return; }
        foreach ($gone as $g) TIX_Co_Money::on_co_lost($eid, $g, false);
    }

    /** DELETE /events/{id}/co-organizer auf der Lizenzseite. */
    public static function rest_remove_source(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if (get_post_type($eid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!self::is_editor()) return new WP_Error('tix_co_forbidden', 'Die Verbindung kann nur der Veranstalter trennen.', ['status' => 403]);
        $oid = TIX_Co_Organizer::pick_one(self::ids($eid), absint($req->get_param('organizer_id')));
        if (is_wp_error($oid)) return $oid;
        if (!$oid) return rest_ensure_response(['ok' => true, 'changed' => 'unchanged', 'removed_id' => null, 'co_organizers' => self::entries_payload($eid, true), 'released' => ['platform' => null, 'source' => null]]);
        if (class_exists('TIX_Co_Money') && is_wp_error($cc = TIX_Co_Money::can_clear($eid, $oid))) return $cc;
        $r = self::remove($eid, filter_var($req->get_param('force'), FILTER_VALIDATE_BOOLEAN), $oid);
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(['ok' => true, 'changed' => 'removed', 'removed_id' => $oid, 'co_organizers' => self::entries_payload($eid, true), 'released' => ['platform' => $r['platform'], 'source' => true]]);
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
        $d = self::by_token($sid, (string) $req->get_header('X-Tix-Co-Token'));
        if (!$d) return new WP_Error('tix_co_token', 'Ungültiger Event-Schlüssel.', ['status' => 401]);
        $req->set_param('_co_oid', intval($d['organizer_id']));
        return true;
    }

    /** Der Schlüssel bestimmt, welcher Co gelöst wird (1.38.716). */
    public static function rest_release(WP_REST_Request $req) {
        $sid = absint($req['id']);
        self::drop($sid, intval($req->get_param('_co_oid')));
        return rest_ensure_response(['ok' => true, 'released_id' => intval($req->get_param('_co_oid'))]);
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
        $ev_sub = '(edit|statistics|orders|guestlist|guestlist/[A-Za-z0-9-]+|tickets|report|report/export|raffle|raffle/entries|raffle/draw|raffle/notify|raffle/reset|ticket-fields|attendees|co-balance|co-balance/pdf)';
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
        $d = self::by_token($sid, (string) $req->get_header('X-Tix-Co-Token')); // je Co ein Schlüssel (1.38.716)
        if (!$d) return new WP_Error('tix_co_token', 'Ungültiger Event-Schlüssel.', ['status' => 401]);
        if (empty($d['access'])) return new WP_Error('tix_co_no_access', 'Der Veranstalter hat den Zugriff auf dieses Event ausgeschaltet.', ['status' => 403]);
        $req->set_param('_co_oid', intval($d['organizer_id']));
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
        $d = self::data($sid, intval($req->get_param('_co_oid')));
        if (!$d) return new WP_Error('tix_co_token', 'Ungültiger Event-Schlüssel.', ['status' => 401]);
        // Der Co-Veranstalter arbeitet schon auf der Plattform → hier nicht mehr „eingeladen“ (1.38.709)
        if (($d['status'] ?? '') === 'invited') { $d['status'] = 'active'; self::put($sid, $d); }
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
        $want = absint($b['organizer_id'] ?? 0);
        if ($action === 'clear') { // mit organizer_id nur diesen Co, sonst alle (ältere Lizenzseiten)
            foreach ($want ? [$want] : self::mirror_ids($eid) as $old) {
                if (!self::mirror_drop($eid, $old)) continue;
                TIX_Co_Organizer::log_change($eid, 'removed', $old, false, 'creator');
                TIX_Co_Organizer::notify_removed_co($eid, $old);
            }
            return rest_ensure_response(['ok' => true, 'co_organizer' => self::mirror_payload($eid), 'co_organizers' => self::mirror_payloads($eid)]);
        }
        if ($action === 'access') {
            $ids = self::mirror_ids($eid);
            $oid = $want ?: (count($ids) === 1 ? $ids[0] : 0);
            if (!$oid || !in_array($oid, $ids, true)) return new WP_Error('tix_invalid_field', $ids ? 'Diesen Co-Veranstalter gibt es bei diesem Event nicht.' : 'Kein Co-Veranstalter gesetzt.', ['status' => 400]);
            $e = self::mirror_entry($eid, $oid);
            $e['access'] = !empty($b['access']);
            self::mirror_put($eid, $e);
            return rest_ensure_response(['ok' => true, 'co_organizer' => self::mirror_payload($eid, $oid), 'co_organizers' => self::mirror_payloads($eid)]);
        }
        if ($action === 'status') return rest_ensure_response(['ok' => true, 'co_organizer' => self::mirror_payload($eid), 'co_organizers' => self::mirror_payloads($eid)]); // Stand für die Lizenzseite (1.38.715)
        if ($action === 'money' && class_exists('TIX_Co_Seller')) { // Geldempfänger/Anteil (1.38.713)
            $r = TIX_Co_Seller::syndicate_money($eid, $b);
            return is_wp_error($r) ? $r : rest_ensure_response($r);
        }
        if ($action !== 'invite') return new WP_Error('tix_invalid_field', 'action: invite, access, money oder clear.', ['status' => 400, 'field' => 'action']);
        if (!empty($b['site'])) return self::syndicate_site($req, $eid, $b);
        if (!empty($b['organizer_id'])) return self::syndicate_org($req, $eid, $b); // vom Plattform-Admin gewählt (1.38.715)
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
        $changed = self::mirror_link($eid, intval($org->ID), !empty($b['access']), $token, 'creator');
        $mailed = ($changed || $new_user) ? TIX_Co_Organizer::notify($eid, intval($org->ID), $new_user ? $user : null, (string) ($b['name'] ?? '')) : false;
        return rest_ensure_response(['ok' => true, 'created' => $created, 'mailed' => (bool) $mailed, 'co_organizer' => self::mirror_payload($eid, intval($org->ID)), 'co_organizers' => self::mirror_payloads($eid)]);
    }

    /** Lizenzseite übernimmt einen bestehenden Plattform-Veranstalter (vom Plattform-Admin auf dem Spiegel gewählt, 1.38.715). */
    private static function syndicate_org(WP_REST_Request $req, $eid, array $b) {
        $token = (string) ($b['token'] ?? '');
        if (strlen($token) < 32) return new WP_Error('tix_invalid_field', 'Event-Schlüssel fehlt.', ['status' => 400, 'field' => 'token']);
        $oid = absint($b['organizer_id']);
        $o = get_post($oid);
        if (!$o || $o->post_type !== 'tix_organizer' || $o->post_status !== 'publish') return new WP_Error('tix_invalid_field', 'Diesen Veranstalter gibt es nicht oder er ist nicht veröffentlicht.', ['status' => 400, 'field' => 'organizer_id']);
        $p = (array) $req->get_param('_partner');
        $main = intval(get_post_meta($eid, '_tix_organizer_id', true));
        if (($main && $oid === $main) || intval($p['organizer_id'] ?? 0) === $oid) return new WP_Error('tix_co_self', 'Das ist der Veranstalter des Events.', ['status' => 409, 'field' => 'organizer_id']);
        $site = class_exists('TIX_Co_Sites') && TIX_Co_Sites::is_site_profile($oid);
        $changed = self::mirror_link($eid, $oid, !empty($b['access']), $token, 'platform');
        $mailed = false; $note = '';
        if ($changed) {
            if ($site) [$mailed, $note] = TIX_Co_Sites::notify_result($eid, $oid, (string) ($b['by'] ?? ''));
            else $mailed = TIX_Co_Organizer::notify($eid, $oid, null, '');
        }
        if ($site) TIX_Co_Sites::trigger_sync($oid);
        return rest_ensure_response(['ok' => true, 'created' => false, 'mailed' => (bool) $mailed, 'mail_note' => $note, 'co_organizer' => self::mirror_payload($eid, $oid), 'co_organizers' => self::mirror_payloads($eid)]);
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
        $changed = self::mirror_link($eid, $oid, !empty($b['access']), $token, 'creator');
        $mailed = false; $note = '';
        if ($changed) [$mailed, $note] = TIX_Co_Sites::notify_result($eid, $oid, (string) ($b['by'] ?? ''));
        TIX_Co_Sites::trigger_sync($oid);
        return rest_ensure_response(['ok' => true, 'created' => $created, 'mailed' => (bool) $mailed, 'mail_note' => $note, 'co_organizer' => self::mirror_payload($eid, $oid), 'co_organizers' => self::mirror_payloads($eid)]);
    }

    // ── Spiegel: Liste der Co (1.38.716) ──

    /** [{oid, access, token}] des Spiegels. Alt-Spiegel: Einzelfelder. */
    public static function mirror_entries($eid) {
        $eid = intval($eid);
        if (!$eid) return [];
        if (metadata_exists('post', $eid, self::M_LIST)) {
            $out = [];
            foreach ((array) get_post_meta($eid, self::M_LIST, true) as $e) {
                $o = intval(is_array($e) ? ($e['oid'] ?? 0) : 0);
                if ($o && !isset($out[$o])) $out[$o] = ['oid' => $o, 'access' => !empty($e['access']), 'token' => (string) ($e['token'] ?? '')];
            }
            return array_values($out);
        }
        $o = intval(get_post_meta($eid, self::M_OID, true));
        return $o ? [['oid' => $o, 'access' => get_post_meta($eid, self::M_ACCESS, true) === '1', 'token' => (string) get_post_meta($eid, self::M_TOKEN, true)]] : [];
    }

    public static function mirror_save($eid, array $list) {
        $eid = intval($eid);
        if (class_exists('TIX_Co_Money')) TIX_Co_Money::normalize($eid);
        foreach ([self::M_OID, self::M_ACCESS, self::M_TOKEN] as $k) delete_post_meta($eid, $k);
        $list = array_values(array_filter($list, function ($e) { return intval($e['oid'] ?? 0) > 0; }));
        if (!$list) { delete_post_meta($eid, self::M_LIST); clean_post_cache($eid); return; }
        update_post_meta($eid, self::M_LIST, $list);
        foreach ($list as $e) add_post_meta($eid, self::M_OID, intval($e['oid'])); // je Co eine Zeile (Abfragen)
        update_post_meta($eid, self::M_ACCESS, !empty($list[0]['access']) ? '1' : '0');
        update_post_meta($eid, self::M_TOKEN, (string) $list[0]['token']);
        clean_post_cache($eid);
    }

    public static function mirror_ids($eid) {
        return array_map(function ($e) { return $e['oid']; }, self::mirror_entries($eid));
    }

    public static function mirror_entry($eid, $oid) {
        foreach (self::mirror_entries($eid) as $e) if ($e['oid'] === intval($oid)) return $e;
        return null;
    }

    public static function mirror_has_access($eid, $oid) {
        $e = self::mirror_entry($eid, $oid);
        return $e ? $e['access'] : false;
    }

    private static function mirror_put($eid, array $e) {
        $list = self::mirror_entries($eid);
        $hit = false;
        foreach ($list as $i => $x) if ($x['oid'] === intval($e['oid'])) { $list[$i] = $e; $hit = true; }
        if (!$hit) $list[] = $e;
        self::mirror_save($eid, $list);
    }

    /** → war verbunden? */
    private static function mirror_drop($eid, $oid) {
        $list = self::mirror_entries($eid);
        $rest = array_values(array_filter($list, function ($x) use ($oid) { return $x['oid'] !== intval($oid); }));
        if (count($rest) === count($list)) return false;
        self::mirror_save($eid, $rest);
        return true;
    }

    /** Co am Spiegel hinzufügen/aktualisieren (ersetzt nie) + Protokoll. → neu? */
    private static function mirror_link($eid, $oid, $access, $token, $via) {
        $cur = self::mirror_entry($eid, $oid);
        self::mirror_put($eid, ['oid' => intval($oid), 'access' => (bool) $access, 'token' => (string) $token]);
        if (!$cur) TIX_Co_Organizer::log_change($eid, 'added', $oid, (bool) $access, $via);
        elseif ($cur['access'] !== (bool) $access) TIX_Co_Organizer::log_change($eid, 'changed', $oid, (bool) $access, $via);
        return !$cur;
    }

    /**
     * Plattform: Teil-B-Verbindung am Spiegel lösen und die Quelle informieren (1.38.706).
     * $oid = nur diesen Co (1.38.716), 0 = alle. → true (Quelle hat bestätigt) | false (nicht erreichbar) | null (kein Partner/Schlüssel)
     */
    public static function release_mirror($eid, $via, $oid = 0) {
        if (!$oid) {
            $res = null;
            foreach (self::mirror_ids($eid) as $o) { $r = self::release_mirror($eid, $via, $o); $res = $res === false ? false : $r; }
            return $res;
        }
        $e = self::mirror_entry($eid, $oid);
        if (!$e) return null;
        $old = $e['oid'];
        $tok = $e['token'];
        self::mirror_drop($eid, $old);
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

    public static function mirror_payloads($eid) {
        return array_values(array_filter(array_map(function ($o) use ($eid) { return self::mirror_payload($eid, $o); }, self::mirror_ids($eid))));
    }

    /** Co $oid des Spiegels (ohne: der erste) oder null. */
    public static function mirror_payload($eid, $oid = 0) {
        $e = $oid ? self::mirror_entry($eid, $oid) : (self::mirror_entries($eid)[0] ?? null);
        if (!$e) return null;
        $oid = $e['oid'];
        $r = (class_exists('TIX_Public_Platform') ? TIX_Public_Platform::organizer_ref($oid) : null) ?: ['id' => $oid, 'slug' => '', 'name' => get_the_title($oid), 'logo' => ''];
        $site = class_exists('TIX_Co_Sites') ? TIX_Co_Sites::site_of($oid) : null;
        $status = $site ? 'active' : TIX_Co_Organizer::org_status($oid); // gleiche Regel wie Teil A (1.38.715)
        return ['id' => $oid, 'name' => html_entity_decode((string) $r['name'], ENT_QUOTES, 'UTF-8'), 'logo' => (string) $r['logo'], 'slug' => (string) $r['slug'],
            'access' => $e['access'], 'status' => $status, 'site' => $site];
    }

    /** Spiegel-Events, bei denen der Veranstalter des Nutzers Co-Veranstalter mit Zugriff ist. */
    private static function my_mirrors() {
        $oid = TIX_App_Scope::organizer_id_for_user();
        if (!$oid) return [];
        $ids = array_map('intval', get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => 200, 'fields' => 'ids',
            'meta_query' => [['key' => self::M_OID, 'value' => strval($oid)], ['key' => '_tix_syndicated', 'value' => '1']]]));
        return array_values(array_filter($ids, function ($e) use ($oid) { return self::mirror_has_access($e, $oid); })); // Zugriff je Co (1.38.716)
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
                'event_role' => 'co', 'remote' => true, 'ready' => self::ready($eid, TIX_App_Scope::organizer_id_for_user()) === true,
                'shared_with' => $main ? ['id' => $main, 'name' => html_entity_decode((string) get_the_title($main), ENT_QUOTES, 'UTF-8')] : ($p ? ['id' => 0, 'name' => (string) $p['name']] : null),
                'base' => '/co-remote/' . $eid];
        }
        return rest_ensure_response(['ok' => true, 'items' => $items]);
    }

    /** Partner + Schlüssel des Co $oid für einen Spiegel → true | WP_Error */
    private static function ready($eid, $oid = 0) {
        $p = TIX_Partners::get((string) get_post_meta($eid, '_tix_source_partner', true));
        if (!$p || empty($p['api_base']) || strlen((string) ($p['key_out'] ?? '')) < 20) return new WP_Error('tix_co_remote_unavailable', 'Die Verbindung zur Seite des Veranstalters ist noch nicht eingerichtet.', ['status' => 409]);
        $e = $oid ? self::mirror_entry($eid, $oid) : (self::mirror_entries($eid)[0] ?? null);
        $tok = $e ? $e['token'] : '';
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
        $coid = intval($actor['organizer_id'] ?? 0);
        $ok = self::ready($eid, $coid); // Schlüssel des handelnden Co (1.38.716)
        if (is_wp_error($ok)) return $ok;
        $e = $coid ? self::mirror_entry($eid, $coid) : (self::mirror_entries($eid)[0] ?? null);
        $p = TIX_Partners::get((string) get_post_meta($eid, '_tix_source_partner', true));
        $sid = intval(get_post_meta($eid, '_tix_source_id', true));
        $resp = wp_remote_post(untrailingslashit($p['api_base']) . '/partner/co/' . $sid, ['timeout' => 25,
            'body' => wp_json_encode(['method' => $method, 'path' => $path, 'query' => $q ?: new stdClass(), 'body' => $body ?: new stdClass(), 'actor' => $actor]),
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Tix-Partner-Id' => TIX_Partners::platform_id(),
                'X-Tix-Partner-Key' => (string) $p['key_out'], 'X-Tix-Co-Token' => (string) $e['token']]]);
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
        register_rest_route(self::NS, '/partner/co/(?P<id>\d+)/assign', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_assign'], 'permission_callback' => [__CLASS__, 'perm_assign']]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/co-organizer', ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_remove_source'], 'permission_callback' => ['TIX_REST_API', 'check_organizer']]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/co-organizer/sites', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_sites_source'], 'permission_callback' => ['TIX_REST_API', 'check_organizer']]);
    }
}
