<?php
/** Angebote des Plattform-Betreibers (1.38.670): Rechte, Katalog, Nummernkreis, Summen, PDF, Mail, Annahme-Link, Versionen.
 *  wp eval-file …/tests/test-platform-offers-wp.php (beide Modi; auf der Einzel-Seite nur die Sperre). */
if (!defined('ABSPATH') || !class_exists('TIX_Platform_Offers')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function po_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function po_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
delete_option(TIX_Platform_Offers::OPT_DB); TIX_Platform_Offers::maybe_create();
$multi = TIX_Platform_Offers::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = []; $ids = [];
$keep = []; foreach ([TIX_Platform_Offers::OPT_CATALOG, TIX_Platform_Offers::OPT_SEQ, TIX_AI_Provider::OPT, TIX_AI_Provider::OPT_USAGE] as $o) $keep[$o] = get_option($o, null);
delete_option(TIX_Platform_Offers::OPT_CATALOG);
$mails = []; add_filter('pre_wp_mail', function ($n, $a) use (&$mails) {
    $a['pdf_ok'] = false; foreach ((array) ($a['attachments'] ?? []) as $f) if (is_readable($f) && substr(file_get_contents($f), 0, 4) === '%PDF') $a['pdf_ok'] = true;
    $mails[] = $a; return true; }, 10, 2);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_offer%' OR option_name LIKE '\\_transient\\_timeout\\_%tix\\_app\\_rl\\_offer%' OR option_name LIKE '\\_transient\\_%tix\\_offer\\_v\\_%' OR option_name LIKE '\\_transient\\_timeout\\_%tix\\_offer\\_v\\_%'");
$admin = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
$mk = function ($role, $n) use (&$users, $sfx) { $u = wp_insert_user(['user_login' => "po_{$n}_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "po_{$n}_$sfx@example.test", 'display_name' => "PO $n", 'role' => $role]); $users[] = $u; return $u; };
$org = $mk('tix_organizer', 'org');

if (!$multi) {
    wp_set_current_user($admin);
    [$st, , $c] = po_rest('GET', '/platform/offers');
    [$st2] = po_rest('GET', '/platform/offer-catalog');
    po_eq('Einzel-Seite: keine Angebote (404)', [404, 'rest_no_route', 404], [$st, $c, $st2]);
    goto cleanup;
}
$s1 = $mk('tix_sales', 's1'); $s2 = $mk('tix_sales', 's2');

// ═══ 1. Rechte ═══
wp_set_current_user(0); [$a] = po_rest('GET', '/platform/offers');
wp_set_current_user($org); [$b] = po_rest('GET', '/platform/offers');
wp_set_current_user($s1); [$c] = po_rest('GET', '/platform/offers'); [$c2, , $cc2] = po_rest('POST', '/platform/offer-catalog', ['reset' => true]);
wp_set_current_user($admin); [$d] = po_rest('GET', '/platform/offers');
po_eq('Rechte: Gast 401, Veranstalter 403, Vertrieb 200, Admin 200; Katalog ändern nur Admin', [401, 403, 200, 403, 200], [$a, $b, $c, $c2, $d]);

// ═══ 2. Katalog-Startwerte ═══
wp_set_current_user($s1);
[$st, $d] = po_rest('GET', '/platform/offer-catalog');
$it = array_column((array) ($d['catalog']['items'] ?? []), null, 'key');
po_eq('Katalog: Preise der Preisseite (Plattform 2,9 % + 0,49 €, Shop Jahresvertrag nach Staffel ohne Monatspreis (1.38.823) bzw. 99 monatlich kündbar, Lizenz 699, App 149 + 1.490, kein Startangebot)',
    [200, false, false, 2.9, 0.49, 'yearly', 99.0, 699.0, 149.0, ['app_einrichtung'], 1490.0, 249.0, true, false, false],
    [$st, $d['custom'] ?? null, $d['can_edit'] ?? null, floatval($it['tickets']['fee_percent'] ?? 0), floatval($it['tickets']['fee_fixed'] ?? 0), isset($it['shop']['prices']['monthly']) ? 'monthly' : ($it['shop']['default_interval'] ?? ''),
        floatval($it['shop_monatlich']['prices']['monthly'] ?? 0), floatval($it['lizenz']['prices']['yearly'] ?? 0), floatval($it['app']['prices']['monthly'] ?? 0), $it['app']['adds'] ?? null,
        floatval($it['app_einrichtung']['prices']['once'] ?? 0), floatval($it['app_alle_module']['prices']['monthly'] ?? 0), count((array) ($d['catalog']['competitors'] ?? [])) >= 10,
        strpos(wp_json_encode($d['catalog'] ?? [], JSON_UNESCAPED_UNICODE), '31.01.2027') !== false, isset($it['jahresvertrag'])]);

// ═══ 3. Anlegen, Nummer, Summen, Vorteile ═══
$cust = ['company' => "Club Nord $sfx", 'name' => 'Lena Berg', 'email' => "po_kunde_$sfx@example.test", 'street' => 'Hafenstr. 1', 'zip' => '20457', 'city' => 'Hamburg'];
[$st, $d] = po_rest('POST', '/platform/offers', ['customer' => $cust, 'mode' => 'own_shop', /* Betriebsart Pflicht beim Senden ab 1.38.821 */ 'items' => [['catalog_key' => 'shop'], ['catalog_key' => 'app'], ['catalog_key' => 'datenumzug', 'price' => 250]],
    'discount' => ['type' => 'percent', 'value' => 10, 'on' => 'recurring'], 'comparison' => ['competitor_id' => 'eventim_light'], 'term_months' => 12]);
$o1 = $d['offer'] ?? []; $ids[] = intval($o1['id'] ?? 0);
$y = wp_date('Y');
$keys = array_column((array) ($o1['data']['items'] ?? []), 'catalog_key');
po_eq('Anlegen: 201, Nummer AN-JJMM-NNNN (ab 1.38.829, NNNN ab 4.000), Entwurf, App-Einrichtung automatisch dazu (ab 819 auch „Einrichtung inklusive“ beim eigenen Shop)', [201, 1, 'draft', ['shop', 'app', 'datenumzug', 'app_einrichtung', 'einrichtung']],
    [$st, preg_match('/^AN-' . wp_date('ym') . '-\\d{4,}$/', (string) ($o1['number'] ?? '')), $o1['status'] ?? '', $keys]);
$t = $o1['totals'] ?? [];
po_eq('Summen: Jahr 790 (Staffel, nicht 12 × 79) + 12 × 149 = 2.578, −10 % = 2.320,20 netto, 2.761,04 brutto; einmalig 250 + 1.490 = 1.740 netto, 2.070,60 brutto; 193,35 im Monat',
    [2578.0, 257.8, 2320.2, 440.84, 2761.04, 1740.0, 0.0, 2070.6, 193.35, []],
    [floatval($t['yearly']['net'] ?? 0), floatval($t['yearly']['discount'] ?? 0), floatval($t['yearly']['net_after'] ?? 0), floatval($t['yearly']['tax'] ?? 0), floatval($t['yearly']['gross'] ?? 0),
        floatval($t['once']['net'] ?? 0), floatval($t['once']['discount'] ?? 0), floatval($t['once']['gross'] ?? 0), floatval($t['monthly_net'] ?? 0), $t['missing_prices'] ?? null]);
$bk = array_column((array) ($o1['data']['benefits'] ?? []), 'key');
po_eq('Vorteile automatisch je Tarif/Modul + „In jedem Konto“; Vergleich nur als Gesprächshilfe gespeichert', [true, true, true, true, 'EVENTIM.Light', 1.41],
    [in_array('shop_domain', $bk, true), in_array('app_stores', $bk, true), in_array('designs', $bk, true), !in_array('eigene_seite', $bk, true),
        $o1['data']['comparison']['name'] ?? '', floatval($o1['data']['comparison']['examples'][0]['them'] ?? 0)]);
[$st, $d] = po_rest('POST', '/platform/offers', ['customer' => ['company' => "Theater Süd $sfx", 'email' => "po_t_$sfx@example.test"], 'mode' => 'platform', 'items' => ['tickets']]);
$o2 = $d['offer'] ?? []; $ids[] = intval($o2['id'] ?? 0);
$n1 = intval(substr((string) $o1['number'], -4)); $n2 = intval(substr((string) ($o2['number'] ?? ''), -4));
po_eq('Nummernkreis aufsteigend mit zufälliger Schrittweite 7–41 (lässt keinen Schluss auf die Anzahl zu), ab 4.000; Ticketplattform als Gebühr je Ticket, keine Summe', [true, true, 0.0, 1, true],
    [$n2 - $n1 >= 7 && $n2 - $n1 <= 41, $n1 > 4000, floatval($o2['totals']['yearly']['gross'] ?? -1), count((array) ($o2['totals']['fees'] ?? [])), in_array('eigene_seite', array_column((array) ($o2['data']['benefits'] ?? []), 'key'), true)]);

// ═══ 4. Ändern, Preis überschreiben, Live-Summe ═══
$items = $o1['data']['items']; $items[0]['price'] = 69;
[$st, $d] = po_rest('PATCH', '/platform/offers/' . $o1['id'], ['items' => $items, 'benefits' => array_merge($o1['data']['benefits'], [['title' => 'Eigener Punkt', 'text' => 'Persönliche Betreuung']])]);
$ln = $d['offer']['totals']['lines'][0] ?? [];
po_eq('Preis überschreiben: Listenpreis bleibt 790 (Staffel), Preis 69, markiert; eigener Vorteil', [200, 790.0, 69.0, true, 'Eigener Punkt'],
    [$st, floatval($d['offer']['data']['items'][0]['list_price'] ?? 0), floatval($ln['unit'] ?? 0), $ln['overridden'] ?? null, end($d['offer']['data']['benefits'])['title'] ?? '']);
[$st, $d] = po_rest('POST', '/platform/offers/calc', ['items' => [['catalog_key' => 'datenumzug', 'name' => 'Datenumzug'], ['catalog_key' => 'shop', 'name' => 'Eigener Shop', 'interval' => 'monthly']]]);
po_eq('Live-Summe: fehlender Preis (Datenumzug auf Anfrage) wird gemeldet; Shop „monatlich“ wird Jahresvertrag 790 statt 12 × 79 (1.38.823)', [200, 1, 790.0, true],
    [$st, count((array) ($d['totals']['missing_prices'] ?? [])), floatval($d['totals']['yearly']['net'] ?? 0), count((array) ($d['benefits_suggested'] ?? [])) >= 5]);

// ═══ 4b. Hochrechnung je Event (Feldnamen und Rechnung wie projectionCalc im Studio) ═══
$pj = ['tickets' => 450, 'avg_price' => 24, 'events_year' => 20, 'tickets_per_order' => 2, 'payment' => 'both', 'paypal_share' => 30];
[$st, $d] = po_rest('PATCH', '/platform/offers/' . $o1['id'], ['projection' => $pj]);
po_eq('Prognose am Angebot gespeichert, Modell aus dem Tarif (Shop → selfhosted), Kartenanteil 70 %, im PDF an', [200, 'selfhosted', 70, true, 'organizer'],
    [$st, $d['offer']['data']['projection']['model'] ?? '', $d['offer']['data']['projection']['card_klarna_share'] ?? 0, $d['offer']['data']['projection']['in_pdf'] ?? null, $d['offer']['data']['projection']['bearer'] ?? '']);
// Beispiel Stadthalle aus dem Studio: 450 × 24 €, 20 Events, 2 je Bestellung, 30/70, Shop + App
[$st, $d] = po_rest('POST', '/platform/offers/calc', ['items' => [['catalog_key' => 'shop'], ['catalog_key' => 'app']], 'projection' => $pj]);
$fc = $d['totals']['projection'] ?? [];
$m = array_column((array) ($fc['selfhosted']['methods'] ?? []), null, 'key');
po_eq('Stadthalle: Provisionsmodell 533,70 € je Event (450 × 1,186 €, 2,9 % + 0,49 €), Selbstgehostet 558,45 € (206,94 an evendis = (1.690 Staffel bei 9.000 Tickets + 12 × 149) × 1,19 = 4.138,82 € ÷ 20, 351,51 an den Zahlungsdienstleister)',
    [200, 533.7, 0.0, 206.94, 351.51, 558.45, 4138.82, 'offer'],
    [$st, floatval($fc['commission']['total_event'] ?? 0), floatval($fc['commission']['to_psp_event'] ?? -1), floatval($fc['selfhosted']['to_evendis_event'] ?? 0),
        floatval($fc['selfhosted']['to_psp_event'] ?? 0), floatval($fc['selfhosted']['total_event'] ?? 0), floatval($fc['selfhosted']['package_year'] ?? 0), $fc['selfhosted']['package_source'] ?? '']);
po_eq('Zahlarten: PayPal 30 %, Karte und Klarna je 35 % (je zur Hälfte), Fixbetrag je Zahlung (2 Tickets je Bestellung)', [[30.0, 35.0, 35.0], 123.2, 87.73, 140.58],
    [[floatval($m['paypal']['share'] ?? 0), floatval($m['card']['share'] ?? 0), floatval($m['klarna']['share'] ?? 0)], floatval($m['paypal']['cost_event'] ?? 0), floatval($m['card']['cost_event'] ?? 0), floatval($m['klarna']['cost_event'] ?? 0)]);
po_eq('Vergleich (Satz 2,9 % + 0,49 €): Provisionsmodell um 24,75 € je Event günstiger (495 € im Jahr), Selbstgehostet lohnt sich ab 852 Tickets im Monat; Sätze mit Stand und Quelle, Pflicht-Hinweis',
    ['commission', 24.75, 495.0, 852, '2026-11-04', true, true, true],
    [$fc['comparison']['cheaper'] ?? '', floatval($fc['comparison']['difference_event'] ?? 0), floatval($fc['comparison']['difference_year'] ?? 0), intval($fc['comparison']['break_even_tickets_month'] ?? 0),
        $m['paypal']['as_of'] ?? '', strpos((string) ($m['card']['source'] ?? ''), 'mollie.com') !== false, strpos((string) ($fc['payment_note'] ?? ''), 'nicht an evendis') !== false,
        strpos((string) ($fc['rates_note'] ?? ''), 'Stand 04.11.2026') !== false]);
[$st, $d] = po_rest('POST', '/platform/offers/calc', ['items' => ['tickets'], 'projection' => ['model' => 'commission', 'tickets' => 100, 'avg_price' => 0, 'payment' => 'paypal', 'bearer' => 'split']]);
$fc2 = $d['totals']['projection'] ?? [];
$m2 = array_column((array) ($fc2['selfhosted']['methods'] ?? []), null, 'key');
po_eq('Freitickets: Provision 0 €; ohne Shop im Angebot rechnet Selbstgehostet mit dem Katalog-Shop (Jahresvertrag 790 inkl. MwSt. = 940,10 €); nur PayPal (100 Zahlungen × 0,39 €); Gebühr geteilt',
    [200, 'commission', 0.0, 'catalog', 940.1, ['paypal'], 39.0, 'split'],
    [$st, $fc2['input']['model'] ?? '', floatval($fc2['commission']['total_event'] ?? -1), $fc2['selfhosted']['package_source'] ?? '', floatval($fc2['selfhosted']['package_year'] ?? 0),
        array_keys($m2), floatval($m2['paypal']['cost_event'] ?? 0), $fc2['commission']['bearer'] ?? '']);
[, $d] = po_rest('POST', '/platform/offers/calc', ['items' => ['tickets']]);
po_eq('Ohne Prognose keine Hochrechnung', [true, null], [array_key_exists('projection', (array) ($d['totals'] ?? [])), $d['totals']['projection'] ?? null]);
[, $d] = po_rest('GET', '/platform/offer-catalog');
$pr = $d['catalog']['payment_rates'] ?? [];
po_eq('Katalog: payment_rates als Objekt {paypal, card, klarna} mit Stand, Abruf und Quelle', [2.99, 0.39, 1.8, 0.25, 2.99, 0.35, '2026-11-05', '2026-11-06', true],
    [floatval($pr['paypal']['percent'] ?? 0), floatval($pr['paypal']['fixed'] ?? 0), floatval($pr['card']['percent'] ?? 0), floatval($pr['card']['fixed'] ?? 0), floatval($pr['klarna']['percent'] ?? 0),
        floatval($pr['klarna']['fixed'] ?? 0), $pr['card']['as_of'] ?? '', $pr['paypal']['retrieved'] ?? '', strpos((string) ($pr['paypal']['source'] ?? ''), 'paypal.com') !== false]);

// ═══ 5. Sichtbarkeit Vertrieb / Admin ═══
wp_set_current_user($s2);
[$st] = po_rest('GET', '/platform/offers/' . $o1['id']);
[, $l2] = po_rest('GET', '/platform/offers');
wp_set_current_user($admin);
[$sta] = po_rest('GET', '/platform/offers/' . $o1['id']);
[, $la] = po_rest('GET', '/platform/offers', ['q' => "Club Nord $sfx"]);
po_eq('Vertrieb sieht nur eigene Angebote, Admin alle; Suche', [404, 0, 200, 1, 'PO s1'], [$st, intval($l2['total'] ?? -1), $sta, intval($la['total'] ?? 0), $la['offers'][0]['owner']['name'] ?? '']);

// ═══ 6. PDF ═══
wp_set_current_user($s1);
[$st, $d] = po_rest('GET', '/platform/offers/' . $o1['id'] . '/pdf');
$pdf = base64_decode((string) ($d['data'] ?? ''));
po_eq('PDF: %PDF, Dateiname mit Nummer und Kunde', [200, '%PDF', true, true], [$st, substr($pdf, 0, 4), strlen($pdf) > 3000, strpos((string) ($d['filename'] ?? ''), (string) $o1['number']) !== false && strpos((string) $d['filename'], 'Club-Nord') !== false]);
$pdf_txt = (function ($b) { $t = ''; if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $b, $m)) foreach ($m[1] as $x) { $u = @gzuncompress($x); $t .= $u !== false ? $u : $x; } return $t; })($pdf);
po_eq('PDF lesbar („Bedingungen“, „Interesse bestätigen“), ohne Wettbewerbsvergleich und ohne Startangebot', [true, true, false, false, false],
    [stripos($pdf_txt, 'Bedingungen') !== false, stripos($pdf_txt, 'Interesse') !== false, stripos($pdf_txt, 'Im Vergleich') !== false, stripos($pdf_txt, 'EVENTIM') !== false, strpos($pdf_txt, '31.01.2027') !== false]);
po_eq('PDF ohne compare_platform: keine „Hochrechnung je Event“ (Plattform-Vergleich nur auf Wunsch, 1.38.823)', false, strpos($pdf_txt, 'Hochrechnung je Event') !== false);
$o_cp = TIX_Platform_Offers::data(TIX_Platform_Offers::get($o1['id'])); $o_cp['compare_platform'] = true;
$pdf_txt = (function ($b) { $t = ''; if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $b, $m)) foreach ($m[1] as $x) { $u = @gzuncompress($x); $t .= $u !== false ? $u : $x; } return $t; })((string) TIX_Platform_Offers::render_pdf($o_cp, ['number' => 'T', 'link' => '']));
po_eq('PDF mit compare_platform: Block „Hochrechnung je Event“ mit beiden Modellen und Hinweis zum Zahlungsdienstleister', [true, true, true, true],
    [strpos($pdf_txt, 'Hochrechnung je Event') !== false, strpos($pdf_txt, 'PROVISIONSMODELL') !== false && strpos($pdf_txt, 'EIGENER SHOP') !== false, // Begriffe 14.11.: Shop-Angebot = eigener Shop, nicht selbstgehostet
        strpos($pdf_txt, 'Zahlungsanbieter') !== false, strpos($pdf_txt, 'Tickets im Monat') !== false]);
