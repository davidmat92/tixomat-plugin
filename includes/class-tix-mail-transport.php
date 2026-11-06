<?php
if (!defined('ABSPATH')) exit;

/**
 * Mailversand wählbar (1.38.674, Doku §95): „Über evendis“ (Standard) oder „Eigener Dienst“.
 *
 *  - evendis: Versand wie eingerichtet (FluentSMTP → evendis-Brevo), Absender fest noreply@evendis.de, Name = Name der Seite,
 *    Reply-To = Kontakt (setzt TIX_Mail_Layout wie bisher).
 *  - own + brevo: Tixomat schickt jede Mail selbst über die Brevo-API des Kunden (pre_wp_mail). Schlägt das fehl, läuft dieselbe
 *    Mail normal weiter über WordPress (= evendis) mit Absender noreply@evendis.de – Rückfall mit Hinweis im Studio.
 *  - own + server: Eigener Absender, auf dem Server eingerichtet (KitchenKlub, Mallorca). Tixomat ändert nichts – so wie heute.
 * Ohne gespeicherte Wahl: noreply@…evendis.de als Absender → evendis, sonst own/server. Bestehende Seiten ändern sich also nicht.
 *
 * Weitere Anbieter (SMTP, Postmark …) bekommen eine Klasse mit TIX_Mail_Provider und einen Eintrag in PROVIDERS.
 *
 *   GET  /studio/mail-transport          Stand (Schlüssel nur maskiert)
 *   POST /studio/mail-transport          {mode, provider?, api_key?, from_email?, from_name?, reply_to?}
 *   POST /studio/mail-transport/test     {to?} Testmail über den gewählten Weg, ohne Rückfall
 * Rechte wie Marke (TIX_Studio::perm_brand).
 */
interface TIX_Mail_Provider {
    /** @param array $cfg gespeicherte Einstellungen inkl. 'api_key' im Klartext
     *  @param array $m {to:[{email,name}], cc, bcc, subject, html, text, from_email, from_name, reply_to, attachments:[path], headers:{}}
     *  @return array|WP_Error {message_id} */
    public static function send(array $cfg, array $m);
    /** Zugang prüfen: true | WP_Error (code tix_mail_key_invalid | tix_mail_ip_blocked | tix_mail_unreachable) */
    public static function verify(array $cfg);
    /** Absender-Domain beim Anbieter: {name, status verified|pending|missing|unknown, hint} */
    public static function domain(array $cfg, $domain);
}

class TIX_Mail_Transport {

    const NS = 'tixomat/v1';
    const OPTION = 'tix_mail_transport';
    const STATE = 'tix_mail_transport_state';
    const EVENDIS_FROM = 'noreply@evendis.de';
    const PROVIDERS = [
        'brevo' => ['label' => 'Brevo', 'class' => 'TIX_Mail_Brevo', 'available' => true, 'fields' => ['api_key', 'from_email', 'from_name', 'reply_to']],
        'smtp' => ['label' => 'SMTP', 'class' => '', 'available' => false, 'fields' => []],
        'postmark' => ['label' => 'Postmark', 'class' => '', 'available' => false, 'fields' => []],
    ];

