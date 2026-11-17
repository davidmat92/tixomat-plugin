<?php
/**
 * W6a (1.38.887): Plattform-Akte – Prüfung/Vertrag (P1), Auszahlungen inkl. SEPA (P2), Gebühren (P3), Vertrag je Veranstalter (P5).
 * Nur Plattform (Mehr-Veranstalter); Einzel-Seite: Routen 404.   wp eval-file …/test-w6a-platform-money-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Platform_Money')) { echo "Tixomat ab 1.38.887 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $req = new WP_REST_Request($m, '/tixomat/v1' . $r); foreach ($q as $k => $v) $req->set_param($k, $v); if ($b !== null) { $req->set_header('content-type', 'application/json'); $req->set_body(wp_json_encode($b)); } $res = rest_do_request($req); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? null) : null, is_array($d) ? ($d['data']['field'] ?? null) : null]; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);
if (!$multi) {
    [$s] = $rest('GET', '/platform/fee-settings');
    $eq('Einzel-Seite: Plattform-Routen 404', 404, $s);
    echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
    return;
}
add_filter('pre_wp_mail', '__return_true', 99);
$keep = [];
foreach (['tix_settings', 'tix_org_approval_settings', 'tix_contract_template', 'tix_contract_versions', 'tix_contract_draft', 'tix_contract_snapshots', 'tix_channel_fee_settings', 'tix_planned_values'] as $o) $keep[$o] = get_option($o, null);
$sid = 0; $org = 0; $sub = 0;
$IBAN = 'DE89370400440532013000'; $IBAN2 = 'DE02120300000000202051';
try {
    $eq('clean rein: Grenzen und Typen', [[ 'a' => 1, 'b' => 2.5 ], null], TIX_Platform_Money::clean(['a' => true, 'b' => '2,5'], ['a' => ['bool'], 'b' => ['float', [0, 10]]]));
    $eq('clean rein: unbekannt → Feld', [null, 'x'], TIX_Platform_Money::clean(['x' => 1], ['a' => ['bool']]));

    // ═══ P1 ═══
    [$s, $d] = $rest('GET', '/platform/approval-settings');
    $eq('GET approval-settings: Felder, Vertragswerte, Vorlage', [200, true, true, true], [$s, array_key_exists('terms_source', $d), is_array($d['contract'] ?? null), is_string($d['template']['default'] ?? null)]);
    [$s, $d] = $rest('POST', '/platform/approval-settings', ['notify_email' => 'pruefung@example.test', 'push_admins' => false, 'contract' => ['einwand_frist_tage' => 21]]);
    $eq('POST: Mail, Push, Einwandfrist gespeichert', [200, 'pruefung@example.test', false, 21], [$s, $d['notify_email'] ?? null, $d['push_admins'] ?? null, $d['contract']['einwand_frist_tage'] ?? null]);
    [$s, , , $fld] = $rest('POST', '/platform/approval-settings', ['notify_email' => 'kaputt']);
    $eq('ungültige Mail → 400 notify_email', [400, 'notify_email'], [$s, $fld]);
    [$s, , , $fld] = $rest('POST', '/platform/approval-settings', ['contract' => ['einwand_frist_tage' => 0]]);
    $eq('Einwandfrist 0 → 400 contract.einwand_frist_tage', [400, 'contract.einwand_frist_tage'], [$s, $fld]);
    $rest('POST', '/platform/approval-settings', ['terms_source' => 'page']);
    [$s, , $c] = $rest('POST', '/platform/contract/publish');
    $eq('Vorlage nicht aktiv → 409 tix_contract_inactive', [409, 'tix_contract_inactive'], [$s, $c]);
    $rest('POST', '/platform/approval-settings', ['terms_source' => 'template']);
    [$s, $d] = $rest('GET', '/platform/contract');
    $eq('GET contract: Stand mit Versionen und Entwurf', [200, true, true], [$s, is_array($d['versions'] ?? null), array_key_exists('draft_changes', $d)]);
    [$s, $d, $c] = $rest('POST', '/platform/contract/publish', ['classification' => 'irgendwie']);
    $eq('Einstufung ungültig → 400', 400, $s);
    [$s, $d, $c] = $rest('POST', '/platform/contract/publish');
    $eq('Veröffentlichen: neue Version oder begründet 409 (Stammdaten/kein Entwurf)', true, ($s === 200 && !empty($d['published']['version'])) || ($s === 409 && in_array($c, ['tix_master_data', 'tix_no_draft'], true)));
    echo "      (publish: $s " . ($c ?: ($d['published']['version'] ?? '')) . ")\n";
    $rest('POST', '/platform/approval-settings', ['terms_source' => 'page']); // Gebühren/Fristen ohne Vertragsvorlage sofort wirksam

    // ═══ P2 ═══
    [$s, $d] = $rest('POST', '/platform/settlement-settings', ['delay_days' => 10, 'sepa_enabled' => true, 'debtor_name' => 'evendis Test GmbH', 'debtor_iban' => $IBAN, 'debtor_bic' => 'COBADEFFXXX']);
    $raw = wp_json_encode($d);
    $eq('Auszahlungs-Einstellungen: gespeichert, IBAN nur maskiert', [200, 10, true, true, false], [$s, $d['delay_days'] ?? null, $d['debtor_iban_set'] ?? null, $d['sepa_ready'] ?? null, strpos($raw, '0532013000') !== false]);
    [$s, , , $fld] = $rest('POST', '/platform/settlement-settings', ['debtor_iban' => 'DE00123']);
    $eq('ungültige IBAN → 400 debtor_iban', [400, 'debtor_iban'], [$s, $fld]);
    [$s, , , $fld] = $rest('POST', '/platform/settlement-settings', ['prefix' => 'EV-2026']);
    $eq('Präfix mit Bindestrich → 400', [400, 'prefix'], [$s, $fld]);
    $audit = (string) $wpdb->get_var("SELECT GROUP_CONCAT(summary) FROM " . TIX_Customers::audit_table() . " WHERE action LIKE 'platform\\_%' AND created >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)");
    $eq('Änderungsprotokoll ohne Kontodaten', [true, false], [$audit !== '', strpos($audit, '0532013000') !== false || strpos($audit, '202051') !== false]);
    // freigegebene Abrechnung mit Empfänger-IBAN
    TIX_Settlement::maybe_install();
    $wpdb->insert(TIX_Settlement::table(), ['number' => 'EV-T-' . wp_rand(1000, 9999), 'type' => 'event', 'organizer_id' => 0, 'event_id' => 0, 'event_title' => 'W6a Test', 'status' => 'approved',
        'payout_amount' => 123.45, 'snapshot' => wp_json_encode(['holder' => 'Club Test', 'iban_enc' => TIX_Payout_Details::encrypt($IBAN2), 'iban_masked' => 'DE02 •••• 2051']), 'created' => current_time('mysql'), 'updated' => current_time('mysql')]);
    $sid = intval($wpdb->insert_id);
    [$s, $d] = $rest('POST', '/platform/settlements/sepa', ['ids' => [$sid]]);
    $xml = is_string($d['data'] ?? null) ? base64_decode($d['data']) : '';
    if ($s !== 200) echo "      (SEPA: " . ($d['message'] ?? '') . ")\n";
    $eq('SEPA: Datei pain.001 mit 1 Überweisung über 123,45 €', [200, 'application/xml', 1, 123.45, true, true], [$s, $d['mime'] ?? null, $d['count'] ?? null, $d['total'] ?? null, strpos($xml, 'pain.001.001.09') !== false, strpos($xml, $IBAN2) !== false]);
    $audit = (string) $wpdb->get_var("SELECT summary FROM " . TIX_Customers::audit_table() . " WHERE action = 'platform_sepa_export' ORDER BY id DESC LIMIT 1");
    $eq('SEPA-Protokoll: nur Anzahl/Summe, keine IBAN', [true, false], [strpos($audit, '123.45') !== false, strpos($audit, '202051') !== false]);
    $rest('POST', '/platform/settlement-settings', ['sepa_enabled' => false]);
    [$s, , $c] = $rest('POST', '/platform/settlements/sepa', ['ids' => [$sid]]);
    $eq('SEPA aus → 409 tix_sepa_off', [409, 'tix_sepa_off'], [$s, $c]);
    [$s] = $rest('GET', "/platform/settlements/999999999/pdf");
    $eq('PDF unbekannte Abrechnung → 404', 404, $s);

    // ═══ P3 ═══
    [$s, $d] = $rest('GET', '/platform/fee-settings');
    $eq('GET fee-settings: Plattform- und Kanal-Gebühr, Beispiel', [200, true, true], [$s, is_array($d['channel'] ?? null), is_array($d['example'] ?? null)]);
    [$s, $d] = $rest('POST', '/platform/fee-settings', ['fee_percent' => 2.5, 'fee_fixed' => 0.59, 'fee_mode_new_org' => 'split', 'channel' => ['percent' => 2.9, 'due_days' => 21]]);
    $eq('Gebühren gespeichert (+ Kanal)', [200, 2.5, 0.59, 'split', 2.9, 21], [$s, $d['fee_percent'] ?? null, $d['fee_fixed'] ?? null, $d['fee_mode_new_org'] ?? null, floatval($d['channel']['percent'] ?? 0), intval($d['channel']['due_days'] ?? 0)]);
    [$s, , , $fld] = $rest('POST', '/platform/fee-settings', ['fee_mode' => 'gratis']);
    $eq('Modus ungültig → 400 fee_mode', [400, 'fee_mode'], [$s, $fld]);
    [$s, , , $fld] = $rest('POST', '/platform/fee-settings', ['channel' => ['percent' => 50]]);
    $eq('Kanal 50 % → 400 channel.percent', [400, 'channel.percent'], [$s, $fld]);
    [$s, , , $fld] = $rest('POST', '/platform/fee-settings', ['fee_label' => 'Steuer']);
    $eq('irreführende Bezeichnung → 400 fee_label', [400, 'fee_label'], [$s === 400 ? 400 : $s, $s === 400 ? $fld : 'fee_label']);
    // Mit aktiver Vertragsvorlage: nachteilige Änderung wird geplant, nicht sofort wirksam; zurückziehbar
    $rest('POST', '/platform/approval-settings', ['terms_source' => 'template']);
    [$s, $d] = $rest('POST', '/platform/fee-settings', ['fee_percent' => 3.5]);
    $pl = array_values(array_filter($d['planned'] ?? [], function ($p) { return ($p['setting'] ?? '') === 'fee_percent'; }));
    $eq('Vorlage aktiv: Gebühr rauf → geplant, aktuell bleibt 2,5', [200, 2.5, 1, 3.5], [$s, $d['fee_percent'] ?? null, count($pl), floatval($pl[0]['planned'] ?? 0)]);
    [$s, $d] = $rest('DELETE', '/platform/planned-values/' . ($pl[0]['id'] ?? 'x'));
    $eq('geplanten Wert zurückziehen', [200, 0], [$s, count(array_filter($d['planned'] ?? [], function ($p) { return ($p['setting'] ?? '') === 'fee_percent'; }))]);
    $rest('POST', '/platform/approval-settings', ['terms_source' => 'page']);
    [$s, $d] = $rest('GET', '/platform/channel-invoices');
    $eq('Kanal-Rechnungen: Liste', [200, true], [$s, is_array($d['invoices'] ?? null)]);
    [$s, , , $fld] = $rest('POST', '/platform/channel-invoices/run', ['period' => current_time('Y-m')]);
    $eq('laufender Monat → 400 period', [400, 'period'], [$s, $fld]);

    // ═══ P5 ═══
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'W6a Club']);
    [$s, $d] = $rest('GET', "/platform/organizers/$org/contract");
    $eq('Vertrag je Veranstalter: noch keine Zustimmung, Standard-Gebühr', [200, null, false], [$s, array_key_exists('terms', (array) $d) ? $d['terms'] : 'x', $d['fee']['custom'] ?? null]);
    [$s, $d] = $rest('POST', "/platform/organizers/$org/contract/addendum", ['fee' => ['custom' => true, 'percent' => 1.9, 'fixed' => 0.39], 'extra' => 'Gilt für 2027']);
    $eq('Zusatzvereinbarung vorgeschlagen', [200, true, 1.9, 'Gilt für 2027'], [$s, $d['proposed'] ?? null, $d['addendum_pending']['fee']['percent'] ?? null, $d['addendum_pending']['extra'] ?? null]);
    [$s, $d] = $rest('GET', "/platform/organizers/$org/contract/document", null, ['type' => 'addendum']);
    if ($s !== 200) echo "      (PDF: " . ($d['message'] ?? '') . ")\n";
    $eq('PDF der Zusatzvereinbarung', [200, 'application/pdf', true], [$s, $d['mime'] ?? null, is_string($d['data'] ?? null) && strpos(base64_decode($d['data']), '%PDF') === 0]);
    [$s, , , $fld] = $rest('POST', "/platform/organizers/$org/contract/addendum", ['fee' => ['custom' => true, 'percent' => 150]]);
    $eq('Gebühr 150 % → 400 fee.percent', [400, 'fee.percent'], [$s, $fld]);
    [$s] = $rest('GET', '/platform/organizers/999999999/contract');
    $eq('unbekannter Veranstalter → 404', 404, $s);

    // ═══ Rechte ═══
    $sub = wp_insert_user(['user_login' => 'w6a_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'user_email' => 'w6a_' . wp_rand(1000, 9999) . '@example.test', 'role' => 'tix_organizer']);
    wp_set_current_user($sub);
    [$s1] = $rest('GET', '/platform/settlement-settings');
    [$s2] = $rest('POST', '/platform/settlements/sepa', []);
    [$s3] = $rest('POST', '/platform/fee-settings', ['fee_percent' => 9]);
    $eq('Veranstalter → 403 überall (keine Kontodaten ohne Admin)', [403, 403, 403], [$s1, $s2, $s3]);
    wp_set_current_user($adm->ID);
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    remove_filter('pre_wp_mail', '__return_true', 99);
    foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
    if ($sid) $wpdb->delete(TIX_Settlement::table(), ['id' => $sid]);
    if ($org) { delete_post_meta($org, TIX_Contract::META_PENDING); wp_delete_post($org, true); }
    if ($sub) wp_delete_user($sub);
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
