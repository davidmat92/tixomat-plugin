<?php
/**
 * Bestand mit Sperre (1.38.767): Regeln, Abgleich mit der Anzeige-Kopie, Reservierung ganz oder gar nicht,
 * create_order ausverkauft ohne Bestellung, Rückgabe/erneutes Abziehen.   wp eval-file …/test-stock-wp.php
 * Gleichzeitige Käufe: tests/test-stock-race.sh <container>
 */
if (!defined('ABSPATH') || !class_exists('TIX_Stock')) { echo "Tixomat ab 1.38.767 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$meta = function ($id) { wp_cache_delete($id, 'post_meta'); $c = get_post_meta($id, '_tix_ticket_categories', true); return array_map(function ($x) { return array_key_exists('stock', $x) ? $x['stock'] : 'x'; }, (array) $c); };
$ev = 0; $orders = [];
try {
    echo "== Regeln\n";
    $eq('limit_of: fehlt/leer/negativ = unbegrenzt, 0 = ausverkauft', [null, null, null, 0, 7, null], [TIX_Stock::limit_of(['name' => 'a']), TIX_Stock::limit_of(['stock' => '']), TIX_Stock::limit_of(['stock' => -1]), TIX_Stock::limit_of(['stock' => '0']), TIX_Stock::limit_of(['stock' => 7]), TIX_Stock::limit_of(['stock' => 'abc'])]);
    $o = [['name' => 'A', 'stock' => 5], ['name' => 'B', 'stock' => 3], ['name' => 'C']];
    $eq('plan: unverändert → nichts', ['set' => [], 'delta' => [], 'drop' => [2]], TIX_Stock::plan($o, $o));
    $eq('plan: +10 bei A → Änderung', [0 => [10, 15]], TIX_Stock::plan($o, [['name' => 'A', 'stock' => 15], $o[1], $o[2]])['delta']);
    $eq('plan: Reihenfolge geändert → absolut', [0 => 3, 1 => 5], TIX_Stock::plan($o, [$o[1], $o[0], $o[2]])['set']);
    $eq('plan: absolute-Schalter → absolut', [0 => 15, 1 => 3], TIX_Stock::plan($o, [['name' => 'A', 'stock' => 15], $o[1], $o[2]], true)['set']);
    $eq('plan: B unbegrenzt + Kategorie weg → drop', [1, 2], TIX_Stock::plan($o, [$o[0], ['name' => 'B']])['drop']);

    echo "== Abgleich\n";
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Bestand Test']);
    add_post_meta($ev, '_tix_ticket_categories', [['name' => 'Früh', 'price' => 10, 'stock' => 5], ['name' => 'Normal', 'price' => 20, 'stock' => 3], ['name' => 'Frei', 'price' => 0]]);
    $eq('neue Kategorien → Tabelle', [0 => 5, 1 => 3], TIX_Stock::levels($ev));
    // gleichzeitiger Verkauf: Tabelle 5 → 4, Editor speichert mit alter Anzeige-Kopie (Früh 5) und +2 bei Normal
    $wpdb->query($wpdb->prepare("UPDATE " . TIX_Stock::table() . " SET available = 4 WHERE event_id = %d AND cat_index = 0", $ev));
    update_post_meta($ev, '_tix_ticket_categories', [['name' => 'Früh', 'price' => 10, 'stock' => 5], ['name' => 'Normal', 'price' => 20, 'stock' => 5], ['name' => 'Frei', 'price' => 0]]);
    $eq('Editor-Speichern: Verkauf bleibt, +2 übernommen', [0 => 4, 1 => 5], TIX_Stock::levels($ev));
    $eq('Anzeige-Kopie nachgezogen', [4, 5, 'x'], $meta($ev));
    TIX_Stock::$absolute = true;
    update_post_meta($ev, '_tix_ticket_categories', [['name' => 'Früh', 'price' => 10, 'stock' => 6], ['name' => 'Normal', 'price' => 20, 'stock' => 5], ['name' => 'Frei', 'price' => 0]]);
    TIX_Stock::$absolute = false;
    $eq('App-Editor (absolut): Wert übernommen', [0 => 6, 1 => 5], TIX_Stock::levels($ev));

    echo "== Reservieren\n";
    $r = TIX_Stock::reserve([['event_id' => $ev, 'cat_index' => 0, 'qty' => 2, 'name' => 'Früh'], ['event_id' => $ev, 'cat_index' => 1, 'qty' => 9, 'name' => 'Normal']]);
    $eq('zu viel bei Normal: tix_sold_out 409, Früh zurückgebucht', ['tix_sold_out', 409, 5, [0 => 6, 1 => 5]], [is_wp_error($r) ? $r->get_error_code() : 'ok', is_wp_error($r) ? ($r->get_error_data()['status'] ?? 0) : 0, is_wp_error($r) ? ($r->get_error_data()['available'] ?? null) : null, TIX_Stock::levels($ev)]);
    $eq('Meldung nennt Kategorie und Rest', 'Normal: Nur noch 5 verfügbar.', is_wp_error($r) ? $r->get_error_message() : '');
    $r = TIX_Stock::reserve([['event_id' => $ev, 'cat_index' => 0, 'qty' => 1], ['event_id' => $ev, 'cat_index' => 0, 'qty' => 1], ['event_id' => $ev, 'cat_index' => 2, 'qty' => 50]]);
    $eq('gleiche Kategorie zusammengefasst, unbegrenzt ohne Eintrag', [[['event_id' => $ev, 'cat_index' => 0, 'qty' => 2]], [0 => 4, 1 => 5], [4, 5, 'x']], [$r, TIX_Stock::levels($ev), $meta($ev)]);
    TIX_Stock::release($r);
    $eq('zurückgebucht', [0 => 6, 1 => 5], TIX_Stock::levels($ev));

    echo "== create_order\n";
    $cnt = function () use ($wpdb) { return intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tix_orders")); };
    $before = $cnt();
    $base = ['billing_first_name' => 'Bestand', 'billing_last_name' => 'Test', 'billing_email' => 'bestand@example.test', 'payment_method' => 'demo', 'total' => 0];
    $oid = TIX_Native_Checkout::create_order($base + ['items' => [['event_id' => $ev, 'cat_index' => 1, 'qty' => 6, 'price' => 0, 'name' => 'Normal', 'event_title' => 'Bestand Test', 'meta' => []]]]);
    $e = TIX_Native_Checkout::$last_error;
    $eq('ausverkauft: keine Bestellung, last_error tix_sold_out', [null, 'tix_sold_out', $before], [$oid, is_wp_error($e) ? $e->get_error_code() : null, $cnt()]);
    $oid = TIX_Native_Checkout::create_order($base + ['allow_oversell' => true, 'items' => [['event_id' => $ev, 'cat_index' => 1, 'qty' => 6, 'price' => 0, 'name' => 'Normal', 'event_title' => 'Bestand Test', 'meta' => []]]]);
    if ($oid) $orders[] = $oid;
    $eq('Demo (allow_oversell): angelegt, Bestand 0, abgezogen 5', [true, 0, 5], [(bool) $oid, TIX_Stock::levels($ev)[1] ?? null, (int) (get_option('_tix_order_stock_taken_' . $oid)[0]['qty'] ?? 0)]);
    $oid2 = TIX_Native_Checkout::create_order($base + ['items' => [['event_id' => $ev, 'cat_index' => 0, 'qty' => 2, 'price' => 0, 'name' => 'Früh', 'event_title' => 'Bestand Test', 'meta' => []]]]);
    if ($oid2) $orders[] = $oid2;
    $eq('normale Bestellung: Bestand 6 → 4, Anzeige-Kopie 4', [true, 4, 4], [(bool) $oid2, TIX_Stock::levels($ev)[0] ?? null, $meta($ev)[0]]);
    TIX_Native_Checkout::restore_stock($oid2);
    $eq('Storno gibt zurück (Tabelle + Kopie)', [6, 6], [TIX_Stock::levels($ev)[0] ?? null, $meta($ev)[0]]);
    TIX_Native_Checkout::reclaim_stock($oid2);
    $eq('spätere Zahlung zieht wieder ab', 4, TIX_Stock::levels($ev)[0] ?? null);
    $h = TIX_Stock::health();
    $eq('Gesundheit: keine Abweichung für das Test-Event', 0, count(array_filter($h['mismatch'], function ($m) use ($ev) { return $m['event_id'] === $ev; })));

    echo "== Löschen\n";
    delete_post_meta($ev, '_tix_ticket_categories');
    $eq('Kategorien gelöscht → Zeilen weg', [], TIX_Stock::levels($ev));
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($orders as $o) { $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $o]); $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $o]); delete_option('_tix_order_stock_taken_' . $o); delete_option('_tix_order_stock_restored_' . $o); }
    if ($ev) wp_delete_post($ev, true);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