    /** Rückfall für genau eine Mail (Absender evendis) */
    private static $force_evendis = false;
    /** Tests: callable($provider, $cfg, $m) statt Versand */
    public static $send_override = null;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('pre_wp_mail', [__CLASS__, 'pre_wp_mail'], 50, 2);
        add_filter('wp_mail_from', [__CLASS__, 'filter_from'], 999);
        add_filter('wp_mail_from_name', [__CLASS__, 'filter_from_name'], 999);
        add_action('wp_mail_succeeded', [__CLASS__, 'reset_force']);
        add_action('wp_mail_failed', [__CLASS__, 'reset_force']);
    }

    // ═══════════════ Einstellungen ═══════════════

    public static function stored() {
        $o = get_option(self::OPTION, []);
        return is_array($o) ? $o : [];
    }

    private static function crypto_key() {
        return hash('sha256', 'tix-mail-transport|' . wp_salt('secure_auth'), true);
    }

    public static function encrypt($plain) {
        if ($plain === '' || !function_exists('openssl_encrypt')) return '';
        $iv = random_bytes(12); $tag = '';
        $ct = openssl_encrypt((string) $plain, 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $ct === false ? '' : 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt($enc) {
        if (!is_string($enc) || strpos($enc, 'v1:') !== 0 || !function_exists('openssl_decrypt')) return '';
        $bin = base64_decode(substr($enc, 3), true);
        if ($bin === false || strlen($bin) < 29) return '';
        $pt = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        return $pt === false ? '' : $pt;
    }

    private static function site_name() {
        return class_exists('TIX_Mail_Layout') ? (string) (TIX_Mail_Layout::brand()['from_name'] ?: TIX_Mail_Layout::brand()['name']) : html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
    }

    private static function site_reply_to() {
        return class_exists('TIX_Mail_Layout') ? (string) TIX_Mail_Layout::brand()['support_email'] : '';
    }

    /** Absender laut Studio-Marke (so, wie die Seite heute verschickt). */
    private static function site_from() {
        return class_exists('TIX_Mail_Layout') ? (string) TIX_Mail_Layout::default_from() : '';
    }

    /** [mode evendis|own, provider brevo|server|null, saved] */
    public static function choice() {
        $o = self::stored();
        if (in_array($o['mode'] ?? '', ['evendis', 'own'], true)) {
            $p = $o['mode'] === 'own' ? (isset(self::PROVIDERS[$o['provider'] ?? '']) ? $o['provider'] : 'server') : null;
            return [$o['mode'], $p, true];
        }
        // nichts gewählt: wie heute
        return preg_match('/@([a-z0-9-]+\.)*evendis\.de$/i', self::site_from()) || self::site_from() === '' ? ['evendis', null, false] : ['own', 'server', false];
    }

    /** Einstellungen des Anbieters mit Schlüssel im Klartext (nur serverseitig). */
    private static function provider_cfg($p) {
        $c = (array) (self::stored()[$p] ?? []);
        $c['api_key'] = self::decrypt($c['key_enc'] ?? '');
        unset($c['key_enc']);
        return $c;
    }

    private static function provider_class($p) {
        $c = self::PROVIDERS[$p]['class'] ?? '';
        return $c !== '' && class_exists($c) ? $c : '';
    }

    // ═══════════════ Versand ═══════════════

    public static function filter_from($from) {
        [$mode] = self::choice();
        return ($mode === 'evendis' || self::$force_evendis) ? self::EVENDIS_FROM : $from;
    }

    public static function filter_from_name($name) {
        [$mode] = self::choice();
        if ($mode === 'evendis' || self::$force_evendis) { $n = self::site_name(); return $n !== '' ? $n : $name; }
        return $name;
    }

    public static function reset_force() {
        self::$force_evendis = false;
    }

    private static function header_list($h) {
        if (is_array($h)) return array_values(array_filter(array_map('strval', $h), 'strlen'));
        $h = trim((string) $h);
        return $h === '' ? [] : preg_split("/\r\n|\n|\r/", $h);
    }

    /** „Name <mail>“ bzw. „mail“ → {email, name} */
    private static function addr($s) {
        $s = trim((string) $s);
        if (preg_match('/^(.*)<([^>]+)>$/', $s, $m)) return ['email' => trim($m[2]), 'name' => trim(trim($m[1]), "\"' ")];
        return ['email' => $s, 'name' => ''];
    }

    private static function addr_list($v) {
        $out = [];
        foreach ((array) (is_array($v) ? $v : explode(',', (string) $v)) as $a) {
            $x = self::addr($a);
            if (is_email($x['email'])) $out[] = $x['name'] !== '' ? $x : ['email' => $x['email']];
        }
        return $out;
    }

    /**
     * wp_mail-Argumente → Nachricht für den Anbieter. Läuft phpmailer_init auf einem leeren PHPMailer, damit Text-Fassung
     * (TIX_Mail_Layout) und Message-ID (Support-Verlauf) wie beim normalen Versand entstehen; verschickt wird damit nichts.
     */
    public static function message(array $atts, array $cfg) {
        $headers = self::header_list($atts['headers'] ?? []);
        $m = ['to' => self::addr_list($atts['to'] ?? []), 'cc' => [], 'bcc' => [], 'reply_to' => null, 'headers' => [],
            'subject' => (string) ($atts['subject'] ?? ''), 'attachments' => array_values(array_filter((array) ($atts['attachments'] ?? []), 'is_string'))];
        $ctype = 'text/plain';
        foreach ($headers as $h) {
            if (strpos($h, ':') === false) continue;
            [$k, $v] = array_map('trim', explode(':', $h, 2));
            switch (strtolower($k)) {
                case 'content-type': if (stripos($v, 'text/html') !== false) $ctype = 'text/html'; break;
                case 'cc': $m['cc'] = array_merge($m['cc'], self::addr_list($v)); break;
                case 'bcc': $m['bcc'] = array_merge($m['bcc'], self::addr_list($v)); break;
                case 'reply-to': $r = self::addr($v); if (is_email($r['email'])) $m['reply_to'] = $r; break;
                case 'from': break; // Absender kommt aus dem eigenen Dienst
                default: if (preg_match('/^(x-[a-z0-9-]+|list-unsubscribe|list-unsubscribe-post|in-reply-to|references)$/i', $k)) $m['headers'][$k] = $v;
            }
        }
        $ctype = apply_filters('wp_mail_content_type', $ctype);
        $body = (string) ($atts['message'] ?? '');
        $m['html'] = $ctype === 'text/html' ? $body : null;
        $m['text'] = $ctype === 'text/html' ? null : $body;
        // phpmailer_init nur zum Ablesen (AltBody, MessageID)
        if (file_exists(ABSPATH . WPINC . '/PHPMailer/PHPMailer.php')) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
            $pm = new PHPMailer\PHPMailer\PHPMailer(true);
            $pm->ContentType = $ctype; $pm->Body = $body; $pm->Subject = $m['subject'];
            try { do_action_ref_array('phpmailer_init', [&$pm]); } catch (\Throwable $e) {}
            if ($m['html'] !== null && $pm->AltBody !== '') $m['text'] = (string) $pm->AltBody;
            if ((string) $pm->MessageID !== '') $m['headers']['Message-Id'] = (string) $pm->MessageID;
        }
        if ($m['html'] !== null && $m['text'] === null && class_exists('TIX_Mail_Layout')) $m['text'] = TIX_Mail_Layout::html_to_text($body);
        $m['from_email'] = (string) ($cfg['from_email'] ?? '');
        $m['from_name'] = (string) ($cfg['from_name'] ?? '') ?: self::site_name();
        if (!$m['reply_to'] && is_email($cfg['reply_to'] ?? '')) $m['reply_to'] = ['email' => $cfg['reply_to']];
        return $m;
    }

    /** Über den Anbieter senden (ohne Rückfall). */
    public static function send_via($p, array $cfg, array $m) {
        if (is_callable(self::$send_override)) return call_user_func(self::$send_override, $p, $cfg, $m);
        $cls = self::provider_class($p);
        if ($cls === '') return new WP_Error('tix_mail_provider_missing', 'Dieser Dienst ist noch nicht verfügbar.');
        return $cls::send($cfg, $m);
    }

    /**
     * pre_wp_mail: eigener Dienst → selbst senden. Fehler → Rückfall: null zurück (WordPress verschickt normal), Absender evendis.
     */
    public static function pre_wp_mail($return, $atts) {
        if ($return !== null) return $return; // anderer Filter (Demo, *.invalid) hat schon entschieden
        [$mode, $p] = self::choice();
        if ($mode !== 'own' || $p === 'server' || !$p) return null;
        $cfg = self::provider_cfg($p);
        if ($cfg['api_key'] === '' || !is_email($cfg['from_email'] ?? '')) { self::fallback('tix_mail_not_configured', 'Eigener Dienst nicht vollständig eingerichtet.'); return null; }
        $m = self::message((array) $atts, $cfg);
        if (!$m['to']) return null;
        $r = self::send_via($p, $cfg, $m);
        if (is_wp_error($r)) { self::fallback($r->get_error_code(), $r->get_error_message()); return null; }
        self::ok();
        do_action('wp_mail_succeeded', (array) $atts + ['via' => $p]);
        return true;
    }

    // ═══════════════ Zustand ═══════════════

    public static function state() {
        $s = get_option(self::STATE, []);
        return is_array($s) ? $s : [];
    }

    private static function ok() {
        $s = self::state();
        if (time() - intval($s['last_ok'] ?? 0) < 600 && empty($s['failing'])) return; // nicht bei jeder Mail schreiben
        $s['last_ok'] = time(); $s['failing'] = false;
        update_option(self::STATE, $s, false);
    }

    private static function fallback($code, $msg) {
        self::$force_evendis = true;
        $s = self::state();
        $now = time();
        $s['fallbacks'] = array_values(array_filter((array) ($s['fallbacks'] ?? []), function ($t) use ($now) { return $t > $now - DAY_IN_SECONDS; }));
        if (count($s['fallbacks']) < 500) $s['fallbacks'][] = $now;
        $s['last_error'] = ['at' => $now, 'code' => (string) $code, 'message' => mb_substr(wp_strip_all_tags((string) $msg), 0, 300)];
        $s['failing'] = true;
        update_option(self::STATE, $s, false);
        do_action('tix_mail_transport_fallback', $code, $msg);
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $perm = ['TIX_Studio', 'perm_brand'];
        register_rest_route(self::NS, '/studio/mail-transport', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $perm],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $perm],
        ]);
        register_rest_route(self::NS, '/studio/mail-transport/test', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_test'], 'permission_callback' => $perm]);
    }

    private static function hint($key) {
        return $key === '' ? null : '••••' . substr($key, -4);
    }

    /** Antwort für das Studio (nie der Schlüssel selbst). */
    public static function payload($check_domain = false) {
        [$mode, $p, $saved] = self::choice();
        $st = self::state();
        $now = time();
        $fb = array_values(array_filter((array) ($st['fallbacks'] ?? []), function ($t) use ($now) { return $t > $now - DAY_IN_SECONDS; }));
        $brevo = self::provider_cfg('brevo');
        $evendis = ['from_email' => self::EVENDIS_FROM, 'from_name' => self::site_name(), 'reply_to' => self::site_reply_to() ?: null];
        $own_cfg = $p && $p !== 'server' ? self::provider_cfg($p) : [];
        $state = 'ok';
        if ($mode === 'own' && $p !== 'server') {
            if (!empty($st['failing'])) $state = 'fallback';
            elseif (empty($st['last_ok'])) $state = 'untested';
        }
        $out = [
            'ok' => true,
            'mode' => $mode, 'provider' => $p, 'saved' => $saved,
            'evendis' => $evendis,
            'own' => [
                'provider' => $p && $p !== 'server' ? $p : (isset(self::stored()['brevo']) ? 'brevo' : null),
                'brevo' => ['api_key_set' => $brevo['api_key'] !== '', 'api_key_hint' => self::hint($brevo['api_key']),
                    'from_email' => $brevo['from_email'] ?? null, 'from_name' => $brevo['from_name'] ?? null, 'reply_to' => $brevo['reply_to'] ?? null],
                // Eigener Absender vom Betrieb (FluentSMTP): nur anzeigen
                'server' => $p === 'server' ? ['from_email' => self::site_from() ?: null, 'from_name' => self::site_name(), 'reply_to' => self::site_reply_to() ?: null] : null,
            ],
            'providers' => array_map(function ($k, $v) { return ['key' => $k, 'label' => $v['label'], 'available' => $v['available'], 'fields' => $v['fields']]; }, array_keys(self::PROVIDERS), self::PROVIDERS),
            'effective' => $mode === 'evendis' ? $evendis + ['via' => 'evendis']
                : ($p === 'server' ? ['from_email' => self::site_from() ?: null, 'from_name' => self::site_name(), 'reply_to' => self::site_reply_to() ?: null, 'via' => 'server']
                : ['from_email' => $own_cfg['from_email'] ?? null, 'from_name' => ($own_cfg['from_name'] ?? '') ?: self::site_name(), 'reply_to' => ($own_cfg['reply_to'] ?? '') ?: (self::site_reply_to() ?: null), 'via' => $p]),
            'status' => ['state' => $state, 'last_ok' => !empty($st['last_ok']) ? gmdate('c', $st['last_ok']) : null,
                'last_error' => !empty($st['last_error']) ? ['at' => gmdate('c', $st['last_error']['at']), 'code' => $st['last_error']['code'], 'message' => $st['last_error']['message']] : null,
                'fallbacks_24h' => count($fb), 'last_fallback' => $fb ? gmdate('c', max($fb)) : null],
            'domain' => null,
        ];
        if ($check_domain && $mode === 'own' && $p === 'brevo' && is_email($own_cfg['from_email'] ?? '') && ($cls = self::provider_class('brevo'))) {
            $dom = strtolower(substr(strrchr($own_cfg['from_email'], '@'), 1));
            $tk = 'tix_mail_dom_' . md5($dom . '|' . substr($own_cfg['api_key'], -6));
            $d = get_transient($tk);
            if (!is_array($d)) { $d = $cls::domain($own_cfg, $dom); set_transient($tk, $d, 10 * MINUTE_IN_SECONDS); }
            $out['domain'] = $d;
        }
        return $out;
    }

    public static function rest_get(WP_REST_Request $req) {
        $r = new WP_REST_Response(self::payload(true));
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    private static function bad($field, $msg, $code = 'tix_invalid_field') {
        return new WP_Error($code, $msg, ['status' => 400, 'field' => $field]);
    }

    /** POST {mode: evendis|own, provider?: brevo, api_key?, from_email?, from_name?, reply_to?} */
    public static function rest_save(WP_REST_Request $req) {
        $b = (array) $req->get_json_params();
        $mode = (string) ($b['mode'] ?? '');
        if (!in_array($mode, ['evendis', 'own'], true)) return self::bad('mode', 'Bitte „evendis“ oder „own“ wählen.');
        $o = self::stored();
        $warning = null;
        if ($mode === 'evendis') {
            $o['mode'] = 'evendis';
        } else {
            $p = (string) ($b['provider'] ?? ($o['provider'] ?? 'brevo'));
            if (!isset(self::PROVIDERS[$p])) return self::bad('provider', 'Unbekannter Dienst.');
            if (!self::PROVIDERS[$p]['available']) return self::bad('provider', self::PROVIDERS[$p]['label'] . ' folgt später.');
            $c = (array) ($o[$p] ?? []);
            if (array_key_exists('api_key', $b) && $b['api_key'] !== null) {
                $k = trim((string) $b['api_key']);
                if ($k === '') unset($c['key_enc']);
                else {
                    if (strlen($k) < 20 || strlen($k) > 200 || preg_match('/\s/', $k)) return self::bad('api_key', 'Der API-Schlüssel sieht nicht vollständig aus.');
                    $c['key_enc'] = self::encrypt($k);
                }
            }
            foreach (['from_email', 'reply_to'] as $f) {
                if (!array_key_exists($f, $b)) continue;
                $v = sanitize_email((string) $b[$f]);
                if ((string) $b[$f] !== '' && !is_email($v)) return self::bad($f, 'Ungültige E-Mail-Adresse.');
                if ($v === '') unset($c[$f]); else $c[$f] = $v;
            }
            if (array_key_exists('from_name', $b)) { $n = mb_substr(sanitize_text_field((string) $b['from_name']), 0, 80); if ($n === '') unset($c['from_name']); else $c['from_name'] = $n; }
            if (empty($c['key_enc'])) return self::bad('api_key', 'Bitte den API-Schlüssel eintragen.');
            if (empty($c['from_email'])) return self::bad('from_email', 'Bitte die Absender-Adresse eintragen.');
            // Zugang prüfen
            $cls = self::provider_class($p);
            $plain = self::decrypt($c['key_enc']);
            $v = is_callable(self::$send_override) ? true : ($cls ? $cls::verify(['api_key' => $plain] + $c) : true);
            if (is_wp_error($v)) {
                if ($v->get_error_code() === 'tix_mail_key_invalid') return new WP_Error('tix_mail_key_invalid', $v->get_error_message(), ['status' => 400, 'field' => 'api_key']);
                $warning = ['code' => $v->get_error_code(), 'message' => $v->get_error_message()];
            }
            $o[$p] = $c;
            $o['mode'] = 'own';
            $o['provider'] = $p;
            // Neuer Weg: Zustand zurücksetzen, die nächste Mail zeigt, ob es geht
            delete_option(self::STATE);
        }
        $o['updated_at'] = time();
        $o['updated_by'] = get_current_user_id();
        update_option(self::OPTION, $o, false);
        return rest_ensure_response(self::payload(true) + ['warning' => $warning]);
    }

    /** POST /test {to?} – Testmail über den gewählten Weg (eigener Dienst ohne Rückfall). */
    public static function rest_test(WP_REST_Request $req) {
        $b = (array) $req->get_json_params();
        $to = array_key_exists('to', $b) && (string) $b['to'] !== '' ? sanitize_email((string) $b['to']) : (string) wp_get_current_user()->user_email;
        if (!is_email($to)) return self::bad('to', 'Ungültige E-Mail-Adresse.');
        [$mode, $p] = self::choice();
        $name = self::site_name();
        $subject = 'Testmail von ' . $name;
        $text = "Hallo,\n\ndiese Testmail zeigt, dass der Mailversand von {$name} funktioniert.\n\nVersand: " . ($mode === 'evendis' ? 'über evendis' : ($p === 'server' ? 'eigener Absender (Server)' : self::PROVIDERS[$p]['label'])) . "\nZeit: " . wp_date('d.m.Y H:i') . "\n";
        if ($mode === 'own' && $p !== 'server') {
            $cfg = self::provider_cfg($p);
            if ($cfg['api_key'] === '' || !is_email($cfg['from_email'] ?? '')) return self::bad('api_key', 'Eigener Dienst nicht vollständig eingerichtet.');
            $m = self::message(['to' => $to, 'subject' => $subject, 'message' => $text, 'headers' => []], $cfg);
            $r = self::send_via($p, $cfg, $m);
            if (is_wp_error($r)) return new WP_Error('tix_mail_send_failed', $r->get_error_message(), ['status' => 502, 'reason' => $r->get_error_code()]);
            self::ok();
            return rest_ensure_response(['ok' => true, 'via' => $p, 'to' => $to, 'from_email' => $m['from_email'], 'message_id' => $r['message_id'] ?? null]);
        }
        $err = null;
        $cb = function ($e) use (&$err) { $err = $e; };
        add_action('wp_mail_failed', $cb);
        $sent = wp_mail($to, $subject, $text);
        remove_action('wp_mail_failed', $cb);
        if (!$sent) return new WP_Error('tix_mail_send_failed', $err instanceof WP_Error ? $err->get_error_message() : 'Die Testmail konnte nicht verschickt werden.', ['status' => 502, 'reason' => 'wp_mail']);
        return rest_ensure_response(['ok' => true, 'via' => $mode === 'evendis' ? 'evendis' : 'server', 'to' => $to, 'from_email' => $mode === 'evendis' ? self::EVENDIS_FROM : (self::site_from() ?: null), 'message_id' => null]);
    }
}