$o_nf = TIX_Platform_Offers::data(TIX_Platform_Offers::get($o1['id'])); $o_nf['projection']['in_pdf'] = false; $o_nf['compare_platform'] = true;
$pdf_off = (string) TIX_Platform_Offers::render_pdf($o_nf, ['number' => 'T', 'link' => '']);
$off_txt = (function ($b) { $t = ''; if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $b, $m)) foreach ($m[1] as $x) { $u = @gzuncompress($x); $t .= $u !== false ? $u : $x; } return $t; })($pdf_off);
po_eq('PDF-Block abschaltbar (in_pdf: false)', [true, false], [strpos($off_txt, 'Bedingungen') !== false, strpos($off_txt, 'Hochrechnung je Event') !== false]);
[$st, $d] = po_rest('POST', '/platform/offers/preview', ['customer' => $cust, 'items' => ['lizenz', 'schulung'], 'benefits' => []]);
po_eq('PDF-Vorschau ohne Speichern (auch mit fehlendem Preis)', [200, '%PDF'], [$st, substr(base64_decode((string) ($d['data'] ?? '')), 0, 4)]);

// ═══ 7. Senden ═══
[$st, , $c] = po_rest('POST', '/platform/offers/' . $o1['id'] . '/send', []);
[$st2, , $c2] = po_rest('POST', '/platform/offers/calc', []);
$o3 = po_rest('POST', '/platform/offers', ['customer' => $cust, 'items' => [['catalog_key' => 'datenumzug']]])[1]['offer']; $ids[] = intval($o3['id']);
[$st3, , $c3] = po_rest('POST', '/platform/offers/' . $o3['id'] . '/send', ['confirm' => true]);
po_eq('Senden: ohne Bestätigung 400, ohne Preis 422 tix_offer_incomplete (ab 1.38.821)', [400, 'tix_confirm_required', 422, 'tix_offer_incomplete'], [$st, $c, $st3, $c3]);
$mails = [];
[$st, $d] = po_rest('POST', '/platform/offers/' . $o1['id'] . '/send', ['confirm' => true, 'message' => 'Wie besprochen, hier dein Angebot.']);
$to = array_column($mails, 'to');
po_eq('Senden: Mail an den Kunden mit PDF, Kopie an mich, Status gesendet, Annahme-Link', [200, 'sent', ["po_kunde_$sfx@example.test", "po_s1_$sfx@example.test"], [true, true], true, true],
    [$st, $d['offer']['status'] ?? '', $to, array_column($mails, 'pdf_ok'), strpos((string) ($d['offer']['link'] ?? ''), 'tix_offer=') !== false,
        strpos((string) ($mails[0]['message'] ?? ''), 'Wie besprochen') !== false]);
