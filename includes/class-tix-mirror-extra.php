<?php
/**
 * Tixomat – Datenspiegel Paket 2: Veranstalter, Gutscheine/Geschenkkarten, Guthaben, Abrechnungen
 * (Gästelisten hängen am Event, Prämien am Kunden – siehe TIX_Mirror_Map).
 *
 * Erfassen:
 *  - Veranstalter: Post-Meta/save_post von tix_organizer (TIX_Mirror_Hooks)
 *  - Gutscheine: Option tix_coupons (ein Array für alle Codes) → ein Eintrag "coupons|all";
 *    gesendet werden nur geänderte Codes (Prüfsummen in Option tix_mirror_coupon_fp)
 *  - Guthaben und Abrechnungen schreiben per $wpdb ohne Hook → Nachlauf in TIX_Mirror::sweep()
 *    über updated (Geldbörsen GMT, Abrechnungen Ortszeit) bzw. neue IDs (Buchungen, Positionen)
 * IBAN: nie im Klartext. Mit TIX_MIRROR_ENC_KEY (wp-config) wird sie für den Spiegel mit eigenem
 * Schlüssel verschlüsselt (m1:…, lässt sich ohne die Seite wiederherstellen), sonst bleibt der
 * Wert der Seite (v1:…, nur mit den Salts der Seite lesbar).
 *
 * @since Datenspiegel Paket 2 (Version vergibt die Zentrale)
 */

if (!defined('ABSPATH')) exit;

class TIX_Mirror_Extra {

    const COUPON_FP = 'tix_mirror_coupon_fp';

    public static function plan($entity, $id, $op, $payload) {
        $site = TIX_Mirror::site();
        switch ($entity) {
            case 'organizer':       return self::plan_organizer($site, (int) $id);
            case 'coupons':         return self::plan_coupons($site);
            case 'wallet':          return self::plan_wallet($site, (int) $id);
            case 'wallet_tx':       return self::plan_wallet_tx($site, (int) $id);
            case 'settlement':      return self::plan_settlement($site, (int) $id);
            case 'settlement_item': return self::plan_settlement_item($site, (int) $id);
        }
        return null;
    }

    private static function del($table, $site, $id) {
        return ['delete', $table, ['site' => 'eq.' . $site, 'id' => 'eq.' . $id]];
    }

    private static function gmt_iso($v) {
        $v = trim((string) $v);
        if ($v === '' || strpos($v, '0000-00-00') === 0) return null;
        return gmdate('c', strtotime($v . ' UTC'));
    }

    private static function local_iso($v) {
        $v = trim((string) $v);
        if ($v === '' || strpos($v, '0000-00-00') === 0) return null;
        try { return (new DateTimeImmutable($v, wp_timezone()))->setTimezone(new DateTimeZone('UTC'))->format('c'); } catch (\Throwable $e) { return null; }
    }

    private static function num($v) { return ($v === null || $v === '' || !is_numeric($v)) ? null : round((float) $v, 2); }
    private static function idn($v) { $v = (int) $v; return $v > 0 ? $v : null; }

    // ═══════════════ IBAN ═══════════════

    /** Wert der Seite (v1:… oder Klartext) → Wert für den Spiegel. Nie Klartext. */
    public static function iban_for_mirror($value) {
        $value = (string) $value;
        if ($value === '') return '';
        $plain = strpos($value, 'v1:') === 0
            ? (class_exists('TIX_Payout_Details') ? TIX_Payout_Details::decrypt($value) : '')
            : preg_replace('/\s+/', '', $value);
        if (defined('TIX_MIRROR_ENC_KEY') && TIX_MIRROR_ENC_KEY !== '' && $plain !== '' && function_exists('openssl_encrypt')) {
            $key = hash('sha256', 'tix-mirror-iban|' . TIX_MIRROR_ENC_KEY, true);
            $iv = random_bytes(12);
            $tag = '';
            $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($ct !== false) return 'm1:' . base64_encode($iv . $tag . $ct);
        }
        // Ohne Spiegel-Schlüssel: nur der verschlüsselte Wert der Seite, nie Klartext.
        return strpos($value, 'v1:') === 0 ? $value : (class_exists('TIX_Payout_Details') ? TIX_Payout_Details::encrypt($plain) : '');
    }

