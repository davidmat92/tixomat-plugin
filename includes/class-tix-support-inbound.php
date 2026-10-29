<?php
/**
 * Support-Mail-Eingang ohne eigenes Postfach (1.38.577).
 *
 * Stufe 1 – Antworten landen automatisch im Ticket:
 *   Ausgehende Support-Mails behalten den Absender der Seite, Reply-To ist eine versteckte
 *   Antwort-Adresse `<slug>-r<id>-<sig>@support.evendis.de`.
 * Stufe 2 – eigene Support-Adresse (optional):
 *   Der Veranstalter leitet z. B. support@seinedomain.de an `<slug>-in-<sig>@support.evendis.de`
 *   weiter → neue Anfragen. `<slug>-fw-<sig>@…` = Einzel-Übernahme einer weitergeleiteten Mail
 *   (Anfrage mit dem ursprünglichen Absender).
 *
 * Zentraler Eingang (Hub, evendis.de-WordPress): Postfix auf evendis-hetzner (Rohmail,
 * empfohlen) oder Brevo Inbound Parsing (JSON) → POST /support/inbound. Der Hub verteilt
 * nach `<slug>` signiert an die Seite (POST /support/inbound/deliver, HMAC je Seite wie
 * /push/relay). Die Seite prüft die Signatur der Adresse selbst (nur sie kennt den Schlüssel)
 * und verarbeitet die Mail über TIX_Support_Mail::process_raw (Zitat-Kürzung, Anhänge,
 * Automatik-/Spam-Filter, Absender-Hinweis, Mitteilung ans Team).
 *
 * WP-CLI: wp tixomat support-inbound status|hub-key|site-add <slug> <url>|connect <slug> <secret> [hub]|simulate <rcpt> <datei.eml>
 */
if (!defined('ABSPATH')) exit;

class TIX_Support_Inbound {

    const NS          = 'tixomat/v1';
    const OPT         = 'tix_sp_inbound';            // Seite: slug, secret, hub, domain, alias, show_contact, Zustand
    const OPT_SITES   = 'tix_sp_inbound_sites';      // Hub: {slug: {url, secret}}
    const OPT_HUB_KEY = 'tix_sp_inbound_hub_key';    // Hub: Schlüssel für Postfix/Brevo
    const OPT_BREVO   = 'tix_sp_inbound_brevo_key';  // Hub (nur Brevo-Weg): API-Schlüssel für Anhänge
    const LOG         = 'tix_sp_inbound_log';
    const DOMAIN      = 'support.evendis.de';
    const HUB_DEFAULT = 'https://veranstalter.evendis.de';
    const MAX_RAW     = 30 * 1024 * 1024;
    const TEST_WAIT   = 15 * MINUTE_IN_SECONDS;

    const PROVIDERS = ['gmail', 'microsoft', 'ionos', 'strato', 'allinkl', 'other'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        if (defined('WP_CLI') && WP_CLI) WP_CLI::add_command('tixomat support-inbound', [__CLASS__, 'cli']);
    }

    // ═══════════════ Einstellungen ═══════════════

    public static function settings() {
        $s = get_option(self::OPT, []);
        return array_merge([
            'slug' => '', 'secret' => '', 'hub' => self::HUB_DEFAULT, 'domain' => self::DOMAIN,
            'alias' => '', 'provider' => '', 'show_contact' => 0,
            'alias_ok_at' => 0, 'alias_last_at' => 0,
            'test_nonce' => '', 'test_sent_at' => 0, 'test_ok_at' => 0,
            'verifications' => [],
        ], is_array($s) ? $s : []);
    }

    private static function save(array $patch) {
        update_option(self::OPT, array_merge(self::settings(), $patch), false);
    }

    /** Seite am zentralen Eingang angemeldet (Slug + Schlüssel)? */
    public static function connected() {
        $s = self::settings();
        return self::valid_slug($s['slug']) && $s['secret'] !== '';
    }

    public static function valid_slug($slug) {
        return is_string($slug) && preg_match('/^[a-z0-9]{2,20}$/', $slug);
    }

    private static function domain() {
        $d = strtolower(trim((string) self::settings()['domain']));
        return $d !== '' ? $d : self::DOMAIN;
    }

    private static function sig($data, $len) {
        return substr(TIX_Support_Mail::sign('inb|' . $data), 0, $len);
    }

    // ── Adressen ──

    public static function reply_address($ticket_id) {
        if (!self::connected()) return '';
        $id = intval($ticket_id);
        return self::settings()['slug'] . '-r' . $id . '-' . self::sig('r|' . $id, 12) . '@' . self::domain();
    }

    public static function channel_address() {
        return self::connected() ? self::settings()['slug'] . '-in-' . self::sig('in', 10) . '@' . self::domain() : '';
    }

    public static function takeover_address() {
        return self::connected() ? self::settings()['slug'] . '-fw-' . self::sig('fw', 10) . '@' . self::domain() : '';
    }

