<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_App_Coupons – Gutscheine nur in der App einlösbar und in der App bewerben (1.38.431, alle Seiten).
 *
 * Felder am Gutschein (Option `tix_coupons`, zusätzlich zu den bisherigen):
 *   app_only     0|1   nur in der App einlösbar
 *   app_promote  0|1   in der App bewerben (Band/Liste)
 *   app_title    Titel im Band (leer = Code)
 *   app_text     kurzer Text (≤ 140 Zeichen)
 *   app_from / app_until  optionaler Zeitraum der Werbung (Y-m-d, einschließlich)
 *   app_auto     0|1   Art in der App: 1 = automatisch im Warenkorb, 0 = Code zum Eingeben
 *   organizer_id int   Mehr-Veranstalter-Modus: Gutschein eines Veranstalters (gilt nur bei dessen Events), 0 = Seite
 *
 * Kanal: App = Header `X-Tix-Client: app` (festgelegter Name, Core-Kassen-Client; auch `app/<bundle>`), ersatzweise
 * `X-Tix-Channel: app` oder User-Agent `Dart/…` (dart:io, ältere App-Builds; Browser nie). Optional `X-Tix-App:
 * <Marke>/<Version> (<ios|android>)` – wird nicht ausgewertet. Fälschbar wie jeder Header – siehe Übergabe 1.38.431.
 *
 * Außerhalb der App: Ablehnung mit Code `tix_coupon_app_only`, `data.app_url` und `data.store_url`/`play_url`.
 * app_url = Universal Link in die App-Kasse `<Kunden-Adresse>/app/kasse/<event_id>/?coupon=<CODE>` (Vertrag
 * evendis-web), wenn die Seite App-Links mit dem Baustein `kasse` hat; sonst App-Store-Link, sonst null.
 * Ohne App landet der Link im Browser: auf evendis.de zeigt Next.js eine Ausweichseite, auf WordPress-Seiten
 * diese Klasse (maybe_render_kasse: Hinweis, Store-Knöpfe, „ohne Gutschein im Web kaufen“).
 *
 * REST:
 *   GET  /public/app-coupons[?organizer=<id|slug>][&event=<id>]   beworbene Gutscheine (60 s Cache)
 *   GET  /organizer/coupons                                        Liste (eigene im Mehr-Veranstalter-Modus)
 *   POST /organizer/coupons                                        anlegen
 *   POST /organizer/coupons/{code}                                 ändern (Teil-Update)
 *   DELETE /organizer/coupons/{code}                               löschen
 */
class TIX_App_Coupons {

