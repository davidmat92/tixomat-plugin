<?php
/**
 * Unit-Tests für TIX_Assistant (KI-Assistent, 1.38.544). Ohne WordPress, mit kleinen Stubs; API und Routen gefälscht.
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/test-assistant.php
 */
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__ . '/');

// ── Stubs ──
$GLOBALS['t_settings'] = [];
$GLOBALS['t_options'] = [];
$GLOBALS['t_trans'] = [];
$GLOBALS['t_user'] = 0;
$GLOBALS['t_admin'] = false;
$GLOBALS['t_roles'] = [];
$GLOBALS['t_mails'] = 0;

class WP_Error {
    public $code; public $message; public $data;
    function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->message = $m; $this->data = $d; }
    function get_error_code() { return $this->code; }
    function get_error_message() { return $this->message; }
    function get_error_data() { return $this->data; }
}
class WP_REST_Response {
    public $data; public $headers = [];
    function __construct($d = null) { $this->data = $d; }
    function get_data() { return $this->data; }
    function header($k, $v) { $this->headers[$k] = $v; }
    function is_error() { return false; }
}
class WP_REST_Request {
    public $method; public $route; public $params = []; public $json = null; public $headers = [];
    function __construct($m = 'GET', $r = '') { $this->method = $m; $this->route = $r; }
    function set_param($k, $v) { $this->params[$k] = $v; }
    function get_param($k) { return $this->params[$k] ?? null; }
    function get_params() { return $this->params; }
    function get_json_params() { return $this->json; }
    function get_header($k) { return $this->headers[$k] ?? null; }
    function get_route() { return $this->route; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function rest_ensure_response($d) { return $d instanceof WP_REST_Response ? $d : new WP_REST_Response($d); }
function tix_get_settings($k = null) { return $k === null ? $GLOBALS['t_settings'] : ($GLOBALS['t_settings'][$k] ?? null); }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['t_options']) ? $GLOBALS['t_options'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['t_options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['t_options'][$k]); return true; }
function get_transient($k) { return $GLOBALS['t_trans'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['t_trans'][$k] = $v; return true; }
function is_user_logged_in() { return $GLOBALS['t_user'] > 0; }
function current_user_can($c) { return $GLOBALS['t_admin']; }
function wp_get_current_user() { return (object) ['roles' => $GLOBALS['t_roles']]; }
function get_bloginfo($k) { return 'KitchenKlub'; }
function home_url($p = '') { return 'https://kitchenklub.de' . $p; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function wp_json_encode($d, $f = 0) { return json_encode($d, $f); }
function get_post_type($id) { return in_array($id, [25, 26], true) ? 'event' : ''; }
function get_post_status($id) { return 'publish'; }
function apply_filters($h, $v) { return $v; }
function add_action() {}
function current_time($f) { return date($f); }
function wp_date($f) { return date($f); }
function wp_mail() { $GLOBALS['t_mails']++; return true; }
function get_post_meta($id, $k, $single = true) { return $GLOBALS['t_meta'][$id][$k] ?? ''; }
function get_the_title($id) { return $id === 90 ? 'Lachwerk Köln' : ''; }
class TIX_App_Scope { public static $multi = false; static function multi() { return self::$multi; } }
class TIX_Customer_URL { const OPTION_DOMAINS = 'tix_org_domains_active'; static function to($u) { return $u; } }
$GLOBALS['t_meta'] = [];
function rest_do_request($r) { throw new Exception('rest_do_request darf im Test nicht laufen'); }

require __DIR__ . '/../includes/class-tix-assistant.php';
$GLOBALS['t_rp'] = [];

$fails = 0; $count = 0;
function ok($cond, $label) {
    global $fails, $count;
    $count++;
    if (!$cond) { $fails++; echo "FEHLER: $label\n"; }
}

// ── Fake-Routen ──
$EVENT = [
    'id' => 25, 'title' => 'Mallorca Night', 'date_start' => '2026-11-07', 'time_start' => '23:00', 'time_doors' => '22:30',
    'location' => 'KitchenKlub', 'address' => 'Musterstr. 1, Wuppertal', 'age_label' => 'ab 18 Jahren', 'url' => 'https://kitchenklub.de/events/mallorca-night/',
    'image_small' => 'https://kitchenklub.de/x-300.jpg', 'tickets_enabled' => true, 'price_from' => 12.0, 'status_label' => 'Verfügbar',
    'categories' => [['index' => 0, 'name' => 'Early Bird', 'price' => 12.0, 'sold_out' => false, 'max_per_order' => 10], ['index' => 1, 'name' => 'VIP', 'price' => 40.0, 'sold_out' => true, 'max_per_order' => 4]],
    'faq' => [['q' => 'Garderobe?', 'a' => 'Ja, 2 €.']], 'dresscode' => 'Weiß',
];
$GLOBALS['t_rest_calls'] = [];
TIX_Assistant::$rest = function (WP_REST_Request $r) use ($EVENT) {
    $GLOBALS['t_rest_calls'][] = $r->method . ' ' . $r->route;
    if (preg_match('#/public/events$#', $r->route)) $GLOBALS['t_rp'][] = $r->params;
    $route = preg_replace('#^/tixomat/v1#', '', $r->route);
    if ($route === '/support/config') return new WP_REST_Response(['ok' => true, 'enabled' => true]);
    if ($route === '/public/events') return new WP_REST_Response(['events' => [$EVENT, array_merge($EVENT, ['id' => 26, 'title' => 'Free Friday', 'free_entry' => true, 'tickets_enabled' => false, 'categories' => []])]]);
    if ($route === '/public/events/25') return new WP_REST_Response($EVENT);
    if ($route === '/public/events/26') return new WP_REST_Response(array_merge($EVENT, ['id' => 26, 'tickets_enabled' => false, 'categories' => []]));
    if ($route === '/customer/cart/quote') return new WP_REST_Response(['totals' => ['count' => 2, 'subtotal' => 24.0, 'fee' => 1.5, 'fee_label' => 'Servicegebühr', 'total' => 25.5, 'currency' => 'EUR', 'tax_inclusive' => true]]);
    if ($route === '/customer/tickets') return new WP_REST_Response(['tickets' => [
        ['event_id' => 25, 'event_title' => 'Mallorca Night', 'event_date' => '2026-11-07', 'code' => 'ABC123', 'checked_in' => false],
        ['event_id' => 25, 'event_title' => 'Mallorca Night', 'event_date' => '2026-11-07', 'code' => 'DEF456', 'checked_in' => true],
    ]]);
    return new WP_Error('rest_no_route', 'Nicht da', ['status' => 404]);
};

// Fake-API: Skript mit Antworten nacheinander
$GLOBALS['t_api_bodies'] = [];
function script_api(array $answers) {
    $GLOBALS['t_api_queue'] = $answers;
    TIX_Assistant::$http = function ($body) {
        $GLOBALS['t_api_bodies'][] = $body;
        $a = array_shift($GLOBALS['t_api_queue']);
        return $a ?? ['code' => 500, 'body' => ['error' => 'leer']];
    };
}
function text_answer($t, $usage = ['input_tokens' => 1000, 'output_tokens' => 200, 'cache_read_input_tokens' => 3000]) {
    return ['code' => 200, 'body' => ['content' => [['type' => 'text', 'text' => $t]], 'stop_reason' => 'end_turn', 'usage' => $usage]];
}
function tool_answer($name, $input, $id = 'tu_1') {
    return ['code' => 200, 'body' => ['content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]], 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 900, 'output_tokens' => 40]]];
}
function chat($json, $headers = []) {
    $r = new WP_REST_Request('POST', '/tixomat/v1/assistant/chat');
    $r->json = $json; $r->headers = $headers;
    return TIX_Assistant::rest_chat($r);
}

// ═══ 1. Schwärzen ═══
$s = TIX_Assistant::scrub('Mail max.muster@web.de, Tel +49 170 1234567 oder 0202 / 123456, IBAN DE89 3704 0044 0532 0130 00, Karte 4111 1111 1111 1111, Ticket GL-25-AB12CD, Code 482913, Bestellung KK7A9X2. Ich heiße Max Muster.');
ok(strpos($s, 'max.muster@web.de') === false, 'E-Mail entfernt');
ok(strpos($s, '1234567') === false && strpos($s, '0202') === false, 'Telefon entfernt');
ok(strpos($s, 'DE89') === false, 'IBAN entfernt');
ok(strpos($s, '4111') === false, 'Kartennummer entfernt');
ok(strpos($s, 'GL-25') === false && strpos($s, '482913') === false && strpos($s, 'KK7A9X2') === false, 'Codes entfernt');
ok(strpos($s, 'Max Muster') === false, 'Name entfernt');
$keep = 'Am 01.11.2026 oder 2026-11-07 um 23:00, ab 18 Jahren, 12,50 €, 2 Tickets, Mein Hund Bello';
ok(TIX_Assistant::scrub($keep) === $keep, 'Datum/Preis/Alter bleiben: ' . TIX_Assistant::scrub($keep));

// ═══ 2. Kosten ═══
ok(abs(TIX_Assistant::cost_eur(['input_tokens' => 100000]) - 0.0092) < 1e-9, 'Input 100k = 0,0092 € (0,10 $/Mio.)');
ok(abs(TIX_Assistant::cost_eur(['output_tokens' => 1000000]) - 0.46) < 1e-9, 'Output 1 Mio. = 0,46 €');
ok(abs(TIX_Assistant::cost_eur(['cache_read_input_tokens' => 100000]) - 0.00092) < 1e-9, 'Cache 100k = 0,00092 €');
ok(TIX_Assistant::cost_eur(['input_tokens' => 120000]) > 5 * 0.0110, 'über 100k fünffach');

// ═══ 3. Rate-Limit ═══
for ($i = 0; $i < 15; $i++) TIX_Assistant::rate_ok('devA', 'ipA');
ok(TIX_Assistant::rate_ok('devA', 'ipA') === false, '16. Nachricht in 10 min gesperrt');
ok(TIX_Assistant::rate_ok('devB', 'ipA') === true, 'anderes Gerät, gleiche IP (Club-WLAN) geht');
$GLOBALS['t_trans'] = [];

// ═══ 4. Config ═══
$cfg = function ($ch = 'app') { $r = new WP_REST_Request('GET', ''); $r->set_param('channel', $ch); return TIX_Assistant::rest_config($r)->get_data(); };
ok($cfg()['enabled'] === false && $cfg()['reason'] === 'off', 'Standard aus');
update_option(TIX_Assistant::OPT, ['enabled' => true]);
ok($cfg()['reason'] === 'no_key', 'ohne Schlüssel no_key');
$GLOBALS['t_settings']['anthropic_api_key'] = 'sk-test';
ok($cfg()['enabled'] === true && $cfg()['name'] === 'KitchenKlub Assistent', 'an mit Schlüssel');
ok(count($cfg()['suggestions']) === 3 && $cfg()['max_chars'] === 500 && $cfg()['support_handoff'] === true, 'Vorschläge, Länge, Support');
update_option(TIX_Assistant::OPT, ['enabled' => true, 'channels' => ['app' => true, 'web' => false]]);
ok($cfg('web')['reason'] === 'channel' && $cfg('app')['enabled'], 'Kanal Website aus');
update_option(TIX_Assistant::OPT, ['enabled' => true, 'daily_budget_eur' => 5]);
update_option('_tix_assistant_usage_' . date('Ymd'), ['cost_eur' => 5.01]);
ok($cfg()['reason'] === 'budget', 'Budget erreicht');
$e = chat(['message' => 'Hallo', 'device_id' => 'device-123456']);
ok(is_wp_error($e) && $e->code === 'tix_assistant_off' && $e->data['reason'] === 'budget', 'Chat bei Budget 403');
delete_option('_tix_assistant_usage_' . date('Ymd'));

// ═══ 5. Eingaben ═══
ok(chat(['message' => '', 'device_id' => 'device-123456'])->code === 'tix_invalid_field', 'leere Nachricht');
ok(chat(['message' => str_repeat('a', 501), 'device_id' => 'device-123456'])->code === 'tix_invalid_field', 'zu lang');
ok(chat(['message' => 'Hi', 'device_id' => 'x'])->code === 'tix_invalid_field', 'device_id fehlt');
ok(chat(['message' => 'Hi', 'device_id' => 'device-123456', 'channel' => 'web'], ['origin' => 'https://boese.example'])->code === 'tix_assistant_off', 'fremde Herkunft');

// ═══ 6. Gespräch mit Suche ═══
script_api([tool_answer('search_events', ['date_from' => '2026-11-06', 'date_to' => '2026-11-08']), text_answer('Am **Samstag** ist Mallorca Night. Ballermann-Stimmung!')]);
$r = chat(['message' => 'Was geht am Wochenende? Meine Mail: a@b.de', 'device_id' => 'device-123456', 'channel' => 'web'], ['origin' => 'https://www.kitchenklub.de']);
ok(!is_wp_error($r), 'Antwort kommt' . (is_wp_error($r) ? ': ' . $r->message : ''));
$d = $r->get_data();
ok($d['reply'] === 'Am Samstag ist Mallorca Night. Mallorca-Stimmung!', 'Markdown raus, Wort ersetzt: ' . $d['reply']);
ok(count($d['cards']) === 2 && $d['cards'][0]['badge'] === 'Vorverkauf' && $d['cards'][1]['badge'] === 'Freier Eintritt', 'Karten mit Etikett');
ok($d['cards'][0]['image_small'] !== '' && $d['cards'][0]['price_from'] === 12.0, 'Karte mit Bild/Preis');
ok(strlen($d['conversation_id']) === 24 && $d['remaining'] === 19 && $d['ai'] === true, 'Gesprächs-ID, Rest');
$first = $GLOBALS['t_api_bodies'][0];
ok($first['model'] === 'claude-haiku-5-5' && $first['max_tokens'] === 400, 'Modell + max_tokens');
ok(strpos(json_encode($first['messages']), 'a@b.de') === false, 'E-Mail nicht an die API');
ok(isset($first['system'][0]['cache_control']) && isset(end($first['tools'])['cache_control']), 'Prompt-Cache gesetzt');
ok(strpos($first['system'][0]['text'], 'Ballermann') !== false && strpos($first['system'][0]['text'], 'nie') !== false, 'Regel im System-Prompt');
ok(in_array('GET /tixomat/v1/public/events', $GLOBALS['t_rest_calls'], true), 'Suche über /public/events');
$second = $GLOBALS['t_api_bodies'][1];
$tr = end($second['messages'])['content'][0];
ok($tr['type'] === 'tool_result' && strpos($tr['content'], 'Mallorca Night') !== false && strpos($tr['content'], 'image') === false, 'Werkzeug-Ergebnis gekürzt');
$u = TIX_Assistant::usage();
ok($u['messages'] === 1 && $u['conversations'] === 1 && $u['cost_eur'] > 0, 'Verbrauch gezählt');
$log = get_option('_tix_assistant_log_' . date('Ymd'));
ok(count($log) === 1 && strpos($log[0]['q'], 'a@b.de') === false && $log[0]['tools'] === ['search_events'], 'Protokoll anonym');

// Folge-Nachricht im selben Gespräch: Verlauf geht mit
script_api([text_answer('Einlass ist ab 22:30.')]);
$r2 = chat(['message' => 'Und Einlass?', 'device_id' => 'device-123456', 'conversation_id' => $d['conversation_id']]);
ok($r2->get_data()['conversation_id'] === $d['conversation_id'] && $r2->get_data()['remaining'] === 18, 'gleiches Gespräch');
ok(count($GLOBALS['t_api_bodies'][2]['messages']) === 3, 'Verlauf mit 3 Zügen');
// fremdes Gerät bekommt das Gespräch nicht
script_api([text_answer('Hallo')]);
$r3 = chat(['message' => 'Hi', 'device_id' => 'device-anderes', 'conversation_id' => $d['conversation_id']]);
ok($r3->get_data()['conversation_id'] !== $d['conversation_id'], 'Gespräch an Gerät gebunden');

// ═══ 7. Kasse ═══
$cards = []; $acts = [];
$o = TIX_Assistant::exec_tool('start_checkout', ['event_id' => 25, 'items' => [['index' => 1, 'qty' => 2]]], $cards, $acts);
ok(isset($o['fehler']) && !$acts, 'ausverkaufte Kategorie abgelehnt');
$o = TIX_Assistant::exec_tool('start_checkout', ['event_id' => 25, 'items' => [['index' => 0, 'qty' => 11]]], $cards, $acts);
ok(isset($o['fehler']), 'Menge über max abgelehnt');
$o = TIX_Assistant::exec_tool('start_checkout', ['event_id' => 25, 'items' => [['index' => 7, 'qty' => 1]]], $cards, $acts);
ok(isset($o['fehler']), 'unbekannte Kategorie');
$o = TIX_Assistant::exec_tool('start_checkout', ['event_id' => 26, 'items' => [['index' => 0, 'qty' => 1]]], $cards, $acts);
ok(isset($o['fehler']), 'ohne Online-Tickets');
$o = TIX_Assistant::exec_tool('start_checkout', ['event_id' => 25, 'items' => [['index' => 0, 'qty' => 2]]], $cards, $acts);
ok(!empty($o['ok']) && $acts[0]['action'] === 'checkout:25?items=0x2' && isset($cards[25]), 'Kassen-Aktion mit Vorauswahl');
$q = TIX_Assistant::exec_tool('quote_tickets', ['event_id' => 25, 'items' => [['index' => 0, 'qty' => 2]]], $cards, $acts);
ok($q['gesamt'] === 25.5 && $q['gebuehr'] === 1.5 && $q['inkl_mwst'] === true, 'Preis berechnet');

// ═══ 8. Tickets + Support ═══
$cards = []; $acts = [];
$o = TIX_Assistant::exec_tool('my_tickets', [], $cards, $acts);
ok($o['angemeldet'] === false && $acts[0]['action'] === 'login:code', 'ohne Login → Code-Login');
$GLOBALS['t_user'] = 7; $acts = [];
$o = TIX_Assistant::exec_tool('my_tickets', [], $cards, $acts);
ok($o['tickets'][0]['anzahl'] === 2 && $o['tickets'][0]['eingecheckt'] === 1 && $acts[0]['action'] === 'tab:tickets', 'Tickets gruppiert');
ok(strpos(json_encode($o), 'ABC123') === false, 'keine Ticket-Codes ans Modell');
$GLOBALS['t_user'] = 0; $acts = [];
$o = TIX_Assistant::exec_tool('handoff_support', ['subject' => 'Erstattung', 'summary' => 'Event abgesagt, Mail x@y.de'], $cards, $acts);
ok(strpos($acts[0]['action'], 'support:new?subject=Erstattung&message=') === 0 && strpos(urldecode($acts[0]['action']), 'x@y.de') === false, 'Support-Übergabe vorbelegt, geschwärzt');
$e = TIX_Assistant::exec_tool('get_event', ['id' => 25], $cards, $acts);
ok($e['einlass'] === '22:30' && $e['dresscode'] === 'Weiß' && $e['faq'][0]['f'] === 'Garderobe?' && $e['kategorien'][1]['ausverkauft'] === true, 'Event-Details');

// ═══ 9. Gesprächslänge + API-Fehler ═══
$GLOBALS['t_trans'] = [];
script_api(array_fill(0, 25, text_answer('ok')));
$cid = null;
for ($i = 0; $i < 20; $i++) { $x = chat(['message' => 'Frage ' . $i, 'device_id' => 'device-lang', 'conversation_id' => $cid]); $cid = $x->get_data()['conversation_id']; $GLOBALS['t_trans'] = array_filter($GLOBALS['t_trans'], function ($k) { return strpos($k, 'tix_as_rl_') !== 0; }, ARRAY_FILTER_USE_KEY); }
$last = end($GLOBALS['t_api_bodies']);
ok(count($last['messages']) <= 11, 'Verlauf gekürzt (' . count($last['messages']) . ')');
ok($last['messages'][0]['role'] === 'user', 'Verlauf beginnt mit Gast');
$x = chat(['message' => 'noch eine', 'device_id' => 'device-lang', 'conversation_id' => $cid]);
ok(is_wp_error($x) && $x->code === 'tix_assistant_rate', 'nach 20 Nachrichten Schluss');
script_api([['code' => 529, 'body' => ['error' => ['type' => 'overloaded']]]]);
$x = chat(['message' => 'Hallo', 'device_id' => 'device-fehler']);
ok(is_wp_error($x) && $x->code === 'tix_assistant_unavailable' && $x->data['status'] === 502, 'API-Fehler → 502');
// Werkzeug-Schleife endet spätestens nach 4 Runden
$GLOBALS['t_api_bodies'] = [];
script_api([tool_answer('search_events', []), tool_answer('search_events', []), tool_answer('search_events', []), tool_answer('search_events', []), text_answer('Fertig')]);
$x = chat(['message' => 'Suche', 'device_id' => 'device-schleife']);
ok($x->get_data()['reply'] === 'Fertig' && count($GLOBALS['t_api_bodies']) === 5 && !isset($GLOBALS['t_api_bodies'][4]['tools']), 'höchstens 4 Werkzeug-Runden');

// ═══ 10. Einstellungen ═══
$save = function ($b) { $r = new WP_REST_Request('POST', ''); $r->json = $b; return TIX_Assistant::rest_settings_save($r); };
ok(is_wp_error($save(['daily_budget_eur' => 100])), 'Budget > 50 abgelehnt');
ok(is_wp_error($save(['persona_preset' => 'frech'])), 'unbekannte Tonalität');
$p = $save(['enabled' => false, 'channels' => ['web' => false], 'suggestions' => ['a', 'b', 'c', 'd', 'e'], 'daily_budget_eur' => '7,5', 'persona_preset' => 'festlich', 'persona_text' => 'Kurz!'])->get_data();
ok($p['enabled'] === false && $p['channels'] === ['app' => true, 'web' => false] && count($p['suggestions']) === 4 && $p['daily_budget_eur'] === 7.5, 'Einstellungen gespeichert');
ok($p['key_set'] === true && $p['model'] === 'claude-haiku-5-5' && isset($p['today']['cost_eur']), 'Payload vollständig');
ok(strpos(TIX_Assistant::system_prompt(), 'voller Vorfreude') !== false && strpos(TIX_Assistant::system_prompt(), 'Kurz!') !== false, 'Tonalität im Prompt');
ok(strpos(TIX_Assistant::system_prompt(25), 'ID 25') !== false, 'Event-Kontext im Prompt');
$GLOBALS['t_user'] = 3; $GLOBALS['t_roles'] = ['tix_organizer'];
ok(TIX_Assistant::perm() === true, 'Inhaber darf (Einzel-Seite)');
$GLOBALS['t_roles'] = ['subscriber'];
ok(is_wp_error(TIX_Assistant::perm()), 'Gast darf nicht');
$GLOBALS['t_user'] = 0;
ok(is_wp_error(TIX_Assistant::perm()), 'ohne Login nicht');

// ═══ 11. Aufräumen ═══
global $wpdb;
$old = date('Ymd', time() - 20 * 86400);
update_option('_tix_assistant_log_' . $old, [['q' => 'alt']]);
$wpdb = new class { public $options = 'wp_options'; function get_col($q) { return array_keys(array_filter($GLOBALS['t_options'], function ($k) { return strpos($k, '_tix_assistant_') === 0; }, ARRAY_FILTER_USE_KEY)); } };
TIX_Assistant::cleanup();
ok(get_option('_tix_assistant_log_' . $old, null) === null && get_option('_tix_assistant_log_' . date('Ymd'), null) !== null, 'Protokoll > 14 Tage gelöscht');

// ═══ 12. Eigene Domain eines Veranstalters (evendis) ═══
TIX_Assistant::reset();
TIX_App_Scope::$multi = true;
update_option(TIX_Assistant::OPT, ['enabled' => true]);
update_option('tix_org_domains_active', ['tickets.lachwerk.de' => 90]);
$GLOBALS['t_meta'][25] = ['_tix_organizer_id' => 90];
$GLOBALS['t_meta'][26] = ['_tix_organizer_id' => 91];
ok(TIX_Assistant::org_for_host('www.tickets.lachwerk.de') === 90 && TIX_Assistant::org_for_host('evendis.de') === 0, 'Domain → Veranstalter');
$r = new WP_REST_Request('GET', ''); $r->set_param('channel', 'web'); $r->set_param('site_host', 'tickets.lachwerk.de');
ok(TIX_Assistant::rest_config($r)->get_data()['name'] === 'Lachwerk Köln Assistent', 'Config mit Veranstalter-Name');
$GLOBALS['t_trans'] = []; $GLOBALS['t_api_bodies'] = []; $GLOBALS['t_rest_calls'] = [];
$GLOBALS['t_rp'] = [];
script_api([tool_answer('search_events', ['organizer' => 91]), tool_answer('get_event', ['id' => 26], 'tu_2'), text_answer('Hier die Events.')]);
$x = chat(['message' => 'Was läuft?', 'device_id' => 'device-domain', 'channel' => 'web', 'site_host' => 'tickets.lachwerk.de'], ['origin' => 'https://tickets.lachwerk.de']);
ok(!is_wp_error($x), 'Chat auf eigener Domain' . (is_wp_error($x) ? ': ' . $x->message : ''));
$dd = $x->get_data();
ok(count($dd['cards']) === 1 && $dd['cards'][0]['id'] === 25, 'nur Events des Veranstalters als Karten');
ok($GLOBALS['t_rp'][0]['organizer'] === 90, 'Suche mit erzwungenem Veranstalter');
$tr2 = end($GLOBALS['t_api_bodies'][2]['messages'])['content'][0]['content'];
ok(strpos($tr2, 'nicht gefunden') !== false, 'fremdes Event gesperrt');
ok(strpos($GLOBALS['t_api_bodies'][0]['system'][0]['text'], 'nur Events von Lachwerk Köln') !== false, 'Prompt nennt Veranstalter');
$cards = []; $acts = [];
TIX_Assistant::set_org(90);
ok(isset(TIX_Assistant::exec_tool('start_checkout', ['event_id' => 26, 'items' => [['index' => 0, 'qty' => 1]]], $cards, $acts)['fehler']), 'Kasse fremdes Event gesperrt');
$GLOBALS['t_user'] = 7;
$o = TIX_Assistant::exec_tool('my_tickets', [], $cards, $acts);
ok(count($o['tickets']) === 1, 'Tickets nur dieses Veranstalters');
$GLOBALS['t_user'] = 0;
// fremde Domain, gleiche Gesprächs-ID → neues Gespräch
script_api([text_answer('Hallo')]);
$y = chat(['message' => 'Hi', 'device_id' => 'device-domain', 'channel' => 'web', 'conversation_id' => $dd['conversation_id']], ['origin' => 'https://kitchenklub.de']);
ok($y->get_data()['conversation_id'] !== $dd['conversation_id'], 'Gespräch an Domain gebunden');
TIX_App_Scope::$multi = false;

echo "$count Prüfungen, $fails Fehler\n";
exit($fails ? 1 : 0);