    /** Reply-To-Kopf für Support-Mails (mit Anzeigename der Seite) */
    public static function reply_to_header($ticket_id) {
        $a = self::reply_address($ticket_id);
        if ($a === '') return '';
        $name = trim(wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES));
        $name = trim(preg_replace('/["\\\\\r\n]/', '', $name));
        return $name !== '' ? '"' . $name . ' Support" <' . $a . '>' : $a;
    }

    /** Adresse lokal zerlegen und Signatur prüfen → [kind reply|in|fw, ticket] oder null */
    public static function parse_local($addr) {
        $addr = strtolower(trim((string) $addr, " <>\t\r\n"));
        $s = self::settings();
        if (!self::connected() || substr($addr, -strlen('@' . self::domain())) !== '@' . self::domain()) return null;
        $local = substr($addr, 0, -strlen('@' . self::domain()));
        if (strpos($local, $s['slug'] . '-') !== 0) return null;
        $rest = substr($local, strlen($s['slug']) + 1);
        if (preg_match('/^r(\d{1,10})-([a-f0-9]{12})$/', $rest, $m)) {
            return hash_equals(self::sig('r|' . intval($m[1]), 12), $m[2]) ? ['kind' => 'reply', 'ticket' => intval($m[1])] : null;
        }
        if (preg_match('/^(in|fw)-([a-f0-9]{10})$/', $rest, $m)) {
            return hash_equals(self::sig($m[1], 10), $m[2]) ? ['kind' => $m[1], 'ticket' => 0] : null;
        }
        return null;
    }

    /** Support-Adresse als Kontakt (Website/App), nur wenn eingeschaltet und verbunden */
    public static function contact_email() {
        $s = self::settings();
        return (!empty($s['show_contact']) && is_email($s['alias']) && $s['alias_ok_at']) ? (string) $s['alias'] : '';
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        register_rest_route(self::NS, '/support/inbound', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_hub'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/support/inbound/deliver', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_deliver'], 'permission_callback' => '__return_true',
        ]);
        $perm = [__CLASS__, 'perm_manage'];
        register_rest_route(self::NS, '/support/mail-inbox', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_state'], 'permission_callback' => $perm],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $perm],
        ]);
        register_rest_route(self::NS, '/support/mail-inbox/test', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_test'], 'permission_callback' => $perm,
        ]);
        register_rest_route(self::NS, '/support/mail-inbox/verifications/(?P<id>[a-z0-9]{8,20})/done', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_verification_done'], 'permission_callback' => $perm,
        ]);
    }

    /** Einstellungen: Admins; Einzel-Seite auch Inhaber/Team-Admins (tix_organizer). Plattform: nur Admins. */
    public static function perm_manage(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]);
        if (class_exists('TIX_REST_API')) {
            $c = TIX_REST_API::check_organizer($req);
            if ($c !== true) return $c;
        }
        $u = wp_get_current_user();
        if (user_can($u, 'manage_options')) return true;
        if (!get_option('tix_multi_organizer') && in_array('tix_organizer', (array) $u->roles, true)) return true;
        return new WP_Error('rest_forbidden', 'Den E-Mail-Eingang richten nur Inhaber und Team-Admins ein.', ['status' => 403]);
    }

    public static function rest_state() {
        return rest_ensure_response(self::state());
    }

    public static function state() {
        $s = self::settings();
        $support = function_exists('tix_get_settings') && tix_get_settings('support_enabled');
        $imap = class_exists('TIX_Support_Mail') && TIX_Support_Mail::is_active();
        $alias_status = 'none';
        if (is_email($s['alias'])) {
            if ($s['alias_ok_at']) $alias_status = 'connected';
            elseif ($s['test_sent_at'] && time() - $s['test_sent_at'] > self::TEST_WAIT) $alias_status = 'problem';
            elseif ($s['test_sent_at']) $alias_status = 'testing';
            else $alias_status = 'waiting';
        }
        $ver = array_values(array_filter((array) $s['verifications'], function ($v) { return empty($v['done']); }));
        return [
            'ok'        => true,
            'available' => self::connected(),
            'support_enabled' => (bool) $support,
            'replies'   => [
                'active'  => self::connected() && !$imap,
                'via'     => $imap ? 'imap' : (self::connected() ? 'inbound' : 'none'),
                'example' => self::connected() ? self::settings()['slug'] . '-r123-…@' . self::domain() : '',
            ],
            'alias' => [
                'address'    => (string) $s['alias'],
                'provider'   => in_array($s['provider'], self::PROVIDERS, true) ? $s['provider'] : '',
                'forward_to' => self::channel_address(),
                'status'     => $alias_status,
                'connected_at' => $s['alias_ok_at'] ? wp_date('c', $s['alias_ok_at']) : '',
                'last_mail_at' => $s['alias_last_at'] ? wp_date('c', $s['alias_last_at']) : '',
                'test_sent_at' => $s['test_sent_at'] ? wp_date('c', $s['test_sent_at']) : '',
            ],
            'takeover_address' => self::takeover_address(),
            'show_contact'     => (bool) $s['show_contact'],
            'verifications'    => array_map(function ($v) {
                return ['id' => $v['id'], 'from' => $v['from'], 'subject' => $v['subject'], 'code' => $v['code'], 'link' => $v['link'], 'excerpt' => $v['excerpt'], 'received_at' => wp_date('c', $v['at'])];
            }, array_slice(array_reverse($ver), 0, 5)),
            'imap'  => ['active' => $imap],
            'log'   => array_slice(self::log_entries(), 0, 15),
        ];
    }

    public static function rest_save(WP_REST_Request $req) {
        if (!self::connected()) return new WP_Error('tix_inbound_off', 'Der E-Mail-Eingang ist für diese Seite noch nicht freigeschaltet.', ['status' => 409]);
        $s = self::settings();
        $patch = [];
        if ($req->has_param('alias')) {
            $given = trim((string) $req->get_param('alias'));
            $a = strtolower(sanitize_email($given));
            if ($given !== '' && !is_email($a)) return new WP_Error('rest_invalid_param', 'Bitte eine gültige E-Mail-Adresse eingeben.', ['status' => 400, 'field' => 'alias']);
            if ($a !== '' && substr($a, -strlen('@' . self::domain())) === '@' . self::domain()) {
                return new WP_Error('rest_invalid_param', 'Bitte deine eigene Adresse eingeben, nicht die Zieladresse.', ['status' => 400, 'field' => 'alias']);
            }
            if ($a !== $s['alias']) {
                $patch += ['alias' => $a, 'alias_ok_at' => 0, 'alias_last_at' => 0, 'test_nonce' => '', 'test_sent_at' => 0, 'test_ok_at' => 0];
                if ($a === '') $patch['show_contact'] = 0;
            }
        }
        if ($req->has_param('provider')) {
            $p = sanitize_key((string) $req->get_param('provider'));
            $patch['provider'] = in_array($p, self::PROVIDERS, true) ? $p : '';
        }
        if ($req->has_param('show_contact')) $patch['show_contact'] = filter_var($req->get_param('show_contact'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        if ($patch) self::save($patch);
        return rest_ensure_response(self::state());
    }

    public static function rest_test() {
        if (!self::connected()) return new WP_Error('tix_inbound_off', 'Der E-Mail-Eingang ist für diese Seite noch nicht freigeschaltet.', ['status' => 409]);
        $s = self::settings();
        if (!is_email($s['alias'])) return new WP_Error('rest_invalid_param', 'Bitte zuerst deine Support-Adresse eingeben.', ['status' => 400, 'field' => 'alias']);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('sp_inbound_test', 6, 3600, 'site')) {
            return new WP_Error('tix_rate_limit', 'Zu viele Testmails. Bitte in einer Stunde erneut versuchen.', ['status' => 429]);
        }
        $nonce = bin2hex(random_bytes(5));
        $token = 'TIXTEST-' . $nonce . '-' . self::sig('t|' . $nonce, 8);
        $name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $html = '<p>Diese Testmail prüft, ob deine Weiterleitung funktioniert.</p>'
            . '<p>Kommt sie über <strong>' . esc_html($s['alias']) . '</strong> bei uns an, steht im Studio „Verbunden“. Du musst nichts weiter tun.</p>'
            . '<p style="color:#9ca3af;font-size:12px;">' . esc_html($token) . '</p>';
        // Ohne X-Tixomat-Support, sonst würde der Eingang die Mail als eigene Mail verwerfen
        $ok = wp_mail($s['alias'], 'Test der Weiterleitung – ' . $name . ' [' . $token . ']', $html, ['Content-Type: text/html; charset=UTF-8']);
        if (!$ok) return new WP_Error('tix_mail_failed', 'Die Testmail konnte nicht gesendet werden.', ['status' => 502]);
        self::save(['test_nonce' => $nonce, 'test_sent_at' => time(), 'test_ok_at' => 0]);
        self::log(['result' => 'test_sent', 'reason' => 'Testmail an ' . $s['alias']]);
        return rest_ensure_response(['sent' => true] + self::state());
    }

    public static function rest_verification_done(WP_REST_Request $req) {
        $s = self::settings();
        $v = (array) $s['verifications'];
        foreach ($v as &$x) if (($x['id'] ?? '') === (string) $req['id']) $x['done'] = 1;
        unset($x);
        self::save(['verifications' => $v]);
        return rest_ensure_response(self::state());
    }

    // ═══════════════ Zentraler Eingang (Hub) ═══════════════

    public static function hub_key() {
        return (string) get_option(self::OPT_HUB_KEY, '');
    }

    private static function hub_sites() {
        $s = get_option(self::OPT_SITES, []);
        return is_array($s) ? $s : [];
    }

    /** POST /support/inbound – Postfix (Rohmail, X-Tix-Rcpt) oder Brevo Inbound Parsing (JSON items[]). */
    public static function rest_hub(WP_REST_Request $req) {
        $key = self::hub_key();
        if ($key === '') return new WP_Error('rest_no_route', 'Kein Eingang auf dieser Seite.', ['status' => 404]);
        $given = (string) ($req->get_header('x-tix-inbound-key') ?: $req->get_param('key'));
        $auth = (string) $req->get_header('authorization');
        if ($given === '' && stripos($auth, 'bearer ') === 0) $given = trim(substr($auth, 7));
        if (!hash_equals($key, $given)) return new WP_Error('tix_inbound_auth', 'Nicht berechtigt.', ['status' => 403]);

        $body = (string) $req->get_body();
        if (strlen($body) > self::MAX_RAW + 1024 * 1024) return new WP_Error('tix_too_large', 'Mail zu groß.', ['status' => 413]);
        $jobs = [];
        $json = stripos((string) $req->get_header('content-type'), 'json') !== false ? json_decode($body, true) : null;
        if (is_array($json) && isset($json['items']) && is_array($json['items'])) {
            foreach ($json['items'] as $item) {
                if (!is_array($item)) continue;
                $raw = self::brevo_to_raw($item);
                foreach (self::brevo_recipients($item) as $r) $jobs[] = [$r, $raw];
            }
        } elseif (is_array($json) && isset($json['raw'])) {
            $raw = base64_decode((string) $json['raw'], true);
            foreach ((array) ($json['rcpt'] ?? []) as $r) $jobs[] = [(string) $r, (string) $raw];
        } else {
            $rcpts = array_filter(array_map('trim', explode(',', (string) $req->get_header('x-tix-rcpt'))));
            if (!$rcpts) $rcpts = self::header_recipients($body);
            foreach ($rcpts as $r) $jobs[] = [$r, $body];
        }

        $out = []; $retry = false;
        foreach ($jobs as [$rcpt, $raw]) {
            $r = self::route($rcpt, $raw);
            if (!empty($r['retry'])) $retry = true;
            $out[] = ['rcpt' => $rcpt] + $r;
        }
        if ($retry) return new WP_REST_Response(['ok' => false, 'results' => $out], 503); // Postfix stellt später erneut zu
        return rest_ensure_response(['ok' => true, 'results' => $out]);
    }

    /** Empfänger → Seite: eigene Seite direkt, sonst signiert weiterleiten */
    public static function route($rcpt, $raw) {
        $rcpt = strtolower(trim((string) $rcpt, " <>\t\r\n"));
        $at = strrpos($rcpt, '@');
        if ($at === false) return ['result' => 'unknown', 'reason' => 'Keine Adresse'];
        $slug = explode('-', substr($rcpt, 0, $at), 2)[0];
        if (!self::valid_slug($slug)) return ['result' => 'unknown', 'reason' => 'Unbekannte Adresse'];
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('sp_inbound_hub', 300, 3600, $slug)) {
            return ['result' => 'ratelimited', 'reason' => 'Zu viele Mails für ' . $slug];
        }
        $own = self::settings();
        if ($own['slug'] === $slug && self::connected()) return self::deliver_local($raw, $rcpt);
        $sites = self::hub_sites();
        if (!isset($sites[$slug]['url'], $sites[$slug]['secret'])) return ['result' => 'unknown', 'reason' => 'Unbekannte Seite ' . $slug];
        $ts = time();
        $res = wp_remote_post(untrailingslashit($sites[$slug]['url']) . '/wp-json/tixomat/v1/support/inbound/deliver', [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'message/rfc822', 'X-Tix-Rcpt' => $rcpt, 'X-Tix-Ts' => (string) $ts,
                'X-Tix-Sig' => self::sign_payload($sites[$slug]['secret'], $rcpt, $ts, $raw)],
            'body' => $raw,
        ]);
        if (is_wp_error($res)) return ['result' => 'error', 'reason' => $res->get_error_message(), 'retry' => true];
        $code = intval(wp_remote_retrieve_response_code($res));
        $j = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code >= 500 || $code === 429) return ['result' => 'error', 'reason' => 'Seite antwortet ' . $code, 'retry' => true];
        if ($code !== 200) return ['result' => 'error', 'reason' => 'Seite antwortet ' . $code . (is_array($j) && !empty($j['code']) ? ' (' . $j['code'] . ')' : '')];
        return ['result' => (string) ($j['result'] ?? 'ok'), 'reason' => (string) ($j['reason'] ?? ''), 'ticket' => intval($j['ticket'] ?? 0)];
    }

    public static function sign_payload($secret, $rcpt, $ts, $raw) {
        return hash_hmac('sha256', strtolower($rcpt) . '|' . intval($ts) . '|' . hash('sha256', (string) $raw), (string) $secret);
    }

    /** POST /support/inbound/deliver – vom Hub signiert */
    public static function rest_deliver(WP_REST_Request $req) {
        $s = self::settings();
        if (!self::connected()) return new WP_Error('tix_inbound_off', 'Eingang nicht eingerichtet.', ['status' => 404]);
        $rcpt = strtolower((string) $req->get_header('x-tix-rcpt'));
        $ts = intval($req->get_header('x-tix-ts'));
        $raw = (string) $req->get_body();
        if (abs(time() - $ts) > 600 || !hash_equals(self::sign_payload($s['secret'], $rcpt, $ts, $raw), (string) $req->get_header('x-tix-sig'))) {
            return new WP_Error('tix_inbound_auth', 'Nicht berechtigt.', ['status' => 403]);
        }
        return rest_ensure_response(self::deliver_local($raw, $rcpt));
    }

    // ═══════════════ Verarbeitung auf der Seite ═══════════════

    public static function deliver_local($raw, $rcpt) {
        $raw = (string) $raw;
        $addr = self::parse_local($rcpt);
        if (!$addr) return self::log(['result' => 'invalid_address', 'reason' => 'Adresse ungültig oder Signatur falsch', 'rcpt' => $rcpt]);
        if (strlen($raw) > self::MAX_RAW) {
            return self::log(['result' => 'skipped_invalid', 'reason' => 'Mail zu groß (' . size_format(strlen($raw)) . ')']);
        }
        $support = function_exists('tix_get_settings') && tix_get_settings('support_enabled');
        $mail = TIX_Support_Mime::parse($raw);
        $h = $mail['headers'];
        $subject = trim(TIX_Support_Mime::header_decoded($h, 'subject'));
        $from = TIX_Support_Mime::parse_address(TIX_Support_Mime::header_decoded($h, 'from'));
        $s = self::settings();

        // 1) Testmail der Weiterleitung (vor dem Schleifenschutz)
        if ($s['test_nonce'] !== '' && preg_match('/TIXTEST-([a-f0-9]{10})-([a-f0-9]{8})/', $subject . "\n" . $mail['text'] . "\n" . $mail['html'], $m)
            && $m[1] === $s['test_nonce'] && hash_equals(self::sig('t|' . $m[1], 8), $m[2])) {
            self::save(['test_ok_at' => time(), 'alias_ok_at' => time(), 'alias_last_at' => time(), 'test_nonce' => '']);
            return self::log(['result' => 'test_ok', 'reason' => 'Testmail angekommen – Weiterleitung verbunden', 'from' => $from['email'], 'subject' => $subject]);
        }

        // 2) Bestätigungsmail des Anbieters (Gmail, Microsoft …) zur Weiterleitung
        if ($addr['kind'] === 'in' && ($v = self::forwarding_verification($from['email'], $subject, $mail))) {
            $list = array_slice((array) $s['verifications'], -9);
            $list[] = $v;
            self::save(['verifications' => $list]);
            return self::log(['result' => 'verification', 'reason' => 'Bestätigung des Anbieters – im Studio unter E-Mail-Eingang', 'from' => $from['email'], 'subject' => $subject]);
        }

        if (!$support) return self::log(['result' => 'ignored', 'reason' => 'Support ist ausgeschaltet', 'from' => $from['email'], 'subject' => $subject]);

        if ($addr['kind'] === 'reply') {
            $r = TIX_Support_Mail::process_raw($raw, ['ticket' => $addr['ticket'], 'allow_new' => false, 'mode' => 'inbound']);
        } elseif ($addr['kind'] === 'in') {
            $r = TIX_Support_Mail::process_raw($raw, ['allow_new' => true, 'mode' => 'inbound']);
            if (in_array($r['result'], ['imported', 'duplicate'], true)) {
                self::save(['alias_last_at' => time()] + (self::settings()['alias_ok_at'] ? [] : ['alias_ok_at' => time()]));
            }
        } else {
            $r = self::takeover($raw, $mail, $from);
        }
        return self::log($r + ['kind' => $addr['kind']]);
    }

    /** Bestätigungsmail einer Weiterleitung erkennen (Code + Link fürs Studio) */
    public static function forwarding_verification($from_email, $subject, array $mail) {
        $from_email = strtolower((string) $from_email);
        $text = trim($mail['text'] !== '' ? $mail['text'] : TIX_Support_Mail::html_to_text($mail['html']));
        $known = preg_match('/^(forwarding-noreply@google\.com|.*@(accountprotection\.)?microsoft\.com|.*@outlook\.com|.*@ionos\.(de|com)|.*@strato\.(de|com)|.*@all-inkl\.com)$/', $from_email);
        $about = preg_match('/(weiterleitung|forwarding|forward)/iu', $subject . ' ' . mb_substr($text, 0, 600))
            && preg_match('/(bestätig|bestaetig|confirm|verif|code)/iu', $subject . ' ' . mb_substr($text, 0, 1500));
        if (!($known && $about) && !($about && preg_match('/^(no-?reply|forwarding-noreply|mailer)/', $from_email))) return null;
        $code = '';
        if (preg_match('/\(#(\d{6,12})\)/', $subject, $m)) $code = $m[1];
        elseif (preg_match('/(?:bestätigungscode|confirmation code|code|bestätigungs-code)\s*[:#]?\s*([A-Z0-9]{4,12})\b/iu', $text, $m)) $code = $m[1];
        $link = '';
        if (preg_match_all('#https://[^\s<>"\')]+#', $text . ' ' . $mail['html'], $mm)) {
            foreach ($mm[0] as $u) {
                if (preg_match('#(mail\.google\.com/mail/vf|mail-settings\.google\.com|/confirm|verify|bestaetig)#i', $u)) { $link = html_entity_decode($u); break; }
            }
        }
        return [
            'id' => bin2hex(random_bytes(6)), 'at' => time(), 'from' => $from_email, 'subject' => mb_substr($subject, 0, 200),
            'code' => $code, 'link' => esc_url_raw($link), 'excerpt' => mb_substr(preg_replace('/\s+/', ' ', $text), 0, 600),
        ];
    }

    /**
     * Einzel-Übernahme: weitergeleitete Mail → Anfrage mit dem ursprünglichen Absender.
     * Als Anhang weitergeleitet (message/rfc822): diese Mail. Sonst Kopfblock im Text
     * („---------- Forwarded message ---------“, „Von: … Gesendet: … Betreff: …“).
     */
    public static function takeover($raw, array $mail, array $by) {
        $inner = '';
        foreach ($mail['attachments'] as $a) {
            if (($a['mime'] ?? '') === 'message/rfc822' && $a['data'] !== '') { $inner = $a['data']; break; }
        }
        if ($inner === '') {
            $orig = self::parse_inline_forward($mail['text'] !== '' ? $mail['text'] : TIX_Support_Mail::html_to_text($mail['html']));
            if (!$orig) return ['result' => 'skipped_invalid', 'reason' => 'Keine weitergeleitete Mail erkannt', 'from' => $by['email']];
            $inner = self::build_raw($orig['from'], $orig['name'], $orig['subject'], $orig['body'], $orig['date'],
                array_filter($mail['attachments'], function ($a) { return ($a['mime'] ?? '') !== 'message/rfc822'; }));
        }
        $note = 'Übernommen aus einer weitergeleiteten Mail von ' . ($by['email'] ?: 'unbekannt') . '.';
        return TIX_Support_Mail::process_raw($inner, ['allow_new' => true, 'mode' => 'inbound', 'notes' => [$note], 'takeover' => true]);
    }

    /** Kopfblock einer inline weitergeleiteten Mail lesen → from, name, subject, date, body */
    public static function parse_inline_forward($text) {
        $text = str_replace("\r\n", "\n", (string) $text);
        $lines = explode("\n", $text);
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $l = trim(ltrim($lines[$i], '> '));
            if (!preg_match('/^(\*?)(von|from|de|absender)\s*:\s*\*?\s*(.+)$/iu', $l, $m)) continue;
            $a = TIX_Support_Mime::parse_address(trim($m[3], '* '));
            if (!is_email($a['email'])) {
                if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $m[3], $em)) $a = ['email' => $em[0], 'name' => trim(preg_replace('/[<\[(].*$/', '', $m[3]), ' "*')];
                else continue;
            }
            // Kopfblock: bis zur ersten Leerzeile nach Von/Betreff/Datum
            $subject = ''; $date = ''; $j = $i + 1;
            for (; $j < $n && $j < $i + 10; $j++) {
                $x = trim(ltrim($lines[$j], '> '), '* ');
                if ($x === '') break;
                if (preg_match('/^(betreff|subject|objet)\s*:\s*\*?\s*(.*)$/iu', $x, $mm)) $subject = trim($mm[2], '* ');
                elseif (preg_match('/^(datum|date|gesendet|sent)\s*:\s*\*?\s*(.*)$/iu', $x, $mm)) $date = trim($mm[2], '* ');
            }
            // auch Kopfzeilen VOR „Von:“ (Thunderbird: Betreff/Datum zuerst)
            for ($k = max(0, $i - 4); $k < $i; $k++) {
                $x = trim(ltrim($lines[$k], '> '), '* ');
                if ($subject === '' && preg_match('/^(betreff|subject)\s*:\s*(.*)$/iu', $x, $mm)) $subject = trim($mm[2]);
                if ($date === '' && preg_match('/^(datum|date)\s*:\s*(.*)$/iu', $x, $mm)) $date = trim($mm[2]);
            }
            $body = trim(implode("\n", array_map(function ($l) { return preg_replace('/^> ?/', '', $l); }, array_slice($lines, $j))));
            return ['from' => strtolower($a['email']), 'name' => (string) $a['name'], 'subject' => $subject, 'date' => $date, 'body' => $body];
        }
        return null;
    }

    /** Schlichte Rohmail (text/plain, optional mit Anhängen) für process_raw bauen */
    public static function build_raw($email, $name, $subject, $text, $date = '', array $atts = [], array $extra = []) {
        $enc = function ($s) { return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s; };
        $name = trim(preg_replace('/["\r\n]/', '', (string) $name));
        $ts = $date !== '' ? strtotime(preg_replace('/\s+um\s+/', ' ', $date)) : false;
        $h = ['From: ' . ($name !== '' ? '"' . $enc($name) . '" ' : '') . '<' . $email . '>',
              'Subject: ' . $enc(preg_replace('/[\r\n]+/', ' ', (string) $subject)),
              'Date: ' . gmdate('D, d M Y H:i:s', $ts ?: time()) . ' +0000',
              'MIME-Version: 1.0'];
        foreach ($extra as $line) $h[] = $line;
        $atts = array_values($atts);
        if (!$atts) {
            return implode("\r\n", $h) . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode((string) $text));
        }
        $b = 'tixb' . bin2hex(random_bytes(8));
        $out = implode("\r\n", $h) . "\r\nContent-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode((string) $text));
        foreach ($atts as $a) {
            $fn = str_replace(['"', "\r", "\n"], '', (string) ($a['name'] ?? 'anhang'));
            $out .= "--$b\r\nContent-Type: " . ($a['mime'] ?? 'application/octet-stream') . "; name=\"$fn\"\r\nContent-Disposition: attachment; filename=\"$fn\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode((string) ($a['data'] ?? '')));
        }
        return $out . "--$b--\r\n";
    }

    // ── Brevo Inbound Parsing (Alternative zu Postfix) ──

    private static function brevo_recipients(array $item) {
        $r = [];
        foreach ((array) ($item['Recipients'] ?? []) as $x) $r[] = is_array($x) ? (string) ($x['Address'] ?? '') : (string) $x;
        if (!$r) foreach (['To', 'Cc'] as $k) foreach ((array) ($item[$k] ?? []) as $x) $r[] = is_array($x) ? (string) ($x['Address'] ?? '') : (string) $x;
        $dom = '@' . self::DOMAIN;
        return array_values(array_unique(array_filter(array_map('strtolower', $r), function ($a) use ($dom) { return substr($a, -strlen($dom)) === $dom; })));
    }

    /** Brevo-JSON → Rohmail (Kopfzeilen, Text/HTML, Anhänge über die Brevo-API) */
    public static function brevo_to_raw(array $item) {
        $from = (array) ($item['From'] ?? []);
        $extra = [];
        if (!empty($item['MessageId'])) $extra[] = 'Message-ID: <' . trim((string) $item['MessageId'], '<> ') . '>';
        if (!empty($item['InReplyTo'])) $extra[] = 'In-Reply-To: <' . trim((string) $item['InReplyTo'], '<> ') . '>';
        foreach ((array) ($item['Headers'] ?? []) as $k => $v) {
            $k = strtolower((string) $k);
            if (in_array($k, ['references', 'auto-submitted', 'precedence', 'list-id', 'list-unsubscribe', 'x-autoreply', 'x-autorespond', 'return-path', 'x-tixomat-support'], true)) {
                $extra[] = $k . ': ' . preg_replace('/[\r\n]+/', ' ', is_array($v) ? implode(' ', $v) : (string) $v);
            }
        }
        if (isset($item['SpamScore']) && floatval($item['SpamScore']) >= 5) $extra[] = 'X-Spam-Flag: YES';
        $text = (string) ($item['RawTextBody'] ?? '');
        if ($text === '' && !empty($item['RawHtmlBody'])) $text = TIX_Support_Mail::html_to_text((string) $item['RawHtmlBody']);
        $atts = [];
        $key = (string) get_option(self::OPT_BREVO, '');
        foreach ((array) ($item['Attachments'] ?? []) as $a) {
            if (!is_array($a) || empty($a['DownloadToken']) || $key === '') continue;
            $res = wp_remote_get('https://api.brevo.com/v3/inbound/attachments/' . rawurlencode((string) $a['DownloadToken']), ['timeout' => 20, 'headers' => ['api-key' => $key]]);
            if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) continue;
            $atts[] = ['name' => (string) ($a['Name'] ?? 'anhang'), 'mime' => (string) ($a['ContentType'] ?? 'application/octet-stream'), 'data' => (string) wp_remote_retrieve_body($res)];
        }
        $date = (string) ($item['SentAtDate'] ?? '');
        return self::build_raw((string) ($from['Address'] ?? ''), (string) ($from['Name'] ?? ''), (string) ($item['Subject'] ?? ''), $text, $date, $atts, $extra);
    }

    private static function header_recipients($raw) {
        $h = TIX_Support_Mime::parse_headers_only($raw);
        $out = [];
        foreach (['x-original-to', 'delivered-to', 'to', 'cc'] as $k) {
            if (preg_match_all('/[A-Z0-9._%+\-]+@' . preg_quote(self::DOMAIN, '/') . '/i', TIX_Support_Mime::header($h, $k), $m)) $out = array_merge($out, $m[0]);
            if ($out) break;
        }
        return array_values(array_unique(array_map('strtolower', $out)));
    }

    // ═══════════════ Protokoll ═══════════════

    private static function log(array $e) {
        $e = array_merge(['at' => time()], $e);
        unset($e['retry']);
        $list = get_option(self::LOG, []);
        $list = is_array($list) ? $list : [];
        array_unshift($list, array_intersect_key($e, array_flip(['at', 'result', 'reason', 'from', 'subject', 'ticket', 'kind', 'rcpt'])));
        update_option(self::LOG, array_slice($list, 0, 40), false);
        return $e;
    }

    public static function log_entries() {
        $list = get_option(self::LOG, []);
        return array_map(function ($e) {
            return ['at' => wp_date('c', intval($e['at'] ?? 0)), 'result' => (string) ($e['result'] ?? ''), 'reason' => (string) ($e['reason'] ?? ''),
                'from' => (string) ($e['from'] ?? ''), 'subject' => mb_substr((string) ($e['subject'] ?? ''), 0, 120), 'ticket' => intval($e['ticket'] ?? 0), 'kind' => (string) ($e['kind'] ?? '')];
        }, is_array($list) ? $list : []);
    }

    // ═══════════════ WP-CLI ═══════════════

    /**
     *   wp tixomat support-inbound status
     *   wp tixomat support-inbound hub-key [neu]             (Hub: Schlüssel für Postfix/Brevo anzeigen bzw. neu)
     *   wp tixomat support-inbound site-add <slug> <url>     (Hub: Seite anmelden, Schlüssel wird ausgegeben)
     *   wp tixomat support-inbound connect <slug> <secret> [hub-url]   (Seite: am Hub anmelden)
     *   wp tixomat support-inbound brevo-key <schlüssel>     (Hub, nur Brevo-Weg)
     *   wp tixomat support-inbound simulate <empfänger> <datei.eml>    (Mail wie vom Hub einspielen)
     */
    public static function cli($args) {
        $do = $args[0] ?? 'status';
        if ($do === 'hub-key') {
            if (($args[1] ?? '') === 'neu' || self::hub_key() === '') update_option(self::OPT_HUB_KEY, bin2hex(random_bytes(24)), false);
            WP_CLI::line(self::hub_key());
            return;
        }
        if ($do === 'site-add' && !empty($args[2])) {
            $slug = strtolower((string) $args[1]);
            if (!self::valid_slug($slug)) WP_CLI::error('Slug: 2–20 Zeichen a–z, 0–9, ohne Bindestrich.');
            $sites = self::hub_sites();
            $sites[$slug] = ['url' => esc_url_raw((string) $args[2]), 'secret' => bin2hex(random_bytes(24))];
            update_option(self::OPT_SITES, $sites, false);
            WP_CLI::line($sites[$slug]['secret']);
            return;
        }
        if ($do === 'connect' && !empty($args[2])) {
            $slug = strtolower((string) $args[1]);
            if (!self::valid_slug($slug)) WP_CLI::error('Ungültiger Slug.');
            self::save(['slug' => $slug, 'secret' => (string) $args[2]] + (!empty($args[3]) ? ['hub' => esc_url_raw((string) $args[3])] : []));
            WP_CLI::success('Verbunden. Antwort-Adressen: ' . $slug . '-r<id>-…@' . self::domain() . ' · Eingang: ' . self::channel_address());
            return;
        }
        if ($do === 'brevo-key' && !empty($args[1])) { update_option(self::OPT_BREVO, (string) $args[1], false); WP_CLI::success('Gespeichert.'); return; }
        if ($do === 'simulate' && !empty($args[2])) {
            $raw = file_get_contents((string) $args[2]);
            if ($raw === false) WP_CLI::error('Datei nicht lesbar.');
            WP_CLI::line(wp_json_encode(self::route((string) $args[1], $raw), JSON_UNESCAPED_UNICODE));
            return;
        }
        $s = self::settings();
        WP_CLI::line(sprintf('Seite: %s · Eingang: %s · Übernahme: %s · Alias: %s (%s) · Hub: %s · Seiten am Hub: %s',
            self::connected() ? $s['slug'] : 'nicht verbunden', self::channel_address() ?: '–', self::takeover_address() ?: '–',
            $s['alias'] ?: '–', self::state()['alias']['status'], self::hub_key() !== '' ? 'ja' : 'nein', implode(', ', array_keys(self::hub_sites())) ?: '–'));
    }
}
