<?php
/**
 * Unit-Tests für TIX_Fees (Modi organizer/split/customer) und die Abrechnungs-Rechnung
 * je Bestellung (TIX_Settlement::order_line). Ohne WordPress, mit kleinen Stubs.
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/test-fees.php
 */
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);

// ── Stubs ──
$GLOBALS['t_settings'] = [];
$GLOBALS['t_options']  = [];
$GLOBALS['t_meta']     = [];
function tix_get_settings($k = null) { return $k === null ? $GLOBALS['t_settings'] : ($GLOBALS['t_settings'][$k] ?? null); }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['t_options']) ? $GLOBALS['t_options'][$k] : $d; }
function get_post_meta($id, $k, $single = true) { return $GLOBALS['t_meta'][$id][$k] ?? ''; }

require __DIR__ . '/../includes/class-tix-fees.php';
require __DIR__ . '/../includes/class-tix-settlement.php';

$fails = 0; $count = 0;
function eq($label, $expected, $actual) {
    global $fails, $count;
    $count++;
    $ok = is_float($expected) || is_float($actual) ? abs(floatval($expected) - floatval($actual)) < 0.001 : $expected === $actual;
    if (!$ok) { $fails++; echo "FAIL  $label: erwartet " . var_export($expected, true) . ", ist " . var_export($actual, true) . "\n"; }
    else echo "ok    $label\n";
}
function reset_env(array $settings = [], array $options = [], array $meta = []) {
    $GLOBALS['t_settings'] = $settings + [
        'fee_fixed' => 0.5, 'fee_percent' => 3, 'fee_mode' => 'organizer', 'fee_label' => 'Servicegebühr',
        'gateway_fee_fixed' => 0, 'gateway_fee_percent' => 0, 'gateway_fee_mode' => 'organizer',
        'fee_rounding' => 'none', 'fee_rounding_custom' => 0, 'fee_max_per_ticket' => 0, 'fee_max_per_order' => 0,
        'fee_split_customer_share' => 50,
    ];
    $GLOBALS['t_options'] = $options;
    $GLOBALS['t_meta'] = $meta;
}
$one = fn($price, $qty = 1) => [['price' => $price, 'qty' => $qty, 'event_id' => 0]];
$calc = function ($items, array $over = []) { return TIX_Fees::calc_with_config($items, array_merge(TIX_Fees::get_fee_config(), $over)); };

// ── 1. Drei Modi, Ticket 20 €, Gebühr 0,50 € + 3 % = 1,10 € ──
reset_env();
$r = $calc($one(20), ['fee_mode' => 'organizer']);
eq('organizer: Gebühr', 1.10, $r['platform_fee']);
eq('organizer: Kunde zahlt', 20.00, $r['customer_total']);
eq('organizer: Veranstalter erhält', 18.90, $r['organizer_payout']);
eq('organizer: Kundenanteil', 0.0, $r['platform_fee_customer']);

$r = $calc($one(20), ['fee_mode' => 'split']);
eq('split: Kunde zahlt', 20.55, $r['customer_total']);
eq('split: Gebührenzeile', 0.55, $r['customer_fee_line']);
eq('split: Veranstalter erhält', 19.45, $r['organizer_payout']);
eq('split: Anteile ergeben Gebühr', 1.10, $r['platform_fee_customer'] + $r['platform_fee_organizer']);
eq('split: Modus gespeichert', 'split', $r['platform_fee_mode']);
eq('split: Plattform erhält', 1.10, $r['platform_revenue']);

$r = $calc($one(20), ['fee_mode' => 'customer']);
eq('customer: Kunde zahlt', 21.10, $r['customer_total']);
eq('customer: Veranstalter erhält', 20.00, $r['organizer_payout']);

// ── 2. Split mit ungeradem Cent: 0,51 € + 3 % = 1,11 € → Kunde 0,56, Veranstalter 0,55 ──
$r = $calc($one(20), ['fee_mode' => 'split', 'fee_fixed' => 0.51]);
eq('split ungerade: Kunde', 0.56, $r['platform_fee_customer']);
eq('split ungerade: Veranstalter', 0.55, $r['platform_fee_organizer']);
eq('split ungerade: Summe', 1.11, $r['platform_fee']);

