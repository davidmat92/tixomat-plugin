<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Wallet_Pay – Guthaben Phase 2: Bezahlen per QR (1.38.433, alle Seiten; Auftrag uebergaben/auftrag-guthaben-phase2.md).
 *
 * Der Gast zeigt einen offline signierten Code, das Personal scannt. Gebucht wird ausschließlich über
 * TIX_Wallet_Balance::post_tx() (Phase 1).
 *
 * Geräte ({prefix}tix_wallet_devices): öffentlicher Schlüssel P-256 (SPKI PEM) aus dem Secure Enclave.
 *   Erstes Gerät eines Kontos sofort aktiv, weitere `pending` bis zur Bestätigung per E-Mail-Code (6 Ziffern,
 *   15 min, 5 Versuche), höchstens 2 aktive Geräte. Entfernte Geräte bleiben als `removed` gesperrt.
 *
 * Code: TXP1.<wallet_id>.<device_id>.<ts>.<nonce>.<sig>
 *   sig = ECDSA P-256 / SHA-256 über "TXP1.<wallet_id>.<device_id>.<ts>.<nonce>" (DER, base64url),
 *   gültig 60 s ab ts, ±30 s Uhrabweichung; jede Nonce nur einmal ({prefix}tix_wallet_nonces, Primärschlüssel
 *   device_id + nonce). Die Zeile dient zugleich als Zahlungsvorgang (Status approval|paid|declined|expired|failed
 *   für /wallet/pay-status und die Freigabe). Aufräumen stündlich nach 24 h.
 */
class TIX_Wallet_Pay {

