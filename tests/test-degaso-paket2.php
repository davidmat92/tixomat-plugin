<?php
/**
 * Unit-Tests für TIX_Degaso (Degaso-Kasse, Gutscheine, 1.38.556). Ohne WordPress, mit kleinen Stubs.
 * Die Degaso Voucher API ist als Attrappe nachgebaut (FakeDegaso, Verhalten laut https://www.degaso.de/apidoc/voucher-openapi.html):
 * Code frei wählbar, ERROR_EXISTS, paymentId je Gutschein nur einmal (ERROR_PAYMENT_ID_USED), Einlösen alles oder nichts
 * (ERROR_VALUE_CHANGED), Verlauf nur 10 Einträge, includeOrigin, Löschen nur bei Saldo 0, unbekannter Schlüssel → 403.
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/test-degaso.php
 */
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__ . '/');

// ── Stubs ──
$GLOBALS['t_options'] = [];
$GLOBALS['t_trans'] = [];
$GLOBALS['t_meta'] = [];
$GLOBALS['t_hooks'] = [];
$GLOBALS['t_admin'] = true;
$GLOBALS['t_checkins'] = [];

class WP_Error {
    public $code; public $message; public $data;
    function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->message = $m; $this->data = $d; }
    function get_error_code() { return $this->code; }
    function get_error_message() { return $this->message; }
    function get_error_data() { return $this->data; }
}
class WP_REST_Response {
    public $data; public $status; public $headers = [];
    function __construct($d = null, $s = 200) { $this->data = $d; $this->status = $s; }
    function get_data() { return $this->data; }
    function header($k, $v) { $this->headers[$k] = $v; }
}
class WP_REST_Request implements ArrayAccess {
    public $params = []; public $json = null;
    function __construct($p = [], $json = null) { $this->params = $p; $this->json = $json; }
    function get_param($k) { return $this->params[$k] ?? null; }
    function get_params() { return $this->params; }
    function get_json_params() { return $this->json; }
    function offsetExists($k): bool { return isset($this->params[$k]); }
    function offsetGet($k): mixed { return $this->params[$k] ?? null; }
    function offsetSet($k, $v): void { $this->params[$k] = $v; }
    function offsetUnset($k): void { unset($this->params[$k]); }
}
class FakeWpdb {
    function prepare($q, ...$a) { return $q; }
    function get_var($q) { return 1; }
    function query($q) { return true; }
}
$wpdb = new FakeWpdb();