    /** Gegenstück für Wiederherstellung/Tests (nur m1:). */
    public static function iban_from_mirror($enc) {
        if (!defined('TIX_MIRROR_ENC_KEY') || strpos((string) $enc, 'm1:') !== 0) return '';
        $bin = base64_decode(substr($enc, 3), true);
        if ($bin === false || strlen($bin) < 29) return '';
        $key = hash('sha256', 'tix-mirror-iban|' . TIX_MIRROR_ENC_KEY, true);
        $pt = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        return $pt === false ? '' : $pt;
    }

    // ═══════════════ Veranstalter ═══════════════

    private static function plan_organizer($site, $id) {
        $p = get_post($id);
        if (!$p || $p->post_type !== 'tix_organizer' || $p->post_status === 'auto-draft') return [self::del('organizers', $site, $id)];
        $g = function ($k) use ($id) { return get_post_meta($id, $k, true); };
        $pd = $g('_tix_payout_data');
        $pd = is_array($pd) ? $pd : [];
        $iban_enc = (string) $g('_tix_payout_iban');
        $plain = $iban_enc !== '' && class_exists('TIX_Payout_Details') ? TIX_Payout_Details::decrypt($iban_enc) : '';
        $payout = [
            'holder'      => (string) ($pd['holder'] ?? ''),
            'bic'         => (string) ($pd['bic'] ?? ''),
            'billing'     => (object) (is_array($pd['billing'] ?? null) ? $pd['billing'] : []),
            'tax_status'  => (string) ($pd['tax_status'] ?? ''),
            'vat_id'      => (string) ($pd['vat_id'] ?? ''),
            'tax_number'  => (string) ($pd['tax_number'] ?? ''),
            'email'       => (string) ($pd['email'] ?? ''),
            'updated'     => $pd['updated'] ?? null,
            'iban_masked' => $plain !== '' && class_exists('TIX_Payout_Details') && method_exists('TIX_Payout_Details', 'mask_iban') ? TIX_Payout_Details::mask_iban($plain) : ($plain !== '' ? substr($plain, 0, 4) . ' **** ' . substr($plain, -4) : ''),
            'iban_enc'    => $iban_enc !== '' ? self::iban_for_mirror($iban_enc) : '',
        ];
        $meta = [];
        foreach ((array) get_post_meta($id) as $k => $vals) {
            if (strpos($k, '_tix_') !== 0 || TIX_Mirror_Map::is_sensitive($k) || strpos($k, '_tix_payout_') === 0) continue;
            $meta[$k] = maybe_unserialize($vals[0] ?? null);
        }
        ksort($meta);
        $row = [
            'site' => $site, 'id' => $id,
            'name'        => html_entity_decode($p->post_title, ENT_QUOTES, 'UTF-8'),
            'status'      => $p->post_status,
            'approval'    => (string) ($g('_tix_org_approval') ?: 'approved'),
            'user_id'     => self::idn($g('_tix_org_user_id') ?: $p->post_author),
            'email'       => (string) $g('_tix_org_email'),
            'phone'       => (string) $g('_tix_org_phone'),
            'city'        => (string) $g('_tix_org_city'),
            'payout'      => $payout,
            'created_at'  => self::local_iso($p->post_date),
            'modified_at' => self::local_iso($p->post_modified),
            'data'        => (object) $meta,
        ];
        return [['upsert', 'organizers', [TIX_Mirror_Map::clean($row)]]];
    }

    // ═══════════════ Gutscheine + Geschenkkarten ═══════════════

    /** Ein Gutschein als Zeile (Format auch für den Abgleich). */
    public static function coupon_row($site, $code, array $c) {
        $data = array_filter($c, function ($k) { return !TIX_Mirror_Map::is_sensitive($k); }, ARRAY_FILTER_USE_KEY);
        return TIX_Mirror_Map::clean([
            'site' => $site, 'id' => (string) $code,
            'discount_type' => (string) ($c['discount_type'] ?? ''),
            'value'         => self::num($c['value'] ?? null),
            'balance'       => self::num($c['balance'] ?? null),
            'used'          => (int) ($c['used'] ?? 0),
            'max_uses'      => isset($c['max_uses']) && $c['max_uses'] !== '' && (int) $c['max_uses'] > 0 ? (int) $c['max_uses'] : null,
            'expires'       => (string) ($c['expires'] ?? ''),
            'organizer_id'  => self::idn($c['organizer_id'] ?? 0),
            'order_id'      => self::idn($c['order_id'] ?? 0),
            'ticket_id'     => self::idn($c['ticket_id'] ?? 0),
            'data'          => (object) $data,
        ]);
    }

