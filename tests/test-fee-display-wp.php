<?php
/**
 * Gesamtpreis inkl. Servicegebühr (PAngV, 1.38.751): API (categories[].fee/total, price_from_total, fee_label), App-Quote,
 * Website-Ticketauswahl, Freitickets ohne Gebühr. tix_get_settings hält Einstellungen je Aufruf fest → drei Aufrufe:
 *   Einzel-Seite: wp eval-file …/test-fee-display-wp.php setup | check | cleanup
 */
if (!defined('ABSPATH') || !class_exists('TIX_Fees') || !method_exists('TIX_Fees', 'ticket_total')) { echo "Tixomat ab 1.38.751 nötig\n"; return; }
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
    wp_clear_scheduled_hook('tix_refresh_price_meta');
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
    $e2 = $mk('FD-Test B', [['name' => 'Frei', 'price' => 0, 'qty' => 100, 'stock' => 100], ['name' => 'Gast', 'price' => 15, 'qty' => 50, 'stock' => 50, 'no_fee' => 1], ['name' => 'VVK', 'price' => 25, 'qty' => 50, 'stock' => 50], ['name' => 'Gutschein', 'price' => 50, 'qty' => 0, 'gift_card' => 1],
        ['name' => 'Early', 'price' => 25, 'qty' => 10, 'stock' => 10, 'phases' => [['name' => 'Early Bird', 'price' => 15, 'until' => '2099-12-31']]]]);
    update_option($T, ['settings' => $bk, 'posts' => [$e1, $e2], 'e1' => $e1, 'e2' => $e2, 'scheduled' => (bool) wp_next_scheduled('tix_refresh_price_meta')], false);
    echo "setup ok (Gebühr 1,00 € je Ticket, Käufer zahlen)\n"; return;
}

$t = get_option($T);
if (!is_array($t)) { echo "Erst setup.\n"; return; }
$e1 = intval($t['e1']); $e2 = intval($t['e2']);

echo "-- Neurechnen nach Gebühren-Änderung\n";
fd_eq('Einstellung geändert → Neurechnen eingeplant (Cron tix_refresh_price_meta)', true, !empty($t['scheduled']));
fd_eq('Neurechnen läuft (mind. 2 Events)', true, TIX_Sync::refresh_all_price_meta() >= 2);
fd_eq('Preis-Felder A: Endpreise mit Hinweis', [21.0, 31.0, 'Ab 21,00 €', true, true],
    [floatval(get_post_meta($e1, '_tix_price_min_total', true)), floatval(get_post_meta($e1, '_tix_price_max_total', true)), get_post_meta($e1, '_tix_price_card', true),
     strpos((string) get_post_meta($e1, '_tix_price_range', true), 'ab 21,00 €') === 0 && strpos((string) get_post_meta($e1, '_tix_price_range', true), 'inkl. Vorverkaufsgebühr') !== false,
     strpos((string) get_post_meta($e1, '_tix_ticket_1_price_formatted', true), 'inkl. 1,00 € Vorverkaufsgebühr') !== false]);
fd_eq('Preis-Felder A: _tix_price_min bleibt Ticketpreis 20', 20.0, floatval(get_post_meta($e1, '_tix_price_min', true)));
fd_eq('event_price_total A: 21 / 31', [21.0, 31.0], [TIX_Fees::event_price_total($e1), TIX_Fees::event_price_total($e1, 'max')]);

echo "-- API /public/events\n";
[$st, $d] = fd_rest('GET', "/public/events/$e1");
$ev = $d['event'] ?? $d;
$c = array_column((array) ($ev['categories'] ?? []), null, 'name');
fd_eq('A: VVK 20 € → fee_per_ticket 1, price_total 21; VIP 30 → 31', [200, 1.0, 21.0, 31.0], [$st, $c['VVK']['fee_per_ticket'] ?? null, $c['VVK']['price_total'] ?? null, $c['VIP']['price_total'] ?? null]);
fd_eq('A: ohne Preisphase base_total = price_total (21)', 21.0, $c['VVK']['base_total'] ?? null);
fd_eq('A: price_from 20, price_from_total 21, service_fee', [20.0, 21.0, ['label' => 'Vorverkaufsgebühr', 'per_ticket' => true, 'per_order' => 0.0, 'max_per_order' => 0.0]],
    [$ev['price_from'] ?? null, $ev['price_from_total'] ?? null, $ev['service_fee'] ?? null]);
