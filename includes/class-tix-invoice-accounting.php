<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Invoice_Accounting – Belege an die Buchhaltung übergeben (1.38.500, Plan Rechnungen Phase 4).
 *
 * Die Rechnung stellt immer der eigene Kern aus (ein Nummernkreis); Lexoffice bekommt sie als fertigen Buchungsbeleg
 * (Verkaufsbeleg bzw. Gutschrift mit Beträgen je Steuersatz) samt PDF (ZUGFeRD) – kein zweiter Nummernkreis.
 *
 * Einstellung je Aussteller (`accounting` in tix_invoice_config bzw. _tix_invoice_config am Veranstalter):
 *   {provider: none|lexoffice, key (verschlüsselt, AES-256-GCM wie die IBAN), use_site_key (nur Einzel-Seite: Schlüssel aus
 *    Tixomat → Rechnungen/Lexoffice), category_id (Lexoffice-Kategorie „Einnahmen“, Vorgabe siehe CATEGORY), since (Datum)}
 * Übergabe im Hintergrund nach dem Anlegen (tix_invoice_created), Wiederholung stündlich bis 5 Versuche; Status in
 * tix_invoices.export_* (pending | done | error | skipped). Belege ohne USt (B/BK, Mehrzweck-Gutschein) werden nicht
 * übergeben (skipped) – Buchung mit dem Steuerberater klären.
 */
class TIX_Invoice_Accounting {

    const API = 'https://api.lexoffice.io/v1';
    const CATEGORY = '8f8664a8-fd86-11e1-a21f-0800200c9a66'; // Lexoffice: Einnahmen (Verkaufsbelege)
    const HOOK = 'tix_invoice_export';
    const MAX_TRIES = 5;

    public static function init() {
        add_action('tix_invoice_created', [__CLASS__, 'on_created'], 20, 3);
        add_action(self::HOOK, [__CLASS__, 'export']);
        add_action(TIX_Invoices::CRON, [__CLASS__, 'retry']);
    }

    // ═══════════════ Einstellung ═══════════════

    public static function settings($issuer_key) {
        $c = (array) (TIX_Invoices::config($issuer_key)['accounting'] ?? []);
        return [
            'provider' => in_array($c['provider'] ?? '', ['lexoffice'], true) ? $c['provider'] : 'none',
            'key' => (string) ($c['key'] ?? ''),
            'use_site_key' => !empty($c['use_site_key']),
            'category_id' => preg_match('/^[0-9a-f-]{36}$/', (string) ($c['category_id'] ?? '')) ? $c['category_id'] : self::CATEGORY,
            'since' => (string) ($c['since'] ?? ''),
        ];
    }

    private static function site_key() {
        return class_exists('TIX_Invoicing') ? trim((string) (TIX_Invoicing::get_settings('lexoffice')['api_key'] ?? '')) : '';
    }

    public static function api_key($issuer_key) {
        $s = self::settings($issuer_key);
        if ($s['use_site_key'] && strpos($issuer_key, 'org:') !== 0) return self::site_key();
        return $s['key'] !== '' && class_exists('TIX_Payout_Details') ? (string) TIX_Payout_Details::decrypt($s['key']) : '';
    }

    /** Für GET /studio/invoice-config (nie den Schlüssel selbst). */
    public static function payload($issuer_key) {
        $s = self::settings($issuer_key);
        $k = self::api_key($issuer_key);
        return ['provider' => $s['provider'], 'key_set' => $k !== '', 'key_last4' => $k !== '' ? substr($k, -4) : null,
            'use_site_key' => $s['use_site_key'], 'site_key_available' => strpos($issuer_key, 'org:') !== 0 && self::site_key() !== '',
            'category_id' => $s['category_id'], 'since' => $s['since'] ?: null];
    }

