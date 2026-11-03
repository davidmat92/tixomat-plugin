<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Matomo – Matomo auf stats.evendis.de bzw. eigenes Matomo des Veranstalters (Statistik Stufe 3a, Plan §4.3, §12.1, F2/F3).
 *
 *  - Instanz: Option tix_matomo (setzt seiten.sh per WP-CLI, Schnittstelle in ops/stats-hetzner/README.md):
 *      evendis      {url, id_site, login: "tix-provision", token, password} – Superuser, Token nur per POST (secureOnly);
 *      KK/Mallorca  {url, id_site, reader: "lesen-<seite>", token}         – liest nur die eigene Site.
 *    Klartext-Token/-Passwort werden beim ersten Lesen verschlüsselt. id_site ist die Site der Seite selbst (Gruppe <seite>:seite).
 *  - Veranstalter (nur evendis, nur mit Superuser): POST /marketing/matomo/activate sucht die Site der Gruppe
 *    evendis:org-<id> (SitesManager.getSitesFromGroup) und legt sie sonst an (addSite, E-Commerce, Europe/Berlin, EUR,
 *    excludeUnknownUrls). Eigenes Matomo optional: {own_url, own_site_id, own_token}.
 *  - Browser: nur mit Einwilligung „Statistik“ (F3 A) – die Website lädt matomo.js mit {url, site_id} aus site-config
 *    tracking.matomo bzw. GET /public/tracking?organizer= (evendis: zusätzlicher Tracker für die Seiten des Veranstalters).
 *  - Studio: GET /marketing/matomo/stats?from&to – Besuche, Aktionen, Absprung, Verweildauer, Quellen-Typen, Geräte, Länder,
 *    E-Commerce; 1 h zwischengespeichert, Token nur serverseitig. Gerechnet wird nichts, es sind Matomos Zahlen.
 *  - Kauf: Tracking API (E-Commerce-Bestellung, ec_id = „tix_<Bestellnummer>“) nur mit consent_analytics und Matomo-Besucher-ID.
 *  - Unsere Instanz gehört zum Modul Marketing; ein eigenes Matomo des Veranstalters (nur Browser) ist frei.
 */
class TIX_Matomo {