/** Brevo (Transaktions-API v3). */
class TIX_Mail_Brevo implements TIX_Mail_Provider {

    const API = 'https://api.brevo.com/v3';

    private static function req($method, $path, $key, $body = null) {
        $args = ['method' => $method, 'timeout' => 15, 'headers' => ['api-key' => $key, 'accept' => 'application/json', 'content-type' => 'application/json']];
        if ($body !== null) $args['body'] = wp_json_encode($body);
        $r = wp_remote_request(self::API . $path, $args);
        if (is_wp_error($r)) return new WP_Error('tix_mail_unreachable', 'Brevo ist nicht erreichbar: ' . $r->get_error_message());
        $code = intval(wp_remote_retrieve_response_code($r));
        $data = json_decode((string) wp_remote_retrieve_body($r), true);
        return [$code, is_array($data) ? $data : []];
    }

    private static function error($code, array $d) {
        $msg = (string) ($d['message'] ?? '');
        if ($code === 401 && stripos($msg, 'ip') !== false) return new WP_Error('tix_mail_ip_blocked', 'Brevo nimmt Aufrufe von diesem Server nicht an. In Brevo unter Sicherheit → Autorisierte IP-Adressen die Adresse des Servers freigeben.');
        if ($code === 401) return new WP_Error('tix_mail_key_invalid', 'Brevo kennt diesen API-Schlüssel nicht.');
        if ($code === 400 && stripos($msg, 'sender') !== false) return new WP_Error('tix_mail_sender_invalid', 'Brevo lehnt den Absender ab. Absender bzw. Domain in Brevo bestätigen: ' . $msg);
        return new WP_Error('tix_mail_provider_error', 'Brevo meldet ' . $code . ($msg !== '' ? ': ' . $msg : '.'));
    }