$token = (string) $wpdb->get_var($wpdb->prepare('SELECT token FROM ' . TIX_Platform_Offers::table() . ' WHERE id = %d', $o1['id']));
[$st, , $c] = po_rest('PATCH', '/platform/offers/' . $o1['id'], ['intro' => 'Neu']);
[$st2, $d2] = po_rest('PATCH', '/platform/offers/' . $o1['id'], ['internal_note' => 'Rückruf Freitag']);
[$st3, , $c3] = po_rest('DELETE', '/platform/offers/' . $o1['id']);
po_eq('Nach dem Senden: Ändern 409, interne Notiz geht, Löschen 409', [409, 'tix_offer_locked', 200, 'Rückruf Freitag', 409], [$st, $c, $st2, $d2['offer']['data']['internal_note'] ?? '', $st3]);

// ═══ 8. Öffentlich: ansehen, annehmen ═══
wp_set_current_user(0);
[$st, $d] = po_rest('GET', "/public/offers/$token");
po_rest('GET', "/public/offers/$token");
$row = TIX_Platform_Offers::get($o1['id']);
po_eq('Kunde öffnet den Link: Daten ohne interne Notiz, Zähler 1 (gleiche IP zählt einmal), Status angesehen', [200, "Club Nord $sfx", false, true, 1, 'viewed'],
    [$st, $d['offer']['customer']['company'] ?? '', strpos(wp_json_encode($d), 'Rückruf') !== false, $d['offer']['can_decide'] ?? null, intval($row->views), $row->status]);
