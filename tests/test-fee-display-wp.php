<?php
/**
 * Gesamtpreis inkl. Servicegebühr (PAngV, 1.38.750): API (categories[].fee/total, price_from_total, fee_label), App-Quote,
 * Website-Ticketauswahl, Freitickets ohne Gebühr. tix_get_settings hält Einstellungen je Aufruf fest → drei Aufrufe:
 *   Einzel-Seite: wp eval-file …/test-fee-display-wp.php setup | check | cleanup
 */
if (!defined('ABSPATH') || !class_exists('TIX_Fees') || !method_exists('TIX_Fees', 'ticket_total')) { echo "Tixomat ab 1.38.750 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) { echo "Nur Einzel-Seite.\n"; return; }
$mode = isset($args[0]) ? (string) $args[0] : 'check';
$T = 'tix_fd_test';
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function fd_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function fd_rest($method, $route, $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body) { $r->set_header('Content-Type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    return [$res->get_status(), $res->get_data()];
}

if ($mode === 'cleanup') {
    $t = get_option($T);
    if (is_array($t)) {
        if ($t['settings'] === null) delete_option('tix_settings'); else update_option('tix_settings', $t['settings']);
        foreach ((array) $t['posts'] as $id) wp_delete_post($id, true);
    }
    delete_option($T);
    echo "cleanup ok\n"; return;
}
if ($mode === 'setup') {
    if (get_option($T)) { echo "Erst cleanup.\n"; return; }
    $bk = get_option('tix_settings', null);
    $s = is_array($bk) ? $bk : [];
    foreach (['fee_fixed' => 1.0, 'fee_percent' => 0, 'fee_mode' => 'customer', 'fee_label' => 'Vorverkaufsgebühr', 'fee_rounding' => 'none', 'fee_rounding_custom' => 0, 'fee_max_per_ticket' => 0,
        'fee_max_per_order' => 0, 'fee_show_in_selector' => 0, 'gateway_fee_fixed' => 0, 'gateway_fee_percent' => 0, 'gateway_fee_mode' => 'organizer', 'gateway_fee_included' => 0, 'tax_enabled' => 0] as $k => $v) $s[$k] = $v;
    update_option('tix_settings', $s);
    $mk = function ($title, $cats) {
        return wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => $title, 'meta_input' => ['_tix_date_start' => '2027-10-01', '_tix_time_start' => '20:00',
            '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1', '_tix_ticket_categories' => $cats]]);
    };
    $e1 = $mk('FD-Test A', [['name' => 'VVK', 'price' => 20, 'qty' => 100, 'stock' => 100], ['name' => 'VIP', 'price' => 30, 'qty' => 10, 'stock' => 10]]);
    $e2 = $mk('FD-Test B', [['name' => 'Frei', 'price' => 0, 'qty' => 100, 'stock' => 100], ['name' => 'Gast', 'price' => 15, 'qty' => 50, 'stock' => 50, 'no_fee' => 1], ['name' => 'VVK', 'price' => 25, 'qty' => 50, 'stock' => 50]]);
    update_option($T, ['settings' => $bk, 'posts' => [$e1, $e2], 'e1' => $e1, 'e2' => $e2], false);
    echo "setup ok (Gebühr 1,00 € je Ticket, Käufer zahlen)\n"; return;
}

$t = get_option($T);
if (!is_array($t)) { echo "Erst setup.\n"; return; }
$e1 = intval($t['e1']); $e2 = intval($t['e2']);

echo "-- API /public/events\n";
[$st, $d] = fd_rest('GET', "/public/events/$e1");
$ev = $d['event'] ?? $d;
$c = array_column((array) ($ev['categories'] ?? []), null, 'name');
fd_eq('A: VVK 20 € → fee 1, total 21; VIP 30 → 31', [200, 1.0, 21.0, 31.0], [$st, $c['VVK']['fee'] ?? null, $c['VVK']['total'] ?? null, $c['VIP']['total'] ?? null]);
fd_eq('A: price_from 20, price_from_total 21, fee_label', [20.0, 21.0, 'Vorverkaufsgebühr'], [$ev['price_from'] ?? null, $ev['price_from_total'] ?? null, $ev['fee_label'] ?? null]);
[$st, $d] = fd_rest('GET', "/public/events/$e2");
$ev = $d['event'] ?? $d;
$c = array_column((array) ($ev['categories'] ?? []), null, 'name');
fd_eq('B: Freiticket ohne Gebühr (0/0), gebührenfreie Kategorie ohne (15/15), VVK 25 → 26', [0.0, 0.0, 0.0, 15.0, 1.0, 26.0],
    [$c['Frei']['fee'] ?? null, $c['Frei']['total'] ?? null, $c['Gast']['fee'] ?? null, $c['Gast']['total'] ?? null, $c['VVK']['fee'] ?? null, $c['VVK']['total'] ?? null]);
