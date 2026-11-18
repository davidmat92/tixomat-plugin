<?php
/**
 * Feste Reihenfolge bei gleichem Datum (1.38.899): Datum, Beginn-Uhrzeit (leer zuerst), ID – auch bei get_posts und DESC.
 *   wp eval-file …/test-event-order-wp.php   (beide Modi)
 */
if (!defined('ABSPATH') || !method_exists('TIX_Public_Events', 'tie_break_vars')) { echo "Tixomat ab 1.38.899 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$ids = [];
$date = gmdate('Y-m-d', current_time('timestamp') + 400 * DAY_IN_SECONDS); // eigener Tag, weit weg von anderen Testdaten
try {
    // Anlage-Reihenfolge absichtlich durcheinander
    foreach ([['A', '21:00'], ['B', ''], ['C', '09:00'], ['D', '21:00'], ['E', null]] as [$t, $time]) {
        $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Order ' . $t]);
        update_post_meta($id, '_tix_date_start', $date);
        if ($time !== null) update_post_meta($id, '_tix_time_start', $time);
        $ids[$t] = $id;
    }
    $mq = [['key' => '_tix_date_start', 'value' => $date, 'compare' => '=']];
    $args = ['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 50, 'fields' => 'ids', 'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'meta_query' => $mq];
    $asc = array_map('intval', get_posts($args + ['order' => 'ASC']));
    // ohne Uhrzeit (B leer, E ohne Meta) zuerst nach ID, dann 09:00, dann 21:00 nach ID
    $exp = [$ids['B'], $ids['E'], $ids['C'], $ids['A'], $ids['D']];
    $eq('get_posts ASC: Datum, Uhrzeit (leer zuerst), ID', $exp, $asc);
    $desc = array_map('intval', get_posts($args + ['order' => 'DESC']));
    $eq('get_posts DESC: genau umgekehrt', array_reverse($exp), $desc);
    $wq = new WP_Query($args + ['order' => 'ASC']);
    $eq('WP_Query (Filter aktiv) gleich', $exp, array_map('intval', $wq->posts));
    $other = array_map('intval', get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 50, 'fields' => 'ids', 'post__in' => array_values($ids), 'orderby' => 'ID', 'order' => 'ASC']));
    $sorted = array_values($ids); sort($sorted);
    $eq('andere Sortierung unberührt', $sorted, $other);
    if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
    $req = new WP_REST_Request('GET', '/tixomat/v1/public/events'); $req->set_query_params(['from' => $date, 'to' => $date, 'per_page' => 50]);
    $got = array_values(array_intersect(array_map('intval', array_column((array) (rest_do_request($req)->get_data()['events'] ?? []), 'id')), array_values($ids)));
    $eq('/public/events: gleiche Reihenfolge', $exp, $got);
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    foreach ($ids as $id) wp_delete_post($id, true);
    if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