function is_wp_error($x) { return $x instanceof WP_Error; }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['t_options']) ? $GLOBALS['t_options'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['t_options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['t_options'][$k]); return true; }
function get_transient($k) { return $GLOBALS['t_trans'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['t_trans'][$k] = $v; return true; }
function wp_cache_delete($k, $g = '') { return true; }
function current_time($f) { return $f === 'timestamp' ? time() : date($f === 'mysql' ? 'Y-m-d H:i:s' : $f); }
function get_date_from_gmt($s) { return $s; }
function wp_generate_password($n = 12, $s = true) { return substr(bin2hex(random_bytes(16)), 0, $n); }
function wp_salt($s = '') { return 'testsalt'; }
function home_url($p = '') { return 'https://kitchenklub.de' . $p; }
function wp_json_encode($d, $f = 0) { return json_encode($d, $f); }
function get_post_meta($id, $k, $single = true) { return $GLOBALS['t_meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['t_meta'][$id][$k] = $v; return true; }
function current_user_can($c) { return $GLOBALS['t_admin']; }
function wp_get_current_user() { return (object) ['ID' => 7, 'user_login' => 'bar1']; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function date_i18n($f, $t) { return date($f, $t); }
function number_format_i18n($n) { return (string) $n; }
function wp_next_scheduled($h) { return 1; }
function wp_schedule_event() { return true; }
function register_rest_route() { return true; }
function add_shortcode() {}
function add_action($h, $cb, $p = 10, $n = 1) { $GLOBALS['t_hooks'][$h][$p][] = [$cb, $n]; }
function add_filter($h, $cb, $p = 10, $n = 1) { add_action($h, $cb, $p, $n); }
function has_action($h) { return !empty($GLOBALS['t_hooks'][$h]); }
function do_action($h, ...$a) {
    $l = $GLOBALS['t_hooks'][$h] ?? [];
    ksort($l);
    foreach ($l as $cbs) foreach ($cbs as list($cb, $n)) call_user_func_array($cb, array_slice($a, 0, $n));
}
function apply_filters($h, $v, ...$a) {
    $l = $GLOBALS['t_hooks'][$h] ?? [];
    ksort($l);
    foreach ($l as $cbs) foreach ($cbs as list($cb, $n)) $v = call_user_func_array($cb, array_slice(array_merge([$v], $a), 0, $n));
    return $v;
}
function get_bloginfo($k) { return "KitchenKlub"; }
function get_the_title($id) { return ""; }
function get_posts($a) { return []; } // create_for_order findet keine Tickets: Karten legt der Test selbst an
class TIX_Tickets { static function checkin_ticket($id, $by) { $GLOBALS['t_checkins'][] = [$id, $by]; } }

// ── Degaso-Attrappe ──
class FakeDegaso {
    public $keys = ['11111111-2222-3333-4444-555555555555', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'];
    public $v = [];          // [key][code] = ['balance','active','history'=>[], 'pids'=>[]]
    public $down = false;
    public $calls = [];
    private $seq = 0;
    function err($status, $reason) { return [$status, ['success' => false, 'message' => $reason, 'failureReason' => $reason]]; }
    function voucher($k, $code, $origin) {
        $o = $this->v[$k][$code];
        $h = array_map(function ($e) use ($origin) { $x = $e; unset($x['_origin']); if ($origin) $x['origin'] = $e['_origin']; return $x; }, $o['history']);
        return ['_id' => 'x', 'apiKey' => $k, 'objectId' => $code, 'object' => ['balance' => $o['balance'], 'active' => $o['active'], 'history' => $h]];
    }
    function hist($k, $code, $amount, $pid, $order = null, $origin = ['kind' => 'unknown']) {
        $this->seq++;
        $e = ['timestamp' => gmdate('Y-m-d\TH:i:s', time() + $this->seq) . '.000Z', 'amount' => $amount, 'paymentId' => $pid, '_origin' => $origin];
        if ($order !== null) $e['orderId'] = $order;
        $this->v[$k][$code]['history'][] = $e;
        $this->v[$k][$code]['history'] = array_slice($this->v[$k][$code]['history'], -10); // nur die letzten 10
        $this->v[$k][$code]['pids'][] = $pid;
    }
    /** Einlösung an der Degaso-Kasse (Bar), wie sie die Kasse selbst bucht */
    function register_pay($code, $amount, $bon = 4711) {
        $k = $this->keys[0];
        $this->v[$k][$code]['balance'] = round($this->v[$k][$code]['balance'] - $amount, 2);
        $this->hist($k, $code, -$amount, 'BELEG-' . $bon, null, ['kind' => 'register', 'cashName' => 'Bar 1', 'checkoutNumber' => $bon, 'employee' => 'Sam']);
    }
    function __invoke($path, $b) {
        $this->calls[] = [$path, $b];
        if ($this->down) return [0, null];
        $k = $b['apiKey'] ?? '';
        if (!in_array($k, $this->keys, true)) return $this->err(403, 'ERROR_FORBIDDEN');
        $this->v[$k] = $this->v[$k] ?? [];
        $origin = !empty($b['includeOrigin']);
        switch ($path) {
            case '/vouchers/new':
                $code = $b['objectId'] ?? ('DG' . (++$this->seq));
                if (isset($this->v[$k][$code])) return $this->err(400, 'ERROR_EXISTS');
                $this->v[$k][$code] = ['balance' => 0, 'active' => true, 'history' => [], 'pids' => []];
                return [200, ['success' => true, 'voucher' => $this->voucher($k, $code, false)]];
            case '/vouchers/topup':
                $code = $b['objectId'] ?? '';
                if (empty($b['paymentId']) || !isset($b['topupAmount']) || $b['topupAmount'] <= 0) return $this->err(403, 'ERROR_FORBIDDEN');
                if (!isset($this->v[$k][$code])) return $this->err(404, 'ERROR_NOT_FOUND');
                if (!$this->v[$k][$code]['active']) return $this->err(400, 'ERROR_INACTIVE');
                if (in_array($b['paymentId'], $this->v[$k][$code]['pids'], true)) return $this->err(400, 'ERROR_PAYMENT_ID_USED');
                $this->v[$k][$code]['balance'] = round($this->v[$k][$code]['balance'] + $b['topupAmount'], 2);
                $this->hist($k, $code, $b['topupAmount'], $b['paymentId']);
                return [200, ['success' => true, 'voucher' => $this->voucher($k, $code, false)]];
            case '/vouchers/payment':
                $codes = (array) ($b['objectIds'] ?? []);
                $amt = round(floatval($b['totalRedeemAmount'] ?? 0), 2);
                if (!$codes || $amt <= 0 || empty($b['paymentId'])) return $this->err(403, 'ERROR_FORBIDDEN');
                $sum = 0;
                foreach ($codes as $c) {
                    if (!isset($this->v[$k][$c])) return $this->err(404, 'ERROR_NOT_FOUND');
                    if (!$this->v[$k][$c]['active']) return $this->err(400, 'ERROR_INACTIVE');
                    if (in_array($b['paymentId'], $this->v[$k][$c]['pids'], true)) return $this->err(400, 'ERROR_PAYMENT_ID_USED');
                    $sum += $this->v[$k][$c]['balance'];
                }
                if ($sum + 0.001 < $amt) return $this->err(400, 'ERROR_VALUE_CHANGED'); // alles oder nichts
                $left = $amt;
                foreach ($codes as $c) {
                    $take = round(min($left, $this->v[$k][$c]['balance']), 2);
                    if ($take <= 0) continue;
                    $this->v[$k][$c]['balance'] = round($this->v[$k][$c]['balance'] - $take, 2);
                    $this->hist($k, $c, -$take, $b['paymentId'], $b['orderId'] ?? null);
                    $left = round($left - $take, 2);
                }
                return [200, ['success' => true, 'vouchers' => array_map(function ($c) use ($k) { return $this->voucher($k, $c, false); }, $codes)]];
            case '/vouchers/get':
                $out = [];
                foreach ((array) ($b['objectIds'] ?? []) as $c) if (isset($this->v[$k][$c])) $out[] = $this->voucher($k, $c, $origin); // unbekannte still übersprungen
                return [200, ['success' => true, 'vouchers' => $out]];
            case '/vouchers/list':
                $out = [];
                foreach ($this->v[$k] as $c => $o) {
                    if (!empty($b['from'])) {
                        $hit = array_filter($o['history'], function ($e) use ($b) { return strtotime($e['timestamp']) >= strtotime($b['from']); });
                        if (!$hit) continue;
                    }
                    $out[$c] = $this->voucher($k, $c, $origin);
                }
                if (!empty($b['onlyCodes'])) return [200, ['success' => true, 'objectIds' => array_keys($out), 'total' => count($out)]];
                return [200, ['success' => true, 'vouchers' => array_values($out), 'total' => count($out)]];
            case '/vouchers/delete':
                $c = $b['objectId'] ?? '';
                if (!isset($this->v[$k][$c])) return $this->err(404, 'ERROR_NOT_FOUND');
                if ($this->v[$k][$c]['balance'] > 0) return $this->err(400, 'ERROR_VALUE_REMAINING');
                unset($this->v[$k][$c]);
                return [200, ['success' => true]];
        }
        return [404, ['success' => false]];
    }
    function bal($code, $ki = 0) { return $this->v[$this->keys[$ki]][$code]['balance'] ?? null; }
    function count($path) { return count(array_filter($this->calls, function ($c) use ($path) { return $c[0] === $path; })); }
}


function delete_transient($k) { unset($GLOBALS["t_trans"][$k]); return true; }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }

// ── Erweiterung der Attrappe: Selforder-Menü, Kassenliste, Kasse über den Router ──
class FakeDegaso2 extends FakeDegaso {
    public $menus = [];
    public $registers = ['Bar 1', 'Eingang'];
    public $offline = [];         // Kassen, die aus sind
    public $orders = [];          // [cash][id] = ['articles', 'paid' => false, 'storno' => false, 'pays' => []]
    public $products = ['Eingang' => [['_id' => 'p-eintritt', 'name' => 'Eintritt', 'price' => '10.00', 'tax' => '19', 'category' => 'Tickets'], ['_id' => 'p-vvk', 'name' => 'Eintritt VVK', 'price' => '8.00', 'tax' => '19', 'category' => 'Tickets']]];
    public $failPay = false;
    private $n = 0;
    function __invoke($path, $b) {
        if (strpos($path, '@register') === 0) {
            $this->calls[] = [$path, $b];
            if ($this->down) return [0, null];
            if (!in_array($b['_key'], $this->keys, true)) return [403, ['message' => 'forbidden']];
            $cash = $b['_cash'];
            if (in_array($cash, $this->offline, true) || !in_array($cash, $this->registers, true)) return [502, ['message' => 'Kasse nicht erreichbar']];
            $ep = substr($path, 9);
            if ($ep === '/getAllProducts') return [200, $this->products[$cash] ?? []];
            if ($ep === '/orderArticles') {
                if (empty($b['articles'])) return [400, []];
                $id = 'ord' . (++$this->n);
                $this->orders[$cash][$id] = ['articles' => $b['articles'], 'paid' => false, 'storno' => false, 'pays' => []];
                return [200, ['_id' => $id, 'table' => $b['table']]];
            }
            if ($ep === '/payOrder') {
                $o = &$this->orders[$cash][$b['orderId']];
                if (!$o) return [500, []];
                if (!empty($b['isStorno'])) { $o['storno'] = true; $o['pays'][] = $b; return [200, ['ok' => true]]; }
                if ($this->failPay) return [500, ['message' => 'TSE-Fehler']];
                if ($o['paid']) return [201, ['message' => 'bereits bezahlt']];
                $o['paid'] = true; $o['pays'][] = $b;
                return [200, ['ok' => true, 'receiptNumber' => 4711, 'tseSignature' => 'SIG==', 'apiKey' => $b['_key']]];
            }
            if ($ep === '/stornoItem') { $this->orders[$cash][$b['orderId']]['storno'] = true; return [200, []]; }
            return [404, []];
        }
        if ($path === '/getMenuByCustomer') {
            $this->calls[] = [$path, $b];
            if ($this->down) return [0, null];
            if (!in_array($b['apiKey'] ?? '', $this->keys, true)) return $this->err(403, 'ERROR_FORBIDDEN');
            return [200, $this->menus];
        }
        if ($path === '/getRegisters') {
            $this->calls[] = [$path, $b];
            return [200, array_map(function ($n) { return ['_id' => md5($n), 'name' => $n, 'apiKey' => 'GEHEIM']; }, $this->registers)];
        }
        return parent::__invoke($path, $b);
    }
}

require __DIR__ . '/../includes/class-tix-giftcards.php';
require __DIR__ . '/../includes/class-tix-degaso.php';
require __DIR__ . '/../includes/class-tix-degaso-menu.php';
require __DIR__ . '/../includes/class-tix-degaso-pos.php';

$fake = new FakeDegaso2();
TIX_Degaso::$transport = $fake;
$LIVE = $fake->keys[0];
$fails = 0; $count = 0;
function eq($label, $expected, $actual) {
    global $fails, $count;
    $count++;
    $ok = is_float($expected) || is_float($actual) ? abs(floatval($expected) - floatval($actual)) < 0.001 : $expected === $actual;
    if (!$ok) { $fails++; echo "FAIL  $label: erwartet " . var_export($expected, true) . ", ist " . var_export($actual, true) . "\n"; }
}
function ok($cond, $label) { eq($label, true, (bool) $cond); }
function save($p) { return TIX_Degaso::rest_save(new WP_REST_Request([], $p)); }
$sale = function ($payment = 'cash', $total = 30.0, $discount = 0.0) {
    return ['event_id' => 25, 'event_title' => 'SHOT NIGHT', 'items' => [['name' => 'Abendkasse', 'price' => 10.0, 'qty' => 2], ['name' => 'Ermäßigt', 'price' => 10.0, 'qty' => 1]],
        'subtotal' => 30.0, 'discount' => $discount, 'total' => $total, 'payment' => $payment, 'coupon' => null];
};

// ════════ A) Getränkekarte ════════
$fake->menus = [
    ['cashName' => 'Bar 1', 'categories' => [['_id' => 'c2', 'name' => 'Longdrinks', 'order' => 2], ['_id' => 'c1', 'name' => 'Bier', 'order' => 1], ['_id' => 'c3', 'name' => 'Personal', 'order' => 3]],
     'products' => [
        ['name' => 'Pils 0,3', 'price' => '3.50', 'categoryId' => 'c1', 'infoText' => 'vom Fass'],
        ['name' => 'Wodka-E', 'price' => '8,00', 'category' => 'c2'],
        ['name' => 'Gin Tonic', 'price' => 9, 'category' => 'Longdrinks', 'showOnGimig' => false],
        ['name' => 'Mitarbeitergetränk', 'price' => 0, 'categoryId' => 'c3'],
        ['name' => 'Wasser', 'price' => '2.50', 'categoryName' => 'Alkoholfrei'],
     ], 'apiKey' => 'GEHEIM'],
    ['cashName' => 'Eingang', 'categories' => [], 'products' => [['name' => 'Garderobe', 'price' => '2']]],
];
eq('aus: öffentlich nicht aktiv', false, TIX_Degaso_Menu::public_payload()['enabled']);
save(['api_key' => $LIVE, 'menu_enabled' => true]);
eq('Anbindung aus: Karte ohne Wirkung', false, TIX_Degaso_Menu::public_payload()['enabled']);
eq('Anbindung aus: Abendkasse ohne Bon', null, apply_filters('tix_pos_sign_sale', null, $sale()));
save(['menu_enabled' => false]);
TIX_Degaso::set_integration(true);
eq('nur Schlüssel: Karte bleibt aus (Standard aus)', false, TIX_Degaso_Menu::public_payload()['enabled']);
ok(!TIX_Degaso::active(), 'Gutscheine bleiben aus, wenn nur der Schlüssel gesetzt ist');
save(['menu_enabled' => true, 'menu_cash' => 'Bar 1', 'menu_hidden' => ['Personal']]);
$m = TIX_Degaso_Menu::public_payload();
ok($m['enabled'], 'an: Karte aktiv');
eq('Kategorien in Degaso-Reihenfolge, ausgeblendete fehlen', ['Bier', 'Longdrinks', 'Alkoholfrei'], array_column($m['categories'], 'name'));
eq('Preis aus Text mit Punkt', 3.5, $m['categories'][0]['items'][0]['price']);
eq('Preis mit Komma', 8.0, $m['categories'][1]['items'][0]['price']);
eq('Info-Text', 'vom Fass', $m['categories'][0]['items'][0]['info']);
eq('im Selforder versteckte Artikel fehlen', 1, count($m['categories'][1]['items']));
ok(strpos($m['youth_note'], 'ab 16') !== false && strpos($m['youth_note'], '§ 9 JuSchG') !== false, 'Jugendschutz-Hinweis 16/18');
ok(strpos(json_encode($m), 'GEHEIM') === false && strpos(json_encode($m), $LIVE) === false, 'keine Schlüssel in der öffentlichen Karte');
$calls = $fake->count('/getMenuByCustomer');
TIX_Degaso_Menu::public_payload();
eq('zweiter Abruf aus dem Zwischenspeicher', $calls, $fake->count('/getMenuByCustomer'));
// offline: alter Stand bleibt
$c = get_option('tix_degaso_menu'); $c['fetched_at'] = time() - 4000; update_option('tix_degaso_menu', $c); $GLOBALS['t_trans'] = [];
$fake->down = true;
$m = TIX_Degaso_Menu::public_payload();
eq('Degaso weg: letzter Stand bleibt', 3, count($m['categories']));
$fake->down = false;
save(['menu_youth' => '18', 'menu_note' => 'Preise inkl. MwSt.']);
$m = TIX_Degaso_Menu::public_payload();
ok(strpos($m['youth_note'], 'ab 18') !== false && strpos($m['youth_note'], 'ab 16') === false, 'Einstellung alles ab 18');
eq('eigener Hinweis', 'Preise inkl. MwSt.', $m['note']);
save(['menu_cash' => 'Eingang']);
$m = TIX_Degaso_Menu::public_payload();
eq('andere Kasse: andere Karte (ohne Kategorien → „Weitere“)', 'Weitere', $m['categories'][0]['name']);
$a = TIX_Degaso_Menu::rest_admin(new WP_REST_Request(['fresh' => '1']))->get_data();
eq('Studio: Kassen zur Auswahl', ['Bar 1', 'Eingang'], $a['cashes']);
// Menü als einzelnes Objekt und als {data: [...]}
$n = TIX_Degaso_Menu::normalize(['data' => [['name' => 'Bar', 'products' => [['name' => 'Cola', 'price' => '3']]]]]);
eq('Format {data: [...]}', 'Cola', $n['menu'][0]['items'][0]['name']);
$n = TIX_Degaso_Menu::normalize(['products' => [['name' => 'Cola', 'price' => '3', 'category' => 'Softdrinks']]]);
eq('Format einzelnes Menü', 'Softdrinks', $n['menu'][0]['name']);
eq('Unsinn → leer', [], TIX_Degaso_Menu::normalize('kaputt')['menu']);
save(['menu_enabled' => false]);
eq('wieder aus', false, TIX_Degaso_Menu::public_payload()['enabled']);

// ════════ B) Abendkasse über Degaso-TSE ════════
eq('aus: kein Bon nötig', null, apply_filters('tix_pos_sign_sale', null, $sale()));
$e = save(['tse_enabled' => true]);
ok(is_wp_error($e) && $e->data['field'] === 'tse_enabled', 'an ohne Kasse/Artikel → Fehler');
$p = save(['tse_cash' => 'Eingang', 'tse_default' => ['id' => 'p-eintritt', 'name' => 'Eintritt'], 'tse_map' => ['Ermäßigt' => ['id' => 'p-vvk', 'name' => 'Eintritt VVK']], 'tse_enabled' => true])->get_data();
ok($p['settings']['tse_enabled'], 'an mit Kasse und Standard-Artikel');
$adm = TIX_Degaso_Pos::rest_admin(new WP_REST_Request([]))->get_data();
eq('Studio: Artikel der Kasse live', ['p-eintritt', 'p-vvk'], array_column($adm['products'], 'id'));
ok(strpos(json_encode($adm), 'GEHEIM') === false, 'Kassenliste ohne Schlüssel');

$r = apply_filters('tix_pos_sign_sale', null, $sale('cash'));
ok(is_array($r) && $r['provider'] === 'degaso', 'Barverkauf → Bon');
$o = $fake->orders['Eingang'][$r['order_id']];
ok($o['paid'], 'bei Degaso bezahlt (TSE)');
eq('Zahlart BAR', 'BAR', $o['pays'][0]['payment']);
eq('Summe', '30.00', $o['pays'][0]['total']);
eq('Zuordnung Ticketart → Artikel', ['p-eintritt', 'p-vvk'], array_column($o['articles'], '_id'));
eq('Bon-Angaben ohne Schlüssel', ['receiptNumber' => '4711', 'tseSignature' => 'SIG=='], $r['receipt']);
$r = apply_filters('tix_pos_sign_sale', null, $sale('card'));
eq('Karte → EC', 'EC', $fake->orders['Eingang'][$r['order_id']]['pays'][0]['payment']);
eq('kostenlos → kein Bon', null, apply_filters('tix_pos_sign_sale', null, $sale('free', 0.0)));
// Rabatt anteilig, Summe stimmt auf den Cent
$r = apply_filters('tix_pos_sign_sale', null, $sale('cash', 20.0, 10.0));
$sum = 0; foreach ($fake->orders['Eingang'][$r['order_id']]['articles'] as $l) $sum += floatval($l['price']) * $l['quantity'];
eq('Rabatt: Bon-Summe = bezahlter Betrag', 20.0, round($sum, 2));
$r = apply_filters('tix_pos_sign_sale', null, $sale('cash', 19.99, 10.01));
$sum = 0; foreach ($fake->orders['Eingang'][$r['order_id']]['articles'] as $l) $sum += floatval($l['price']) * $l['quantity'];
eq('krumme Rabatte: Summe auf den Cent', 19.99, round($sum, 2));
ok(strpos($fake->orders['Eingang'][$r['order_id']]['pays'][0]['freeText'], 'Rabatt/Gutschein 10,01 €') !== false, 'Rabatt im Freitext');

// Kasse aus → kein Verkauf
$fake->offline = ['Eingang'];
$before = count($fake->orders['Eingang']);
$e = apply_filters('tix_pos_sign_sale', null, $sale('cash'));
ok(is_wp_error($e) && $e->code === 'tix_pos_tse_unavailable' && $e->data['status'] === 503, 'Kasse aus → 503, kein Verkauf');
ok(strpos($e->message, 'nicht erreichbar') !== false && strpos($e->message, 'Ohne Kassenbon') !== false, 'klarer Hinweis');
eq('nichts bei Degaso angelegt', $before, count($fake->orders['Eingang']));
$fake->offline = [];
$fake->down = true;
ok(is_wp_error(apply_filters('tix_pos_sign_sale', null, $sale('card'))), 'Netz weg → kein Verkauf');
$fake->down = false;
// Bezahlen scheitert (TSE) → offene Bestellung abgeräumt, kein Verkauf
$fake->failPay = true;
$e = apply_filters('tix_pos_sign_sale', null, $sale('cash'));
ok(is_wp_error($e), 'TSE-Fehler beim Bezahlen → kein Verkauf');
$last = end($fake->orders['Eingang']);
ok($last['storno'] && !$last['paid'], 'offene Degaso-Bestellung storniert');
$fake->failPay = false;

// Bestellung in Tixomat scheitert nach dem Bon → Storno-Bon
$r = apply_filters('tix_pos_sign_sale', null, $sale('cash'));
do_action('tix_pos_sign_cancel', $r, 'order_failed');
$o = $fake->orders['Eingang'][$r['order_id']];
ok($o['storno'], 'Storno-Bon nach gescheiterter Bestellung');
eq('Storno mit negativer Summe', '-30.00', end($o['pays'])['total']);
// Kassen-Storno
$r = apply_filters('tix_pos_sign_sale', null, $sale('card'));
do_action('tix_pos_signed', 901, $r);
ok(is_array(get_option('_tix_pos_receipt_901')), 'Beleg an der Bestellung gemerkt');
do_action('tix_pos_voided', 901);
ok($fake->orders['Eingang'][$r['order_id']]['storno'], 'Kassen-Storno → Storno-Bon');
$n = count($fake->orders['Eingang'][$r['order_id']]['pays']);
do_action('tix_pos_voided', 901);
eq('Storno doppelt → nur ein Storno-Bon', $n, count($fake->orders['Eingang'][$r['order_id']]['pays']));
do_action('tix_pos_voided', 902);
ok(true, 'Storno ohne Bon (vor der Anbindung) → nichts');

// Gutscheine unberührt, solange ihr Schalter aus ist
ok(!TIX_Degaso::active(), 'Gutschein-Abgleich weiter aus');
TIX_Degaso::set_integration(false);
eq('Anbindung aus → Abendkasse wieder ohne Bon (Einstellung bleibt)', null, apply_filters('tix_pos_sign_sale', null, $sale()));
ok(TIX_Degaso::settings()['tse_enabled'] && TIX_Degaso::key() !== '', 'Schlüssel und Einstellungen bleiben gespeichert');
TIX_Degaso::set_integration(true);
save(['tse_enabled' => false]);
eq('aus → wieder ohne Bon', null, apply_filters('tix_pos_sign_sale', null, $sale()));

echo ($fails ? "$fails von $count Prüfungen fehlgeschlagen\n" : "Alle $count Prüfungen grün\n");
exit($fails ? 1 : 0);
