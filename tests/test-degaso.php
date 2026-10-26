<?php
/**
 * Unit-Tests für TIX_Degaso (Degaso-Kasse, Gutscheine, 1.38.555). Ohne WordPress, mit kleinen Stubs.
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

require __DIR__ . '/../includes/class-tix-giftcards.php';
require __DIR__ . '/../includes/class-tix-giftcard-pos.php';
require __DIR__ . '/../includes/class-tix-degaso.php';

$fake = new FakeDegaso();
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
function card($code) { return TIX_Giftcards::get_card($code); }
function add_card($code, $value, $order = 0, $balance = null, $expires = null) {
    $c = get_option('tix_coupons', []);
    $c[$code] = ['discount_type' => 'giftcard', 'value' => $value, 'balance' => $balance ?? $value, 'expires' => $expires ?? date('Y-m-d', strtotime('+3 years')),
        'order_id' => $order, 'ticket_id' => $order ? 500 + $order : 0, 'redemptions' => []];
    update_option('tix_coupons', $c);
}
function save($p) { return TIX_Degaso::rest_save(new WP_REST_Request([], $p)); }

// ── 1. Aus: nichts passiert, Bar wie bisher ──
add_card('GS-AAAA-0001', 50, 1);
do_action('tix_order_completed', 1);
eq('aus: kein Aufruf an Degaso', 0, count($fake->calls));
ok(TIX_Degaso::bar_allowed(), 'aus: Bar-Abbuchung erlaubt');
$r = TIX_Giftcards::redeem('GS-AAAA-0001', 10, 'online', 'order#9');
ok($r['ok'] && abs($r['balance'] - 40) < 0.001, 'aus: lokal abgebucht wie bisher');

// ── 2. Schlüssel speichern: falsch → abgelehnt, richtig → verschlüsselt, nie im Klartext ──
$e = save(['api_key' => 'kein schlüssel!']);
ok(is_wp_error($e) && $e->code === 'tix_invalid_field', 'Format falsch → Fehler am Feld');
$e = save(['api_key' => '99999999-2222-3333-4444-555555555555']);
ok(is_wp_error($e) && $e->code === 'tix_degaso_check', 'unbekannter Schlüssel (403) → nicht gespeichert');
eq('kein Schlüssel gespeichert', '', TIX_Degaso::settings()['api_key']);
$p = save(['api_key' => $LIVE, 'enabled' => true])->get_data();
ok($p['active'], 'aktiv nach Speichern');
eq('letzte 4 Zeichen', '5555', $p['key']['last4']);
ok(strpos(json_encode($p), $LIVE) === false, 'Antwort enthält den Schlüssel nicht');
ok(strpos(json_encode($GLOBALS['t_options']['tix_degaso']), $LIVE) === false, 'Option speichert verschlüsselt');
eq('Entschlüsseln', $LIVE, TIX_Degaso::key());
ok(!TIX_Degaso::bar_allowed(), 'aktiv: Bar-Abbuchung in Tixomat standardmäßig aus');
eq('Ablauf Standard: nichts tun', 'none', $p['settings']['expiry_action']);
$c = TIX_Degaso::rest_check(new WP_REST_Request([]))->get_data();
ok($c['ok'], 'Verbindung prüfen mit gespeichertem Schlüssel');
$c = TIX_Degaso::rest_check(new WP_REST_Request(['api_key' => 'ffffffff-2222-3333-4444-555555555555']))->get_data();
ok(!$c['ok'] && $c['reason'] === 'ERROR_FORBIDDEN', 'Verbindung prüfen mit fremdem Schlüssel → Klartext-Fehler');

// ── 3. Ausstellen bei bezahlter Bestellung ──
$fake->calls = [];
add_card('GS-BBBB-0002', 50, 2);
do_action('tix_order_completed', 2);
eq('Degaso: Gutschein angelegt mit Saldo 50', 50.0, $fake->bal('GS-BBBB-0002'));
eq('paymentId der Ausgabe', 'tix-kitchenklub-de-o-2-GS-BBBB-0002', $fake->v[$LIVE]['GS-BBBB-0002']['history'][0]['paymentId']);
eq('Zustand ok', 'ok', TIX_Degaso::state('GS-BBBB-0002')['state']);
do_action('tix_order_completed', 2); // Hook läuft doppelt
eq('zweiter Lauf: keine zweite Aufladung', 1, $fake->count('/vouchers/topup'));
// Zustand verloren (z. B. Option zurückgesetzt) → ERROR_EXISTS, Verlauf ist unser → ok, nichts doppelt
delete_option('tix_degaso_state');
do_action('tix_order_completed', 2);
eq('nach Zustandsverlust: Saldo bleibt 50', 50.0, $fake->bal('GS-BBBB-0002'));
eq('nach Zustandsverlust: wieder ok', 'ok', TIX_Degaso::state('GS-BBBB-0002')['state']);

// ── 4. Einlösen online (bezahlte Bestellung) → erst Degaso, dann lokal mit Degaso-Saldo ──
$r = TIX_Giftcards::redeem('GS-BBBB-0002', 20, 'online', 'order#77');
ok($r['ok'], 'online eingelöst');
eq('Degaso-Saldo 30', 30.0, $fake->bal('GS-BBBB-0002'));
eq('lokaler Saldo gespiegelt', 30.0, card('GS-BBBB-0002')['balance']);
eq('orderId im Degaso-Verlauf', 'order#77', end($fake->v[$LIVE]['GS-BBBB-0002']['history'])['orderId']);

// ── 5. An der Degaso-Kasse eingelöst → Abgleich holt es ab (Kasse, Bon-Nr.), Ticket entwertet bei 0 ──
$fake->register_pay('GS-BBBB-0002', 12.5, 815);
eq('lokal vor dem Abgleich unverändert', 30.0, card('GS-BBBB-0002')['balance']);
$n = TIX_Degaso::pull();
eq('ein Gutschein aktualisiert', 1, $n);
eq('lokal 17,50 nach Abgleich', 17.5, card('GS-BBBB-0002')['balance']);
$last = end(card('GS-BBBB-0002')['redemptions']);
eq('Kanal degaso', 'degaso', $last['channel']);
eq('Ort aus includeOrigin', 'degaso:Bar 1 #815', $last['ref']);
eq('Betrag positiv wie Tixomat-Einlösungen', 12.5, $last['amount']);
TIX_Degaso::pull();
eq('zweiter Abgleich: kein Doppel-Eintrag', 17.5, card('GS-BBBB-0002')['balance']);
eq('Verlauf unverändert', 2, count(card('GS-BBBB-0002')['redemptions']));
$fake->register_pay('GS-BBBB-0002', 17.5, 816);
TIX_Degaso::pull();
eq('aufgebraucht an der Bar → lokal 0', 0.0, card('GS-BBBB-0002')['balance']);
ok(in_array([502, 'giftcard-degaso'], $GLOBALS['t_checkins'], true), 'Gutschein-Ticket bei 0 entwertet');

// ── 6. Einlösen vor Ort in Tixomat (Guthaben-Umbuchung) mit veraltetem Saldo → Degaso lehnt ab, lokal nichts ──
add_card('GS-CCCC-0003', 40, 3);
do_action('tix_order_completed', 3);
$fake->register_pay('GS-CCCC-0003', 30, 900); // lokal noch 40
$r = TIX_Giftcards::redeem('GS-CCCC-0003', 25, 'wallet', 'wallet:1');
ok(!$r['ok'], 'zu wenig bei Degaso → abgelehnt');
ok(strpos($r['message'], '10,00 €') !== false, 'Meldung nennt den echten Rest');
eq('Degaso unverändert 10', 10.0, $fake->bal('GS-CCCC-0003'));
eq('lokal nicht abgebucht', 40.0, card('GS-CCCC-0003')['balance']);
TIX_Degaso::refresh('GS-CCCC-0003');
eq('Live-Abruf spiegelt den Rest', 10.0, card('GS-CCCC-0003')['balance']);

// ── 7. Bar in Tixomat: standardmäßig gesperrt, mit Schalter über Degaso ──
$res = TIX_Giftcard_Pos::rest_redeem(new WP_REST_Request(['code' => 'GS-CCCC-0003', 'amount' => '5']));
ok(is_wp_error($res) && $res->code === 'tix_giftcard_degaso_only', 'Bar-Abbuchung → nur an der Degaso-Kasse');
$g = TIX_Giftcard_Pos::rest_get(new WP_REST_Request(['code' => 'GS-CCCC-0003']))->get_data();
ok($g['degaso_only'], 'Scan zeigt degaso_only');
save(['bar_redeem' => true]);
$res = TIX_Giftcard_Pos::rest_redeem(new WP_REST_Request(['code' => 'GS-CCCC-0003', 'amount' => '5', 'idempotency_key' => 'k1']))->get_data();
ok($res['ok'], 'mit Schalter: Bar bucht');
eq('Degaso 5', 5.0, $fake->bal('GS-CCCC-0003'));
eq('lokal 5', 5.0, card('GS-CCCC-0003')['balance']);
$rid = $res['redemption_id'];
eq('paymentId der Bar-Buchung = Einlöse-ID', 'tix-kitchenklub-de-r-' . $rid, end($fake->v[$LIVE]['GS-CCCC-0003']['history'])['paymentId']);
$res2 = TIX_Giftcard_Pos::rest_redeem(new WP_REST_Request(['code' => 'GS-CCCC-0003', 'amount' => '5', 'idempotency_key' => 'k1']))->get_data();
ok($res2['replay'], 'Doppel-Tippen: Wiederholung, nichts gebucht');
eq('Degaso weiter 5', 5.0, $fake->bal('GS-CCCC-0003'));
// Storno an der Bar → Rückbuchung bei Degaso
$v = TIX_Giftcard_Pos::rest_void(new WP_REST_Request(['code' => 'GS-CCCC-0003', 'redemption_id' => $rid]))->get_data();
ok($v['ok'], 'Bar-Storno');
eq('Degaso wieder 10', 10.0, $fake->bal('GS-CCCC-0003'));
TIX_Giftcard_Pos::rest_void(new WP_REST_Request(['code' => 'GS-CCCC-0003', 'redemption_id' => $rid]));
eq('Storno doppelt → nicht doppelt gutgeschrieben', 10.0, $fake->bal('GS-CCCC-0003'));
save(['bar_redeem' => false]);

// ── 8. Degaso fällt aus ──
add_card('GS-DDDD-0004', 30, 4);
do_action('tix_order_completed', 4);
$fake->down = true;
$r = TIX_Giftcards::redeem('GS-DDDD-0004', 10, 'wallet', 'wallet:2');
ok(!$r['ok'] && strpos($r['message'], 'nicht erreichbar') !== false, 'vor Ort ohne Degaso → abgelehnt, nicht auf Verdacht');
eq('lokal unverändert', 30.0, card('GS-DDDD-0004')['balance']);
$r = TIX_Giftcards::redeem('GS-DDDD-0004', 10, 'online', 'order#88');
ok($r['ok'], 'bezahlte Bestellung ohne Degaso → angenommen');
eq('lokal 20', 20.0, card('GS-DDDD-0004')['balance']);
eq('in der Warteschlange', 1, count(get_option('tix_degaso_queue')));
TIX_Degaso::run_queue();
eq('weiter in der Warteschlange, solange Degaso weg ist', 1, count(get_option('tix_degaso_queue')));
add_card('GS-EEEE-0005', 25, 5);
do_action('tix_order_completed', 5);
eq('Ausgabe bei Ausfall: ausstehend', 'pending', TIX_Degaso::state('GS-EEEE-0005')['state']);
$fake->down = false;
TIX_Degaso::cron();
eq('nachgebucht: Degaso 20', 20.0, $fake->bal('GS-DDDD-0004'));
eq('Warteschlange leer', 0, count(get_option('tix_degaso_queue')));
eq('ausstehender Gutschein nachgereicht', 25.0, $fake->bal('GS-EEEE-0005'));
TIX_Degaso::cron();
eq('zweiter Cron: nichts doppelt', 20.0, $fake->bal('GS-DDDD-0004'));
eq('Abgleich nach Nachbuchung: lokal bleibt 20', 20.0, card('GS-DDDD-0004')['balance']);

// ── 9. Bezahlte Bestellung, aber an der Bar schon verbraucht → Rest abgeräumt, Konflikt gemeldet ──
add_card('GS-FFFF-0006', 20, 6);
do_action('tix_order_completed', 6);
$fake->register_pay('GS-FFFF-0006', 15, 901);
$r = TIX_Giftcards::redeem('GS-FFFF-0006', 20, 'online', 'order#99');
ok($r['ok'], 'bezahlte Bestellung wird nie abgelehnt');
eq('Degaso-Rest abgeräumt', 0.0, $fake->bal('GS-FFFF-0006'));
$log = get_option('tix_degaso_log');
ok($log[0]['level'] === 'conflict' && strpos($log[0]['msg'], 'Fehlbetrag 15,00 €') !== false, 'Konflikt mit Fehlbetrag im Protokoll');

// ── 10. Bestellung storniert → Rest bei Degaso abgebucht; Rückbuchung nach Storno ──
add_card('GS-GGGG-0007', 60, 7);
do_action('tix_order_completed', 7);
TIX_Giftcards::deactivate_for_order(7);
eq('Storno: Degaso-Saldo 0', 0.0, $fake->bal('GS-GGGG-0007'));
eq('Storno: lokal 0', 0.0, card('GS-GGGG-0007')['balance']);
do_action('tix_giftcard_credited', 'GS-BBBB-0002', 7.5, 'storno55');
do_action('tix_giftcard_credited', 'GS-BBBB-0002', 7.5, 'storno55');
eq('Rückbuchung genau einmal', 7.5, $fake->bal('GS-BBBB-0002'));

// ── 11. Konflikt: Code gibt es bei Degaso schon mit fremdem Guthaben ──
$fake->v[$LIVE]['GS-HHHH-0008'] = ['balance' => 99, 'active' => true, 'history' => [], 'pids' => []];
add_card('GS-HHHH-0008', 10, 8);
do_action('tix_order_completed', 8);
eq('fremder Code → Konflikt', 'conflict', TIX_Degaso::state('GS-HHHH-0008')['state']);
eq('fremdes Guthaben nicht verändert', 99.0, $fake->bal('GS-HHHH-0008'));
ok(TIX_Degaso::payload()['status']['conflicts'] >= 1, 'Konflikt im Studio-Status');

// ── 12. Altbestand übertragen (Vorschau, dann echt, Wiederholung harmlos) ──
add_card('GS-IIII-0009', 30, 0, 12.5);
add_card('GS-JJJJ-0010', 30, 0, 0);
add_card('GS-KKKK-0011', 30, 0, 8, date('Y-m-d', strtotime('-1 day')));
$d = TIX_Degaso::rest_import(new WP_REST_Request(['dry_run' => true]))->get_data();
$codes = array_column($d['list'], 'code');
ok(in_array('GS-IIII-0009', $codes, true) && !in_array('GS-JJJJ-0010', $codes, true) && !in_array('GS-KKKK-0011', $codes, true), 'Vorschau: nur gültige mit Rest');
ok(!isset($fake->v[$LIVE]['GS-IIII-0009']), 'Vorschau bucht nichts');
TIX_Degaso::rest_import(new WP_REST_Request([]));
eq('übertragen mit Restsaldo', 12.5, $fake->bal('GS-IIII-0009'));
eq('Import-paymentId', 'tix-kitchenklub-de-i-GS-IIII-0009', $fake->v[$LIVE]['GS-IIII-0009']['history'][0]['paymentId']);
TIX_Degaso::rest_import(new WP_REST_Request([]));
eq('zweiter Import: nichts doppelt', 12.5, $fake->bal('GS-IIII-0009'));

// ── 13. Ablauf: Standard nichts, mit Einstellung Rest abbuchen ──
add_card('GS-LLLL-0012', 20, 12);
do_action('tix_order_completed', 12);
$cs = get_option('tix_coupons'); $cs['GS-LLLL-0012']['expires'] = date('Y-m-d', strtotime('-2 days')); update_option('tix_coupons', $cs);
eq('Standard: nichts', 0, TIX_Degaso::daily());
eq('Degaso behält Rest (kulant)', 20.0, $fake->bal('GS-LLLL-0012'));
save(['expiry_action' => 'clear']);
eq('mit Einstellung: ein Gutschein', 1, TIX_Degaso::daily());
eq('Degaso-Rest abgebucht', 0.0, $fake->bal('GS-LLLL-0012'));
eq('lokal 0 mit Kanal expired', 'expired', end(card('GS-LLLL-0012')['redemptions'])['channel']);
eq('nochmal: nichts', 0, TIX_Degaso::daily());
save(['expiry_action' => 'none']);

// ── 14. Verlauf bei Degaso nur 10 Einträge → Abgleich bucht den Unterschied ──
add_card('GS-MMMM-0013', 100, 13);
do_action('tix_order_completed', 13);
for ($i = 0; $i < 12; $i++) $fake->register_pay('GS-MMMM-0013', 1, 1000 + $i);
TIX_Degaso::pull();
eq('nach 12 Bar-Buchungen (Verlauf nur 10): Saldo stimmt', 88.0, card('GS-MMMM-0013')['balance']);
$sum = array_sum(array_column(card('GS-MMMM-0013')['redemptions'], 'amount'));
eq('Verlauf erklärt den ganzen Betrag', 12.0, $sum);

// ── 15. Testbetrieb mit eigenem Schlüssel ──
$p = save(['test_api_key' => $fake->keys[1], 'test_mode' => true])->get_data();
ok($p['settings']['test_mode'] && $p['test_key']['set'], 'Testbetrieb an');
add_card('GS-NNNN-0014', 15, 14);
do_action('tix_order_completed', 14);
eq('Testbetrieb bucht beim Test-Betrieb', 15.0, $fake->bal('GS-NNNN-0014', 1));
ok(!isset($fake->v[$LIVE]['GS-NNNN-0014']), 'nichts im echten Betrieb');
save(['test_mode' => false, 'clear_test_key' => true]);
eq('zurück auf echten Schlüssel', $LIVE, TIX_Degaso::key());

// ── 16. Rechte: Mehr-Veranstalter-Modus nur Admins ──
class TIX_App_Scope { public static $m = true; static function multi() { return self::$m; } }
$GLOBALS['t_admin'] = false;
ok(is_wp_error(TIX_Degaso::check_admin(new WP_REST_Request([]))), 'evendis: Veranstalter dürfen nicht');
$GLOBALS['t_admin'] = true;
ok(TIX_Degaso::check_admin(new WP_REST_Request([])) === true, 'evendis: Admin darf');

echo ($fails ? "$fails von $count Prüfungen fehlgeschlagen\n" : "Alle $count Prüfungen grün\n");
exit($fails ? 1 : 0);
