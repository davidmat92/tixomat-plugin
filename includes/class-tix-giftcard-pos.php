<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Giftcard_Pos – Geschenkgutschein an Bar bzw. Kasse abbuchen, ohne Ticketkauf (1.38.496, User).
 *
 *  - GET  /pos/giftcard/{code} → {ok, code, value, balance, expires, status active|expired|used, usable, organizer_id,
 *         organizer_name, recipient_name, redemptions [{id, at, amount, channel, rest, voided}] (letzte 10)}
 *  - POST /pos/giftcard/redeem {code, amount, event_id?, idempotency_key?}
 *         → {ok, code, amount, redeemed, balance_before, balance_after, balance, value, expires, used_up, redemption_id, replay}
 *  - POST /pos/giftcard/void {code, redemption_id} → Abbuchung zurücknehmen (Kasse & Bar: nur eigene von heute)
 *  - code: was der QR enthält (GS-XXXX-XXXX), ebenso Ticket-QR GL-<event>-<ticket> bzw. Ticket-Code des Gutschein-Tickets.
 *  - Fehler: 404 tix_giftcard_unknown, 409 tix_giftcard_expired, 409 tix_giftcard_balance (zu wenig bzw. kein Rest, mit balance),
 *    409 tix_giftcard_scope (anderer Veranstalter), 400 tix_invalid_field (amount), 429 tix_rate_limit.
 *  - Rechte: TIX_REST_API::check_pos (Kasse & Bar, Personal, Veranstalter, Admin); Mehr-Veranstalter-Modus nur Gutscheine
 *    des eigenen Veranstalters (Plattform-Gutscheine nur Admin).
 *  - Protokoll: tix_coupons[code].redemptions[] {id, ts, amount, channel bar, ref pos:<login>, by, event_id, idem, rest};
 *    Storno als Eintrag mit negativem Betrag (void_of), Original bekommt voided.
 *  - Sperre wie POST /wallet/redeem-giftcard (GET_LOCK tix_gc_<md5>), damit Bar und App nicht gleichzeitig abbuchen.
 */
class TIX_Giftcard_Pos {

