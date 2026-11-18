<?php
/**
 * 1.38.904: Schalter auto_complete_enabled entfällt; Mollie „authorized“ → Einzug (Captures API), „paid“ → completed.
 *   wp eval-file …/test-mollie-capture-wp.php   (beide Modi; Mollie-Aufrufe gemockt)
 */
if (!defined('ABSPATH') || !method_exists('TIX_Gateway_Mollie', 'capture')) { echo "Tixomat ab 1.38.904 nötig\n"; return; }
$f = 0; $n = 0;
$ok = function ($l, $c) use (&$f, &$n) { $n++; echo ($c ? 'ok    ' : 'FAIL  ') . $l . "\n"; if (!$c) $f++; };
global $wpdb;
// tix_get_settings() hält die Einstellungen je Aufruf fest: ohne Mollie-Schlüssel erst setzen, dann neu starten
if (trim((string) tix_get_settings('mollie_api_key')) === '') {
    $s0 = get_option('tix_settings', []); $s0 = is_array($s0) ? $s0 : [];
    $s0['mollie_api_key'] = 'test_mockmockmockmockmockmockmock00'; update_option('tix_settings', $s0); update_option('tix_test_mollie_key_set', 1, false);
    echo "Test-Schlüssel gesetzt – bitte noch einmal starten\n"; return;
}
$orig = get_option('tix_settings', []);
if (get_option('tix_test_mollie_key_set')) { unset($orig['mollie_api_key']); delete_option('tix_test_mollie_key_set'); }
$s = get_option('tix_settings', []); $s['auto_complete_enabled'] = 0; update_option('tix_settings', $s);
$state = ['status' => 'authorized', 'capture_code' => 201, 'captures' => 0];
$pid = 'tr_Test' . wp_generate_password(8, false, false);
$mock = function ($pre, $args, $url) use (&$state, &$pid) {
    if (strpos($url, 'api.mollie.com') === false) return $pre;
    if (strpos($url, '/captures') !== false) { $state['captures']++; $state['last_capture'] = json_decode((string) $args['body'], true); return ['response' => ['code' => $state['capture_code'], 'message' => ''], 'body' => wp_json_encode(['id' => 'cpt_mock', 'status' => 'pending']), 'headers' => [], 'cookies' => []]; }
    if (strpos($url, '/payments/' . $pid) !== false) return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => wp_json_encode(['id' => $pid, 'status' => $state['status'], 'amount' => ['value' => '25.00', 'currency' => 'EUR'], 'metadata' => ['order_id' => $state['order']]]), 'headers' => [], 'cookies' => []];
    return ['response' => ['code' => 404, 'message' => ''], 'body' => '{}', 'headers' => [], 'cookies' => []];
};
add_filter('pre_http_request', $mock, PHP_INT_MAX, 3);
$die = function () { return function ($m) { throw new RuntimeException('die:' . (is_string($m) ? $m : '')); }; };
add_filter('wp_die_ajax_handler', $die); add_filter('wp_die_handler', $die);
$hook = function () use (&$pid) { $_POST['id'] = $pid; try { TIX_Gateway_Mollie::handle_webhook(); } catch (RuntimeException $e) { return $e->getMessage(); } return 'kein die'; };
$skip = function () { return true; }; add_filter('pre_wp_mail', $skip);
$eid = 0; $oid = 0;
try {
    $eid = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Capture-Test']);
    $oid = TIX_Native_Checkout::create_order(['billing_first_name' => 'Cap', 'billing_last_name' => 'Ture', 'billing_email' => 'cap' . wp_rand() . '@example.test', 'payment_method' => 'mollie', 'allow_oversell' => true, 'total' => 25,
        'items' => [['event_id' => $eid, 'cat_index' => 0, 'name' => 'Ticket', 'event_title' => 'Capture-Test', 'price' => 25, 'qty' => 1, 'meta' => []]]]);
    $oid = is_array($oid) ? intval($oid['order_id'] ?? $oid['id'] ?? 0) : intval($oid);
    $state['order'] = $oid;
    update_option('_tix_mollie_payment_' . $oid, $pid, false);
    $st = function () use ($wpdb, $oid) { return $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tix_orders WHERE id = %d", $oid)); };
    $ok('Bestellung angelegt', $oid > 0);
    $completed = 0; $cnt = function ($id) use (&$completed, $oid) { if (intval($id) === $oid) $completed++; }; add_action('tix_order_completed', $cnt, 1);
    // authorized → Einzug, processing
    $r = $hook();
    $ok('authorized: Einzug ausgelöst (1×, voller Betrag)', $state['captures'] === 1 && ($state['last_capture']['amount']['value'] ?? '') === '25.00');
    $ok('authorized: Status processing, nicht completed (auch mit altem Schalter 0)', $st() === 'processing' && $completed === 0);
    $ok('Merker gesetzt', (bool) get_option('_tix_mollie_capture_' . $oid));
    // erneut authorized: kein zweiter Einzug
    delete_transient('tix_mollie_lock_' . $oid);
    $hook();
    $ok('erneut authorized: kein zweiter Einzug', $state['captures'] === 1 && $st() === 'processing');
    // paid → completed
    delete_transient('tix_mollie_lock_' . $oid);
    $state['status'] = 'paid';
    $hook();
    $ok('paid: completed, tix_order_completed genau 1×', $st() === 'completed' && $completed === 1);
    // Einzug schlägt fehl → Merker nicht gesetzt, Wiederholung möglich
    delete_option('_tix_mollie_capture_' . $oid);
    $state['capture_code'] = 422;
    $ok('Einzug-Fehler → false, kein Merker', TIX_Gateway_Mollie::capture($oid, $pid, ['amount' => ['value' => '25.00', 'currency' => 'EUR']]) === false && !get_option('_tix_mollie_capture_' . $oid));
    $state['capture_code'] = 201;
    $ok('Wiederholung klappt', TIX_Gateway_Mollie::capture($oid, $pid, []) === true && TIX_Gateway_Mollie::capture($oid, $pid, []) === 'already');
    // Schalter ist weg: processing über andere Wege bleibt processing
    TIX_Native_Checkout::update_order_status($oid, 'processing', 'paypal');
    $ok('kein Auto-Abschluss mehr über den alten Hook', $st() === 'processing');
    remove_action('tix_order_completed', $cnt, 1);
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    remove_filter('pre_http_request', $mock, PHP_INT_MAX); remove_filter('wp_die_ajax_handler', $die); remove_filter('wp_die_handler', $die); remove_filter('pre_wp_mail', $skip);
    unset($_POST['id']);
    update_option('tix_settings', $orig);
    if ($oid) {
        foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => (string) $oid]) as $t) wp_delete_post($t, true);
        $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $oid]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $oid]);
        delete_option('_tix_mollie_payment_' . $oid); delete_option('_tix_mollie_capture_' . $oid);
    }
    if ($eid) wp_delete_post($eid, true);
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