    /**
     * Nur geänderte Codes senden (Prüfsummen vom letzten erfolgreichen Senden); gelöschte Codes
     * im Spiegel entfernen. Ohne gemerkte Prüfsummen: alles senden und Überzähliges löschen.
     */
    private static function plan_coupons($site) {
        $all = get_option('tix_coupons', []);
        $all = is_array($all) ? $all : [];
        $prev = get_option(self::COUPON_FP, null);
        $full = !is_array($prev);
        $fp = [];
        $rows = [];
        foreach ($all as $code => $c) {
            if (!is_array($c) || (string) $code === '') continue;
            $row = self::coupon_row($site, $code, $c);
            $fp[(string) $code] = md5(wp_json_encode($row));
            if ($full || ($prev[(string) $code] ?? '') !== $fp[(string) $code]) $rows[] = $row;
        }
        $ops = [];
        if ($rows) $ops[] = ['upsert', 'coupons', $rows];
        $removed = $full ? null : array_diff(array_keys($prev), array_keys($fp));
        if ($full || $removed) $ops[] = ['prune', 'coupons', 0, array_map('strval', array_keys($fp))];
        $ops[] = ['remember', self::COUPON_FP, $fp];
        return $ops;
    }

    // ═══════════════ Guthaben ═══════════════

    private static function plan_wallet($site, $id) {
        global $wpdb;
        $w = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tix_wallets WHERE id = %d", $id), ARRAY_A);
        if (!$w) return [self::del('wallets', $site, $id)];
        return [['upsert', 'wallets', [[
            'site' => $site, 'id' => $id,
            'user_id'       => (int) $w['user_id'],
            'scope_type'    => (string) $w['scope_type'],
            'organizer_id'  => (int) $w['organizer_id'],
            'balance_paid'  => (int) $w['balance_paid'],
            'balance_bonus' => (int) $w['balance_bonus'],
            'status'        => (string) $w['status'],
            'created_at'    => self::gmt_iso($w['created']),
            'updated_at'    => self::gmt_iso($w['updated']),
        ]]]];
    }

    private static function plan_wallet_tx($site, $id) {
        global $wpdb;
        $x = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tix_wallet_tx WHERE id = %d", $id), ARRAY_A);
        if (!$x) return [self::del('wallet_transactions', $site, $id)];
        $meta = json_decode((string) $x['meta'], true);
        $meta = is_array($meta) ? array_filter($meta, function ($k) { return !TIX_Mirror_Map::is_sensitive($k); }, ARRAY_FILTER_USE_KEY) : [];
        return [['upsert', 'wallet_transactions', [TIX_Mirror_Map::clean([
            'site' => $site, 'id' => $id,
            'wallet_id'           => (int) $x['wallet_id'],
            'type'                => (string) $x['type'],
            'amount_paid'         => (int) $x['amount_paid'],
            'amount_bonus'        => (int) $x['amount_bonus'],
            'balance_after_paid'  => (int) $x['balance_after_paid'],
            'balance_after_bonus' => (int) $x['balance_after_bonus'],
            'order_id'            => self::idn($x['order_id']),
            'ref_tx_id'           => self::idn($x['ref_tx_id']),
            'staff_user_id'       => self::idn($x['staff_user_id']),
            'station'             => (string) $x['station'],
            'device_id'           => self::idn($x['device_id']),
            'organizer_id'        => (int) ($x['organizer_id'] ?? 0), // Plattform-Cashless (1.38.466, Wallet-DB 3)
            'channel'             => (string) $x['channel'],
            'idempotency_key'     => (string) $x['idempotency_key'],
            'meta'                => (object) $meta,
            'created_at'          => self::gmt_iso($x['created']),
        ])]]];
    }

    // ═══════════════ Abrechnungen ═══════════════

