<?php
/**
 * TIX_AI_Provider – KI-Anbieter und Schlüssel aus dem Studio (1.38.646, User 2026-11-05).
 *
 * Eine Schicht für alle KI-Klassen (AI_Writer, AI_REST, Assistent, Support-KI, Content Guard, Social-Texte, Newsletter-KI):
 * Die Klassen bauen ihre Anfragen weiter im Format der Anthropic Messages API und schicken sie über
 * TIX_AI_Writer::anthropic_post bzw. send(). Ist im Studio ein Anbieter eingetragen, geht die Anfrage an diesen –
 * bei OpenAI übersetzt (Chat Completions: System, Bilder, Werkzeuge, Werkzeug-Ergebnisse) und die Antwort zurück ins
 * Anthropic-Format. Ohne Eintrag im Studio bleibt alles wie bisher (Schlüssel aus den Plugin-Einstellungen).
 *
 * Einstellung: Seite (Option tix_ai_provider), evendis zusätzlich je Veranstalter (Post-Meta _tix_ai_provider).
 * Vorrang: Schlüssel des Veranstalters → Schlüssel der Seite (auf evendis nur, wenn allow_platform) → Plugin-Einstellungen.
 * Schlüssel verschlüsselt (TIX_Ad_Accounts::enc), nie im Klartext ausgegeben (key_hint „sk-…3f9a“).
 * Verbrauch je Tag und Bereich in der Option tix_ai_usage (62 Tage), Tageslimit in Euro je Bereich.
 */
if (!defined('ABSPATH')) exit;

class TIX_AI_Provider {

    const NS = 'tixomat/v1';
    const OPT = 'tix_ai_provider';
    const META = '_tix_ai_provider';
    const OPT_USAGE = 'tix_ai_usage';
    const KEEP_DAYS = 62;
    const MARKER = '__tix_ai_studio__';
    const PROVIDERS = ['claude' => 'Claude (Anthropic)', 'openai' => 'OpenAI'];
    const MODELS = [
        'claude' => ['claude-sonnet-5-5' => 'Claude Sonnet 5.5 (Standard)', 'claude-opus-5-5' => 'Claude Opus 5.5 (Premium)', 'claude-haiku-5-5' => 'Claude Haiku 5.5 (günstig)'],
        'openai' => ['gpt-4o' => 'GPT-4o (Standard)', 'gpt-4o-mini' => 'GPT-4o mini (günstig)', 'o3-mini' => 'o3-mini (Reasoning)'],
    ];
    const DEFAULT_MODEL = ['claude' => 'claude-sonnet-5-5', 'openai' => 'gpt-4o'];
    /** Schätzpreise USD je 1 Mio. Token [Eingabe, Ausgabe] (Filter tix_ai_prices) */
    const PRICES = ['claude-sonnet-5-5' => [3.0, 15.0], 'claude-opus-5-5' => [15.0, 75.0], 'claude-haiku-5-5' => [1.0, 5.0],
        'gpt-4o' => [2.5, 10.0], 'gpt-4o-mini' => [0.15, 0.6], 'o3-mini' => [1.1, 4.4]];
    const USD_EUR = 0.92;
    const PURPOSES = ['TIX_Newsletter_AI' => 'newsletter', 'TIX_Assistant' => 'assistant', 'TIX_Support_AI' => 'support', 'TIX_Content_Guard' => 'guard',
        'TIX_Meta_Ads' => 'social', 'TIX_Bulk_Editor' => 'import', 'TIX_AI_REST' => 'texte', 'TIX_AI_Writer' => 'texte'];

