<?php
/**
 * Servicegebühr im Studio (1.38.748): /studio/fees, /studio/fees/preview, Website- und App-Kasse.
 * tix_get_settings hält die Einstellungen je Aufruf fest – die Kassen deshalb in einem zweiten Aufruf prüfen:
 *   Einzel-Seite: wp eval-file …/test-studio-fees-wp.php run      (Routen, Rechte, Prüfung, Vorschau, speichert Testwerte)
 *                 wp eval-file …/test-studio-fees-wp.php kasse    (Website-Kasse + App-Quote mit den gespeicherten Werten)
 *                 wp eval-file …/test-studio-fees-wp.php cleanup  (Einstellungen zurück, Testdaten weg)
 *   Plattform:    wp eval-file …/test-studio-fees-wp.php          (gesperrt: 403 tix_fees_locked)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Studio_Fees')) { echo "Tixomat ab 1.38.748 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$mode = isset($args[0]) ? (string) $args[0] : 'run';
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function sf_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function sf_rest($method, $route, $body = [], $query = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($query) $r->set_query_params($query);
    if ($body) { $r->set_header('Content-Type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = $res->get_data();
    return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? null) : null, is_array($d) ? ($d['data']['field'] ?? null) : null];
}
function sf_done() { echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n"; }

if (TIX_Studio_Fees::locked()) {
    echo "== Plattform (Mehr-Veranstalter-Modus)\n";
    wp_set_current_user(intval((get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']))[0] ?? 0));
    [$st, $d] = sf_rest('GET', '/me');
    sf_eq('/me: service_fee nicht verfügbar', [200, ['available' => false, 'can_edit' => false]], [$st, $d['service_fee'] ?? null]);
    foreach ([['GET', '/studio/fees'], ['POST', '/studio/fees'], ['POST', '/studio/fees/preview']] as [$m, $r]) {
        [$st, , $c] = sf_rest($m, $r, $m === 'POST' ? ['fixed' => 1] : []);
        sf_eq("$m $r gesperrt", [403, 'tix_fees_locked'], [$st, $c]);
    }
    wp_set_current_user(0);
    sf_done(); return;
}

$T = 'tix_sf_test';
if ($mode === 'cleanup') {
    $t = get_option($T);
    if (is_array($t)) {
        if ($t['settings'] === null) delete_option('tix_settings'); else update_option('tix_settings', $t['settings']);
        foreach ((array) $t['posts'] as $id) wp_delete_post($id, true);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ((array) $t['users'] as $id) { delete_user_meta($id, '_tix_cart'); wp_delete_user($id); }
    }
    delete_option($T);
    echo "cleanup ok\n"; return;
}

if ($mode === 'run') {
    if (get_option($T)) { echo "Erst cleanup ausführen.\n"; return; }
    $backup = get_option('tix_settings', null);
    $mk = function ($role) { return wp_insert_user(['user_login' => 'sf_' . $role . '_' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(20), 'user_email' => 'sf_' . wp_rand(1000, 999999) . '@example.test', 'role' => $role]); };
    $uo = $mk('tix_organizer'); $uc = $mk('tix_cashier'); $ub = $mk('subscriber');
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SF-Test Gebühr', 'meta_input' => ['_tix_date_start' => '2027-09-01', '_tix_time_start' => '20:00', '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1',
        '_tix_ticket_categories' => [['name' => 'VVK', 'price' => 20, 'qty' => 100, 'stock' => 100], ['name' => 'Gast', 'price' => 10, 'qty' => 50, 'stock' => 50, 'no_fee' => 1]]]]);
    update_option($T, ['settings' => $backup, 'posts' => [$ev], 'users' => [$uo, $uc, $ub], 'event' => $ev], false);
    // Grundzustand: keine Gebühr, keine Zahlungskosten
    $s = is_array($backup) ? $backup : [];
    foreach (['fee_fixed' => 0, 'fee_percent' => 0, 'fee_mode' => 'organizer', 'fee_label' => 'Servicegebühr', 'fee_rounding' => 'none', 'fee_rounding_custom' => 0, 'fee_max_per_ticket' => 0,
        'fee_max_per_order' => 0, 'fee_split_customer_share' => 50, 'fee_show_in_selector' => 0, 'gateway_fee_fixed' => 0, 'gateway_fee_percent' => 0, 'gateway_fee_mode' => 'organizer', 'gateway_fee_included' => 0,
        'tax_enabled' => 0] as $k => $v) $s[$k] = $v;
    update_option('tix_settings', $s);

    echo "-- Rechte\n";
    wp_set_current_user(0);
    sf_eq('ohne Anmeldung 401', 401, sf_rest('GET', '/studio/fees')[0]);
    wp_set_current_user($uc);
    sf_eq('/me Kasse: verfügbar, nicht bearbeitbar', ['available' => true, 'can_edit' => false], sf_rest('GET', '/me')[1]['service_fee'] ?? null);
    foreach ([[$uc, 'Kasse'], [$ub, 'Kunde']] as [$u, $n]) { wp_set_current_user($u); sf_eq("$n: 403", 403, sf_rest('GET', '/studio/fees')[0]); sf_eq("$n: speichern 403", 403, sf_rest('POST', '/studio/fees', ['fixed' => 1])[0]); }
    wp_set_current_user($uo);
    [$st, $d] = sf_rest('GET', '/studio/fees');
    sf_eq('Inhaber: 200, Grundzustand ohne Gebühr', [200, false, 'organizer', 0.0, 'Servicegebühr', 'Ticket 20,00 € → Käufer zahlt 20,00 € (keine Servicegebühr)'],
        [$st, $d['fees']['active'] ?? null, $d['fees']['mode'] ?? '', $d['fees']['fixed'] ?? null, $d['fees']['label'] ?? '', $d['preview']['text'] ?? '']);
    sf_eq('/me Inhaber: service_fee verfügbar + bearbeitbar', ['available' => true, 'can_edit' => true], sf_rest('GET', '/me')[1]['service_fee'] ?? null);
    sf_eq('GET: available true', true, $d['available'] ?? null);
    sf_eq('Antwort: Modi, Rundungen, Zahlungskosten, overrides', [['customer', 'split', 'organizer'], 6, 'organizer', 0], [array_column($d['modes'] ?? [], 'key'), count($d['roundings'] ?? []), $d['payment']['mode'] ?? '', $d['overrides'] ?? -1]);

    echo "-- Prüfung\n";
    foreach ([['mode', 'gast'], ['fixed', -1], ['fixed', 51], ['fixed', 'abc'], ['percent', 60], ['label', ''], ['label', str_repeat('x', 41)], ['max_per_ticket', 501], ['max_per_order', 5001],
        ['split_customer_share', 101], ['rounding', '0.95'], ['rounding_custom', 1], ['show_in_selector', 'vielleicht']] as [$f, $v]) {
        [$st, , $c, $fld] = sf_rest('POST', '/studio/fees', [$f => $v]);
        sf_eq("400 $f = " . var_export($v, true), [400, 'tix_invalid_field', $f], [$st, $c, $fld]);
    }
    sf_eq('nach Fehlern nichts gespeichert', 0.0, floatval(get_option('tix_settings')['fee_fixed'] ?? 0));

    echo "-- Vorschau (speichert nichts)\n";
    [$st, $d] = sf_rest('POST', '/studio/fees/preview', ['mode' => 'customer', 'fixed' => '0,99', 'percent' => 2, 'price' => 20, 'qty' => 2]);
    sf_eq('2 × 20 €, 0,99 € + 2 % → 2,78 € Gebühr, Käufer 42,78 €', [200, 2.78, 42.78, '2 Tickets à 20,00 € → Käufer zahlt 42,78 € (inkl. 2,78 € Servicegebühr)'],
        [$st, $d['preview']['service_fee'] ?? null, $d['preview']['buyer_pays'] ?? null, $d['preview']['text'] ?? '']);
    sf_eq('Vorschau speichert nichts', ['organizer', 0.0], [get_option('tix_settings')['fee_mode'] ?? '', floatval(get_option('tix_settings')['fee_fixed'] ?? 0)]);
    [, $d] = sf_rest('POST', '/studio/fees/preview', ['mode' => 'split', 'fixed' => 1, 'split_customer_share' => 50, 'price' => 20]);
    sf_eq('geteilt 50 %: Käufer 0,50 €', [0.5, 20.5], [$d['preview']['service_fee'] ?? null, $d['preview']['buyer_pays'] ?? null]);
    [, $d] = sf_rest('POST', '/studio/fees/preview', ['mode' => 'customer', 'fixed' => 1, 'max_per_ticket' => 0.5, 'price' => 20, 'qty' => 3]);
    sf_eq('Höchstbetrag je Ticket 0,50 €: 3 × 0,50 €', 1.5, $d['preview']['service_fee'] ?? null);
    [, $d] = sf_rest('POST', '/studio/fees/preview', ['mode' => 'customer', 'fixed' => 1, 'max_per_order' => 2, 'price' => 20, 'qty' => 5]);
    sf_eq('Höchstbetrag je Bestellung 2 €', 2.0, $d['preview']['service_fee'] ?? null);
    [, $d] = sf_rest('POST', '/studio/fees/preview', ['mode' => 'customer', 'fixed' => 0.5, 'rounding' => '0.90', 'price' => 20]);
    sf_eq('Rundung auf ,90: 20,50 → 20,90 (Gebühr 0,90, Überschuss 0,40)', [20.9, 0.9, 0.4], [$d['preview']['buyer_pays'] ?? null, $d['preview']['service_fee'] ?? null, $d['preview']['rounding_surplus'] ?? null]);
    [, $d] = sf_rest('POST', '/studio/fees/preview', ['mode' => 'organizer', 'fixed' => 1, 'price' => 20]);
    sf_eq('Modus organizer: Käufer zahlt nur den Ticketpreis', [0.0, 20.0], [$d['preview']['service_fee'] ?? null, $d['preview']['buyer_pays'] ?? null]);

    echo "-- Speichern\n";
    [$st, $d] = sf_rest('POST', '/studio/fees', ['mode' => 'customer', 'fixed' => 0.99, 'percent' => '2,5', 'label' => 'Vorverkaufsgebühr', 'show_in_selector' => true, 'price' => 30]);
    $o = get_option('tix_settings');
    sf_eq('gespeichert in tix_settings', ['customer', 0.99, 2.5, 'Vorverkaufsgebühr', 1], [$o['fee_mode'], $o['fee_fixed'], $o['fee_percent'], $o['fee_label'], $o['fee_show_in_selector']]);
    sf_eq('Antwort: aktiv, Vorschau 30 € → 31,74 €', [200, true, 1.74, 31.74, 'Ticket 30,00 € → Käufer zahlt 31,74 € (inkl. 1,74 € Vorverkaufsgebühr)'],
        [$st, $d['fees']['active'] ?? null, $d['preview']['service_fee'] ?? null, $d['preview']['buyer_pays'] ?? null, $d['preview']['text'] ?? '']);
    [$st, $d] = sf_rest('POST', '/studio/fees', ['percent' => 0]);
    sf_eq('Teil-Update: nur percent geändert', ['customer', 0.99, 0.0, 'Vorverkaufsgebühr'], [$d['fees']['mode'] ?? '', $d['fees']['fixed'] ?? null, $d['fees']['percent'] ?? null, $d['fees']['label'] ?? '']);
    sf_rest('POST', '/studio/fees', ['percent' => 2.5]);
    if (class_exists('TIX_Customers')) {
        global $wpdb;
        $row = $wpdb->get_row("SELECT * FROM " . TIX_Customers::audit_table() . " WHERE action = 'fees_update' ORDER BY id DESC LIMIT 1", ARRAY_A);
        $pc = json_decode((string) ($row['summary'] ?? ''), true)['percent'] ?? [null, null];
        sf_eq('Protokoll fees_update mit [alt, neu]', [true, 0.0, 2.5], [intval($row['actor_id'] ?? 0) === $uo, floatval($pc[0]), floatval($pc[1])]);
    }
    // Zahlungskosten zu Lasten der Käufer: Vorschau rechnet sie mit (wie die Kassen)
    $s = get_option('tix_settings'); $s['gateway_fee_mode'] = 'customer'; $s['gateway_fee_fixed'] = 0.25; update_option('tix_settings', $s);
    [, $d] = sf_rest('GET', '/studio/fees', [], ['price' => 20]);
    sf_eq('Zahlungskosten Käufer 0,25 €: Vorschau 20 € → 21,74 €', [21.74, 'customer'], [$d['preview']['buyer_pays'] ?? null, $d['payment']['mode'] ?? '']);
    $s['gateway_fee_mode'] = 'organizer'; $s['gateway_fee_fixed'] = 0; update_option('tix_settings', $s);
    wp_set_current_user(0);
    echo "Weiter mit: kasse\n";
    sf_done(); return;
}

if ($mode === 'kasse') {
    $t = get_option($T);
    if (!is_array($t)) { echo "Erst run ausführen.\n"; return; }
    $ev = intval($t['event']); $uo = intval($t['users'][0]);
    echo "-- Website-Kasse (gespeichert: Käufer zahlen 0,99 € + 2,5 %)\n";
    wp_set_current_user($uo);
    TIX_Native_Checkout::save_cart(['items' => [['event_id' => $ev, 'cat_index' => 0, 'name' => 'VVK', 'price' => 20.0, 'qty' => 2], ['event_id' => $ev, 'cat_index' => 1, 'name' => 'Gast', 'price' => 10.0, 'qty' => 1]], 'coupon' => null]);
    $f = TIX_Native_Checkout::cart_fees();
    // 2 × (0,99 + 0,50) = 2,98; Gast-Kategorie gebührenfrei
    sf_eq('Gebühr 2 × 1,49 € = 2,98 €, gebührenfreie Kategorie ohne', [2.98, 'customer', 'Vorverkaufsgebühr'], [$f['customer_fee_line'], $f['platform_fee_mode'], $f['fee_label']]);
    sf_eq('Warenkorb-Summe 50 € + 2,98 € = 52,98 €', 52.98, TIX_Native_Checkout::cart_total());
    TIX_Native_Checkout::clear_cart();
    echo "-- App-Kasse (POST /customer/cart/quote)\n";
    [$st, $d] = sf_rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 2], ['index' => 1, 'qty' => 1]]]);
    $tt = $d['totals'] ?? [];
    sf_eq('Quote: Zwischensumme 50 €, Gebühr 2,98 €, Bezeichnung, Summe 52,98 €', [200, 50.0, 2.98, 'Vorverkaufsgebühr', 52.98], [$st, floatval($tt['subtotal'] ?? 0), floatval($tt['fee'] ?? 0), $tt['fee_label'] ?? '', floatval($tt['total'] ?? 0)]);
    wp_set_current_user(0);
    [$st, $d] = sf_rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 1]]]);
    sf_eq('Quote als Gast: 20 € → 21,49 €', [200, 1.49, 21.49], [$st, floatval($d['totals']['fee'] ?? 0), floatval($d['totals']['total'] ?? 0)]);
    sf_done(); return;
}
echo "Modus: run | kasse | cleanup\n";
