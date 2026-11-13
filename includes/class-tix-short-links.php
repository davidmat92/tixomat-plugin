<?php
/**
 * TIX_Short_Links – Kurzlinks `/go/<code>` mit QR (Statistik Stufe 2, docs/plan-statistik-marketing.md §7/§9, frei nach P2).
 *
 * Ein Kurzlink zeigt auf eine Event-Seite, die Veranstalterseite (evendis) oder eine Seite der eigenen Website und trägt
 * Quelle/Medium/Kampagne. Beim Aufruf hängt das Plugin utm_source/utm_medium/utm_campaign, utm_id = „go-<code>“ und den
 * Kampagnen-Code `c=<code>` an – die Website reicht `c=` ohne Gerätespeicher bis zur Kasse (Stufe 1), die Bestellung trägt
 * dann link_code = <code>. So zählen Klicks (Link bzw. QR), Aufrufe der Event-Seite (Zählung, utm_id) und Käufe je Link.
 *
 * Tabellen: {prefix}tix_short_links (ein Eintrag je Link), {prefix}tix_short_clicks (day, link_id, kind link|qr, n) – nur
 * Tageszähler, keine IP, kein Browser (Bots werden geprüft, nie gespeichert).
 *
 * REST (Inhaber/Team-Admin/Admin wie die Statistik; evendis je Veranstalter):
 *   GET    /marketing/links?from=&to=          → {ok, links: [{id, code, url, short_url, qr_url, label, event, target, source,
 *                                                  medium, campaign, archived, created, clicks, clicks_qr, views, orders,
 *                                                  tickets, revenue}], totals, range}
 *   POST   /marketing/links {label, event_id? | target?, source?, medium?, campaign?, code?}  → neuer Link
 *   POST   /marketing/links/{id} {label?, source?, medium?, campaign?, archived?}            → ändern (Ziel bleibt)
 *   DELETE /marketing/links/{id}                                                             → Link + Klicks weg
 *   GET    /public/go/{code}?qr=1             → {url} und ein Klick (für die Next-Seiten, die /go/<code> weiterleiten)
 * Auf Seiten, die WordPress selbst ausliefert, leitet /go/<code> direkt weiter (template_redirect).
 */
if (!defined('ABSPATH')) exit;

class TIX_Short_Links {

