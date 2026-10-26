<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Degaso – Geschenkgutscheine mit der Degaso-Kasse verbinden (1.38.555, Paket 1, Plan tixomat-apps/docs/plan-degaso.md).
 *
 * Degaso führt den Saldo, Tixomat stellt aus und spiegelt (User-Entscheid 2026-10-26).
 *  - Ausstellen: tix_order_completed (nach der Code-Erzeugung) → /vouchers/new {objectId: GS-…} + /vouchers/topup.
 *  - Einlösen in Tixomat (Online-Kasse, App-Kasse, Guthaben, Bar nur mit Schalter): Filter `tix_giftcard_redeem_remote` in
 *    TIX_Giftcards::redeem() → /vouchers/payment, lokal gebucht wird erst danach mit dem Saldo von Degaso.
 *  - Rückbuchung (Storno/void): Aktion `tix_giftcard_credited` → /vouchers/topup. Rest weg (Bestellung storniert): Aktion
 *    `tix_giftcard_cleared` → /vouchers/payment über den ganzen Degaso-Saldo.
 *  - Rückweg ohne Webhooks: Cron alle 10 min /vouchers/list {from, includeOrigin} und live /vouchers/get (Aktion
 *    `tix_giftcard_refresh`, höchstens alle 20 s je Code) → Einlösungen an der Bar landen als Kanal `degaso` im Verlauf.
 *  - Doppelbuchung: jede Buchung trägt eine feste paymentId `tix-<seite>-<art>-<bezug>` (o Ausgabe, i Import, r Einlösung,
 *    v Rückbuchung, c Rest weg, x Ablauf); Wiederholen ist gefahrlos (ERROR_PAYMENT_ID_USED = schon gebucht).
 *  - Fällt Degaso aus: Ausgabe/Rückbuchung/bezahlte Bestellungen gehen in die Warteschlange (Cron, gleiche paymentId);
 *    Einlösen vor dem Bezahlen (Bar, Guthaben) wird abgelehnt, nie auf Verdacht gebucht.
 *  - Ablauf: Einstellung expiry_action none (Standard, Degaso kennt kein Ablaufdatum) | clear (Rest bei Ablauf in Degaso abbuchen).
 *  - Bar: Gutschein abbuchen in Tixomat „Kasse & Bar“ nur mit bar_redeem (Standard aus: die Getränke laufen über die
 *    Degaso-Kasse mit TSE; Abbuchen in Tixomat hieße Verkauf ohne Kassenbon).
 *
 * Schlüssel: verschlüsselt in der Option (AES-256-GCM, wie TIX_Payout_Details), nie in einer Antwort (nur „gesetzt“ + letzte 4).
 * Antworten von Degaso enthalten den Schlüssel – sie werden nie durchgereicht.
 *
 * REST (Studio; Einzel-Seite Inhaber/Team-Admin/Admin, Mehr-Veranstalter-Modus nur Admin):
 *   GET  /degaso/admin                      Einstellungen, Zahlen, Fehler
 *   POST /degaso/admin                      {enabled, api_key, clear_key, test_mode, test_api_key, clear_test_key, bar_redeem, expiry_action}
 *   POST /degaso/admin/check  {api_key?, test?}  Verbindung prüfen (ohne Speichern möglich)
 *   POST /degaso/admin/sync                 Warteschlange + Ausstehende + Abgleich sofort
 *   POST /degaso/admin/import {dry_run}     vorhandene Gutscheine mit Restguthaben übertragen
 */
class TIX_Degaso {

    const NS       = 'tixomat/v1';
    const BASE     = 'https://api.alleskasse.de';
    const OPT      = 'tix_degaso';
    const STATE    = 'tix_degaso_state';
    const QUEUE    = 'tix_degaso_queue';
    const LOG      = 'tix_degaso_log';
    const CRON     = 'tix_degaso_sync';
    const CRON_DAY = 'tix_degaso_daily';

