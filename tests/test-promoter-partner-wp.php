<?php
/**
 * R1 Partner im Promoter-System (1.38.812): Typ Partner (Firma, Anschrift, USt-IdNr.), Kategorie-Sätze je Event,
 * Gutschrift mit Nummer, USt bzw. § 19 UStG, PDF; Promoter bleiben unverändert.
 *   wp eval-file …/test-promoter-partner-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Promoter_Partner') || !class_exists('TIX_Promoter_DB')) { echo "Tixomat ab 1.38.812 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$pids = []; $posts = [];
$opt = get_option('tix_settings', []);
try {
    $s = (array) $opt; $s['promoter_enabled'] = 1; update_option('tix_settings', $s);
    if (class_exists('TIX_Ad_Accounts') && !TIX_Ad_Accounts::module_on(0)) { delete_option('tix_marketing_trial_until'); $rest('POST', '/marketing/trial'); }
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    wp_set_current_user($adm);
    $past = wp_date('Y-m-d', time() - 3 * DAY_IN_SECONDS);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Partner-Event', 'meta_input' => ['_tix_date_start' => $past, '_tix_date_end' => $past,
        '_tix_ticket_categories' => [['name' => 'Normal', 'price' => 20, 'qty' => 100], ['name' => 'VIP', 'price' => 50, 'qty' => 10]]]]); $posts[] = $ev;
    [$st, $d] = $rest('POST', '/organizer/promoters', ['name' => 'Stadtmagazin', 'kind' => 'partner', 'email' => 'p' . wp_rand(1000, 9999) . '@example.test']);
    $eq('Partner ohne Firma → 400 company', [400, 'company'], [$st, $d['data']['field'] ?? '']);
    [$st, $d] = $rest('POST', '/organizer/promoters', ['name' => 'Stadtmagazin', 'kind' => 'partner', 'company' => 'Stadtmagazin GmbH', 'address' => "Markt 1\n58332 Schwelm", 'vat_id' => 'de 123456789']);
    $pid = intval($d['promoter']['id'] ?? 0); if ($pid) $pids[] = $pid;
    $eq('Partner anlegen: Typ, Firma, USt-IdNr. normalisiert, USt-pflichtig', [201, 'partner', 'Stadtmagazin GmbH', 'DE123456789', true], [$st, $d['promoter']['kind'] ?? '', $d['promoter']['company'] ?? '', $d['promoter']['vat_id'] ?? '', $d['promoter']['vat_liable'] ?? null]);
    [$st, $d] = $rest('POST', "/organizer/promoters/$pid", ['vat_id' => 'kaputt']);
    $eq('Ungültige USt-IdNr. → 400', [400, 'vat_id'], [$st, $d['data']['field'] ?? '']);
    [$st, $d] = $rest('POST', '/organizer/promoters', ['name' => 'Max Promoter']);
    $pp = intval($d['promoter']['id'] ?? 0); if ($pp) $pids[] = $pp;
    $eq('Promoter bleibt Person', 'person', $d['promoter']['kind'] ?? '');
    [, $d] = $rest('GET', '/organizer/promoters', null, ['kind' => 'partner']);
    $ids = array_column((array) ($d['promoters'] ?? []), 'id');
    $eq('Liste ?kind=partner', [true, false], [in_array($pid, $ids, true), in_array($pp, $ids, true)]);
    // Kategorie-Sätze
    [$st, $d] = $rest('POST', "/organizer/promoters/$pid/events", ['event_id' => $ev, 'commission_type' => 'percent', 'commission_value' => 5, 'category_rates' => [['cat_index' => 1, 'type' => 'percent', 'value' => 15]]]);
    $a = array_values(array_filter((array) ($d['promoter']['assignments'] ?? []), function ($x) use ($ev) { return $x['event_id'] === $ev; }))[0] ?? [];
    $eq('Zuordnung mit Kategorie-Satz VIP 15 %', [200, [['cat_index' => 1, 'type' => 'percent', 'value' => 15.0]]], [$st, $a['category_rates'] ?? null]);
    [$st, $d] = $rest('POST', "/organizer/promoters/$pid/events", ['event_id' => $ev, 'commission_type' => 'percent', 'commission_value' => 5, 'category_rates' => [['cat_index' => 9, 'type' => 'percent', 'value' => 15]]]);
    $eq('Unbekannte Kategorie → 400', [400, 'category_rates.0.cat_index'], [$st, $d['data']['field'] ?? '']);
    $p = TIX_Promoter_DB::get_promoter($pid);
    $own = TIX_Promoter_Partner::rate_for($pid, $ev, 1);
    $eff = TIX_Promoter::get_effective_commission($pid, $ev);
    $ap1 = TIX_Promoter_Rules::apply($p, $ev, 1, 2, 100.0, $own, true);
    $ap0 = TIX_Promoter_Rules::apply($p, $ev, 0, 2, 40.0, $eff);
    $eq('Provision: VIP 15 % aus Kategorie-Satz, Normal 5 % aus der Zuordnung', [15.0, 2.0], [round($ap1['commission'], 2), round($ap0['commission'], 2)]);
    // Gutschrift
    TIX_Promoter_DB::insert_commission(['promoter_id' => $pid, 'event_id' => $ev, 'order_id' => 990001, 'attribution' => 'referral', 'tickets_qty' => 2, 'order_total' => 100, 'commission_amount' => 15]);
    TIX_Promoter_DB::insert_commission(['promoter_id' => $pid, 'event_id' => $ev, 'order_id' => 990002, 'attribution' => 'referral', 'tickets_qty' => 2, 'order_total' => 40, 'commission_amount' => 2]);
    $wpdb->query($wpdb->prepare("UPDATE " . TIX_Promoter_DB::table_commissions() . " SET created_at = %s WHERE promoter_id = %d", $past . ' 12:00:00', $pid));
    [$st, $d] = $rest('POST', '/organizer/promoter-payouts', ['promoter_id' => $pid, 'from' => wp_date('Y-m-d', time() - 30 * DAY_IN_SECONDS), 'to' => current_time('Y-m-d')]);
    $po = intval($d['payout']['id'] ?? 0);
    $cn = $d['payout']['credit_note'] ?? [];
    $eq('Auszahlung an Partner → Gutschrift: netto 17, USt 3,23, brutto 20,23, Nummer GS-…', [201, 17.0, 3.23, 20.23, true], [$st, $cn['net'] ?? null, $cn['vat'] ?? null, $cn['gross'] ?? null, strpos((string) ($cn['number'] ?? ''), 'GS-') === 0]);
    [$st, $d] = $rest('GET', "/organizer/promoter-payouts/$po/credit-note");
    $pdf = base64_decode((string) ($d['data'] ?? ''));
    $eq('Gutschrift als PDF, gleiche Nummer', [200, true, $cn['number'] ?? 'x', 'application/pdf'], [$st, strpos($pdf, '%PDF') === 0, $d['number'] ?? '', $d['mime'] ?? '']);
    [, $d2] = $rest('GET', "/organizer/promoter-payouts/$po/credit-note");
    $eq('Nummer bleibt beim erneuten Abruf', $cn['number'] ?? 'x', $d2['number'] ?? '');
    // Kleinunternehmer
    $rest('POST', "/organizer/promoters/$pid", ['vat_liable' => false]);
    $wpdb->update(TIX_Promoter_DB::table_payouts(), ['doc_no' => '', 'doc_data' => null], ['id' => $po]);
    $doc = TIX_Promoter_Partner::issue($po);
    $eq('§ 19 UStG: keine USt, neue Nummer höher', [0.0, 17.0, true], [$doc['vat'], $doc['gross'], strcmp($doc['number'], (string) $cn['number']) > 0]);
    // Promoter (Person) → keine Gutschrift
    TIX_Promoter_DB::insert_commission(['promoter_id' => $pp, 'event_id' => $ev, 'order_id' => 990003, 'attribution' => 'referral', 'tickets_qty' => 1, 'order_total' => 20, 'commission_amount' => 1]);
    $wpdb->query($wpdb->prepare("UPDATE " . TIX_Promoter_DB::table_commissions() . " SET created_at = %s WHERE promoter_id = %d", $past . ' 12:00:00', $pp));
    [, $d] = $rest('POST', '/organizer/promoter-payouts', ['promoter_id' => $pp, 'from' => wp_date('Y-m-d', time() - 30 * DAY_IN_SECONDS), 'to' => current_time('Y-m-d')]);
    $po2 = intval($d['payout']['id'] ?? 0);
    [$st, , $c] = $rest('GET', "/organizer/promoter-payouts/$po2/credit-note");
    $eq('Promoter (Person): keine Gutschrift (409), Auszahlung wie bisher', [409, 'tix_not_partner', null], [$st, $c, array_key_exists('credit_note', (array) ($d['payout'] ?? [])) ? $d['payout']['credit_note'] : 'x']);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($pids as $p) {
        $wpdb->delete(TIX_Promoter_DB::table_commissions(), ['promoter_id' => $p]);
        $wpdb->delete(TIX_Promoter_DB::table_payouts(), ['promoter_id' => $p]);
        $wpdb->delete(TIX_Promoter_DB::table_events(), ['promoter_id' => $p]);
        $wpdb->delete(TIX_Promoter_DB::table_promoters(), ['id' => $p]);
    }
    foreach ($posts as $p) wp_delete_post($p, true);
    update_option('tix_settings', $opt);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
