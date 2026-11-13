<?php
/**
 * B26 Rechnungen (1.38.802): Storno von Hand, Filter je Event, offener Betrag / storniert in der Liste.
 *   wp eval-file …/test-invoice-cancel-wp.php (Einzel-Seite; evendis-Aussteller brauchen Auszahlungsdaten)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Invoices') || !method_exists('TIX_Invoices', 'rest_cancel')) { echo "Tixomat ab 1.38.802 nötig\n"; return; }
if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) { echo "Nur Einzel-Seite\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$keep = ['tix_settings' => get_option('tix_settings'), 'tix_invoices_enabled' => get_option('tix_invoices_enabled', null)];
$posts = []; $orders = []; $invs = [];
add_filter('pre_wp_mail', '__return_true');
try {
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    wp_set_current_user($adm);
    $s = (array) get_option('tix_settings', []);
    $s['invoice_company_name'] = 'B26 Test GmbH'; $s['invoice_company_address'] = "Teststraße 1\n58332 Schwelm"; $s['invoice_company_tax_id'] = '123/456/78901';
    update_option('tix_settings', $s);
    update_option('tix_invoices_enabled', '1');
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'B26 Abend', 'meta_input' => ['_tix_date_start' => wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS), '_tix_ticket_categories' => [['name' => 'Normal', 'price' => 20, 'qty' => 50]]]]); $posts[] = $ev;
    $ev2 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'B26 Anderes', 'meta_input' => ['_tix_date_start' => wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS), '_tix_ticket_categories' => [['name' => 'Normal', 'price' => 15, 'qty' => 50]]]]); $posts[] = $ev2;
    $buy = function ($eid, $price) use (&$orders) {
        $oid = TIX_Native_Checkout::create_order(['billing_first_name' => 'Kim', 'billing_last_name' => 'Beleg', 'billing_email' => 'b26_' . wp_rand(1000, 9999) . '@example.test', 'billing_address_1' => 'Weg 2', 'billing_postcode' => '12345', 'billing_city' => 'Ort', 'billing_country' => 'DE',
            'payment_method' => 'bank_transfer', 'allow_oversell' => true, 'total' => $price, 'items' => [['event_id' => $eid, 'cat_index' => 0, 'name' => 'Normal', 'event_title' => get_the_title($eid), 'price' => $price, 'qty' => 1, 'meta' => []]]]);
        $orders[] = intval($oid);
        TIX_Native_Checkout::update_order_status($oid, 'completed', 'bank_transfer');
        TIX_Invoices::issue_for_order($oid);
        return intval($oid);
    };
    $o1 = $buy($ev, 20); $o2 = $buy($ev2, 15);
    $inv1 = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Invoices::table() . " WHERE order_id = %d AND kind = 'invoice' ORDER BY id ASC LIMIT 1", $o1));
    foreach ($wpdb->get_col("SELECT id FROM " . TIX_Invoices::table() . " WHERE order_id IN (" . intval($o1) . "," . intval($o2) . ")") as $i) $invs[] = intval($i);
    $eq('Rechnung zur Bestellung ausgestellt', true, (bool) $inv1);
    $id1 = intval($inv1->id ?? 0);
    [$st, $d] = $rest('GET', '/organizer/invoices', null, ['event_id' => $ev]);
    $row = array_values(array_filter((array) ($d['invoices'] ?? $d['items'] ?? []), function ($r) use ($id1) { return intval($r['id']) === $id1; }))[0] ?? null;
    $other = array_values(array_filter((array) ($d['invoices'] ?? $d['items'] ?? []), function ($r) use ($o2) { return intval($r['order_id']) === $o2; }));
    $eq('Liste je Event: nur dieses Event; offen 20 €, nicht storniert', [200, true, 0, 20.0, false], [$st, (bool) $row, count($other), floatval($row['open_amount'] ?? -1), $row['cancelled'] ?? null]);
    [$st, , $c] = $rest('POST', "/invoices/$id1/cancel", ['reason' => 'x']);
    $eq('Storno ohne Grund → 400', 400, $st);
    [$st, $d] = $rest('POST', "/invoices/$id1/cancel", ['reason' => 'Falsche Rechnungsadresse']);
    if (!empty($d['correction']['id'])) $invs[] = intval($d['correction']['id']);
    $eq('Storno: Korrektur über −20 €, Grund als Notiz, Rechnung storniert', [200, 'correction', -20.0, 'manual', 'Falsche Rechnungsadresse', true, 0.0], [$st, $d['correction']['kind'] ?? '', floatval($d['correction']['gross'] ?? 0), $d['correction']['reason'] ?? '', $d['correction']['note'] ?? '', $d['invoice']['cancelled'] ?? null, floatval($d['invoice']['open_amount'] ?? -1)]);
    [$st, , $c] = $rest('POST', "/invoices/$id1/cancel", ['reason' => 'Nochmal']);
    $eq('Zweiter Storno → 409', [409, 'tix_invoice_already_cancelled'], [$st, $c]);
    [$st, , $c] = $rest('POST', '/invoices/' . intval($d['correction']['id'] ?? 0) . '/cancel', ['reason' => 'Korrektur weg']);
    $eq('Korrektur stornieren → 409', [409, 'tix_invoice_is_correction'], [$st, $c]);
    [$st, $d] = $rest('GET', '/organizer/invoices', null, ['event_id' => $ev, 'format' => 'csv']);
    $eq('Export je Event (CSV): Rechnung + Korrektur', [200, 2], [$st, intval($d['rows'] ?? 0)]);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($invs as $i) $wpdb->delete(TIX_Invoices::table(), ['id' => $i]);
    foreach ($orders as $oid) {
        foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $oid]) as $t) wp_delete_post($t, true);
        foreach (['tix_order_items', 'tix_order_notes'] as $tb) $wpdb->delete($wpdb->prefix . $tb, ['order_id' => $oid]);
        $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $oid]);
    }
    foreach ($posts as $p) wp_delete_post($p, true);
    foreach ($keep as $k => $v) { if ($v === null) delete_option($k); else update_option($k, $v); }
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
