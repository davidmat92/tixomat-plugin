<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Degaso_Menu – Getränkekarte aus der Degaso-Kasse in App und Website (1.38.557, Paket 6, nur Anzeige, kein Verkauf).
 *
 * Quelle: Selforder-API `POST /getMenuByCustomer {apiKey}` (Menüs je Kasse: categories, products). Die Antwort ist bei
 * Degaso nicht im Detail beschrieben – normalize() nimmt die üblichen Feldnamen (name/title, price, category/categoryId,
 * infoText/description, hidden/active/showOnGimig) und gibt nur eigene Felder weiter (Antworten enthalten den Schlüssel).
 * Zwischenspeicher: Option `tix_degaso_menu` {fetched_at, cash, categories}; neu geholt höchstens alle 30 min (Cron bzw.
 * Abruf), bei Fehler bleibt der letzte Stand (offline nutzbar).
 *
 * Einstellungen (Option tix_degaso, Studio): menu_enabled (Standard aus), menu_cash (Kasse, leer = erste), menu_hidden
 * (ausgeblendete Kategorien), menu_youth ('16_18' Standard = Bier/Wein/Sekt ab 16, Spirituosen ab 18 | '18' = alles ab 18),
 * menu_note (eigener Hinweis, z. B. „Preise inkl. MwSt.“).
 *
 * REST:
 *   GET  /public/drinks                    öffentlich: {ok, enabled, title, updated_at, categories [{name, items [{name, price, info}]}], youth_note, note, currency}
 *   GET  /degaso/admin/menu?fresh=1        Studio: Vorschau mit allen Kategorien (hidden), Kassen zur Auswahl
 */
class TIX_Degaso_Menu {

    const NS    = 'tixomat/v1';
    const CACHE = 'tix_degaso_menu';
    const TTL   = 1800;