    /**
     * Aus POST /studio/invoice-config {accounting: {provider, key?, use_site_key?, category_id?}} → neue Einstellung oder WP_Error.
     * Ein neuer Schlüssel wird bei Lexoffice geprüft (GET /profile).
     */
    public static function apply($issuer_key, array $cur, $in) {
        if (!is_array($in)) return new WP_Error('tix_invalid', 'accounting: Objekt erwartet.', ['status' => 400, 'field' => 'accounting']);
        $c = (array) ($cur['accounting'] ?? []);
        if (array_key_exists('provider', $in)) {
            if (!in_array($in['provider'], ['none', 'lexoffice'], true)) return new WP_Error('tix_invalid', 'accounting.provider: none oder lexoffice.', ['status' => 400, 'field' => 'accounting.provider']);
            if (($c['provider'] ?? 'none') !== $in['provider'] && $in['provider'] !== 'none') $c['since'] = current_time('Y-m-d');
            $c['provider'] = $in['provider'];
        }
        if (array_key_exists('use_site_key', $in)) {
            if (strpos($issuer_key, 'org:') === 0 && !empty($in['use_site_key'])) return new WP_Error('tix_invalid', 'Veranstalter tragen ihren eigenen Schlüssel ein.', ['status' => 400, 'field' => 'accounting.use_site_key']);
            $c['use_site_key'] = !empty($in['use_site_key']) ? 1 : 0;
        }
        if (array_key_exists('category_id', $in)) {
            $v = trim((string) $in['category_id']);
            if ($v !== '' && !preg_match('/^[0-9a-f-]{36}$/', $v)) return new WP_Error('tix_invalid', 'accounting.category_id: Lexoffice-Kategorie-ID (UUID).', ['status' => 400, 'field' => 'accounting.category_id']);
            if ($v === '') unset($c['category_id']); else $c['category_id'] = $v;
        }
        if (array_key_exists('key', $in)) {
            $k = trim((string) $in['key']);
            if ($k === '') { unset($c['key']); }
            else {
                $chk = self::request('GET', '/profile', $k);
                if (is_wp_error($chk)) return new WP_Error('tix_accounting_key', 'Lexoffice lehnt den Schlüssel ab: ' . $chk->get_error_message(), ['status' => 400, 'field' => 'accounting.key']);
                $c['key'] = TIX_Payout_Details::encrypt($k);
                $c['use_site_key'] = 0;
            }
        }
        $cur['accounting'] = $c;
        return $cur;
    }

    // ═══════════════ Übergabe ═══════════════

    public static function on_created($id, $kind, $order_id) {
        global $wpdb;
        $inv = TIX_Invoices::get($id);
        if (!$inv) return;
        $s = self::settings($inv->issuer_key);
        if ($s['provider'] === 'none') return;
        $skip = !in_array($inv->series, ['R', 'RK'], true);
        $wpdb->update(TIX_Invoices::table(), ['export_provider' => $s['provider'], 'export_status' => $skip ? 'skipped' : 'pending',
            'export_error' => $skip ? 'Beleg ohne USt wird nicht übergeben' : ''], ['id' => intval($id)]);
        if (!$skip && !wp_next_scheduled(self::HOOK, [intval($id)])) wp_schedule_single_event(time() + 15, self::HOOK, [intval($id)]);
    }