[$st, $d] = fd_rest('GET', '/public/events');
$li = array_column((array) ($d['events'] ?? ($d['items'] ?? $d)), null, 'id')[$e1] ?? null;
fd_eq('Liste: price_from_total und service_fee auch dort', [21.0, true], [$li['price_from_total'] ?? null, $li['service_fee']['per_ticket'] ?? null]);
[$st, $d] = fd_rest('GET', "/public/events/$e2");
$ev = $d['event'] ?? $d;
$c = array_column((array) ($ev['categories'] ?? []), null, 'name');
fd_eq('B: Freiticket (0/0), gebührenfrei (0/15), VVK (1/26), Gutschein (0/50)', [0.0, 0.0, 0.0, 15.0, 1.0, 26.0, 0.0, 50.0],
    [$c['Frei']['fee_per_ticket'] ?? null, $c['Frei']['price_total'] ?? null, $c['Gast']['fee_per_ticket'] ?? null, $c['Gast']['price_total'] ?? null, $c['VVK']['fee_per_ticket'] ?? null, $c['VVK']['price_total'] ?? null,
     $c['Gutschein']['fee_per_ticket'] ?? null, $c['Gutschein']['price_total'] ?? null]);
fd_eq('B: price_from_total 0 (Freiticket)', 0.0, $ev['price_from_total'] ?? null);
fd_eq('B: Preisphase 15 statt 25: price_total 16, base_total 26 (durchgestrichener Normalpreis)', [15.0, 25.0, 16.0, 26.0],
    [$c['Early']['price'] ?? null, $c['Early']['base_price'] ?? null, $c['Early']['price_total'] ?? null, $c['Early']['base_total'] ?? null]);
fd_eq('B: Gutschein base_total ohne Gebühr (50)', 50.0, $c['Gutschein']['base_total'] ?? null);

echo "-- App-Quote\n";
[$st, $d] = fd_rest('POST', '/customer/cart/quote', ['event_id' => $e2, 'items' => [['index' => 0, 'qty' => 2], ['index' => 1, 'qty' => 1], ['index' => 2, 'qty' => 1]]]);
$tt = $d['totals'] ?? [];
$qc = array_column((array) ($d['categories'] ?? []), null, 'name');
fd_eq('Quote: 2 × frei + Gast + VVK → Gebühr nur VVK 1 €, Summe 41 €; Kategorien mit price_total', [200, 1.0, 41.0, 26.0], [$st, floatval($tt['fee'] ?? -1), floatval($tt['total'] ?? -1), $qc['VVK']['price_total'] ?? null]);
fd_eq('Quote: Gebühr je Zeile (frei 0, Gast 0, VVK 1)', [0.0, 0.0, 1.0], array_map(function ($l) { return floatval($l['fee'] ?? -1); }, (array) ($tt['lines'] ?? [])));
[$st, $d] = fd_rest('POST', '/customer/cart/quote', ['event_id' => $e2, 'items' => [['index' => 3, 'qty' => 1]]]);
fd_eq('Quote: Gutschein-Kauf ohne Gebühr', [200, 0.0, 50.0], [$st, floatval($d['totals']['fee'] ?? -1), floatval($d['totals']['total'] ?? -1)]);

echo "-- Kasse: Freitickets\n";
$f = TIX_Fees::calc_order_fees([['price' => 0, 'qty' => 3, 'event_id' => $e2, 'cat_index' => 0]]);
fd_eq('nur Freitickets: keine Gebühr, Summe 0', [0.0, 0.0], [floatval($f['customer_fee_line']), floatval($f['customer_total'])]);
$f = TIX_Fees::calc_order_fees([['price' => 30, 'qty' => 1, 'event_id' => $e2, 'cat_index' => -1, 'meta' => ['wallet_topup' => 1]]]);
fd_eq('Guthaben-Aufladung: keine Gebühr', 0.0, floatval($f['customer_fee_line']));
$f = TIX_Fees::calc_order_fees([['price' => 50, 'qty' => 1, 'event_id' => $e2, 'cat_index' => 3]]);
fd_eq('Gutschein-Kategorie in der Kasse: keine Gebühr', 0.0, floatval($f['customer_fee_line']));
fd_eq('CTA-Knopf [tix_cta_button]: ab 21,00 €', true, strpos(TIX_Single_Event::shortcode_cta_button(['id' => $e1]), 'ab 21,00') !== false);

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
