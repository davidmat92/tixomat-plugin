<?php
/**
 * TIX_Promoter_Partner – Medien- und Vertriebspartner als Typ im Promoter-System (R1, Auftrag Marketing/Reichweite 13.11.2026;
 * kein zweites System; ab 1.38.812).
 *
 * Partner = Promoter mit kind = 'partner': Firma, Anschrift, USt-IdNr., umsatzsteuerpflichtig ja/nein (§ 19 UStG).
 * Gleiche Links, gleiches Portal, gleiche Zuordnung, gleiche Provision (nach dem Event fällig), dazu:
 *  - Provision je Event UND je Ticket-Kategorie für diesen Partner (`category_rates` an der Zuordnung, vor den
 *    Kategorie-Regeln des Events),
 *  - statt Auszahlung an eine Privatperson eine **Gutschrift** (§ 14 Abs. 2 Satz 2 UStG) zur Auszahlung:
 *    Nummer GS-<Kürzel>-<Jahr>-<NNNN> je Aussteller (Seite bzw. Veranstalter), Provision = netto, zzgl. USt (Vorgabe 19 %,
 *    Filter tix_partner_vat_rate) bzw. Hinweis § 19 UStG; Daten beim Anlegen festgeschrieben (doc_data), PDF daraus.
 *
 * Spalten (eigene Tabellen des Promoter-Systems):
 *   tix_promoters        kind, company, vat_id, address, vat_liable
 *   tix_promoter_events  category_rates (JSON {cat_index: {type, value}})
 *   tix_promoter_payouts doc_no, doc_data (JSON)
 * REST:
 *   GET /organizer/promoter-payouts/{id}/credit-note → {ok, number, filename, mime, data (Base64-PDF)} (nur Partner)
 *   GET /promoter/payouts/{id}/credit-note            → dasselbe im Partner-Portal (X-Tix-Promoter-Token, nur eigene)
 */
if (!defined('ABSPATH')) exit;

class TIX_Promoter_Partner {

    const NS = 'tixomat/v1';
    const VAT_ID_RE = '/^(AT|BE|BG|CY|CZ|DE|DK|EE|EL|ES|FI|FR|HR|HU|IE|IT|LT|LU|LV|MT|NL|PL|PT|RO|SE|SI|SK|XI|CHE)(?=[A-Z0-9+*]*\d)[A-Z0-9+*]{2,12}$/';

    public static function vat_id_ok($v) {
        if (strpos($v, 'DE') === 0) return (bool) preg_match('/^DE\d{9}$/', $v);
        return (bool) preg_match(self::VAT_ID_RE, $v);
    }

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function vat_rate() { return floatval(apply_filters('tix_partner_vat_rate', 19.0)); }

    // ═══════════════ Spalten ═══════════════