    /** Veranstalter für die laufende Anfrage (null = aus dem angemeldeten Konto) */
    private static $org = null;
    /** Testhaken: callable($url, $args) → Antwort wie wp_remote_post */
    public static $http = null;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }

    public static function models($provider) { $m = (array) apply_filters('tix_ai_models', self::MODELS); return (array) ($m[$provider] ?? []); }

    // ═══════════════ Bereich und Einstellung ═══════════════

    public static function set_org($org) { self::$org = $org === null ? null : intval($org); }

    public static function with_org($org, callable $fn) {
        $prev = self::$org;
        self::$org = intval($org);
        try { return $fn(); } finally { self::$org = $prev; }
    }

    public static function context_org() {
        if (self::$org !== null) return self::$org;
        if (!self::multi() || !is_user_logged_in() || !class_exists('TIX_App_Scope') || TIX_App_Scope::is_admin()) return 0;
        return intval(TIX_App_Scope::organizer_id_for_user());
    }

    private static function raw($org) {
        $v = $org ? get_post_meta(intval($org), self::META, true) : get_option(self::OPT, []);
        return is_array($v) ? $v : [];
    }

    /** Einstellung eines Bereichs (Schlüssel entschlüsselt) */
    public static function settings($org) {
        $v = self::raw($org);
        $p = isset(self::PROVIDERS[$v['provider'] ?? '']) ? $v['provider'] : 'claude';
        $key = !empty($v['key_enc']) ? (string) TIX_Ad_Accounts::dec($v['key_enc']) : '';
        $model = (string) ($v['model'] ?? '');
        if (!isset(self::models($p)[$model])) $model = self::DEFAULT_MODEL[$p];
        return ['provider' => $p, 'key' => $key, 'model' => $model, 'daily_limit_eur' => round(floatval($v['daily_limit_eur'] ?? 0), 2),
            'allow_platform' => !array_key_exists('allow_platform', $v) || !empty($v['allow_platform']), 'checked_at' => (string) ($v['checked_at'] ?? '')];
    }

    private static function save($org, array $set) {
        $v = self::raw($org);
        if (array_key_exists('key', $set)) { $v['key_enc'] = $set['key'] === '' ? '' : TIX_Ad_Accounts::enc($set['key']); unset($set['key']); }
        $v = array_merge($v, $set);
        if ($org) update_post_meta(intval($org), self::META, $v); else update_option(self::OPT, $v, false);
    }

    /** Alter Weg: Schlüssel aus den Plugin-Einstellungen (bis im Studio etwas eingetragen ist) */
    private static function legacy() {
        if (!function_exists('tix_get_settings')) return null;
        $m = class_exists('TIX_AI_Writer') ? TIX_AI_Writer::model_for(tix_get_settings('ai_model')) : 'claude-sonnet-5-5';
        $openai = (bool) preg_match('/^(gpt|o\d)/', $m);
        $key = trim((string) tix_get_settings($openai ? 'openai_api_key' : 'anthropic_api_key'));
        return $key !== '' ? ['provider' => $openai ? 'openai' : 'claude', 'key' => $key, 'model' => $m, 'daily_limit_eur' => 0.0, 'source' => 'legacy', 'scope' => 0] : null;
    }

    /** Wirksame Einstellung → {provider, key, model, daily_limit_eur, source organizer|site|legacy, scope} | null */
    public static function effective($org = null) {
        $org = $org === null ? self::context_org() : intval($org);
        if ($org) {
            $o = self::settings($org);
            if ($o['key'] !== '') return $o + ['source' => 'organizer', 'scope' => $org];
        }
        $s = self::settings(0);
        if ($s['key'] !== '' && (!$org || $s['allow_platform'])) return $s + ['source' => 'site', 'scope' => $org ?: 0];
        if ($org && $s['key'] !== '' && !$s['allow_platform']) return null; // Plattform-Schlüssel nicht freigegeben
        $l = self::legacy();
        return $l ? $l + ['scope' => $org ?: 0] : null;
    }

    /** Im Studio eingetragen (Veranstalter oder Seite)? Dann laufen alle Aufrufe über diese Schicht. */
    public static function configured($org = null) {
        $e = self::effective($org);
        return $e && $e['source'] !== 'legacy';
    }

    public static function ready($org = null) { return self::effective($org) !== null; }

    public static function hint($key) {
        $key = (string) $key;
        if ($key === '') return '';
        $pre = preg_match('/^(sk-ant-|sk-proj-|sk-)/', $key, $m) ? $m[1] : substr($key, 0, 3);
        return $pre . '…' . substr($key, -4);
    }

    // ═══════════════ Verbrauch ═══════════════

    public static function cost_eur($model, array $usage) {
        $p = (array) apply_filters('tix_ai_prices', self::PRICES);
        list($pi, $po) = $p[$model] ?? [3.0, 15.0];
        $in = intval($usage['input_tokens'] ?? 0) + intval($usage['cache_creation_input_tokens'] ?? 0) * 1.25 + intval($usage['cache_read_input_tokens'] ?? 0) * 0.1;
        return ($in * $pi + intval($usage['output_tokens'] ?? 0) * $po) / 1e6 * self::USD_EUR;
    }

    public static function usage_day($scope, $day = null) {
        $u = get_option(self::OPT_USAGE, []);
        $r = is_array($u) ? ($u[$day ?: current_time('Y-m-d')][intval($scope)] ?? []) : [];
        return ['n' => intval($r['n'] ?? 0), 'cost_eur' => floatval($r['cost_eur'] ?? 0), 'in' => intval($r['in'] ?? 0), 'out' => intval($r['out'] ?? 0), 'purposes' => (array) ($r['purposes'] ?? [])];
    }

    private static function purpose() {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $f) if (!empty($f['class']) && isset(self::PURPOSES[$f['class']]) && $f['class'] !== 'TIX_AI_Writer') return self::PURPOSES[$f['class']];
        return 'texte';
    }

    public static function record($scope, $model, array $usage) {
        $u = get_option(self::OPT_USAGE, []);
        $u = is_array($u) ? $u : [];
        $d = current_time('Y-m-d');
        $r = self::usage_day($scope, $d);
        $c = self::cost_eur($model, $usage);
        $pp = self::purpose();
        $r['purposes'][$pp] = round(floatval($r['purposes'][$pp] ?? 0) + $c, 4);
        $u[$d][intval($scope)] = ['n' => $r['n'] + 1, 'cost_eur' => round($r['cost_eur'] + $c, 4), 'in' => $r['in'] + intval($usage['input_tokens'] ?? 0), 'out' => $r['out'] + intval($usage['output_tokens'] ?? 0), 'purposes' => $r['purposes']];
        $keep = gmdate('Y-m-d', strtotime($d) - self::KEEP_DAYS * DAY_IN_SECONDS);
        foreach (array_keys($u) as $k) if ($k < $keep) unset($u[$k]);
        update_option(self::OPT_USAGE, $u, false);
        return $c;
    }

    // ═══════════════ Senden ═══════════════

    private static function http($url, array $args) {
        return self::$http ? call_user_func(self::$http, $url, $args) : wp_remote_post($url, $args);
    }

    private static function fake_response($code, array $body) {
        return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];
    }

    /**
     * Anfrage im Anthropic-Format an den wirksamen Anbieter. → Antwort wie wp_remote_post (Body im Anthropic-Format) | WP_Error
     * $cfg = effective() oder eine eigene Einstellung (Test des Schlüssels).
     */
    public static function send(array $payload, $timeout = 30, $cfg = null) {
        $cfg = $cfg ?: self::effective();
        if (!$cfg) return new WP_Error('ai_unavailable', 'Die KI ist hier nicht eingerichtet (kein API-Schlüssel).', ['status' => 503]);
        $lim = floatval($cfg['daily_limit_eur'] ?? 0);
        if ($lim > 0 && self::usage_day($cfg['scope'])['cost_eur'] >= $lim) return new WP_Error('ai_budget', 'Das Tageslimit für KI ist erreicht – morgen geht es weiter.', ['status' => 429]);
        $payload['model'] = $cfg['model'];
        if ($cfg['provider'] === 'openai') {
            $r = self::http('https://api.openai.com/v1/chat/completions', ['timeout' => $timeout, 'headers' => ['Authorization' => 'Bearer ' . $cfg['key'], 'Content-Type' => 'application/json'],
                'body' => wp_json_encode(self::to_openai($payload))]);
            if (is_wp_error($r)) return $r;
            $code = intval(wp_remote_retrieve_response_code($r));
            $body = json_decode((string) wp_remote_retrieve_body($r), true);
            if ($code < 200 || $code >= 300 || !is_array($body)) {
                return self::fake_response($code ?: 502, ['type' => 'error', 'error' => ['type' => 'api_error', 'message' => (string) ($body['error']['message'] ?? ('HTTP ' . $code))]]);
            }
            $out = self::from_openai($body, $cfg['model']);
            self::record($cfg['scope'], $cfg['model'], $out['usage']);
            return self::fake_response(200, $out);
        }
        $headers = class_exists('TIX_AI_Writer') ? TIX_AI_Writer::anthropic_headers($cfg['key']) : ['x-api-key' => $cfg['key'], 'content-type' => 'application/json', 'anthropic-version' => '2023-06-01'];
        $r = self::http('https://api.anthropic.com/v1/messages', ['timeout' => $timeout, 'headers' => $headers, 'body' => wp_json_encode($payload)]);
        if (!is_wp_error($r) && intval(wp_remote_retrieve_response_code($r)) === 200) {
            $b = json_decode((string) wp_remote_retrieve_body($r), true);
            self::record($cfg['scope'], (string) ($b['model'] ?? $cfg['model']), (array) ($b['usage'] ?? []));
        }
        return $r;
    }

    // ═══════════════ Übersetzung Anthropic ⇄ OpenAI ═══════════════

    private static function text_of($c) {
        if (is_string($c)) return $c;
        $t = '';
        foreach ((array) $c as $b) if (is_array($b) && ($b['type'] ?? '') === 'text') $t .= ($t !== '' ? "\n\n" : '') . (string) $b['text'];
        return $t;
    }

    public static function to_openai(array $p) {
        $msgs = [];
        $sys = self::text_of($p['system'] ?? '');
        if ($sys !== '') $msgs[] = ['role' => 'system', 'content' => $sys];
        foreach ((array) ($p['messages'] ?? []) as $m) {
            $role = ($m['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $c = $m['content'] ?? '';
            if (is_string($c)) { $msgs[] = ['role' => $role, 'content' => $c]; continue; }
            $parts = []; $calls = []; $results = [];
            foreach ((array) $c as $b) {
                $t = (string) ($b['type'] ?? '');
                if ($t === 'text') $parts[] = ['type' => 'text', 'text' => (string) $b['text']];
                elseif ($t === 'image' && ($b['source']['type'] ?? '') === 'base64') $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $b['source']['media_type'] . ';base64,' . $b['source']['data']]];
                elseif ($t === 'image' && ($b['source']['type'] ?? '') === 'url') $parts[] = ['type' => 'image_url', 'image_url' => ['url' => (string) $b['source']['url']]];
                elseif ($t === 'tool_use') $calls[] = ['id' => (string) $b['id'], 'type' => 'function', 'function' => ['name' => (string) $b['name'], 'arguments' => wp_json_encode((object) ($b['input'] ?? []))]];
                elseif ($t === 'tool_result') $results[] = ['role' => 'tool', 'tool_call_id' => (string) $b['tool_use_id'], 'content' => is_string($b['content'] ?? '') ? (string) ($b['content'] ?? '') : self::text_of($b['content'])];
            }
            foreach ($results as $r) $msgs[] = $r;
            if ($role === 'assistant') {
                $a = ['role' => 'assistant', 'content' => self::text_of(array_map(function ($x) { return $x; }, array_filter($parts, function ($x) { return $x['type'] === 'text'; }))) ?: null];
                if ($calls) $a['tool_calls'] = $calls;
                if ($a['content'] !== null || $calls) $msgs[] = $a;
            } elseif ($parts) {
                $only_text = !array_filter($parts, function ($x) { return $x['type'] !== 'text'; });
                $msgs[] = ['role' => 'user', 'content' => $only_text ? implode("\n\n", array_column($parts, 'text')) : $parts];
            }
        }
        $o = ['model' => (string) $p['model'], 'messages' => $msgs, 'max_completion_tokens' => intval($p['max_tokens'] ?? 1024)];
        if (!empty($p['tools'])) {
            $o['tools'] = array_map(function ($t) { return ['type' => 'function', 'function' => ['name' => (string) $t['name'], 'description' => (string) ($t['description'] ?? ''), 'parameters' => $t['input_schema'] ?? ['type' => 'object', 'properties' => new stdClass()]]]; }, array_values(array_filter((array) $p['tools'], function ($t) { return isset($t['name'], $t['input_schema']); })));
            if (!$o['tools']) unset($o['tools']);
        }
        $tc = $p['tool_choice'] ?? null;
        if (isset($o['tools']) && is_array($tc)) {
            if (($tc['type'] ?? '') === 'tool') $o['tool_choice'] = ['type' => 'function', 'function' => ['name' => (string) $tc['name']]];
            elseif (($tc['type'] ?? '') === 'any') $o['tool_choice'] = 'required';
            elseif (($tc['type'] ?? '') === 'none') $o['tool_choice'] = 'none';
        }
        return $o;
    }

    public static function from_openai(array $b, $model) {
        $msg = (array) ($b['choices'][0]['message'] ?? []);
        $content = [];
        if (!empty($msg['content']) && is_string($msg['content'])) $content[] = ['type' => 'text', 'text' => $msg['content']];
        foreach ((array) ($msg['tool_calls'] ?? []) as $c) {
            $in = json_decode((string) ($c['function']['arguments'] ?? '{}'), true);
            $content[] = ['type' => 'tool_use', 'id' => (string) ($c['id'] ?? ''), 'name' => (string) ($c['function']['name'] ?? ''), 'input' => is_array($in) ? $in : []];
        }
        $fr = (string) ($b['choices'][0]['finish_reason'] ?? 'stop');
        return ['id' => (string) ($b['id'] ?? ''), 'type' => 'message', 'role' => 'assistant', 'model' => (string) ($b['model'] ?? $model), 'content' => $content,
            'stop_reason' => $fr === 'tool_calls' ? 'tool_use' : ($fr === 'length' ? 'max_tokens' : 'end_turn'),
            'usage' => ['input_tokens' => intval($b['usage']['prompt_tokens'] ?? 0), 'output_tokens' => intval($b['usage']['completion_tokens'] ?? 0)]];
    }

    /** Schlüssel prüfen (Modell-Liste abrufen, kostet nichts) → true | WP_Error am Feld api_key */
    public static function test_key($provider, $key) {
        $url = $provider === 'openai' ? 'https://api.openai.com/v1/models' : 'https://api.anthropic.com/v1/models';
        $h = $provider === 'openai' ? ['Authorization' => 'Bearer ' . $key] : (class_exists('TIX_AI_Writer') ? TIX_AI_Writer::anthropic_headers($key) : ['x-api-key' => $key, 'anthropic-version' => '2023-06-01']);
        $args = ['timeout' => 15, 'headers' => $h, 'method' => 'GET'];
        $r = self::$http ? call_user_func(self::$http, $url, $args) : wp_remote_get($url, $args);
        if (is_wp_error($r)) return new WP_Error('tix_ai_key', 'Der Anbieter ist gerade nicht erreichbar – bitte später noch einmal.', ['status' => 502, 'field' => 'api_key']);
        $code = intval(wp_remote_retrieve_response_code($r));
        if ($code === 200) return true;
        $msg = $code === 401 ? 'Der Schlüssel wird nicht angenommen.' : ($code === 403 ? 'Der Schlüssel hat keine Berechtigung für die API.' : 'Der Anbieter meldet einen Fehler (HTTP ' . $code . ').');
        return new WP_Error('tix_ai_key', $msg, ['status' => 400, 'field' => 'api_key']);
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $p = ['TIX_Ad_Accounts', 'perm'];
        register_rest_route(self::NS, '/ai/settings', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $p],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $p],
        ]);
        register_rest_route(self::NS, '/ai/usage', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_usage'], 'permission_callback' => $p]);
    }

    /** Bereich der Anfrage: evendis-Veranstalter → eigener; Admin auf evendis ohne ?organizer → Plattform; Einzel-Seite → Seite */
    private static function scope(WP_REST_Request $req) { return self::multi() ? intval(TIX_Ad_Accounts::org($req)) : 0; }

    private static function can_site() { return current_user_can('manage_options') || !self::multi(); }

    public static function rest_get(WP_REST_Request $req) {
        $org = self::scope($req);
        $s = self::settings($org);
        $eff = self::effective($org);
        $site = self::settings(0);
        $models = [];
        foreach (self::PROVIDERS as $id => $label) $models[$id] = ['label' => $label, 'default' => self::DEFAULT_MODEL[$id], 'models' => array_map(function ($k, $v) { return ['id' => $k, 'label' => $v]; }, array_keys(self::models($id)), self::models($id))];
        $u = self::usage_day($eff['scope'] ?? $org);
        $r = rest_ensure_response(['ok' => true, 'scope' => $org ? 'organizer' : 'site', 'organizer_id' => $org,
            'provider' => $s['provider'], 'model' => $s['model'], 'connected' => $s['key'] !== '', 'key_hint' => self::hint($s['key']), 'checked_at' => $s['checked_at'] ?: null,
            'daily_limit_eur' => $s['daily_limit_eur'],
            'effective' => $eff ? ['source' => $eff['source'], 'provider' => $eff['provider'], 'model' => $eff['model']] : null,
            'platform' => self::multi() ? ['available' => $site['key'] !== '', 'allowed' => $site['allow_platform'], 'provider' => $site['provider']] : null,
            'allow_platform' => $org ? null : $site['allow_platform'], 'can_edit' => $org ? true : self::can_site(),
            'providers' => $models, 'today' => ['requests' => $u['n'], 'cost_eur' => round($u['cost_eur'], 2)]]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    public static function rest_save(WP_REST_Request $req) {
        $org = self::scope($req);
        if (!$org && !self::can_site()) return new WP_Error('rest_forbidden', 'Die KI der Plattform richtet nur ein Admin ein.', ['status' => 403]);
        if (rest_sanitize_boolean($req->get_param('disconnect'))) {
            self::save($org, ['key' => '', 'checked_at' => '']);
            return self::rest_get($req);
        }
        $cur = self::settings($org);
        $set = [];
        $provider = $req->has_param('provider') ? (string) $req->get_param('provider') : $cur['provider'];
        if (!isset(self::PROVIDERS[$provider])) return new WP_Error('tix_invalid_field', 'Anbieter: claude oder openai.', ['status' => 400, 'field' => 'provider']);
        $key = $req->has_param('api_key') ? trim((string) $req->get_param('api_key')) : null;
        if ($provider !== $cur['provider'] && $key === null && $cur['key'] !== '') return new WP_Error('tix_invalid_field', 'Für einen anderen Anbieter bitte dessen Schlüssel eintragen.', ['status' => 400, 'field' => 'api_key']);
        if ($key !== null) {
            if ($key === '' || strlen($key) < 20 || strlen($key) > 300 || preg_match('/\s/', $key)) return new WP_Error('tix_invalid_field', 'Bitte den vollständigen API-Schlüssel einfügen.', ['status' => 400, 'field' => 'api_key']);
            if ($provider === 'claude' && strpos($key, 'sk-ant-') !== 0) return new WP_Error('tix_invalid_field', 'Ein Claude-Schlüssel beginnt mit „sk-ant-“.', ['status' => 400, 'field' => 'api_key']);
            if ($provider === 'openai' && (strpos($key, 'sk-') !== 0 || strpos($key, 'sk-ant-') === 0)) return new WP_Error('tix_invalid_field', 'Ein OpenAI-Schlüssel beginnt mit „sk-“.', ['status' => 400, 'field' => 'api_key']);
            $t = self::test_key($provider, $key);
            if (is_wp_error($t)) return $t;
            $set['key'] = $key;
            $set['checked_at'] = current_time('mysql', true);
        }
        $set['provider'] = $provider;
        $model = $req->has_param('model') ? (string) $req->get_param('model') : ($provider === $cur['provider'] ? $cur['model'] : self::DEFAULT_MODEL[$provider]);
        if (!isset(self::models($provider)[$model])) return new WP_Error('tix_invalid_field', 'Unbekanntes Modell für diesen Anbieter.', ['status' => 400, 'field' => 'model']);
        $set['model'] = $model;
        if ($req->has_param('daily_limit_eur')) {
            $l = round(floatval(str_replace(',', '.', (string) $req->get_param('daily_limit_eur'))), 2);
            if ($l < 0 || $l > 1000) return new WP_Error('tix_invalid_field', 'Tageslimit: 0–1000 € (0 = ohne Limit).', ['status' => 400, 'field' => 'daily_limit_eur']);
            $set['daily_limit_eur'] = $l;
        }
        if ($req->has_param('allow_platform')) {
            if ($org || !current_user_can('manage_options') || !self::multi()) return new WP_Error('tix_invalid_field', 'Die Freigabe des Plattform-Schlüssels setzt nur der Admin der Plattform.', ['status' => 400, 'field' => 'allow_platform']);
            $set['allow_platform'] = rest_sanitize_boolean($req->get_param('allow_platform')) ? 1 : 0;
        }
        self::save($org, $set);
        return self::rest_get($req);
    }

    public static function rest_usage(WP_REST_Request $req) {
        $org = self::scope($req);
        $eff = self::effective($org);
        $scope = intval($eff['scope'] ?? $org);
        $days = max(1, min(62, intval($req->get_param('days') ?: 30)));
        $rows = []; $sum = ['requests' => 0, 'cost_eur' => 0.0]; $purp = [];
        $month = current_time('Y-m'); $msum = 0.0;
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = wp_date('Y-m-d', current_time('timestamp', true) - $i * DAY_IN_SECONDS);
            $u = self::usage_day($scope, $d);
            $rows[] = ['date' => $d, 'requests' => $u['n'], 'cost_eur' => round($u['cost_eur'], 2)];
            $sum['requests'] += $u['n']; $sum['cost_eur'] += $u['cost_eur'];
            foreach ($u['purposes'] as $k => $v) $purp[$k] = round(($purp[$k] ?? 0) + $v, 2);
        }
        foreach ((array) get_option(self::OPT_USAGE, []) as $d => $by) if (strpos((string) $d, $month) === 0) $msum += floatval($by[$scope]['cost_eur'] ?? 0);
        $lim = floatval($eff['daily_limit_eur'] ?? 0);
        $today = self::usage_day($scope);
        return rest_ensure_response(['ok' => true, 'scope' => $org ? 'organizer' : 'site', 'source' => $eff['source'] ?? null, 'days' => $rows,
            'total' => ['requests' => $sum['requests'], 'cost_eur' => round($sum['cost_eur'], 2)], 'month_eur' => round($msum, 2), 'by_purpose' => $purp,
            'today' => ['requests' => $today['n'], 'cost_eur' => round($today['cost_eur'], 2)], 'daily_limit_eur' => $lim, 'remaining_today_eur' => $lim > 0 ? max(0, round($lim - $today['cost_eur'], 2)) : null,
            'note' => 'Geschätzte Kosten aus der Zahl der Token; maßgeblich ist die Abrechnung des Anbieters.']);
    }
}

TIX_AI_Provider::init();