// ── 3. Anderer Kundenanteil (30 %) ──
$r = $calc($one(20), ['fee_mode' => 'split', 'fee_split_share' => 30]);
eq('split 30 %: Kunde', 0.33, $r['platform_fee_customer']);
eq('split 30 %: Veranstalter erhält', 19.23, $r['organizer_payout']);

// ── 4. Rundung im Split-Modus (auf x,90) ──
$r = $calc($one(20), ['fee_mode' => 'split', 'fee_rounding' => '0.90']);
eq('split+Rundung: Kunde zahlt', 20.90, $r['customer_total']);
eq('split+Rundung: Überschuss', 0.35, $r['rounding_surplus']);
eq('split+Rundung: Veranstalter unverändert', 19.45, $r['organizer_payout']);
eq('split+Rundung: Plattform', 1.45, $r['platform_revenue']);
$r = $calc($one(20), ['fee_mode' => 'organizer', 'fee_rounding' => '0.90']);
eq('organizer+Rundung: keine Rundung', 20.00, $r['customer_total']);

// ── 5. Höchstbeträge ──
$r = $calc($one(20, 3), ['fee_mode' => 'split', 'fee_max_per_order' => 3]);
eq('max/Bestellung: Gebühr', 3.00, $r['platform_fee']);
eq('max/Bestellung: Kunde', 1.50, $r['platform_fee_customer']);
eq('max/Bestellung: Veranstalter erhält', 58.50, $r['organizer_payout']);
$r = $calc($one(100), ['fee_mode' => 'customer', 'fee_max_per_ticket' => 2]);
eq('max/Ticket: Gebühr', 2.00, $r['platform_fee']);

// ── 6. Zahlungsgebühr beim Veranstalter (0,25 € + 1,5 %) ──
$r = $calc($one(20), ['fee_mode' => 'split', 'gateway_fee_fixed' => 0.25, 'gateway_fee_percent' => 1.5]);
eq('Zahlungsgebühr split: Betrag', 0.56, $r['gateway_fee']);
eq('Zahlungsgebühr split: Kunde zahlt', 20.55, $r['customer_total']);
eq('Zahlungsgebühr split: Veranstalter erhält', 18.89, $r['organizer_payout']);
$r = $calc($one(20), ['fee_mode' => 'customer', 'gateway_fee_fixed' => 0.25, 'gateway_fee_percent' => 1.5, 'gateway_fee_mode' => 'customer']);
eq('Zahlungsgebühr Kunde: Veranstalter erhält', 20.00, $r['organizer_payout']);
eq('Zahlungsgebühr Kunde: Kunde zahlt', 21.68, $r['customer_total']);

// ── 7. Gebührenfreie Kategorie ──
reset_env([], [], [77 => ['_tix_ticket_categories' => [['no_fee' => 1], []]]]);
$r = TIX_Fees::calc_with_config([['price' => 20, 'qty' => 1, 'event_id' => 77, 'cat_index' => 0], ['price' => 20, 'qty' => 1, 'event_id' => 77, 'cat_index' => 1]],
    array_merge(TIX_Fees::get_fee_config(), ['fee_mode' => 'split']));
eq('no_fee: nur eine Gebühr', 1.10, $r['platform_fee']);

// ── 8. get_fee_config: Mehr-Veranstalter-Modus nur Modus, sonst eigene Beträge ──
$org_meta = [5 => ['_tix_fee_override' => 1, '_tix_fee_mode' => 'split', '_tix_fee_fixed' => 9, '_tix_fee_percent' => 9, '_tix_fee_label' => 'X']];
reset_env([], ['tix_multi_organizer' => '1'], $org_meta);
$c = TIX_Fees::get_fee_config(5);
eq('multi: Modus vom Veranstalter', 'split', $c['fee_mode']);
eq('multi: Fixbetrag zentral', 0.5, $c['fee_fixed']);
eq('multi: Prozent zentral', 3.0, $c['fee_percent']);
eq('multi: Bezeichnung zentral', 'Servicegebühr', $c['fee_label']);
reset_env([], ['tix_multi_organizer' => '0'], $org_meta);
$c = TIX_Fees::get_fee_config(5);
eq('einzeln: eigene Beträge', 9.0, $c['fee_fixed']);
eq('einzeln: split erlaubt', 'split', $c['fee_mode']);
reset_env(['fee_mode' => 'bogus']);
eq('ungültiger Modus → organizer', 'organizer', TIX_Fees::get_fee_config()['fee_mode']);

