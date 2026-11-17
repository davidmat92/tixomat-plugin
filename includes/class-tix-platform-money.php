<?php
/**
 * TIX_Platform_Money – Plattform-Akte W6a (1.38.887): Veranstalter-Prüfung/Vertrag (P1), Auszahlungen (P2), Gebühren (P3),
 * Vertrag je Veranstalter (P5). Vertrag: uebergaben/vertrag-plattform-akte-w6-2026-11-17.md, DOCUMENTATION.md §246.
 *
 * Nur Plattform-Admins im Mehr-Veranstalter-Modus (TIX_Platform_Admin::perm; Einzel-Seite 404). Nur mitgeschickte Felder werden
 * geschrieben; bei einem Fehler nichts. Jede Änderung → tix_audit_log (platform_*), ohne Kontodaten.
 *
 *   GET|POST /platform/approval-settings        P1 Einstellungen der Prüfung + Vertragswerte (+ eigene Vorlage)
 *   GET      /platform/contract                 P1 Stand: gültige/angekündigte Version, Entwurf mit Änderungen, Versionen
 *   POST     /platform/contract/publish         P1 Entwurf veröffentlichen {classification?: favorable|adverse|immediate}
 *   GET|POST /platform/settlement-settings      P2 (Auftraggeber-IBAN nur schreiben, Antwort maskiert)
 *   POST     /platform/settlements/sepa         P2 SEPA-Sammeldatei (pain.001) der freigegebenen Abrechnungen {ids?} → Datei als base64
 *   GET      /platform/settlements/{id}/pdf     P2 ?doc=settlement|invoice → Datei als base64
 *   GET|POST /platform/fee-settings             P3 Plattformgebühr, Zahlungsgebühr, Vorgabe neue Veranstalter, Kanal-Gebühr (+ planned[])
 *   DELETE   /platform/planned-values/{id}      P3 geplanten Wert zurückziehen (nachteilige Änderung bei aktiver Vertragsvorlage)
 *   GET      /platform/channel-invoices         P3 Monatsrechnungen der Kanal-Gebühr ?period=YYYY-MM
 *   POST     /platform/channel-invoices/run     P3 {period} Rechnungen erzeugen
 *   POST     /platform/channel-invoices/{id}/(send|paid|void)
 *   GET      /platform/channel-invoices/{id}/pdf
 *   GET      /platform/organizers/{id}/contract                P5 Zustimmungen, Zusatzvereinbarungen, Gebühr
 *   GET      /platform/organizers/{id}/contract/document       P5 ?type=terms|addendum&addendum_id= → PDF als base64
 *   POST     /platform/organizers/{id}/contract/addendum       P5 {fee: {custom, percent, fixed}, extra} → Zusatzvereinbarung vorschlagen
 */
if (!defined('ABSPATH')) exit;

class TIX_Platform_Money {

    const NS = 'tixomat/v1';

    public static function init() { add_action('rest_api_init', [__CLASS__, 'routes']); }

    public static function routes() {
        $p = ['permission_callback' => ['TIX_Platform_Admin', 'perm']];
        $r = function ($path, $method, $cb) use ($p) { register_rest_route(self::NS, $path, ['methods' => $method, 'callback' => [__CLASS__, $cb]] + $p); };
        $r('/platform/approval-settings', 'GET', 'rest_approval_get');
        $r('/platform/approval-settings', 'POST', 'rest_approval_save');
        $r('/platform/contract', 'GET', 'rest_contract_get');
        $r('/platform/contract/publish', 'POST', 'rest_contract_publish');
        $r('/platform/settlement-settings', 'GET', 'rest_settlement_get');
        $r('/platform/settlement-settings', 'POST', 'rest_settlement_save');
        $r('/platform/settlements/sepa', 'POST', 'rest_sepa');
        $r('/platform/settlements/(?P<id>\d+)/pdf', 'GET', 'rest_settlement_pdf');
        $r('/platform/fee-settings', 'GET', 'rest_fee_get');
        $r('/platform/fee-settings', 'POST', 'rest_fee_save');
        $r('/platform/channel-invoices', 'GET', 'rest_ci_list');
        $r('/platform/channel-invoices/run', 'POST', 'rest_ci_run');
        $r('/platform/channel-invoices/(?P<id>\d+)/(?P<action>send|paid|void)', 'POST', 'rest_ci_action');
        $r('/platform/channel-invoices/(?P<id>\d+)/pdf', 'GET', 'rest_ci_pdf');
        $r('/platform/planned-values/(?P<id>[A-Za-z0-9_-]{1,40})', 'DELETE', 'rest_planned_withdraw');
        $r('/platform/organizers/(?P<id>\d+)/contract', 'GET', 'rest_org_contract');
        $r('/platform/organizers/(?P<id>\d+)/contract/document', 'GET', 'rest_org_document');
        $r('/platform/organizers/(?P<id>\d+)/contract/addendum', 'POST', 'rest_org_addendum');
    }