[$st, $d] = po_rest('GET', "/public/offers/$token/pdf");
[$st2, , $c2] = po_rest('GET', '/public/offers/' . str_repeat('x', 40));
po_eq('Öffentliches PDF; unbekannter Token 404', [200, '%PDF', 404, 'tix_offer_not_found'], [$st, substr(base64_decode((string) ($d['data'] ?? '')), 0, 4), $st2, $c2]);
po_eq('Kundenseite ohne Vergleich mit dem bisherigen Anbieter', false, array_key_exists('comparison', (array) ($d0 = po_rest('GET', "/public/offers/$token")[1]['offer'] ?? [])) || strpos(wp_json_encode($d0), 'EVENTIM') !== false);
[$a, , $ac] = po_rest('POST', "/public/offers/$token/interest", ['name' => 'Le']);
$mails = [];
[$c, $cd] = po_rest('POST', "/public/offers/$token/interest", ['name' => 'Lena Berg', 'note' => 'Wir freuen uns!']);
[$e, , $ec] = po_rest('POST', "/public/offers/$token/accept", ['name' => 'Lena Berg']);
po_eq('Interesse bestätigen: Name zu kurz 400, ohne Häkchen möglich, Status accepted (= Interesse bestätigt), Hinweis „Interesse bestätigt“ an den Ersteller, zweites Mal 409',
    [400, 'tix_offer_name', 200, 'accepted', true, true, 409, 'tix_offer_decided'],
    [$a, $ac, $c, $cd['offer']['status'] ?? '', in_array("po_s1_$sfx@example.test", array_column($mails, 'to'), true), strpos((string) ($mails[0]['subject'] ?? ''), 'Interesse bestätigt') !== false, $e, $ec]);