    const NS = 'tixomat/v1';
    const MAX_AMOUNT = 10000;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        $perm = ['TIX_REST_API', 'check_pos'];
        register_rest_route(self::NS, '/pos/giftcard/redeem', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_redeem'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, '/pos/giftcard/void', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_void'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, '/pos/giftcard/(?P<code>[A-Za-z0-9-]{3,60})', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $perm]);
    }

    private static function out($data, $status = 200) {
        $r = new WP_REST_Response($data, $status);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /** Eingabe bzw. QR-Inhalt → Gutschein oder null. */
    public static function resolve($raw) {
        $code = class_exists('TIX_Wallet_Balance') ? TIX_Wallet_Balance::giftcard_code_from_input($raw) : strtoupper(trim((string) $raw));
        return ($code !== '' && class_exists('TIX_Giftcards')) ? TIX_Giftcards::get_card($code) : null;
    }

    private static function fresh($code) {
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('tix_coupons', 'options');
        return TIX_Giftcards::get_card((string) $code);
    }

    private static function org_name($oid) {
        return $oid ? html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8') : html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
    }

    /** Mehr-Veranstalter-Modus: Gutschein muss zum Veranstalter des Personals gehören. */
    private static function scope_error($card) {
        if (!class_exists('TIX_App_Scope') || !TIX_App_Scope::multi() || current_user_can('manage_options')) return null;
        $oid = intval($card['organizer_id'] ?? 0);
        if ($oid && $oid === intval(TIX_App_Scope::organizer_id_for_user())) return null;
        return new WP_Error('tix_giftcard_scope', $oid ? 'Dieser Gutschein gilt nur bei ' . self::org_name($oid) . '.' : 'Dieser Gutschein lässt sich hier nicht einlösen.', ['status' => 409]);
    }

    private static function status($card) {
        if (!empty($card['expires']) && strtotime($card['expires'] . ' 23:59:59') < current_time('timestamp')) return 'expired';
        return round(floatval($card['balance'] ?? 0), 2) <= 0 ? 'used' : 'active';
    }

    public static function payload($card) {
        $st = self::status($card);
        $red = [];
        foreach (array_slice((array) ($card['redemptions'] ?? []), -10) as $r) {
            if (!is_array($r)) continue;
            $red[] = ['id' => isset($r['id']) ? (string) $r['id'] : null, 'at' => (string) ($r['ts'] ?? ''), 'amount' => round(floatval($r['amount'] ?? 0), 2),
                'channel' => (string) ($r['channel'] ?? ''), 'rest' => round(floatval($r['rest'] ?? 0), 2), 'voided' => !empty($r['voided'])];
        }
        $oid = intval($card['organizer_id'] ?? 0);
        $g = is_array($card['gift'] ?? null) ? $card['gift'] : [];
        return [
            'code'           => (string) $card['code'],
            'value'          => round(floatval($card['value'] ?? 0), 2),
            'balance'        => round(floatval($card['balance'] ?? 0), 2),
            'expires'        => (string) ($card['expires'] ?? ''),
            'status'         => $st,
            'usable'         => $st === 'active',
            'organizer_id'   => $oid,
            'organizer_name' => self::org_name($oid),
            'recipient_name' => ($g['recipient_name'] ?? '') !== '' ? (string) $g['recipient_name'] : null,
            'redemptions'    => array_reverse($red),
        ];
    }

    private static function limited($bucket, $max) {
        return class_exists('TIX_App_Account') && TIX_App_Account::rate_limited($bucket, $max, 600, 'u' . get_current_user_id());
    }

    /** GET /pos/giftcard/{code} */
    public static function rest_get(WP_REST_Request $req) {
        if (self::limited('pos_gc_get', 120)) return new WP_Error('tix_rate_limit', 'Zu viele Abfragen – bitte kurz warten.', ['status' => 429]);
        $card = self::resolve($req['code']);
        if (!$card) return new WP_Error('tix_giftcard_unknown', 'Diesen Gutschein kennen wir nicht. Bitte den Code prüfen.', ['status' => 404]);
        if ($e = self::scope_error($card)) return $e;
        return self::out(['ok' => true] + self::payload($card));
    }

    private static function amount($raw) {
        $v = str_replace(',', '.', trim((string) $raw));
        return is_numeric($v) ? round(floatval($v), 2) : 0.0;
    }

    /** POST /pos/giftcard/redeem {code, amount, event_id?, idempotency_key?} */
    public static function rest_redeem(WP_REST_Request $req) {
        global $wpdb;
        if (self::limited('pos_gc_redeem', 60)) return new WP_Error('tix_rate_limit', 'Zu viele Buchungen – bitte kurz warten.', ['status' => 429]);
        $amount = self::amount($req->get_param('amount'));
        if ($amount <= 0 || $amount > self::MAX_AMOUNT) return new WP_Error('tix_invalid_field', 'Bitte einen Betrag über 0 € angeben.', ['status' => 400, 'field' => 'amount']);
        $card = self::resolve($req->get_param('code'));
        if (!$card) return new WP_Error('tix_giftcard_unknown', 'Diesen Gutschein kennen wir nicht. Bitte den Code prüfen.', ['status' => 404]);
        if ($e = self::scope_error($card)) return $e;
        $idem = substr(sanitize_text_field((string) ($req->get_param('idempotency_key') ?? '')), 0, 64);
        $event_id = intval($req->get_param('event_id') ?? 0);
        $code = (string) $card['code'];
        $lock = 'tix_gc_' . md5(strtoupper($code));
        if (!intval($wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)))) return new WP_Error('tix_busy', 'Gerade viel los – bitte nochmal.', ['status' => 409]);
        try {
            $card = self::fresh($code);
            // gleiche Buchung nochmal (Doppel-Tippen, Netz weg): gleiche Antwort, nichts abbuchen
            if ($idem !== '') {
                foreach ((array) ($card['redemptions'] ?? []) as $r) {
                    if (is_array($r) && ($r['idem'] ?? '') === $idem && ($r['channel'] ?? '') === 'bar') {
                        $a = round(floatval($r['amount']), 2);
                        return self::out(['ok' => true, 'code' => $code, 'amount' => $a, 'redeemed' => $a, 'balance_before' => round(floatval($r['rest']) + $a, 2), 'balance_after' => round(floatval($r['rest']), 2),
                            'balance' => round(floatval($card['balance'] ?? 0), 2), 'value' => round(floatval($card['value'] ?? 0), 2), 'expires' => (string) ($card['expires'] ?? ''),
                            'used_up' => round(floatval($card['balance'] ?? 0), 2) <= 0, 'redemption_id' => (string) ($r['id'] ?? ''), 'replay' => true]);
                    }
                }
            }
            $st = self::status($card);
            $bal = round(floatval($card['balance'] ?? 0), 2);
            if ($st === 'expired') return new WP_Error('tix_giftcard_expired', 'Dieser Gutschein ist abgelaufen (' . date_i18n('d.m.Y', strtotime($card['expires'])) . ').', ['status' => 409, 'balance' => $bal, 'expires' => (string) $card['expires']]);
            if ($bal <= 0) return new WP_Error('tix_giftcard_balance', 'Auf diesem Gutschein ist nichts mehr drauf.', ['status' => 409, 'balance' => 0.0]);
            if ($amount > $bal + 0.001) return new WP_Error('tix_giftcard_balance', 'Auf dem Gutschein sind nur noch ' . number_format($bal, 2, ',', '.') . ' €.', ['status' => 409, 'balance' => $bal, 'field' => 'amount']);
            $u = wp_get_current_user();
            $res = TIX_Giftcards::redeem($code, $amount, 'bar', 'pos:' . $u->user_login, array_filter(['by' => intval($u->ID), 'event_id' => $event_id ?: null, 'idem' => $idem !== '' ? $idem : null], function ($v) { return $v !== null; }));
            if (empty($res['ok'])) return new WP_Error('tix_giftcard_balance', (string) $res['message'], ['status' => 409, 'balance' => round(floatval($res['balance']), 2)]);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
        return self::out(['ok' => true, 'code' => $code, 'amount' => $amount, 'redeemed' => $amount, 'balance_before' => round(floatval($res['before']), 2), 'balance_after' => round(floatval($res['balance']), 2),
            'balance' => round(floatval($res['balance']), 2), 'value' => round(floatval($res['value']), 2), 'expires' => (string) ($card['expires'] ?? ''),
            'used_up' => round(floatval($res['balance']), 2) <= 0, 'redemption_id' => (string) $res['id'], 'replay' => false]);
    }

    /** POST /pos/giftcard/void {code, redemption_id} – Bar-Abbuchung zurücknehmen. */
    public static function rest_void(WP_REST_Request $req) {
        global $wpdb;
        if (self::limited('pos_gc_void', 30)) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen – bitte kurz warten.', ['status' => 429]);
        $card = self::resolve($req->get_param('code'));
        if (!$card) return new WP_Error('tix_giftcard_unknown', 'Diesen Gutschein kennen wir nicht. Bitte den Code prüfen.', ['status' => 404]);
        if ($e = self::scope_error($card)) return $e;
        $rid = sanitize_text_field((string) ($req->get_param('redemption_id') ?? ''));
        if ($rid === '') return new WP_Error('tix_invalid_field', 'redemption_id fehlt.', ['status' => 400, 'field' => 'redemption_id']);
        $code = (string) $card['code'];
        $u = wp_get_current_user();
        $cashier = class_exists('TIX_REST_API') && TIX_REST_API::is_cashier_only($u);
        $lock = 'tix_gc_' . md5(strtoupper($code));
        if (!intval($wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)))) return new WP_Error('tix_busy', 'Gerade viel los – bitte nochmal.', ['status' => 409]);
        try {
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('tix_coupons', 'options');
            $coupons = get_option('tix_coupons', []);
            $key = null;
            foreach ((array) $coupons as $k => $c) if (strcasecmp((string) $k, $code) === 0) { $key = $k; break; }
            $idx = null;
            foreach ((array) ($coupons[$key]['redemptions'] ?? []) as $i => $r) if (is_array($r) && (string) ($r['id'] ?? '') === $rid) { $idx = $i; break; }
            if ($key === null || $idx === null) return new WP_Error('tix_not_found', 'Diese Buchung gibt es nicht.', ['status' => 404]);
            $r = $coupons[$key]['redemptions'][$idx];
            if (($r['channel'] ?? '') !== 'bar' || floatval($r['amount'] ?? 0) <= 0) return new WP_Error('tix_giftcard_void', 'Nur Abbuchungen an Bar bzw. Kasse lassen sich hier zurücknehmen.', ['status' => 409]);
            $bal = round(floatval($coupons[$key]['balance'] ?? 0), 2);
            if (!empty($r['voided'])) return self::out(['ok' => true, 'code' => (string) $key, 'amount' => round(floatval($r['amount']), 2), 'balance' => $bal, 'balance_after' => $bal, 'replay' => true]);
            if ($cashier && (intval($r['by'] ?? 0) !== intval($u->ID) || substr((string) ($r['ts'] ?? ''), 0, 10) !== current_time('Y-m-d'))) {
                return new WP_Error('tix_cashier_scope', 'Du kannst nur deine eigenen Buchungen von heute zurücknehmen.', ['status' => 403]);
            }
            $a = round(floatval($r['amount']), 2);
            $new = round(min(floatval($coupons[$key]['value'] ?? 0), $bal + $a), 2);
            $coupons[$key]['redemptions'][$idx]['voided'] = current_time('mysql');
            $coupons[$key]['redemptions'][] = ['id' => strtolower(wp_generate_password(12, false)), 'ts' => current_time('mysql'), 'amount' => -$a, 'channel' => 'bar', 'ref' => 'storno:' . $rid, 'void_of' => $rid, 'by' => intval($u->ID), 'rest' => $new];
            $coupons[$key]['balance'] = $new;
            update_option('tix_coupons', $coupons);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
        return self::out(['ok' => true, 'code' => (string) $key, 'amount' => $a, 'balance' => $new, 'balance_after' => $new, 'replay' => false]);
    }
}

TIX_Giftcard_Pos::init();