    // ═══════════════ Hilfen ═══════════════

    private static function bad($field, $msg, $code = 'tix_invalid_field', $status = 400) {
        return new WP_Error($code, $msg, ['status' => $status, 'field' => $field]);
    }

    private static function body(WP_REST_Request $req) {
        $b = $req->get_json_params();
        return is_array($b) ? $b : (array) $req->get_body_params();
    }

    private static function settings_raw() {
        $s = get_option('tix_settings', []);
        return is_array($s) ? $s : [];
    }

    private static function audit($action, $subject, array $summary) {
        if (class_exists('TIX_Customers') && method_exists('TIX_Customers', 'audit')) TIX_Customers::audit($action, $subject, ['scope' => 'platform', 'oid' => 0], $summary);
    }

    /** Geplante Werte (TIX_Planned_Values): [{id, key, setting, label, current, planned, current_label, planned_label, valid_from}] */
    private static function planned() {
        if (!class_exists('TIX_Planned_Values')) return [];
        $all = TIX_Planned_Values::all();
        $pub = TIX_Planned_Values::public_list(); // gleiche Reihenfolge wie all()
        foreach ($pub as $i => $p) $pub[$i] = ['id' => (string) ($all[$i]['id'] ?? '')] + $p;
        return array_values($pub);
    }

    /** DELETE /platform/planned-values/{id} – geplanten Wert zurückziehen (eine schon angekündigte Version wird zurückgenommen) */
    public static function rest_planned_withdraw(WP_REST_Request $req) {
        if (!class_exists('TIX_Planned_Values')) return new WP_Error('tix_unavailable', 'Nicht verfügbar.', ['status' => 500]);
        $hit = TIX_Planned_Values::withdraw((string) $req['id']);
        if (!$hit) return new WP_Error('not_found', 'Geplanter Wert nicht gefunden.', ['status' => 404]);
        self::audit('platform_planned_withdraw', (string) $req['id'], ['setting' => (string) ($hit['key'] ?? '')]);
        return rest_ensure_response(['ok' => true, 'planned' => self::planned()]);
    }

    private static function file_response($name, $mime, $bytes) {
        $r = rest_ensure_response(['ok' => true, 'filename' => $name, 'mime' => $mime, 'size' => strlen($bytes), 'data' => base64_encode($bytes)]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /**
     * Rein: Werte nach Felddefinition prüfen. $defs: key => [typ, extra]; Typen bool|int|float|enum|text|date|code.
     * → [sauber, null] | [null, feld]
     */
    public static function clean(array $in, array $defs) {
        $out = [];
        foreach ($in as $k => $v) {
            if (!isset($defs[$k])) return [null, (string) $k];
            [$t, $x] = $defs[$k] + [1 => []];
            switch ($t) {
                case 'bool': if (!is_bool($v) && !in_array($v, [0, 1, '0', '1'], true)) return [null, $k]; $out[$k] = $v ? 1 : 0; break;
                case 'int': if (!is_numeric($v) || intval($v) != $v || intval($v) < $x[0] || intval($v) > $x[1]) return [null, $k]; $out[$k] = intval($v); break;
                case 'float':
                    $v = is_string($v) ? str_replace(',', '.', $v) : $v;
                    if (!is_numeric($v) || floatval($v) < $x[0] || floatval($v) > $x[1]) return [null, $k]; $out[$k] = round(floatval($v), $x[2] ?? 2); break;
                case 'enum': if (!in_array((string) $v, $x, true)) return [null, $k]; $out[$k] = (string) $v; break;
                case 'text': if (!is_string($v) || mb_strlen(trim($v)) > $x[0]) return [null, $k]; $out[$k] = sanitize_text_field($v); break;
                case 'date': if (!is_string($v) || ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v))) return [null, $k]; $out[$k] = $v; break;
                case 'code': if (!is_string($v) || !preg_match('/^[A-Za-z0-9]{1,8}$/', $v)) return [null, $k]; $out[$k] = strtoupper($v); break;
                case 'email': if (!is_string($v) || ($v !== '' && !is_email($v))) return [null, $k]; $out[$k] = sanitize_email($v); break;
                case 'url': if (!is_string($v) || ($v !== '' && filter_var($v, FILTER_VALIDATE_URL) === false)) return [null, $k]; $out[$k] = esc_url_raw($v); break;
                default: return [null, $k];
            }
        }
        return [$out, null];
    }

    // ═══════════════ P1 Veranstalter-Prüfung + Vertrag ═══════════════

