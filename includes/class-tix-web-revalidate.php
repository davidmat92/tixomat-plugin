<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Web_Revalidate – evendis.de (Next.js) nach Änderungen sofort neu aufbauen lassen (Vertrag evendis-web 64539c0).
 *
 * POST <URL> mit Header x-revalidate-secret und {"organizer":"<slug>","events":["<slug>",…]} (Slugs nur [a-z0-9-],
 * höchstens 50 Events). URL: Konstante TIX_WEB_REVALIDATE_URL bzw. Option tix_web_revalidate_url, Standard
 * <Kunden-Adresse>/api/revalidate/ (evendis: https://evendis.de/api/revalidate/ – Next.js läuft auf einem anderen
 * Server als WordPress). Schlüssel: Konstante TIX_WEB_REVALIDATE_SECRET bzw. Option tix_web_revalidate_secret –
 * ohne Schlüssel (oder ohne Kunden-Adresse) passiert nichts.
 * Aufruf nur über IPv4 (CURLOPT_IPRESOLVE_V4) – die Weiche auf evendis.de lässt nur den WordPress-Server durch.
 * Käufe lösen nichts aus (Ticket-Kategorien werden dabei nur gezählt); „Ausverkauft“ (_tix_status) schon.
 *
 * Gesammelt wird je Aufruf (Speichern im WP-Admin, „Meine Landingpage“, POST /organizer/profile, App-Editor,
 * Veröffentlichen/Absagen/Löschen eines Events), verschickt einmal beim Beenden des Requests, nicht blockierend
 * (Zeitlimit 2 s). Fehler landen nur im Server-Log; der letzte Aufruf steht in der Option tix_web_revalidate_last.
 */
class TIX_Web_Revalidate {

    const MAX_EVENTS  = 50;
    /** Event-Meta, deren Änderung die Seite betrifft (Präfixe). */
    const EVENT_META  = ['_tix_info_', '_tix_date_', '_tix_time_', '_tix_status', '_thumbnail_id', '_tix_lineup_links', '_tix_location', '_tix_organizer_id', '_tix_price_range'];

    private static $site = false;  // Einzel-Seite: Homepage (marken-web) dieses Hosts neu laden (1.38.732)
    private static $orgs = [];
    private static $domains = [];
    private static $events = [];   // event_id => [slug, organizer_id]
    private static $hooked = false;

    public static function secret() {
        $s = defined('TIX_WEB_REVALIDATE_SECRET') ? (string) TIX_WEB_REVALIDATE_SECRET : (string) get_option('tix_web_revalidate_secret', '');
        return trim($s);
    }