    const NS = 'tixomat/v1';
    const OPT = 'tix_matomo';
    const OPT_SITE = 'tix_matomo_site';
    const META_SITE = '_tix_matomo_site';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('tix_order_completed', [__CLASS__, 'on_paid'], 21);
        add_action('tix_matomo_order', [__CLASS__, 'send_order'], 10, 2);
    }

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }

    /** Instanz {url, token, id_site, superuser} oder null */
    public static function instance() {
        $o = get_option(self::OPT, []);
        if (!is_array($o) || empty($o['url'])) return null;
        $dirty = false;
        foreach (['token', 'password'] as $k) {
            if (isset($o[$k]) && $o[$k] !== '') { $o[$k . '_enc'] = TIX_Ad_Accounts::enc((string) $o[$k]); unset($o[$k]); $dirty = true; }
        }
        if ($dirty) update_option(self::OPT, $o, false);
        $t = TIX_Ad_Accounts::dec($o['token_enc'] ?? '');
        $u = rtrim((string) $o['url'], '/');
        if ($t === '' || !preg_match('#^https://#', $u)) return null;
        return ['url' => $u, 'token' => $t, 'id_site' => intval($o['id_site'] ?? 0), 'superuser' => (string) ($o['login'] ?? '') !== ''];
    }

    /** Gruppe der Veranstalter-Site in Matomo (Mandanten-Schlüssel, nur evendis) */
    public static function group($org) { return 'evendis:org-' . intval($org); }

    public static function site($org) {
        $v = $org ? get_post_meta(intval($org), self::META_SITE, true) : get_option(self::OPT_SITE, []);
        $v = is_array($v) ? $v : [];
        // Site der Seite selbst kommt aus der Instanz (seiten.sh), nicht aus dem Studio
        if (!$org && empty($v['site_id'])) { $i = self::instance(); if ($i && $i['id_site'] > 0) $v['site_id'] = $i['id_site']; }
        return $v;
    }
    private static function save_site($org, array $v) { if ($org) update_post_meta(intval($org), self::META_SITE, $v); else update_option(self::OPT_SITE, $v, false); }

    /** Verbindung für Reporting/Kauf: unsere Instanz (Modul) oder eigenes Matomo mit Token */
    public static function conn($org) {
        $s = self::site($org);
        if (!empty($s['own_url']) && !empty($s['own_site_id'])) {
            $t = TIX_Ad_Accounts::dec($s['own_token_enc'] ?? '');
            return ['url' => $s['own_url'], 'site_id' => intval($s['own_site_id']), 'token' => $t, 'own' => true];
        }
        $i = self::instance();
        if ($i && !empty($s['site_id']) && TIX_Ad_Accounts::module_on($org)) return ['url' => $i['url'], 'site_id' => intval($s['site_id']), 'token' => $i['token'], 'own' => false];
        return null;
    }

    /** Für die Website (nur IDs) */
    public static function browser($org) {
        $c = self::conn($org);
        return $c ? ['url' => $c['url'], 'site_id' => $c['site_id']] : null;
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $p = ['TIX_Ad_Accounts', 'perm'];
        register_rest_route(self::NS, '/marketing/matomo', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $p],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $p],
        ]);
        register_rest_route(self::NS, '/marketing/matomo/activate', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_activate'], 'permission_callback' => ['TIX_Ad_Accounts', 'perm_module']]);
        register_rest_route(self::NS, '/marketing/matomo/stats', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_stats'], 'permission_callback' => $p]);
        register_rest_route(self::NS, '/public/tracking', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_public'], 'permission_callback' => '__return_true']);
    }

    public static function rest_get(WP_REST_Request $req) {
        $org = TIX_Ad_Accounts::org($req);
        $s = self::site($org);
        $c = self::conn($org);
        return rest_ensure_response(['ok' => true, 'instance' => self::instance() !== null, 'module_on' => TIX_Ad_Accounts::module_on($org),
            'site_id' => intval($s['site_id'] ?? 0) ?: null, 'own' => !empty($s['own_url']) ? ['url' => (string) $s['own_url'], 'site_id' => intval($s['own_site_id'] ?? 0), 'token_set' => !empty($s['own_token_enc'])] : null,
            'active' => $c !== null, 'url' => $c['url'] ?? null]);
    }

    /** POST /marketing/matomo {own_url, own_site_id, own_token} – eigenes Matomo; own_url '' = entfernen */
    public static function rest_save(WP_REST_Request $req) {
        $org = TIX_Ad_Accounts::org($req);
        $s = self::site($org);
        if ($req->has_param('own_url')) {
            $u = rtrim(trim((string) $req->get_param('own_url')), '/');
            if ($u === '') { unset($s['own_url'], $s['own_site_id'], $s['own_token_enc']); }
            else {
                if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#i', $u)) return new WP_Error('tix_invalid_field', 'Bitte die Adresse deines Matomo mit https:// angeben.', ['status' => 400, 'field' => 'own_url']);
                $sid = intval($req->get_param('own_site_id'));
                if ($sid < 1) return new WP_Error('tix_invalid_field', 'Bitte die Website-ID aus Matomo angeben.', ['status' => 400, 'field' => 'own_site_id']);
                $s['own_url'] = $u; $s['own_site_id'] = $sid;
                $tk = trim((string) $req->get_param('own_token'));
                if ($tk !== '') { if (!preg_match('/^[a-f0-9]{32}$/', $tk)) return new WP_Error('tix_invalid_field', 'Das Matomo-Token hat 32 Zeichen (Persönlich → Sicherheit → Auth-Token).', ['status' => 400, 'field' => 'own_token']); $s['own_token_enc'] = TIX_Ad_Accounts::enc($tk); }
            }
        }
        self::save_site($org, $s);
        return self::rest_get($req);
    }

    /** POST /marketing/matomo/activate – Site einschalten: Seite = id_site aus der Instanz; Veranstalter (evendis) = Site der Gruppe finden oder anlegen */
    public static function rest_activate(WP_REST_Request $req) {
        $org = TIX_Ad_Accounts::org($req);
        $i = self::instance();
        if (!$i) return new WP_Error('tix_matomo_missing', 'Matomo ist für diese Seite noch nicht eingerichtet.', ['status' => 503]);
        $s = self::site($org);
        if (empty($s['site_id'])) {
            if (!$org) return new WP_Error('tix_matomo_missing', 'Matomo ist für diese Seite noch nicht eingerichtet.', ['status' => 503]);
            if (!$i['superuser']) return new WP_Error('tix_matomo_forbidden', 'Eigene Matomo-Websites für Veranstalter gibt es nur auf evendis.', ['status' => 403]);
            $sid = 0;
            $found = self::api($i, 'SitesManager.getSitesFromGroup', ['group' => self::group($org)]);
            if (is_wp_error($found)) return $found;
            foreach ((array) $found as $x) if (is_array($x) && intval($x['idsite'] ?? 0) > 0) { $sid = intval($x['idsite']); break; }
            if (!$sid) {
                $name = html_entity_decode(get_the_title($org), ENT_QUOTES, 'UTF-8');
                $urls = [home_url('/')];
                if (class_exists('TIX_Public_Platform') && method_exists('TIX_Public_Platform', 'slug')) $urls[] = home_url('/v/' . TIX_Public_Platform::slug($org) . '/');
                $r = self::api($i, 'SitesManager.addSite', ['siteName' => mb_substr($name ?: 'Veranstalter', 0, 90), 'urls' => $urls, 'ecommerce' => 1, 'timezone' => 'Europe/Berlin', 'currency' => 'EUR', 'group' => self::group($org), 'excludeUnknownUrls' => 1]);
                if (is_wp_error($r)) return $r;
                $sid = is_array($r) ? intval($r['value'] ?? 0) : intval($r);
            }
            if ($sid < 1) return new WP_Error('tix_matomo_error', 'Matomo hat keine Website angelegt.', ['status' => 502]);
            $s['site_id'] = $sid;
            self::save_site($org, $s);
        }
        return self::rest_get($req);
    }

    /** GET /public/tracking?organizer=<id|slug> → {matomo: {url, site_id}|null} (evendis: Seiten des Veranstalters) */
    public static function rest_public(WP_REST_Request $req) {
        $org = class_exists('TIX_Features') ? TIX_Features::org_from_param((string) $req->get_param('organizer')) : 0;
        $res = rest_ensure_response(['matomo' => $org ? self::browser($org) : self::browser(0)]);
        $res->header('Cache-Control', 'public, max-age=300');
        return $res;
    }

    /** GET /marketing/matomo/stats?from&to – Matomos Zahlen (1 h Cache) */
    public static function rest_stats(WP_REST_Request $req) {
        $org = TIX_Ad_Accounts::org($req);
        $c = self::conn($org);
        if (!$c || $c['token'] === '') return rest_ensure_response(['ok' => true, 'available' => false]);
        $to = (string) ($req->get_param('to') ?: current_time('Y-m-d'));
        $from = (string) ($req->get_param('from') ?: gmdate('Y-m-d', strtotime($to) - 29 * DAY_IN_SECONDS));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) return new WP_Error('tix_invalid_field', 'Bitte einen gültigen Zeitraum angeben.', ['status' => 400, 'field' => 'from']);
        $k = 'tix_matomo_stats_' . md5($c['url'] . '|' . $c['site_id'] . "|$from|$to");
        $cached = get_transient($k);
        if (is_array($cached)) return rest_ensure_response($cached);
        $q = ['idSite' => $c['site_id'], 'period' => 'range', 'date' => "$from,$to"];
        $sum = self::api($c, 'VisitsSummary.get', $q);
        if (is_wp_error($sum)) return rest_ensure_response(['ok' => true, 'available' => true, 'error' => $sum->get_error_message()]);
        $top = function ($m, $label) use ($c, $q) {
            $r = self::api($c, $m, $q + ['filter_limit' => 6, 'flat' => 1]);
            if (is_wp_error($r) || !is_array($r)) return [];
            return array_values(array_map(function ($x) use ($label) { return ['label' => (string) ($x['label'] ?? ''), 'visits' => intval($x['nb_visits'] ?? 0)]; }, array_filter($r, 'is_array')));
        };
        $eco = self::api($c, 'Goals.get', $q + ['idGoal' => 'ecommerceOrder']);
        $out = ['ok' => true, 'available' => true, 'own' => $c['own'], 'url' => $c['url'] . '/index.php?module=CoreHome&action=index&idSite=' . $c['site_id'] . '&period=range&date=' . rawurlencode("$from,$to"),
            'range' => ['from' => $from, 'to' => $to],
            'summary' => ['visits' => intval($sum['nb_visits'] ?? 0), 'visitors' => intval($sum['nb_uniq_visitors'] ?? 0) ?: null, 'actions' => intval($sum['nb_actions'] ?? 0),
                'bounce_rate' => (string) ($sum['bounce_rate'] ?? ''), 'avg_time' => intval($sum['avg_time_on_site'] ?? 0), 'actions_per_visit' => floatval($sum['nb_actions_per_visit'] ?? 0)],
            'referrers' => $top('Referrers.getReferrerType', 'type'), 'devices' => $top('DevicesDetection.getType', 'type'), 'countries' => $top('UserCountry.getCountry', 'country'),
            'ecommerce' => is_array($eco) && !is_wp_error($eco) ? ['orders' => intval($eco['nb_conversions'] ?? 0), 'revenue' => round(floatval($eco['revenue'] ?? 0), 2), 'rate' => (string) ($eco['conversion_rate'] ?? '')] : null,
            'fetched' => gmdate('c')];
        set_transient($k, $out, HOUR_IN_SECONDS);
        return rest_ensure_response($out);
    }


    /** Reporting/Management-API (POST, token_auth im Body) → Array oder WP_Error */
    public static function api(array $c, $method, array $params) {
        $body = array_merge(['module' => 'API', 'method' => $method, 'format' => 'JSON', 'token_auth' => $c['token']], $params);
        if (isset($body['urls']) && is_array($body['urls'])) { foreach ($body['urls'] as $n => $u) $body["urls[$n]"] = $u; unset($body['urls']); }
        $r = wp_remote_post($c['url'] . '/index.php', ['timeout' => 20, 'body' => $body]);
        if (is_wp_error($r)) return new WP_Error('tix_matomo_down', 'Matomo ist gerade nicht erreichbar.', ['status' => 502]);
        $j = json_decode((string) wp_remote_retrieve_body($r), true);
        if (wp_remote_retrieve_response_code($r) !== 200 || (is_array($j) && ($j['result'] ?? '') === 'error')) return new WP_Error('tix_matomo_error', mb_substr((string) ($j['message'] ?? 'Matomo-Fehler'), 0, 200), ['status' => 502]);
        return $j;
    }

    // ═══════════════ Kauf ═══════════════

    public static function on_paid($order_id) {
        foreach (class_exists('TIX_Conversions') ? TIX_Conversions::scopes(intval($order_id)) : [0] as $org) {
            if (self::conn($org)) wp_schedule_single_event(time(), 'tix_matomo_order', [intval($order_id), $org]);
        }
    }

    public static function send_order($order_id, $org) {
        global $wpdb;
        $c = self::conn($org);
        if (!$c) return;
        $a = TIX_Order_Attribution::get($order_id) ?: [];
        $an = json_decode((string) ($a['analytics_ids'] ?? ''), true) ?: [];
        if ((string) ($a['consent_analytics'] ?? '') !== '1' || empty($an['pk_vid'])) return;
        $o = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Order::table_name() . " WHERE id = %d", $order_id), ARRAY_A);
        if (!$o || !in_array($o['status'], ['completed', 'processing'], true)) return;
        $items = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT event_id, name, cat_name, quantity, total FROM " . TIX_Order::items_table_name() . " WHERE order_id = %d", $order_id), ARRAY_A) as $i) {
            $q = max(1, intval($i['quantity']));
            $items[] = [(string) intval($i['event_id']), mb_substr((string) ($i['cat_name'] ?: $i['name']), 0, 80), 'Tickets', round(floatval($i['total']) / $q, 2), $q];
        }
        $eid = TIX_Conversions::event_id($o);
        $q = ['idsite' => $c['site_id'], 'rec' => 1, 'apiv' => 1, '_id' => $an['pk_vid'], 'idgoal' => 0, 'ec_id' => $eid, 'revenue' => round(floatval($o['total']), 2), 'ec_items' => wp_json_encode($items),
            'url' => home_url('/kasse/status/'), 'send_image' => 0];
        $r = wp_remote_post($c['url'] . '/matomo.php', ['timeout' => 10, 'body' => $q]);
        $code = is_wp_error($r) ? 0 : intval(wp_remote_retrieve_response_code($r));
        if (class_exists('TIX_Conversions')) {
            TIX_Conversions::log_public($org, 'matomo', $order_id, $eid, $code >= 200 && $code < 300 ? 'sent' : 'failed', $code, $code >= 200 && $code < 300 ? 'Angenommen' : 'Nicht erreichbar');
            TIX_Conversions::forget_if_done($order_id);
        }
    }
}

TIX_Matomo::init();