    const NS            = 'tixomat/v1';
    const CRON          = 'tix_wallet_pay_cleanup';
    const VALID         = 60;    // s
    const SKEW          = 30;    // s
    const APPROVAL_TTL  = 120;   // s bis eine offene Freigabe verfällt
    const REVERSE_STAFF = 600;   // s Storno durch Personal
    const MAX_DEVICES   = 2;
    const POLL_MAX      = 25;    // s Long-Polling

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action(self::CRON, [__CLASS__, 'cleanup']);
        add_action('init', function () {
            if (!wp_next_scheduled(TIX_Wallet_Pay::CRON)) wp_schedule_event(time() + 900, 'hourly', TIX_Wallet_Pay::CRON);
        }, 26);
    }

    private static function B() { return 'TIX_Wallet_Balance'; }

    private static function out($data, $status = 200) {
        $r = new WP_REST_Response($data, $status);
        $r->header('Cache-Control', 'no-store');
        $r->header('X-LiteSpeed-Cache-Control', 'no-cache');
        return $r;
    }

    private static function err($code, $msg, $status, array $extra = []) {
        return new WP_Error($code, $msg, ['status' => $status] + $extra);
    }

    /** Ist Guthaben irgendwo aktiv? (Seite bzw. ein Veranstalter mit Modul) */
    public static function any_enabled() {
        if (!TIX_Wallet_Balance::ready()) return false;
        if (!class_exists('TIX_App_Scope') || !TIX_App_Scope::multi()) return TIX_Wallet_Balance::scope_enabled(0);
        $ids = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids', 'meta_key' => '_tix_org_modules', 'meta_value' => '"wallet":true', 'meta_compare' => 'LIKE']);
        foreach ($ids as $oid) if (TIX_Wallet_Balance::scope_enabled($oid)) return true;
        return false;
    }

    private static function off() {
        return self::any_enabled() ? null : self::err('tix_wallet_off', 'Guthaben ist hier nicht aktiv.', 404);
    }

    /** Ratenbegrenzung (flüchtig, Transient). Grenzen per Filter `tix_wallet_rate_limits`. */
    private static function limited($bucket, $key) {
        $lim = apply_filters('tix_wallet_rate_limits', ['staff' => 60, 'device' => 60, 'wallet' => 60, 'guest' => 120]);
        $max = intval($lim[$bucket] ?? 60);
        $k = 'tix_wpay_rl_' . $bucket . '_' . md5((string) $key);
        $d = get_transient($k);
        if (!is_array($d) || time() - intval($d['t'] ?? 0) >= 60) $d = ['n' => 0, 't' => time()];
        $d['n']++;
        set_transient($k, $d, 60);
        return $d['n'] > $max;
    }

    private static function rate_error() {
        return self::err('tix_wallet_rate_limited', 'Zu viele Versuche – bitte kurz warten.', 429);
    }

    // ═══════════════ Geräte ═══════════════

    private static function T($n) { return TIX_Wallet_Balance::t($n); }

    private static function device($id) {
        global $wpdb;
        $d = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::T('wallet_devices') . " WHERE id = %d", intval($id)), ARRAY_A);
        return $d ?: null;
    }

    private static function active_count($uid) {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::T('wallet_devices') . " WHERE user_id = %d AND status = 'active'", intval($uid))));
    }

    /** P-256-Schlüssel prüfen; liefert [pem, key_id] oder WP_Error. */
    public static function parse_key($pem) {
        $pem = trim((string) $pem);
        if ($pem !== '' && strpos($pem, '-----BEGIN') === false) {
            $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $pem), 64, "\n") . "-----END PUBLIC KEY-----\n";
        }
        $k = $pem !== '' ? @openssl_pkey_get_public($pem) : false;
        $det = $k ? openssl_pkey_get_details($k) : null;
        if (!$det || intval($det['type'] ?? -1) !== OPENSSL_KEYTYPE_EC || ($det['ec']['curve_name'] ?? '') !== 'prime256v1') {
            return self::err('tix_wallet_key_invalid', 'Der Geräte-Schlüssel ist ungültig (P-256 erwartet).', 400);
        }
        $norm = (string) $det['key'];
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $norm));
        return [$norm, hash('sha256', $der)];
    }

    private static function device_payload(array $d) {
        return [
            'id' => intval($d['id']), 'name' => (string) $d['name'], 'platform' => (string) $d['platform'], 'status' => (string) $d['status'],
            'created_at' => mysql2date('c', get_date_from_gmt($d['created']), false),
            'last_used_at' => $d['last_used'] ? mysql2date('c', get_date_from_gmt($d['last_used']), false) : null,
        ];
    }

    private static function code_hash($code) {
        return hash_hmac('sha256', (string) $code, wp_salt('auth'));
    }

    private static function send_device_code(array $d, $code) {
        $u = get_userdata(intval($d['user_id']));
        if (!$u || !is_email($u->user_email)) return false;
        $site = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $m = ['title' => 'Neues Gerät bestätigen', 'subtitle' => $site . ' App', 'kind' => 'account', 'blocks' => [
            ['type' => 'text', 'html' => 'Hallo,<br>ein neues Gerät möchte mit deinem Guthaben bezahlen' . ($d['name'] !== '' ? ' („' . esc_html($d['name']) . '“)' : '') . '. Mit diesem Code bestätigst du es in der App:'],
            ['type' => 'code', 'value' => (string) $code, 'hint' => 'Gültig für 15 Minuten'],
            ['type' => 'notice', 'tone' => 'info', 'html' => 'Warst du das nicht? Dann ignoriere diese E-Mail – ohne Code kann das Gerät nicht bezahlen.'],
        ]];
        $html = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::render($m) : '<html><body><p>' . esc_html($code) . '</p></body></html>';
        $subject = 'Neues Gerät für dein Guthaben – ' . $site;
        return class_exists('TIX_Customer_URL') ? TIX_Customer_URL::mail($u->user_email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']) : wp_mail($u->user_email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    private static function issue_code($device_id) {
        global $wpdb;
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $wpdb->update(self::T('wallet_devices'), ['code_hash' => self::code_hash($code), 'code_expires' => time() + 15 * MINUTE_IN_SECONDS, 'code_tries' => 0], ['id' => intval($device_id)]);
        self::send_device_code(self::device($device_id), $code);
    }

    /** POST /wallet/devices {public_key, name, platform} */
    public static function rest_device_add(WP_REST_Request $req) {
        global $wpdb;
        if ($e = self::off()) return $e;
        $uid = get_current_user_id();
        if (self::limited('guest', 'dev' . $uid)) return self::rate_error();
        $k = self::parse_key($req->get_param('public_key'));
        if (is_wp_error($k)) return $k;
        list($pem, $key_id) = $k;
        $name = mb_substr(sanitize_text_field((string) $req->get_param('name')), 0, 80);
        $platform = in_array($req->get_param('platform'), ['ios', 'android'], true) ? $req->get_param('platform') : '';
        $D = self::T('wallet_devices');
        $have = $wpdb->get_row($wpdb->prepare("SELECT * FROM $D WHERE key_id = %s", $key_id), ARRAY_A);
        if ($have) {
            if (intval($have['user_id']) !== $uid) return self::err('tix_wallet_key_taken', 'Dieser Geräte-Schlüssel gehört zu einem anderen Konto.', 409);
            if (in_array($have['status'], ['removed', 'blocked'], true)) return self::err('tix_wallet_device_blocked', 'Dieses Gerät wurde entfernt. Bitte in der App einen neuen Schlüssel erzeugen.', 403);
            if ($have['status'] === 'pending') self::issue_code($have['id']); // erneut senden
            return self::out(['ok' => true, 'device' => self::device_payload(self::device($have['id'])), 'verification_required' => $have['status'] === 'pending']);
        }
        $active = self::active_count($uid);
        if ($active >= self::MAX_DEVICES) return self::err('tix_wallet_device_limit', 'Höchstens ' . self::MAX_DEVICES . ' Geräte. Bitte zuerst ein Gerät entfernen.', 409);
        $status = $active === 0 ? 'active' : 'pending';
        $now = current_time('mysql', true);
        $wpdb->insert($D, ['user_id' => $uid, 'key_id' => $key_id, 'public_key' => $pem, 'name' => $name, 'platform' => $platform, 'status' => $status,
            'verified_at' => $status === 'active' ? $now : null, 'created' => $now]);
        $id = intval($wpdb->insert_id);
        if (!$id) return self::err('tix_wallet_failed', 'Gerät konnte nicht angelegt werden.', 500);
        if ($status === 'pending') self::issue_code($id);
        return self::out(['ok' => true, 'device' => self::device_payload(self::device($id)), 'verification_required' => $status === 'pending'], 201);
    }

    /** POST /wallet/devices/{id}/verify {code} */
    public static function rest_device_verify(WP_REST_Request $req) {
        global $wpdb;
        $d = self::device($req['id']);
        $uid = get_current_user_id();
        if (!$d || intval($d['user_id']) !== $uid) return self::err('tix_wallet_device_unknown', 'Gerät nicht gefunden.', 404);
        if ($d['status'] === 'active') return self::out(['ok' => true, 'device' => self::device_payload($d)]);
        if ($d['status'] !== 'pending') return self::err('tix_wallet_device_blocked', 'Dieses Gerät ist gesperrt.', 403);
        $code = preg_replace('/\D/', '', (string) $req->get_param('code'));
        if (intval($d['code_expires']) < time() || intval($d['code_tries']) >= 5) return self::err('tix_wallet_code_expired', 'Der Code ist abgelaufen – bitte das Gerät erneut anmelden, dann kommt ein neuer Code.', 410);
        if (strlen($code) !== 6 || !hash_equals((string) $d['code_hash'], self::code_hash($code))) {
            $wpdb->query($wpdb->prepare("UPDATE " . self::T('wallet_devices') . " SET code_tries = code_tries + 1 WHERE id = %d", $d['id']));
            return self::err('tix_wallet_code_wrong', 'Der Code ist nicht korrekt.', 400);
        }
        if (self::active_count($uid) >= self::MAX_DEVICES) return self::err('tix_wallet_device_limit', 'Höchstens ' . self::MAX_DEVICES . ' Geräte. Bitte zuerst ein Gerät entfernen.', 409);
        $wpdb->update(self::T('wallet_devices'), ['status' => 'active', 'verified_at' => current_time('mysql', true), 'code_hash' => null, 'code_expires' => 0], ['id' => intval($d['id'])]);
        return self::out(['ok' => true, 'device' => self::device_payload(self::device($d['id']))]);
    }

    /** GET /wallet/devices */
    public static function rest_devices(WP_REST_Request $req) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::T('wallet_devices') . " WHERE user_id = %d AND status IN ('active','pending') ORDER BY id", get_current_user_id()), ARRAY_A);
        return self::out(['ok' => true, 'max' => self::MAX_DEVICES, 'devices' => array_map([__CLASS__, 'device_payload'], (array) $rows)]);
    }

    /** DELETE /wallet/devices/{id} – entfernt (bleibt gesperrt, Schlüssel nicht wiederverwendbar). */
    public static function rest_device_delete(WP_REST_Request $req) {
        global $wpdb;
        $d = self::device($req['id']);
        if (!$d || intval($d['user_id']) !== get_current_user_id() || $d['status'] === 'removed') return self::err('tix_wallet_device_unknown', 'Gerät nicht gefunden.', 404);
        $wpdb->update(self::T('wallet_devices'), ['status' => 'removed'], ['id' => intval($d['id'])]);
        return self::out(['ok' => true]);
    }

    // ═══════════════ Code prüfen ═══════════════

    private static function b64url_decode($s) {
        $s = strtr((string) $s, '-_', '+/');
        return base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /**
     * Code zerlegen und prüfen (ohne die Nonce zu verbrauchen).
     * @return array {wallet, device, nonce, ts, code} | WP_Error
     */
    public static function check_code($code, $scope = null) {
        global $wpdb;
        $code = trim((string) $code);
        if (!preg_match('/^TXP1\.(\d{1,12})\.(\d{1,12})\.(\d{9,11})\.([A-Za-z0-9_-]{16,43})\.([A-Za-z0-9_-]{40,200})$/', $code, $m)) {
            return self::err('tix_wallet_code_invalid', 'Kein gültiger Bezahl-Code. Bitte den Code in der App des Gastes scannen.', 400);
        }
        list(, $wid, $did, $ts, $nonce, $sig) = $m;
        $now = time();
        $ts = intval($ts);
        if ($ts > $now + self::SKEW || $ts < $now - self::VALID - self::SKEW) {
            return self::err('tix_wallet_code_expired', 'Code abgelaufen – der Gast soll den Bezahl-Bildschirm neu öffnen.', 410);
        }
        $d = self::device($did);
        $w = TIX_Wallet_Balance::get_wallet($wid);
        if (!$d || !$w || intval($d['user_id']) !== intval($w['user_id']) || intval($w['user_id']) <= 0) {
            return self::err('tix_wallet_device_unknown', 'Gerät unbekannt – der Gast soll sich in der App neu anmelden.', 403);
        }
        if ($d['status'] === 'pending') return self::err('tix_wallet_device_pending', 'Dieses Handy ist noch nicht bestätigt. Der Gast bestätigt es mit dem Code aus seiner E-Mail.', 403);
        if ($d['status'] !== 'active') return self::err('tix_wallet_device_blocked', 'Dieses Handy ist gesperrt. Bitte ein anderes Gerät oder Bargeld nutzen.', 403);
        $raw = self::b64url_decode($sig);
        $msg = 'TXP1.' . $wid . '.' . $did . '.' . $ts . '.' . $nonce;
        if ($raw === false || openssl_verify($msg, $raw, (string) $d['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
            return self::err('tix_wallet_code_signature', 'Code ungültig (Signatur stimmt nicht).', 403);
        }
        if ($scope !== null && intval($w['organizer_id']) !== intval($scope)) {
            // Plattform-Guthaben (1.38.466): gilt bei jedem Veranstalter mit Cashless (Modul „wallet“)
            $platform_ok = TIX_Wallet_Balance::is_platform_wallet($w) && TIX_Wallet_Balance::platform_on() && intval($scope) > 0 && TIX_Wallet_Balance::scope_enabled(intval($scope));
            if (!$platform_ok) return self::err('tix_wallet_scope', 'Dieses Guthaben gilt hier nicht.', 403);
        }
        if (!TIX_Wallet_Balance::scope_enabled($w['organizer_id']) && $scope !== null) {
            return self::err('tix_wallet_off', 'Guthaben ist hier nicht aktiv.', 404);
        }
        if ($w['status'] !== 'active') return self::err('tix_wallet_locked', 'Diese Geldbörse ist gesperrt. Bitte an die Kasse bzw. das Team verweisen.', 423);
        $used = $wpdb->get_var($wpdb->prepare("SELECT state FROM " . self::T('wallet_nonces') . " WHERE device_id = %d AND nonce = %s", intval($did), $nonce));
        return ['wallet' => $w, 'device' => $d, 'nonce' => $nonce, 'ts' => $ts, 'code' => $code, 'used' => $used !== null ? (string) $used : null];
    }

    /** Nonce verbrauchen = Zahlungsvorgang anlegen (atomar per Primärschlüssel). */
    private static function consume(array $c, array $row) {
        global $wpdb;
        $n = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . self::T('wallet_nonces') . " (device_id, nonce, used_at, wallet_id, state, amount, station, staff_user_id, tx_id, idempotency_key, kind, organizer_id)
             VALUES (%d, %s, %d, %d, %s, %d, %s, %d, 0, %s, %s, %d)",
            intval($c['device']['id']), $c['nonce'], time(), intval($c['wallet']['id']), $row['state'], intval($row['amount']), (string) $row['station'],
            intval($row['staff_user_id']), (string) $row['idempotency_key'], (string) $row['kind'], intval($row['organizer_id'] ?? 0)
        ));
        return $n === 1;
    }

    private static function pay_row($device_id, $nonce) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::T('wallet_nonces') . " WHERE device_id = %d AND nonce = %s", intval($device_id), (string) $nonce), ARRAY_A);
        return $r ?: null;
    }

    private static function set_state(array $r, $state, $tx_id = 0) {
        global $wpdb;
        $wpdb->update(self::T('wallet_nonces'), ['state' => $state, 'tx_id' => intval($tx_id)], ['device_id' => intval($r['device_id']), 'nonce' => $r['nonce']]);
    }

    private static function first_name($uid) {
        $u = get_userdata(intval($uid));
        if (!$u) return '';
        $f = trim((string) $u->first_name) ?: trim((string) get_user_meta($u->ID, 'billing_first_name', true));
        if ($f === '') $f = strtok((string) $u->display_name, ' ') ?: '';
        return (string) $f;
    }

    private static function avatar($uid) {
        $a = (string) get_user_meta(intval($uid), '_tix_avatar_url', true);
        return $a !== '' ? $a : null;
    }

    // ═══════════════ Personal ═══════════════

    /** Bereich des Personals (Mehr-Veranstalter-Modus: eigener Veranstalter; Admin: Bereich der Geldbörse). */
    private static function staff_scope_for(array $wallet_or_null = null) {
        if (!class_exists('TIX_App_Scope') || !TIX_App_Scope::multi()) return 0;
        if (current_user_can('manage_options')) return $wallet_or_null ? intval($wallet_or_null['organizer_id']) : null;
        return TIX_App_Scope::organizer_id_for_user();
    }

    private static function stations($oid) {
        $s = TIX_Wallet_Balance::settings($oid);
        return $s['stations'] ? array_values($s['stations']) : ['Kasse'];
    }

    private static function station($req, $oid) {
        $list = self::stations($oid);
        $st = trim(sanitize_text_field((string) $req->get_param('station')));
        if ($st === '') return $list[0];
        return in_array($st, $list, true) ? $st : self::err('tix_wallet_station', 'Unbekannte Station. Möglich: ' . implode(', ', $list) . '.', 400, ['stations' => $list]);
    }

    /** Code prüfen im Bereich des Personals. */
    private static function staff_check(WP_REST_Request $req) {
        if ($e = self::off()) return $e;
        if (self::limited('staff', get_current_user_id())) return self::rate_error();
        $peek = preg_match('/^TXP1\.(\d+)\./', (string) $req->get_param('code'), $m) ? TIX_Wallet_Balance::get_wallet($m[1]) : null;
        $scope = self::staff_scope_for($peek);
        $c = self::check_code($req->get_param('code'), $scope === null ? ($peek ? intval($peek['organizer_id']) : 0) : $scope);
        if (is_wp_error($c)) return $c;
        // Wo wird bezahlt? Plattform-Guthaben beim Veranstalter des Personals, sonst der Bereich der Geldbörse
        $c['cash_org'] = (TIX_Wallet_Balance::is_platform_wallet($c['wallet']) && intval($scope) > 0) ? intval($scope) : intval($c['wallet']['organizer_id']);
        if (self::limited('device', $c['device']['id']) || self::limited('wallet', $c['wallet']['id'])) return self::rate_error();
        return $c;
    }

    private static function amount_cents($req) {
        $a = TIX_Wallet_Balance::cents($req->get_param('amount'));
        return ($a > 0 && $a <= 500000) ? $a : self::err('tix_wallet_amount', 'Bitte einen Betrag eingeben.', 400);
    }

    private static function preview(array $c, $amount) {
        $w = $c['wallet'];
        $bal = intval($w['balance_paid']) + intval($w['balance_bonus']);
        $cash_org = intval($c['cash_org'] ?? $w['organizer_id']);
        $s = TIX_Wallet_Balance::settings($cash_org);
        $th = TIX_Wallet_Balance::cents($s['approval_threshold']);
        return [
            'wallet_id' => intval($w['id']), 'first_name' => self::first_name($w['user_id']), 'avatar' => self::avatar($w['user_id']),
            'balance' => TIX_Wallet_Balance::euro($bal),
            'amount' => $amount ? TIX_Wallet_Balance::euro($amount) : null,
            'balance_ok' => $amount ? $bal >= $amount : $bal > 0,
            'missing' => $amount && $bal < $amount ? TIX_Wallet_Balance::euro($amount - $bal) : 0,
            'approval_required' => $amount ? ($th > 0 && $amount >= $th) : false,
            'approval_threshold' => $s['approval_threshold'],
            'code_used' => $c['used'] !== null,
            'stations' => self::stations($cash_org),
            'platform' => TIX_Wallet_Balance::is_platform_wallet($w),
        ];
    }

    /** POST /wallet/scan {code, amount?, station?} – Vorschau, verbraucht den Code nicht. */
    public static function rest_scan(WP_REST_Request $req) {
        $c = self::staff_check($req);
        if (is_wp_error($c)) return $c;
        $amount = $req->get_param('amount') !== null && $req->get_param('amount') !== '' ? self::amount_cents($req) : 0;
        if (is_wp_error($amount)) return $amount;
        if ($c['used'] !== null) return self::err('tix_wallet_code_used', 'Dieser Code wurde schon benutzt – der Gast soll den Bezahl-Bildschirm neu laden.', 409);
        return self::out(['ok' => true, 'preview' => self::preview($c, $amount)]);
    }

    private static function charge_result(array $r, $status = 200) {
        $w = TIX_Wallet_Balance::get_wallet($r['wallet_id']);
        $out = ['ok' => true, 'status' => $r['state'], 'amount' => TIX_Wallet_Balance::euro($r['amount']), 'station' => (string) $r['station'],
            'first_name' => $w ? self::first_name($w['user_id']) : '', 'balance' => $w ? TIX_Wallet_Balance::euro(intval($w['balance_paid']) + intval($w['balance_bonus'])) : null];
        if ($r['state'] === 'approval') {
            $out['status'] = 'approval_required';
            $out['approval_expires_at'] = gmdate('c', intval($r['used_at']) + self::APPROVAL_TTL);
            $status = 202;
        }
        if (intval($r['tx_id'])) {
            global $wpdb;
            $tx = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Wallet_Balance::t('wallet_tx') . " WHERE id = %d", intval($r['tx_id'])), ARRAY_A);
            if ($tx) $out['tx'] = TIX_Wallet_Balance::tx_payload($tx, true);
        }
        return self::out($out, $status);
    }

    /** Offene Freigabe abgelaufen? */
    private static function expire_if_due(array $r) {
        if ($r['state'] === 'approval' && intval($r['used_at']) + self::APPROVAL_TTL < time()) {
            self::set_state($r, 'expired');
            $r['state'] = 'expired';
        }
        return $r;
    }

    /** Abbuchen für einen Vorgang (direkt bzw. nach Freigabe). */
    private static function book(array $r) {
        $tx = TIX_Wallet_Balance::post_tx(intval($r['wallet_id']), [
            'type' => 'charge', 'amount' => -intval($r['amount']), 'idempotency_key' => 'charge:' . $r['idempotency_key'], 'staff_user_id' => intval($r['staff_user_id']),
            'station' => (string) $r['station'], 'device_id' => intval($r['device_id']), 'channel' => 'app', 'meta' => ['nonce' => (string) $r['nonce']],
            'organizer_id' => intval($r['organizer_id'] ?? 0),
        ]);
        if (is_wp_error($tx)) { self::set_state($r, 'failed'); return $tx; }
        self::set_state($r, 'paid', $tx['id']);
        if (empty($tx['replay'])) {
            $w = TIX_Wallet_Balance::get_wallet($r['wallet_id']);
            // Plattform-Guthaben: Betrag geht mit der nächsten Abrechnung an den Veranstalter (offener Posten „cashless“)
            if (TIX_Wallet_Balance::is_platform_wallet($w) && intval($r['organizer_id'] ?? 0) > 0 && class_exists('TIX_Settlement')) {
                TIX_Settlement::add_cashless(intval($r['organizer_id']), -intval($tx['amount_paid']) - intval($tx['amount_bonus']), intval($tx['id']), (string) $r['station']);
            }
            if ($w && class_exists('TIX_Notifications')) {
                TIX_Notifications::add_user_item(intval($w['user_id']), 'Bezahlt', TIX_Wallet_Balance::fmt($r['amount']) . ' bezahlt · ' . $r['station'], ['type' => 'wallet', 'action' => 'wallet']);
            }
        }
        return $tx;
    }

    private static function find_by_idem($staff, $key) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::T('wallet_nonces') . " WHERE staff_user_id = %d AND idempotency_key = %s ORDER BY used_at DESC LIMIT 1", intval($staff), (string) $key), ARRAY_A);
        return $r ?: null;
    }

    private static function idem($req) {
        $k = trim((string) $req->get_param('idempotency_key'));
        return preg_match('/^[A-Za-z0-9_.:-]{8,80}$/', $k) ? $k : self::err('tix_wallet_idempotency', 'idempotency_key fehlt (8–80 Zeichen).', 400);
    }

    /** POST /wallet/charge {code, amount, station, idempotency_key} */
    public static function rest_charge(WP_REST_Request $req) {
        $key = self::idem($req);
        if (is_wp_error($key)) return $key;
        $staff = get_current_user_id();
        // Wiederholung (Doppeltipp, schlechtes Netz, Nachfragen bei Freigabe): gleicher Schlüssel = gleicher Vorgang
        if ($prev = self::find_by_idem($staff, $key)) {
            $prev = self::expire_if_due($prev);
            if ($prev['state'] === 'declined') return self::err('tix_wallet_declined', 'Der Gast hat die Zahlung abgelehnt.', 409);
            if ($prev['state'] === 'expired') return self::err('tix_wallet_approval_expired', 'Der Gast hat nicht rechtzeitig bestätigt. Bitte neu scannen.', 410);
            if ($prev['state'] === 'failed') return self::err('tix_wallet_insufficient', 'Guthaben reicht nicht.', 409);
            return self::charge_result($prev);
        }
        $c = self::staff_check($req);
        if (is_wp_error($c)) return $c;
        if ($c['used'] !== null) return self::err('tix_wallet_code_used', 'Dieser Code wurde schon benutzt – der Gast soll den Bezahl-Bildschirm neu laden.', 409);
        $amount = self::amount_cents($req);
        if (is_wp_error($amount)) return $amount;
        $oid = intval($c['cash_org'] ?? $c['wallet']['organizer_id']);
        $station = self::station($req, $oid);
        if (is_wp_error($station)) return $station;
        $bal = intval($c['wallet']['balance_paid']) + intval($c['wallet']['balance_bonus']);
        if ($bal < $amount) {
            return self::err('tix_wallet_insufficient', 'Guthaben reicht nicht. Es fehlen ' . TIX_Wallet_Balance::fmt($amount - $bal) . '.', 409, ['missing' => TIX_Wallet_Balance::euro($amount - $bal), 'balance' => TIX_Wallet_Balance::euro($bal)]);
        }
        $th = TIX_Wallet_Balance::cents(TIX_Wallet_Balance::settings($oid)['approval_threshold']);
        $needs = $th > 0 && $amount >= $th;
        $row = ['state' => $needs ? 'approval' : 'pending', 'amount' => $amount, 'station' => $station, 'staff_user_id' => $staff, 'idempotency_key' => $key, 'kind' => 'charge', 'organizer_id' => $oid];
        if (!self::consume($c, $row)) return self::err('tix_wallet_code_used', 'Dieser Code wurde schon benutzt – der Gast soll den Bezahl-Bildschirm neu laden.', 409);
        $r = self::pay_row($c['device']['id'], $c['nonce']);
        if ($needs) {
            if (class_exists('TIX_Notifications')) {
                TIX_Notifications::add_user_item(intval($c['wallet']['user_id']), 'Zahlung bestätigen?', TIX_Wallet_Balance::fmt($amount) . ' · ' . $station . ' – bitte in der App bestätigen', ['type' => 'wallet', 'action' => 'wallet']);
            }
            return self::charge_result($r);
        }
        $tx = self::book($r);
        if (is_wp_error($tx)) return $tx;
        return self::charge_result(self::pay_row($c['device']['id'], $c['nonce']));
    }

    /** Darf der Nutzer verwalten (Admin, Inhaber/Team-Admin bzw. Rolle Veranstalter)? */
    private static function is_manager() {
        if (current_user_can('manage_options')) return true;
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) return TIX_App_Scope::is_org_manager();
        return in_array('tix_organizer', (array) wp_get_current_user()->roles, true);
    }

    /** POST /wallet/charge/{tx_id}/reverse {reason} */
    public static function rest_reverse(WP_REST_Request $req) {
        global $wpdb;
        if ($e = self::off()) return $e;
        $tx = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Wallet_Balance::t('wallet_tx') . " WHERE id = %d", intval($req['tx_id'])), ARRAY_A);
        if (!$tx || $tx['type'] !== 'charge') return self::err('tix_wallet_tx_unknown', 'Buchung nicht gefunden.', 404);
        $w = TIX_Wallet_Balance::get_wallet($tx['wallet_id']);
        $scope = self::staff_scope_for($w);
        $own_platform = TIX_Wallet_Balance::is_platform_wallet($w) && intval($scope) > 0 && intval($tx['organizer_id'] ?? 0) === intval($scope);
        if ($scope !== null && intval($w['organizer_id']) !== intval($scope) && !$own_platform) return self::err('tix_wallet_tx_unknown', 'Buchung nicht gefunden.', 404);
        $reason = trim(sanitize_text_field((string) $req->get_param('reason')));
        if ($reason === '') return self::err('tix_wallet_reason', 'Bitte einen Grund angeben.', 400);
        $age = time() - strtotime($tx['created'] . ' UTC');
        if ($age > self::REVERSE_STAFF && !self::is_manager()) {
            return self::err('tix_wallet_reverse_late', 'Stornieren geht nach 10 Minuten nur noch durch den Veranstalter.', 403);
        }
        $done = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Wallet_Balance::t('wallet_tx') . " WHERE ref_tx_id = %d AND type = 'reversal'", intval($tx['id'])), ARRAY_A);
        if ($done) return self::out(['ok' => true, 'tx' => TIX_Wallet_Balance::tx_payload($done, true), 'already' => true]);
        $r = TIX_Wallet_Balance::post_tx(intval($tx['wallet_id']), ['type' => 'reversal', 'amount_paid' => -intval($tx['amount_paid']), 'amount_bonus' => -intval($tx['amount_bonus']),
            'ref_tx_id' => intval($tx['id']), 'staff_user_id' => get_current_user_id(), 'station' => (string) $tx['station'], 'channel' => 'app',
            'idempotency_key' => 'reverse:' . $tx['id'], 'allow_locked' => true, 'meta' => ['reason' => $reason], 'organizer_id' => intval($tx['organizer_id'] ?? 0)]);
        if (is_wp_error($r)) return $r;
        if (empty($r['replay']) && TIX_Wallet_Balance::is_platform_wallet($w) && intval($tx['organizer_id'] ?? 0) > 0 && class_exists('TIX_Settlement')) {
            TIX_Settlement::add_cashless(intval($tx['organizer_id']), -(intval($r['amount_paid']) + intval($r['amount_bonus'])), intval($r['id']), 'Storno ' . (string) $tx['station']);
        }
        if (empty($r['replay']) && class_exists('TIX_Notifications')) {
            TIX_Notifications::add_user_item(intval($w['user_id']), 'Zahlung storniert', TIX_Wallet_Balance::fmt(-intval($tx['amount_paid']) - intval($tx['amount_bonus'])) . ' zurück · ' . $tx['station'], ['type' => 'wallet', 'action' => 'wallet']);
        }
        $w = TIX_Wallet_Balance::get_wallet($tx['wallet_id']);
        return self::out(['ok' => true, 'tx' => TIX_Wallet_Balance::tx_payload($r, true), 'balance' => TIX_Wallet_Balance::euro(intval($w['balance_paid']) + intval($w['balance_bonus']))]);
    }

    /** POST /wallet/topup-pos {code, amount, method: pos_cash|pos_card, station, idempotency_key} */
    public static function rest_topup_pos(WP_REST_Request $req) {
        $key = self::idem($req);
        if (is_wp_error($key)) return $key;
        $method = (string) $req->get_param('method');
        if ($method === 'sumup') return self::err('tix_wallet_method', 'Kartenzahlung über SumUp kommt mit Phase 4.', 400);
        if (!in_array($method, ['pos_cash', 'pos_card'], true)) return self::err('tix_wallet_method', 'Bitte bar (pos_cash) oder Karte (pos_card) wählen.', 400);
        $staff = get_current_user_id();
        if ($prev = self::find_by_idem($staff, $key)) return self::charge_result($prev);
        $c = self::staff_check($req);
        if (is_wp_error($c)) return $c;
        if ($c['used'] !== null) return self::err('tix_wallet_code_used', 'Dieser Code wurde schon benutzt – der Gast soll den Bezahl-Bildschirm neu laden.', 409);
        $amount = self::amount_cents($req);
        if (is_wp_error($amount)) return $amount;
        $w = $c['wallet']; $oid = intval($w['organizer_id']);
        // Plattform-Guthaben nur in der App aufladen (an der Kasse läge das Geld beim Veranstalter, nicht bei evendis)
        if (TIX_Wallet_Balance::is_platform_wallet($w)) return self::err('tix_wallet_platform_pos', 'Das evendis-Guthaben lässt sich nur in der App aufladen.', 409);
        $station = self::station($req, $oid);
        if (is_wp_error($station)) return $station;
        $s = TIX_Wallet_Balance::settings($oid);
        $bonus = TIX_Wallet_Balance::bonus_for($amount, $s);
        if ($amount > TIX_Wallet_Balance::cents($s['max_topup'])) return self::err('tix_wallet_limit_topup', 'Höchstens ' . TIX_Wallet_Balance::fmt(TIX_Wallet_Balance::cents($s['max_topup'])) . ' je Aufladung.', 409, ['limit' => $s['max_topup']]);
        $cur = intval($w['balance_paid']) + intval($w['balance_bonus']);
        if ($cur + $amount + $bonus > TIX_Wallet_Balance::cents($s['max_balance'])) {
            $room = max(0, TIX_Wallet_Balance::cents($s['max_balance']) - $cur);
            return self::err('tix_wallet_limit_balance', 'Höchstguthaben ' . TIX_Wallet_Balance::fmt(TIX_Wallet_Balance::cents($s['max_balance'])) . ' – noch ' . TIX_Wallet_Balance::fmt($room) . ' möglich (inklusive Bonus).', 409, ['limit' => $s['max_balance'], 'room' => TIX_Wallet_Balance::euro($room)]);
        }
        if (!self::consume($c, ['state' => 'pending', 'amount' => $amount, 'station' => $station, 'staff_user_id' => $staff, 'idempotency_key' => $key, 'kind' => 'topup'])) {
            return self::err('tix_wallet_code_used', 'Dieser Code wurde schon benutzt – der Gast soll den Bezahl-Bildschirm neu laden.', 409);
        }
        $r = self::pay_row($c['device']['id'], $c['nonce']);
        $tx = TIX_Wallet_Balance::post_tx($w['id'], ['type' => 'topup', 'amount_paid' => $amount, 'idempotency_key' => 'pos:' . $key, 'staff_user_id' => $staff, 'station' => $station,
            'device_id' => intval($c['device']['id']), 'channel' => $method, 'meta' => ['nonce' => $c['nonce']]]);
        if (is_wp_error($tx)) { self::set_state($r, 'failed'); return $tx; }
        if ($bonus > 0) TIX_Wallet_Balance::post_tx($w['id'], ['type' => 'bonus', 'amount_bonus' => $bonus, 'ref_tx_id' => intval($tx['id']), 'idempotency_key' => 'pos:' . $key . ':bonus', 'staff_user_id' => $staff, 'station' => $station, 'channel' => $method]);
        self::set_state($r, 'paid', $tx['id']);
        if (empty($tx['replay']) && class_exists('TIX_Notifications')) {
            TIX_Notifications::add_user_item(intval($w['user_id']), 'Guthaben aufgeladen', 'Guthaben aufgeladen: ' . TIX_Wallet_Balance::fmt($amount) . ($bonus ? ' + ' . TIX_Wallet_Balance::fmt($bonus) . ' Bonus' : '') . ' · ' . $station, ['type' => 'wallet', 'action' => 'wallet']);
        }
        $out = self::charge_result(self::pay_row($c['device']['id'], $c['nonce']));
        $d = $out->get_data(); $d['bonus'] = TIX_Wallet_Balance::euro($bonus); $out->set_data($d);
        return $out;
    }

    // ═══════════════ Gast ═══════════════

    /** Code des Gastes (eigene Geldbörse, Signatur egal ob abgelaufen) → Vorgang. */
    private static function own_row($code) {
        if (!preg_match('/^TXP1\.(\d+)\.(\d+)\.(\d+)\.([A-Za-z0-9_-]{16,43})\./', (string) $code, $m)) return self::err('tix_wallet_code_invalid', 'Ungültiger Code.', 400);
        $w = TIX_Wallet_Balance::get_wallet($m[1]);
        $d = self::device($m[2]);
        if (!$w || !$d || intval($w['user_id']) !== get_current_user_id() || intval($d['user_id']) !== get_current_user_id()) return self::err('tix_wallet_code_invalid', 'Ungültiger Code.', 400);
        return ['wallet' => $w, 'device' => $d, 'nonce' => $m[4], 'row' => self::pay_row($d['id'], $m[4])];
    }

    private static function status_payload($o) {
        $r = $o['row'];
        if (!$r) return ['ok' => true, 'status' => 'pending'];
        $r = self::expire_if_due($r);
        $w = TIX_Wallet_Balance::get_wallet($o['wallet']['id']);
        $out = ['ok' => true, 'status' => $r['state'] === 'approval' ? 'approval_required' : ($r['state'] === 'pending' ? 'pending' : $r['state']),
            'kind' => (string) $r['kind'], 'amount' => TIX_Wallet_Balance::euro($r['amount']), 'station' => (string) $r['station'],
            'balance' => TIX_Wallet_Balance::euro(intval($w['balance_paid']) + intval($w['balance_bonus']))];
        if ($r['state'] === 'approval') $out['approval_expires_at'] = gmdate('c', intval($r['used_at']) + self::APPROVAL_TTL);
        if (intval($r['tx_id'])) $out['tx_id'] = intval($r['tx_id']);
        return $out;
    }

    /** GET /wallet/pay-status?code=&timeout= – Long-Polling bis 25 s. */
    public static function rest_pay_status(WP_REST_Request $req) {
        if ($e = self::off()) return $e;
        if (self::limited('guest', 'ps' . get_current_user_id())) return self::rate_error();
        $o = self::own_row($req->get_param('code'));
        if (is_wp_error($o)) return $o;
        $timeout = $req->get_param('timeout') !== null ? max(0, min(self::POLL_MAX, intval($req->get_param('timeout')))) : self::POLL_MAX;
        $until = microtime(true) + $timeout;
        $first = self::status_payload($o);
        while ($first['status'] === 'pending' && microtime(true) < $until) {
            usleep(700000);
            $o['row'] = self::pay_row($o['device']['id'], $o['nonce']);
            $first = self::status_payload($o);
        }
        return self::out($first);
    }

    /** POST /wallet/approve {code, approve} – Freigabe durch den Gast (nach Face ID in der App). */
    public static function rest_approve(WP_REST_Request $req) {
        if ($e = self::off()) return $e;
        $o = self::own_row($req->get_param('code'));
        if (is_wp_error($o)) return $o;
        $r = $o['row'] ? self::expire_if_due($o['row']) : null;
        if (!$r || $r['kind'] !== 'charge') return self::err('tix_wallet_no_approval', 'Hier wartet keine Zahlung auf deine Bestätigung.', 404);
        if ($r['state'] === 'expired') return self::err('tix_wallet_approval_expired', 'Die Zeit für die Bestätigung ist abgelaufen. Bitte an der Kasse neu bezahlen.', 410);
        if ($r['state'] !== 'approval') return self::out(self::status_payload($o + ['row' => $r]) + ['already' => true]);
        $approve = filter_var($req->get_param('approve'), FILTER_VALIDATE_BOOLEAN);
        if (!$approve) {
            self::set_state($r, 'declined');
            $o['row'] = self::pay_row($o['device']['id'], $o['nonce']);
            return self::out(self::status_payload($o));
        }
        $tx = self::book($r);
        if (is_wp_error($tx)) return $tx;
        $o['row'] = self::pay_row($o['device']['id'], $o['nonce']);
        return self::out(self::status_payload($o));
    }

    // ═══════════════ Routen, Aufräumen ═══════════════

    public static function register_routes() {
        $login = function ($req) { return class_exists('TIX_App_Account') ? TIX_App_Account::check_customer($req) : is_user_logged_in(); };
        $staff = ['TIX_Wallet_Balance', 'check_staff'];
        $reg = function ($path, $method, $cb, $perm) {
            register_rest_route(self::NS, $path, ['methods' => $method, 'callback' => [__CLASS__, $cb], 'permission_callback' => $perm]);
        };
        register_rest_route(self::NS, '/wallet/devices', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_devices'], 'permission_callback' => $login],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_device_add'], 'permission_callback' => $login],
        ]);
        $reg('/wallet/devices/(?P<id>\d+)/verify', 'POST', 'rest_device_verify', $login);
        $reg('/wallet/devices/(?P<id>\d+)', 'DELETE', 'rest_device_delete', $login);
        $reg('/wallet/pay-status', 'GET', 'rest_pay_status', $login);
        $reg('/wallet/approve', 'POST', 'rest_approve', $login);
        $reg('/wallet/scan', 'POST', 'rest_scan', $staff);
        $reg('/wallet/charge', 'POST', 'rest_charge', $staff);
        $reg('/wallet/charge/(?P<tx_id>\d+)/reverse', 'POST', 'rest_reverse', $staff);
        $reg('/wallet/topup-pos', 'POST', 'rest_topup_pos', $staff);
    }

    /** Stündlich: Vorgänge älter als 24 h löschen (Codes sind nach 90 s ohnehin ungültig), offene Freigaben verfallen lassen. */
    public static function cleanup() {
        global $wpdb;
        if (!TIX_Wallet_Balance::ready()) return 0;
        $N = self::T('wallet_nonces');
        $wpdb->query($wpdb->prepare("UPDATE $N SET state = 'expired' WHERE state = 'approval' AND used_at < %d", time() - self::APPROVAL_TTL));
        return intval($wpdb->query($wpdb->prepare("DELETE FROM $N WHERE used_at < %d", time() - DAY_IN_SECONDS)));
    }
}
