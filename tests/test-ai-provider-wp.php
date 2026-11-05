<?php
/** KI-Anbieter und Schlüssel aus dem Studio (TIX_AI_Provider, 1.38.646). Anbieter nachgebildet über TIX_AI_Provider::$http,
 *  es geht nichts ins Netz. wp eval-file …/tests/test-ai-provider-wp.php (Einzel-Seite und Plattform) */
if (!defined('ABSPATH') || !class_exists('TIX_AI_Provider')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function ap_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function ap_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = []; $posts = [];
$before = ['p' => get_option(TIX_AI_Provider::OPT, null), 'u' => get_option(TIX_AI_Provider::OPT_USAGE, null)];
delete_option(TIX_AI_Provider::OPT); delete_option(TIX_AI_Provider::OPT_USAGE);
$admin = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
$owner = wp_insert_user(['user_login' => "ap_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "ap_owner_$sfx@example.test", 'role' => $multi ? 'tix_organizer' : 'administrator']); $users[] = $owner;
$other = wp_insert_user(['user_login' => "ap_other_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "ap_other_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $other;
$org = 0; $org2 = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'AP Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'AP Andere', 'meta_input' => ['_tix_org_user_id' => $other]]); $posts[] = $org2;
}
$CK = 'sk-ant-api03-' . str_repeat('a', 40) . 'K3f9'; $OK = 'sk-proj-' . str_repeat('b', 40) . 'X7z1'; $OK2 = 'sk-proj-' . str_repeat('c', 40) . 'Q2w2';
// ── Nachbildung der Anbieter ──
$GLOBALS['ap'] = ['calls' => []];
TIX_AI_Provider::$http = function ($url, $args) use ($CK, $OK, $OK2) {
    $GLOBALS['ap']['calls'][] = [$url, $args];
    $J = function ($c, $b) { return ['headers' => [], 'body' => wp_json_encode($b), 'response' => ['code' => $c, 'message' => ''], 'cookies' => [], 'filename' => null]; };
    $auth = (string) ($args['headers']['Authorization'] ?? ''); $xk = (string) ($args['headers']['x-api-key'] ?? '');
    if (strpos($url, '/v1/models') !== false) return $J(($xk === $CK || $auth === 'Bearer ' . $OK || $auth === 'Bearer ' . $OK2) ? 200 : 401, ['data' => []]);
    $b = json_decode((string) $args['body'], true);
    if (strpos($url, 'api.anthropic.com/v1/messages') !== false) return $J(200, ['model' => $b['model'], 'content' => [['type' => 'text', 'text' => 'Claude sagt hallo']], 'usage' => ['input_tokens' => 1000, 'output_tokens' => 500]]);
    if (strpos($url, 'api.openai.com/v1/chat/completions') !== false) {
        if (!empty($b['tools'])) return $J(200, ['id' => 'x', 'model' => $b['model'], 'choices' => [['finish_reason' => 'tool_calls', 'message' => ['content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => $b['tools'][0]['function']['name'], 'arguments' => '{"subjects":["A"]}']]]]]], 'usage' => ['prompt_tokens' => 2000, 'completion_tokens' => 100]]);
        return $J(200, ['id' => 'y', 'model' => $b['model'], 'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'OpenAI sagt hallo']]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50]]);
    }
    return $J(404, ['error' => ['message' => 'nicht nachgebildet']]);
};
$last = function () { $c = end($GLOBALS['ap']['calls']); return [$c[0] ?? '', json_decode((string) ($c[1]['body'] ?? ''), true)]; };

// ═══ Einstellungen ═══
wp_set_current_user($owner);
[$st, $g] = ap_rest('GET', '/ai/settings');
ap_eq('Anfangs: nicht verbunden, Auswahl Claude/OpenAI mit Modellen', [200, false, ['claude', 'openai'], 'claude-sonnet-5-5'], [$st, $g['connected'] ?? null, array_keys((array) ($g['providers'] ?? [])), $g['providers']['claude']['default'] ?? '']);
[$st, $d, $code] = ap_rest('POST', '/ai/settings', ['provider' => 'claude', 'api_key' => $OK]);
ap_eq('OpenAI-Schlüssel bei Claude → 400 am Feld', [400, 'api_key'], [$st, $d['data']['field'] ?? '']);
[$st, $d, $code] = ap_rest('POST', '/ai/settings', ['provider' => 'claude', 'api_key' => 'sk-ant-api03-' . str_repeat('z', 40)]);
ap_eq('Abgelehnter Schlüssel → tix_ai_key am Feld', ['tix_ai_key', 'api_key'], [$code, $d['data']['field'] ?? '']);
[$st, $g] = ap_rest('POST', '/ai/settings', ['provider' => 'claude', 'api_key' => $CK, 'model' => 'claude-haiku-5-5', 'daily_limit_eur' => 5]);
ap_eq('Claude verbunden: gekürzt angezeigt, Modell, Limit, wirksam', [200, true, 'sk-ant-…K3f9', 'claude-haiku-5-5', 5.0, $multi ? 'organizer' : 'site'], [$st, $g['connected'] ?? null, $g['key_hint'] ?? '', $g['model'] ?? '', $g['daily_limit_eur'] ?? null, $g['effective']['source'] ?? '']);
$raw = $multi ? get_post_meta($org, TIX_AI_Provider::META, true) : get_option(TIX_AI_Provider::OPT);
ap_eq('Schlüssel verschlüsselt gespeichert, nie in der Antwort', [false, false], [strpos(wp_json_encode($raw), $CK) !== false, strpos(wp_json_encode($g), $CK) !== false]);
if (!$multi) {
    ap_eq('Einzel-Seite: allow_platform ist null', [true, null], [array_key_exists('allow_platform', (array) $g), $g['allow_platform'] ?? null]);
    [$st] = ap_rest('POST', '/ai/settings', ['model' => 'claude-haiku-5-5', 'allow_platform' => true]);
    ap_eq('Einzel-Seite: zurückgeschicktes allow_platform wird ignoriert (Speichern klappt)', 200, $st);
}
[$st, $d] = ap_rest('POST', '/ai/settings', ['model' => 'gpt-4o']);
ap_eq('Fremdes Modell → 400', [400, 'model'], [$st, $d['data']['field'] ?? '']);

// ═══ Alle KI-Aufrufe über die Schicht ═══
$r = TIX_AI_Writer::anthropic_post(['model' => 'claude-opus-5-5', 'max_tokens' => 50, 'system' => 'S', 'messages' => [['role' => 'user', 'content' => 'Hi']]], 'alter-schluessel', 10);
list($u, $b) = $last();
ap_eq('anthropic_post nutzt den Studio-Schlüssel und das Studio-Modell', [true, 'claude-haiku-5-5', $CK, 'Claude sagt hallo'], [strpos($u, 'api.anthropic.com') !== false, $b['model'] ?? '', (string) (end($GLOBALS['ap']['calls'])[1]['headers']['x-api-key'] ?? ''), json_decode(wp_remote_retrieve_body($r), true)['content'][0]['text'] ?? '']);
$cid = 0; $cnt = count($GLOBALS['ap']['calls']);
[$st, $g] = ap_rest('POST', '/ai/settings', ['provider' => 'openai']);
ap_eq('Anbieter wechseln ohne neuen Schlüssel → 400', [400, 'api_key'], [$st, $g['data']['field'] ?? '']);
[$st, $g] = ap_rest('POST', '/ai/settings', ['provider' => 'openai', 'api_key' => $OK]);
ap_eq('OpenAI verbunden, Standardmodell gpt-4o', [200, 'openai', 'gpt-4o', 'sk-proj-…X7z1'], [$st, $g['provider'] ?? '', $g['model'] ?? '', $g['key_hint'] ?? '']);
$img = base64_encode('png');
$payload = ['model' => 'claude-sonnet-5-5', 'max_tokens' => 300, 'system' => [['type' => 'text', 'text' => 'Du bist hilfreich.', 'cache_control' => ['type' => 'ephemeral']]],
    'tools' => [['name' => 'newsletter', 'description' => 'D', 'input_schema' => ['type' => 'object', 'properties' => ['subjects' => ['type' => 'array']]]]], 'tool_choice' => ['type' => 'tool', 'name' => 'newsletter'],
    'messages' => [['role' => 'user', 'content' => [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $img]], ['type' => 'text', 'text' => 'Was ist das?']]],
        ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'tu1', 'name' => 'suche', 'input' => ['q' => 'x']]]],
        ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'tu1', 'content' => 'Ergebnis']]]]];
$r = TIX_AI_Writer::anthropic_post($payload, 'egal', 10);
list($u, $o) = $last();
$msgs = $o['messages'] ?? [];
ap_eq('OpenAI: System, Bild als data-URL, Werkzeug-Aufruf und -Ergebnis übersetzt',
    [true, 'gpt-4o', 'system', 'Du bist hilfreich.', 'image_url', 'data:image/png;base64,' . $img, 'tu1', 'tool', 'Ergebnis', 'newsletter', 'function', 300],
    [strpos($u, 'api.openai.com') !== false, $o['model'] ?? '', $msgs[0]['role'] ?? '', $msgs[0]['content'] ?? '', $msgs[1]['content'][0]['type'] ?? '', $msgs[1]['content'][0]['image_url']['url'] ?? '',
     $msgs[2]['tool_calls'][0]['id'] ?? '', $msgs[3]['role'] ?? '', $msgs[3]['content'] ?? '', $o['tool_choice']['function']['name'] ?? '', $o['tools'][0]['type'] ?? '', $o['max_completion_tokens'] ?? 0]);
$rb = json_decode(wp_remote_retrieve_body($r), true);
ap_eq('OpenAI-Antwort im Anthropic-Format: tool_use mit Eingabe, stop_reason, usage', ['tool_use', ['subjects' => ['A']], 'tool_use', 2000], [$rb['content'][0]['type'] ?? '', $rb['content'][0]['input'] ?? null, $rb['stop_reason'] ?? '', $rb['usage']['input_tokens'] ?? 0]);
$r = TIX_AI_Writer::anthropic_post(['model' => 'x', 'max_tokens' => 10, 'messages' => [['role' => 'user', 'content' => 'Hi']]], '', 10);
ap_eq('OpenAI-Text als text-Block', 'OpenAI sagt hallo', json_decode(wp_remote_retrieve_body($r), true)['content'][0]['text'] ?? '');
ap_eq('Andere KI-Klassen sehen „eingerichtet“ (provider_ready, Newsletter-KI)', [true, true], [TIX_AI_Writer::provider_ready(), (bool) (ap_rest('GET', '/marketing/newsletter/ai')[1]['available'] ?? false)]);

// ═══ Verbrauch und Tageslimit ═══
[$st, $U] = ap_rest('GET', '/ai/usage', ['days' => 7]);
ap_eq('Verbrauch: 3 Aufrufe heute, Kosten > 0, 7 Tage', [200, 3, true, 7], [$st, $U['today']['requests'] ?? 0, ($U['today']['cost_eur'] ?? 0) > 0, count((array) ($U['days'] ?? []))]);
ap_rest('POST', '/ai/settings', ['daily_limit_eur' => 0.5]);
TIX_AI_Provider::record(TIX_AI_Provider::effective()['scope'], 'claude-opus-5-5', ['input_tokens' => 40000, 'output_tokens' => 0]); // 0,55 €
$cnt2 = count($GLOBALS['ap']['calls']);
$r = TIX_AI_Writer::anthropic_post(['model' => 'x', 'max_tokens' => 10, 'messages' => [['role' => 'user', 'content' => 'Hi']]], '', 10);
ap_eq('Tageslimit erreicht → ai_budget ohne Aufruf', ['ai_budget', 0], [is_wp_error($r) ? $r->get_error_code() : '', count($GLOBALS['ap']['calls']) - $cnt2]);
ap_rest('POST', '/ai/settings', ['daily_limit_eur' => 0]);

// ═══ Bereiche (evendis) ═══
if ($multi) {
    wp_set_current_user($admin);
    [$st, $g] = ap_rest('POST', '/ai/settings', ['provider' => 'openai', 'api_key' => $OK2, 'allow_platform' => true]);
    ap_eq('Admin: Plattform-Schlüssel gesetzt', [200, 'site', true], [$st, $g['scope'] ?? '', $g['allow_platform'] ?? null]);
    wp_set_current_user($other);
    [$st, $g] = ap_rest('GET', '/ai/settings');
    ap_eq('Anderer Veranstalter ohne eigenen Schlüssel: nutzt Plattform, sieht deren Schlüssel nicht', [200, false, 'site', ''], [$st, $g['connected'] ?? null, $g['effective']['source'] ?? '', $g['key_hint'] ?? '']);
    [$st, $d] = ap_rest('POST', '/ai/settings', ['allow_platform' => false]);
    ap_eq('Veranstalter darf die Plattform-Freigabe nicht setzen', 400, $st);
    TIX_AI_Provider::set_org($org);
    $eff = TIX_AI_Provider::effective();
    ap_eq('Eigener Schlüssel schlägt den Plattform-Schlüssel', ['organizer', 'openai', 'sk-proj-…X7z1'], [$eff['source'] ?? '', $eff['provider'] ?? '', TIX_AI_Provider::hint($eff['key'] ?? '')]);
    TIX_AI_Provider::set_org(null);
    wp_set_current_user($admin);
    ap_rest('POST', '/ai/settings', ['allow_platform' => false]);
    wp_set_current_user($other);
    ap_eq('Plattform-Schlüssel nicht freigegeben → für Veranstalter ohne eigenen nichts', null, TIX_AI_Provider::effective());
    [$st, $U2] = ap_rest('GET', '/ai/usage');
    ap_eq('Verbrauch je Veranstalter getrennt', 0, $U2['today']['requests'] ?? -1);
    wp_set_current_user($admin);
    ap_rest('POST', '/ai/settings', ['disconnect' => true]);
    wp_set_current_user($owner);
}
[$st, $g] = ap_rest('POST', '/ai/settings', ['disconnect' => true]);
ap_eq('Trennen: nicht verbunden, danach gilt der alte Weg (Plugin-Einstellungen) bzw. nichts', [200, false, false], [$st, $g['connected'] ?? null, TIX_AI_Provider::configured()]);
wp_set_current_user(0);
[$st] = ap_rest('GET', '/ai/settings');
ap_eq('Ohne Anmeldung → 401', 401, $st);

// Aufräumen
TIX_AI_Provider::$http = null;
wp_set_current_user($admin);
foreach ($before as $k => $v) { $n = $k === 'p' ? TIX_AI_Provider::OPT : TIX_AI_Provider::OPT_USAGE; if ($v === null) delete_option($n); else update_option($n, $v, false); }
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
