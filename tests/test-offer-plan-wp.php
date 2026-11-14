<?php
/**
 * AN1 Planrechnung eigener Shop (1.38.816): Referenzwerte aus dem Auftrag (gen_angebot_muster.py), Staffel, Vorgaben,
 * Prüfung der Annahmen, Einbau ins Angebot.
 *   wp eval-file …/test-offer-plan-wp.php (Plattform; Angebote gibt es nur dort)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Offer_Plan') || !class_exists('TIX_Platform_Offers')) { echo "Tixomat ab 1.38.816 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$cat = TIX_Platform_Offers::catalog();
// Referenz: Ø 25 €, Gebühr 1,50 €, 2 je Bestellung, 60 % Karte Mollie / 40 % PayPal, Shop 790 €/Jahr (fest)
$p = TIX_Offer_Plan::clean(['avg_price' => 25, 'service_fee' => 1.5, 'tickets_per_order' => 2, 'mix' => ['card' => 60, 'paypal' => 40], 'shop_price' => 790, 'steps' => [1000, 5000, 20000, 50000]], $cat); // Referenz-Stufen des Auftrags
$r = TIX_Offer_Plan::compute($p, $cat);
$col = function ($k) use ($r) { return array_column($r['rows'], $k); };
$eq('Referenz: an den Zahlungsanbieter', [756.14, 3780.7, 15122.8, 37807.0], $col('psp'));
$eq('Referenz: bleibt bei dir', [24953.86, 127929.3, 514087.2, 1286403.0], $col('kept'));
$eq('Referenz: Vorteil gegenüber Plattform (Gäste zahlen)', [-46.14, 2929.3, 14087.2, 36403.0], $col('advantage'));
$eq('Referenz: Break-even mit/ohne eigene Gebühr', [1063, 2643], [$r['break_even']['with_fee'], $r['break_even']['without_fee']]);
$eq('Referenz: Plattform (du trägst) 50.000 Tickets', 1198949.58, $r['rows'][3]['platform_you_pay']);
// Staffel (F-AN1 B) und Vorgaben (F-AN2: 60 % PayPal / 40 % Karte)
$s = TIX_Offer_Plan::compute(TIX_Offer_Plan::clean(['custom' => 60000], $cat), $cat);
$eq('Vorgaben: Ø 25 €, 1,50 €, 2 je Bestellung, 60 % PayPal / 40 % Karte', [25.0, 1.5, 2.0, ['paypal' => 60.0, 'card' => 40.0]], [$s['input']['avg_price'], $s['input']['service_fee'], $s['input']['tickets_per_order'], $s['input']['mix']]);
$eq('Staffel (1.38.825): Stufen 1.000/5.000/10.000 → 790 / 790 / 1.690 €, über 10.000 auf Anfrage', [790.0, 790.0, 1690.0, null, true], array_merge(array_column($s['rows'], 'evendis'), [$s['rows'][3]['on_request']]));
$eq('Eigene Zahl als Stufe markiert', [60000, true], [$s['rows'][3]['tickets'], $s['rows'][3]['custom']]);
$eq('Gast-Sicht: eigener Shop 26,50 €, Plattform 25 + 1,22 €', [26.5, 1.22, 26.22], [$s['guest']['pays_own_shop'], $s['guest']['platform_fee'], $s['guest']['pays_platform']]);
$keys = array_column($s['to_psp']['catalog'], 'provider', 'key');
$eq('Zahlarten im Katalog: Mollie und Stripe nebeneinander', ['Mollie', 'Stripe', 'Mollie'], [$keys['card'] ?? '', $keys['card_stripe'] ?? '', $keys['paypal_mollie'] ?? '']);
// Prüfung
$eq('Anteile ≠ 100 % → Fehler', 'plan.mix', TIX_Offer_Plan::clean(['mix' => ['paypal' => 50, 'card' => 40]], $cat)->get_error_data()['field'] ?? '');
$eq('Unbekannte Zahlart → Fehler', 'plan.mix.bitcoin', TIX_Offer_Plan::clean(['mix' => ['bitcoin' => 100]], $cat)->get_error_data()['field'] ?? '');
$eq('Stripe-Mischung rechnet (60 % PayPal / 40 % Stripe)', true, TIX_Offer_Plan::compute(TIX_Offer_Plan::clean(['mix' => ['paypal' => 60, 'card_stripe' => 40]], $cat), $cat)['rows'][0]['psp'] > 0);
// REST + Angebot (Routen nur auf der Plattform/Lizenzserver)
if (!TIX_Platform_Offers::multi()) { echo "skip  REST: /platform/offers nur auf der Plattform\n"; echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n"; return; }
$adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($adm);
[$st, $d] = $rest('POST', '/platform/offers/plan', ['avg_price' => 30]);
$eq('POST /platform/offers/plan', [200, 30.0, 3], [$st, $d['plan_calc']['input']['avg_price'] ?? null, count($d['plan_calc']['rows'] ?? [])]);
[$st, $d] = $rest('POST', '/platform/offers/calc', ['customer' => ['company' => 'Plan-Test'], 'items' => [['catalog_key' => 'shop']], 'plan' => ['mix' => ['paypal' => 10]]]);
$eq('Angebot mit ungültiger Planrechnung → 400 plan.mix', [400, 'plan.mix'], [$st, $d['data']['field'] ?? '']);
[$st, $d] = $rest('POST', '/platform/offers/calc', ['customer' => ['company' => 'Plan-Test'], 'items' => [['catalog_key' => 'shop']], 'plan' => ['service_fee' => 2]]);
$eq('Angebot mit Planrechnung → plan_calc im Ergebnis', [200, 2.0], [$st, $d['plan_calc']['input']['service_fee'] ?? null]);
[$st, $d] = $rest('POST', '/platform/offers', ['customer' => ['company' => 'Plan-Test', 'email' => 'plan-test@example.org'], 'items' => [['catalog_key' => 'shop']], 'plan' => ['custom' => 3000]]);
$oid = intval($d['offer']['id'] ?? $d['id'] ?? 0);
[$st2, $g] = $oid ? $rest('GET', "/platform/offers/$oid") : [0, []];
$g = $g['offer'] ?? $g;
$eq('Angebot gespeichert: plan bleibt Tarifname, Annahmen in data.plan, Ergebnis plan_calc', [true, true, 3000, 4], [$oid > 0, is_string($g['plan'] ?? null), $g['data']['plan']['custom'] ?? null, count($g['plan_calc']['rows'] ?? [])]);
if ($oid) { global $wpdb; $wpdb->delete(TIX_Platform_Offers::table(), ['id' => $oid]); }
wp_set_current_user(0);
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
