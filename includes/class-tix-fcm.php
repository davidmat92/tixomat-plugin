<?php
/**
 * TIX_FCM – Android-Push über Firebase Cloud Messaging (HTTP v1), 1.38.527.
 *
 * - Zugang: Firebase-Dienstkonto-JSON in der Option `_tix_fcm_service_account` (trägt der User selbst ein: Tixomat → App-Push
 *   oder `wp tixomat push fcm-import <datei>`; wird nie ausgegeben). Projekt-ID aus dem JSON.
 * - OAuth-Zugriffstoken per JWT (RS256, Scope firebase.messaging) bei oauth2.googleapis.com, gecacht bis kurz vor Ablauf.
 * - Nachricht: notification {title, body} + data (nur Zeichenketten, wie bei APNs) + android.priority high,
 *   android.notification.channel_id „tixomat_default“ (legt die App an).
 * - Token, die FCM ablehnt (404/NOT_FOUND, UNREGISTERED, INVALID_ARGUMENT), meldet send() zurück; TIX_Notifications entfernt sie.
 * - Google-Aufrufe über IPv4 (der Server bevorzugt IPv6), wie bei TIX_Places.
 *
 * @package Tixomat
 */

if (!defined('ABSPATH')) exit;

class TIX_FCM {

    const OPT_SA     = '_tix_fcm_service_account';
    const OPT_STATS  = '_tix_fcm_stats';
    const SCOPE      = 'https://www.googleapis.com/auth/firebase.messaging';
    const TOKEN_URI  = 'https://oauth2.googleapis.com/token';
    const API_HOST   = 'fcm.googleapis.com';
    const AUTH_HOST  = 'oauth2.googleapis.com';
    const CHANNEL    = 'tixomat_default';

    public static function init() {
        add_action('http_api_curl', [__CLASS__, 'force_ipv4'], 10, 3);
        add_action('admin_menu', [__CLASS__, 'menu'], 33);
        add_action('admin_post_tix_fcm_save', [__CLASS__, 'handle_save']);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat push', [__CLASS__, 'cli']);
    }

    /** Nur für die Google-Hosts von FCM: IPv4 erzwingen. */
    public static function force_ipv4($handle, $args, $url) {
        $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
        if (in_array($host, [self::API_HOST, self::AUTH_HOST], true) && defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }
    }

    // ═══════════════ Zugang ═══════════════

    /** Dienstkonto prüfen: JSON-Text oder Array → [project_id, client_email, private_key, private_key_id] oder WP_Error. */
    public static function parse_account($raw) {
        $a = is_array($raw) ? $raw : json_decode(trim((string) $raw), true);
        if (!is_array($a)) return new WP_Error('tix_fcm_json', 'Kein gültiges JSON. Bitte die Dienstkonto-Datei aus Firebase (Projekteinstellungen → Dienstkonten → Neuen privaten Schlüssel generieren) vollständig einfügen.');
        if (($a['type'] ?? 'service_account') !== 'service_account') return new WP_Error('tix_fcm_type', 'Das ist kein Dienstkonto-Schlüssel (type muss service_account sein).');
        foreach (['project_id', 'client_email', 'private_key'] as $k) {
            if (trim((string) ($a[$k] ?? '')) === '') return new WP_Error('tix_fcm_field', 'Im JSON fehlt ' . $k . '.');
        }
        if (!preg_match('/^[a-z0-9-]{4,40}$/', (string) $a['project_id'])) return new WP_Error('tix_fcm_project', 'project_id ungültig.');
        if (!function_exists('openssl_pkey_get_private') || !openssl_pkey_get_private((string) $a['private_key'])) return new WP_Error('tix_fcm_key', 'Der private Schlüssel im JSON lässt sich nicht lesen.');
        return ['project_id' => (string) $a['project_id'], 'client_email' => (string) $a['client_email'], 'private_key' => (string) $a['private_key'],
            'private_key_id' => (string) ($a['private_key_id'] ?? '')];
    }

    /** Gespeichertes Dienstkonto oder null. */
    public static function account() {
        static $memo = [];
        $raw = get_option(self::OPT_SA, '');
        if ($raw === '' || $raw === []) return null;
        $k = md5(is_array($raw) ? wp_json_encode($raw) : (string) $raw);
        if (!array_key_exists($k, $memo)) {
            $a = self::parse_account($raw);
            $memo[$k] = is_wp_error($a) ? null : $a;
        }
        return $memo[$k];
    }

    public static function configured() {
        return self::account() !== null;
    }

    /** Dienstkonto speichern (nach Prüfung). → true | WP_Error */
    public static function save_account($raw) {
        $a = self::parse_account($raw);
        if (is_wp_error($a)) return $a;
        update_option(self::OPT_SA, wp_json_encode(is_array($raw) ? $raw : json_decode(trim((string) $raw), true)), false);
        self::forget_token();
        self::stat(['saved_at' => time(), 'last_error' => null]);
        return true;
    }