    const APPROVAL = [
        'terms_title' => ['text', [120]], 'terms_url' => ['url'], 'terms_page_id' => ['int', [0, PHP_INT_MAX]], 'terms_version' => ['text', [40]],
        'notify_email' => ['email'], 'push_admins' => ['bool'], 'contract_copy_admin' => ['bool'], 'terms_source' => ['enum', ['page', 'template']],
    ];
    const CONTRACT = [
        'evendis_vertreten_durch' => ['text', [120]], 'evendis_register' => ['text', [120]], 'einwand_frist_tage' => ['int', [1, 365]],
        'zahlungsziel_tage' => ['int', [0, 365]], 'kuendigung_monate' => ['int', [1, 12]], 'aenderung_vorlauf_tage' => ['int', [1, 365]],
        'gerichtsstand' => ['text', [120]], 'mwst_hinweis' => ['text', [200]],
    ];

    private static function approval_payload() {
        $s = class_exists('TIX_Org_Approval') ? TIX_Org_Approval::settings() : [];
        $out = ['ok' => true];
        foreach (array_keys(self::APPROVAL) as $k) $out[$k] = $s[$k] ?? null;
        $out['push_admins'] = !empty($out['push_admins']); $out['contract_copy_admin'] = !empty($out['contract_copy_admin']);
        $out['terms_page_id'] = intval($out['terms_page_id']) ?: null;
        $out['contract'] = class_exists('TIX_Contract_Template') ? array_intersect_key(TIX_Contract_Template::values_settings(), self::CONTRACT) : null;
        $tpl = (string) get_option(class_exists('TIX_Contract_Template') ? TIX_Contract_Template::OPTION_TEMPLATE : '', '');
        $out['template'] = ['custom' => trim($tpl) !== '', 'text' => class_exists('TIX_Contract_Template') ? TIX_Contract_Template::template() : '',
            'default' => class_exists('TIX_Contract_Template') ? TIX_Contract_Template::default_template() : ''];
        return $out;
    }

    public static function rest_approval_get(WP_REST_Request $req) { return rest_ensure_response(self::approval_payload()); }

    public static function rest_approval_save(WP_REST_Request $req) {
        if (!class_exists('TIX_Org_Approval')) return new WP_Error('tix_unavailable', 'Veranstalter-Prüfung nicht verfügbar.', ['status' => 500]);
        $b = self::body($req);
        $contract = $b['contract'] ?? null; $template = array_key_exists('template', $b) ? $b['template'] : null;
        unset($b['contract'], $b['template']);
        [$a, $bad] = self::clean($b, self::APPROVAL);
        if ($a === null) return self::bad($bad, isset(self::APPROVAL[$bad]) ? 'Ungültiger Wert.' : 'Unbekanntes Feld: ' . $bad . '.');
        if (isset($a['terms_page_id']) && $a['terms_page_id'] && get_post_type($a['terms_page_id']) !== 'page') return self::bad('terms_page_id', 'Seite nicht gefunden.');
        $c = null;
        if ($contract !== null) {
            if (!is_array($contract)) return self::bad('contract', 'contract als Objekt.');
            [$c, $bad] = self::clean($contract, self::CONTRACT);
            if ($c === null) return self::bad('contract.' . $bad, 'Ungültiger Wert.');
        }
        if ($template !== null && (!is_string($template) || mb_strlen($template) > 100000)) return self::bad('template', 'Vorlage als Text (höchstens 100.000 Zeichen).');
        $s = TIX_Org_Approval::settings();
        if (isset($a['terms_title']) && $a['terms_title'] === '') $a['terms_title'] = 'Vermittlungsvertrag für Veranstalter';
        $s = array_merge($s, $a);
        if ($c !== null) $s['contract'] = array_merge((array) ($s['contract'] ?? []), $c);
        update_option(TIX_Org_Approval::OPTION, $s, false);
        if ($template !== null && class_exists('TIX_Contract_Template')) {
            $tpl = str_replace("\r\n", "\n", $template);
            if (trim($tpl) === '' || trim($tpl) === trim(TIX_Contract_Template::default_template())) delete_option(TIX_Contract_Template::OPTION_TEMPLATE);
            else update_option(TIX_Contract_Template::OPTION_TEMPLATE, wp_kses_post($tpl), false);
        }
        if (class_exists('TIX_Contract_Template') && method_exists('TIX_Contract_Template', 'detect')) TIX_Contract_Template::detect();
        self::audit('platform_approval_settings', 0, ['fields' => array_keys($a), 'contract' => $c ? array_keys($c) : null, 'template' => $template !== null]);
        return rest_ensure_response(self::approval_payload());
    }