fd_eq('B: price_from_total 0 (Freiticket)', 0.0, $ev['price_from_total'] ?? null);

echo "-- App-Quote\n";
[$st, $d] = fd_rest('POST', '/customer/cart/quote', ['event_id' => $e2, 'items' => [['index' => 0, 'qty' => 2], ['index' => 1, 'qty' => 1], ['index' => 2, 'qty' => 1]]]);
$tt = $d['totals'] ?? [];
$qc = array_column((array) ($d['categories'] ?? []), null, 'name');
fd_eq('Quote: 2 × frei + Gast + VVK → Gebühr nur VVK 1 €, Summe 41 €; Kategorien mit total', [200, 1.0, 41.0, 26.0], [$st, floatval($tt['fee'] ?? -1), floatval($tt['total'] ?? -1), $qc['VVK']['total'] ?? null]);

echo "-- Kasse: Freitickets\n";
$f = TIX_Fees::calc_order_fees([['price' => 0, 'qty' => 3, 'event_id' => $e2, 'cat_index' => 0]]);
fd_eq('nur Freitickets: keine Gebühr, Summe 0', [0.0, 0.0], [floatval($f['customer_fee_line']), floatval($f['customer_total'])]);

echo "-- Website-Ticketauswahl\n";
$html = TIX_Ticket_Selector::render(['id' => $e1]);
fd_eq('Preis inkl. Gebühr 21,00 € und Hinweis', [true, true, true], [strpos($html, '21,00&nbsp;€') !== false, strpos($html, 'inkl. 1,00&nbsp;€ Vorverkaufsgebühr') !== false, strpos($html, 'data-fee="1"') !== false]);
fd_eq('Summe: data-fee-label am Auswahl-Element, Gebühren-Zeile vorhanden', [true, true], [strpos($html, 'data-fee-label="Vorverkaufsgebühr"') !== false, strpos($html, 'tix-sel-fee-line') !== false]);
fd_eq('data-price bleibt der Ticketpreis (keine doppelte Gebühr)', true, strpos($html, 'data-price="20"') !== false);
$html = TIX_Ticket_Selector::render(['id' => $e2]);
fd_eq('B: Freiticket ohne Hinweis (data-fee 0), Gast 15,00 €', [true, true], [strpos($html, 'data-fee="0"') !== false, strpos($html, '15,00&nbsp;€') !== false]);

echo "-- Varianten (Konfiguration direkt)\n";
$base = TIX_Fees::get_fee_config();
$cfg = array_merge($base, ['fee_mode' => 'split', 'fee_split_share' => 50]);
$tt = TIX_Fees::ticket_total($e1, 0, 20, 1, $cfg);
fd_eq('geteilt 50 %: fee 0,50, total 20,50, ticket_fee 0,50', [0.5, 20.5, 0.5], [$tt['fee'], $tt['total'], TIX_Fees::ticket_fee($e1, 0, 20, $cfg)]);
$cfg = array_merge($base, ['fee_rounding' => '0.90']);
fd_eq('Rundung ,90: total 21,90 (Gebühr 1,90); ticket_fee ohne Rundung 1,00', [21.9, 1.9, 1.0], [TIX_Fees::ticket_total($e1, 0, 20, 1, $cfg)['total'], TIX_Fees::ticket_total($e1, 0, 20, 1, $cfg)['fee'], TIX_Fees::ticket_fee($e1, 0, 20, $cfg)]);
$cfg = array_merge($base, ['fee_mode' => 'organizer']);
fd_eq('Veranstalter trägt: fee 0, total = Preis', [0.0, 20.0, 0.0], [TIX_Fees::ticket_total($e1, 0, 20, 1, $cfg)['fee'], TIX_Fees::ticket_total($e1, 0, 20, 1, $cfg)['total'], TIX_Fees::ticket_fee($e1, 0, 20, $cfg)]);
$cfg = array_merge($base, ['gateway_fee_mode' => 'customer', 'gateway_fee_fixed' => 0.25]);
fd_eq('Zahlungskosten zu Lasten des Käufers im total (21,25)', 21.25, TIX_Fees::ticket_total($e1, 0, 20, 1, $cfg)['total']);
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