    public static function init() {
        add_filter('tix_degaso_defaults', function ($d) {
            return $d + ['menu_enabled' => false, 'menu_cash' => '', 'menu_hidden' => [], 'menu_youth' => '16_18', 'menu_note' => ''];
        });
        add_filter('tix_degaso_settings_out', [__CLASS__, 'settings_out'], 10, 2);
        add_filter('tix_degaso_settings_in', [__CLASS__, 'settings_in'], 10, 2);
        add_action('tix_degaso_cron', [__CLASS__, 'cron']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function active() {
        return TIX_Degaso::connected() && !empty(TIX_Degaso::settings()['menu_enabled']);
    }

    public static function settings_out($out, $s) {
        return $out + ['menu_enabled' => !empty($s['menu_enabled']), 'menu_cash' => (string) $s['menu_cash'], 'menu_hidden' => array_values((array) $s['menu_hidden']),
            'menu_youth' => $s['menu_youth'] === '18' ? '18' : '16_18', 'menu_note' => (string) $s['menu_note']];
    }

    public static function settings_in($s, $p) {
        if (is_wp_error($s)) return $s;
        if (array_key_exists('menu_enabled', $p)) $s['menu_enabled'] = filter_var($p['menu_enabled'], FILTER_VALIDATE_BOOLEAN);
        if (array_key_exists('menu_cash', $p)) $s['menu_cash'] = mb_substr(sanitize_text_field((string) $p['menu_cash']), 0, 80);
        if (array_key_exists('menu_hidden', $p)) $s['menu_hidden'] = array_slice(array_values(array_filter(array_map(function ($x) { return mb_substr(sanitize_text_field((string) $x), 0, 80); }, (array) $p['menu_hidden']))), 0, 100);
        if (array_key_exists('menu_youth', $p)) $s['menu_youth'] = $p['menu_youth'] === '18' ? '18' : '16_18';
        if (array_key_exists('menu_note', $p)) $s['menu_note'] = mb_substr(sanitize_textarea_field((string) $p['menu_note']), 0, 300);
        if (array_key_exists('menu_cash', $p) || array_key_exists('menu_enabled', $p)) { delete_option(self::CACHE); delete_transient('tix_dg_menu_lock'); } // andere Kasse: neu holen
        return $s;
    }

    /** Jugendschutz-Hinweis (immer angezeigt, § 9 JuSchG) */
    public static function youth_note($mode) {
        return $mode === '18'
            ? 'Alkohol schenken wir nur an Gäste ab 18 Jahren aus. Bitte halte deinen Ausweis bereit.'
            : 'Bier, Wein und Sekt erst ab 16, Spirituosen und Mischgetränke mit Spirituosen erst ab 18 Jahren (§ 9 JuSchG). Bitte halte deinen Ausweis bereit.';
    }

    // ═══════════════ Abruf und Aufbereitung ═══════════════

    /**
     * Rohantwort → {cashes: [Name], menu: [{name, items:[{name, price, info}]}]} der gewählten Kasse (leer = erstes Menü).
     * Ohne Kategorien-Liste gruppiert nach dem Kategorienamen am Produkt.
     */
    public static function normalize($raw, $cash = '') {
        $menus = [];
        $list = is_array($raw) ? ($raw['menus'] ?? $raw['data'] ?? $raw['menu'] ?? $raw) : [];
        if (isset($list['products']) || isset($list['categories'])) $list = [$list]; // ein einzelnes Menü
        foreach ((array) $list as $m) if (is_array($m) && (isset($m['products']) || isset($m['categories']))) $menus[] = $m;
        $cashes = array_values(array_unique(array_filter(array_map(function ($m) { return (string) ($m['cashName'] ?? $m['name'] ?? ''); }, $menus))));
        $menu = null;
        foreach ($menus as $m) if ($cash !== '' && strcasecmp((string) ($m['cashName'] ?? $m['name'] ?? ''), $cash) === 0) { $menu = $m; break; }
        if (!$menu) $menu = $menus[0] ?? ['categories' => [], 'products' => []];

        $cats = [];   // Schlüssel (id bzw. Name klein) → Name
        $order = [];
        foreach ((array) ($menu['categories'] ?? []) as $i => $c) {
            if (!is_array($c) && !is_string($c)) continue;
            $name = is_string($c) ? $c : trim((string) ($c['name'] ?? $c['title'] ?? $c['categoryName'] ?? ''));
            if ($name === '' || (is_array($c) && (!empty($c['hidden']) || (isset($c['active']) && !$c['active'])))) continue;
            $pos = is_array($c) && isset($c['order']) ? floatval($c['order']) : (is_array($c) && isset($c['position']) ? floatval($c['position']) : $i);
            foreach (array_filter([is_array($c) ? (string) ($c['_id'] ?? $c['id'] ?? '') : '', mb_strtolower($name)]) as $k) $cats[$k] = $name;
            $order[$name] = $pos;
        }
        $groups = [];
        foreach ((array) ($menu['products'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $name = trim((string) ($p['name'] ?? $p['title'] ?? ''));
            if ($name === '' || !empty($p['hidden']) || (isset($p['active']) && !$p['active']) || (isset($p['showOnGimig']) && $p['showOnGimig'] === false)) continue;
            $price = round(floatval(str_replace(',', '.', (string) ($p['price'] ?? 0))), 2);
            $ck = (string) ($p['categoryId'] ?? $p['category'] ?? $p['categoryName'] ?? $p['cat'] ?? '');
            $cat = $cats[$ck] ?? $cats[mb_strtolower($ck)] ?? ($ck !== '' && !preg_match('/^[a-f0-9]{24}$/', $ck) ? $ck : 'Weitere');
            if (!isset($order[$cat])) $order[$cat] = 1000 + count($order);
            $info = trim(wp_strip_all_tags((string) ($p['infoText'] ?? $p['description'] ?? $p['info'] ?? '')));
            $groups[$cat][] = array_filter(['name' => mb_substr($name, 0, 80), 'price' => $price, 'info' => $info !== '' ? mb_substr($info, 0, 200) : null], function ($v) { return $v !== null; });
        }
        uksort($groups, function ($a, $b) use ($order) { return ($order[$a] ?? 0) <=> ($order[$b] ?? 0); });
        $out = [];
        foreach ($groups as $n => $items) $out[] = ['name' => mb_substr((string) $n, 0, 60), 'items' => $items];
        return ['cashes' => $cashes, 'menu' => $out];
    }

    /** Karte von Degaso holen und speichern. → true | Fehlertext (der alte Stand bleibt) */
    public static function refresh() {
        $s = TIX_Degaso::settings();
        $r = TIX_Degaso::call('/getMenuByCustomer', []);
        if (!$r['ok']) return $r['reason'] === 'network' ? 'Die Degaso-Kasse ist gerade nicht erreichbar.' : 'Degaso liefert keine Karte (' . $r['reason'] . ').';
        $n = self::normalize($r['data'], (string) $s['menu_cash']);
        update_option(self::CACHE, ['fetched_at' => time(), 'cash' => (string) $s['menu_cash'], 'cashes' => $n['cashes'], 'categories' => $n['menu']], false);
        return true;
    }

    /** Gespeicherte Karte; holt neu, wenn älter als 30 min (höchstens ein Abruf je Minute) */
    public static function cached($force = false) {
        $c = get_option(self::CACHE, []);
        $stale = !is_array($c) || empty($c['fetched_at']) || time() - intval($c['fetched_at']) > self::TTL;
        if (($force || $stale) && !get_transient('tix_dg_menu_lock')) {
            set_transient('tix_dg_menu_lock', 1, 60);
            self::refresh();
            $c = get_option(self::CACHE, []);
        }
        return is_array($c) ? $c : [];
    }

    public static function cron() {
        if (self::active()) self::cached();
    }

    /** Öffentliche Karte (ohne ausgeblendete Kategorien) */
    public static function public_payload() {
        $s = TIX_Degaso::settings();
        if (!self::active()) return ['ok' => true, 'enabled' => false];
        $c = self::cached();
        $hidden = array_map('mb_strtolower', (array) $s['menu_hidden']);
        $cats = array_values(array_filter((array) ($c['categories'] ?? []), function ($g) use ($hidden) { return !in_array(mb_strtolower((string) $g['name']), $hidden, true) && !empty($g['items']); }));
        return [
            'ok' => true, 'enabled' => true, 'title' => 'Getränkekarte',
            'updated_at' => !empty($c['fetched_at']) ? gmdate('c', intval($c['fetched_at'])) : null,
            'categories' => $cats, 'youth_note' => self::youth_note($s['menu_youth']),
            'note' => (string) $s['menu_note'], 'currency' => 'EUR',
        ];
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        register_rest_route(self::NS, '/public/drinks', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_public'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/degaso/admin/menu', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_admin'], 'permission_callback' => ['TIX_Degaso', 'check_admin']]);
    }

    public static function rest_public(WP_REST_Request $req) {
        $r = new WP_REST_Response(self::public_payload());
        $r->header('Cache-Control', 'public, max-age=300');
        return $r;
    }

    /** Studio: frische Vorschau mit allen Kategorien (ausgeblendete markiert) und der Kassenauswahl */
    public static function rest_admin(WP_REST_Request $req) {
        if (!TIX_Degaso::connected()) return new WP_Error('tix_degaso_nokey', 'Es ist noch kein Degaso-Schlüssel eingetragen.', ['status' => 409]);
        $err = filter_var($req->get_param('fresh'), FILTER_VALIDATE_BOOLEAN) ? self::refresh() : true;
        $c = self::cached();
        $s = TIX_Degaso::settings();
        $hidden = array_map('mb_strtolower', (array) $s['menu_hidden']);
        $cats = array_map(function ($g) use ($hidden) { return ['name' => $g['name'], 'count' => count($g['items']), 'hidden' => in_array(mb_strtolower($g['name']), $hidden, true), 'items' => array_slice($g['items'], 0, 50)]; }, (array) ($c['categories'] ?? []));
        $r = new WP_REST_Response(['ok' => $err === true, 'message' => $err === true ? '' : $err, 'updated_at' => !empty($c['fetched_at']) ? gmdate('c', intval($c['fetched_at'])) : null,
            'cashes' => array_values((array) ($c['cashes'] ?? [])), 'categories' => $cats, 'youth_note' => self::youth_note($s['menu_youth'])]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }
}

TIX_Degaso_Menu::init();
