<?php
/**
 * A4 Rollstuhlplatz/Begleitperson (1.38.812): Kategorie-Typ speichern, öffentlich mit Hinweis, Begleitperson nur 1:1 mit Ticket.
 *   wp eval-file …/test-access-categories-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Access_Categories')) { echo "Tixomat ab 1.38.812 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$posts = [];
try {
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    wp_set_current_user($adm);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Access-Event', 'meta_input' => ['_tix_date_start' => wp_date('Y-m-d', time() + 20 * DAY_IN_SECONDS), '_tix_tickets_enabled' => '1',
        '_tix_ticket_categories' => [['name' => 'Normal', 'price' => 20, 'qty' => 100, 'stock' => 100]]]]); $posts[] = $ev;
    [$st, $d] = $rest('POST', "/events/$ev", ['categories' => [['index' => 0, 'name' => 'Normal', 'price' => 20, 'qty' => 100], ['name' => 'Rollstuhlplatz', 'price' => 20, 'qty' => 4, 'access' => 'wheelchair'],
        ['name' => 'Begleitperson', 'price' => 0, 'qty' => 4, 'access' => 'companion'], ['name' => 'Quatsch', 'price' => 5, 'qty' => 1, 'access' => 'xyz']]]);
    $cats = get_post_meta($ev, '_tix_ticket_categories', true);
    $eq('Speichern: access wheelchair/companion, Unbekanntes ignoriert', [true, 'wheelchair', 'companion', false], [in_array($st, [200, 201], true), $cats[1]['access'] ?? '', $cats[2]['access'] ?? '', isset($cats[3]['access'])]);
    $pub = TIX_App_Checkout::public_categories($ev);
    $by = array_column($pub, null, 'index');
    $eq('Öffentlich: access + Hinweis (Merkzeichen B)', ['wheelchair', 'companion', true, ''], [$by[1]['access'] ?? '', $by[2]['access'] ?? '', strpos((string) ($by[2]['access_hint'] ?? ''), 'Merkzeichen B') !== false, $by[0]['access'] ?? 'x']);
    $eq('Begleitperson allein → Fehler', true, TIX_Access_Categories::cart_error([['event_id' => $ev, 'cat_index' => 2, 'qty' => 1]]) !== '');
    $eq('Begleitperson + Rollstuhlplatz 1:1 → ok', '', TIX_Access_Categories::cart_error([['event_id' => $ev, 'cat_index' => 1, 'qty' => 1], ['event_id' => $ev, 'cat_index' => 2, 'qty' => 1]]));
    $eq('2 Begleitpersonen auf 1 Ticket → Fehler', true, TIX_Access_Categories::cart_error([['event_id' => $ev, 'cat_index' => 0, 'qty' => 1], ['event_id' => $ev, 'cat_index' => 2, 'qty' => 2]]) !== '');
    $eq('Normale Bestellung unberührt', '', TIX_Access_Categories::cart_error([['event_id' => $ev, 'cat_index' => 0, 'qty' => 3]]));
    [$st, $d] = $rest('POST', "/events/$ev", ['categories' => [['index' => 0, 'name' => 'Normal', 'price' => 20, 'qty' => 100], ['index' => 1, 'name' => 'Rollstuhlplatz', 'price' => 20, 'qty' => 4], ['index' => 2, 'name' => 'Begleitperson', 'price' => 0, 'qty' => 4, 'access' => '']]]);
    $cats = get_post_meta($ev, '_tix_ticket_categories', true);
    $eq('Ohne access-Feld bleibt der Typ, access "" entfernt ihn', ['wheelchair', false], [$cats[1]['access'] ?? '', isset($cats[2]['access'])]);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($posts as $p) wp_delete_post($p, true);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