    public static function url() {
        $u = defined('TIX_WEB_REVALIDATE_URL') ? (string) TIX_WEB_REVALIDATE_URL : (string) get_option('tix_web_revalidate_url', '');
        if (trim($u) !== '') return trim($u);
        $base = class_exists('TIX_Customer_URL') ? (string) TIX_Customer_URL::base() : '';
        return $base !== '' ? untrailingslashit($base) . '/api/revalidate/' : '';
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    /** Aktiv? evendis (Mehr-Veranstalter): nur mit Schlüssel (evendis-web). Einzel-Seiten: immer (marken-web lädt auch ohne Schlüssel, 1.38.732). */
    public static function active() {
        return self::multi() ? self::secret() !== '' : true;
    }

    /** Host der Homepage einer Einzel-Seite: Kunden-Adresse, sonst die WordPress-Adresse. */
    public static function site_host() {
        $b = class_exists('TIX_Customer_URL') ? (string) TIX_Customer_URL::base() : '';
        if ($b === '') $b = (string) get_option('home');
        return strtolower(preg_replace('/^www\./', '', (string) wp_parse_url($b, PHP_URL_HOST)));
    }

    /** Ziel auf Einzel-Seiten: Option/Konstante, sonst https://<host>/api/revalidate/ (Schrägstrich am Ende, sonst 308). */
    public static function site_url() {
        $u = defined('TIX_WEB_REVALIDATE_URL') ? (string) TIX_WEB_REVALIDATE_URL : (string) get_option('tix_web_revalidate_url', '');
        if (trim($u) !== '' && strpos($u, '://neu.') === false) return trailingslashit(trim($u)); // alte Anweisung „neu.<domain>“ ist überholt
        $h = self::site_host();
        return $h !== '' ? 'https://' . $h . '/api/revalidate/' : '';
    }

    /** Homepage dieser Seite neu laden (Marke, Logo, Website, Inhalte …); ein Aufruf am Ende der Anfrage. */
    public static function touch_site() {
        if (self::multi()) return;
        self::$site = true;
        self::hook();
    }

    /** Routen, deren erfolgreiches Speichern die Homepage betrifft (Einzel-Seiten). */
    const SITE_ROUTES = '#^/tixomat/v1/(studio/(config|site-config|placeholder|legal|club|domain|mails|features|sponsors(/logo)?)|app/config|organizer/profile(/image)?|partner/evendis-page(/image)?|events(/\d+)?(/.*)?)$#';

    public static function init() {
        add_action('save_post_tix_organizer', function ($id) { TIX_Web_Revalidate::touch_org($id); }, 99);
        add_action('save_post_event', function ($id) { TIX_Web_Revalidate::touch_event($id); }, 99);
        add_action('transition_post_status', function ($new, $old, $post) {
            if ($post && $post->post_type === 'event' && $new !== $old) TIX_Web_Revalidate::touch_event($post->ID);
        }, 99, 3);
        // Vor dem Papierkorb/Löschen: Slug ist danach verändert bzw. weg
        add_action('wp_trash_post', function ($id) { if (get_post_type($id) === 'event') TIX_Web_Revalidate::touch_event($id); });
        add_action('before_delete_post', function ($id) { if (get_post_type($id) === 'event') TIX_Web_Revalidate::touch_event($id); });
        add_action('added_post_meta', [__CLASS__, 'on_meta'], 99, 3);
        add_action('updated_post_meta', [__CLASS__, 'on_meta'], 99, 3);
        add_action('deleted_post_meta', [__CLASS__, 'on_meta'], 99, 3);
        // Einzel-Seite: Marke, Logo, Homepage-Einstellungen, App-Inhalte – auch über WP-CLI (Kunden-Dienst „marke“) (1.38.732)
        foreach (['tix_studio_brand', 'tix_site_config', '_tix_app_config', 'tix_customer_base_url', 'tix_website_enabled', 'blogname', 'site_icon'] as $o) {
            add_action('update_option_' . $o, [__CLASS__, 'touch_site']);
            add_action('add_option_' . $o, [__CLASS__, 'touch_site']);
        }
        // App/Web speichern über REST (auch wenn nur Ticket-Kategorien/Preise geändert wurden)
        add_filter('rest_request_after_callbacks', [__CLASS__, 'after_rest'], 99, 3);
    }

    public static function after_rest($response, $handler, $request) {
        if (!self::active() || is_wp_error($response) || !in_array($request->get_method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return $response;
        $route = (string) $request->get_route();
        if (!self::multi()) {
            $status = $response instanceof WP_REST_Response ? $response->get_status() : 200;
            if ($status < 400) {
                // Website: nur Veröffentlichen bzw. Entwurf verwerfen wirkt live
                if ($route === '/tixomat/v1/studio/website') { $b = $request->get_json_params(); $b = is_array($b) ? $b : (array) $request->get_body_params(); if (!empty($b['publish']) || array_key_exists('domain', $b)) self::touch_site(); }
                elseif (preg_match(self::SITE_ROUTES, $route)) self::touch_site();
            }
        }
        if (preg_match('#^/tixomat/v1/events/(\d+)$#', $route, $m)) self::touch_event(intval($m[1]));
        elseif (preg_match('#^/tixomat/v1/organizer/profile(/image)?$#', $route) && class_exists('TIX_App_Scope')) {
            $oid = intval(TIX_App_Scope::organizer_id_for_user());
            if ($oid) self::touch_org($oid);
        }
        return $response;
    }

    public static function on_meta($meta_id, $post_id, $key) {
        $type = get_post_type($post_id);
        if ($type === 'tix_organizer') { self::touch_org($post_id); return; }
        if ($type !== 'event') return;
        foreach (self::EVENT_META as $p) {
            if (strpos((string) $key, $p) === 0) { self::touch_event($post_id); return; }
        }
    }

    private static function clean_slug($s) {
        $s = strtolower(rawurldecode((string) $s));
        $s = preg_replace('/__trashed(-\d+)?$/', '', $s);
        return preg_match('/^[a-z0-9-]+$/', $s) ? $s : '';
    }

    private static function hook() {
        if (self::$hooked) return;
        self::$hooked = true;
        add_action('shutdown', [__CLASS__, 'flush'], 5);
    }

    public static function touch_org($oid) {
        if (!self::active() || get_post_type($oid) !== 'tix_organizer') return;
        self::$orgs[intval($oid)] = true;
        self::hook();
    }

    /** Eigene Domain angelegt/geändert/abgeschaltet → {domains: [host]} (TIX_Org_Domains). */
    public static function touch_domain($host) {
        if (!self::active()) return;
        $h = strtolower(trim((string) $host));
        if ($h === '' || !preg_match('/^[a-z0-9.-]+$/', $h)) return;
        self::$domains[$h] = true;
        self::hook();
    }

    public static function touch_event($eid) {
        if (!self::active() || get_post_type($eid) !== 'event') return;
        $p = get_post($eid);
        $slug = $p ? self::clean_slug($p->post_name) : '';
        $oid = intval(get_post_meta($eid, '_tix_organizer_id', true));
        $prev = self::$events[intval($eid)] ?? null;
        self::$events[intval($eid)] = [$slug !== '' ? $slug : ($prev[0] ?? ''), $oid ?: ($prev[1] ?? 0)];
        self::hook();
    }

    private static function org_slug($oid) {
        $s = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::slug($oid) : (string) get_post_field('post_name', $oid);
        return self::clean_slug($s);
    }

    /** Slugs der kommenden, veröffentlichten Events eines Veranstalters. */
    private static function upcoming_slugs($oid) {
        $ids = get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => self::MAX_EVENTS, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [['key' => '_tix_organizer_id', 'value' => (string) intval($oid)], ['key' => '_tix_date_start', 'value' => current_time('Y-m-d'), 'compare' => '>=', 'type' => 'DATE']]]);
        $out = [];
        foreach ($ids as $id) { $s = self::clean_slug(get_post_field('post_name', $id)); if ($s !== '') $out[] = $s; }
        return $out;
    }

    /** Aufträge bauen: je Veranstalter einer (mit seinen kommenden bzw. geänderten Events), Events ohne Veranstalter gesammelt. */
    public static function payloads() {
        $by_org = [];
        foreach (array_keys(self::$orgs) as $oid) $by_org[$oid] = self::upcoming_slugs($oid);
        $loose = [];
        foreach (self::$events as $e) {
            list($slug, $oid) = $e;
            if ($oid && get_post_type($oid) === 'tix_organizer') {
                if (!isset($by_org[$oid])) $by_org[$oid] = [];
                if ($slug !== '') $by_org[$oid][] = $slug;
            } elseif ($slug !== '') {
                $loose[] = $slug;
            }
        }
        $out = [];
        foreach ($by_org as $oid => $events) {
            $p = [];
            $os = self::org_slug($oid);
            if ($os !== '') $p['organizer'] = $os;
            $events = array_values(array_unique($events));
            sort($events);
            $events = array_slice($events, 0, self::MAX_EVENTS);
            if ($events) $p['events'] = $events;
            if ($p) $out[] = $p;
        }
        foreach (array_chunk(array_values(array_unique($loose)), self::MAX_EVENTS) as $chunk) $out[] = ['events' => $chunk];
        if (self::$domains) { $d = array_keys(self::$domains); sort($d); $out[] = ['domains' => $d]; }
        return $out;
    }

    /** Beim Beenden des Requests: einmal je Veranstalter senden (evendis) bzw. einmal die Homepage dieses Hosts (Einzel-Seite). */
    public static function flush() {
        if (!self::multi()) { self::flush_site(); return; }
        $secret = self::secret();
        if ($secret === '' || self::url() === '' || (!self::$orgs && !self::$events && !self::$domains)) return;
        $payloads = self::payloads();
        self::$orgs = []; self::$events = []; self::$domains = [];
        $sent = [];
        // Die Weiche auf evendis.de lässt nur die IPv4-Adresse des WordPress-Servers durch
        $v4 = function ($handle) { if (defined('CURLOPT_IPRESOLVE')) curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); };
        add_action('http_api_curl', $v4);
        foreach ($payloads as $p) {
            $args = apply_filters('tix_web_revalidate_args', [
                'timeout' => 2, 'blocking' => false, 'redirection' => 0,
                'headers' => ['x-revalidate-secret' => $secret, 'Content-Type' => 'application/json'],
                'body'    => wp_json_encode($p),
            ], $p);
            $res = wp_remote_post(self::url(), $args);
            $err = is_wp_error($res) ? $res->get_error_message() : '';
            if ($err !== '') error_log('[tixomat] Website-Aktualisierung fehlgeschlagen: ' . $err);
            $sent[] = ['payload' => $p, 'error' => $err, 'code' => (!$err && !empty($args['blocking'])) ? intval(wp_remote_retrieve_response_code($res)) : null];
        }
        remove_action('http_api_curl', $v4);
        update_option('tix_web_revalidate_last', ['time' => current_time('mysql'), 'requests' => $sent], false);
    }