    public static function send(array $cfg, array $m) {
        $body = ['sender' => array_filter(['email' => $m['from_email'], 'name' => $m['from_name']]), 'to' => $m['to'], 'subject' => $m['subject']];
        if ($m['cc']) $body['cc'] = $m['cc'];
        if ($m['bcc']) $body['bcc'] = $m['bcc'];
        if ($m['reply_to']) $body['replyTo'] = $m['reply_to'];
        if ($m['html'] !== null) $body['htmlContent'] = $m['html'];
        if ($m['text'] !== null && $m['text'] !== '') $body['textContent'] = $m['text'];
        if ($m['headers']) $body['headers'] = $m['headers'];
        $att = [];
        foreach ($m['attachments'] as $f) if (is_readable($f) && filesize($f) < 10 * MB_IN_BYTES) $att[] = ['name' => basename($f), 'content' => base64_encode((string) file_get_contents($f))];
        if ($att) $body['attachment'] = $att;
        $r = self::req('POST', '/smtp/email', $cfg['api_key'], $body);
        if (is_wp_error($r)) return $r;
        [$code, $d] = $r;
        if ($code >= 200 && $code < 300) return ['message_id' => (string) ($d['messageId'] ?? '')];
        return self::error($code, $d);
    }

    public static function verify(array $cfg) {
        $r = self::req('GET', '/account', $cfg['api_key']);
        if (is_wp_error($r)) return $r;
        [$code, $d] = $r;
        return $code === 200 ? true : self::error($code, $d);
    }

    public static function domain(array $cfg, $domain) {
        $hint = 'Damit die Mails ankommen, die Domain ' . $domain . ' in Brevo unter Absender & Domains bestätigen (DKIM, DMARC).';
        $r = self::req('GET', '/senders/domains/' . rawurlencode($domain), $cfg['api_key']);
        if (is_wp_error($r)) return ['name' => $domain, 'status' => 'unknown', 'hint' => $hint];
        [$code, $d] = $r;
        if ($code === 404) return ['name' => $domain, 'status' => 'missing', 'hint' => $hint];
        if ($code !== 200) return ['name' => $domain, 'status' => 'unknown', 'hint' => $hint];
        $ok = !empty($d['authenticated']) || !empty($d['verified']);
        return ['name' => $domain, 'status' => $ok ? 'verified' : 'pending', 'hint' => $ok ? null : $hint];
    }
}