    private static function settlement_row($site, array $s) {
        $data = $s;
        $snap = json_decode((string) ($s['snapshot'] ?? ''), true);
        if (is_array($snap)) {
            if (!empty($snap['iban_enc'])) $snap['iban_enc'] = self::iban_for_mirror($snap['iban_enc']);
            foreach (array_keys($snap) as $k) if ($k !== 'iban_enc' && $k !== 'iban_masked' && TIX_Mirror_Map::is_sensitive($k)) unset($snap[$k]);
            $data['snapshot'] = $snap;
        } else {
            unset($data['snapshot']);
        }
        return TIX_Mirror_Map::clean([
            'site' => $site, 'id' => (int) $s['id'],
            'number'         => (string) $s['number'],
            'type'           => (string) $s['type'],
            'organizer_id'   => self::idn($s['organizer_id']),
            'event_id'       => self::idn($s['event_id']),
            'status'         => (string) $s['status'],
            'gross'          => self::num($s['gross']),
            'refunds'        => self::num($s['refunds']),
            'platform_fee'   => self::num($s['platform_fee']),
            'gateway_fees'   => self::num($s['gateway_fees']),
            'payout_amount'  => self::num($s['payout_amount']),
            'due_date'       => (string) $s['due_date'],
            'invoice_number' => (string) $s['invoice_number'],
            'paid_at'        => (string) $s['paid_at'],
            'created_at'     => self::local_iso($s['created']),
            'updated_at'     => self::local_iso($s['updated']),
            'data'           => (object) $data,
        ]);
    }

    public static function settlement_item_row($site, array $i) {
        return [
            'site' => $site, 'id' => (int) $i['id'],
            'settlement_id' => (int) $i['settlement_id'],
            'organizer_id'  => self::idn($i['organizer_id']),
            'event_id'      => self::idn($i['event_id']),
            'order_id'      => self::idn($i['order_id']),
            'type'          => (string) $i['type'],
            'amount'        => self::num($i['amount']),
            'created_at'    => self::gmt_iso($i['created']),
            'data'          => (object) TIX_Mirror_Map::clean($i),
        ];
    }

    private static function plan_settlement($site, $id) {
        global $wpdb;
        $s = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tix_settlements WHERE id = %d", $id), ARRAY_A);
        if (!$s) return [self::del('settlements', $site, $id)];
        $ops = [['upsert', 'settlements', [self::settlement_row($site, $s)]]];
        // Positionen dieser Abrechnung und offene Posten des Veranstalters; Aufräumen je Veranstalter.
        $org = (int) $s['organizer_id'];
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tix_settlement_items WHERE settlement_id = %d OR (settlement_id = 0 AND organizer_id = %d)", $id, $org
        ), ARRAY_A);
        if ($items) $ops[] = ['upsert', 'settlement_items', array_map(function ($i) use ($site) { return self::settlement_item_row($site, $i); }, $items)];
        if ($org > 0) {
            $keep = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}tix_settlement_items WHERE organizer_id = %d", $org)));
            $ops[] = ['prune', 'settlement_items', $org, $keep];
        }
        return $ops;
    }

    private static function plan_settlement_item($site, $id) {
        global $wpdb;
        $i = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tix_settlement_items WHERE id = %d", $id), ARRAY_A);
        if (!$i) return [self::del('settlement_items', $site, $id)];
        return [['upsert', 'settlement_items', [self::settlement_item_row($site, $i)]]];
    }

    // ═══════════════ Nachlauf (Tabellen ohne Hooks) ═══════════════

    /**
     * [Typ, Tabelle, Spalte, Art, ab Anfang]: time_utc (GMT-Spalte), time_local (WP-Ortszeit), id (nur anhängen).
     */
    public static function sweeps() {
        global $wpdb;
        return [
            ['wallet',          $wpdb->prefix . 'tix_wallets',          'updated', 'time_utc',   true],
            ['wallet_tx',       $wpdb->prefix . 'tix_wallet_tx',        'id',      'id',         true],
            ['settlement',      $wpdb->prefix . 'tix_settlements',      'updated', 'time_local', true],
            ['settlement_item', $wpdb->prefix . 'tix_settlement_items', 'id',      'id',         true],
        ];
    }
}