    /** Stündlich: offene und fehlgeschlagene Übergaben (bis MAX_TRIES). */
    public static function retry() {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . TIX_Invoices::table() . " WHERE export_status IN ('pending','error') AND export_tries < %d ORDER BY id LIMIT 50", self::MAX_TRIES));
        foreach ($ids as $id) self::export(intval($id));
    }

    private static function iso($ymd_his) {
        $ts = strtotime((string) $ymd_his);
        return wp_date('Y-m-d\T00:00:00.000P', $ts ?: time());
    }

    /** Beleg an Lexoffice übergeben. @return true|WP_Error */
    public static function export($id) {
        global $wpdb;
        $inv = TIX_Invoices::get($id);
        if (!$inv || !in_array($inv->series, ['R', 'RK'], true)) return new WP_Error('tix_export_skip', 'Nicht übergebbar.');
        if ($inv->export_status === 'done') return true;
        $s = self::settings($inv->issuer_key);
        $key = self::api_key($inv->issuer_key);
        $fail = function ($msg) use ($wpdb, $inv) {
            $wpdb->query($wpdb->prepare("UPDATE " . TIX_Invoices::table() . " SET export_status = 'error', export_error = %s, export_tries = export_tries + 1, export_at = %s WHERE id = %d", mb_substr($msg, 0, 250), current_time('mysql'), intval($inv->id)));
            return new WP_Error('tix_export_failed', $msg);
        };
        if ($s['provider'] !== 'lexoffice') return $fail('Keine Buchhaltung eingerichtet.');
        if ($key === '') return $fail('Kein Lexoffice-Schlüssel hinterlegt.');
        $d = json_decode((string) $inv->data, true);
        $credit = $inv->series === 'RK';
        $items = [];
        foreach ((array) ($d['taxes'] ?? []) as $t) {
            $items[] = ['amount' => round(abs(floatval($t['gross'])), 2), 'taxAmount' => round(abs(floatval($t['tax'])), 2),
                'taxRatePercent' => $inv->small_business ? 0 : floatval($t['rate'] ?? 0), 'categoryId' => $s['category_id']];
        }
        $body = [
            'type' => $credit ? 'salescreditnote' : 'salesinvoice',
            'voucherNumber' => (string) $inv->number,
            'voucherDate' => self::iso($inv->created),
            'shippingDate' => self::iso($inv->service_date ?: $inv->created),
            'totalGrossAmount' => round(abs(floatval($inv->gross)), 2),
            'totalTaxAmount' => round(abs(floatval($inv->tax)), 2),
            'taxType' => 'gross',
            'useCollectiveContact' => true,
            'voucherItems' => $items,
            'remark' => mb_substr('Bestellung ' . $inv->order_number . ($credit && !empty($d['ref']['number']) ? ' · Korrektur zu ' . $d['ref']['number'] : '') . ' · ' . ($d['recipient']['name'] ?? ''), 0, 250),
        ];
        // schon angelegt (z. B. Abbruch beim Datei-Upload)? Dann nur die Datei nachreichen
        $vid = (string) $inv->export_ref;
        if ($vid === '') {
            $r = self::request('POST', '/vouchers', $key, $body);
            if (is_wp_error($r)) return $fail($r->get_error_message());
            $vid = (string) ($r['id'] ?? '');
            if ($vid === '') return $fail('Lexoffice hat keine Beleg-ID geliefert.');
            $wpdb->update(TIX_Invoices::table(), ['export_ref' => $vid], ['id' => intval($inv->id)]);
        }
        list($pdf, $name) = TIX_Invoices::pdf($inv);
        if ($pdf) {
            $u = self::upload($vid, $key, $pdf, $name);
            if (is_wp_error($u)) return $fail('Beleg angelegt, PDF fehlt: ' . $u->get_error_message());
        }
        $wpdb->update(TIX_Invoices::table(), ['export_status' => 'done', 'export_error' => '', 'export_at' => current_time('mysql')], ['id' => intval($inv->id)]);
        return true;
    }

    /** HTTP an Lexoffice; → array (JSON) | WP_Error mit lesbarer Meldung. */
    public static function request($method, $path, $key, array $body = null) {
        $args = ['method' => $method, 'timeout' => 20, 'headers' => ['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json']];
        if ($body !== null) { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = wp_json_encode($body); }
        $r = wp_remote_request(self::API . $path, $args);
        return self::result($r, $key);
    }

    private static function result($r, $key) {
        if (is_wp_error($r)) return new WP_Error('tix_lexoffice', 'Verbindung: ' . $r->get_error_message());
        $code = intval(wp_remote_retrieve_response_code($r));
        $data = json_decode((string) wp_remote_retrieve_body($r), true);
        if ($code >= 200 && $code < 300) return is_array($data) ? $data : [];
        $msg = is_array($data) ? (string) ($data['message'] ?? ($data['IssueList'][0]['i18nKey'] ?? '')) : '';
        if (is_array($data) && !empty($data['IssueList'])) {
            $parts = [];
            foreach ((array) $data['IssueList'] as $i) $parts[] = trim(($i['source'] ?? '') . ' ' . ($i['i18nKey'] ?? ''));
            $msg = implode('; ', $parts);
        }
        $msg = str_replace($key, '***', $msg);
        if ($code === 401 || $code === 403) return new WP_Error('tix_lexoffice_auth', 'Schlüssel ungültig oder ohne Berechtigung (' . $code . ').');
        if ($code === 429) return new WP_Error('tix_lexoffice_limit', 'Zu viele Anfragen bei Lexoffice – wird später wiederholt.');
        return new WP_Error('tix_lexoffice', 'Lexoffice ' . $code . ($msg !== '' ? ': ' . mb_substr($msg, 0, 180) : ''));
    }

    /** PDF zum Beleg hochladen (multipart, POST /vouchers/{id}/files). */
    private static function upload($voucher_id, $key, $bytes, $name) {
        $b = 'tix' . wp_generate_password(16, false, false);
        $body = "--$b\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . str_replace('"', '', $name) . "\"\r\nContent-Type: application/pdf\r\n\r\n" . $bytes . "\r\n"
            . "--$b\r\nContent-Disposition: form-data; name=\"type\"\r\n\r\nvoucher\r\n--$b--\r\n";
        $r = wp_remote_post(self::API . '/vouchers/' . rawurlencode($voucher_id) . '/files', ['timeout' => 30, 'body' => $body,
            'headers' => ['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json', 'Content-Type' => 'multipart/form-data; boundary=' . $b]]);
        $res = self::result($r, $key);
        return is_wp_error($res) ? $res : true;
    }
}
