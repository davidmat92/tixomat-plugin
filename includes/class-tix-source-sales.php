<?php
/**
 * Verkäufe der Quellseite im Plattform-Studio (1.38.719, User 10.11.).
 *
 * Quelle (Lizenzseite, z. B. kitchenklub.de, mallorca-festival-xxl.de, KMT):
 *   Schalter `share_sales_with_platform` (Option `tix_share_sales_with_platform`, Vorgabe an) über GET/POST /studio/config.
 *   GET /partner/events/{id}/sales  (X-Tix-Partner-Id = Host der Plattform, X-Tix-Partner-Key) → Kennzahlen des geteilten
 *   Events wie /events/{id}/statistics, ohne Käuferdaten. Schalter aus → 403 tix_sales_hidden.
 *
 * Plattform (evendis.de):
 *   GET /events/{id}/source-sales (nur Plattform-Admin) für Spiegel (`_tix_syndicated=1`) → {ok, available, site, statistics,
 *   fetched_at} bzw. {ok, available: false, reason: hidden|unreachable|no_partner|unsupported, site}. 60 s Cache.
 */
if (!defined('ABSPATH')) exit;

class TIX_Source_Sales {
    const NS      = 'tixomat/v1';
    const OPT     = 'tix_share_sales_with_platform';
    const CACHE   = 60;
    /** Kennzahlen, die die Quelle herausgibt (alles Summen, keine Käuferdaten). */
    const FIELDS  = ['total_revenue', 'total_tickets', 'total_orders', 'by_category', 'by_day', 'checkin_total', 'checkin_done', 'checkin_rate',
        'sales', 'sales_by_day', 'by_channel', 'checkin_by_slot'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    /** Quelle: Verkaufszahlen mit der Plattform teilen? (Vorgabe an) */
    public static function enabled() {
        return get_option(self::OPT, '1') !== '0';
    }

    public static function set($on) {
        update_option(self::OPT, $on ? '1' : '0', false);
    }

    /** Für /studio/config → syndication.share_sales_with_platform (null auf der Plattform selbst). */
    public static function setting() {
        return self::multi() ? null : self::enabled();
    }

    public static function routes() {
        register_rest_route(self::NS, '/partner/events/(?P<id>\d+)/sales', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_source'], 'permission_callback' => [__CLASS__, 'perm_partner']]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/source-sales', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_platform'], 'permission_callback' => [__CLASS__, 'perm_admin']]);
    }

    // ═══════════════ Quelle ═══════════════

    /** Partner-Schlüssel der Plattform (wie die Partner-API, aber ohne „Verkauf über die Plattform“ als Voraussetzung). */
    public static function perm_partner(WP_REST_Request $req) {
        if (self::multi() || !class_exists('TIX_Partners')) return new WP_Error('tix_partner_disabled', 'Diese Seite ist keine Quelle.', ['status' => 403]);
        $id  = strtolower(trim((string) $req->get_header('X-Tix-Partner-Id')));
        $key = (string) $req->get_header('X-Tix-Partner-Key');
        $exp = (string) tix_get_settings('partner_api_key');
        if (strlen($exp) < 20 || $key === '' || !hash_equals($exp, $key) || $id === '' || $id !== TIX_Partners::source_platform_id()) {
            return new WP_Error('tix_partner_auth', 'Ungültige Partner-Zugangsdaten.', ['status' => 401]);
        }
        $rk = 'tix_srcsales_rl_' . md5($id);
        $n = intval(get_transient($rk)) + 1;
        set_transient($rk, $n, 300);
        if ($n > 300) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen. Bitte kurz warten.', ['status' => 429]);
        wp_set_current_user(0);
        return true;
    }

    /** Geteiltes Event dieser Seite? */
    public static function shared($eid) {
        return get_post_type($eid) === 'event' && (get_post_meta($eid, '_tix_syndicate', true) === '1' || intval(get_post_meta($eid, '_tix_syndicate_remote_id', true)) > 0);
    }

    /** Kennzahlen eines Events ohne Käuferdaten (gleiche Rechnung wie /events/{id}/statistics). */
    public static function figures($eid) {
        $admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        $prev = get_current_user_id();
        wp_set_current_user($admin ? intval($admin[0]) : 0); // Statistik nur für Inhaber/Admins berechnet (Zusatzfelder)
        try {
            $r = new WP_REST_Request('GET', '/' . self::NS . '/events/' . intval($eid) . '/statistics');
            $r->set_url_params(['id' => intval($eid)]);
            $res = TIX_REST_API::event_statistics($r);
        } finally {
            wp_set_current_user($prev);
        }
        if (is_wp_error($res)) return $res;
        $all = (array) (rest_ensure_response($res)->get_data()['statistics'] ?? []);
        $out = [];
        foreach (self::FIELDS as $k) if (array_key_exists($k, $all)) $out[$k] = $all[$k];
        return $out;
    }

    public static function rest_source(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if (!self::shared($eid)) return new WP_Error('tix_event', 'Kein geteiltes Event dieser Seite.', ['status' => 404]);
        if (!self::enabled()) return new WP_Error('tix_sales_hidden', 'Diese Seite teilt ihre Verkaufszahlen nicht mit der Plattform.', ['status' => 403]);
        $f = self::figures($eid);
        if (is_wp_error($f)) return $f;
        $site = class_exists('TIX_Co_Legal') ? TIX_Co_Legal::site() : [];
        $r = rest_ensure_response(['ok' => true, 'event_id' => $eid, 'site' => ['name' => (string) (tix_get_settings('syndication_site_name') ?: html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8')),
            'host' => (string) ($site['host'] ?? '')], 'statistics' => $f, 'generated_at' => gmdate('c')]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    // ═══════════════ Plattform ═══════════════

    public static function perm_admin(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]);
        if (!current_user_can('manage_options')) return new WP_Error('rest_forbidden', 'Nur für Plattform-Admins.', ['status' => 403]);
        return true;
    }

    /** Spiegel → {ok, available, …} (siehe Kopf). */
    public static function for_mirror($eid, $fresh = false) {
        $eid = intval($eid);
        $p = class_exists('TIX_Partners') ? TIX_Partners::get((string) get_post_meta($eid, '_tix_source_partner', true)) : null;
        $name = $p ? (string) $p['name'] : (string) get_post_meta($eid, '_tix_source_site', true);
        $host = $p ? strtolower(preg_replace('/^www\./', '', (string) wp_parse_url((string) ($p['api_base'] ?? ''), PHP_URL_HOST))) : '';
        $site = ['name' => $name !== '' ? $name : null, 'host' => $host !== '' ? $host : null];
        $sid = intval(get_post_meta($eid, '_tix_source_id', true));
        if (!$p || empty($p['api_base']) || strlen((string) ($p['key_out'] ?? '')) < 20 || !$sid) return ['ok' => true, 'available' => false, 'reason' => 'no_partner', 'site' => $site];
        $ck = 'tix_srcsales_' . $eid;
        if (!$fresh) { $c = get_transient($ck); if (is_array($c)) return $c; }
        $resp = wp_remote_get(untrailingslashit($p['api_base']) . '/partner/events/' . $sid . '/sales', ['timeout' => 15,
            'headers' => ['Accept' => 'application/json', 'X-Tix-Partner-Id' => TIX_Partners::platform_id(), 'X-Tix-Partner-Key' => (string) $p['key_out']]]);
        $code = is_wp_error($resp) ? 0 : intval(wp_remote_retrieve_response_code($resp));
        $d = is_wp_error($resp) ? null : json_decode((string) wp_remote_retrieve_body($resp), true);
        if ($code === 200 && is_array($d) && is_array($d['statistics'] ?? null)) {
            if (!empty($d['site']['name'])) $site['name'] = (string) $d['site']['name'];
            if (!empty($d['site']['host'])) $site['host'] = (string) $d['site']['host'];
            $out = ['ok' => true, 'available' => true, 'site' => $site, 'statistics' => $d['statistics'], 'fetched_at' => gmdate('c')];
        } else {
            $err = is_array($d) ? (string) ($d['code'] ?? '') : '';
            $reason = $err === 'tix_sales_hidden' ? 'hidden' : ($code === 404 && $err === 'rest_no_route' ? 'unsupported' : 'unreachable');
            $out = ['ok' => true, 'available' => false, 'reason' => $reason, 'site' => $site];
        }
        set_transient($ck, $out, self::CACHE);
        return $out;
    }

    public static function rest_platform(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if (!self::multi()) return new WP_Error('tix_not_multi', 'Nur auf der Plattform.', ['status' => 404]);
        if (get_post_type($eid) !== 'event') return new WP_Error('tix_event', 'Event nicht gefunden.', ['status' => 404]);
        if (get_post_meta($eid, '_tix_syndicated', true) !== '1') return new WP_Error('tix_not_mirror', 'Das Event stammt nicht von einer anderen Seite.', ['status' => 409]);
        $r = rest_ensure_response(self::for_mirror($eid, filter_var($req->get_param('fresh'), FILTER_VALIDATE_BOOLEAN)));
        $r->header('Cache-Control', 'no-store');
        return $r;
    }
}

TIX_Source_Sales::init();
