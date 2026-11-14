<?php
/**
 * AN2 (1.38.817): Häkchen je Option, frei gesetzte Preise, Gliederung „Dein eigener Ticketshop“ (PDF + Online-Ansicht).
 *   wp eval-file …/test-offer-options-wp.php (Teil REST nur auf der Plattform)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Offer_Plan') || !method_exists('TIX_Platform_Offers', 'selfhosted_blocks')) { echo "Tixomat ab 1.38.817 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$O = 'TIX_Platform_Offers';
$it = function ($k, $iv = null, array $x = []) use ($O) { return array_merge($O::item_from_catalog($k, $iv), $x); };
$adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($adm);

// ── Häkchen
$d = $O::clean_data(['customer' => ['company' => 'Opt'], 'items' => [$it('shop', 'yearly'), $it('app'), $it('hosting', null, ['price' => 50, 'on' => false])]]);
$t = $O::totals($d);
$eq('Nicht angehakt: zählt nicht in den Summen, Zeile bleibt (on false)', [790.0 + 149 * 12, 3, false], [$t['yearly']['net'], count($t['lines']), $t['lines'][2]['on']]);
$eq('Häkchen-Liste: Positionen + evendis_channel', [['Eigener Shop', true], ['Eigene App – Basis', true], ['Hosting', false], ['Zusätzlich über evendis.de verkaufen', false]], array_map(function ($o) { return [$o['label'], $o['on']]; }, $d['options']));
$d2 = $O::clean_data(['options' => ['shop', 'evendis_channel']], $d);
$eq('options als Schlüssel-Liste: genau diese angehakt', [true, false, false, true], [$d2['items'][0]['on'], $d2['items'][1]['on'], $d2['items'][2]['on'], $d2['extras']['evendis_channel']]);
$d3 = $O::clean_data(['options' => [['key' => $d['items'][2]['id'], 'on' => true]]], $d);
$eq('options als Objekte: nur genannte ändern', [true, true, true, false], [$d3['items'][0]['on'], $d3['items'][1]['on'], $d3['items'][2]['on'], $d3['extras']['evendis_channel']]);
$eq('Ohne options bleiben Häkchen und Zusatz-Optionen erhalten', [false, true], [$O::clean_data(['intro' => 'x'], $d2)['items'][1]['on'], $O::clean_data(['intro' => 'x'], $d2)['extras']['evendis_channel']]);

// ── Preise frei
$d4 = $O::clean_data(['customer' => ['company' => 'Opt'], 'items' => [$it('shop', 'yearly'), $it('seating')], 'price_overrides' => ['items' => ['seating' => 25], 'shop_tiers' => [['max' => 10000, 'price' => 990], ['max' => 3000, 'price' => 590]], 'channel_fee' => ['percent' => 2.5, 'fixed' => 0.39]], 'extras' => ['evendis_channel' => true]]);
$t4 = $O::totals($d4);
$eq('Positions-Preis frei (Modul ohne Listenpreis 25 €/Monat), Staffel frei sortiert, 1. Stufe = Listenpreis Shop jährlich', [25.0, 590.0, [3000, 10000], 590.0 + 300.0],
    [$d4['items'][1]['price'], $d4['items'][0]['list_price'], array_column($d4['price_overrides']['shop_tiers'], 'max'), $t4['yearly']['net']]);
$pc = $O::plan_result(array_merge($d4, ['plan' => TIX_Offer_Plan::clean(['steps' => [1000, 5000, 20000], 'channel_share' => 20], $O::catalog())]));
$eq('Planrechnung: eigene Staffel (590/990/auf Anfrage), Optionen 300 €/Jahr, Kanal-Gebühr frei', [590.0, 990.0, null, 300.0, ['percent' => 2.5, 'fixed' => 0.39]],
    [$pc['rows'][0]['evendis'], $pc['rows'][1]['evendis'], $pc['rows'][2]['evendis'], $pc['rows'][0]['options'], array_intersect_key((array) $pc['to_evendis']['channel'], ['percent' => 1, 'fixed' => 1])]);
$eq('Kanal-Gebühr netto: 1.000 × 20 % × (25 × 2,5 % + 0,39) / 1,19 = 170,59', 170.59, $pc['rows'][0]['channel_fee']);
$eq('Bleibt = Umsatz + Gebühr − Zahlung − Shop − Optionen − Kanal', round($pc['rows'][0]['revenue'] + $pc['rows'][0]['own_fee'] - $pc['rows'][0]['psp'] - 590 - 300 - 170.59, 2), $pc['rows'][0]['kept']);
$d5 = $O::clean_data(['items' => [$it('shop', 'yearly', ['price' => 1200])], 'plan' => []]);
$p5 = $O::plan_result(array_merge($d5, ['plan' => TIX_Offer_Plan::clean([], $O::catalog())]));
$eq('Shop-Preis frei → fester Jahrespreis in allen Stufen, auch über 50.000 keine Anfrage', [1200.0, 1200.0], [$p5['rows'][0]['evendis'], $p5['rows'][3]['evendis']]);
$d6 = $O::clean_data(['items' => [$it('shop', 'yearly')], 'discount' => ['type' => 'percent', 'value' => 10]]);
$p6 = $O::plan_result(array_merge($d6, ['plan' => TIX_Offer_Plan::clean([], $O::catalog())]));
$eq('Rabatt wirkt auf „an evendis“ (790 − 10 % = 711)', 711.0, $p6['rows'][0]['evendis']);
// Szenario aus dem PDF-Probelauf: Optionen verschieben den Break-even
$d7 = $O::clean_data(['items' => [$it('shop', 'yearly'), $it('app'), $it('app_einrichtung'), $it('seating', null, ['price' => 19])], 'extras' => ['evendis_channel' => true], 'plan' => ['custom' => 3000, 'channel_share' => 10]]);
$p7 = $O::plan_result($d7);
$eq('Optionen (149 + 19)×12 = 2.016 €, einmalig 1.490 € getrennt, Break-even 4.970', [2016.0, 1490.0, 4970], [$p7['to_evendis']['options_year'], $p7['to_evendis']['once'], $p7['break_even']['with_fee']]);
$eq('Ohne Optionen unverändert (AN1-Referenz bleibt)', 756.14, TIX_Offer_Plan::compute(TIX_Offer_Plan::clean(['mix' => ['card' => 60, 'paypal' => 40], 'shop_price' => 790], $O::catalog()), $O::catalog())['rows'][0]['psp']);

// ── Gliederung + PDF + Online
$eq('Gliederung auto: Shop → selfhosted, nur Tickets über evendis → classic, Schalter classic', ['selfhosted', 'classic', 'classic'],
    [$O::layout_of($d7), $O::layout_of($O::clean_data(['items' => [$it('tickets')]])), $O::layout_of($O::clean_data(['layout' => 'classic'], $d7))]);
$eq('Shop nicht angehakt, ohne Planrechnung → classic', 'classic', $O::layout_of($O::clean_data(['options' => ['app'], 'plan' => null], $d7)));
$B = $O::selfhosted_blocks($d7);
$eq('Kacheln + Blöcke: 790 €/Jahr, Mischsatz, lohnt sich ab 4.970; Block 1 ohne Hosting, mit evendis.de-Zeile', ['790 € / Jahr', '2,51 % + 0,33 €', '4.970 Tickets/Jahr', true, true],
    [$B['tiles'][0]['value'], $B['tiles'][1]['value'], $B['tiles'][2]['value'], in_array('Zusätzlich über evendis.de verkaufen', array_column($B['block1']['rows'], 'name'), true), (bool) array_filter(array_column($B['block1']['rows'], 'tiered'))]);
$pdf = $O::render_pdf($d7, ['number' => 'AN-T-1', 'date' => wp_date('Y-m-d')]);
$cls = $O::render_pdf($O::clean_data(['layout' => 'classic'], $d7), ['number' => 'AN-T-1', 'date' => wp_date('Y-m-d')]);
$eq('PDF neu und klassisch erzeugt (unterschiedlich)', [true, true, true], [strpos((string) $pdf, '%PDF') === 0, strpos((string) $cls, '%PDF') === 0, $pdf !== $cls]);

// ── REST (Plattform)
if (TIX_Platform_Offers::multi()) {
    $rest = function ($m, $r, $b = null) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); return [$res->get_status(), $res->get_data()]; };
    [$st, $r] = $rest('POST', '/platform/offers/calc', ['items' => [['catalog_key' => 'shop']], 'price_overrides' => ['shop_tiers' => [['max' => 100, 'price' => -1]]]]);
    $eq('Ungültige Staffel → 400 am Feld', [400, 'price_overrides.shop_tiers.0.price'], [$st, $r['data']['field'] ?? '']);
    [$st, $r] = $rest('POST', '/platform/offers/calc', ['items' => [['catalog_key' => 'shop']], 'layout' => 'bunt']);
    $eq('Ungültige Gliederung → 400 layout', [400, 'layout'], [$st, $r['data']['field'] ?? '']);
    [$st, $r] = $rest('POST', '/platform/offers', ['customer' => ['company' => 'Opt-Test', 'email' => 'opt@example.org'], 'items' => [['catalog_key' => 'shop', 'interval' => 'yearly'], ['catalog_key' => 'app'], ['catalog_key' => 'hosting', 'on' => false]],
        'extras' => ['evendis_channel' => true], 'price_overrides' => ['items' => ['app' => 129]]]);
    $o = $r['offer'] ?? $r; $oid = intval($o['id'] ?? 0);
    $ops = array_column((array) ($o['data']['options'] ?? []), 'on', 'label');
    $eq('Anlegen: Häkchen (App-Einrichtung automatisch dazu), Preis App 129, Hosting aus, evendis.de an', [201, true, false, true, 129.0],
        [$st, $ops['App-Einrichtung'] ?? null, $ops['Hosting'] ?? null, $ops['Zusätzlich über evendis.de verkaufen'] ?? null, floatval(array_column((array) ($o['data']['items'] ?? []), 'price', 'catalog_key')['app'] ?? 0)]);
    if ($oid) {
        global $wpdb;
        $wpdb->update(TIX_Platform_Offers::table(), ['status' => 'sent', 'token' => 'optt' . $oid], ['id' => $oid]);
        $pv = $O::public_view($O::get($oid));
        $eq('Online-Ansicht: selfhosted mit Kacheln und plan_calc, Hosting nicht in den Positionen', ['selfhosted', 3, true, false],
            [$pv['layout'], count($pv['selfhosted']['tiles'] ?? []), isset($pv['selfhosted']['plan_calc']['rows']), in_array('Hosting', array_column($pv['items'], 'name'), true)]);
        $m = new ReflectionMethod('TIX_Platform_Offers', 'page_html'); $m->setAccessible(true);
        $html = (string) $m->invoke(null, $O::get($oid), 'optt' . $oid, '');
        $eq('Online-Seite: „Dein eigener Ticketshop“, Blöcke 1–4, kein Hosting', [true, true, true, false],
            [strpos($html, 'Dein eigener Ticketshop') !== false, strpos($html, 'Was du an deinen Zahlungsdienstleister zahlst') !== false, strpos($html, 'Planrechnung') !== false, strpos($html, '>Hosting<') !== false]);
        [$st] = $rest('PUT', "/platform/offers/$oid", ['options' => []]);
        $wpdb->delete(TIX_Platform_Offers::table(), ['id' => $oid]);
    }
} else echo "skip  REST: /platform/offers nur auf der Plattform\n";
wp_set_current_user(0);
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