    public static function ensure_columns() {
        static $done = false;
        if ($done || !class_exists('TIX_Promoter_DB')) return;
        $done = true;
        global $wpdb;
        $add = function ($t, $col, $def) use ($wpdb) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) return;
            if (!$wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM $t LIKE %s", $col))) $wpdb->query("ALTER TABLE $t ADD COLUMN $col $def");
        };
        $p = TIX_Promoter_DB::table_promoters();
        $add($p, 'kind', "VARCHAR(10) NOT NULL DEFAULT 'person'");
        $add($p, 'company', "VARCHAR(190) NOT NULL DEFAULT ''");
        $add($p, 'vat_id', "VARCHAR(30) NOT NULL DEFAULT ''");
        $add($p, 'address', "TEXT NULL");
        $add($p, 'vat_liable', "TINYINT(1) NOT NULL DEFAULT 1");
        $add(TIX_Promoter_DB::table_events(), 'category_rates', "TEXT NULL");
        $add(TIX_Promoter_DB::table_payouts(), 'doc_no', "VARCHAR(40) NOT NULL DEFAULT ''");
        $add(TIX_Promoter_DB::table_payouts(), 'doc_data', "LONGTEXT NULL");
    }

    public static function is_partner($p) { return $p && (string) ($p->kind ?? 'person') === 'partner'; }

    // ═══════════════ Felder ═══════════════

    /** Partner-Felder aus der Anfrage → [set, WP_Error|null]; $cur = bestehender Promoter (Update) */
    public static function fields(WP_REST_Request $req, $cur = null) {
        $set = [];
        $bad = function ($f, $m) { return new WP_Error('tix_invalid_field', $m, ['status' => 400, 'field' => $f]); };
        $kind = $req->has_param('kind') ? (string) $req->get_param('kind') : (string) ($cur->kind ?? 'person');
        if (!in_array($kind, ['person', 'partner'], true)) return [null, $bad('kind', 'Typ: person oder partner.')];
        if ($req->has_param('kind')) $set['kind'] = $kind;
        if ($req->has_param('company')) $set['company'] = mb_substr(trim(sanitize_text_field((string) $req->get_param('company'))), 0, 190);
        if ($req->has_param('vat_id')) {
            $v = strtoupper(preg_replace('/\s+/', '', (string) $req->get_param('vat_id')));
            if ($v !== '' && !self::vat_id_ok($v)) return [null, $bad('vat_id', 'USt-IdNr. z. B. DE123456789.')];
            $set['vat_id'] = $v;
        }
        if ($req->has_param('address')) $set['address'] = mb_substr(trim(sanitize_textarea_field((string) $req->get_param('address'))), 0, 300);
        if ($req->has_param('vat_liable')) $set['vat_liable'] = rest_sanitize_boolean($req->get_param('vat_liable')) ? 1 : 0;
        if ($kind === 'partner') {
            $company = $set['company'] ?? (string) ($cur->company ?? '');
            $address = $set['address'] ?? (string) ($cur->address ?? '');
            if ($company === '') return [null, $bad('company', 'Bitte die Firma des Partners angeben.')];
            if ($address === '') return [null, $bad('address', 'Bitte die Anschrift angeben (für die Gutschrift).')];
        }
        return [$set, null];
    }

    public static function item_fields($p) {
        return ['kind' => (string) ($p->kind ?? 'person') === 'partner' ? 'partner' : 'person', 'company' => (string) ($p->company ?? ''), 'vat_id' => (string) ($p->vat_id ?? ''),
            'address' => (string) ($p->address ?? ''), 'vat_liable' => !isset($p->vat_liable) || intval($p->vat_liable) === 1];
    }

    /** Kategorie-Sätze einer Zuordnung prüfen → [JSON|null, WP_Error|null] */
    public static function clean_rates($in, $event_id) {
        if ($in === null || $in === '' || $in === []) return [null, null];
        if (!is_array($in) || count($in) > 50) return [null, new WP_Error('tix_invalid_field', 'Höchstens 50 Kategorie-Sätze.', ['status' => 400, 'field' => 'category_rates'])];
        if (!$event_id) return [null, new WP_Error('tix_invalid_field', 'Kategorie-Sätze gehen nur für ein bestimmtes Event.', ['status' => 400, 'field' => 'category_rates'])];
        $cats = (array) get_post_meta($event_id, '_tix_ticket_categories', true);
        $out = [];
        foreach (array_values($in) as $i => $r) {
            $ix = intval($r['cat_index'] ?? -1); $t = (string) ($r['type'] ?? 'percent'); $v = round(floatval($r['value'] ?? 0), 2);
            if (!isset($cats[$ix])) return [null, new WP_Error('tix_invalid_field', 'Diese Ticketart gibt es nicht.', ['status' => 400, 'field' => "category_rates.$i.cat_index"])];
            if (!in_array($t, ['percent', 'fixed'], true) || $v < 0 || ($t === 'percent' && $v > 50) || ($t === 'fixed' && $v > 100)) return [null, new WP_Error('tix_invalid_field', 'Provision: 0–50 % bzw. 0–100 € je Ticket.', ['status' => 400, 'field' => "category_rates.$i.value"])];
            $out[$ix] = ['type' => $t, 'value' => $v];
        }
        return [wp_json_encode($out), null];
    }

    /** Eigener Satz des Promoters/Partners für Event + Kategorie → ['type','value','source'=>'partner_category'] oder null */
    public static function rate_for($promoter_id, $event_id, $cat_index) {
        if ($cat_index < 0 || !class_exists('TIX_Promoter_DB')) return null;
        self::ensure_columns();
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT category_rates FROM " . TIX_Promoter_DB::table_events() . " WHERE promoter_id = %d AND event_id = %d AND status = 'active'", intval($promoter_id), intval($event_id)));
        $r = $raw ? json_decode((string) $raw, true) : null;
        if (!is_array($r) || !isset($r[$cat_index]) || floatval($r[$cat_index]['value'] ?? 0) <= 0) return null;
        return ['type' => (string) $r[$cat_index]['type'], 'value' => floatval($r[$cat_index]['value']), 'source' => 'partner_category'];
    }

    // ═══════════════ Gutschrift ═══════════════

    private static function issuer_key($p) {
        $oid = intval($p->organizer_id ?? 0);
        if ($oid) return 'org:' . $oid;
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi() ? 'platform' : 'site';
    }

    /** Nummer aus dem Zählerspeicher der Rechnungen (Reihe GS je Aussteller und Jahr) */
    private static function next_number($key, $code) {
        global $wpdb;
        $c = TIX_Invoices::counters_table();
        $y = intval(current_time('Y'));
        $wpdb->query('START TRANSACTION');
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $c (issuer_key, series, year, last) VALUES (%s, 'GS', %d, 0)", $key, $y));
        $last = $wpdb->get_var($wpdb->prepare("SELECT last FROM $c WHERE issuer_key = %s AND series = 'GS' AND year = %d FOR UPDATE", $key, $y));
        if ($last === null) { $wpdb->query('ROLLBACK'); return null; }
        $seq = intval($last) + 1;
        $wpdb->query($wpdb->prepare("UPDATE $c SET last = %d WHERE issuer_key = %s AND series = 'GS' AND year = %d", $seq, $key, $y));
        $wpdb->query('COMMIT');
        return 'GS-' . ($code !== '' ? $code . '-' : '') . $y . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /** Gutschrift zur Auszahlung festschreiben (einmal) → doc_data oder WP_Error */
    public static function issue($payout_id) {
        global $wpdb;
        self::ensure_columns();
        $po = TIX_Promoter_DB::get_payout(intval($payout_id));
        if (!$po) return new WP_Error('not_found', 'Auszahlung nicht gefunden.', ['status' => 404]);
        if (!empty($po->doc_no)) { $d = json_decode((string) $po->doc_data, true); if (is_array($d)) return $d; }
        $p = TIX_Promoter_DB::get_promoter(intval($po->promoter_id));
        if (!self::is_partner($p)) return new WP_Error('tix_not_partner', 'Gutschriften gibt es nur für Partner.', ['status' => 409]);
        if (!class_exists('TIX_Invoices')) return new WP_Error('tix_unavailable', 'Belege sind hier nicht verfügbar.', ['status' => 503]);
        $key = self::issuer_key($p);
        $iss = TIX_Invoices::issuer($key);
        $by = [];
        foreach ((array) TIX_Promoter_DB::get_commissions(['payout_id' => intval($po->id)]) as $c) {
            $eid = intval($c->event_id);
            if (!isset($by[$eid])) $by[$eid] = ['event' => html_entity_decode((string) ($c->event_title ?: get_the_title($eid)), ENT_QUOTES, 'UTF-8'), 'date' => (string) get_post_meta($eid, '_tix_date_start', true), 'tickets' => 0, 'revenue' => 0.0, 'net' => 0.0];
            $by[$eid]['tickets'] += intval($c->tickets_qty); $by[$eid]['revenue'] += floatval($c->order_total); $by[$eid]['net'] += floatval($c->commission_amount);
        }
        $lines = array_values(array_map(function ($l) { $l['revenue'] = round($l['revenue'], 2); $l['net'] = round($l['net'], 2); return $l; }, $by));
        $net = round(array_sum(array_column($lines, 'net')), 2);
        $liable = intval($p->vat_liable ?? 1) === 1;
        $rate = $liable ? self::vat_rate() : 0.0;
        $vat = round($net * $rate / 100, 2);
        $no = self::next_number($key, (string) ($iss['code'] ?? ''));
        if (!$no) return new WP_Error('tix_counter', 'Nummernkreis nicht verfügbar.', ['status' => 500]);
        $doc = ['number' => $no, 'date' => current_time('Y-m-d'), 'period' => ['from' => (string) $po->period_from, 'to' => (string) $po->period_to],
            'issuer' => ['name' => (string) $iss['name'], 'lines' => array_values((array) $iss['address_lines']), 'vat_id' => (string) $iss['vat_id'], 'tax_id' => (string) $iss['tax_id'], 'key' => $key],
            'partner' => ['company' => (string) $p->company, 'contact' => (string) $p->display_name, 'address' => (string) $p->address, 'vat_id' => (string) $p->vat_id, 'vat_liable' => $liable, 'code' => (string) $p->promoter_code],
            'lines' => $lines, 'net' => $net, 'vat_rate' => $rate, 'vat' => $vat, 'gross' => round($net + $vat, 2)];
        $wpdb->update(TIX_Promoter_DB::table_payouts(), ['doc_no' => $no, 'doc_data' => wp_json_encode($doc)], ['id' => intval($po->id)]);
        return $doc;
    }

    private static function eur($v) { return number_format(floatval($v), 2, ',', '.') . ' EUR'; }

    /** PDF aus den festgeschriebenen Daten */
    public static function pdf(array $d, $cancelled = false) {
        if (!class_exists('TIX_Simple_PDF') && defined('TIXOMAT_PATH')) require_once TIXOMAT_PATH . 'includes/class-tix-event-report.php';
        $W = 595; $H = 842; $M = 50;
        $pdf = class_exists('TIX_PDFA') && TIX_PDFA::available() ? new TIX_PDFA($W, $H, $M, 40) : new TIX_Simple_PDF($W, $H, $M, 40);
        if (method_exists($pdf, 'set_meta')) $pdf->set_meta(['title' => 'Gutschrift ' . $d['number'], 'author' => (string) $d['issuer']['name']]);
        $pdf->add_page();
        $rt = function ($x, $y, $s, $size) use ($pdf) { $w = method_exists($pdf, 'width') ? $pdf->width($s, $size) : strlen($s) * $size * 0.5; $pdf->text(round($x - $w, 2), $y, $s); };
        $y = $H - 60;
        $pdf->set_color(0, 0, 0);
        $pdf->set_font('Helvetica', 9);
        $pdf->text($M, $y, trim($d['issuer']['name'] . ' · ' . implode(' · ', $d['issuer']['lines']), ' ·')); $y -= 30;
        $pdf->set_font('Helvetica', 10);
        foreach (array_filter(array_merge([$d['partner']['company'], $d['partner']['contact'] !== $d['partner']['company'] ? $d['partner']['contact'] : ''], preg_split('/\r\n|\r|\n/', (string) $d['partner']['address']))) as $l) { $pdf->text($M, $y, $l); $y -= 13; }
        if ($d['partner']['vat_id'] !== '') { $pdf->text($M, $y, 'USt-IdNr.: ' . $d['partner']['vat_id']); $y -= 13; }
        $y -= 20;
        $pdf->set_font('Helvetica-Bold', 16); $pdf->text($M, $y, 'Gutschrift ' . $d['number'] . ($cancelled ? ' – STORNIERT' : '')); $y -= 20;
        $pdf->set_font('Helvetica', 10);
        $pdf->text($M, $y, 'Datum: ' . wp_date('d.m.Y', strtotime($d['date'] . ' 12:00:00')) . '   ·   Leistungszeitraum: ' . wp_date('d.m.Y', strtotime($d['period']['from'] . ' 12:00:00')) . ' – ' . wp_date('d.m.Y', strtotime($d['period']['to'] . ' 12:00:00'))); $y -= 14;
        $pdf->text($M, $y, 'Partner-Code: ' . $d['partner']['code']); $y -= 24;
        $pdf->set_font('Helvetica-Bold', 9);
        $pdf->text($M, $y, 'Event'); $rt(380, $y, 'Tickets', 9); $rt(470, $y, 'Umsatz', 9); $rt($W - $M, $y, 'Provision netto', 9); $y -= 14;
        $pdf->set_font('Helvetica', 9);
        foreach ($d['lines'] as $l) {
            if ($y < 140) { $pdf->add_page(); $y = $H - 60; }
            $pdf->text($M, $y, mb_substr($l['event'] . ($l['date'] ? ' (' . wp_date('d.m.Y', strtotime($l['date'] . ' 12:00:00')) . ')' : ''), 0, 55));
            $rt(380, $y, (string) $l['tickets'], 9); $rt(470, $y, self::eur($l['revenue']), 9); $rt($W - $M, $y, self::eur($l['net']), 9); $y -= 13;
        }
        $y -= 8;
        $pdf->set_font('Helvetica', 10);
        $pdf->text(330, $y, 'Summe netto'); $rt($W - $M, $y, self::eur($d['net']), 10); $y -= 14;
        if ($d['partner']['vat_liable']) { $pdf->text(330, $y, 'zzgl. USt ' . rtrim(rtrim(number_format($d['vat_rate'], 1, ',', ''), '0'), ',') . ' %'); $rt($W - $M, $y, self::eur($d['vat']), 10); $y -= 14; }
        $pdf->set_font('Helvetica-Bold', 11); $pdf->text(330, $y, 'Auszahlungsbetrag'); $rt($W - $M, $y, self::eur($d['gross']), 11); $y -= 30;
        $pdf->set_font('Helvetica', 9);
        $notes = ['Gutschrift im Sinne des § 14 Abs. 2 Satz 2 UStG über die Vermittlungsprovision für Ticketverkäufe im genannten Zeitraum. Widerspricht der Partner, verliert die Gutschrift ihre Wirkung als Rechnung.'];
        if (!$d['partner']['vat_liable']) $notes[] = 'Der Partner ist Kleinunternehmer; gemäß § 19 UStG wird keine Umsatzsteuer ausgewiesen.';
        $notes[] = 'Aussteller: ' . $d['issuer']['name'] . ($d['issuer']['vat_id'] !== '' ? ' · USt-IdNr. ' . $d['issuer']['vat_id'] : ($d['issuer']['tax_id'] !== '' ? ' · St.-Nr. ' . $d['issuer']['tax_id'] : ''));
        foreach ($notes as $n) foreach (explode("\n", wordwrap($n, 105, "\n", true)) as $l) { $pdf->text($M, $y, $l); $y -= 12; }
        return $pdf->output();
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        register_rest_route(self::NS, '/organizer/promoter-payouts/(?P<id>\d+)/credit-note', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_org'], 'permission_callback' => ['TIX_Ad_Accounts', 'perm']]);
        register_rest_route(self::NS, '/promoter/payouts/(?P<id>\d+)/credit-note', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_portal'], 'permission_callback' => ['TIX_Promoter_Portal', 'perm']]);
    }

    private static function deliver($po) {
        $d = self::issue(intval($po->id));
        if (is_wp_error($d)) return $d;
        $bytes = self::pdf($d, (string) $po->status === 'cancelled');
        return rest_ensure_response(['ok' => true, 'number' => $d['number'], 'filename' => 'Gutschrift-' . $d['number'] . '.pdf', 'mime' => 'application/pdf', 'net' => $d['net'], 'vat' => $d['vat'], 'gross' => $d['gross'], 'data' => base64_encode($bytes)]);
    }

    public static function rest_org(WP_REST_Request $req) {
        self::ensure_columns();
        $po = TIX_Promoter_DB::get_payout(intval($req['id']));
        $p = $po ? TIX_Promoter_DB::get_promoter(intval($po->promoter_id)) : null;
        if (!$po || !$p || intval($p->organizer_id ?? 0) !== intval(TIX_Ad_Accounts::org($req))) return new WP_Error('not_found', 'Auszahlung nicht gefunden.', ['status' => 404]);
        return self::deliver($po);
    }

    public static function rest_portal(WP_REST_Request $req) {
        self::ensure_columns();
        $me = TIX_Promoter_Portal::current();
        if (!$me) return new WP_Error('rest_forbidden', 'Bitte anmelden.', ['status' => 401]);
        $po = TIX_Promoter_DB::get_payout(intval($req['id']));
        if (!$po || intval($po->promoter_id) !== intval($me->id)) return new WP_Error('not_found', 'Auszahlung nicht gefunden.', ['status' => 404]);
        return self::deliver($po);
    }
}

TIX_Promoter_Partner::init();