wp_set_current_user($s1);
[, $d] = po_rest('GET', '/platform/offers/' . $o1['id']);
po_eq('Studio: Entscheidung mit Name und Nachricht, Zeitstempel', ['accepted', 'customer', 'Lena Berg', 'Wir freuen uns!', true],
    [$d['offer']['status'] ?? '', $d['offer']['decision']['by'] ?? '', $d['offer']['decision']['name'] ?? '', $d['offer']['decision']['note'] ?? '', !empty($d['offer']['decided_at'])]);

// ═══ 9. Version, Duplizieren, Ablauf, Status von Hand ═══
$items2 = $o2['data']['items'];
po_rest('POST', '/platform/offers/' . $o2['id'] . '/send', ['confirm' => true, 'cc_me' => false]);
$tok2 = (string) $wpdb->get_var($wpdb->prepare('SELECT token FROM ' . TIX_Platform_Offers::table() . ' WHERE id = %d', $o2['id']));
[$st, $d] = po_rest('POST', '/platform/offers/' . $o2['id'] . '/version');
$v2 = $d['offer'] ?? []; $ids[] = intval($v2['id'] ?? 0);
wp_set_current_user(0); [$pst, , $pc] = po_rest('GET', "/public/offers/$tok2"); wp_set_current_user($s1);
po_eq('Neue Version: gleiche Grundnummer -2, Entwurf; alte ersetzt, alter Link 410', [201, $o2['number'] . '-2', 'draft', 'replaced', 410, 'tix_offer_replaced'],
    [$st, $v2['number'] ?? '', $v2['status'] ?? '', TIX_Platform_Offers::get($o2['id'])->status, $pst, $pc]);
