<?php
/**
 * Servicegebühr – Folgepakete (1.38.752): Bezeichnung (30 Zeichen, Sperrliste), Erstattung einzelner Tickets mit anteiliger
 * Gebühr, Spiegel mit Gebühr der Quelle, Verteilung `source_fee`, USt-Hinweis Kanal-Gebühr im Vertrag.
 *   Einzel-Seite: wp eval-file …/test-fee-followups-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Fees') || !method_exists('TIX_Fees', 'refund_quote')) { echo "Tixomat ab 1.38.752 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) { echo "Nur Einzel-Seite.\n"; return; }
global $wpdb;
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function ff_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function ff_rest($method, $route, $body = [], $query = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($query) $r->set_query_params($query);
    if ($body) { $r->set_header('Content-Type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = $res->get_data();
    return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? null) : null];
}
$tmp_p = []; $orders = [];
$admin = intval((get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']))[0] ?? 0);
try {
    echo "-- Bezeichnung\n";
    ff_eq('erlaubt: Servicegebühr, Vorverkaufsgebühr, Systemgebühr', [null, null, null], [TIX_Fees::label_error('Servicegebühr'), TIX_Fees::label_error('Vorverkaufsgebühr'), TIX_Fees::label_error('Systemgebühr')]);
    foreach (['Zahlungsgebühr', 'Kartengebühr', 'Transaktionsgebühr', 'Bankgebühr', 'Versandkosten', 'Ticketsteuer', 'Kulturabgabe', 'PayPal-Gebühr', 'Gebühr inkl. USt'] as $bad) {
        ff_eq("gesperrt: $bad", true, is_string(TIX_Fees::label_error($bad)));
    }
    ff_eq('31 Zeichen zu lang, 30 ok, leer nicht', [true, null, true], [is_string(TIX_Fees::label_error(str_repeat('a', 31))), TIX_Fees::label_error(str_repeat('a', 30)), is_string(TIX_Fees::label_error(' '))]);
    ff_eq('„Austausch“ ist kein „ust“-Treffer', null, TIX_Fees::label_error('Austauschgebühr'));
    ff_eq('safe_label: unzulässig → Servicegebühr', ['Servicegebühr', 'Vorverkaufsgebühr'], [TIX_Fees::safe_label('Kartengebühr'), TIX_Fees::safe_label('Vorverkaufsgebühr')]);
    wp_set_current_user($admin);
    [$st, $d, $c] = ff_rest('POST', '/studio/fees', ['label' => 'Zahlungsgebühr']);
    ff_eq('/studio/fees: gesperrte Bezeichnung 400', [400, 'tix_invalid_field', 'label'], [$st, $c, $d['data']['field'] ?? null]);
    [$st, , $c] = ff_rest('POST', '/studio/fees/preview', ['label' => str_repeat('x', 31)]);
    ff_eq('/studio/fees/preview: 31 Zeichen 400', [400, 'tix_invalid_field'], [$st, $c]);
    [$st, $d] = ff_rest('GET', '/studio/fees');
    ff_eq('/studio/fees: label_rules (max 30, Vorschläge)', [30, ['Servicegebühr', 'Vorverkaufsgebühr', 'Systemgebühr']], [$d['label_rules']['max'] ?? null, $d['label_rules']['suggestions'] ?? null]);

    echo "-- Erstattung einzelner Tickets\n";
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'FF-Test Erstattung', 'meta_input' => ['_tix_date_start' => '2027-11-01',
        '_tix_ticket_categories' => [['name' => 'VVK', 'price' => 20, 'qty' => 100, 'stock' => 90], ['name' => 'VIP', 'price' => 50, 'qty' => 10, 'stock' => 9]]]]);
    $tmp_p[] = $ev;
    // Bestellung: 2 × VVK (je 1,00 € Gebühr) + 1 × VIP (je 2,00 €) = 90 + 4 = 94 €
    $wpdb->insert($wpdb->prefix . 'tix_orders', ['order_number' => 'FF-' . wp_rand(1000, 99999), 'status' => 'completed', 'total' => 94, 'subtotal' => 94, 'tax' => 0, 'discount' => 0, 'payment_method' => 'bank_transfer',
        'payment_method_title' => 'Überweisung', 'billing_email' => 'ff@example.test', 'customer_id' => 0, 'wc_order_id' => 0, 'order_key' => 'k' . wp_rand(1000, 9999), 'checked_in' => 0,
        'date_created' => current_time('mysql'), 'date_modified' => current_time('mysql')]);
    $o = intval($wpdb->insert_id); $orders[] = $o;
    $wpdb->insert($wpdb->prefix . 'tix_order_items', ['order_id' => $o, 'product_id' => 0, 'event_id' => $ev, 'quantity' => 2, 'total' => 40, 'tax' => 0, 'name' => 'VVK', 'cat_name' => 'VVK', 'meta' => '']);
    $wpdb->insert($wpdb->prefix . 'tix_order_items', ['order_id' => $o, 'product_id' => 0, 'event_id' => $ev, 'quantity' => 1, 'total' => 50, 'tax' => 0, 'name' => 'VIP', 'cat_name' => 'VIP', 'meta' => '']);
    update_option('_tix_order_fees_' . $o, ['customer_fee_line' => 4.0, 'platform_fee' => 4.0, 'fee_label' => 'Vorverkaufsgebühr',
        'lines' => [['event_id' => $ev, 'cat_index' => 0, 'price' => 20, 'qty' => 2, 'fee' => 2.0], ['event_id' => $ev, 'cat_index' => 1, 'price' => 50, 'qty' => 1, 'fee' => 2.0]]], false);
    $mk_t = function ($ci, $price, $oid = 0) use ($o, $ev, &$tmp_p) {
        $oid = $oid ?: $o;
        $id = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'FF-Ticket', 'meta_input' => ['_tix_ticket_order_id' => $oid, '_tix_ticket_event_id' => $ev,
            '_tix_ticket_cat_index' => $ci, '_tix_ticket_price' => $price, '_tix_ticket_status' => 'valid', '_tix_ticket_code' => 'FF' . wp_rand(100000, 999999)]]);
        $tmp_p[] = $id; return $id;
    };
    $t1 = $mk_t(0, 20); $t2 = $mk_t(0, 20); $t3 = $mk_t(1, 50);
    $q = TIX_Fees::refund_quote($o, [$t1]);
    ff_eq('1 × VVK: 20 € + 1,00 € Gebühr = 21 €, nicht alle', [20.0, 1.0, 21.0, false, 'Vorverkaufsgebühr'], [$q['tickets_amount'], $q['fee'], $q['amount'], $q['all'], $q['fee_label']]);
    $q = TIX_Fees::refund_quote($o, [$t3]);
    ff_eq('1 × VIP: 50 € + 2,00 €', [52.0], [$q['amount']]);
    ff_eq('fremdes Ticket → 400', 'tix_invalid_field', is_wp_error($e = TIX_Fees::refund_quote($o, [999999999])) ? $e->get_error_code() : null);
    [$st, $d] = ff_rest('GET', "/orders/$o/refund-quote", [], ['tickets' => (string) $t1]);
    ff_eq('GET refund-quote: 21 €', [200, 21.0], [$st, $d['quote']['amount'] ?? null]);
    [$st, $d] = ff_rest('POST', "/orders/$o/refund", ['tickets' => [$t1], 'reason' => 'Test']);
    ff_eq('POST refund tickets: 21 € erstattet, Teil, nur Ticket 1 storniert, Bestand VVK +1', [200, 21.0, false, 'cancelled', 'valid', 91],
        [$st, $d['refunded'] ?? null, $d['full'] ?? null, get_post_meta($t1, '_tix_ticket_status', true), get_post_meta($t2, '_tix_ticket_status', true),
         intval((get_post_meta($ev, '_tix_ticket_categories', true)[0]['stock']) ?? 0)]);
    [$st, , $c] = ff_rest('POST', "/orders/$o/refund", ['tickets' => [$t1]]);
    ff_eq('schon storniertes Ticket nochmal → 400', [400, 'tix_invalid_field'], [$st, $c]);
    $q = TIX_Fees::refund_quote($o, [$t2, $t3]);
    ff_eq('Rest (alle übrigen): all, Gebühr 1 + 2', [true, 3.0], [$q['all'], $q['fee']]);
    [$st, $d] = ff_rest('POST', "/orders/$o/refund", ['tickets' => [$t2, $t3]]);
    ff_eq('Rest erstatten: voller offener Betrag 73 €, Bestellung erstattet', [200, 73.0, true, 'refunded'],
        [$st, $d['refunded'] ?? null, $d['full'] ?? null, (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tix_orders WHERE id = %d", $o))]);
    // Ältere Bestellung ohne `lines`, mit Rabatt: Gebühr gleichmäßig (feste Gebühr), Preise auf gezahlten Warenwert skaliert
    $wpdb->insert($wpdb->prefix . 'tix_orders', ['order_number' => 'FF-' . wp_rand(1000, 99999), 'status' => 'completed', 'total' => 38, 'subtotal' => 38, 'tax' => 0, 'discount' => 4, 'payment_method' => 'bank_transfer',
        'payment_method_title' => 'Überweisung', 'billing_email' => 'ff@example.test', 'customer_id' => 0, 'wc_order_id' => 0, 'order_key' => 'k' . wp_rand(1000, 9999), 'checked_in' => 0,
        'date_created' => current_time('mysql'), 'date_modified' => current_time('mysql')]);
    $o2 = intval($wpdb->insert_id); $orders[] = $o2;
    update_option('_tix_order_fees_' . $o2, ['customer_fee_line' => 2.0, 'fee_label' => 'Servicegebühr'], false);
    $u1 = $mk_t(0, 20, $o2); $u2 = $mk_t(0, 20, $o2);
    $q = TIX_Fees::refund_quote($o2, [$u1]);
    ff_eq('alt ohne lines + Rabatt: 18 € (36/40) + 1 € Gebühr', [18.0, 1.0, 19.0], [$q['tickets_amount'], $q['fee'], $q['amount']]);

    echo "-- Spiegel mit Gebühr der Quelle\n";
    $m = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'FF-Spiegel', 'meta_input' => ['_tix_date_start' => '2027-12-01', '_tix_time_start' => '20:00',
        '_tix_syndicated' => '1', '_tix_source_site' => 'Quelle', '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1',
        '_tix_source_fee' => ['fixed' => 2.0, 'percent' => 0, 'mode' => 'customer', 'label' => 'Systemgebühr', 'rounding' => 'none', 'gateway' => ['mode' => 'organizer'], 'channel_mode' => 'customer'],
        '_tix_ticket_categories' => [['name' => 'VVK', 'price' => 30, 'qty' => 100, 'stock' => 100]]]]);
    $tmp_p[] = $m;
    $cfg = TIX_Fees::display_config($m);
    ff_eq('display_config: Gebühr der Quelle (2 €, Käufer, Systemgebühr)', [2.0, 'customer', 'Systemgebühr'], [$cfg['fee_fixed'], $cfg['fee_mode'], $cfg['fee_label']]);
    $pe = TIX_Public_Events::payload($m, true);
    ff_eq('Spiegel öffentlich: price_total 32, price_from_total 32, service_fee.label', [32.0, 32.0, 'Systemgebühr'],
        [$pe['categories'][0]['price_total'] ?? null, $pe['price_from_total'] ?? null, $pe['service_fee']['label'] ?? null]);
    TIX_Sync::apply_fee_display($m, true);
    ff_eq('Spiegel Preis-Felder: _tix_price_min_total 32, Range mit Hinweis', [32.0, true], [floatval(get_post_meta($m, '_tix_price_min_total', true)), strpos((string) get_post_meta($m, '_tix_price_range', true), 'inkl. Systemgebühr') !== false]);
    delete_post_meta($m, '_tix_source_fee');
    ff_eq('Spiegel ohne source_fee (alte Quelle): keine Gebühr', [0.0, 30.0], [TIX_Fees::display_config($m)['fee_fixed'], TIX_Fees::ticket_total($m, 0, 30)['total']]);
    $sp = TIX_Fees::source_fee_payload($ev);
    ff_eq('Verteilung: source_fee mit Satz, Bezeichnung, Zahlungskosten, Kanal-Träger', true, isset($sp['fixed'], $sp['percent'], $sp['mode'], $sp['label'], $sp['gateway'], $sp['channel_mode']));

    echo "-- Vertrag Kanal-Gebühr\n";
    $tpl = (string) file_get_contents(TIXOMAT_PATH . 'includes/contract/vermittlungsvertrag.md');
    ff_eq('Kanal-Gebühr mit USt-Hinweis, kein „Tixomat“ im Vertrag', [true, false], [strpos($tpl, '{{kanal_gebuehr_fix}} € je Ticket ({{gebuehr_mwst_hinweis}}) an') !== false, stripos($tpl, 'tixomat') !== false]);
} catch (Throwable $e) {
    $GLOBALS['t_fails']++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($orders as $o) { $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $o]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $o]); delete_option('_tix_order_fees_' . $o);
        delete_option('_tix_refund_' . $o); delete_option('_tix_refund_total_' . $o); }
    foreach ($tmp_p as $id) wp_delete_post($id, true);
    wp_clear_scheduled_hook('tix_refresh_price_meta');
    wp_set_current_user(0);
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
