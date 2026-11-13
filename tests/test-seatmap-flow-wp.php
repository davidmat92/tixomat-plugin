<?php
/**
 * Saalplan Ende-zu-Ende über die echten Kassenwege (Audit 13.11., 1.38.797): halten → bestellen (/customer/orders) → Tickets mit
 * Platz und lesbarer Angabe → Mail/PDF-Daten → zweiter Käufer → Kategorie mit Sitzbereich ohne Platz → Ablauf → Teil-Storno → Storno.
 *   wp eval-file …/test-seatmap-flow-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Seatmap') || !class_exists('TIX_App_Checkout')) { echo "Plugin nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$T = $wpdb->prefix . TIX_Seatmap::TABLE_SUFFIX;
TIX_Seatmap::maybe_upgrade();
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand(20, 200);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_%tix\\_app\\_rl\\_%'");
$keep = [];
foreach ([TIX_Seating::OPT_BOOKED, TIX_Seating::OPT_SETTINGS] as $o) $keep[$o] = get_option($o, null);
$posts = []; $orders = []; $mails = [];
add_filter('pre_wp_mail', function ($r, $a) use (&$mails) { $mails[] = $a; return true; }, 10, 2);
$sold = function ($ev) use ($wpdb, $T) { return array_map('strval', (array) $wpdb->get_col($wpdb->prepare("SELECT seat_id FROM $T WHERE event_id = %d AND status = 'sold' ORDER BY seat_id", $ev))); };
$tickets_of = function ($oid) { return array_map('intval', get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'meta_query' => ['relation' => 'OR', ['key' => '_tix_ticket_order_id', 'value' => $oid], ['key' => '_tix_order_id', 'value' => $oid]]])); };
try {
    wp_set_current_user(0);
    $org = 0;
    if ($multi) {
        $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'Flow Veranstalter']); $posts[] = $org;
        TIX_Plan::save_org($org, ['seating' => true]);
        update_post_meta($org, '_tix_org_approval', 'approved');
    } else update_option(TIX_Seating::OPT_BOOKED, '1', false);
    delete_option(TIX_Seating::OPT_SETTINGS);
    $sm = wp_insert_post(['post_type' => 'tix_seatmap', 'post_status' => 'publish', 'post_title' => 'Flow-Saal']); $posts[] = $sm;
    if ($multi) update_post_meta($sm, TIX_Seatmap::META_ORG, $org);
    TIX_Seatmap::store($sm, ['layout' => 'theater', 'sections' => [['id' => 'parkett', 'label' => 'Parkett', 'price' => 20, 'block' => ['rows' => 2, 'seats' => 6]]]]);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Flow-Konzert', 'meta_input' => [
        '_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() + 12 * DAY_IN_SECONDS), '_tix_time_start' => '20:00', '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1',
        '_tix_ticket_categories' => [['name' => 'Parkett', 'price' => 20, 'qty' => 12, 'seatmap_section' => 'parkett'], ['name' => 'Stehplatz', 'price' => 10, 'qty' => 50]]]]); $posts[] = $ev;
    TIX_Seatmap::assign($ev, $sm);
    $eq('Zuweisung: Event nutzt den Plan, Modul an', [$sm, true], [TIX_Seatmap::event_seatmap($ev), TIX_Seatmap::module_on($ev)]);
    $billing = function ($k) { return ['first_name' => 'Kim', 'last_name' => 'Flow', 'email' => "flow_{$k}_" . wp_rand(1000, 9999) . '@example.test']; };

    // Kauf wie die Kasse: Warenkorbzeile (seat_line mit Halter) → create_order → Zahlung (update_order_status)
    $buy = function ($lines, $email) use (&$orders) {
        $total = 0; foreach ($lines as $l) $total += $l['price'] * $l['qty'];
        $oid = TIX_Native_Checkout::create_order(['billing_first_name' => 'Kim', 'billing_last_name' => 'Flow', 'billing_email' => $email, 'payment_method' => 'demo', 'total' => $total, 'items' => $lines]);
        if (!$oid || is_wp_error($oid)) return 0;
        $orders[] = intval($oid);
        return intval($oid);
    };
    // A: halten (App-Route) + bestellen + bezahlen
    [$st, $h] = $rest('POST', '/customer/seats/hold', ['event_id' => $ev, 'seat_ids' => ['parkett_A_3', 'parkett_A_4']]);
    $tokA = (string) ($h['hold_token'] ?? '');
    $holderA = TIX_Seatmap::app_holder($tokA);
    [$stq, $qa] = $rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => [['seats' => ['parkett_A_3', 'parkett_A_4']]], 'hold_token' => $tokA]);
    $eq('App-Kasse: halten und Angebot mit den Plätzen 200', [200, 200, true], [$st, $stq, !empty($qa['ok'])]);
    $line = TIX_Native_Checkout::seat_line($ev, ['parkett_A_3', 'parkett_A_4'], $holderA);
    $oA = is_array($line) ? $buy([$line], 'flow_a_' . wp_rand(1000, 9999) . '@example.test') : 0;
    if ($oA) { TIX_Seatmap::attach_order([$line], $holderA, $oA); TIX_Native_Checkout::update_order_status($oA, 'completed', 'demo'); }
    $tA = $oA ? $tickets_of($oA) : [];
    $eq('Bestellung bezahlt: 2 Tickets, Plätze verkauft', [2, ['parkett_A_3', 'parkett_A_4']], [count($tA), $sold($ev)]);
    $seats_t = array_map(function ($t) { return (string) get_post_meta($t, '_tix_ticket_seat_id', true); }, $tA); sort($seats_t);
    $eq('Tickets tragen die Platz-ID', ['parkett_A_3', 'parkett_A_4'], $seats_t);

    // Platzangabe lesbar: Ticket-PDF-Daten, Ticket-Antworten, Mail
    $pdf = $tA ? TIX_Ticket_Template::gather_ticket_data($tA[0]) : [];
    $eq('Ticket/PDF: lesbare Platzangabe „Parkett · Reihe A, Platz 3/4“', true, (bool) preg_match('/^Parkett · Reihe A, Platz [34]$/', (string) ($pdf['seat'] ?? $pdf['seat_display'] ?? '')));
    $lbl = method_exists('TIX_Seatmap', 'ticket_seat_label') ? TIX_Seatmap::ticket_seat_label($tA[0]) : '';
    $eq('Ticket: seat_label-Hilfe für alle Antworten', true, (bool) preg_match('/^Parkett · Reihe A, Platz [34]$/', $lbl));
    $mailtxt = implode("\n", array_map(function ($m) { return (string) ($m['message'] ?? ''); }, $mails));
    $eq('Mail an den Käufer nennt die Plätze', true, strpos($mailtxt, 'Reihe A, Platz 3') !== false && strpos($mailtxt, 'Reihe A, Platz 4') !== false);

    // B: zweiter Käufer – gleiche Plätze
    $_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand(20, 200);
    [$st, $hx] = $rest('POST', '/customer/seats/hold', ['event_id' => $ev, 'seat_ids' => ['parkett_A_3']]);
    $eq('Wettlauf: verkaufter Platz lässt sich nicht halten (ok false, failed)', [200, false, ['parkett_A_3']], [$st, $hx['ok'] ?? null, $hx['failed'] ?? null]);
    [$st, , $c] = $rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => [['seats' => ['parkett_A_4']]], 'hold_token' => 'x' . wp_generate_password(20, false)]);
    $eq('Wettlauf: App-Angebot auf verkauften Platz → 409 tix_seat_taken', [409, 'tix_seat_taken'], [$st, $c]);
    // B2: zwei Käufer gleichzeitig
    [, $h1] = $rest('POST', '/customer/seats/hold', ['event_id' => $ev, 'seat_ids' => ['parkett_B_1']]);
    $_SERVER['REMOTE_ADDR'] = '192.0.2.' . wp_rand(20, 200);
    [$st2, $h2] = $rest('POST', '/customer/seats/hold', ['event_id' => $ev, 'seat_ids' => ['parkett_B_1']]);
    $l2 = TIX_Native_Checkout::seat_line($ev, ['parkett_B_1'], 'web_zweiter');
    $eq('Wettlauf: fremd gehaltener Platz → halten failed, Kasse lehnt ab', [false, ['parkett_B_1'], true], [$h2['ok'] ?? null, $h2['failed'] ?? null, is_string($l2)]);
    // B3: bezahlt nach Ablauf der eigenen Haltung, Platz inzwischen verkauft → Konflikt, kein Doppelverkauf
    $holdC = 'web_spaet';
    $lc = TIX_Native_Checkout::seat_line($ev, ['parkett_B_2'], $holdC);
    $oC = is_array($lc) ? $buy([$lc], 'flow_c_' . wp_rand(1000, 9999) . '@example.test') : 0;
    if ($oC) TIX_Seatmap::attach_order([$lc], $holdC, $oC);
    $wpdb->query($wpdb->prepare("DELETE FROM $T WHERE event_id = %d AND seat_id = 'parkett_B_2'", $ev)); // Haltung abgelaufen und aufgeräumt
    $lx = TIX_Native_Checkout::seat_line($ev, ['parkett_B_2'], 'web_schneller');
    $oX = is_array($lx) ? $buy([$lx], 'flow_x_' . wp_rand(1000, 9999) . '@example.test') : 0;
    if ($oX) { TIX_Seatmap::attach_order([$lx], 'web_schneller', $oX); TIX_Native_Checkout::update_order_status($oX, 'completed', 'demo'); }
    if ($oC) TIX_Native_Checkout::update_order_status($oC, 'completed', 'demo');
    $cnt = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $T WHERE event_id = %d AND seat_id = 'parkett_B_2' AND status = 'sold'", $ev)));
    $eq('Späte Zahlung auf inzwischen verkauften Platz: genau ein Verkauf, Konflikt gemerkt', [1, true], [$cnt, $oC ? isset(TIX_Seatmap::conflicts_of($oC)[$ev . '|parkett_B_2']) : false]);

    // C: Kategorie mit Sitzbereich ohne Platzwahl; Stehplatz geht
    [$st, , $c] = $rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 1]]]);
    $eq('App: Kategorie „Parkett“ (Sitzbereich) ohne Platz → 409 tix_seat_required', [409, 'tix_seat_required'], [$st, $c]);
    [$st] = $rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => [['index' => 1, 'qty' => 1]]]);
    $eq('App: Stehplatz (ohne Sitzbereich) geht weiter', 200, $st);
    $cart = TIX_Native_Checkout::add_items(['items' => []], [['event_id' => $ev, 'cat_index' => 0, 'qty' => 1]]);
    $eq('Web-Warenkorb: Parkett ohne Platz abgelehnt (Text)', true, is_string($cart) && strpos($cart, 'Saalplan') !== false);
    $cart = TIX_Native_Checkout::add_items(['items' => []], [['event_id' => $ev, 'cat_index' => 1, 'qty' => 1]]);
    $eq('Web-Warenkorb: Stehplatz geht', true, is_array($cart) && count($cart['items'] ?? []) === 1);

    // G: Spiegel/Kopie mit übernommener Plan-ID nutzt keinen (fremden) Plan
    $cp = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Flow-Spiegel', 'meta_input' => ['_tix_seatmap_id' => $sm, ($multi ? '_tix_syndicated' : '_tix_platform_co') => ($multi ? '1' : '6453')]]); $posts[] = $cp;
    $eq('Spiegel/Kopie: kein Saalplan (Plätze nur beim Ersteller)', 0, TIX_Seatmap::event_seatmap($cp));

    // D: Ablauf einer Haltung
    TIX_Seatmap::hold($ev, $sm, ['parkett_B_6'], 'web_ablauf');
    $wpdb->query($wpdb->prepare("UPDATE $T SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE event_id = %d AND seat_id = 'parkett_B_6'", $ev));
    TIX_Seatmap::cleanup_expired();
    $eq('Abgelaufene Haltung wird frei', 0, intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $T WHERE event_id = %d AND seat_id = 'parkett_B_6'", $ev))));

    // E: Teil-Storno (einzelnes Ticket) gibt seinen Platz frei
    if ($tA) {
        update_post_meta($tA[0], '_tix_ticket_status', 'cancelled');
        $left = $sold($ev);
        $gone = (string) get_post_meta($tA[0], '_tix_ticket_seat_id', true);
        $eq('Teil-Storno eines Tickets gibt genau dessen Platz frei', [2, false], [count($left), in_array($gone, $left, true)]);
    }
    // F: Storno der Bestellung gibt alle frei
    if ($oA) TIX_Native_Checkout::update_order_status($oA, 'cancelled', 'admin');
    $eq('Storno der Bestellung: ihre Plätze frei (fremder Verkauf B2 bleibt)', ['parkett_B_2'], $sold($ev));
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($orders as $oid) {
        foreach ($tickets_of($oid) as $t) wp_delete_post($t, true);
        foreach (['tix_order_items', 'tix_order_notes'] as $tb) $wpdb->delete($wpdb->prefix . $tb, ['order_id' => $oid]);
        $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $oid]);
    }
    if (!empty($ev)) $wpdb->delete($T, ['event_id' => $ev]);
    foreach (array_reverse($posts) as $p) wp_delete_post($p, true);
    foreach ($keep as $k => $v) { if ($v === null) delete_option($k); else update_option($k, $v, false); }
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