    private static function contract_payload() {
        if (!class_exists('TIX_Contract_Template')) return ['ok' => true, 'available' => false];
        $info = TIX_Contract_Template::info_payload();
        $d = TIX_Contract_Template::draft();
        $labels = TIX_Contract_Template::FIELDS;
        $info['draft_changes'] = $d ? array_values(array_map(function ($c) use ($labels) {
            return ['key' => (string) $c['key'], 'label' => (string) ($labels[$c['key']][0] ?? $c['key']), 'old' => $c['old'] ?? null, 'new' => $c['new'] ?? null, 'favorable' => !empty($c['favorable'])];
        }, (array) $d['changes'])) : [];
        $info['versions'] = array_values(array_map(function ($v) {
            return ['version' => (string) $v['version'], 'valid_from' => wp_date('c', intval($v['valid_from'])), 'created_at' => wp_date('c', intval($v['created_at'])),
                'classification' => (string) ($v['classification'] ?? ''), 'requires_consent' => !empty($v['requires_consent']), 'sha256' => (string) ($v['sha256'] ?? ''),
                'view_url' => TIX_Contract_Template::view_url((string) $v['version'])];
        }, array_reverse(TIX_Contract_Template::versions())));
        return ['ok' => true, 'available' => true] + $info;
    }

    public static function rest_contract_get(WP_REST_Request $req) {
        if (class_exists('TIX_Contract_Template')) TIX_Contract_Template::detect();
        return rest_ensure_response(self::contract_payload());
    }

    public static function rest_contract_publish(WP_REST_Request $req) {
        if (!class_exists('TIX_Contract_Template')) return new WP_Error('tix_unavailable', 'Vertragsvorlage nicht verfügbar.', ['status' => 500]);
        if (!TIX_Contract_Template::active()) return new WP_Error('tix_contract_inactive', 'Die Vertragsvorlage ist nicht aktiv (terms_source = template).', ['status' => 409]);
        $cls = (string) $req->get_param('classification');
        if ($cls !== '' && !in_array($cls, ['favorable', 'adverse', 'immediate'], true)) return self::bad('classification', 'favorable, adverse oder immediate.');
        TIX_Contract_Template::detect();
        $v = TIX_Contract_Template::publish($cls ?: null, get_current_user_id());
        if (is_wp_error($v)) return new WP_Error($v->get_error_code() === 'tix_no_draft' ? 'tix_no_draft' : $v->get_error_code(), $v->get_error_message(), ['status' => 409]);
        self::audit('platform_contract_publish', (string) $v['version'], ['classification' => $v['classification'], 'valid_from' => wp_date('c', intval($v['valid_from']))]);
        return rest_ensure_response(['published' => ['version' => (string) $v['version'], 'valid_from' => wp_date('c', intval($v['valid_from'])), 'classification' => (string) $v['classification'], 'requires_consent' => !empty($v['requires_consent'])]] + self::contract_payload());
    }

    // ═══════════════ P2 Auszahlungen ═══════════════

    const SETTLEMENT = [
        'enabled' => ['bool'], 'delay_days' => ['int', [0, 365]], 'since' => ['date'], 'tax_mode' => ['enum', ['agency', 'reseller']],
        'ticket_vat_rate' => ['float', [0, 100]], 'fee_vat_rate' => ['float', [0, 100]], 'prefix' => ['code'], 'invoice_prefix' => ['code'],
        'refund_alert' => ['float', [0, 100]], 'sepa_enabled' => ['bool'], 'debtor_name' => ['text', [70]], 'debtor_bic' => ['text', [11]],
    ];
    const SETTLEMENT_DEFAULTS = ['enabled' => 1, 'delay_days' => 7, 'since' => '', 'tax_mode' => 'agency', 'ticket_vat_rate' => 7, 'fee_vat_rate' => 19,
        'prefix' => 'EV', 'invoice_prefix' => 'EVR', 'refund_alert' => 15, 'sepa_enabled' => 0, 'debtor_name' => '', 'debtor_bic' => ''];

    private static function settlement_payload() {
        $s = self::settings_raw();
        $out = ['ok' => true];
        foreach (self::SETTLEMENT_DEFAULTS as $k => $d) {
            $v = $s['settlement_' . $k] ?? $d;
            $t = self::SETTLEMENT[$k][0];
            $out[$k] = $t === 'bool' ? !empty($v) : ($t === 'int' ? intval($v) : ($t === 'float' ? round(floatval($v), 2) : (string) $v));
        }
        $iban = (string) ($s['settlement_debtor_iban'] ?? '');
        $out['debtor_iban_set'] = $iban !== '';
        $out['debtor_iban_masked'] = $iban !== '' && class_exists('TIX_Payout_Details') ? TIX_Payout_Details::mask_iban($iban) : null;
        $out['sepa_ready'] = !empty($out['sepa_enabled']) && $out['debtor_name'] !== '' && $iban !== '';
        $out['planned'] = self::planned();
        return $out;
    }

