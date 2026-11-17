<?php
/**
 * Kasse vor Ort ohne Servicegebühr (1.38.881, User-Entscheid 17.11.): POST /pos/orders = nur Ticketpreis, Freikarten 0 €,
 * gleich wie /pos/quote; Online-Kasse rechnet weiter mit Gebühr.   wp eval-file …/test-pos-no-fee-wp.php
 * Aussagekräftig nur mit Kundengebühr (fee_mode=customer, fee_fixed>0) – der Lauf setzt sie vorher per Option.
 */
if (!defined('ABSPATH') || !class_exists('TIX_Fees')) { echo "Tixomat nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d]; };
add_filter('pre_wp_mail', '__return_true', 99);
$ev = 0; $orders = [];
try {
    $adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Kasse ohne Gebühr']);
    update_post_meta($ev, '_tix_date_start', wp_date('Y-m-d', time() + DAY_IN_SECONDS)); update_post_meta($ev, '_tix_time_start', '20:00');
    update_post_meta($ev, '_tix_tickets_enabled', '1');
    update_post_meta($ev, '_tix_ticket_categories', [['name' => 'Normal', 'price' => 20, 'stock' => 50, 'online' => '1']]);
    $online_fee = TIX_Fees::calc_order_fees([['event_id' => $ev, 'cat_index' => 0, 'price' => 20.0, 'qty' => 2]])['customer_fee_line'];
    echo "(Online-Gebühr für 2×20 €: $online_fee €)\n";
    $eq('Vorbedingung: online fällt eine Servicegebühr an', true, $online_fee > 0);

    foreach ([['cash', 40.0], ['card', 40.0], ['free', 0.0]] as [$pay, $want]) {
        [$sq, $q] = $rest('POST', '/pos/quote', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 2]], 'payment' => $pay]);
        [$so, $o] = $rest('POST', '/pos/orders', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 2]], 'payment' => $pay]);
        $oid = intval($o['order_id'] ?? 0); $orders[] = $oid;
        $db = floatval($wpdb->get_var($wpdb->prepare("SELECT total FROM {$wpdb->prefix}tix_orders WHERE id = %d", $oid)));
        $fees = TIX_Object_Data::read('_tix_order_fees', $oid, false);
        $eq("Kasse $pay 2×20 €: Angebot = Bestellung = $want €, keine Gebührendaten", [200, 200, $want, $want, $want, false],
            [$sq, $so, floatval($q['total'] ?? -1), floatval($o['total'] ?? -1), $db, is_array($fees) && floatval($fees['customer_fee_line'] ?? 0) > 0]);
    }
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    remove_filter('pre_wp_mail', '__return_true', 99);
    foreach (array_filter($orders) as $oid) {
        foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => (string) $oid]) as $t) wp_delete_post($t, true);
        $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $oid]);
        $wpdb->delete($wpdb->prefix . 'tix_order_notes', ['order_id' => $oid]);
        $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $oid]);
        TIX_Object_Data::remove('_tix_order_fees', $oid);
        delete_option('_tix_pos_order_' . $oid); delete_option('_tix_order_source_' . $oid);
    }
    if ($ev) wp_delete_post($ev, true);
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
