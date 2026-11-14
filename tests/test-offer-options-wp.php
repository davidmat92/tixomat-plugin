<?php
/**
 * AN2 (1.38.817): Häkchen je Option, frei gesetzte Preise, Gliederung „Dein eigener Ticketshop“ (PDF + Online-Ansicht).
 * 1.38.818: Betriebsart (platform | own_shop | self_hosted), Rundum-Einrichtung, Referenzen.
 * 1.38.819: App nur beim eigenen Betrieb, ein Einrichtungsposten je Betriebsart, Summen-Zusammenfassung plan_calc.totals.
 * 1.38.821: schnell anlegen, Prüfung nur beim Versenden. 1.38.822: führende Stufe (plan.lead) mit Faktenblock und Erklärung.
 *   wp eval-file …/test-offer-options-wp.php (Teil REST nur auf der Plattform)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Offer_Plan') || !method_exists('TIX_Platform_Offers', 'lead_texts')) { echo "Tixomat ab 1.38.822 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$O = 'TIX_Platform_Offers';
$it = function ($k, $iv = null, array $x = []) use ($O) { return array_merge($O::item_from_catalog($k, $iv), $x); };
$adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($adm);

// ── Häkchen
$d = $O::clean_data(['customer' => ['company' => 'Opt'], 'items' => [$it('shop', 'yearly'), $it('app'), $it('hosting', null, ['price' => 50, 'on' => false])]]);
$t = $O::totals($d);
$eq('Nicht angehakt: zählt nicht in den Summen, Zeile bleibt (on false); Einrichtung inklusive 0 €', [790.0 + 149 * 12, 0.0, 4, false], [$t['yearly']['net'], $t['once']['net'], count($t['lines']), $t['lines'][2]['on']]);
$eq('Häkchen-Liste: Positionen + Zusatz-Optionen', [['Eigener Shop', true], ['Eigene App – Basis', true], ['Hosting', false], ['Rundum-Einrichtung durch evendis', true], ['Zusätzlich über evendis.de verkaufen', false], ['Einrichtung kostenlos (selbstgehostet)', false]], array_map(function ($o) { return [$o['label'], $o['on']]; }, $d['options']));
$d2 = $O::clean_data(['options' => ['shop', 'evendis_channel']], $d);
$eq('options als Schlüssel-Liste: genau diese angehakt (Einrichtung bleibt beim eigenen Shop immer)', [true, false, false, true, true], [$d2['items'][0]['on'], $d2['items'][1]['on'], $d2['items'][2]['on'], $d2['items'][3]['on'], $d2['extras']['evendis_channel']]);
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
$eq('Gliederung auto: Shop → own, nur Tickets über evendis → classic, Schalter classic', ['own', 'classic', 'classic'],
    [$O::layout_of($d7), $O::layout_of($O::clean_data(['items' => [$it('tickets')]])), $O::layout_of($O::clean_data(['layout' => 'classic'], $d7))]);
$eq('Shop nicht angehakt, ohne Planrechnung → classic', 'classic', $O::layout_of($O::clean_data(['options' => ['app'], 'plan' => null], $d7)));
$B = $O::own_blocks($d7);
$eq('Kacheln + Blöcke: 790 €/Jahr, Mischsatz, lohnt sich ab 4.970; Block 1 ohne Hosting, mit evendis.de-Zeile', ['790 € / Jahr', '2,51 % + 0,33 €', '4.970 Tickets/Jahr', true, true],
    [$B['tiles'][0]['value'], $B['tiles'][1]['value'], $B['tiles'][2]['value'], in_array('Zusätzlich über evendis.de verkaufen', array_column($B['block1']['rows'], 'name'), true), (bool) array_filter(array_column($B['block1']['rows'], 'tiered'))]);
$pdf = $O::render_pdf($d7, ['number' => 'AN-T-1', 'date' => wp_date('Y-m-d')]);
$cls = $O::render_pdf($O::clean_data(['layout' => 'classic'], $d7), ['number' => 'AN-T-1', 'date' => wp_date('Y-m-d')]);
$eq('PDF neu und klassisch erzeugt (unterschiedlich)', [true, true, true], [strpos((string) $pdf, '%PDF') === 0, strpos((string) $cls, '%PDF') === 0, $pdf !== $cls]);


// ── 1.38.818 Betriebsart
$eq('Betriebsart abgeleitet: Shop → own_shop, Lizenz → self_hosted, sonst platform; gewählt bleibt gewählt', ['own_shop', 'self_hosted', 'platform', 'self_hosted', true],
    [$O::clean_data(['items' => [$it('shop')]])['mode'], $O::clean_data(['items' => [$it('lizenz')]])['mode'], $O::clean_data(['items' => [$it('tickets')]])['mode'],
     $O::clean_data(['intro' => 'x'], $O::clean_data(['items' => [$it('shop')], 'mode' => 'self_hosted']))['mode'], $O::clean_data(['items' => [$it('shop')], 'mode' => 'self_hosted'])['mode_chosen']]);
$eq('Lizenz-Abgleich: own_shop = managed, self_hosted = selfhosted', ['managed', 'selfhosted', null], [$O::MODE_LICENSE['own_shop'], $O::MODE_LICENSE['self_hosted'], $O::MODE_LICENSE['platform']]);
$eq('Hosting nur beim eigenen Shop (Fehler), Lizenz im eigenen Shop (Hinweis)', ['tix_offer_item_mode', 'items.1', ['tix_offer_mode_mismatch']],
    [($e1 = $O::mode_error($O::clean_data(['items' => [$it('lizenz'), $it('hosting')]]))) ? $e1->get_error_code() : '', $e1 ? $e1->get_error_data()['field'] : '', array_column($O::mode_hints($O::clean_data(['items' => [$it('lizenz')], 'mode' => 'own_shop'])), 'code')]);
$ds = $O::clean_data(['items' => [$it('lizenz', 'yearly'), $it('app')], 'mode' => 'self_hosted', 'plan' => [], 'price_overrides' => ['server_month' => 30]]);
$ps = $O::plan_result($ds);
$eq('Selbstgehostet „wie eigener Shop plus Server“: Staffel 790 … 2.490 €, Server 30 × 12 = 360 € (nicht an evendis), Vergleich eigener Shop 790 € ohne Server', ['self_hosted', 790.0, 360.0, 2490.0, 790.0, 0.0],
    [$ps['mode'], $ps['rows'][0]['evendis'], $ps['rows'][0]['server'], $ps['rows'][3]['evendis'], $ps['rows'][0]['compare']['own_shop']['evendis'], $ps['rows'][0]['compare']['own_shop']['server']]);
$eq('Bleibt selbstgehostet = Umsatz + Gebühr − Zahlung − 790 − App 1.788 − Server 360; Lizenz-Listenpreis jährlich = 1. Stufe', [round(25000 + 1500 - $ps['rows'][0]['psp'] - 790 - 1788 - 360, 2), 790.0], [$ps['rows'][0]['kept'], $ds['items'][0]['list_price']]);
$eq('Break-even je Betriebsart vorhanden', [true, true], [isset($ps['by_mode']['own_shop']['break_even']['with_fee']), isset($ps['by_mode']['self_hosted']['break_even']['with_fee'])]);
$eq('Ungültige Betriebsart → 400 mode', 'mode', $O::plan_error(['mode' => 'cloud'])->get_error_data()['field'] ?? '');
$Bs = $O::own_blocks($ds);
$eq('PDF-Texte je Betriebsart: Titel, Server-Zeile als Info, Vergleich eigener Shop', ['Dein Ticketshop auf deinem Server', true, 'Eigener Shop'],
    [$Bs['title'], (bool) array_filter($Bs['block1']['rows'], function ($r) { return !empty($r['info']) && strpos($r['name'], 'Server') === 0; }), $Bs['other_label']]);
$eq('Plattform-Angebot → klassische Gliederung', 'classic', $O::layout_of($O::clean_data(['items' => [$it('tickets')], 'plan' => []])));


// ── 1.38.819 Regeln je Betriebsart + Summen
$eins = function ($d) { foreach ($d['items'] as $x) if ($x['catalog_key'] === 'einrichtung') return [$x['on'], $x['price'] !== null ? floatval($x['price']) : $x['list_price'], $x['note']]; return null; };
$dsh = $O::clean_data(['items' => [$it('lizenz', 'yearly')], 'mode' => 'self_hosted']);
$eq('Selbstgehostet: Einrichtung automatisch 190 €; Häkchen „Einrichtung kostenlos“ → 0 € „erlassen“; Preis frei bleibt möglich', [[true, 190.0], [true, 0.0, 'erlassen'], 150.0],
    [array_slice($eins($dsh), 0, 2), $eins($O::clean_data(['extras' => ['setup_free' => true]], $dsh)), $eins($O::clean_data(['price_overrides' => ['items' => ['einrichtung' => 150]]], $dsh))[1]]);
$eq('Erlassen wieder aus → 190 €, Hinweis weg', [true, 190.0, ''], $eins($O::clean_data(['extras' => ['setup_free' => false]], $O::clean_data(['extras' => ['setup_free' => true]], $dsh))));
$eq('Wechsel eigener Shop → selbstgehostet: 190 € statt der erzwungenen 0 €', [true, 190.0, ''], $eins($O::clean_data(['mode' => 'self_hosted'], $O::clean_data(['items' => [$it('shop', 'yearly')], 'mode' => 'own_shop']))));
$eq('Eigener Shop: Einrichtung immer 0 € „inklusive“ (auch mit Preis 500); Plattform ohne Einrichtung', [[true, 0.0, 'inklusive beim eigenen Shop'], null],
    [$eins($O::clean_data(['items' => [$it('shop', 'yearly'), $it('einrichtung', null, ['price' => 500])], 'mode' => 'own_shop'])), $eins($O::clean_data(['items' => [$it('tickets')]]))]);
$eq('Nur EIN Einrichtungsposten, auch bei doppelter Eingabe nicht mehr', 1, count(array_filter($O::clean_data(['items' => [$it('shop', 'yearly'), $it('einrichtung')], 'mode' => 'own_shop'])['items'], function ($x) { return $x['catalog_key'] === 'einrichtung'; })));
$eq('App bei Plattform → Fehler, beim eigenen Shop erlaubt', ['tix_offer_item_mode', null], [($e2 = $O::mode_error($O::clean_data(['items' => [$it('tickets'), $it('app')]]))) ? $e2->get_error_code() : '', $O::mode_error($O::clean_data(['items' => [$it('shop'), $it('app')]]))]);
$Bsh = $O::own_blocks($O::clean_data(['items' => [$it('lizenz', 'yearly')], 'mode' => 'self_hosted', 'benefits' => []]));
$eq('Selbstgehostet: Vorteil „Deine Daten liegen allein bei dir“ immer dabei', 'Deine Daten liegen allein bei dir', $Bsh['benefits'][0]['title'] ?? '');
$dt = $O::clean_data(['items' => [$it('lizenz', 'yearly'), $it('app')], 'mode' => 'self_hosted', 'plan' => ['steps' => [1000]]]);
$tt = $O::plan_result($dt)['totals'][0]['modes'];
$psp = $O::plan_result($dt)['rows'][0]['psp'];
$eq('Summen selbstgehostet 1.000 Tickets: an evendis 790 + 1.788 + 190 = 2.768, Hoster 240, bleibt 25.000 + 1.500 − 2.768 − Zahlung − 240', [2768.0, 190.0, 240.0, round(26500 - 2768 - $psp - 240, 2)],
    [$tt['self_hosted']['to_evendis'], $tt['self_hosted']['to_evendis_once'], $tt['self_hosted']['to_hoster'], $tt['self_hosted']['kept']]);
$eq('Vergleich eigener Shop: 790 + 1.788, Einrichtung 0, kein Hoster', [2578.0, 0.0, 0.0], [$tt['own_shop']['to_evendis'], $tt['own_shop']['to_evendis_once'], $tt['own_shop']['to_hoster']]);
$eq('Plattform (du trägst): an evendis 1.000 × (25 × 2,9 % + 0,49)/1,19 = 1.021,01, keine Zahlungskosten, je Ticket und Prozent', [1021.01, 0.0, 23978.99, 23.98, 95.9, 25000.0],
    [$tt['platform']['to_evendis'], $tt['platform']['to_psp'], $tt['platform']['kept'], $tt['platform']['kept_per_ticket'], $tt['platform']['kept_percent'], $tt['platform']['guests_pay_variant']['kept']]);
$eq('Selbstgehostet erlassen: einmalig 0 im Vergleich', 0.0, $O::plan_result($O::clean_data(['extras' => ['setup_free' => true]], $dt))['totals'][0]['modes']['self_hosted']['to_evendis_once']);
$eq('Reihenfolge im Überblick: Angebots-Betriebsart zuerst', ['self_hosted', 'own_shop', 'platform'], $O::own_blocks($dt)['block4']['totals_order']);


// ── 1.38.822 führende Stufe
$C = $O::catalog();
$pl = TIX_Offer_Plan::compute(TIX_Offer_Plan::clean([], $C), $C);
$eq('Vorgabe führend: 5.000 Tickets (in den Stufen), Stufen unverändert', [5000, [1000, 5000, 20000, 50000]], [$pl['lead']['tickets'], array_column($pl['rows'], 'tickets')]);
$pl2 = TIX_Offer_Plan::compute(TIX_Offer_Plan::clean(['lead' => 8000], $C), $C);
$eq('Gewählt 8.000 → als Stufe ergänzt, Faktenblock = Summen-Zeile', [8000, true, true], [$pl2['lead']['tickets'], in_array(8000, array_column($pl2['rows'], 'tickets'), true),
    $pl2['lead']['kept'] === $pl2['totals'][array_search(8000, array_column($pl2['totals'], 'tickets'), true)]['modes']['own_shop']['kept']]);
$eq('Eigene Zahl ist führend, wenn nichts gewählt', 3000, TIX_Offer_Plan::compute(TIX_Offer_Plan::clean(['custom' => 3000], $C), $C)['lead']['tickets']);
$eq('Ungültig → 400 plan.lead', 'plan.lead', TIX_Offer_Plan::clean(['lead' => 0], $C)->get_error_data()['field'] ?? '');
$L = $pl['lead'];
$eq('Faktenblock 5.000: an evendis 790, Zahlung 4.166,05, bleibt 127.543,95, je Ticket 25,51; gegenüber Plattform (du trägst) + 7.648,99, (Gäste zahlen) + 2.543,95', [790.0, 4166.05, 127543.95, 25.51, 7648.99, 2543.95],
    [$L['to_evendis'], $L['to_psp'], $L['kept'], $L['kept_per_ticket'], $L['savings_vs_platform'], $L['savings_vs_platform_guests']]);
$eq('Beispiel einer Bestellung: 2 × (25 + 1,50) = 53 €, PayPal 1,97 €, Karte 1,20 €, Mittel 1,67 € (× 2.500 = Zahlung)', [53.0, [1.97, 1.2], 1.67, true],
    [$L['order_example']['gross'], array_column($L['order_example']['methods'], 'fee'), $L['order_example']['blended_fee'], abs($L['orders'] * 53 * 0.02514 + $L['orders'] * 0.334 - $L['to_psp']) < 1]);
$dp = $O::clean_data(['items' => [$it('shop', 'yearly')], 'mode' => 'own_shop', 'projection' => ['model' => 'selfhosted', 'tickets' => 400, 'avg_price' => 20, 'events_year' => 10]]);
$eq('Ohne Wahl: führend aus der Hochrechnung des Angebots (400 × 10 = 4.000)', 4000, $O::plan_for_offer($dp)['lead']['tickets']);
$Bl = $O::own_blocks($dp);
$eq('Texte: Überschrift, „dir bleiben“ hervorgehoben, Schritte von Ticketeinnahmen bis Vergleich', ['Bei 4.000 Tickets im Jahr', true, 'Deine Ticketeinnahmen', 'Zum Vergleich: die Ticketplattform', true],
    [$Bl['lead']['headline'], (bool) array_filter($Bl['lead']['facts'], function ($f) { return $f['strong'] && $f['label'] === 'dir bleiben'; }), $Bl['lead']['steps'][0]['title'] ?? '', end($Bl['lead']['steps'])['title'] ?? '', strpos($Bl['lead']['glossary'], 'Netto = ohne Mehrwertsteuer') === 0]);
$Bs2 = $O::own_blocks($O::clean_data(['items' => [$it('lizenz', 'yearly')], 'mode' => 'self_hosted', 'plan' => []]));
$eq('Selbstgehostet: Hoster als eigener Fakt und eigener Schritt', [true, true], [in_array('an deinen Hoster (Server, ca.)', array_column($Bs2['lead']['facts'], 'label'), true), in_array('Dein Server', array_column($Bs2['lead']['steps'], 'title'), true)]);
$Bq = $O::own_blocks($O::clean_data(['items' => [$it('shop', 'yearly')], 'mode' => 'own_shop', 'plan' => ['lead' => 80000]]));
$eq('Über der Staffel führend → „individuelles Angebot“ statt Zahlen', [true, []], [$Bq['lead']['on_request'], $Bq['lead']['facts']]);
$pdf_l = (string) $O::render_pdf($dp, ['number' => 'AN-T-3', 'date' => wp_date('Y-m-d')]);
$pdf_lt = (function ($b) { $t = ''; if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $b, $m)) foreach ($m[1] as $z) { $u = @gzuncompress($z); $t .= $u !== false ? $u : $z; } return $t; })($pdf_l);
$eq('PDF: Faktenblock und Erklärung', [true, true], [strpos($pdf_l, '%PDF') === 0, stripos($pdf_lt, 'So kommen die Zahlen zustande') !== false]);

// ── Rundum-Einrichtung
$de = $O::clean_data(['items' => [$it('shop', 'yearly'), $it('einrichtung', null, ['price' => 990])], 'mode' => 'own_shop']);
$Be = $O::own_blocks($de);
$eq('Rundum-Einrichtung: Botschaft, Schritte je Betriebsart (eigener Shop: Hosting bei uns, kein App-Schritt ohne App), was beim Kunden bleibt', [true, true, false, false, true],
    [strpos($Be['setup']['headline'], 'kein technisches Wissen') !== false, in_array('Server und Hosting bei uns einrichten', $Be['setup']['steps'], true),
     (bool) preg_grep('/Hoster/', $Be['setup']['steps']), (bool) preg_grep('/App-Store/', $Be['setup']['steps']), (bool) preg_grep('/Zahlungsanbieter.*selbst abschließen/', $Be['setup']['customer_tasks'])]);
$Bh = $O::own_blocks($O::clean_data(['items' => [$it('lizenz', 'yearly'), $it('app'), $it('einrichtung')], 'mode' => 'self_hosted']));
$eq('Selbstgehostet mit App: Server beim Hoster einrichten, App-Store-Schritt, Kunde mietet den Server', [true, true, true],
    [(bool) preg_grep('/Hoster/', $Bh['setup']['steps']), (bool) preg_grep('/App-Store/', $Bh['setup']['steps']), (bool) preg_grep('/Server bei deinem Hoster mieten/', $Bh['setup']['customer_tasks'])]);
$eq('Eigener Shop: Einrichtung immer drin (auch ohne Häkchen), 0 € trotz Preis 990; Katalog: eine Position „einrichtung“', [true, 0.0, 1],
    [(bool) $O::own_blocks($O::clean_data(['options' => ['shop']], $de))['setup'], $O::totals($de)['once']['net'], count(array_filter($O::catalog()['items'], function ($x) { return $x['key'] === 'einrichtung'; }))]);

// ── Referenzen (Katalog im Test, danach zurück)
$bak = get_option($O::OPT_CATALOG, null);
$c = $O::catalog();
$c['references'] = [['key' => 'kk', 'customer_key' => 'p-test', 'name' => 'KitchenKlub', 'url' => 'https://kitchenklub.de', 'text' => 'Club, eigene App, seit 2026', 'mode' => 'own_shop', 'approved' => true],
    ['key' => 'zu', 'name' => 'Noch nicht gefragt', 'url' => 'http://unsicher.de', 'approved' => false]];
$cc = $O::clean_catalog($c);
$eq('Katalog-Referenzen: Freigabe Vorgabe aus, nur https-Links', [true, false, ''], [$cc['references'][0]['approved'], $cc['references'][1]['approved'], $cc['references'][1]['url']]);
update_option($O::OPT_CATALOG, $cc, false);
$cs = $O::clean_catalog(array_diff_key($O::catalog(), ['references' => 1]));
$eq('Katalog ohne Feld references speichern löscht keine Referenzen', 2, count($cs['references']));
$dr = $O::clean_data(['items' => [$it('shop', 'yearly')], 'references' => ['zu', 'kk', 'kk']]);
$eq('Angebot: nur freigegebene Referenzen, ohne Doppelte; Route lehnt nicht freigegebene ab', [['kk'], 'references.0'], [$dr['references'], $O::plan_error(['references' => ['zu']])->get_error_data()['field'] ?? '']);
$Br = $O::own_blocks($dr);
$eq('Block „So sieht es bei anderen aus“: Name, Satz, Link, ohne Kundenschlüssel und Freigabe-Feld', ['KitchenKlub', 'Club, eigene App, seit 2026', 'https://kitchenklub.de', false, false],
    [$Br['references'][0]['name'] ?? '', $Br['references'][0]['text'] ?? '', $Br['references'][0]['url'] ?? '', isset($Br['references'][0]['customer_key']), isset($Br['references'][0]['approved'])]);
$pdf_r = (string) $O::render_pdf($dr, ['number' => 'AN-T-2', 'date' => wp_date('Y-m-d')]);
$eq('PDF mit Referenz erzeugt', true, strpos($pdf_r, '%PDF') === 0);
if ($bak === null) delete_option($O::OPT_CATALOG); else update_option($O::OPT_CATALOG, $bak, false);

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
        $eq('Online-Ansicht: own mit Kacheln und plan_calc, Hosting nicht in den Positionen', ['own', 3, true, false],
            [$pv['layout'], count($pv['own']['tiles'] ?? []), isset($pv['own']['plan_calc']['rows']), in_array('Hosting', array_column($pv['items'], 'name'), true)]);
        $m = new ReflectionMethod('TIX_Platform_Offers', 'page_html'); $m->setAccessible(true);
        $html = (string) $m->invoke(null, $O::get($oid), 'optt' . $oid, '');
        $eq('Online-Seite: Faktenblock und Erklärung oben', [true, true], [strpos($html, 'das zahlst du, das bleibt dir') !== false, strpos($html, 'So kommen die Zahlen zustande') !== false && strpos($html, 'So kommen die Zahlen zustande') < strpos($html, 'Was du an evendis zahlst')]);
        $eq('Online-Seite: „Dein eigener Ticketshop“, Blöcke 1–4, kein Hosting', [true, true, true, false],
            [strpos($html, 'Dein eigener Ticketshop') !== false, strpos($html, 'Was du an deinen Zahlungsdienstleister zahlst') !== false, strpos($html, 'Planrechnung') !== false, strpos($html, '>Hosting<') !== false]);
        // 1.38.821: schnell anlegen nur mit Namen; PDF immer, Prüfung nur beim Versenden (Empfänger, Betriebsart, Positionen, Preise)
        [$st, $r] = $rest('POST', '/platform/offers', ['name' => 'Schnell-Club']);
        $q = $r['offer'] ?? []; $qid = intval($q['id'] ?? 0);
        $eq('Anlegen nur mit Namen: 201 Entwurf, Name als Firma, unvollständig (email, mode, items)', [201, 'draft', 'Schnell-Club', false, ['email', 'mode', 'items']], [$st, $q['status'] ?? '', $q['company'] ?? '', $q['complete'] ?? null, $q['missing'] ?? null]);
        [$st, $r] = $rest('POST', "/platform/offers/$qid/send", ['confirm' => true]);
        $eq('Senden unvollständig → 422 tix_offer_incomplete mit missing[] und field', [422, 'tix_offer_incomplete', ['customer.email', 'mode', 'items'], 'customer.email'], [$st, $r['code'] ?? '', array_column((array) ($r['data']['missing'] ?? []), 'field'), $r['data']['field'] ?? '']);
        $x = new WP_REST_Request('GET', "/tixomat/v1/platform/offers/$qid/pdf"); $x->set_query_params(['customer' => 1]); $rx = rest_do_request($x);
        $pdf_b = base64_decode((string) ($rx->get_data()['data'] ?? ''));
        $pdf_t = (function ($b) { $t = ''; if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $b, $m)) foreach ($m[1] as $z) { $u = @gzuncompress($z); $t .= $u !== false ? $u : $z; } return $t; })($pdf_b);
        $eq('PDF auch unvollständig immer exportierbar, ohne Entwurf-Hinweis', [200, true, false], [$rx->get_status(), strpos($pdf_b, '%PDF') === 0, stripos($pdf_t, 'Entwurf') !== false]);
        [$st, $r] = $rest('POST', '/platform/offers/preview', ['customer' => ['company' => 'Vorschau']]);
        $pv_t = (function ($b) { $t = ''; if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $b, $m)) foreach ($m[1] as $z) { $u = @gzuncompress($z); $t .= $u !== false ? $u : $z; } return $t; })(base64_decode((string) ($r['data'] ?? '')));
        $eq('Vorschau ohne Speichern: ohne „Entwurf“, Dateiname Angebot.pdf', [200, false, 'Angebot.pdf'], [$st, stripos($pv_t, 'Entwurf') !== false, $r['filename'] ?? '']);
        [$st, $r] = $rest('POST', "/platform/offers/$qid", ['mode' => 'own_shop', 'customer' => ['company' => 'Schnell-Club', 'email' => 'schnell@example.org']]);
        $eq('Betriebsart gewählt ohne Positionen → Grundpaket aus den Vorgaben (Eigener Shop 790 €), vollständig', [200, true, [], true],
            [$st, $r['offer']['complete'] ?? null, $r['offer']['missing'] ?? null, in_array('shop', array_column((array) ($r['offer']['data']['items'] ?? []), 'catalog_key'), true)]);
        [, $l] = $rest('GET', '/platform/offers');
        $row = null; foreach ((array) ($l['offers'] ?? []) as $o) if (intval($o['id'] ?? 0) === $qid) $row = $o;
        $eq('Liste: complete und missing', [true, []], [$row['complete'] ?? null, $row['missing'] ?? null]);
        global $wpdb; if ($qid) $wpdb->delete(TIX_Platform_Offers::table(), ['id' => $qid]);
        $eq('Fehlender Preis als items.<i>.price mit item_id', ['price', 'items.0.price'], (function () use ($O, $it) { $m = $O::missing($O::clean_data(['customer' => ['company' => 'X', 'email' => 'x@example.org'], 'mode' => 'platform', 'items' => [$it('datenumzug')]])); return [$m[0]['code'] ?? '', $m[0]['field'] ?? '']; })());
        [$st, $r] = $rest('GET', '/platform/offer-references');
        $eq('GET /platform/offer-references: Katalog-Referenzen und Vorschläge mit Betriebsart', [200, true, true], [$st, is_array($r['references'] ?? null), is_array($r['candidates'] ?? null) && (!$r['candidates'] || isset($r['candidates'][0]['mode'], $r['candidates'][0]['live']))]);
        [$st, $r] = $rest('POST', '/platform/offers/calc', ['items' => [['catalog_key' => 'lizenz'], ['catalog_key' => 'hosting']]]);
        $eq('calc: Hosting bei selbstgehostet → 400 tix_offer_item_mode', [400, 'tix_offer_item_mode'], [$st, $r['code'] ?? '']);
        [$st, $r] = $rest('POST', '/platform/offers', ['customer' => ['company' => 'App-Plattform'], 'items' => [['catalog_key' => 'tickets'], ['catalog_key' => 'app']]]);
        $eq('App bei Plattform → 400 am Feld', [400, 'tix_offer_item_mode', 'items.1'], [$st, $r['code'] ?? '', $r['data']['field'] ?? '']);
        $wpdb->delete(TIX_Platform_Offers::table(), ['id' => $oid]);
    }
} else echo "skip  REST: /platform/offers nur auf der Plattform\n";
wp_set_current_user(0);
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