    /** Tests: callable($path, array $body) → [int $status, array|null $json]; null = WordPress-HTTP */
    public static $transport = null;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('tix_order_completed', [__CLASS__, 'on_order_completed'], 30); // nach TIX_Giftcards::create_for_order (20)
        add_filter('tix_giftcard_redeem_remote', [__CLASS__, 'remote_redeem'], 10, 6);
        add_action('tix_giftcard_credited', [__CLASS__, 'on_credited'], 10, 3);
        add_action('tix_giftcard_cleared', [__CLASS__, 'on_cleared'], 10, 3);
        add_action('tix_giftcard_refresh', [__CLASS__, 'refresh'], 10, 1);
        add_filter('cron_schedules', function ($s) {
            $s['tix_10min'] = $s['tix_10min'] ?? ['interval' => 600, 'display' => 'Alle 10 Minuten'];
            return $s;
        });
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_action(self::CRON_DAY, [__CLASS__, 'daily']);
        add_action('init', function () {
            if (!self::active()) return;
            if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 120, 'tix_10min', self::CRON);
            if (!wp_next_scheduled(self::CRON_DAY)) wp_schedule_event(strtotime('tomorrow 04:30'), 'daily', self::CRON_DAY);
        });
    }

    // ═══════════════ Einstellungen ═══════════════

    public static function defaults() {
        return ['enabled' => false, 'api_key' => '', 'test_mode' => false, 'test_api_key' => '', 'bar_redeem' => false,
            'expiry_action' => 'none', 'last_pull' => '', 'last_ok' => '', 'connected_at' => ''];
    }

    public static function settings() {
        $s = get_option(self::OPT, []);
        return array_merge(self::defaults(), is_array($s) ? $s : []);
    }

    private static function save(array $s) {
        update_option(self::OPT, array_intersect_key($s, self::defaults()), false);
    }

    /** Schlüssel des aktuellen Betriebs (Testbetrieb = eigener Schlüssel) */
    public static function key() {
        $s = self::settings();
        return self::decrypt($s['test_mode'] ? $s['test_api_key'] : $s['api_key']);
    }

    public static function active() {
        return !empty(self::settings()['enabled']) && self::key() !== '';
    }

    /** Gutschein abbuchen in Tixomat „Kasse & Bar“ erlaubt? (ohne Degaso wie bisher) */
    public static function bar_allowed() {
        return !self::active() || !empty(self::settings()['bar_redeem']);
    }

    private static function crypto_key() {
        $extra = defined('TIX_DEGASO_KEY') ? (string) TIX_DEGASO_KEY : '';
        return hash('sha256', 'tix-degaso|' . $extra . '|' . wp_salt('secure_auth'), true);
    }

    public static function encrypt($plain) {
        if ($plain === '' || !function_exists('openssl_encrypt')) return '';
        $iv = random_bytes(12);
        $tag = '';
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

    private static function last4($enc) {
        $k = self::decrypt($enc);
        return $k === '' ? '' : substr($k, -4);
    }

    // ═══════════════ HTTP ═══════════════

    /**
     * POST an die Degaso-API. → ['ok' => bool, 'status' => int, 'data' => array, 'reason' => failureReason|'network'|'http']
     * Ein Wiederholungsversuch bei Netzfehler – alle schreibenden Aufrufe sind über paymentId bzw. ERROR_EXISTS sicher.
     */
    public static function call($path, array $body, $key = null) {
        $body = ['apiKey' => $key ?? self::key()] + $body;
        for ($try = 0; $try < 2; $try++) {
            if (self::$transport) {
                list($status, $json) = call_user_func(self::$transport, $path, $body);
            } else {
                $res = wp_remote_post(self::base() . $path, [
                    'timeout' => 8, 'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                    'body' => wp_json_encode($body), 'user-agent' => 'Tixomat/' . (defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : '1'),
                ]);
                if (is_wp_error($res)) { $status = 0; $json = null; }
                else { $status = intval(wp_remote_retrieve_response_code($res)); $json = json_decode((string) wp_remote_retrieve_body($res), true); }
            }
            if ($status === 0) continue;
            $json = is_array($json) ? $json : [];
            $ok = $status >= 200 && $status < 300 && ($json['success'] ?? true) !== false;
            return ['ok' => $ok, 'status' => $status, 'data' => $json, 'reason' => $ok ? '' : (string) ($json['failureReason'] ?? 'http')];
        }
        return ['ok' => false, 'status' => 0, 'data' => [], 'reason' => 'network'];
    }

    private static function base() {
        return defined('TIX_DEGASO_BASE') ? rtrim(TIX_DEGASO_BASE, '/') : self::BASE;
    }

    // ═══════════════ Hilfen ═══════════════

    /** Kennung der Seite in jeder paymentId (z. B. tix-kitchenklub-de) */
    public static function site_tag() {
        $h = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
        return 'tix-' . substr(trim(preg_replace('/[^a-z0-9]+/', '-', $h), '-'), 0, 30);
    }

    public static function pid($kind, $ref) {
        return self::site_tag() . '-' . $kind . '-' . preg_replace('/[^A-Za-z0-9_.-]+/', '', (string) $ref);
    }

    private static function ours($payment_id) {
        return strpos((string) $payment_id, self::site_tag() . '-') === 0;
    }

    public static function state($code) {
        $all = get_option(self::STATE, []);
        $s = is_array($all) ? ($all[strtoupper($code)] ?? []) : [];
        return is_array($s) ? $s : [];
    }

    private static function set_state($code, array $patch) {
        wp_cache_delete(self::STATE, 'options');
        $all = get_option(self::STATE, []);
        if (!is_array($all)) $all = [];
        $k = strtoupper($code);
        $all[$k] = array_merge(is_array($all[$k] ?? null) ? $all[$k] : [], $patch);
        update_option(self::STATE, $all, false);
    }

    public static function log($level, $code, $msg) {
        $l = get_option(self::LOG, []);
        if (!is_array($l)) $l = [];
        array_unshift($l, ['ts' => current_time('mysql'), 'level' => $level, 'code' => (string) $code, 'msg' => (string) $msg]);
        update_option(self::LOG, array_slice($l, 0, 50), false);
    }

    private static function remote_voucher(array $r, $code) {
        $list = $r['data']['vouchers'] ?? (isset($r['data']['voucher']) ? [$r['data']['voucher']] : []);
        foreach ((array) $list as $v) if (is_array($v) && strcasecmp((string) ($v['objectId'] ?? ''), $code) === 0) return $v;
        return null;
    }

    private static function eur($v) {
        return number_format(floatval($v), 2, ',', '.') . ' €';
    }

    private static function fail_msg($reason) {
        switch ($reason) {
            case 'network': return 'Die Degaso-Kasse ist gerade nicht erreichbar.';
            case 'ERROR_FORBIDDEN': return 'Degaso lehnt den Schlüssel ab.';
            case 'ERROR_INACTIVE': return 'Der Gutschein ist an der Degaso-Kasse gesperrt.';
            case 'ERROR_NOT_FOUND': return 'Degaso kennt diesen Gutschein nicht.';
            default: return 'Degaso meldet einen Fehler (' . $reason . ').';
        }
    }

    private static function queue_add(array $job) {
        $q = get_option(self::QUEUE, []);
        if (!is_array($q)) $q = [];
        foreach ($q as $j) if (($j['pid'] ?? '') === $job['pid']) return; // schon drin
        $q[] = $job + ['tries' => 0, 'at' => current_time('mysql'), 'err' => ''];
        update_option(self::QUEUE, $q, false);
    }

    // ═══════════════ Ausstellen ═══════════════

    public static function on_order_completed($order_id) {
        if (!self::active() || !class_exists('TIX_Giftcards')) return;
        $order_id = intval($order_id);
        foreach ((array) get_option('tix_coupons', []) as $code => $c) {
            if (is_array($c) && ($c['discount_type'] ?? '') === 'giftcard' && intval($c['order_id'] ?? 0) === $order_id) {
                self::push((string) $code, 'o', $order_id . '-' . $code);
            }
        }
    }

    /**
     * Gutschein bei Degaso anlegen und mit dem aktuellen Tixomat-Saldo aufladen. → true | Fehlertext
     * Gibt es den Code bei Degaso schon: ok, wenn er von uns stammt (paymentId) oder leer ist; sonst Konflikt.
     */
    public static function push($code, $kind = 'o', $ref = '') {
        $st = self::state($code);
        if (($st['state'] ?? '') === 'ok') return true;
        $card = TIX_Giftcards::get_card($code);
        if (!$card) return 'Gutschein fehlt.';
        $code = (string) $card['code'];
        $bal = round(floatval($card['balance'] ?? 0), 2);
        $pid = self::pid($kind, $ref !== '' ? $ref : $code);
        $new = self::call('/vouchers/new', ['objectId' => $code]);
        if (!$new['ok'] && $new['reason'] === 'ERROR_EXISTS') {
            $got = self::call('/vouchers/get', ['objectIds' => [$code]]);
            $v = $got['ok'] ? self::remote_voucher($got, $code) : null;
            if (!$got['ok'] || !$v) return self::push_failed($code, $kind, $ref, $got['reason'] ?: 'ERROR_NOT_FOUND');
            $hist = (array) ($v['object']['history'] ?? []);
            $mine = array_filter($hist, function ($h) { return self::ours($h['paymentId'] ?? ''); });
            if ($mine) {
                self::set_state($code, ['state' => 'ok', 'synced_at' => current_time('mysql'), 'remote' => floatval($v['object']['balance'] ?? 0), 'err' => '']);
                return true;
            }
            if (floatval($v['object']['balance'] ?? 0) > 0 || $hist) {
                self::set_state($code, ['state' => 'conflict', 'err' => 'Den Code gibt es bei Degaso schon (Saldo ' . self::eur($v['object']['balance'] ?? 0) . ').']);
                self::log('conflict', $code, 'Den Code gibt es bei Degaso schon mit eigenem Verlauf – bitte im Degaso-Backoffice prüfen.');
                return 'Den Code gibt es bei Degaso schon.';
            }
        } elseif (!$new['ok']) {
            return self::push_failed($code, $kind, $ref, $new['reason']);
        }
        if ($bal > 0) {
            $top = self::call('/vouchers/topup', ['objectId' => $code, 'topupAmount' => $bal, 'paymentId' => $pid]);
            if (!$top['ok'] && $top['reason'] !== 'ERROR_PAYMENT_ID_USED') return self::push_failed($code, $kind, $ref, $top['reason']);
        }
        self::set_state($code, ['state' => 'ok', 'synced_at' => current_time('mysql'), 'remote' => $bal, 'err' => '', 'tries' => 0]);
        self::touch_ok();
        return true;
    }

    private static function push_failed($code, $kind, $ref, $reason) {
        $st = self::state($code);
        self::set_state($code, ['state' => 'pending', 'kind' => $kind, 'ref' => $ref, 'err' => self::fail_msg($reason), 'tries' => intval($st['tries'] ?? 0) + 1]);
        if (intval($st['tries'] ?? 0) === 0) self::log('error', $code, 'Übertragen an Degaso: ' . self::fail_msg($reason) . ' Wird automatisch wiederholt.');
        return self::fail_msg($reason);
    }

    private static function touch_ok() {
        $s = self::settings();
        $s['last_ok'] = current_time('mysql');
        self::save($s);
    }

    // ═══════════════ Einlösen ═══════════════

    /**
     * Filter tix_giftcard_redeem_remote (TIX_Giftcards::redeem): null = Degaso aus, sonst
     * ['ok' => true, 'balance' => float] bzw. ['ok' => false, 'message' => …, 'balance' => float].
     * Kanäle online/pos sind bezahlte Bestellungen: dort wird nie abgelehnt – Ausfall → Warteschlange, Fehlbetrag → Konflikt-Eintrag.
     */
    public static function remote_redeem($pre, $code, $amount, $channel, $ref, $rid) {
        if ($pre !== null || !self::active()) return $pre;
        $must = in_array($channel, ['online', 'pos'], true);
        $amount = round(floatval($amount), 2);
        $card = TIX_Giftcards::get_card($code);
        $local = $card ? round(floatval($card['balance'] ?? 0), 2) : 0;
        $pid = self::pid('r', $rid);
        $pushed = self::push($code, 'i', $code);
        if ($pushed !== true) {
            if ($must) {
                self::queue_add(['op' => 'pay', 'code' => $code, 'amount' => $amount, 'pid' => $pid, 'ref' => (string) $ref]);
                return ['ok' => true, 'balance' => max(0, round($local - $amount, 2))];
            }
            return ['ok' => false, 'message' => $pushed . ' Bitte gleich nochmal versuchen.', 'balance' => $local];
        }
        $r = self::call('/vouchers/payment', ['objectIds' => [$code], 'totalRedeemAmount' => $amount, 'paymentId' => $pid, 'orderId' => (string) $ref]);
        if ($r['ok'] || $r['reason'] === 'ERROR_PAYMENT_ID_USED') {
            $v = $r['ok'] ? self::remote_voucher($r, $code) : null;
            $bal = $v ? round(floatval($v['object']['balance'] ?? 0), 2) : self::remote_balance($code, max(0, $local - $amount));
            self::set_state($code, ['remote' => $bal, 'synced_at' => current_time('mysql'), 'seen' => self::seen_add(self::state($code), $pid)]);
            self::touch_ok();
            return ['ok' => true, 'balance' => $bal];
        }
        if ($r['reason'] === 'ERROR_VALUE_CHANGED') {
            $rest = self::remote_balance($code, 0);
            if (!$must) return ['ok' => false, 'message' => 'Auf dem Gutschein sind nur noch ' . self::eur($rest) . ' (an der Kasse schon eingelöst).', 'balance' => $rest];
            if ($rest > 0) self::call('/vouchers/payment', ['objectIds' => [$code], 'totalRedeemAmount' => $rest, 'paymentId' => $pid . '-rest', 'orderId' => (string) $ref]);
            self::log('conflict', $code, 'Bestellung ' . $ref . ': ' . self::eur($amount) . ' angerechnet, bei Degaso waren nur noch ' . self::eur($rest) . ' drauf. Fehlbetrag ' . self::eur($amount - $rest) . '.');
            return ['ok' => true, 'balance' => 0.0];
        }
        if ($must) {
            if ($r['reason'] === 'network') self::queue_add(['op' => 'pay', 'code' => $code, 'amount' => $amount, 'pid' => $pid, 'ref' => (string) $ref]);
            else self::log('conflict', $code, 'Bestellung ' . $ref . ': ' . self::fail_msg($r['reason']) . ' ' . self::eur($amount) . ' nur in Tixomat abgebucht.');
            return ['ok' => true, 'balance' => max(0, round($local - $amount, 2))];
        }
        return ['ok' => false, 'message' => self::fail_msg($r['reason']) . ($r['reason'] === 'network' ? ' Bitte gleich nochmal versuchen.' : ''), 'balance' => $local];
    }

    private static function remote_balance($code, $fallback) {
        $g = self::call('/vouchers/get', ['objectIds' => [$code]]);
        $v = $g['ok'] ? self::remote_voucher($g, $code) : null;
        return $v ? round(floatval($v['object']['balance'] ?? 0), 2) : round(floatval($fallback), 2);
    }

    private static function seen_add(array $st, $key) {
        $seen = (array) ($st['seen'] ?? []);
        if (!in_array($key, $seen, true)) $seen[] = $key;
        return array_slice($seen, -40);
    }

    // ═══════════════ Rückbuchung, Rest weg ═══════════════

    /** Aktion tix_giftcard_credited: Betrag kam zurück auf den Gutschein (Storno einer Bestellung, Bar-Storno) */
    public static function on_credited($code, $amount, $ref) {
        if (!self::active() || ($amount = round(floatval($amount), 2)) <= 0) return;
        if ((self::state($code)['state'] ?? '') !== 'ok') return; // noch nicht bei Degaso: push überträgt später den aktuellen Saldo
        $job = ['op' => 'credit', 'code' => (string) $code, 'amount' => $amount, 'pid' => self::pid('v', $ref)];
        if (!self::run_job($job)) self::queue_add($job);
    }

    /** Aktion tix_giftcard_cleared: Gutschein stillgelegt (Bestellung storniert/erstattet) → ganzen Degaso-Saldo abbuchen */
    public static function on_cleared($code, $amount, $ref) {
        if (!self::active()) return;
        if ((self::state($code)['state'] ?? '') !== 'ok') { self::set_state($code, ['state' => 'skip', 'err' => 'Stillgelegt, bevor er bei Degaso war.']); return; }
        $job = ['op' => 'clear', 'code' => (string) $code, 'amount' => 0, 'pid' => self::pid('c', $ref)];
        if (!self::run_job($job)) self::queue_add($job);
    }

    /** Einen Auftrag ausführen. true = erledigt (auch „war schon gebucht“), false = später nochmal */
    private static function run_job(array $j) {
        $code = (string) $j['code'];
        if ($j['op'] === 'credit') {
            $r = self::call('/vouchers/topup', ['objectId' => $code, 'topupAmount' => floatval($j['amount']), 'paymentId' => $j['pid']]);
        } else {
            $amount = $j['op'] === 'pay' ? floatval($j['amount']) : self::remote_balance($code, -1);
            if ($amount < 0) return false;
            if ($amount <= 0) return true;
            $r = self::call('/vouchers/payment', ['objectIds' => [$code], 'totalRedeemAmount' => $amount, 'paymentId' => $j['pid'], 'orderId' => (string) ($j['ref'] ?? '')]);
            if (!$r['ok'] && $r['reason'] === 'ERROR_VALUE_CHANGED' && $j['op'] === 'pay') {
                $rest = self::remote_balance($code, 0);
                if ($rest > 0) self::call('/vouchers/payment', ['objectIds' => [$code], 'totalRedeemAmount' => $rest, 'paymentId' => $j['pid'] . '-rest']);
                self::log('conflict', $code, 'Nachgebucht ' . ($j['ref'] ?? '') . ': ' . self::eur($j['amount']) . ' angerechnet, bei Degaso waren nur noch ' . self::eur($rest) . ' drauf.');
                return true;
            }
        }
        if ($r['ok'] || $r['reason'] === 'ERROR_PAYMENT_ID_USED') {
            $v = $r['ok'] ? self::remote_voucher($r, $code) : null;
            $st = self::state($code);
            self::set_state($code, ['seen' => self::seen_add($st, $j['pid'])] + ($v ? ['remote' => round(floatval($v['object']['balance'] ?? 0), 2)] : []));
            self::touch_ok();
            return true;
        }
        if ($r['reason'] !== 'network') {
            self::log('error', $code, ($j['op'] === 'credit' ? 'Rückbuchung' : 'Abbuchung') . ' bei Degaso: ' . self::fail_msg($r['reason']));
            return $r['reason'] === 'ERROR_NOT_FOUND' || $r['reason'] === 'ERROR_INACTIVE'; // nicht endlos wiederholen
        }
        return false;
    }

    // ═══════════════ Abgleich Degaso → Tixomat ═══════════════

    /** Aktion tix_giftcard_refresh: Saldo eines Gutscheins live holen (höchstens alle 20 s) */
    public static function refresh($code) {
        if (!self::active() || (self::state($code)['state'] ?? '') !== 'ok') return;
        $t = 'tix_dg_r_' . md5(strtoupper((string) $code));
        if (get_transient($t)) return;
        set_transient($t, 1, 20);
        $g = self::call('/vouchers/get', ['objectIds' => [(string) $code], 'includeOrigin' => true]);
        $v = $g['ok'] ? self::remote_voucher($g, (string) $code) : null;
        if ($v) self::apply_remote($v);
    }

    /** Cron: Warteschlange, ausstehende Gutscheine, Abgleich */
    public static function cron() {
        if (!self::active()) return;
        self::run_queue();
        self::push_pending();
        self::pull();
    }

    public static function run_queue() {
        $q = get_option(self::QUEUE, []);
        if (!is_array($q) || !$q) return 0;
        $keep = [];
        foreach ($q as $j) {
            if (self::run_job($j)) continue;
            $j['tries'] = intval($j['tries'] ?? 0) + 1;
            $j['err'] = 'Degaso nicht erreichbar';
            if ($j['tries'] === 12) self::log('error', $j['code'], 'Buchung ' . $j['pid'] . ' hängt seit 2 Stunden – Degaso nicht erreichbar.');
            $keep[] = $j;
        }
        update_option(self::QUEUE, $keep, false);
        return count($q) - count($keep);
    }

    public static function push_pending() {
        $all = get_option(self::STATE, []);
        $n = 0;
        foreach ((array) $all as $code => $st) {
            if (($st['state'] ?? '') !== 'pending' || $n >= 50) continue;
            if (self::push($code, (string) ($st['kind'] ?? 'o'), (string) ($st['ref'] ?? '')) === true) $n++;
        }
        return $n;
    }

    /** Geänderte Gutscheine seit dem letzten Abgleich holen und übernehmen */
    public static function pull() {
        $s = self::settings();
        $from = $s['last_pull'] !== '' ? gmdate('Y-m-d\TH:i:s\Z', strtotime($s['last_pull']) - 7200) : gmdate('Y-m-d\TH:i:s\Z', time() - 86400 * 400);
        $started = gmdate('Y-m-d H:i:s');
        $r = self::call('/vouchers/list', ['from' => $from, 'includeOrigin' => true]);
        if (!$r['ok']) return -1;
        $n = 0;
        foreach ((array) ($r['data']['vouchers'] ?? []) as $v) {
            if (is_array($v) && (self::state((string) ($v['objectId'] ?? ''))['state'] ?? '') === 'ok' && self::apply_remote($v)) $n++;
        }
        $s = self::settings();
        $s['last_pull'] = $started;
        self::save($s);
        self::touch_ok();
        return $n;
    }

    /**
     * Stand von Degaso in den Tixomat-Gutschein übernehmen: neue fremde Verlaufseinträge (Bar) als Kanal `degaso`,
     * Saldo = Degaso. Was der Verlauf (nur 10 Einträge) nicht erklärt, wird als Abgleich gebucht. → true, wenn geändert.
     */
    public static function apply_remote(array $v) {
        global $wpdb;
        $code = (string) ($v['objectId'] ?? '');
        if ($code === '' || !TIX_Giftcards::get_card($code)) return false;
        $remote = round(floatval($v['object']['balance'] ?? 0), 2);
        $lock = 'tix_gc_' . md5(strtoupper($code));
        $locked = isset($wpdb) ? intval($wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock))) : 1;
        if (!$locked) return false;
        try {
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('tix_coupons', 'options');
            $coupons = get_option('tix_coupons', []);
            $key = null;
            foreach ((array) $coupons as $k => $c) if (strcasecmp((string) $k, $code) === 0) { $key = $k; break; }
            if ($key === null) return false;
            $st = self::state($code);
            $seen = (array) ($st['seen'] ?? []);
            $local = round(floatval($coupons[$key]['balance'] ?? 0), 2);
            $changed = false;
            $run = $local;
            foreach ((array) ($v['object']['history'] ?? []) as $h) {
                $p = (string) ($h['paymentId'] ?? '');
                $hk = $p . '|' . ($h['timestamp'] ?? '');
                if (self::ours($p) || in_array($hk, $seen, true)) continue;
                $amt = round(-floatval($h['amount'] ?? 0), 2); // Degaso: Einlösung negativ → bei uns positiv
                $o = (array) ($h['origin'] ?? []);
                $where = trim(($o['cashName'] ?? '') . (isset($o['checkoutNumber']) ? ' #' . $o['checkoutNumber'] : ''));
                $run = round($run - $amt, 2);
                $coupons[$key]['redemptions'][] = array_filter([
                    'id' => strtolower(wp_generate_password(12, false)),
                    'ts' => !empty($h['timestamp']) ? get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($h['timestamp']))) : current_time('mysql'),
                    'amount' => $amt, 'channel' => 'degaso', 'ref' => 'degaso:' . ($where !== '' ? $where : $p),
                    'by_name' => (string) ($o['employee'] ?? ''), 'rest' => max(0, $run),
                ], function ($x) { return $x !== ''; });
                $seen[] = $hk;
                $changed = true;
            }
            if (abs($run - $remote) > 0.004) {
                $coupons[$key]['redemptions'][] = ['id' => strtolower(wp_generate_password(12, false)), 'ts' => current_time('mysql'),
                    'amount' => round($run - $remote, 2), 'channel' => 'degaso', 'ref' => 'degaso:abgleich', 'rest' => $remote];
                $changed = true;
            }
            if (abs($local - $remote) > 0.004) { $coupons[$key]['balance'] = $remote; $changed = true; }
            if ($changed) update_option('tix_coupons', $coupons);
            self::set_state($code, ['remote' => $remote, 'synced_at' => current_time('mysql'), 'seen' => array_slice($seen, -40),
                'active' => !isset($v['object']['active']) || !empty($v['object']['active'])]);
            if ($changed && $remote <= 0 && !empty($coupons[$key]['ticket_id']) && class_exists('TIX_Tickets') && method_exists('TIX_Tickets', 'checkin_ticket')) {
                $tid = intval($coupons[$key]['ticket_id']);
                if (!get_post_meta($tid, '_tix_ticket_checked_in', true)) TIX_Tickets::checkin_ticket($tid, 'giftcard-degaso');
            }
            return $changed;
        } finally {
            if (isset($wpdb)) $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    // ═══════════════ Ablauf ═══════════════

    /** Täglich: abgelaufene Gutscheine – nur mit expiry_action = clear wird der Rest bei Degaso abgebucht */
    public static function daily() {
        if (!self::active() || self::settings()['expiry_action'] !== 'clear') return 0;
        $today = current_time('Y-m-d');
        $n = 0;
        foreach ((array) get_option('tix_coupons', []) as $code => $c) {
            if (!is_array($c) || ($c['discount_type'] ?? '') !== 'giftcard' || empty($c['expires']) || $c['expires'] >= $today) continue;
            $st = self::state($code);
            if (($st['state'] ?? '') !== 'ok' || !empty($st['expired_at'])) continue;
            $rest = self::remote_balance((string) $code, -1);
            if ($rest < 0) continue;
            if ($rest > 0) {
                $r = self::call('/vouchers/payment', ['objectIds' => [(string) $code], 'totalRedeemAmount' => $rest, 'paymentId' => self::pid('x', $code)]);
                if (!$r['ok'] && $r['reason'] !== 'ERROR_PAYMENT_ID_USED') continue;
                self::expire_local((string) $code, $rest);
            }
            self::set_state((string) $code, ['expired_at' => current_time('mysql'), 'remote' => 0.0, 'seen' => self::seen_add($st, self::pid('x', $code))]);
            $n++;
        }
        return $n;
    }

    private static function expire_local($code, $rest) {
        wp_cache_delete('tix_coupons', 'options');
        $coupons = get_option('tix_coupons', []);
        foreach ($coupons as $k => &$c) {
            if (strcasecmp((string) $k, $code) !== 0) continue;
            $c['redemptions'][] = ['id' => strtolower(wp_generate_password(12, false)), 'ts' => current_time('mysql'), 'amount' => round($rest, 2), 'channel' => 'expired', 'ref' => 'degaso:ablauf', 'rest' => 0];
            $c['balance'] = 0;
        }
        unset($c);
        update_option('tix_coupons', $coupons);
    }

    // ═══════════════ Altbestand ═══════════════

    /** Gutscheine mit Restguthaben, die noch nicht bei Degaso sind (nicht abgelaufen) */
    public static function import_candidates() {
        $today = current_time('Y-m-d');
        $out = [];
        foreach ((array) get_option('tix_coupons', []) as $code => $c) {
            if (!is_array($c) || ($c['discount_type'] ?? '') !== 'giftcard' || floatval($c['balance'] ?? 0) <= 0) continue;
            if (!empty($c['expires']) && $c['expires'] < $today) continue;
            if (in_array(self::state($code)['state'] ?? '', ['ok', 'conflict'], true)) continue;
            $out[] = ['code' => (string) $code, 'balance' => round(floatval($c['balance']), 2), 'expires' => (string) ($c['expires'] ?? '')];
        }
        return $out;
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $perm = [__CLASS__, 'check_admin'];
        register_rest_route(self::NS, '/degaso/admin', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $perm],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $perm],
        ]);
        register_rest_route(self::NS, '/degaso/admin/check', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_check'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, '/degaso/admin/sync', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_sync'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, '/degaso/admin/import', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_import'], 'permission_callback' => $perm]);
    }

    /** Einzel-Seite: wer Gutscheine einstellen darf (Inhaber, Team-Admin, Admin); Mehr-Veranstalter-Modus nur Admins */
    public static function check_admin(WP_REST_Request $req) {
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            return current_user_can('manage_options') ? true : new WP_Error('rest_forbidden', 'Die Kassen-Anbindung richtet nur das Plattform-Team ein.', ['status' => 403]);
        }
        if (current_user_can('manage_options')) return true;
        return class_exists('TIX_Wallet_Balance') ? TIX_Wallet_Balance::check_manager($req) : new WP_Error('rest_forbidden', 'Keine Berechtigung.', ['status' => 403]);
    }

    private static function out($data, $status = 200) {
        $r = new WP_REST_Response($data, $status);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    public static function payload() {
        $s = self::settings();
        $states = (array) get_option(self::STATE, []);
        $count = ['ok' => 0, 'pending' => 0, 'conflict' => 0];
        $problems = [];
        foreach ($states as $code => $st) {
            $k = $st['state'] ?? '';
            if (isset($count[$k])) $count[$k]++;
            if (in_array($k, ['pending', 'conflict'], true) && count($problems) < 20) $problems[] = ['code' => (string) $code, 'state' => $k, 'message' => (string) ($st['err'] ?? '')];
        }
        $queue = (array) get_option(self::QUEUE, []);
        return [
            'ok' => true,
            'settings' => [
                'enabled' => !empty($s['enabled']), 'test_mode' => !empty($s['test_mode']), 'bar_redeem' => !empty($s['bar_redeem']),
                'expiry_action' => $s['expiry_action'] === 'clear' ? 'clear' : 'none',
            ],
            'key' => ['set' => self::decrypt($s['api_key']) !== '', 'last4' => self::last4($s['api_key'])],
            'test_key' => ['set' => self::decrypt($s['test_api_key']) !== '', 'last4' => self::last4($s['test_api_key'])],
            'active' => self::active(),
            'status' => [
                'synced' => $count['ok'], 'pending' => $count['pending'], 'conflicts' => $count['conflict'], 'queue' => count($queue),
                'import_open' => count(self::import_candidates()), 'last_sync' => (string) $s['last_pull'] !== '' ? get_date_from_gmt($s['last_pull']) : '',
                'last_ok' => (string) $s['last_ok'], 'connected_at' => (string) $s['connected_at'],
            ],
            'problems' => $problems,
            'log' => array_slice((array) get_option(self::LOG, []), 0, 20),
        ];
    }

    public static function rest_get(WP_REST_Request $req) {
        return self::out(self::payload());
    }

    public static function rest_save(WP_REST_Request $req) {
        $s = self::settings();
        $p = $req->get_json_params();
        if (!is_array($p)) $p = $req->get_params();
        foreach (['api_key' => 'api_key', 'test_api_key' => 'test_api_key'] as $in => $field) {
            if (!array_key_exists($in, $p) || trim((string) $p[$in]) === '') continue;
            $k = trim((string) $p[$in]);
            if (!preg_match('/^[A-Za-z0-9-]{8,80}$/', $k)) return new WP_Error('tix_invalid_field', 'Der Schlüssel sieht nicht richtig aus (Buchstaben, Ziffern und Bindestriche).', ['status' => 400, 'field' => $in]);
            $c = self::call('/vouchers/list', ['onlyCodes' => true], $k);
            if (!$c['ok']) return new WP_Error('tix_degaso_check', self::fail_msg($c['reason']) . ' Der Schlüssel wurde nicht gespeichert.', ['status' => 409, 'field' => $in]);
            $s[$field] = self::encrypt($k);
            if ($field === 'api_key') $s['connected_at'] = current_time('mysql');
        }
        if (!empty($p['clear_key'])) $s['api_key'] = '';
        if (!empty($p['clear_test_key'])) $s['test_api_key'] = '';
        foreach (['enabled', 'test_mode', 'bar_redeem'] as $b) if (array_key_exists($b, $p)) $s[$b] = filter_var($p[$b], FILTER_VALIDATE_BOOLEAN);
        if (array_key_exists('expiry_action', $p)) $s['expiry_action'] = $p['expiry_action'] === 'clear' ? 'clear' : 'none';
        $was_test = !empty(self::settings()['test_mode']);
        if ($s['test_mode'] !== $was_test) { $s['last_pull'] = ''; delete_option(self::STATE); delete_option(self::QUEUE); } // anderer Betrieb: neu zuordnen
        self::save($s);
        if (self::active() && !wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 60, 'tix_10min', self::CRON);
        return self::out(self::payload());
    }

    public static function rest_check(WP_REST_Request $req) {
        $k = trim((string) ($req->get_param('api_key') ?? ''));
        if ($k === '') {
            $s = self::settings();
            $k = self::decrypt(filter_var($req->get_param('test'), FILTER_VALIDATE_BOOLEAN) ? $s['test_api_key'] : $s['api_key']);
        }
        if ($k === '') return new WP_Error('tix_degaso_nokey', 'Es ist noch kein Schlüssel eingetragen.', ['status' => 400, 'field' => 'api_key']);
        $c = self::call('/vouchers/list', ['onlyCodes' => true], $k);
        if (!$c['ok']) return self::out(['ok' => false, 'message' => self::fail_msg($c['reason']), 'reason' => $c['reason']], 200);
        $codes = (array) ($c['data']['objectIds'] ?? []);
        $ours = count(array_filter($codes, function ($x) { return stripos((string) $x, 'GS-') === 0; }));
        return self::out(['ok' => true, 'message' => 'Verbindung steht.', 'vouchers' => intval($c['data']['total'] ?? count($codes)), 'ours' => $ours]);
    }

    public static function rest_sync(WP_REST_Request $req) {
        if (!self::active()) return new WP_Error('tix_degaso_off', 'Die Degaso-Anbindung ist aus.', ['status' => 409]);
        $q = self::run_queue();
        $p = self::push_pending();
        $n = self::pull();
        if ($n < 0) return new WP_Error('tix_degaso_check', self::fail_msg('network'), ['status' => 502]);
        return self::out(['done' => ['queue' => $q, 'pushed' => $p, 'updated' => $n]] + self::payload());
    }

    public static function rest_import(WP_REST_Request $req) {
        if (!self::active()) return new WP_Error('tix_degaso_off', 'Die Degaso-Anbindung ist aus.', ['status' => 409]);
        $list = self::import_candidates();
        if (filter_var($req->get_param('dry_run'), FILTER_VALIDATE_BOOLEAN)) {
            return self::out(['ok' => true, 'dry_run' => true, 'count' => count($list), 'sum' => round(array_sum(array_column($list, 'balance')), 2), 'list' => array_slice($list, 0, 50)]);
        }
        $done = 0; $failed = [];
        foreach (array_slice($list, 0, 200) as $c) {
            $r = self::push($c['code'], 'i', $c['code']);
            if ($r === true) $done++; else $failed[] = ['code' => $c['code'], 'message' => $r];
        }
        return self::out(['ok' => true, 'imported' => $done, 'failed' => $failed, 'left' => max(0, count($list) - 200)] + self::payload());
    }
}

TIX_Degaso::init();