// ── 9. Client-Konfiguration (Modal/Express-Checkout) ──
reset_env();
eq('client: organizer → null', null, TIX_Fees::client_fee_config(array_merge(TIX_Fees::get_fee_config(), ['fee_mode' => 'organizer'])));
$cc = TIX_Fees::client_fee_config(array_merge(TIX_Fees::get_fee_config(), ['fee_mode' => 'split']));
eq('client: split share', 0.5, $cc['share']);
$cc = TIX_Fees::client_fee_config(array_merge(TIX_Fees::get_fee_config(), ['fee_mode' => 'customer']));
eq('client: customer share', 1.0, $cc['share']);

// ── 10. Abrechnung je Bestellung (TIX_Settlement::order_line) ──
$row = function ($id, $total, $status = 'completed', $pm = 'stripe', $line_event = 20, $line_all = 20, $qty = 1) {
    return (object) ['id' => $id, 'total' => $total, 'status' => $status, 'payment_method' => $pm, 'line_event' => $line_event, 'line_all' => $line_all, 'qty_event' => $qty, 'order_number' => 'T-' . $id];
};
$fees_split = ['platform_fee' => 1.10, 'platform_fee_mode' => 'split', 'platform_fee_customer' => 0.55, 'platform_fee_organizer' => 0.55,
               'customer_fee_line' => 0.55, 'gateway_fee' => 0, 'gateway_fee_mode' => 'organizer'];
reset_env(['gateway_fee_fixed' => 0.25, 'gateway_fee_percent' => 1.4], ['_tix_order_fees_1' => $fees_split], [1 => ['_tix_payment_fee' => 0.54, '_tix_payment_fee_currency' => 'EUR']]);
$l = TIX_Settlement::order_line($row(1, 20.55), 9, false);
eq('Abrechnung: Ticketumsatz', 20.00, $l['gross']);
eq('Abrechnung: Plattformgebühr Veranstalter', 0.55, $l['platform_fee']);
eq('Abrechnung: tatsächliche Stripe-Gebühr', 0.54, $l['gateway_fee']);
eq('Abrechnung: Quelle', 'actual', $l['gateway_fee_source']);
eq('Abrechnung: Netto', 18.91, $l['amount']);
eq('Abrechnung: Kundengebühr (Info)', 0.55, $l['customer_fee']);

// Ohne tatsächliche Gebühr → zentraler Satz (0,25 + 1,4 % von 20,55 = 0,54)
reset_env(['gateway_fee_fixed' => 0.25, 'gateway_fee_percent' => 1.4], ['_tix_order_fees_2' => $fees_split]);
$l = TIX_Settlement::order_line($row(2, 20.55), 9, false);
eq('Abrechnung: Satz statt Anbietergebühr', 0.54, $l['gateway_fee']);
eq('Abrechnung: Quelle Satz', 'rate', $l['gateway_fee_source']);
// Mollie mit Gebühr 0 → gilt als unbekannt
reset_env(['gateway_fee_fixed' => 0.25, 'gateway_fee_percent' => 1.4], ['_tix_order_fees_2' => $fees_split], [2 => ['_tix_payment_fee' => 0]]);
$l = TIX_Settlement::order_line($row(2, 20.55, 'completed', 'mollie'), 9, false);
eq('Abrechnung: Mollie 0 → Satz', 'rate', $l['gateway_fee_source']);
// Überweisung: keine Zahlungsgebühr
$l = TIX_Settlement::order_line($row(2, 20.55, 'completed', 'bank'), 9, false);
eq('Abrechnung: Überweisung ohne Zahlungsgebühr', 0.0, $l['gateway_fee']);

