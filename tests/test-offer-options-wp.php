<?php
/**
 * AN2 (1.38.817): Häkchen je Option, frei gesetzte Preise, Gliederung „Dein eigener Ticketshop“ (PDF + Online-Ansicht).
 * 1.38.818: Betriebsart (platform | own_shop | self_hosted), Rundum-Einrichtung, Referenzen.
 * 1.38.819: App nur beim eigenen Betrieb, ein Einrichtungsposten je Betriebsart, Summen-Zusammenfassung plan_calc.totals.
 * 1.38.821: schnell anlegen, Prüfung nur beim Versenden. 1.38.822: führende Stufe (plan.lead) mit Faktenblock und Erklärung.
 * 1.38.833: „So kommen die Zahlen zustande“ auf eigener Seite.
 * 1.38.832: Referenzen im Kurzangebot.
 * 1.38.831: kein Vorsteuer-Satz, Kurzangebot (length short, ≤ 2 Seiten).
 * 1.38.830: App-Vorteile mit Etikett „mit App-Paket“.
 * 1.38.829: Markenrot, Planrechnung nur Grundpaket, App-Gruppe („nur mit App“), Ticketpreis als Annahme, Angebotsnummern AN-JJMM-NNNN.
 * 1.38.828: eine Planrechnungs-Tabelle, kurze Hinweise, Bedingungen ohne Plattform-Satz.
 * 1.38.827: Rabatt je Position (discount_percent), Guthaben braucht die App (Zubuchung), Shop ohne App; geschenkte Positionen (items[].gift bzw. Modul mit 0 €); kein Vergleich der Betriebsarten, Schrift Geist + Geist Mono, Rundungen und Abstände wie im Studio.
 * 1.38.826: PayPal als eigener Zahlungsanbieter, Verkauf über evendis.de nur mit Häkchen (unter „Optional“).
 * 1.38.825: Staffel 790/1.690 (bis 5.000/10.000, darüber Anfrage), Vertriebler (sales_reps/sales_rep), Blöcke nie über Seitengrenzen.
 * 1.38.823: Jahresvertrag nach Staffel (nie 12 × Monat), „Optional dazubuchbar“ (totals.base/with_options), Plattform-Vergleich nur mit compare_platform.
 *   wp eval-file …/test-offer-options-wp.php (Teil REST nur auf der Plattform)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Offer_Plan') || !method_exists('TIX_Platform_Offers', 'strip_platform')) { echo "Tixomat ab 1.38.823 nötig\n"; return; }
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
$eq('Positions-Preis frei (Modul ohne Listenpreis 25 €/Monat), Staffel frei sortiert, Listenpreis Shop = Stufe der führenden Zahl (Vorgabe 5.000 → 990, ab 1.38.823)', [25.0, 990.0, [3000, 10000], 990.0 + 300.0],
    [$d4['items'][1]['price'], $d4['items'][0]['list_price'], array_column($d4['price_overrides']['shop_tiers'], 'max'), $t4['yearly']['net']]);
$pc = $O::plan_result(array_merge($d4, ['plan' => TIX_Offer_Plan::clean(['steps' => [1000, 5000, 20000], 'channel_share' => 20], $O::catalog())]));
$eq('Planrechnung: eigene Staffel (590/990/auf Anfrage), Optionen 300 €/Jahr, Kanal-Gebühr frei', [590.0, 990.0, null, 300.0, ['percent' => 2.5, 'fixed' => 0.39]],
    [$pc['rows'][0]['evendis'], $pc['rows'][1]['evendis'], $pc['rows'][2]['evendis'], $pc['rows'][0]['options'], array_intersect_key((array) $pc['to_evendis']['channel'], ['percent' => 1, 'fixed' => 1])]);
$eq('Kanal-Gebühr netto: 1.000 × 20 % × (25 × 2,5 % + 0,39) / 1,19 = 170,59', 170.59, $pc['rows'][0]['channel_fee']);
$eq('Bleibt = Umsatz + Gebühr − Zahlung − Shop − Optionen − Kanal', round($pc['rows'][0]['revenue'] + $pc['rows'][0]['own_fee'] - $pc['rows'][0]['psp'] - 590 - 300 - 170.59, 2), $pc['rows'][0]['kept']);
$d5 = $O::clean_data(['items' => [$it('shop', 'yearly', ['price' => 1200])], 'plan' => []]);
$p5 = $O::plan_result(array_merge($d5, ['plan' => TIX_Offer_Plan::clean([], $O::catalog())]));
$eq('Shop-Preis frei → fester Jahrespreis in allen Stufen, auch über 10.000 keine Anfrage', [1200.0, 1200.0], [$p5['rows'][0]['evendis'], $p5['rows'][2]['evendis']]);
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
$eq('Kacheln + Blöcke: 790 €/Jahr, Mischsatz, ohne Plattform-Vergleich „Dir bleiben je Ticket“ (1.38.823); Block 1 ohne Hosting, mit evendis.de-Zeile', ['790 € / Jahr', '2,51 % + 0,33 €', 'Dir bleiben je Ticket', true, true],
    [$B['tiles'][0]['value'], $B['tiles'][1]['value'], $B['tiles'][2]['title'], in_array('Zusätzlich über evendis.de verkaufen', array_column($B['block1']['rows'], 'name'), true), (bool) array_filter(array_column($B['block1']['rows'], 'tiered'))]);
$eq('Mit compare_platform: Kachel „lohnt sich ab“ – Planrechnung nur mit dem Grundpaket (ab 1.38.829: 1.185 statt 4.970 mit Optionen)', '1.185 Tickets/Jahr', $O::own_blocks($O::clean_data(['compare_platform' => true], $d7))['tiles'][2]['value']);
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
$eq('Selbstgehostet „wie eigener Shop plus Server“: Staffel 790 … 1.690 €, Server 30 × 12 = 360 € (nicht an evendis), Vergleich eigener Shop 790 € ohne Server', ['self_hosted', 790.0, 360.0, 1690.0, 790.0, 0.0],
    [$ps['mode'], $ps['rows'][0]['evendis'], $ps['rows'][0]['server'], $ps['rows'][2]['evendis'], $ps['rows'][0]['compare']['own_shop']['evendis'], $ps['rows'][0]['compare']['own_shop']['server']]);
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
$eq('Überblick nur mit der Betriebsart des Angebots (kein Vergleich eigener Shop ↔ selbstgehostet, 1.38.827); Plattform nur mit compare_platform', [['self_hosted'], ['self_hosted', 'platform']],
    [$O::own_blocks($dt)['block4']['totals_order'], $O::own_blocks($O::clean_data(['compare_platform' => true], $dt))['block4']['totals_order']]);


// ── 1.38.822 führende Stufe
$C = $O::catalog();
$pl = TIX_Offer_Plan::compute(TIX_Offer_Plan::clean([], $C), $C);
$eq('Vorgabe führend: 5.000 Tickets (in den Stufen), Stufen unverändert (1.000/5.000/10.000 ab 1.38.825)', [5000, [1000, 5000, 10000]], [$pl['lead']['tickets'], array_column($pl['rows'], 'tickets')]);
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
$eq('Texte: Überschrift, „dir bleiben“ hervorgehoben, Schritte von Ticketeinnahmen bis „Was dir bleibt“ (ohne Plattform-Vergleich, 1.38.823)', ['Bei 4.000 Tickets im Jahr', true, 'Deine Ticketeinnahmen', 'Was dir bleibt', true],
    [$Bl['lead']['headline'], (bool) array_filter($Bl['lead']['facts'], function ($f) { return $f['strong'] && $f['label'] === 'dir bleiben'; }), $Bl['lead']['steps'][0]['title'] ?? '', end($Bl['lead']['steps'])['title'] ?? '', strpos($Bl['lead']['glossary'], 'Netto = ohne Mehrwertsteuer') === 0]);
$Bs2 = $O::own_blocks($O::clean_data(['items' => [$it('lizenz', 'yearly')], 'mode' => 'self_hosted', 'plan' => []]));
$eq('Selbstgehostet: Hoster als eigener Fakt und eigener Schritt', [true, true], [in_array('an deinen Hoster (ca.)', array_column($Bs2['lead']['facts'], 'label'), true), in_array('Dein Server', array_column($Bs2['lead']['steps'], 'title'), true)]);
$Bq = $O::own_blocks($O::clean_data(['items' => [$it('shop', 'yearly')], 'mode' => 'own_shop', 'plan' => ['lead' => 80000]]));
$eq('Über der Staffel führend → „individuelles Angebot“ statt Zahlen', [true, []], [$Bq['lead']['on_request'], $Bq['lead']['facts']]);
$pdf_l = (string) $O::render_pdf($dp, ['number' => 'AN-T-3', 'date' => wp_date('Y-m-d')]);
$pdf_lt = (function ($b) { $t = ''; if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $b, $m)) foreach ($m[1] as $z) { $u = @gzuncompress($z); $t .= $u !== false ? $u : $z; } return $t; })($pdf_l);
$eq('PDF: Faktenblock und Erklärung', [true, true], [strpos($pdf_l, '%PDF') === 0, stripos($pdf_lt, 'So kommen die Zahlen zustande') !== false]);

// ── 1.38.823: Jahresvertrag nach Staffel, „Optional dazubuchbar“, Plattform-Vergleich nur auf Wunsch
$ptxt = function ($b) { $t = ''; if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', (string) $b, $m)) foreach ($m[1] as $z) { $u = @gzuncompress($z); $t .= $u !== false ? $u : $z; } return $t; };
$j1 = $O::clean_data(['items' => [$it('shop', 'monthly')], 'mode' => 'own_shop']);
$eq('Jahresvertrag: Shop „monatlich“ angelegt → jährlich, 790 € (Staffel bei 5.000), nicht 12 × 79 = 948', ['yearly', 790.0, 790.0], [$j1['items'][0]['interval'], $j1['items'][0]['list_price'], $O::totals($j1)['yearly']['net']]);
$j2 = $O::clean_data(['items' => [$it('shop')], 'mode' => 'own_shop', 'plan' => ['lead' => 10000]]);
$j3 = $O::clean_data(['items' => [$it('shop')], 'mode' => 'own_shop', 'projection' => ['tickets' => 400, 'avg_price' => 20, 'events_year' => 25]]);
$j4 = $O::clean_data(['items' => [$it('shop')], 'mode' => 'own_shop', 'plan' => ['lead' => 20000]]);
$eq('Jahrespreis nach führender Stufe (Staffel 1.38.825): 10.000 → 1.690, Hochrechnung 400 × 25 → 1.690, über 10.000 → kein Listenpreis (Preis fehlt)', [1690.0, 1690.0, null, true],
    [$j2['items'][0]['list_price'], $j3['items'][0]['list_price'], $j4['items'][0]['list_price'], in_array($j4['items'][0]['id'], $O::totals($j4)['missing_prices'], true)]);
$j5 = $O::clean_data(['items' => [$it('lizenz', 'monthly')], 'mode' => 'self_hosted', 'plan' => ['lead' => 10000], 'extras' => ['setup_free' => true]]);
$eq('Lizenz genauso: jährlich, 1.690 bei 10.000, Einrichtung erlassen 0 €', ['yearly', 1690.0, 1690.0, 0.0], [$j5['items'][0]['interval'], $j5['items'][0]['list_price'], $O::totals($j5)['yearly']['net'], $O::totals($j5)['once']['net']]);
$Bj = $O::own_blocks($j2); $pj2 = $O::plan_result($j2);
$pdf_j = $ptxt($O::render_pdf($j2, ['number' => 'AN-T-J', 'date' => wp_date('Y-m-d')]));
$eq('Gleicher Jahreswert überall: Summe, plan_calc (Faktenblock + Zeile 10.000), Kachel/Fakt, PDF; kein 948 im PDF', [1690.0, 1690.0, 1690.0, '1.690 €', true, false],
    [$O::totals($j2)['base']['yearly']['net'], $pj2['lead_base']['base_price'], $pj2['rows'][array_search(10000, array_column($pj2['rows'], 'tickets'), true)]['evendis'], $Bj['lead']['facts'][0]['value'],
     (bool) preg_match('/1\.690,00 .{1,3}\/ Jahr/', $pdf_j), strpos($pdf_j, '948') !== false]);
$eq('Festpreis: bei 10.000 „nie mehr als 1.690 €“, bei 5.000 „790 €“, mit Staffel; über der Staffel keiner', [true, true, true, null],
    [strpos($Bj['fixed_price']['text'] ?? '', 'Bis 10.000 verkaufte Tickets im Jahr zahlst du nie mehr als 1.690') === 0, strpos($O::own_blocks($j1)['fixed_price']['text'] ?? '', 'nie mehr als 790') !== false,
     strpos($Bj['fixed_price']['tiers'] ?? '', 'Staffel je Jahr') === 0, $O::own_blocks($j4)['fixed_price']]);
$raw_j = (string) $O::render_pdf($j2, ['number' => 'AN-T-L', 'date' => wp_date('Y-m-d')]);
$eq('PDF: Original-Logo als Bild, Festpreis-Satz sichtbar', [true, true], [strpos($raw_j, '/Subtype /Image') !== false || !function_exists('imagecreatefrompng'), strpos($ptxt($raw_j), 'Festpreis: Bis 10.000') !== false]);
$jm = $O::clean_data(['items' => [$it('shop_monatlich')], 'mode' => 'own_shop']);
$eq('Monatspreis nur bei monatlicher Zahlung (Eigener Shop – monatlich kündbar): 99 €/Monat = 1.188 €/Jahr, „bei monatlicher Zahlung“', ['monthly', 1188.0, true],
    [$jm['items'][0]['interval'], $O::totals($jm)['yearly']['net'], strpos($O::own_blocks($jm)['block1']['base_rows'][0]['detail'], 'bei monatlicher Zahlung') === 0]);

$op = $O::clean_data(['items' => [$it('shop'), $it('seating', null, ['price' => 29]), $it('giftcards', null, ['price' => 19])], 'mode' => 'own_shop', 'plan' => ['lead' => 5000]]);
$to = $O::totals($op);
$eq('Optional: Module markiert, Grundpaket 790 / mit Optionen 1.366 (+ 576), Einrichtung zählt zum Grundpaket', [[false, true, true, false], 790.0, 1366.0, 576.0, true, 940.1],
    [array_column($to['lines'], 'optional'), $to['base']['yearly']['net'], $to['with_options']['yearly']['net'], $to['options']['yearly_net'], $to['has_options'], $to['base']['yearly']['gross']]);
$Bo = $O::own_blocks($op); $po = $O::plan_result($op);
$eq('Blöcke getrennt; Faktenblock mit dem Grundpaket (790), Planrechnung mit Optionen (1.366)', [2, false, 790.0, 1366.0, '790 €'],
    [count($Bo['block1']['option_rows']), in_array('Modul Saalplan & Tische', array_column($Bo['block1']['base_rows'], 'name'), true), $po['lead_base']['to_evendis'], $po['lead']['to_evendis'], $Bo['lead']['facts'][0]['value']]);
$pdf_o = $ptxt($O::render_pdf($op, ['number' => 'AN-T-O', 'date' => wp_date('Y-m-d')]));
$eq('PDF: Block „Optional dazubuchbar“, Kennzeichen OPTIONAL, Summen Grundpaket und „Mit gewählten Optionen“', [true, true, true, true],
    [strpos($pdf_o, 'Optional dazubuchbar') !== false, strpos($pdf_o, 'OPTIONAL') !== false, strpos($pdf_o, 'Grundpaket im Jahr, netto') !== false, strpos($pdf_o, 'Optionen im Jahr, brutto') !== false]);
$pdf_b = $ptxt($O::render_pdf($O::clean_data(['options' => ['shop']], $op), ['number' => 'AN-T-B', 'date' => wp_date('Y-m-d')]));
$eq('Ohne Optionen kein leerer Block', false, strpos($pdf_b, 'Optional dazubuchbar') !== false);

$eq('compare_platform: Standard aus, nur bei true an (auch nicht bei "1")', [false, true, false, true],
    [$op['compare_platform'], $O::clean_data(['compare_platform' => true], $op)['compare_platform'], $O::clean_data(['compare_platform' => '1'], $op)['compare_platform'], $O::clean_data(['intro' => 'x'], $O::clean_data(['compare_platform' => true], $op))['compare_platform']]);
$eq('Ohne Vergleich: Faktenblock ohne Plattform, kein Vergleichs-Schritt, Gast nur eigener Shop, Planrechnung ohne Plattform-Zeilen', [false, false, false, false, false],
    [strpos($Bo['lead']['note'], 'Ticketplattform') !== false, in_array('Zum Vergleich: die Ticketplattform', array_column($Bo['lead']['steps'], 'title'), true), isset($Bo['block3']['pays_platform']),
     isset($Bo['block4']['rows'][0]['platform_you_pay']), in_array('platform', $Bo['block4']['totals_order'], true)]);
$eq('PDF ohne Vergleich: kein „Ticketplattform“, keine Plattform-Spalte, kein „Vorteil“', [false, false, false], [strpos($pdf_o, 'Ticketplattform') !== false, strpos($pdf_o, 'Plattform evendis.de') !== false, strpos($pdf_o, 'Vorteil Eigener Shop') !== false]);
$opc = $O::clean_data(['compare_platform' => true], $op);
$Bc = $O::own_blocks($opc);
$pdf_c = $ptxt($O::render_pdf($opc, ['number' => 'AN-T-C', 'date' => wp_date('Y-m-d')]));
$eq('Mit compare_platform: Vergleich in Faktenblock, Schritten und PDF', [true, true, true, true],
    [strpos($Bc['lead']['note'], 'Ticketplattform') !== false, in_array('Zum Vergleich: die Ticketplattform', array_column($Bc['lead']['steps'], 'title'), true), strpos($pdf_c, 'zum Vergleich: Plattform') !== false, isset($Bc['block3']['pays_platform'])]);
$sp = $O::strip_platform($po);
$eq('Kundenseite ohne Vergleich: plan_calc ohne Plattform (Zeilen, Summen, Faktenblock, Gast)', [false, false, false, false],
    [isset($sp['rows'][0]['platform_you_pay']), isset($sp['totals'][0]['modes']['platform']), isset($sp['lead']['savings_vs_platform']), isset($sp['guest']['pays_platform'])]);
$m2 = new ReflectionMethod('TIX_Platform_Offers', 'page_own'); $m2->setAccessible(true);
$h2 = (string) $m2->invoke(null, ['own' => array_merge($Bo, ['plan_calc' => $O::strip_platform($Bo['plan'])]), 'totals' => $to], 'tok');
$eq('Online: „Optional dazubuchbar“ mit Kennzeichen, Summe „Mit gewählten Optionen“, ohne Plattform-Spalte', [true, true, true, false],
    [strpos($h2, 'Optional dazubuchbar') !== false, strpos($h2, 'class="opt"') !== false, strpos($h2, 'Mit gewählten Optionen') !== false, strpos($h2, 'Plattform evendis.de') !== false]);

// ── 1.38.825: neue Staffel, Vertriebler, Seitenumbruch
$eq('Staffel 790 bis 5.000, 1.690 bis 10.000, darüber auf Anfrage; Stufen 1.000/5.000/10.000; selbstgehostet gleich', [[[5000, 790.0], [10000, 1690.0]], [1000, 5000, 10000], true],
    [array_map(function ($x) { return [$x['max'], $x['price']]; }, TIX_Offer_Plan::default_tiers()), TIX_Offer_Plan::default_settings()['steps'], $O::default_catalog()['self_hosted_tiers'] == $O::default_catalog()['shop_tiers']]);
$bak2 = get_option($O::OPT_CATALOG, null);
$cm = $O::default_catalog(); $cm['shop_tiers'] = TIX_Offer_Plan::old_default_tiers(); $cm['self_hosted_tiers'] = TIX_Offer_Plan::old_default_tiers(); $cm['plan_defaults']['steps'] = [1000, 5000, 20000, 50000]; unset($cm['sales_reps']);
update_option($O::OPT_CATALOG, $cm, false);
$cc = $O::catalog();
$eq('Gespeicherter Katalog mit alten Vorgaben → neue Staffel und Stufen, Vertriebler ergänzt', [1690.0, 1690.0, [1000, 5000, 10000], 3], [$cc['shop_tiers'][1]['price'], $cc['self_hosted_tiers'][1]['price'], $cc['plan_defaults']['steps'], count($cc['sales_reps'])]);
$cm['shop_tiers'] = [['max' => 5000, 'price' => 690]]; update_option($O::OPT_CATALOG, $cm, false);
$eq('Eigene Staffel im Katalog bleibt', [[5000, 690.0]], array_map(function ($x) { return [$x['max'], floatval($x['price'])]; }, $O::catalog()['shop_tiers']));
$reps = $O::default_catalog()['sales_reps'];
$eq('Vertriebler-Vorgaben: David (+49 175 5026857), Jan, Masod, E-Mail vertrieb@evendis.de', [['David Mathauschek', '+49 175 5026857'], ['Jan Preiß', '+49 1511 9712012'], ['Masod Kamaly', '+49 178 8281770'], ['vertrieb@evendis.de']],
    [[$reps[0]['name'], $reps[0]['phone']], [$reps[1]['name'], $reps[1]['phone']], [$reps[2]['name'], $reps[2]['phone']], array_values(array_unique(array_column($reps, 'email')))]);
$cm = $O::default_catalog(); $cm['sales_reps'][2]['user_id'] = get_current_user_id(); update_option($O::OPT_CATALOG, $cm, false);
$sn = $O::clean_data(['customer' => ['company' => 'V'], 'mode' => 'own_shop']);
$sj = $O::clean_data(['customer' => ['company' => 'V'], 'mode' => 'own_shop', 'sales_rep' => 'jan']);
$eq('Neues Angebot: Vertriebler des eingeloggten Kontos vorausgewählt (Masod), gewählt Jan → Kontakt Jan', ['masod', 'Masod Kamaly', 'jan', ['Jan Preiß', 'vertrieb@evendis.de', '+49 1511 9712012']],
    [$sn['sales_rep'], $sn['contact']['name'], $sj['sales_rep'], array_values($sj['contact'])]);
$eq('Gewählter Vertriebler bleibt beim Ändern; Konto ohne Zuordnung → David; Altangebot ohne Vertriebler behält seinen Kontakt', ['jan', 'david', null, 'Alt Kontakt'],
    [$O::clean_data(['intro' => 'x'], $sj)['sales_rep'], (function () use ($O) { update_option($O::OPT_CATALOG, $O::default_catalog(), false); return $O::clean_data(['customer' => ['company' => 'V']])['sales_rep']; })(),
     ($ao = $O::clean_data(['intro' => 'y'], ['customer' => ['company' => 'A'], 'contact' => ['name' => 'Alt Kontakt', 'email' => 'alt@example.org', 'phone' => ''], 'items' => []]))['sales_rep'], $ao['contact']['name']]);
$eq('Unbekannter Vertriebler → 400 sales_rep; Katalog: E-Mail ungültig → 400', ['sales_rep', 'sales_reps.0.email'],
    [$O::plan_error(['sales_rep' => 'niemand'])->get_error_data()['field'] ?? '', $O::clean_sales_reps([['name' => 'X', 'email' => 'kaputt']])->get_error_data()['field'] ?? '']);
$pdf_r = $ptxt($O::render_pdf($sj, ['number' => 'AN-T-R', 'date' => wp_date('Y-m-d')]));
$eq('PDF: Kontakt zeigt den Vertriebler', [true, true], [strpos($pdf_r, '+49 1511 9712012') !== false, strpos($pdf_r, 'DEIN KONTAKT BEI EVENDIS') !== false]);
if ($bak2 === null) delete_option($O::OPT_CATALOG); else update_option($O::OPT_CATALOG, $bak2, false);
$many = [$it('shop')]; for ($i = 0; $i < 45; $i++) $many[] = $it('seating', null, ['price' => 5 + $i, 'name' => 'Modul Nr. ' . ($i + 1)]);
$pdf_m = $ptxt($O::render_pdf($O::clean_data(['items' => $many, 'mode' => 'own_shop']), ['number' => 'AN-T-M', 'date' => wp_date('Y-m-d')]));
$eq('Block größer als eine Seite: bricht um, Tabellenkopf wird wiederholt (PREIS mindestens 3×)', true, substr_count($pdf_m, '(PREIS)') >= 3);

// ── 1.38.826: PayPal als eigener Zahlungsanbieter, evendis.de-Verkauf nur mit Häkchen
$C6 = $O::catalog();
$eq('PayPal direkt im Katalog (2,99 % + 0,39 €, Quelle), gleichwertig neben Mollie und Stripe; Texte nennen PayPal', ['PayPal', 2.99, 0.39, true, true, true],
    [$C6['payment_rates']['paypal']['provider'], floatval($C6['payment_rates']['paypal']['percent']), floatval($C6['payment_rates']['paypal']['fixed']), strpos((string) $C6['payment_rates']['paypal']['source'], 'paypal.com') !== false,
     (bool) preg_grep('/Mollie, Stripe oder PayPal/', array_column($C6['setup']['customer_tasks'], 'text')), strpos(TIX_Offer_Plan::compute(TIX_Offer_Plan::clean([], $C6), $C6)['to_psp']['providers_note'], 'Mollie, Stripe und PayPal') === 0]);
$k0 = $O::clean_data(['customer' => ['company' => 'Kanal'], 'mode' => 'own_shop']);
$k1 = $O::clean_data(['extras' => ['evendis_channel' => true]], $k0);
$bk0 = array_column(array_filter($k0['benefits'], function ($b) { return $b['on']; }), 'key');
$Bk1 = $O::own_blocks($k1);
$eq('evendis.de-Verkauf: Standard aus, kein Vorteil „Zusätzlich auf evendis.de“; mit Häkchen Vorteil an und Zeile unter „Optional“, Faktenblock ohne Kanal-Gebühr', [false, false, true, true, false, true, 0.0],
    [$k0['extras']['evendis_channel'], in_array('evendis_kanal', $bk0, true), in_array('evendis_kanal', array_column(array_filter($k1['benefits'], function ($b) { return $b['on']; }), 'key'), true),
     in_array('Zusätzlich über evendis.de verkaufen', array_column($Bk1['block1']['option_rows'], 'name'), true), in_array('Zusätzlich über evendis.de verkaufen', array_column($Bk1['block1']['base_rows'], 'name'), true),
     $O::totals($k1)['has_options'], floatval($O::plan_for_offer($k1)['lead_base']['channel_fee'])]);
$eq('Häkchen wieder aus → Vorteil wieder aus', false, in_array('evendis_kanal', array_column(array_filter($O::clean_data(['extras' => ['evendis_channel' => false]], $k1)['benefits'], function ($b) { return $b['on']; }), 'key'), true));

// ── 1.38.827: kein Vergleich der Betriebsarten, Geist-Schrift, Rundungen
$Bo7 = $O::own_blocks($O::clean_data(['items' => [$it('shop')], 'mode' => 'own_shop']));
$pdf7raw = (string) $O::render_pdf($O::clean_data(['items' => [$it('shop')], 'mode' => 'own_shop']), ['number' => 'AN-T-7', 'date' => wp_date('Y-m-d')]);
$pdf7 = $ptxt($pdf7raw);
$eq('Eigener Shop: kein „Selbstgehostet“ in Überblick, Zeilen und PDF', [['own_shop'], false, false, false],
    [$Bo7['block4']['totals_order'], isset($Bo7['block4']['rows'][0]['compare']), strpos($pdf7, 'Selbstgehostet') !== false, strpos($pdf7, 'zum Vergleich') !== false]);
$sp7 = $O::strip_other_mode($O::plan_for_offer($O::clean_data(['items' => [$it('lizenz')], 'mode' => 'self_hosted'])));
$eq('Kundenseite selbstgehostet: plan_calc ohne eigenen Shop', [false, false, true], [isset($sp7['totals'][0]['modes']['own_shop']), isset($sp7['rows'][0]['compare']), isset($sp7['totals'][0]['modes']['self_hosted'])]);
$eq('PDF: Geist und Geist Mono eingebettet, runde Ecken (Bézier)', [true, true, true], [strpos($pdf7raw, '/BaseFont /Geist-Regular') !== false || !TIX_PDFA::available(), strpos($pdf7raw, '/BaseFont /GeistMono-Regular') !== false || !TIX_PDFA::available(), strpos($pdf7, ' c ') !== false]);
$inv = new TIX_PDFA(595, 842, 48, 40); $inv->add_page(); $inv->set_font('Helvetica', 10); $inv->text(50, 800, 'Rechnung');
$eq('Andere PDFs (Rechnungen) bleiben bei Manrope', [true, false], [strpos($inv->output(), 'Manrope-Regular') !== false, strpos((string) $inv->output(), 'Geist') !== false]);

// ── 1.38.827: geschenkte Module
$gi = [$it('shop'), $it('app', null, ['price' => 149]), $it('seating', null, ['gift' => true]), $it('app_modul', null, ['gift' => true]), $it('music', null, ['price' => 0])];
$dg = $O::clean_data(['items' => $gi, 'mode' => 'own_shop', 'plan' => ['lead' => 2000]]);
$tg = $O::totals($dg); $Bg = $O::own_blocks($dg);
$rows_g = array_column($Bg['block1']['base_rows'], null, 'name');
$rows_go = array_column($Bg['block1']['option_rows'], null, 'name');
$eq('Geschenkt: 0 € – geschenkt, Listenpreis durchgestrichen; Module ohne App-Bezug im Grundpaket, App-Module (nur mit App) bei der App unter „Optional“; Summe ohne, Faktenblock 790', [0.0, '0,00 € – geschenkt', '29,00 € / Monat', true, false, ['Eigene App – Basis', 'App-Modul'], 790.0, 790.0 + 1788.0, '790 €'],
    [$dg['items'][3]['price'], $rows_go['App-Modul']['price'] ?? '', $rows_go['App-Modul']['list_price'] ?? '', !empty($rows_g['Modul Musikwunsch']['gift']), $O::is_optional($dg['items'][2]),
     array_column($Bg['block1']['option_rows'], 'name'), $tg['base']['yearly']['net'], $tg['with_options']['yearly']['net'], $Bg['lead']['facts'][0]['value']]);
$eq('PDF: „geschenkt“ sichtbar', true, strpos($ptxt($O::render_pdf($dg, ['number' => 'AN-T-G', 'date' => wp_date('Y-m-d')])), 'geschenkt') !== false);

// ── 1.38.827: Rabatt je Position, Guthaben braucht die App (als Zubuchung), Shop läuft ohne App
$dd = $O::clean_data(['items' => [$it('shop'), $it('app'), $it('app_einrichtung', null, ['discount_percent' => 50])], 'mode' => 'own_shop', 'plan' => ['lead' => 2000]]);
$Bd = $O::own_blocks($dd); $rd = array_column($Bd['block1']['option_rows'], null, 'name');
$eq('Rabatt 50 % auf App-Einrichtung: 745 statt 1.490 (durchgestrichen), Etikett, einmalig 745; App bleibt optional; Faktenblock 790', [745.0, '1.490,00 €', '50 % Rabatt', 745.0, true, '790 €'],
    [$dd['items'][2]['price'], $rd['App-Einrichtung']['list_price'] ?? '', $rd['App-Einrichtung']['discount_label'] ?? '', $O::totals($dd)['once']['net'], isset($rd['Eigene App – Basis']), $Bd['lead']['facts'][0]['value']]);
$dw = $O::clean_data(['items' => [$it('shop'), $it('wallet', null, ['gift' => true])], 'mode' => 'own_shop']);
$Bw = $O::own_blocks($dw); $rw = array_column($Bw['block1']['option_rows'], null, 'name');
$eq('Guthaben ohne App → App + Einrichtung als Zubuchung angehakt (nicht im Grundpaket), Kennzeichen, Hinweis; Faktenblock ohne App', [['app', 'app_einrichtung'], true, 'für Modul Guthaben nötig', ['tix_offer_needs_app'], '790 €', 790.0],
    [$dw['auto_added'], isset($rw['Eigene App – Basis']), $rw['Eigene App – Basis']['needed_for'] ?? '', array_column($O::mode_hints($dw), 'code'), $Bw['lead']['facts'][0]['value'], $O::totals($dw)['base']['yearly']['net']]);
$pdf_w = $ptxt($O::render_pdf($dw, ['number' => 'AN-T-W', 'date' => wp_date('Y-m-d')]));
$eq('PDF: „Dein Shop läuft komplett ohne App“ sichtbar', true, strpos($pdf_w, 'komplett ohne App') !== false);

// ── 1.38.828: eine Planrechnungs-Tabelle, Bedingungen ohne Plattform-Satz
$d8 = $O::clean_data(['items' => [$it('shop'), $it('app')], 'mode' => 'own_shop', 'plan' => ['lead' => 5000]]);
$B8 = $O::own_blocks($d8); $p8 = $ptxt($O::render_pdf($d8, ['number' => 'AN-T-8', 'date' => wp_date('Y-m-d')]));
$i8 = array_search(5000, array_column($B8['block4']['table'], 'tickets'), true);
$eq('Eine Tabelle: „bleibt dir“ = Ergebnis 1. Jahr, nur Grundpaket (Optionen 0, 1.38.829), keine zweite Tabelle, 4 kurze Hinweise', [true, 0.0, false, false, 4],
    [$B8['block4']['table'][$i8]['kept'] === $B8['block4']['totals'][$i8]['modes']['own_shop']['kept'], $B8['block4']['table'][$i8]['options'], strpos($p8, 'Ergebnis im') !== false, strpos($p8, 'Rechnung im Detail') !== false, count($B8['block4']['notes'])]);
$eq('Bedingungen: „Tickets über evendis“ nur mit evendis.de-Verkauf bzw. Plattform-Vergleich', [false, true, true],
    [strpos($O::conditions_text($d8), 'Tickets über evendis') !== false, strpos($O::conditions_text($O::clean_data(['extras' => ['evendis_channel' => true]], $d8)), 'Tickets über evendis') !== false, strpos($O::conditions_text($O::clean_data(['compare_platform' => true], $d8)), 'Tickets über evendis') !== false]);

// ── 1.38.829: Markenrot, Planrechnung nur Grundpaket, App-Gruppe, Ticketpreis als Annahme, Nummern
$d9 = $O::clean_data(['items' => [$it('shop'), $it('app'), $it('app_einrichtung', null, ['discount_percent' => 50]), $it('wallet', null, ['gift' => true]), $it('seating', null, ['gift' => true])], 'mode' => 'own_shop', 'plan' => ['lead' => 2000]]);
$B9 = $O::own_blocks($d9);
$eq('Planrechnung nur Grundpaket: keine Optionen, keine einmaligen Optionskosten; Hinweis', [0.0, 0.0, true],
    [array_sum(array_column($B9['block4']['table'], 'options')), array_sum(array_map('floatval', array_column($B9['block4']['table'], 'once'))), (bool) preg_grep('/Optionale Zubuchungen sind hier nicht eingerechnet/', $B9['block4']['notes'])]);
$eq('Guthaben (auch geschenkt) bei der App unter „Optional“, Kennzeichen „nur mit App“, Hinweis; Saalplan geschenkt im Grundpaket', [['Eigene App – Basis', 'App-Einrichtung', 'Modul Guthaben'], 'nur mit App', true, true],
    [array_column($B9['block1']['option_rows'], 'name'), array_column($B9['block1']['option_rows'], 'needed_for', 'name')['Modul Guthaben'] ?? '', strpos(array_column($B9['block1']['option_rows'], 'detail', 'name')['Modul Guthaben'] ?? '', 'nur zusammen mit dem App-Paket') !== false,
     in_array('Modul Saalplan & Tische', array_column($B9['block1']['base_rows'], 'name'), true)]);
$p9raw = (string) $O::render_pdf($d9, ['number' => 'AN-T-9', 'date' => wp_date('Y-m-d')]); $p9 = $ptxt($p9raw);
$eq('Ticketpreis als Annahme im Faktenblock und in der Planrechnung; Markenrot #FC2F55', [true, true, true],
    [strpos($B9['lead']['note'], 'Bei anderen Ticketpreisen bleibt der evendis-Preis gleich') !== false, (bool) preg_grep('/^Annahme: Ø 25,00/', $B9['block4']['notes']), strpos($p9, '0.988 0.184 0.333 rg') !== false]);
if ($O::multi()) {
    global $wpdb;
    $nums = []; $ids9 = [];
    for ($i = 0; $i < 4; $i++) { $nr = $O::next_number(); $nums[] = intval(substr($nr, strrpos($nr, '-') + 1)); }
    $diffs = array_map(function ($a, $b) { return $b - $a; }, array_slice($nums, 0, -1), array_slice($nums, 1));
    $eq('Nummern AN-JJMM-NNNN: streng aufsteigend, Schritt 7–41, ab 4.000', [true, true, 1], [min($diffs) >= 7 && max($diffs) <= 41, $nums[0] > 4000, preg_match('/^AN-\d{4}-\d{4,}$/', $O::next_number())]);
    $rid = $O::insert($O::clean_data(['customer' => ['company' => 'Nummer']]), ['base_number' => 'AN-2026-0002']);
    $new = $O::renumber($rid);
    $eq('Entwurf aus altem Schema bekommt neue Nummer; gesendet → 409', [true, 'tix_offer_locked'],
        [is_string($new) && $O::get($rid)->number === $new && $O::get($rid)->base_number === $new, (function () use ($O, $rid, $wpdb) { $wpdb->update($O::table(), ['status' => 'sent'], ['id' => $rid]); $e = $O::renumber($rid); return is_wp_error($e) ? $e->get_error_code() : ''; })()]);
    $wpdb->delete($O::table(), ['id' => $rid]);
}

// ── 1.38.830: App-Vorteile als optional gekennzeichnet
$d30 = $O::clean_data(['items' => [$it('shop'), $it('app')], 'mode' => 'own_shop']);
$b30 = $O::own_blocks($d30)['benefits'];
$tags30 = array_column($b30, 'tag', 'title');
$eq('App-Vorteile bleiben, Etikett „mit App-Paket“, am Ende; Shop-Vorteile ohne Etikett; im PDF sichtbar', ['mit App-Paket', '', 'mit App-Paket', true],
    [$tags30['In App Store und Google Play'] ?? null, $tags30['Shop unter deiner Domain'] ?? null, end($b30)['tag'], strpos($ptxt($O::render_pdf($d30, ['number' => 'AN-T-30', 'date' => wp_date('Y-m-d')])), 'MIT APP-PAKET') !== false]);

// ── 1.38.831: ohne Vorsteuer-Satz, Kurzangebot
$d31 = $O::clean_data(['customer' => ['company' => 'Kurz GmbH'], 'items' => [$it('shop'), $it('app'), $it('wallet', null, ['gift' => true]), $it('seating', null, ['gift' => true]), $it('music', null, ['gift' => true])], 'mode' => 'own_shop', 'plan' => ['lead' => 5000]]);
$long31 = (string) $O::render_pdf($d31, ['number' => 'AN-T-31', 'date' => wp_date('Y-m-d'), 'link' => 'https://evendis.de/?tix_offer=x']);
$short31 = (string) $O::render_pdf($d31, ['number' => 'AN-T-31', 'date' => wp_date('Y-m-d'), 'length' => 'short', 'link' => 'https://evendis.de/?tix_offer=x']);
$st31 = $ptxt($short31);
$pages = function ($b) { return substr_count($b, '/Type /Page /Parent'); };
$eq('Kein „Vorsteuer“ mehr (PDF und Texte)', [false, false], [stripos($ptxt($long31), 'Vorsteuer') !== false, stripos(implode(' ', array_column($O::own_blocks($d31)['lead']['steps'], 'text')), 'Vorsteuer') !== false]);
$eq('length: Standard long, short wählbar, sonst 400', ['long', 'short', 'length'], [$d31['length'], $O::clean_data(['length' => 'short'], $d31)['length'], $O::plan_error(['length' => 'mittel'])->get_error_data()['field'] ?? '']);
$eq('Kurzangebot: höchstens 2 Seiten (lang mehr), Faktenblock, Festpreis, ohne App, Annahme, Leistungen mit „Geschenkt dazu“, Optional „nur mit App“, Planrechnung, Zahlungsanbieter-Satz, Interesse, Kontakt', [true, true, true, true, true, true, true, true, true, true, true, true],
    [$pages($short31) <= 2, $pages($long31) > 2, strpos($st31, 'das zahlst du, das bleibt dir') !== false, strpos($st31, 'Festpreis: Bis 5.000') !== false, strpos($st31, 'komplett ohne App') !== false,
     strpos($st31, 'Annahme:') !== false, strpos($st31, 'Geschenkt dazu') !== false, strpos($st31, 'NUR MIT APP') !== false, strpos($st31, 'Planrechnung') !== false,
     strpos($st31, 'Mollie, Stripe oder PayPal') !== false, strpos($st31, 'Interesse') !== false, strpos($st31, 'DEIN KONTAKT BEI EVENDIS') !== false]);

// ── 1.38.832: Referenzen im Kurzangebot (Katalog im Test, danach zurück)
$bak32 = get_option($O::OPT_CATALOG, null);
$c32 = $O::catalog(); $c32['references'] = [['key' => 'kitchenklub', 'name' => 'KitchenKlub', 'text' => 'Club in Wuppertal – eigener Ticketshop und eigene App', 'url' => 'https://kitchenklub.de', 'app_store_url' => 'https://apps.apple.com/de/app/id6814961638', 'mode' => 'own_shop', 'approved' => true],
    ['key' => 'mallorca-festival-xxl', 'name' => 'Mallorca Festival XXL', 'text' => 'Open-Air-Festival – eigener Ticketshop und eigene App', 'url' => 'https://mallorca-festival-xxl.de', 'play_store_url' => 'https://play.google.com/store/apps/details?id=de.mallorcafestivalxxl.app', 'mode' => 'own_shop', 'approved' => true]];
update_option($O::OPT_CATALOG, $c32, false);
$d32 = $O::clean_data(['customer' => ['company' => 'Ref'], 'items' => [$it('shop'), $it('app'), $it('wallet', null, ['gift' => true])], 'mode' => 'own_shop', 'references' => ['kitchenklub', 'mallorca-festival-xxl']]);
$s32 = (string) $O::render_pdf($d32, ['number' => 'AN-T-32', 'date' => wp_date('Y-m-d'), 'length' => 'short', 'link' => 'https://evendis.de/?tix_offer=x']);
$l32 = $ptxt($O::render_pdf($d32, ['number' => 'AN-T-32', 'date' => wp_date('Y-m-d')]));
$eq('Referenzen kurz (Kacheln, ≤ 2 Seiten) und lang', [true, true, true, true, true],
    [substr_count($s32, '/Type /Page /Parent') <= 2, strpos($ptxt($s32), 'So sieht es bei anderen aus') !== false, strpos($ptxt($s32), 'Mallorca Festival XXL') !== false, strpos($ptxt($s32), 'App Store') !== false, strpos($l32, 'So sieht es bei anderen aus') !== false]);
if ($bak32 === null) delete_option($O::OPT_CATALOG); else update_option($O::OPT_CATALOG, $bak32, false);

// ── 1.38.833: „So kommen die Zahlen zustande“ auf eigener Seite
$d33 = $O::clean_data(['customer' => ['company' => 'Seite GmbH', 'name' => 'Eva Test'], 'items' => [$it('shop'), $it('app')], 'mode' => 'own_shop', 'plan' => ['lead' => 5000]]);
$raw33 = (string) $O::render_pdf($d33, ['number' => 'AN-T-33', 'date' => wp_date('Y-m-d')]);
$pg33 = [];
if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw33, $m33)) foreach ($m33[1] as $z) { $u = @gzuncompress($z); if ($u !== false && strpos($u, ' Tf ') !== false) $pg33[] = $u; }
$ix = null; foreach ($pg33 as $i => $c) if (strpos($c, 'So kommen die Zahlen zustande') !== false) $ix = $i;
$eq('Erklärung auf eigener Seite: beginnt nach Seite 1 (Anschreiben), alle Schritte und Fußnote auf derselben Seite, ohne Faktenblock/Anrede', [true, true, true, false, false],
    [$ix !== null && $ix > 0, $ix !== null && strpos($pg33[$ix], 'Was dir bleibt') !== false && strpos($pg33[$ix], 'Deine Ticketeinnahmen') !== false, $ix !== null && strpos($pg33[$ix], 'Netto = ohne') !== false,
     $ix !== null && strpos($pg33[$ix], 'Hallo') !== false, $ix !== null && strpos($pg33[$ix], 'das zahlst du, das bleibt dir') !== false]);

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
        $eq('Online-Seite: Faktenblock und Erklärung oben', [true, true], [strpos($html, 'das zahlst du, das bleibt dir') !== false, strpos($html, 'So kommen die Zahlen zustande') !== false && strpos($html, 'So kommen die Zahlen zustande') < strpos($html, 'Grundpaket – das zahlst du an evendis')]);
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
        $x2 = new WP_REST_Request('GET', "/tixomat/v1/platform/offers/$qid/pdf"); $x2->set_query_params(['length' => 'short']); $r2 = rest_do_request($x2);
        $x3 = new WP_REST_Request('GET', "/tixomat/v1/platform/offers/$qid/pdf"); $x3->set_query_params(['length' => 'mittel']); $r3 = rest_do_request($x3);
        $eq('PDF-Abruf ?length=short → Kurzangebot (Dateiname), Angebot unverändert; ungültig 400', [200, 0, 'long', 400],
            [$r2->get_status(), strpos((string) ($r2->get_data()['filename'] ?? ''), 'Kurzangebot-'), $O::data($O::get($qid))['length'] ?? 'long', $r3->get_status()]);
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