    const NS = 'tixomat/v1';
    const DB_VERSION = '1';
    const MAX_PER_SCOPE = 500;
    const CODE_RE = '/^[a-z0-9][a-z0-9-]{2,31}$/';
    const KEEP_CLICK_DAYS = 400;

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_create'], 22);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('template_redirect', [__CLASS__, 'wp_redirect'], 1);
        add_action('before_delete_post', function ($id) { if (get_post_type($id) === 'tix_organizer') self::purge_organizer(intval($id)); });
    }

    public static function t() { global $wpdb; return $wpdb->prefix . 'tix_short_links'; }
    public static function tc() { global $wpdb; return $wpdb->prefix . 'tix_short_clicks'; }

    public static function maybe_create() {
        if (get_option('tix_short_links_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::t() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            organizer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            code VARCHAR(32) NOT NULL,
            label VARCHAR(120) NOT NULL DEFAULT '',
            event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            target VARCHAR(500) NOT NULL DEFAULT '',
            source VARCHAR(64) NOT NULL DEFAULT '',
            medium VARCHAR(64) NOT NULL DEFAULT '',
            campaign VARCHAR(64) NOT NULL DEFAULT '',
            archived TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY org (organizer_id, id)
        ) $c;");
        dbDelta("CREATE TABLE " . self::tc() . " (
            day DATE NOT NULL,
            link_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(4) NOT NULL DEFAULT 'link',
            n INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (day, link_id, kind),
            KEY link (link_id, day)
        ) $c;");
        update_option('tix_short_links_db', self::DB_VERSION, true);
    }

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }
    private static function bad($field, $msg, $code = 'tix_invalid_field', $status = 400) { return new WP_Error($code, $msg, ['status' => $status, 'field' => $field]); }

    public static function routes() {
        $p = ['TIX_Studio_Stats', 'perm_manager'];
        register_rest_route(self::NS, '/marketing/links', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => $p],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_create'], 'permission_callback' => $p],
        ]);
        register_rest_route(self::NS, '/marketing/links/(?P<id>\d+)', [
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_update'], 'permission_callback' => $p],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_delete'], 'permission_callback' => $p],
        ]);
        register_rest_route(self::NS, '/public/go/(?P<code>[A-Za-z0-9-]{1,32})', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_go'], 'permission_callback' => '__return_true']);
    }

    // ═══════════════ Adressen ═══════════════

    /** Grundadresse der Kunden-Website (ohne / am Ende) */
    private static function base() {
        $b = class_exists('TIX_Customer_URL') ? (string) TIX_Customer_URL::base() : '';
        return rtrim($b !== '' ? $b : home_url(), '/');
    }

    private static function customer_url($u) { return class_exists('TIX_Customer_URL') ? (string) TIX_Customer_URL::to((string) $u) : (string) $u; }

    public static function short_url($code) { return self::base() . '/go/' . $code; }

    /** Ziel ohne Kampagnen-Anhang: Event-Seite bzw. gespeicherter Pfad auf der Kunden-Website */
    private static function target_url(array $l) {
        if (intval($l['event_id'])) return self::customer_url(get_permalink(intval($l['event_id'])));
        $t = (string) $l['target'];
        return $t === '' ? self::base() . '/' : self::base() . $t;
    }

    /** Ziel mit UTM, utm_id „go-<code>“ und c=<code> (bestehende Parameter des Ziels bleiben) */
    public static function landing_url(array $l, $qr = false) {
        $args = array_filter([
            'utm_source' => (string) $l['source'] ?: ($qr ? 'qr' : 'link'),
            'utm_medium' => (string) $l['medium'] ?: ($qr ? 'qr' : 'shortlink'),
            'utm_campaign' => (string) $l['campaign'],
            'utm_id' => 'go-' . $l['code'],
            'c' => (string) $l['code'],
        ], 'strlen');
        return add_query_arg(array_map('rawurlencode', $args), self::target_url($l));
    }

    /** Pfad (beginnt mit /) auf der eigenen Website prüfen und kürzen; '' = Startseite, false = ungültig */
    private static function clean_target($raw) {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === '/') return '';
        $base_host = strtolower((string) wp_parse_url(self::base(), PHP_URL_HOST));
        if (preg_match('#^https?://#i', $raw)) {
            $h = strtolower((string) wp_parse_url($raw, PHP_URL_HOST));
            if ($h === '' || ($h !== $base_host && $h !== strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)))) return false;
            $q = (string) wp_parse_url($raw, PHP_URL_QUERY);
            $raw = (string) wp_parse_url($raw, PHP_URL_PATH) . ($q !== '' ? '?' . $q : '');
        }
        if ($raw === '' || $raw[0] !== '/' || strpos($raw, '//') !== false || strpos($raw, '\\') !== false || preg_match('/[\s<>"]/', $raw)) return false;
        if (preg_match('#^/(wp-admin|wp-login|wp-json|go)(/|$|\?)#i', $raw)) return false;
        return mb_substr($raw, 0, 500);
    }

    // ═══════════════ Bereich ═══════════════

    private static function org(WP_REST_Request $req) { return class_exists('TIX_Ad_Accounts') ? TIX_Ad_Accounts::org($req) : 0; }

    private static function get($id) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t() . " WHERE id = %d", intval($id)), ARRAY_A);
        return $r ?: null;
    }

    /** Link des Aufrufers (gleicher Bereich) oder WP_Error */
    private static function own($id, WP_REST_Request $req) {
        $l = self::get($id);
        if (!$l || intval($l['organizer_id']) !== self::org($req)) return new WP_Error('not_found', 'Link nicht gefunden.', ['status' => 404]);
        return $l;
    }

    private static function new_code() {
        global $wpdb;
        for ($i = 0; $i < 20; $i++) {
            $c = strtolower(wp_generate_password(6, false, false));
            if (!preg_match('/^[a-z]/', $c)) continue; // Buchstabe vorne (lesbarer, nie wie eine Zahl)
            if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::t() . " WHERE code = %s", $c))) return $c;
        }
        return 'l' . strtolower(wp_generate_password(10, false, false));
    }

    // ═══════════════ REST ═══════════════

    private static function fields(WP_REST_Request $req, array $cur = []) {
        $out = [];
        foreach (['source' => 64, 'medium' => 64, 'campaign' => 64] as $f => $len) {
            if ($req->has_param($f)) $out[$f] = TIX_Web_Stats::norm($req->get_param($f), $len);
        }
        if ($req->has_param('label')) $out['label'] = mb_substr(trim(wp_strip_all_tags((string) $req->get_param('label'))), 0, 120);
        return $out;
    }

    public static function rest_create(WP_REST_Request $req) {
        self::maybe_create();
        global $wpdb;
        $org = self::org($req);
        if (self::multi() && !$org && !current_user_can('manage_options')) return new WP_Error('rest_forbidden', 'Dieses Konto ist keinem Veranstalter zugeordnet.', ['status' => 403]);
        if (intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::t() . " WHERE organizer_id = %d", $org))) >= self::MAX_PER_SCOPE) return self::bad('code', 'Höchstens ' . self::MAX_PER_SCOPE . ' Links. Lösch bitte alte Links.', 'tix_links_full', 409);
        $row = ['organizer_id' => $org, 'event_id' => 0, 'target' => '', 'source' => '', 'medium' => '', 'campaign' => '', 'label' => '', 'archived' => 0, 'created_by' => get_current_user_id(), 'created' => current_time('mysql', true)];
        $eid = absint($req->get_param('event_id'));
        if ($eid) {
            if (get_post_type($eid) !== 'event' || !in_array($eid, array_map('intval', TIX_Studio_Stats::scope_ids()), true)) return self::bad('event_id', 'Event nicht gefunden.', 'not_found', 404);
            $row['event_id'] = $eid;
        } else {
            $t = self::clean_target($req->get_param('target'));
            if ($t === false) return self::bad('target', 'Bitte eine Seite deiner eigenen Website angeben (z. B. /events/).');
            // evendis: Veranstalter verlinken nur ihre Events oder ihre Veranstalterseite
            if (self::multi() && $org) {
                $page = (string) wp_parse_url(self::customer_url(get_permalink($org)), PHP_URL_PATH);
                if ($t === '' || $page === '' || strpos($t, rtrim($page, '/')) !== 0) {
                    if ($t !== '') return self::bad('target', 'Verlinke ein Event oder deine Veranstalterseite.');
                    $t = $page;
                }
            }
            $row['target'] = $t;
        }
        $row = array_merge($row, self::fields($req));
        if ($row['label'] === '') $row['label'] = $eid ? html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8') : 'Link';
        $code = strtolower(trim((string) $req->get_param('code')));
        if ($code !== '') {
            if (!preg_match(self::CODE_RE, $code)) return self::bad('code', 'Kurzname: 3–32 Zeichen, nur a–z, 0–9 und Bindestrich, vorne ein Buchstabe oder eine Zahl.');
            if ($wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::t() . " WHERE code = %s", $code))) return self::bad('code', 'Diesen Kurznamen gibt es schon.', 'tix_code_taken', 409);
        } else $code = self::new_code();
        $row['code'] = $code;
        if (!$wpdb->insert(self::t(), $row)) return self::bad('code', 'Diesen Kurznamen gibt es schon.', 'tix_code_taken', 409);
        self::mirror($wpdb->insert_id);
        return rest_ensure_response(['ok' => true, 'link' => self::out(self::get($wpdb->insert_id), [])]);
    }

    public static function rest_update(WP_REST_Request $req) {
        global $wpdb;
        $l = self::own($req['id'], $req);
        if (is_wp_error($l)) return $l;
        $set = self::fields($req);
        if ($req->has_param('archived')) $set['archived'] = rest_sanitize_boolean($req->get_param('archived')) ? 1 : 0;
        if ($set) { $wpdb->update(self::t(), $set, ['id' => intval($l['id'])]); self::mirror($l['id']); }
        return rest_ensure_response(['ok' => true, 'link' => self::out(self::get($l['id']), [])]);
    }

    public static function rest_delete(WP_REST_Request $req) {
        global $wpdb;
        $l = self::own($req['id'], $req);
        if (is_wp_error($l)) return $l;
        $wpdb->delete(self::tc(), ['link_id' => intval($l['id'])]);
        $wpdb->delete(self::t(), ['id' => intval($l['id'])]);
        self::mirror($l['id']);
        return rest_ensure_response(['ok' => true, 'deleted' => intval($l['id'])]);
    }

    public static function rest_list(WP_REST_Request $req) {
        self::maybe_create();
        global $wpdb;
        $to = (string) ($req->get_param('to') ?: current_time('Y-m-d'));
        $from = (string) ($req->get_param('from') ?: gmdate('Y-m-d', strtotime($to) - 29 * DAY_IN_SECONDS));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) return self::bad('from', 'Bitte einen gültigen Zeitraum angeben.');
        $org = self::org($req);
        $fev = absint($req->get_param('event_id')); // 1.38.806: nur Links auf dieses Event (Event → Statistik → Kampagnen)
        $links = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t() . " WHERE organizer_id = %d" . ($fev ? $wpdb->prepare(' AND event_id = %d', $fev) : '') . " ORDER BY archived ASC, id DESC", $org), ARRAY_A);
        $st = $links ? self::stats($links, $from, $to) : [];
        $out = array_map(function ($l) use ($st) { return self::out($l, $st[intval($l['id'])] ?? []); }, $links);
        $tot = ['clicks' => 0, 'clicks_qr' => 0, 'views' => 0, 'orders' => 0, 'tickets' => 0, 'revenue' => 0.0];
        foreach ($out as $o) foreach ($tot as $k => $v) $tot[$k] += $o[$k];
        $tot['revenue'] = round($tot['revenue'], 2);
        return rest_ensure_response(['ok' => true, 'range' => ['from' => $from, 'to' => $to], 'links' => $out, 'totals' => $tot, 'base' => self::base() . '/go/']);
    }

    /** Klicks (Link/QR), Aufrufe der Event-Seite und Käufe je Link im Zeitraum */
    private static function stats(array $links, $from, $to) {
        global $wpdb;
        $ids = array_map(function ($l) { return intval($l['id']); }, $links);
        $by_code = []; foreach ($links as $l) $by_code[$l['code']] = intval($l['id']);
        $s = array_fill_keys($ids, ['clicks' => 0, 'clicks_qr' => 0, 'views' => 0, 'orders' => 0, 'tickets' => 0, 'revenue' => 0.0]);
        $in = implode(',', $ids);
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT link_id, kind, SUM(n) n FROM " . self::tc() . " WHERE link_id IN ($in) AND day BETWEEN %s AND %s GROUP BY link_id, kind", $from, $to)) as $r) {
            $s[intval($r->link_id)][$r->kind === 'qr' ? 'clicks_qr' : 'clicks'] += intval($r->n);
        }
        $uids = []; foreach ($links as $l) $uids[] = $wpdb->prepare('%s', 'go-' . $l['code']);
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT utm_id, SUM(n) n FROM " . TIX_Web_Stats::dim_table() . " WHERE day BETWEEN %s AND %s AND step = 'event' AND utm_id IN (" . implode(',', $uids) . ") GROUP BY utm_id", $from, $to)) as $r) {
            $id = $by_code[substr((string) $r->utm_id, 3)] ?? 0;
            if ($id) $s[$id]['views'] += intval($r->n);
        }
        // Käufe: bezahlte Bestellungen im Bereich mit link_code (bzw. utm_id go-<code>)
        if (class_exists('TIX_Sales_Figures') && class_exists('TIX_Order_Attribution')) {
            $res = TIX_Sales_Figures::for_events(TIX_Studio_Stats::scope_ids(), $from, $to);
            $paid = []; $rev = [];
            foreach ($res['rows'] as $r) if ($r->money['paid']) { $paid[intval($r->id)] = 1; $rev[intval($r->id)] = ($rev[intval($r->id)] ?? 0.0) + $r->money['net']; }
            foreach ($paid ? TIX_Order_Attribution::many(array_keys($paid), ['link_code', 'utm_id']) : [] as $oid => $a) {
                $code = (string) ($a['link_code'] ?? '');
                if (!isset($by_code[$code]) && strpos((string) ($a['utm_id'] ?? ''), 'go-') === 0) $code = substr((string) $a['utm_id'], 3);
                $id = $by_code[$code] ?? 0;
                if (!$id) continue;
                $s[$id]['orders']++;
                $s[$id]['tickets'] += intval($res['order_tickets'][$oid] ?? 0);
                $s[$id]['revenue'] += floatval($rev[$oid] ?? 0);
            }
        }
        return $s;
    }

    private static function out(array $l, array $st) {
        $eid = intval($l['event_id']);
        $short = self::short_url($l['code']);
        return [
            'id' => intval($l['id']), 'code' => (string) $l['code'], 'short_url' => $short, 'qr_url' => $short . '?qr=1', 'url' => self::landing_url($l),
            'label' => (string) $l['label'], 'event' => $eid ? ['id' => $eid, 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'), 'date' => (string) get_post_meta($eid, '_tix_date_start', true)] : null,
            'target' => $eid ? null : ((string) $l['target'] ?: '/'), 'source' => (string) $l['source'], 'medium' => (string) $l['medium'], 'campaign' => (string) $l['campaign'],
            'archived' => !empty($l['archived']), 'created' => mysql_to_rfc3339((string) $l['created']),
            'clicks' => intval($st['clicks'] ?? 0), 'clicks_qr' => intval($st['clicks_qr'] ?? 0), 'views' => intval($st['views'] ?? 0),
            'orders' => intval($st['orders'] ?? 0), 'tickets' => intval($st['tickets'] ?? 0), 'revenue' => round(floatval($st['revenue'] ?? 0), 2),
        ];
    }

    // ═══════════════ Aufruf ═══════════════

    /** Link zum Code auflösen und einen Klick zählen → Ziel-URL oder null (unbekannt, archiviert, Event weg) */
    public static function resolve($code, $qr, $ua, $event_id = 0) {
        self::maybe_create();
        global $wpdb;
        $code = strtolower((string) $code);
        if (!preg_match(self::CODE_RE, $code)) return null;
        $l = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t() . " WHERE code = %s", $code), ARRAY_A);
        // Kein Kurzlink mit diesem Code: Promoter-Code (TIX_Promoter_Rules, 1.38.663) – /go/<promoter-code>[?e=<event>]
        if (!$l) return apply_filters('tix_go_fallback', null, $code, intval($event_id), (bool) $qr);
        if (!empty($l['archived'])) return null;
        if (intval($l['event_id']) && !in_array(get_post_status(intval($l['event_id'])), ['publish', 'future'], true)) return null;
        if (!preg_match(TIX_Web_Stats::BOT, (string) $ua) && !(class_exists('TIX_Shadow') && TIX_Shadow::active())) { // Schatten-Abruf zählt nicht (1.38.808)
            $wpdb->query($wpdb->prepare("INSERT INTO " . self::tc() . " (day, link_id, kind, n) VALUES (%s, %d, %s, 1) ON DUPLICATE KEY UPDATE n = n + 1", wp_date('Y-m-d'), intval($l['id']), $qr ? 'qr' : 'link'));
            self::mirror($l['id']); // Postausgang fasst mehrere Klicks je Link zusammen
            if (mt_rand(1, 500) === 1) $wpdb->query($wpdb->prepare("DELETE FROM " . self::tc() . " WHERE day < %s", wp_date('Y-m-d', time() - self::KEEP_CLICK_DAYS * DAY_IN_SECONDS)));
        }
        return self::landing_url($l, $qr);
    }

    /** GET /public/go/{code}?qr=1 → {url}; die Next-Seite leitet weiter und reicht den Browser (user_agent) für den Bot-Filter durch */
    public static function rest_go(WP_REST_Request $req) {
        $ua = (string) ($req->get_param('ua') ?: $req->get_header('user-agent'));
        $url = self::resolve($req['code'], (string) $req->get_param('qr') === '1', $ua, absint($req->get_param('e')));
        if (!$url) return new WP_Error('not_found', 'Diesen Link gibt es nicht (mehr).', ['status' => 404, 'fallback' => self::base() . '/']);
        $r = rest_ensure_response(['ok' => true, 'url' => $url]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /** WordPress liefert die Seite selbst aus: /go/<code> direkt weiterleiten */
    public static function wp_redirect() {
        $p = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!preg_match('#^/go/([A-Za-z0-9-]{1,32})/?$#', $p, $m)) return;
        $url = self::resolve($m[1], isset($_GET['qr']) && $_GET['qr'] === '1', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), isset($_GET['e']) ? absint($_GET['e']) : 0);
        nocache_headers();
        wp_redirect($url ?: self::base() . "/", 302, "evendis");
        exit;
    }

    public static function purge_organizer($o) {
        global $wpdb;
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM " . self::t() . " WHERE organizer_id = %d", intval($o))));
        if (!$ids) return;
        $wpdb->query("DELETE FROM " . self::tc() . " WHERE link_id IN (" . implode(',', $ids) . ")");
        $wpdb->query($wpdb->prepare("DELETE FROM " . self::t() . " WHERE organizer_id = %d", intval($o)));
        foreach ($ids as $id) self::mirror($id);
    }

    /** Datenspiegel: Link samt Tageszählern nachziehen (fehlt er in WordPress, löscht der Spiegel beides) */
    private static function mirror($id) {
        if (class_exists('TIX_Mirror')) TIX_Mirror::touch('short_link', intval($id));
    }
}

TIX_Short_Links::init();