    public static function rest_settlement_get(WP_REST_Request $req) { $r = rest_ensure_response(self::settlement_payload()); $r->header('Cache-Control', 'no-store'); return $r; }

    public static function rest_settlement_save(WP_REST_Request $req) {
        $b = self::body($req);
        $iban = array_key_exists('debtor_iban', $b) ? $b['debtor_iban'] : null;
        unset($b['debtor_iban'], $b['debtor_iban_set'], $b['debtor_iban_masked'], $b['sepa_ready'], $b['ok']);
        [$c, $bad] = self::clean($b, self::SETTLEMENT);
        if ($c === null) return self::bad($bad, isset(self::SETTLEMENT[$bad]) ? 'Ungültiger Wert.' : 'Unbekanntes Feld: ' . $bad . '.');
        if (isset($c['debtor_bic']) && $c['debtor_bic'] !== '' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper(str_replace(' ', '', $c['debtor_bic'])))) return self::bad('debtor_bic', 'BIC: 8 oder 11 Zeichen.');
        $norm = null;
        if ($iban !== null) {
            if (!is_string($iban)) return self::bad('debtor_iban', 'IBAN als Text.');
            $norm = class_exists('TIX_Payout_Details') ? TIX_Payout_Details::normalize_iban($iban) : strtoupper(preg_replace('/\s+/', '', $iban));
            if ($norm !== '' && class_exists('TIX_Payout_Details') && !TIX_Payout_Details::iban_valid($norm)) return self::bad('debtor_iban', 'Die IBAN ist nicht gültig.');
        }
        $s = self::settings_raw();
        foreach ($c as $k => $v) $s['settlement_' . $k] = $k === 'debtor_bic' ? strtoupper(str_replace(' ', '', $v)) : $v;
        if ($norm !== null) $s['settlement_debtor_iban'] = $norm;
        update_option('tix_settings', $s);
        self::audit('platform_settlement_settings', 0, ['fields' => array_merge(array_keys($c), $norm !== null ? ['debtor_iban'] : [])]); // nie Kontodaten
        return self::rest_settlement_get($req);
    }

    public static function rest_sepa(WP_REST_Request $req) {
        global $wpdb;
        if (!class_exists('TIX_Settlement') || !class_exists('TIX_Settlement_Admin')) return new WP_Error('tix_unavailable', 'Abrechnungen nicht verfügbar.', ['status' => 500]);
        if (!intval(TIX_Settlement::opt('settlement_sepa_enabled'))) return new WP_Error('tix_sepa_off', 'Die SEPA-Sammeldatei ist nicht eingeschaltet (settlement-settings sepa_enabled).', ['status' => 409]);
        $ids = $req->get_param('ids');
        $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
        $sql = "SELECT * FROM " . TIX_Settlement::table() . " WHERE status = 'approved'";
        if ($ids) $sql .= ' AND id IN (' . implode(',', $ids) . ')';
        $rows = (array) $wpdb->get_results($sql . ' ORDER BY id ASC');
        $xml = TIX_Settlement_Admin::sepa_xml($rows);
        if (is_wp_error($xml)) return new WP_Error('tix_sepa', $xml->get_error_message(), ['status' => 409]);
        preg_match('#<NbOfTxs>(\d+)</NbOfTxs><CtrlSum>([\d.]+)</CtrlSum>#', $xml, $m);
        self::audit('platform_sepa_export', count($rows), ['count' => intval($m[1] ?? 0), 'total' => floatval($m[2] ?? 0)]); // nur Anzahl und Summe
        $r = self::file_response('auszahlungen-' . wp_date('Y-m-d') . '.xml', 'application/xml', $xml);
        $d = $r->get_data(); $d['count'] = intval($m[1] ?? 0); $d['total'] = floatval($m[2] ?? 0); $r->set_data($d);
        return $r;
    }

    public static function rest_settlement_pdf(WP_REST_Request $req) {
        if (!class_exists('TIX_Settlement_PDF')) return new WP_Error('tix_unavailable', 'PDF nicht verfügbar.', ['status' => 500]);
        $s = TIX_Settlement::get(absint($req['id']));
        if (!$s) return new WP_Error('not_found', 'Abrechnung nicht gefunden.', ['status' => 404]);
        $doc = (string) $req->get_param('doc') === 'invoice' ? 'invoice' : 'settlement';
        $pdf = TIX_Settlement_PDF::render($s, $doc);
        if (!$pdf || empty($pdf['bytes'])) return new WP_Error('tix_no_document', 'Kein PDF verfügbar.', ['status' => 404]);
        return self::file_response((string) $pdf['filename'], 'application/pdf', (string) $pdf['bytes']);
    }

    // ═══════════════ P3 Gebühren ═══════════════

    const FEES = [
        'fee_fixed' => ['float', [0, 10]], 'fee_percent' => ['float', [0, 100, 4]], 'fee_mode' => ['enum', ['organizer', 'split', 'customer']],
        'fee_label' => ['text', [30]], 'fee_rounding' => ['enum', ['none', '0.90', '0.99', '0.50', '0.00', 'custom']], 'fee_rounding_custom' => ['float', [0, 0.99]],
        'fee_max_per_ticket' => ['float', [0, 1000]], 'fee_max_per_order' => ['float', [0, 10000]], 'fee_split_customer_share' => ['float', [0, 100]],
        'fee_mode_new_org' => ['enum', ['', 'organizer', 'split', 'customer']],
        'gateway_fee_fixed' => ['float', [0, 10]], 'gateway_fee_percent' => ['float', [0, 100, 4]], 'gateway_fee_mode' => ['enum', ['organizer', 'customer']], 'gateway_fee_included' => ['bool'],
    ];
    const FEE_DEFAULTS = ['fee_fixed' => 0, 'fee_percent' => 0, 'fee_mode' => 'organizer', 'fee_label' => 'Servicegebühr', 'fee_rounding' => 'none', 'fee_rounding_custom' => 0,
        'fee_max_per_ticket' => 0, 'fee_max_per_order' => 0, 'fee_split_customer_share' => 50, 'fee_mode_new_org' => 'customer',
        'gateway_fee_fixed' => 0, 'gateway_fee_percent' => 0, 'gateway_fee_mode' => 'organizer', 'gateway_fee_included' => 0];
    const CHANNEL = ['percent' => ['float', [0, 20, 4]], 'fixed' => ['float', [0, 5]], 'label' => ['text', [60]], 'new_partners' => ['bool'], 'due_days' => ['int', [0, 60]], 'payment' => ['enum', ['sepa', 'transfer']]];

    private static function fee_payload() {
        $s = self::settings_raw();
        $out = ['ok' => true];
        foreach (self::FEE_DEFAULTS as $k => $d) {
            $v = $s[$k] ?? $d; $t = self::FEES[$k][0];
            $out[$k] = $t === 'bool' ? !empty($v) : ($t === 'float' ? round(floatval($v), self::FEES[$k][1][2] ?? 2) : (string) $v);
        }
        $out['channel'] = class_exists('TIX_Channel_Fee') ? TIX_Channel_Fee::settings() : null;
        $out['example'] = class_exists('TIX_Fees') ? TIX_Fees::calc_with_config([['price' => 20.0, 'qty' => 1]], TIX_Fees::get_fee_config(null)) : null;
        $d = class_exists('TIX_Contract_Template') ? TIX_Contract_Template::draft() : null;
        $out['contract_draft'] = (bool) $d; // Gebühren stehen im Vertrag: Änderung → Entwurf unter /platform/contract
        $out['planned'] = self::planned(); // nachteilige Änderungen bei aktiver Vertragsvorlage: geplant bis zur neuen Vertragsversion
        return $out;
    }

    public static function rest_fee_get(WP_REST_Request $req) { return rest_ensure_response(self::fee_payload()); }

    public static function rest_fee_save(WP_REST_Request $req) {
        $b = self::body($req);
        $channel = $b['channel'] ?? null;
        unset($b['channel'], $b['example'], $b['contract_draft'], $b['ok']);
        [$c, $bad] = self::clean($b, self::FEES);
        if ($c === null) return self::bad($bad, isset(self::FEES[$bad]) ? 'Ungültiger Wert.' : 'Unbekanntes Feld: ' . $bad . '.');
        if (isset($c['fee_label']) && class_exists('TIX_Fees') && ($e = TIX_Fees::label_error($c['fee_label']))) return self::bad('fee_label', $e);
        $ch = null;
        if ($channel !== null) {
            if (!is_array($channel)) return self::bad('channel', 'channel als Objekt.');
            [$ch, $bad] = self::clean($channel, self::CHANNEL);
            if ($ch === null) return self::bad('channel.' . $bad, 'Ungültiger Wert.');
            if (isset($ch['label']) && $ch['label'] === '') $ch['label'] = 'Servicegebühr';
        }
        if ($c) { $s = self::settings_raw(); foreach ($c as $k => $v) $s[$k] = $v; update_option('tix_settings', $s); }
        if ($ch && class_exists('TIX_Channel_Fee')) update_option(TIX_Channel_Fee::OPTION, array_merge(TIX_Channel_Fee::settings(), $ch), false);
        if (class_exists('TIX_Contract_Template') && method_exists('TIX_Contract_Template', 'detect')) TIX_Contract_Template::detect();
        self::audit('platform_fee_settings', 0, ['new' => $c, 'channel' => $ch]);
        return self::rest_fee_get($req);
    }

    // ── Kanal-Gebühr: Monatsrechnungen ──

    private static function ci_item($i) {
        $i = (array) $i;
        $snap = json_decode((string) ($i['snapshot'] ?? ''), true);
        return ['id' => intval($i['id'] ?? 0), 'number' => (string) ($i['number'] ?? ''), 'partner' => (string) ($i['partner'] ?? ''),
            'partner_name' => is_array($snap) ? (string) ($snap['partner_name'] ?? ($snap['name'] ?? '')) : '',
            'period' => (string) ($i['period'] ?? ''), 'status' => (string) ($i['status'] ?? ''), 'amount' => round(floatval($i['amount'] ?? 0), 2),
            'charges' => intval($i['charges'] ?? 0), 'credits' => intval($i['credits'] ?? 0), 'payment' => (string) ($i['payment'] ?? ''),
            'due_date' => (string) ($i['due_date'] ?? '') ?: null, 'created' => (string) ($i['created'] ?? '') ?: null,
            'sent_at' => (string) ($i['sent_at'] ?? '') ?: null, 'paid_at' => (string) ($i['paid_at'] ?? '') ?: null];
    }

    public static function rest_ci_list(WP_REST_Request $req) {
        global $wpdb;
        if (!class_exists('TIX_Channel_Invoice')) return new WP_Error('tix_unavailable', 'Kanal-Rechnungen nicht verfügbar.', ['status' => 500]);
        TIX_Channel_Invoice::maybe_install();
        $t = TIX_Channel_Invoice::table();
        $period = (string) $req->get_param('period');
        $sql = "SELECT * FROM $t";
        if (preg_match('/^\d{4}-\d{2}$/', $period)) $sql .= $wpdb->prepare(' WHERE period = %s', $period);
        $rows = (array) $wpdb->get_results($sql . ' ORDER BY id DESC LIMIT 500', ARRAY_A);
        return rest_ensure_response(['ok' => true, 'invoices' => array_map([__CLASS__, 'ci_item'], $rows), 'active' => TIX_Channel_Invoice::active()]);
    }

    public static function rest_ci_run(WP_REST_Request $req) {
        if (!class_exists('TIX_Channel_Invoice')) return new WP_Error('tix_unavailable', 'Kanal-Rechnungen nicht verfügbar.', ['status' => 500]);
        $ym = (string) $req->get_param('period');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym) || $ym >= current_time('Y-m')) return self::bad('period', 'Abgeschlossener Monat als JJJJ-MM.');
        $made = (array) TIX_Channel_Invoice::cron($ym);
        self::audit('platform_channel_invoices_run', $ym, ['created' => count($made)]);
        return rest_ensure_response(['ok' => true, 'created' => count($made)] + (array) self::rest_ci_list($req)->get_data());
    }

    public static function rest_ci_action(WP_REST_Request $req) {
        global $wpdb;
        if (!class_exists('TIX_Channel_Invoice')) return new WP_Error('tix_unavailable', 'Kanal-Rechnungen nicht verfügbar.', ['status' => 500]);
        $inv = TIX_Channel_Invoice::get(absint($req['id']));
        if (!$inv) return new WP_Error('not_found', 'Rechnung nicht gefunden.', ['status' => 404]);
        $a = (string) $req['action'];
        if ($a === 'send') { $r = TIX_Channel_Invoice::send($inv); if (is_wp_error($r)) return new WP_Error($r->get_error_code(), $r->get_error_message(), ['status' => 409]); }
        elseif ($a === 'paid') $wpdb->update(TIX_Channel_Invoice::table(), ['status' => 'paid', 'paid_at' => current_time('mysql')], ['id' => intval($inv->id)]);
        else TIX_Channel_Invoice::void($inv);
        self::audit('platform_channel_invoice_' . $a, intval($inv->id), []);
        return rest_ensure_response(['ok' => true, 'invoice' => self::ci_item(TIX_Channel_Invoice::get(intval($inv->id)))]);
    }

    public static function rest_ci_pdf(WP_REST_Request $req) {
        if (!class_exists('TIX_Channel_Invoice')) return new WP_Error('tix_unavailable', 'Kanal-Rechnungen nicht verfügbar.', ['status' => 500]);
        $inv = TIX_Channel_Invoice::get(absint($req['id']));
        if (!$inv) return new WP_Error('not_found', 'Rechnung nicht gefunden.', ['status' => 404]);
        $bytes = (string) TIX_Channel_Invoice::pdf_bytes($inv);
        if ($bytes === '') return new WP_Error('tix_no_document', 'Kein PDF verfügbar.', ['status' => 404]);
        return self::file_response(sanitize_file_name($inv->number . '.pdf'), 'application/pdf', $bytes);
    }

    // ═══════════════ P5 Vertrag je Veranstalter ═══════════════

    private static function org_or_error($id) {
        $oid = absint($id);
        if (get_post_type($oid) !== 'tix_organizer') return new WP_Error('not_found', 'Veranstalter nicht gefunden.', ['status' => 404]);
        if (!class_exists('TIX_Contract') || !class_exists('TIX_Org_Approval')) return new WP_Error('tix_unavailable', 'Verträge nicht verfügbar.', ['status' => 500]);
        return $oid;
    }

    private static function fee_text(array $fee) {
        return !empty($fee['custom']) ? ['custom' => true, 'percent' => round(floatval($fee['percent']), 4), 'fixed' => round(floatval($fee['fixed']), 2)] : ['custom' => false, 'percent' => null, 'fixed' => null];
    }

    private static function org_contract_payload($oid) {
        $rec = get_post_meta($oid, TIX_Org_Approval::META_TERMS, true);
        $hist = get_post_meta($oid, TIX_Contract::META_HISTORY, true);
        $acc = function ($r) { return ['version' => (string) ($r['version'] ?? ''), 'accepted_at' => !empty($r['accepted_at']) ? wp_date('c', intval($r['accepted_at'])) : null, 'sha256' => (string) ($r['sha256'] ?? '')]; };
        $p = TIX_Contract::pending($oid);
        $add = function ($a) { return ['id' => (string) $a['id'], 'title' => (string) ($a['title'] ?? ''), 'fee' => self::fee_text((array) ($a['values']['fee'] ?? [])), 'extra' => (string) ($a['values']['extra'] ?? ''),
            'created_at' => wp_date('c', intval($a['created_at'] ?? 0)), 'accepted_at' => !empty($a['accepted_at']) ? wp_date('c', intval($a['accepted_at'])) : null]; };
        return ['ok' => true, 'organizer_id' => $oid, 'name' => html_entity_decode(get_the_title($oid), ENT_QUOTES, 'UTF-8'),
            'terms' => is_array($rec) && !empty($rec['version']) ? $acc($rec) : null,
            'history' => is_array($hist) ? array_values(array_map($acc, array_reverse($hist))) : [],
            'outdated' => class_exists('TIX_Contract_Template') && TIX_Contract_Template::active() ? (bool) TIX_Contract_Template::outdated($oid) : false,
            'fee' => self::fee_text(TIX_Contract::current_fee($oid)),
            'addendum_pending' => $p ? $add($p) : null,
            'addenda' => array_values(array_map($add, array_reverse(TIX_Contract::accepted_list($oid))))];
    }

    public static function rest_org_contract(WP_REST_Request $req) {
        $oid = self::org_or_error($req['id']);
        return is_wp_error($oid) ? $oid : rest_ensure_response(self::org_contract_payload($oid));
    }

    public static function rest_org_document(WP_REST_Request $req) {
        $oid = self::org_or_error($req['id']);
        if (is_wp_error($oid)) return $oid;
        $type = (string) $req->get_param('type') === 'addendum' ? 'addendum' : 'terms';
        $doc = TIX_Contract::document($oid, $type, sanitize_text_field((string) $req->get_param('addendum_id'))); // nicht „id“ (= Veranstalter aus dem Pfad)
        if (!$doc || $doc['bytes'] === '') return new WP_Error('tix_no_document', 'Dokument nicht gefunden.', ['status' => 404]);
        return self::file_response((string) $doc['name'], 'application/pdf', (string) $doc['bytes']);
    }

    public static function rest_org_addendum(WP_REST_Request $req) {
        $oid = self::org_or_error($req['id']);
        if (is_wp_error($oid)) return $oid;
        $b = self::body($req);
        $fee = $b['fee'] ?? null;
        if (!is_array($fee)) return self::bad('fee', 'fee {custom, percent, fixed} erwartet.');
        $custom = !empty($fee['custom']);
        if ($custom) {
            [$f, $bad] = self::clean(['percent' => $fee['percent'] ?? 0, 'fixed' => $fee['fixed'] ?? 0], ['percent' => ['float', [0, 100, 4]], 'fixed' => ['float', [0, 10]]]);
            if ($f === null) return self::bad('fee.' . $bad, 'Ungültiger Wert.');
        } else $f = ['percent' => 0, 'fixed' => 0];
        $extra = (string) ($b['extra'] ?? '');
        if (mb_strlen($extra) > 2000) return self::bad('extra', 'Höchstens 2000 Zeichen.');
        $a = TIX_Contract::propose($oid, ['custom' => $custom] + $f, $extra);
        self::audit('platform_contract_addendum', $oid, ['custom' => $custom, 'percent' => $f['percent'], 'fixed' => $f['fixed'], 'proposed' => (bool) $a]);
        return rest_ensure_response(['proposed' => (bool) $a, 'unchanged' => !$a] + self::org_contract_payload($oid));
    }
}

TIX_Platform_Money::init();