[$st, $d] = po_rest('POST', '/platform/offers/' . $o1['id'] . '/duplicate');
$du = $d['offer'] ?? []; $ids[] = intval($du['id'] ?? 0);
po_eq('Duplizieren: neue Nummer, Entwurf, ohne Entscheidung', [201, 'draft', true, null], [$st, $du['status'] ?? '', ($du['number'] ?? '') !== $o1['number'] && strpos((string) $du['number'], '-' . wp_date('ym') . '-') !== false, $du['decision'] ?? null]);
[, $l] = po_rest('GET', '/platform/offers');
po_eq('Liste ohne ersetzte Fassungen, Zähler je Status', [false, 1], [in_array($o2['id'], array_column((array) $l['offers'], 'id'), true), intval($l['counts']['accepted'] ?? 0)]);
// Ablauf
po_rest('PATCH', '/platform/offers/' . $v2['id'], ['valid_until' => wp_date('Y-m-d', time() + DAY_IN_SECONDS)]);
po_rest('POST', '/platform/offers/' . $v2['id'] . '/send', ['confirm' => true, 'cc_me' => false]);
$tok3 = (string) $wpdb->get_var($wpdb->prepare('SELECT token FROM ' . TIX_Platform_Offers::table() . ' WHERE id = %d', $v2['id']));
$wpdb->update(TIX_Platform_Offers::table(), ['valid_until' => wp_date('Y-m-d', time() - 2 * DAY_IN_SECONDS)], ['id' => $v2['id']]);
[, $l] = po_rest('GET', '/platform/offers', ['status' => 'expired']);
wp_set_current_user(0); [$pst, , $pc] = po_rest('GET', "/public/offers/$tok3"); wp_set_current_user($s1);
TIX_Platform_Offers::expire_due();
po_eq('Abgelaufen: in der Liste „abgelaufen“, Link 410, Cron setzt den Status', [true, 410, 'tix_offer_expired', 'expired'],
    [in_array($v2['id'], array_column((array) $l['offers'], 'id'), true), $pst, $pc, TIX_Platform_Offers::get($v2['id'])->status]);