    public static function remove_account() {
        self::forget_token();
        delete_option(self::OPT_SA);
        delete_option(self::OPT_STATS);
    }

    private static function token_key() {
        $a = self::account();
        return '_tix_fcm_at_' . substr(md5(($a['client_email'] ?? '') . '|' . ($a['private_key_id'] ?? '') . '|' . ($a['project_id'] ?? '')), 0, 12);
    }

    private static function forget_token() {
        delete_transient(self::token_key());
    }

    private static function b64url($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** OAuth-Zugriffstoken (gecacht bis 5 min vor Ablauf) oder ''. */
    public static function access_token($force = false) {
        $a = self::account();
        if (!$a) return '';
        $key = self::token_key();
        if (!$force) {
            $c = get_transient($key);
            if (is_string($c) && $c !== '') return $c;
        }
        $now = time();
        $head = ['alg' => 'RS256', 'typ' => 'JWT'];
        if ($a['private_key_id'] !== '') $head['kid'] = $a['private_key_id'];
        $input = self::b64url(wp_json_encode($head)) . '.' . self::b64url(wp_json_encode([
            'iss' => $a['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URI, 'iat' => $now, 'exp' => $now + 3600,
        ]));
        $pkey = openssl_pkey_get_private($a['private_key']);
        $sig = '';
        if (!$pkey || !openssl_sign($input, $sig, $pkey, OPENSSL_ALGO_SHA256)) { self::error('jwt', 'JWT konnte nicht signiert werden.'); return ''; }
        $res = wp_remote_post(self::TOKEN_URI, ['timeout' => 10, 'body' => [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $input . '.' . self::b64url($sig),
        ]]);
        if (is_wp_error($res)) { self::error('oauth', $res->get_error_message()); return ''; }
        $code = intval(wp_remote_retrieve_response_code($res));
        $j = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code !== 200 || empty($j['access_token'])) {
            self::error('oauth', 'Google lehnt das Dienstkonto ab (HTTP ' . $code . (is_array($j) && !empty($j['error']) ? ', ' . sanitize_text_field((string) $j['error']) : '') . ').');
            return '';
        }
        $ttl = max(60, intval($j['expires_in'] ?? 3600) - 300);
        set_transient($key, (string) $j['access_token'], $ttl);
        return (string) $j['access_token'];
    }

    // ═══════════════ Versand ═══════════════

    /** data-Werte als Zeichenketten (FCM verlangt string → string). */
    private static function data_map(array $data) {
        $out = [];
        foreach ($data as $k => $v) {
            $k = (string) $k;
            if ($k === '' || preg_match('/^(google|gcm)\b|^(from|notification|message_type|collapse_key)$/i', $k)) continue;
            $out[$k] = is_scalar($v) || $v === null ? (string) $v : (string) wp_json_encode($v);
        }
        return $out;
    }

    /** Eine Nachricht für ein Gerät (JSON für messages:send). */
    public static function message($token, $title, $body, array $data = []) {
        $m = [
            'token'        => (string) $token,
            'notification' => ['title' => (string) $title, 'body' => (string) $body],
            'android'      => ['priority' => 'high', 'notification' => ['channel_id' => self::CHANNEL, 'sound' => 'default']],
        ];
        $d = self::data_map($data);
        if ($d) $m['data'] = $d;
        return ['message' => $m];
    }

    /** Soll das Token nach dieser Antwort verworfen werden? */
    public static function is_invalid($code, $json) {
        if (intval($code) === 404) return true;
        $e = is_array($json) ? (array) ($json['error'] ?? []) : [];
        $status = (string) ($e['status'] ?? '');
        if (in_array($status, ['NOT_FOUND', 'INVALID_ARGUMENT', 'UNREGISTERED'], true)) return true;
        foreach ((array) ($e['details'] ?? []) as $d) {
            if (in_array((string) ($d['errorCode'] ?? ''), ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) return true;
        }
        return false;
    }

    /**
     * An Android-Geräte senden. → ['sent' => n, 'invalid' => [token]]
     * Ohne Dienstkonto oder ohne Zugriffstoken passiert nichts (der In-App-Feed genügt dann).
     */
    public static function send(array $tokens, $title, $body, array $data = []) {
        $out = ['sent' => 0, 'invalid' => []];
        $tokens = array_values(array_unique(array_filter(array_map('strval', $tokens))));
        $a = self::account();
        if (!$tokens || !$a) return $out;
        $at = self::access_token();
        if ($at === '') return $out;
        $url = 'https://' . self::API_HOST . '/v1/projects/' . rawurlencode($a['project_id']) . '/messages:send';
        $retried = false;
        $failed = 0;
        foreach ($tokens as $token) {
            $payload = wp_json_encode(self::message($token, $title, $body, $data));
            $res = wp_remote_post($url, ['timeout' => 8, 'headers' => ['Authorization' => 'Bearer ' . $at, 'Content-Type' => 'application/json; charset=UTF-8'], 'body' => $payload]);
            $code = is_wp_error($res) ? 0 : intval(wp_remote_retrieve_response_code($res));
            // abgelaufenes Zugriffstoken: einmal neu holen und wiederholen
            if ($code === 401 && !$retried) {
                $retried = true;
                $at = self::access_token(true);
                if ($at === '') break;
                $res = wp_remote_post($url, ['timeout' => 8, 'headers' => ['Authorization' => 'Bearer ' . $at, 'Content-Type' => 'application/json; charset=UTF-8'], 'body' => $payload]);
                $code = is_wp_error($res) ? 0 : intval(wp_remote_retrieve_response_code($res));
            }
            if ($code === 200) { $out['sent']++; continue; }
            $j = is_wp_error($res) ? null : json_decode((string) wp_remote_retrieve_body($res), true);
            if (self::is_invalid($code, $j)) { $out['invalid'][] = $token; continue; }
            $failed++;
            if ($failed === 1) self::error('send', is_wp_error($res) ? $res->get_error_message() : 'HTTP ' . $code . (is_array($j) && !empty($j['error']['status']) ? ' ' . sanitize_text_field((string) $j['error']['status']) : ''));
            if (in_array($code, [401, 403], true)) break; // Zugang falsch: nicht alle Geräte durchprobieren
        }
        if ($out['sent']) self::stat(['last_sent_at' => time(), 'sent_total' => intval(self::stats()['sent_total'] ?? 0) + $out['sent']] + ($failed ? [] : ['last_error' => null]));
        return $out;
    }

    // ═══════════════ Status ═══════════════

    private static function stats() {
        $s = get_option(self::OPT_STATS, []);
        return is_array($s) ? $s : [];
    }

    private static function stat(array $set) {
        update_option(self::OPT_STATS, array_merge(self::stats(), $set), false);
    }

    private static function error($where, $msg) {
        self::stat(['last_error' => ['where' => $where, 'message' => mb_substr((string) $msg, 0, 200), 'at' => time()]]);
        error_log('TIX FCM (' . $where . '): ' . $msg);
    }

    /** Status ohne Geheimnisse: {configured, project_id, client_email, saved_at, last_sent_at, sent_total, last_error} */
    public static function status() {
        $a = self::account();
        $s = self::stats();
        return ['configured' => (bool) $a, 'project_id' => $a['project_id'] ?? null, 'client_email' => $a['client_email'] ?? null,
            'saved_at' => $s['saved_at'] ?? null, 'last_sent_at' => $s['last_sent_at'] ?? null, 'sent_total' => intval($s['sent_total'] ?? 0),
            'last_error' => $s['last_error'] ?? null];
    }

    // ═══════════════ Verwaltung ═══════════════

    public static function menu() {
        add_submenu_page('tixomat', 'App-Push', 'App-Push', 'manage_options', 'tix-app-push', [__CLASS__, 'render']);
    }

    public static function handle_save() {
        if (!current_user_can('manage_options') || !check_admin_referer('tix_fcm_save')) wp_die('Keine Berechtigung.');
        $back = admin_url('admin.php?page=tix-app-push');
        if (!empty($_POST['remove'])) {
            self::remove_account();
            wp_safe_redirect(add_query_arg('tix_fcm', 'removed', $back));
            exit;
        }
        $raw = (string) wp_unslash($_POST['service_account'] ?? '');
        if (!empty($_FILES['service_account_file']['tmp_name']) && is_uploaded_file($_FILES['service_account_file']['tmp_name'])) {
            $raw = (string) file_get_contents($_FILES['service_account_file']['tmp_name']);
        }
        $r = self::save_account($raw);
        wp_safe_redirect(add_query_arg(is_wp_error($r) ? ['tix_fcm' => 'error', 'msg' => rawurlencode($r->get_error_message())] : ['tix_fcm' => 'saved'], $back));
        exit;
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        $st = self::status();
        $msg = sanitize_key((string) ($_GET['tix_fcm'] ?? ''));
        $apns = class_exists('TIX_Notifications') && TIX_Notifications::apns_configured();
        $count = class_exists('TIX_Notifications') ? TIX_Notifications::device_counts() : ['ios' => 0, 'android' => 0];
        ?>
        <div class="wrap">
            <h1>App-Push</h1>
            <?php if ($msg === 'saved'): ?><div class="notice notice-success is-dismissible"><p>Dienstkonto gespeichert.</p></div><?php endif; ?>
            <?php if ($msg === 'removed'): ?><div class="notice notice-success is-dismissible"><p>Dienstkonto entfernt.</p></div><?php endif; ?>
            <?php if ($msg === 'error'): ?><div class="notice notice-error"><p><?php echo esc_html(rawurldecode((string) ($_GET['msg'] ?? 'Fehler.'))); ?></p></div><?php endif; ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 20px;max-width:820px;margin:12px 0;">
                <h2 style="margin-top:0;">Status</h2>
                <p>iPhone (APNs): <strong><?php echo $apns ? 'eingerichtet' : 'nicht eingerichtet'; ?></strong> · <?php echo intval($count['ios']); ?> Geräte<br>
                Android (Firebase): <strong><?php echo $st['configured'] ? 'eingerichtet' : 'nicht eingerichtet'; ?></strong> · <?php echo intval($count['android']); ?> Geräte
                <?php if ($st['configured']): ?><br><span style="color:#64748b;">Projekt <?php echo esc_html($st['project_id']); ?> · <?php echo esc_html($st['client_email']); ?><?php if ($st['last_sent_at']): ?> · zuletzt gesendet <?php echo esc_html(wp_date('d.m.Y H:i', intval($st['last_sent_at']))); ?><?php endif; ?></span><?php endif; ?></p>
                <?php if (!empty($st['last_error'])): ?><p style="color:#b91c1c;">Letzter Fehler (<?php echo esc_html(wp_date('d.m.Y H:i', intval($st['last_error']['at']))); ?>): <?php echo esc_html($st['last_error']['message']); ?></p><?php endif; ?>
            </div>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 20px;max-width:820px;margin:12px 0;">
                <h2 style="margin-top:0;">Firebase-Dienstkonto (Android)</h2>
                <p>Firebase-Konsole → Projekteinstellungen → Dienstkonten → „Neuen privaten Schlüssel generieren“. Die JSON-Datei hier hochladen oder ihren Inhalt einfügen. Der Schlüssel wird nicht wieder angezeigt.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="tix_fcm_save"><?php wp_nonce_field('tix_fcm_save'); ?>
                    <p><input type="file" name="service_account_file" accept="application/json,.json"></p>
                    <p><textarea name="service_account" rows="5" style="width:100%;font-family:monospace;" placeholder="{ &quot;type&quot;: &quot;service_account&quot;, … }" autocomplete="off"></textarea></p>
                    <?php submit_button($st['configured'] ? 'Ersetzen' : 'Speichern', 'primary', 'submit', false); ?>
                    <?php if ($st['configured']): ?> <button class="button" name="remove" value="1" onclick="return confirm('Dienstkonto entfernen? Android-Push stoppt.');">Entfernen</button><?php endif; ?>
                </form>
            </div>
            <?php if (class_exists('TIX_App_Links')) TIX_App_Links::render_admin_section(); ?>
        </div>
        <?php
    }

    /**
     * wp tixomat push status | fcm-import <datei> | fcm-remove | test --user=<id> [--title=] [--body=]
     */
    public static function cli($args, $assoc) {
        $cmd = (string) ($args[0] ?? 'status');
        if ($cmd === 'status') {
            $out = self::status() + ['apns_configured' => class_exists('TIX_Notifications') && TIX_Notifications::apns_configured(),
                'devices' => class_exists('TIX_Notifications') ? TIX_Notifications::device_counts() : null];
            WP_CLI::line(wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return;
        }
        if ($cmd === 'fcm-import') {
            $file = (string) ($args[1] ?? '');
            if ($file === '' || !is_readable($file)) WP_CLI::error('Aufruf: wp tixomat push fcm-import <dienstkonto.json>');
            $r = self::save_account((string) file_get_contents($file));
            if (is_wp_error($r)) WP_CLI::error($r->get_error_message());
            $t = self::access_token(true);
            WP_CLI::success('Dienstkonto gespeichert (Projekt ' . self::account()['project_id'] . '). Zugriffstoken: ' . ($t !== '' ? 'ok' : 'FEHLER – ' . (self::stats()['last_error']['message'] ?? '')));
            return;
        }
        if ($cmd === 'fcm-remove') { self::remove_account(); WP_CLI::success('Dienstkonto entfernt.'); return; }
        if ($cmd === 'test') {
            $uid = intval($assoc['user'] ?? 0);
            if (!$uid || !class_exists('TIX_Notifications')) WP_CLI::error('Aufruf: wp tixomat push test --user=<id> [--title=…] [--body=…]');
            $n = TIX_Notifications::push_to_user($uid, (string) ($assoc['title'] ?? 'Test'), (string) ($assoc['body'] ?? 'Testnachricht von Tixomat'), ['action' => '']);
            WP_CLI::success('Gesendet an ' . $n . ' Gerät(e).');
            return;
        }
        WP_CLI::error('Unbekannt. status | fcm-import <datei> | fcm-remove | test --user=<id>');
    }
}