// Teil-Erstattung 5 € (Summe aller Erstattungen)
reset_env([], ['_tix_order_fees_3' => $fees_split, '_tix_refund_total_3' => 5.0], [3 => ['_tix_payment_fee' => 0.54]]);
$l = TIX_Settlement::order_line($row(3, 20.55), 9, false);
eq('Erstattung teilweise', 5.00, $l['refund']);
eq('Erstattung teilweise: Netto', 13.91, $l['amount']);
// Volle Erstattung (Status refunded): Ticketanteil, Gebühren bleiben
reset_env([], ['_tix_order_fees_4' => $fees_split], [4 => ['_tix_payment_fee' => 0.54]]);
$l = TIX_Settlement::order_line($row(4, 20.55, 'refunded'), 9, false);
eq('Erstattung voll: gedeckelt auf Ticketumsatz', 20.00, $l['refund']);
eq('Erstattung voll: Netto (Gebühren bleiben)', -1.09, $l['amount']);
// Ältere Erstattung ohne Summen-Option → letzte Erstattung
reset_env([], ['_tix_order_fees_5' => $fees_split, '_tix_refund_5' => ['amount' => 3]], [5 => ['_tix_payment_fee' => 0.54]]);
$l = TIX_Settlement::order_line($row(5, 20.55), 9, false);
eq('Erstattung alt (nur _tix_refund_)', 3.00, $l['refund']);

// Bestellung über zwei Events: hälftig
reset_env([], ['_tix_order_fees_6' => ['platform_fee' => 2.20, 'platform_fee_mode' => 'organizer', 'customer_fee_line' => 0, 'gateway_fee_mode' => 'organizer']], [6 => ['_tix_payment_fee' => 0.80]]);
$l = TIX_Settlement::order_line($row(6, 40, 'completed', 'stripe', 20, 40, 1), 9, false);
eq('Zwei Events: Umsatz halb', 20.00, $l['gross']);
eq('Zwei Events: Gebühr halb (alte Bestellung, organizer)', 1.10, $l['platform_fee']);
eq('Zwei Events: Zahlungsgebühr halb', 0.40, $l['gateway_fee']);
eq('Zwei Events: Netto', 18.50, $l['amount']);

// Alte Bestellung Kunden-Modus ohne Aufteilung → Veranstalter trägt nichts
reset_env([], ['_tix_order_fees_7' => ['platform_fee' => 1.10, 'platform_fee_mode' => 'customer', 'customer_fee_line' => 1.10, 'gateway_fee_mode' => 'customer']]);
$l = TIX_Settlement::order_line($row(7, 21.10), 9, false);
eq('Alt customer: Umsatz', 20.00, $l['gross']);
eq('Alt customer: keine Gebühr', 0.0, $l['platform_fee']);
eq('Alt customer: Zahlungsgebühr trägt Kunde', 'customer', $l['gateway_fee_source']);
eq('Alt customer: Netto', 20.00, $l['amount']);

// IBAN-Prüfung
require __DIR__ . '/../includes/class-tix-payout-details.php';
eq('IBAN gültig (DE89…3000)', true, TIX_Payout_Details::iban_valid('DE89 3704 0044 0532 0130 00'));
eq('IBAN Prüfziffer falsch', false, TIX_Payout_Details::iban_valid('DE88 3704 0044 0532 0130 00'));
eq('IBAN Länge falsch', false, TIX_Payout_Details::iban_valid('DE89 3704 0044 0532 0130 0'));
eq('IBAN AT gültig', true, TIX_Payout_Details::iban_valid('AT61 1904 3002 3457 3201'));
eq('IBAN maskiert', 'DE89 •••• •••• •••• 3000', TIX_Payout_Details::mask_iban('DE89370400440532013000'));

echo "\n" . ($count - $fails) . "/$count Tests grün\n";
exit($fails ? 1 : 0);