    const NS = 'tixomat/v1';
    const OPT_STORE = 'tix_app_store_url';
    const OPT_PLAY  = 'tix_play_store_url';
    const OPT_NAME  = 'tix_app_name';
    const TEXT_MAX  = 140;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_post_tix_app_coupon_settings', [__CLASS__, 'save_settings']);
        add_action('template_redirect', [__CLASS__, 'maybe_render_kasse'], 1);
    }

    // ── Kanal ──

    /** Kommt die Anfrage aus einer Tixomat-App? */
    public static function is_app_request() {
        $ch = strtolower(trim((string) ($_SERVER['HTTP_X_TIX_CHANNEL'] ?? ($_SERVER['HTTP_X_TIX_CLIENT'] ?? ''))));
        $app = ($ch === 'app' || strpos($ch, 'app/') === 0);
        if (!$app) {
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            $app = strpos($ua, 'Dart/') === 0;
        }
        return (bool) apply_filters('tix_is_app_request', $app);
    }

    public static function app_name() {
        $n = trim((string) get_option(self::OPT_NAME, ''));
        return $n !== '' ? $n : html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
    }

    /** Wird der Gutschein in diesem Kanal abgelehnt? */
    public static function blocks($coupon) {
        return is_array($coupon) && !empty($coupon['app_only']) && !self::is_app_request();
    }

    public static function app_only_message() {
        return sprintf('Dieser Gutschein gilt nur in der %s-App.', self::app_name());
    }

    /**
     * Prüfung für TIX_Native_Checkout::coupon_discount: '' = ok, sonst Meldung.
     * Nur-App und Gutscheine eines Veranstalters (gelten nur bei dessen Events).
     */
    public static function check($coupon, array $event_ids) {
        if (!is_array($coupon) || ($coupon['discount_type'] ?? '') === 'giftcard') return '';
        if (self::blocks($coupon)) return self::app_only_message();
        $oid = intval($coupon['organizer_id'] ?? 0);
        if ($oid > 0) {
            foreach (array_unique(array_map('intval', $event_ids)) as $eid) {
                $orgs = class_exists('TIX_App_Scope') ? TIX_App_Scope::event_org_ids($eid) : [intval(get_post_meta($eid, '_tix_organizer_id', true))];
                if (!in_array($oid, $orgs, true)) {
                    return 'Gilt nur bei ' . html_entity_decode(get_the_title($oid), ENT_QUOTES, 'UTF-8') . '.';
                }
            }
        }
        return '';
    }

    public static function store_url() {
        $u = trim((string) get_option(self::OPT_STORE, ''));
        return preg_match('#^https://#', $u) ? $u : null;
    }

    /** Link in die App-Kasse des Events (Universal Link) mit Code, sonst App-Store, sonst null. */
    public static function app_url($coupon, array $event_ids = []) {
        $code = is_array($coupon) ? (string) ($coupon['code'] ?? '') : (string) $coupon;
        $eid = $event_ids ? intval(reset($event_ids)) : 0;
        $links = class_exists('TIX_App_Links') && TIX_App_Links::ids() && in_array('kasse', TIX_App_Links::paths(), true);
        if ($eid && $links && get_post_type($eid) === 'event' && get_post_status($eid) === 'publish') {
            $url = home_url('/app/kasse/' . $eid . '/');
            if (class_exists('TIX_Customer_URL')) $url = TIX_Customer_URL::to($url);
            return add_query_arg('coupon', rawurlencode($code), $url);
        }
        return self::store_url() ?: self::play_url();
    }

    /** Ausweichseite /app/kasse/<id>/ im Browser (App nicht installiert). */
    public static function maybe_render_kasse() {
        $path = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $base = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if (!preg_match('#^' . preg_quote($base, '#') . '/app/kasse/(\d+)/?$#', $path, $m)) return;
        $eid = intval($m[1]);
        $ok = get_post_type($eid) === 'event' && get_post_status($eid) === 'publish';
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        status_header($ok ? 200 : 404);
        $title = $ok ? html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8') : 'Event nicht gefunden';
        $event = $ok ? get_permalink($eid) : home_url('/');
        if (class_exists('TIX_Customer_URL')) $event = TIX_Customer_URL::to($event);
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['coupon'] ?? '')));
        $store = self::store_url(); $play = self::play_url();
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html(self::app_name() . '-App') . '</title>'
            . '<style>body{margin:0;font-family:-apple-system,system-ui,sans-serif;background:#f6f6f8;color:#14141c}main{max-width:460px;margin:0 auto;padding:40px 20px;text-align:center}h1{font-size:22px;margin:0 0 10px}p{color:#55556a;line-height:1.5}.c{display:inline-block;font:700 18px/1 monospace;letter-spacing:2px;background:#fff;border:2px dashed #c9c9d6;border-radius:12px;padding:12px 18px;margin:8px 0 18px}a.b{display:block;background:#14141c;color:#fff;text-decoration:none;border-radius:12px;padding:14px;margin:10px 0;font-weight:600}a.l{color:#55556a}</style></head><body><main>'
            . '<h1>' . esc_html($title) . '</h1>'
            . '<p>' . esc_html(self::app_only_message()) . ' Hol dir die App und öffne das Event dort – der Gutschein ist dann schon eingetragen.</p>'
            . ($code !== '' ? '<div class="c">' . esc_html($code) . '</div>' : '')
            . ($store ? '<a class="b" href="' . esc_url($store) . '">Im App Store laden</a>' : '')
            . ($play ? '<a class="b" href="' . esc_url($play) . '">Bei Google Play laden</a>' : '')
            . '<p><a class="l" href="' . esc_url($event) . '">Ohne Gutschein im Web kaufen</a></p>'
            . '</main></body></html>';
        exit;
    }

    /** Daten für die Ablehnung im Web (REST-Fehler / AJAX). */
    public static function web_error_data($coupon, array $event_ids = []) {
        return ['app_url' => self::app_url($coupon, $event_ids), 'store_url' => self::store_url(), 'play_url' => self::play_url()];
    }

    public static function play_url() {
        $u = trim((string) get_option(self::OPT_PLAY, ''));
        return preg_match('#^https://#', $u) ? $u : null;
    }

    /** Freundliche Meldung für eingelöste App-Gutscheine (Quote). */
    public static function applied_message($coupon) {
        if (!is_array($coupon) || (empty($coupon['app_only']) && empty($coupon['app_promote']))) return '';
        $t = trim((string) ($coupon['app_title'] ?? '')) ?: (string) ($coupon['code'] ?? '');
        return 'App-Gutschein „' . $t . '“ eingelöst.';
    }

    // ── Felder ──

    public static function defaults() {
        return ['app_only' => 0, 'app_promote' => 0, 'app_title' => '', 'app_text' => '', 'app_from' => '', 'app_until' => '', 'app_auto' => 0, 'organizer_id' => 0];
    }

    private static function date_or_empty($v) {
        $v = trim((string) $v);
        if ($v === '') return '';
        $v = substr($v, 0, 10);
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v) ? $v : false; // streng: kein 2026-13-40
    }

    /** App-Felder aus Eingaben (Admin-Formular oder REST) übernehmen; nur übergebene Schlüssel. WP_Error bei Fehler. */
    public static function apply_fields(array $c, array $in, $is_form = false) {
        foreach (['app_only', 'app_promote', 'app_auto'] as $k) {
            if (array_key_exists($k, $in) || $is_form) $c[$k] = !empty($in[$k]) && $in[$k] !== 'false' ? 1 : 0;
        }
        if (array_key_exists('app_title', $in)) $c['app_title'] = mb_substr(sanitize_text_field((string) $in['app_title']), 0, 60);
        if (array_key_exists('app_text', $in)) $c['app_text'] = mb_substr(sanitize_text_field((string) $in['app_text']), 0, self::TEXT_MAX);
        foreach (['app_from', 'app_until'] as $k) {
            if (!array_key_exists($k, $in)) continue;
            $d = self::date_or_empty($in[$k]);
            if ($d === false) return new WP_Error('tix_invalid_field', 'Bitte ein Datum im Format JJJJ-MM-TT angeben.', ['status' => 400, 'field' => $k]);
            $c[$k] = $d;
        }
        if (!empty($c['app_from']) && !empty($c['app_until']) && $c['app_until'] < $c['app_from']) {
            return new WP_Error('tix_invalid_field', 'Das Ende der Werbung liegt vor dem Anfang.', ['status' => 400, 'field' => 'app_until']);
        }
        return array_merge(self::defaults(), $c);
    }

    // ── Liste für die Apps ──

    public static function discount_label($c) {
        $v = floatval($c['value'] ?? 0);
        $num = rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
        switch ($c['discount_type'] ?? 'percent') {
            case 'fixed':              return $num . ' €';
            case 'per_ticket_fixed':   return $num . ' € je Ticket';
            case 'per_ticket_percent': return $num . ' % je Ticket';
            default:                   return $num . ' %';
        }
    }

    private static function local_ts($date, $end_of_day) {
        if (!$date) return 0;
        try {
            $d = new DateTime($date . ($end_of_day ? ' 23:59:59' : ' 00:00:00'), wp_timezone());
            return $d->getTimestamp();
        } catch (Exception $e) {
            return 0;
        }
    }

    /** Aktiver, beworbener Gutschein? (nicht abgelaufen, nicht aufgebraucht, im Werbezeitraum) */
    public static function promoted_now($c) {
        if (empty($c['app_promote']) || ($c['discount_type'] ?? '') === 'giftcard' || !empty($c['is_promoter'])) return false;
        $now = time();
        if (!empty($c['expires'])) { $ts = strtotime((string) $c['expires']); if ($ts && $ts < $now) return false; }
        $max = intval($c['max_uses'] ?? 0);
        if ($max > 0 && intval($c['used'] ?? 0) >= $max) return false;
        if (!empty($c['app_from']) && self::local_ts($c['app_from'], false) > $now) return false;
        if (!empty($c['app_until']) && self::local_ts($c['app_until'], true) < $now) return false;
        return true;
    }

    /** Ende (früheres von Ablauf und Werbe-Ende) als ISO 8601 oder null. */
    private static function ends_at($c) {
        $ts = [];
        if (!empty($c['expires']) && ($t = strtotime((string) $c['expires']))) $ts[] = $t;
        if (!empty($c['app_until'])) $ts[] = self::local_ts($c['app_until'], true);
        return $ts ? wp_date('c', min($ts)) : null;
    }

    public static function feed_item($c) {
        $ends = self::ends_at($c);
        return [
            'code'           => (string) $c['code'],
            'title'          => trim((string) ($c['app_title'] ?? '')) ?: (string) $c['code'],
            'text'           => (string) ($c['app_text'] ?? ''),
            'auto_apply'     => !empty($c['app_auto']),
            'app_only'       => !empty($c['app_only']),
            'organizer_id'   => intval($c['organizer_id'] ?? 0),
            'event_ids'      => array_values(array_map('intval', (array) ($c['allowed_events'] ?? []))),
            'discount_type'  => (string) ($c['discount_type'] ?? 'percent'),
            'value'          => round(floatval($c['value'] ?? 0), 2),
            'discount_label' => self::discount_label($c),
            'min_amount'     => round(floatval($c['min_amount'] ?? 0), 2),
            'starts_at'      => !empty($c['app_from']) ? wp_date('c', self::local_ts($c['app_from'], false)) : null,
            'ends_at'        => $ends,
            'valid_until'    => $ends,
        ];
    }

    private static function resolve_organizer($key) {
        $key = trim((string) $key);
        if ($key === '') return 0;
        if (ctype_digit($key)) return get_post_type(intval($key)) === 'tix_organizer' ? intval($key) : -1;
        $f = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_tix_org_landing_slug', 'meta_value' => $key]);
        if (!$f) $f = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'name' => sanitize_title($key)]);
        return $f ? intval($f[0]) : -1;
    }

    /** Gilt der Gutschein (laut Einschränkungen) für dieses Event? */
    private static function fits_event($c, $eid) {
        $oid = intval($c['organizer_id'] ?? 0);
        if ($oid && !in_array($oid, TIX_App_Scope::event_org_ids($eid), true)) return false;
        $allowed = array_map('intval', (array) ($c['allowed_events'] ?? []));
        if ($allowed && !in_array($eid, $allowed, true)) return false;
        if (in_array($eid, array_map('intval', (array) ($c['excluded_events'] ?? [])), true)) return false;
        $ac = array_map('intval', (array) ($c['allowed_categories'] ?? []));
        $xc = array_map('intval', (array) ($c['excluded_categories'] ?? []));
        if ($ac || $xc) {
            $terms = get_the_terms($eid, 'event_category');
            $ids = is_array($terms) ? array_map(function ($t) { return intval($t->term_id); }, $terms) : [];
            if ($ac && !array_intersect($ids, $ac)) return false;
            if ($xc && array_intersect($ids, $xc)) return false;
        }
        return true;
    }

    /** Passt der Gutschein zum Veranstalter? (eigene + Seiten-Gutscheine, die bei ihm gelten können) */
    private static function fits_organizer($c, $oid) {
        $coid = intval($c['organizer_id'] ?? 0);
        if ($coid) return $coid === $oid;
        $allowed = array_map('intval', (array) ($c['allowed_events'] ?? []));
        if (!$allowed) return true;
        foreach ($allowed as $eid) if (in_array($oid, TIX_App_Scope::event_org_ids($eid), true)) return true;
        return false;
    }

    /** GET /public/app-coupons[?organizer=][&event=] */
    public static function rest_feed(WP_REST_Request $req) {
        $org = $req->get_param('organizer') ?? $req->get_param('organizer_id');
        $ev  = absint($req->get_param('event') ?? $req->get_param('event_id'));
        $oid = self::resolve_organizer($org);
        $out = [];
        if ($oid >= 0 && (!$ev || (get_post_type($ev) === 'event' && get_post_status($ev) === 'publish'))) {
            foreach ((array) get_option('tix_coupons', []) as $k => $c) {
                if (!is_array($c)) continue;
                $c['code'] = (string) $k;
                if (!self::promoted_now($c)) continue;
                if ($oid > 0 && !self::fits_organizer($c, $oid)) continue;
                if ($ev && !self::fits_event($c, $ev)) continue;
                $out[] = self::feed_item($c);
            }
        }
        $r = new WP_REST_Response(['ok' => true, 'coupons' => $out]);
        $r->header('Cache-Control', 'public, max-age=60');
        return $r;
    }

    // ── Verwaltung (App, Veranstalter-Bereich) ──

    public static function register_routes() {
        register_rest_route(self::NS, '/public/app-coupons', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_feed'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/organizer/coupons', [
            ['methods' => 'GET',  'callback' => [__CLASS__, 'rest_list'],   'permission_callback' => [__CLASS__, 'check_staff']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_create'], 'permission_callback' => [__CLASS__, 'check_staff']],
        ]);
        register_rest_route(self::NS, '/organizer/coupons/(?P<code>[A-Za-z0-9_-]{3,40})', [
            ['methods' => 'POST',   'callback' => [__CLASS__, 'rest_update'], 'permission_callback' => [__CLASS__, 'check_staff']],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_delete'], 'permission_callback' => [__CLASS__, 'check_staff']],
        ]);
    }

    /** Admin; im Mehr-Veranstalter-Modus Inhaber/Team-Admin des Veranstalters; sonst Rolle „Veranstalter“. */
    public static function check_staff(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        if (TIX_App_Scope::is_admin()) return true;
        if (TIX_App_Scope::multi()) return TIX_App_Scope::check_manager($req);
        $u = wp_get_current_user();
        return in_array('tix_organizer', (array) $u->roles, true) ? true : TIX_App_Scope::deny('Gutscheine verwaltet der Veranstalter.');
    }

    /** Veranstalter, auf den der Nutzer eingeschränkt ist (0 = alle). */
    private static function scope_org() {
        return TIX_App_Scope::scoped() ? TIX_App_Scope::organizer_id_for_user() : 0;
    }

    private static function all() {
        wp_cache_delete('tix_coupons', 'options');
        $c = get_option('tix_coupons', []);
        return is_array($c) ? $c : [];
    }

    private static function visible($c, $oid) {
        if (!is_array($c) || ($c['discount_type'] ?? '') === 'giftcard') return false;
        return !$oid || intval($c['organizer_id'] ?? 0) === $oid;
    }

    public static function staff_item($code, $c) {
        $c = array_merge(self::defaults(), $c);
        return [
            'code'           => (string) $code,
            'discount_type'  => (string) ($c['discount_type'] ?? 'percent'),
            'value'          => round(floatval($c['value'] ?? 0), 2),
            'discount_label' => self::discount_label($c),
            'description'    => (string) ($c['description'] ?? ''),
            'expires'        => (string) ($c['expires'] ?? ''),
            'max_uses'       => intval($c['max_uses'] ?? 0),
            'used'           => intval($c['used'] ?? 0),
            'min_amount'     => round(floatval($c['min_amount'] ?? 0), 2),
            'allowed_events' => array_values(array_map('intval', (array) ($c['allowed_events'] ?? []))),
            'one_per_email'  => !empty($c['one_per_email']),
            'organizer_id'   => intval($c['organizer_id']),
            'app_only'       => !empty($c['app_only']),
            'promote_in_app' => !empty($c['app_promote']),
            'auto_apply'     => !empty($c['app_auto']),
            'title'          => (string) $c['app_title'],
            'text'           => (string) $c['app_text'],
            'promote_from'   => (string) $c['app_from'],
            'promote_until'  => (string) $c['app_until'],
            'promoted_now'   => self::promoted_now(['code' => $code] + $c),
        ];
    }

    /** GET /organizer/coupons */
    public static function rest_list(WP_REST_Request $req) {
        $oid = self::scope_org();
        $out = [];
        foreach (self::all() as $k => $c) if (self::visible($c, $oid)) $out[] = self::staff_item($k, $c);
        $r = rest_ensure_response(['ok' => true, 'coupons' => $out, 'can_app_fields' => true, 'organizer_id' => $oid, 'text_max' => self::TEXT_MAX]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /** REST-Eingaben (Namen wie in staff_item) → Gutschein. */
    private static function from_rest(array $c, WP_REST_Request $req, $oid, $create) {
        $p = $req->get_json_params();
        if (!is_array($p)) $p = $req->get_body_params();
        $map = ['promote_in_app' => 'app_promote', 'auto_apply' => 'app_auto', 'title' => 'app_title', 'text' => 'app_text', 'promote_from' => 'app_from', 'promote_until' => 'app_until'];
        $in = [];
        foreach ($p as $k => $v) $in[$map[$k] ?? $k] = $v;
        if ($create || array_key_exists('discount_type', $in)) {
            $t = (string) ($in['discount_type'] ?? 'percent');
            if (!in_array($t, ['percent', 'fixed', 'per_ticket_percent', 'per_ticket_fixed'], true)) return new WP_Error('tix_invalid_field', 'Unbekannte Rabatt-Art.', ['status' => 400, 'field' => 'discount_type']);
            $c['discount_type'] = $t;
        }
        if ($create || array_key_exists('value', $in)) {
            $v = round(floatval(str_replace(',', '.', (string) ($in['value'] ?? 0))), 2);
            if ($v <= 0) return new WP_Error('tix_invalid_field', 'Bitte einen Rabatt größer 0 angeben.', ['status' => 400, 'field' => 'value']);
            if (in_array($c['discount_type'], ['percent', 'per_ticket_percent'], true)) $v = min(100, $v);
            $c['value'] = $v;
        }
        if (array_key_exists('description', $in)) $c['description'] = mb_substr(sanitize_text_field((string) $in['description']), 0, 120);
        if (array_key_exists('expires', $in)) {
            $d = self::date_or_empty($in['expires']);
            if ($d === false) return new WP_Error('tix_invalid_field', 'Bitte ein Datum im Format JJJJ-MM-TT angeben.', ['status' => 400, 'field' => 'expires']);
            $c['expires'] = $d;
        }
        if (array_key_exists('max_uses', $in)) $c['max_uses'] = max(0, intval($in['max_uses']));
        if (array_key_exists('min_amount', $in)) $c['min_amount'] = max(0, round(floatval(str_replace(',', '.', (string) $in['min_amount'])), 2));
        if (array_key_exists('one_per_email', $in)) $c['one_per_email'] = !empty($in['one_per_email']) ? 1 : 0;
        if (array_key_exists('allowed_events', $in)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $in['allowed_events']))));
            foreach ($ids as $eid) {
                if (get_post_type($eid) !== 'event' || ($oid && !in_array($oid, TIX_App_Scope::event_org_ids($eid), true))) {
                    return new WP_Error('tix_invalid_field', 'Event nicht gefunden oder nicht deins.', ['status' => 400, 'field' => 'allowed_events']);
                }
            }
            $c['allowed_events'] = $ids;
        }
        if ($oid) $c['organizer_id'] = $oid;
        elseif (array_key_exists('organizer_id', $in) && TIX_App_Scope::is_admin()) {
            $o = intval($in['organizer_id']);
            if ($o && get_post_type($o) !== 'tix_organizer') return new WP_Error('tix_invalid_field', 'Veranstalter nicht gefunden.', ['status' => 400, 'field' => 'organizer_id']);
            $c['organizer_id'] = $o;
        }
        return self::apply_fields($c, $in);
    }

    /** POST /organizer/coupons */
    public static function rest_create(WP_REST_Request $req) {
        $oid = self::scope_org();
        $code = strtoupper(trim(sanitize_text_field((string) $req->get_param('code'))));
        if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) return new WP_Error('tix_invalid_field', 'Code: 3–40 Zeichen, A–Z, 0–9, _ und -.', ['status' => 400, 'field' => 'code']);
        $all = self::all();
        foreach (array_keys($all) as $k) if (strcasecmp((string) $k, $code) === 0) return new WP_Error('tix_coupon_exists', 'Diesen Code gibt es schon.', ['status' => 409, 'field' => 'code']);
        $c = self::from_rest(['code' => $code, 'used' => 0, 'max_uses' => 0, 'expires' => '', 'description' => '', 'auto_apply' => 0], $req, $oid, true);
        if (is_wp_error($c)) return $c;
        $all[$code] = $c;
        update_option('tix_coupons', $all);
        return self::saved($code, $c, 201);
    }

    private static function find_own($code, $oid) {
        foreach (self::all() as $k => $c) {
            if (strcasecmp((string) $k, (string) $code) === 0) return self::visible($c, $oid) ? [(string) $k, $c] : null;
        }
        return null;
    }

    /** POST /organizer/coupons/{code} – Teil-Update */
    public static function rest_update(WP_REST_Request $req) {
        $oid = self::scope_org();
        $f = self::find_own($req['code'], $oid);
        if (!$f) return new WP_Error('tix_coupon_not_found', 'Gutschein nicht gefunden.', ['status' => 404]);
        $c = self::from_rest($f[1], $req, $oid, false);
        if (is_wp_error($c)) return $c;
        $all = self::all();
        $all[$f[0]] = $c;
        update_option('tix_coupons', $all);
        return self::saved($f[0], $c, 200);
    }

    /** DELETE /organizer/coupons/{code} */
    public static function rest_delete(WP_REST_Request $req) {
        $oid = self::scope_org();
        $f = self::find_own($req['code'], $oid);
        if (!$f) return new WP_Error('tix_coupon_not_found', 'Gutschein nicht gefunden.', ['status' => 404]);
        $all = self::all();
        unset($all[$f[0]]);
        update_option('tix_coupons', $all);
        return rest_ensure_response(['ok' => true]);
    }

    private static function saved($code, $c, $status) {
        $r = new WP_REST_Response(['ok' => true, 'coupon' => self::staff_item($code, $c)], $status);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    // ── Admin (Seite „Gutscheine“) ──

    /** Felder im Gutschein-Formular. */
    public static function render_admin_fields($edit) {
        $e = array_merge(self::defaults(), is_array($edit) ? $edit : []);
        $multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
        ?>
        <tr><th colspan="2" style="padding-top:24px;"><h3 style="margin:0 0 4px;font-size:14px;text-transform:uppercase;letter-spacing:0.05em;color:#475569;border-top:1px solid #e5e7eb;padding-top:14px;">📱 App</h3></th></tr>
        <tr><th>Einlösen</th><td>
            <label><input type="checkbox" name="app_only" value="1" <?php checked(!empty($e['app_only'])); ?>> <strong>Nur in der App einlösbar</strong></label>
            <p class="description">Im Web erscheint „<?php echo esc_html(self::app_only_message()); ?>“ mit Link in die App.</p>
        </td></tr>
        <tr><th>In der App bewerben</th><td>
            <label><input type="checkbox" name="app_promote" value="1" <?php checked(!empty($e['app_promote'])); ?>> In der App zeigen (Band bzw. Liste)</label>
            <p><input type="text" name="app_title" class="regular-text" maxlength="60" value="<?php echo esc_attr($e['app_title']); ?>" placeholder="Titel (leer = Code)"></p>
            <p><input type="text" name="app_text" class="large-text" maxlength="<?php echo self::TEXT_MAX; ?>" value="<?php echo esc_attr($e['app_text']); ?>" placeholder="Kurzer Text, z. B. „10 % auf alle Tickets – nur in der App“"></p>
            <p>Zeitraum (optional): <input type="date" name="app_from" value="<?php echo esc_attr($e['app_from']); ?>"> bis <input type="date" name="app_until" value="<?php echo esc_attr($e['app_until']); ?>"></p>
        </td></tr>
        <tr><th>Art in der App</th><td>
            <label style="margin-right:14px;"><input type="radio" name="app_auto" value="1" <?php checked(!empty($e['app_auto'])); ?>> Automatisch im Warenkorb</label>
            <label><input type="radio" name="app_auto" value="0" <?php checked(empty($e['app_auto'])); ?>> Code zum Eingeben</label>
            <p class="description">Die App zeigt den Code in beiden Fällen.</p>
        </td></tr>
        <?php if ($multi) :
            $orgs = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC']); ?>
        <tr><th><label for="ttc-org">Veranstalter</label></th><td>
            <select id="ttc-org" name="organizer_id"><option value="0">— ganze Plattform —</option>
                <?php foreach ($orgs as $o) : ?><option value="<?php echo intval($o->ID); ?>" <?php selected(intval($e['organizer_id']), intval($o->ID)); ?>><?php echo esc_html($o->post_title); ?></option><?php endforeach; ?>
            </select>
            <p class="description">Gutschein eines Veranstalters gilt nur bei dessen Events.</p>
        </td></tr>
        <?php endif;
    }

    /** Werte aus dem Admin-Formular übernehmen (TIX_Coupons::handle_save). */
    public static function from_admin_post(array $c, array $post) {
        $in = wp_unslash(array_intersect_key($post, array_flip(['app_only', 'app_promote', 'app_auto', 'app_title', 'app_text', 'app_from', 'app_until'])));
        $r = self::apply_fields($c, $in + ['app_title' => '', 'app_text' => '', 'app_from' => '', 'app_until' => ''], true);
        if (is_wp_error($r)) {
            $in['app_from'] = $in['app_until'] = '';
            $r = self::apply_fields($c, $in, true);
        }
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && isset($post['organizer_id'])) {
            $o = intval($post['organizer_id']);
            $r['organizer_id'] = ($o && get_post_type($o) === 'tix_organizer') ? $o : 0;
        }
        return is_wp_error($r) ? $c : $r;
    }

    /** Einstellungen der Seite (App-Name, Store-Links) – Formular unter der Gutschein-Liste. */
    public static function render_settings() {
        ?>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-top:24px;max-width:900px;">
            <h2 style="margin-top:0;font-size:16px;">📱 App-Gutscheine: Links</h2>
            <p class="description">Für Gutscheine „nur in der App“: Wird so ein Code im Web eingegeben, bekommt der Kunde einen Link in die App. Mit App-Links für Event-Seiten (Universal Links) öffnet sich das Event in der App, sonst der App-Store.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('tix_app_coupon_settings'); ?><input type="hidden" name="action" value="tix_app_coupon_settings">
                <table class="form-table">
                    <tr><th><label for="tac-name">Name der App</label></th><td><input type="text" id="tac-name" name="app_name" class="regular-text" value="<?php echo esc_attr(get_option(self::OPT_NAME, '')); ?>" placeholder="<?php echo esc_attr(html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8')); ?>"></td></tr>
                    <tr><th><label for="tac-store">App Store (iOS)</label></th><td><input type="url" id="tac-store" name="store_url" class="large-text" value="<?php echo esc_attr(get_option(self::OPT_STORE, '')); ?>" placeholder="https://apps.apple.com/de/app/…"></td></tr>
                    <tr><th><label for="tac-play">Google Play</label></th><td><input type="url" id="tac-play" name="play_url" class="large-text" value="<?php echo esc_attr(get_option(self::OPT_PLAY, '')); ?>" placeholder="https://play.google.com/store/apps/details?id=…"></td></tr>
                </table>
                <p><button class="button button-primary">Speichern</button></p>
            </form>
        </div>
        <?php
    }

    public static function save_settings() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_app_coupon_settings');
        update_option(self::OPT_NAME, sanitize_text_field(wp_unslash($_POST['app_name'] ?? '')), false);
        foreach ([self::OPT_STORE => 'store_url', self::OPT_PLAY => 'play_url'] as $opt => $f) {
            $u = esc_url_raw(trim(wp_unslash($_POST[$f] ?? '')));
            update_option($opt, preg_match('#^https://#', $u) ? $u : '', false);
        }
        wp_safe_redirect(add_query_arg(['page' => 'tix-coupons', 'msg' => 'saved'], admin_url('admin.php')));
        exit;
    }
}