[$st, , $c] = po_rest('POST', '/platform/offers/' . $v2['id'] . '/send', ['confirm' => true]);
po_eq('Abgelaufen senden: 409 mit Hinweis auf „Gültig bis“', [409, 'tix_offer_expired'], [$st, $c]);
[$st, $d] = po_rest('POST', '/platform/offers/' . $v2['id'] . '/status', ['status' => 'rejected', 'note' => 'Zu teuer']);
po_eq('Status von Hand: abgelehnt mit Notiz', [200, 'rejected', 'team', 'Zu teuer'], [$st, $d['offer']['status'] ?? '', $d['offer']['decision']['by'] ?? '', $d['offer']['decision']['note'] ?? '']);
[$st] = po_rest('DELETE', '/platform/offers/' . $o3['id']);
po_eq('Entwurf löschen', [200, null], [$st, TIX_Platform_Offers::get($o3['id'])]);

// ═══ 10. Online-Seite ═══
po_eq('Annahme-Seite: eigenständiges HTML mit Formular bzw. Hinweis', [true, true],
    [strpos(TIX_Platform_Offers::accept_url('abc'), 'tix_offer=abc') !== false, (function () use ($o1) { $r = new ReflectionMethod('TIX_Platform_Offers', 'page_html'); $r->setAccessible(true);
        $h = $r->invoke(null, TIX_Platform_Offers::get($o1['id']), 'x', ''); return strpos($h, 'Interesse ist bestätigt') !== false && strpos($h, 'Vertrag') !== false && strpos($h, 'Deine Vorteile') !== false && strpos($h, 'Grundpaket im Jahr') !== false && strpos($h, 'Optional dazubuchbar') !== false && stripos($h, 'verbindlich an') === false; })()]);