    /**
     * Einzel-Seite (1.38.732, Vertrag uebergaben/auftrag-revalidate-plugin-2026-11-10.md): POST https://<host>/api/revalidate/
     * {"domains":["<host>"]}, Schlüssel nur wenn gesetzt. Jede Änderung an Marke, Website, Events, Inhalten löst genau einen Aufruf aus.
     */
    public static function flush_site() {
        $any = self::$site || self::$orgs || self::$events || self::$domains;
        $extra = array_keys(self::$domains);
        self::$site = false; self::$orgs = []; self::$events = []; self::$domains = [];
        if (!$any) return;
        $url = self::site_url();
        $host = self::site_host();
        if ($url === '' || $host === '') return;
        $domains = array_values(array_unique(array_filter(array_merge([$host], $extra))));
        $headers = ['Content-Type' => 'application/json'];
        if (self::secret() !== '') $headers['x-revalidate-secret'] = self::secret();
        $v4 = function ($handle) { if (defined('CURLOPT_IPRESOLVE')) curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); };
        add_action('http_api_curl', $v4);
        $args = apply_filters('tix_web_revalidate_args', ['timeout' => 2, 'blocking' => false, 'redirection' => 0, 'headers' => $headers, 'body' => wp_json_encode(['domains' => $domains])], ['domains' => $domains]);
        $res = wp_remote_post($url, $args);
        remove_action('http_api_curl', $v4);
        $err = is_wp_error($res) ? $res->get_error_message() : '';
        if ($err !== '') error_log('[tixomat] Homepage-Aktualisierung fehlgeschlagen: ' . $err);
        update_option('tix_web_revalidate_last', ['time' => current_time('mysql'), 'requests' => [['url' => $url, 'payload' => ['domains' => $domains], 'secret' => self::secret() !== '', 'error' => $err,
            'code' => (!$err && !empty($args['blocking'])) ? intval(wp_remote_retrieve_response_code($res)) : null]]], false);
    }
}
