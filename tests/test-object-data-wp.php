<?php
/**
 * Daten je Objekt in Tabelle statt Option je Objekt (1.38.769): Übergang, Migration, Lesen/Schreiben, Mehrfach-Lesen, SQL-Prüfung.
 *   wp eval-file …/test-object-data-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Object_Data')) { echo "Tixomat ab 1.38.769 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$base = 990000000 + wp_rand(1000, 99999);
$ids = [$base, $base + 1, $base + 2];
$fees = ['customer_fee_line' => 1.5, 'lines' => [['ticket' => 'ABC', 'fee' => 0.75]], 'label' => 'Servicegebühr – „äöü“'];
$events = [];
add_action('tix_object_data_changed', function ($k, $i) use (&$events) { $events[] = "$k:$i"; }, 10, 2);
try {
    echo "== Übergang (alte Optionen)\n";
    update_option('_tix_order_fees_' . $ids[0], $fees, false);
    update_option('_tix_refund_total_' . $ids[0], 12.5, false);
    update_option('_tix_refund_total_' . $ids[1], 10.0, false);
    update_option('tix_lic_golive_' . $ids[2], ['status' => 'pending', 'nonce' => 'x'], false);
    $eq('read fällt auf alte Option zurück', [$fees, 12.5], [TIX_Object_Data::read('_tix_order_fees', $ids[0]), TIX_Object_Data::read('_tix_refund_total_', $ids[0])]);
    $eq('Default bei fehlendem Eintrag', [false, null], [TIX_Object_Data::read('_tix_order_fees', $ids[1], false), TIX_Object_Data::read('_tix_order_fees', $ids[1])]);

    echo "== Migration\n";
    $m = TIX_Object_Data::migrate();
    $eq('verschoben (mind. die Test-Einträge)', true, ($m['order_fees'] ?? 0) >= 1 && ($m['order_refund_total'] ?? 0) >= 2 && ($m['license_golive'] ?? 0) >= 1);
    $eq('alte Optionen gelöscht', [false, false, false], [get_option('_tix_order_fees_' . $ids[0]), get_option('_tix_refund_total_' . $ids[1]), get_option('tix_lic_golive_' . $ids[2])]);
    // Zahlen als Option kamen als Text zurück ('12.5', '10'); nach dem Umzug genau so (Leser casten selbst)
    $eq('Werte gleich wie vorher get_option (Umlaute, Zahlen als Text)', [$fees, '12.5', '10'], [TIX_Object_Data::read('_tix_order_fees', $ids[0]), TIX_Object_Data::read('_tix_refund_total', $ids[0]), TIX_Object_Data::read('_tix_refund_total', $ids[1])]);
    $raw = $wpdb->get_var($wpdb->prepare("SELECT data FROM " . TIX_Object_Data::table() . " WHERE kind = 'order_fees' AND object_id = %d", $ids[0]));
    $eq('gespeichert als JSON (nicht PHP-serialisiert)', true, is_array(json_decode((string) $raw, true)) && strpos((string) $raw, 'a:') !== 0);

    echo "== Schreiben/Löschen\n";
    update_option('_tix_refund_' . $ids[1], ['amount' => 1], false); // Rest einer alten Option
    $events = [];
    TIX_Object_Data::write('_tix_refund', $ids[1], ['amount' => 5, 'reason' => 'Test']);
    $eq('write: Tabelle, alte Option weg, Hook', [['amount' => 5, 'reason' => 'Test'], false, ["order_refund_last:{$ids[1]}"]], [TIX_Object_Data::read('_tix_refund', $ids[1]), get_option('_tix_refund_' . $ids[1]), $events]);
    TIX_Object_Data::write('_tix_refund_total', $ids[1], 10.0);
    $eq('read_many: Tabelle + fehlende; neu geschriebene Zahl bleibt Zahl', [$ids[0] => '12.5', $ids[1] => 10.0], TIX_Object_Data::read_many('_tix_refund_total', [$ids[0], $ids[1], $ids[2]]));
    $all = TIX_Object_Data::all('tix_lic_golive_');
    $eq('all: Live-Anfrage dabei', 'pending', $all[$ids[2]]['status'] ?? null);
    $sql = TIX_Object_Data::exists_sql('_tix_refund', 'x.id');
    $hit = $wpdb->get_col("SELECT x.id FROM (SELECT {$ids[0]} AS id UNION SELECT {$ids[1]}) x WHERE $sql");
    $eq('exists_sql findet nur die Bestellung mit Erstattung', [(string) $ids[1]], $hit);
    TIX_Object_Data::remove('_tix_refund', $ids[1]);
    $eq('remove', null, TIX_Object_Data::read('_tix_refund', $ids[1]));

    echo "== Nicht umgezogene Arten bleiben Optionen\n";
    TIX_Object_Data::write('_tix_pos_order', $ids[0], ['event_id' => 5]);
    $eq('write → Option', ['event_id' => 5], get_option('_tix_pos_order_' . $ids[0]));
    $eq('read → Option', ['event_id' => 5], TIX_Object_Data::read('_tix_pos_order_', $ids[0]));
    TIX_Object_Data::remove('_tix_pos_order', $ids[0]);
    $eq('remove → Option weg', false, get_option('_tix_pos_order_' . $ids[0]));

    $hl = TIX_Object_Data::health();
    $eq('health: Tabelle da, Zählung je Art', [true, true], [$hl['table'], ($hl['rows']['order_refund_total'] ?? 0) >= 2]);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($ids as $i) { foreach (array_keys(TIX_Object_Data::KINDS) as $p) TIX_Object_Data::remove($p, $i); delete_option('_tix_pos_order_' . $i); }
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