// ═══ 11. Katalog pflegen, KI-Einleitung ═══
wp_set_current_user($admin);
[$st, , $c] = po_rest('POST', '/platform/offer-catalog', ['catalog' => ['items' => []]]);
$cat = TIX_Platform_Offers::default_catalog();
$cat['shop_tiers'] = [['max' => 5000, 'price' => 750], ['max' => 10000, 'price' => 1690]]; // Jahresvertrag nach Staffel (1.38.823)
$cat['items'][] = ['key' => 'shop', 'name' => 'Doppelt'];
[$st2, , $c2] = po_rest('POST', '/platform/offer-catalog', ['catalog' => $cat]);
array_pop($cat['items']);
[$st3, $d3] = po_rest('POST', '/platform/offer-catalog', ['catalog' => $cat]);
$n = po_rest('POST', '/platform/offers', ['customer' => $cust, 'items' => [['catalog_key' => 'shop']]])[1]['offer']; $ids[] = intval($n['id']);
po_eq('Katalog: leer 400, doppelter Schlüssel 400, Staffel geändert gilt für neue Angebote, altes behält seinen', [400, 400, 'tix_catalog_invalid', 200, true, 750.0, 1690.0],
    [$st, $st2, $c2, $st3, $d3['custom'] ?? null, floatval($n['data']['items'][0]['list_price'] ?? 0), floatval(TIX_Platform_Offers::data(TIX_Platform_Offers::get($o1['id']))['items'][0]['list_price'] ?? 0)]);
$cat2 = TIX_Platform_Offers::default_catalog();
$cat2['payment_rates']['klarna']['percent'] = null; $cat2['payment_rates']['klarna']['fixed'] = null;
[$stc] = po_rest('POST', '/platform/offer-catalog', ['catalog' => $cat2]);
[, $dm] = po_rest('POST', '/platform/offers/calc', ['items' => ['shop'], 'projection' => ['tickets' => 100, 'avg_price' => 10, 'payment' => 'both']]);
$fm = $dm['totals']['projection'] ?? [];
po_eq('Fehlender Satz im Katalog: „bitte eintragen“ statt Zahl (rate_missing), keine Summe', [200, ['klarna'], true, null, null],
    [$stc, $fm['selfhosted']['missing_rates'] ?? null, (bool) (array_column((array) ($fm['selfhosted']['methods'] ?? []), null, 'key')['klarna']['rate_missing'] ?? false),
        array_key_exists('total_event', (array) ($fm['selfhosted'] ?? [])) ? $fm['selfhosted']['total_event'] : 'fehlt', array_key_exists('cheaper', (array) ($fm['comparison'] ?? [])) ? $fm['comparison']['cheaper'] : 'fehlt']);
[$st, $d] = po_rest('POST', '/platform/offer-catalog', ['reset' => true]);
po_eq('Katalog zurücksetzen', [200, false], [$st, $d['custom'] ?? null]);
update_option(TIX_AI_Provider::OPT, ['provider' => 'claude', 'key_enc' => TIX_Ad_Accounts::enc('sk-ant-api03-' . str_repeat('o', 40) . 'Zz99')], false);
TIX_AI_Provider::$http = function ($url, $args) {
    return ['headers' => [], 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null,
        'body' => wp_json_encode(['model' => 'claude-sonnet-5-5', 'content' => [['type' => 'text', 'text' => "Hallo Lena,\nschön, dass ihr mit dem Club Nord den nächsten Schritt geht. Mit eurem eigenen Shop bleibt das Geld bei euch."]], 'usage' => ['input_tokens' => 300, 'output_tokens' => 60]])];
};
[$st, $d] = po_rest('POST', '/platform/offers/intro', ['offer_id' => $o1['id']]);
TIX_AI_Provider::$http = null;
po_eq('KI-Einleitung: Vorschlag ohne Anrede-Zeile', [200, true, false], [$st, strpos((string) ($d['intro'] ?? ''), 'Club Nord') !== false, stripos((string) ($d['intro'] ?? ''), 'Hallo') === 0]);

cleanup:
foreach ($ids as $i) if ($i) $wpdb->delete(TIX_Platform_Offers::table(), ['id' => $i]);
foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($users as $u) wp_delete_user($u);
wp_set_current_user(0);
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES GRÜN') . " ({$GLOBALS['t_count']} Prüfungen)\n";
