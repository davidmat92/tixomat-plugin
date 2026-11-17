<?php
/**
 * Bestellungen von Hand (1.38.879, W1): mark-paid, Notizen, Tickets nachholen, Bankdaten, manuelle Bestellung, Ticket-Status.
 *   wp eval-file …/test-order-manual-wp.php   (Vorkasse-Teil „transfer“ nur, wenn Überweisung eingerichtet ist)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Order_Manual')) { echo "Tixomat ab 1.38.879 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? null) : null]; };
$mails = [];
// Versuche zählen (vor Demo-Shop-Sperre und Versand), dann nicht wirklich senden
$cap = function ($atts) use (&$mails) { $mails[] = ['to' => is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to'], 'subject' => (string) $atts['subject']]; return $atts; };
add_filter('wp_mail', $cap, 1);
add_filter('pre_wp_mail', '__return_true', 99);
$sfx = substr(md5(microtime()), 0, 6);
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$demo = class_exists('TIX_Demo_Shop') && TIX_Demo_Shop::on(); // Demo-Instanz verwirft Kundenmails absichtlich
$ev = 0; $ev2 = 0; $orders = [];
$stock = function ($eid, $ci = 0) { wp_cache_delete($eid, 'post_meta'); $c = get_post_meta($eid, '_tix_ticket_categories', true); return intval($c[$ci]['stock'] ?? -99); };
$tickets = function ($oid) { return array_map('intval', get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => (string) $oid])); };
$st = function ($oid) use ($wpdb) { return (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tix_orders WHERE id = %d", $oid)); };
try {
    // ═══ Reine Regeln ═══
    $eq('mark_paid: on-hold Vorkasse → ok', true, TIX_Order_Manual::mark_paid_check('on-hold', 'bank', 20, null, false, false));
    $eq('mark_paid: cancelled → tix_not_open', 'tix_not_open', TIX_Order_Manual::mark_paid_check('cancelled', 'bank', 20, null, false, true)[0]);
    $eq('mark_paid: Mollie → tix_online_payment', 'tix_online_payment', TIX_Order_Manual::mark_paid_check('pending', 'mollie', 20, null, false, true)[0]);
    $eq('mark_paid: evendis Veranstalter → tix_platform_only, Admin ok', ['tix_platform_only', true], [TIX_Order_Manual::mark_paid_check('on-hold', 'bank', 20, null, true, false)[0], TIX_Order_Manual::mark_paid_check('on-hold', 'bank', 20, 20.0, true, true)]);
    $eq('mark_paid: Teilzahlung → tix_partial_payment', 'tix_partial_payment', TIX_Order_Manual::mark_paid_check('on-hold', 'bank', 20, 10, false, true)[0]);
    $eq('ticket: storniert → reaktivieren nur von Hand storniert + bezahlt', [true, 'tix_not_reactivatable', 'tix_not_reactivatable'],
        [TIX_Order_Manual::ticket_status_check('cancelled', 'valid', false, '', 'studio', true), TIX_Order_Manual::ticket_status_check('cancelled', 'valid', false, '', '', true)[0], TIX_Order_Manual::ticket_status_check('cancelled', 'valid', false, '', 'studio', false)[0]]);
    $eq('ticket: eingecheckt / Weiterverkauf nicht stornierbar', ['tix_ticket_used', 'tix_ticket_resale'], [TIX_Order_Manual::ticket_status_check('valid', 'cancelled', true, '', '', true)[0], TIX_Order_Manual::ticket_status_check('valid', 'cancelled', false, 'open', '', true)[0]]);
    $eq('Art: evendis paid immer 403, transfer nur Admin, ohne Bank 409', ['tix_platform_only', 'tix_platform_only', true, 'tix_bank_off', true],
        [TIX_Order_Manual::manual_kind_check('paid', true, true, true)[0], TIX_Order_Manual::manual_kind_check('transfer', true, false, true)[0], TIX_Order_Manual::manual_kind_check('transfer', true, true, true), TIX_Order_Manual::manual_kind_check('transfer', false, true, false)[0], TIX_Order_Manual::manual_kind_check('paid', false, false, false)]);

    // ═══ Aufbau ═══
    $adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Von Hand ' . $sfx]);
    update_post_meta($ev, '_tix_date_start', wp_date('Y-m-d', time() + 10 * DAY_IN_SECONDS)); update_post_meta($ev, '_tix_time_start', '20:00');
    update_post_meta($ev, '_tix_tickets_enabled', '1');
    update_post_meta($ev, '_tix_ticket_categories', [['name' => 'Normal', 'price' => 20, 'stock' => 10, 'online' => '1'], ['name' => 'Knapp', 'price' => 10, 'stock' => 1, 'online' => '1']]);
    $mail = "kunde_$sfx@example.test";

    [$s, $d] = $rest('GET', '/orders', ['per_page' => 1]);
    $eq('GET /orders: can_create + create_kinds (evendis nur free, ohne Bank kein transfer)', [200, true, $multi ? ['free'] : ['free', 'paid']], [$s, $d['can_create'] ?? null, (class_exists('TIX_Gateway_Bank') && TIX_Gateway_Bank::is_available()) ? array_values(array_diff($d['create_kinds'] ?? [], ['transfer'])) : ($d['create_kinds'] ?? null)]);

    // ═══ Manuelle Bestellung: Freikarte ═══
    $mails = [];
    [$s, $d] = $rest('POST', '/orders/manual', ['event_id' => $ev, 'kind' => 'free', 'items' => [['cat_index' => 0, 'qty' => 2]], 'customer' => ['first_name' => 'Frei', 'last_name' => 'Karte', 'email' => $mail], 'note' => 'Gästeliste']);
    $o1 = intval($d['order_id'] ?? 0); $orders[] = $o1;
    $eq('Freikarte: 200, bezahlt, 0 €, 2 Tickets, Zahlart free', [200, 'completed', 0.0, 2, 'free'], [$s, $d['status'] ?? null, $d['total'] ?? null, $d['tickets'] ?? null, $d['payment_method'] ?? null]);
    $eq('Freikarte: Bestand 10 → 8', 8, $stock($ev));
    $to = array_column($mails, 'to');
    $eq('Freikarte: Kundenmail ja (Demo: verworfen), Team-Mail „Neue Bestellung“ nein', [!$demo, false], [in_array($mail, $to, true), in_array(get_option('admin_email'), $to, true)]);
    $eq('Freikarte: Kunde ist nicht der Mitarbeiter', 0, intval($wpdb->get_var($wpdb->prepare("SELECT customer_id FROM {$wpdb->prefix}tix_orders WHERE id = %d", $o1))));

    // ═══ notify:false ═══
    $mails = [];
    [$s, $d] = $rest('POST', '/orders/manual', ['event_id' => $ev, 'kind' => 'free', 'notify' => false, 'items' => [['cat_index' => 0, 'qty' => 1]], 'customer' => ['first_name' => 'Still', 'email' => $mail]]);
    $orders[] = intval($d['order_id'] ?? 0);
    $eq('notify:false: Tickets ja, keine Mail an den Kunden', [200, 1, false], [$s, $d['tickets'] ?? null, in_array($mail, array_column($mails, 'to'), true)]);

    // ═══ Idempotenz ═══
    $key = 'idem-' . $sfx;
    [, $a] = $rest('POST', '/orders/manual', ['event_id' => $ev, 'kind' => 'free', 'idempotency_key' => $key, 'items' => [['cat_index' => 0, 'qty' => 1]], 'customer' => ['first_name' => 'I', 'email' => $mail]]);
    [, $b] = $rest('POST', '/orders/manual', ['event_id' => $ev, 'kind' => 'free', 'idempotency_key' => $key, 'items' => [['cat_index' => 0, 'qty' => 1]], 'customer' => ['first_name' => 'I', 'email' => $mail]]);
    $orders[] = intval($a['order_id'] ?? 0);
    $eq('gleicher Schlüssel: dieselbe Bestellung, replayed', [true, true], [($a['order_id'] ?? 0) === ($b['order_id'] ?? -1), !empty($b['replayed'])]);

    // ═══ Bezahlt beim Veranstalter ═══
    [$s, $d, $c] = $rest('POST', '/orders/manual', ['event_id' => $ev, 'kind' => 'paid', 'discount_percent' => 50, 'items' => [['cat_index' => 0, 'qty' => 1]], 'customer' => ['first_name' => 'Bar', 'email' => $mail]]);
    if ($multi) {
        $eq('evendis: paid → 403 tix_platform_only', [403, 'tix_platform_only'], [$s, $c]);
    } else {
        $orders[] = intval($d['order_id'] ?? 0);
        $eq('paid: bezahlt, Zahlart manual, 50 % Rabatt (≥ 10 €)', [200, 'completed', 'manual', true], [$s, $d['status'] ?? null, $d['payment_method'] ?? null, floatval($d['total'] ?? 0) >= 10.0 && floatval($d['total'] ?? 0) < 20.0]);
    }

    // ═══ Fehler ═══
    [$s, $d, $c] = $rest('POST', '/orders/manual', ['event_id' => $ev, 'kind' => 'free', 'items' => [['cat_index' => 1, 'qty' => 2]], 'customer' => ['first_name' => 'X', 'email' => $mail]]);
    $eq('ausverkauft (1 da, 2 gewollt) → 409 tix_sold_out', [409, 'tix_sold_out'], [$s, $c]);
    [$s, , $c] = $rest('POST', '/orders/manual', ['event_id' => $ev, 'kind' => 'free', 'items' => [['cat_index' => 0, 'qty' => 1]], 'customer' => ['first_name' => 'X', 'email' => 'kaputt']]);
    $eq('ungültige E-Mail → 400', 400, $s);
    [$s, , $c] = $rest('POST', '/orders/manual', ['event_id' => $ev, 'kind' => 'bar', 'items' => [['cat_index' => 0, 'qty' => 1]], 'customer' => ['first_name' => 'X', 'email' => $mail]]);
    $eq('unbekannte Art → 400', 400, $s);
    if (!(class_exists('TIX_Gateway_Bank') && TIX_Gateway_Bank::is_available())) {
        [$s, , $c] = $rest('POST', '/orders/manual', ['event_id' => $ev, 'kind' => 'transfer', 'items' => [['cat_index' => 0, 'qty' => 1]], 'customer' => ['first_name' => 'X', 'email' => $mail]]);
        $eq('Vorkasse ohne Einrichtung → 409 tix_bank_off', [409, 'tix_bank_off'], [$s, $c]);
    }

    // ═══ Notizen + Detail ═══
    [$s, $d] = $rest('POST', "/orders/$o1/notes", ['text' => 'Rückruf am Montag']);
    $eq('Notiz angelegt (Autor = Mitarbeiter)', [200, 'Rückruf am Montag', 'internal'], [$s, $d['note']['text'] ?? null, $d['note']['type'] ?? null]);
    [$s] = $rest('POST', "/orders/$o1/notes", ['text' => '']);
    $eq('leere Notiz → 400', 400, $s);
    [$s, $d] = $rest('GET', "/orders/$o1/notes");
    $eq('Notizen: neueste zuerst, „von Hand“ im Verlauf', ['Rückruf am Montag', true], [$d['notes'][0]['text'] ?? null, (bool) array_filter($d['notes'] ?? [], function ($x) { return strpos($x['text'], 'Bestellung von Hand') !== false && strpos($x['text'], 'Gästeliste') !== false; })]);
    [$s, $d] = $rest('GET', "/orders/$o1/detail");
    $eq('Detail: notes + manual (nicht bezahlbar, Tickets nachholbar)', [200, true, false, true], [$s, count($d['order']['notes'] ?? []) >= 2, $d['order']['manual']['can_mark_paid'] ?? null, $d['order']['manual']['can_regenerate'] ?? null]);

    // ═══ Tickets nachholen ═══
    [$s, $d] = $rest('POST', "/orders/$o1/regenerate-tickets");
    $eq('Tickets vollständig: nichts erzeugt', [200, 0, 2], [$s, $d['created'] ?? null, $d['tickets'] ?? null]);
    foreach ($tickets($o1) as $t) wp_delete_post($t, true);
    [$s, $d] = $rest('POST', "/orders/$o1/regenerate-tickets");
    $eq('Tickets fehlten ganz: neu erzeugt', [200, 2, 2], [$s, $d['created'] ?? null, $d['tickets'] ?? null]);

    // ═══ Ticket-Status (V12) ═══
    $t1 = $tickets($o1); $tA = $t1[0]; $before = $stock($ev);
    [$s, $d] = $rest('POST', "/tickets/$tA/status", ['status' => 'cancelled', 'reason' => 'doppelt']);
    $eq('Ticket storniert, Bestand unverändert', [200, 'cancelled', 'cancelled', $before], [$s, $d['ticket']['status'] ?? null, (string) get_post_meta($tA, '_tix_ticket_status', true), $stock($ev)]);
    [, $d] = $rest('GET', "/orders/$o1/detail");
    $eq('Detail: Ticket reaktivierbar', true, $d['order']['manual']['tickets'][(string) $tA]['reactivate'] ?? null);
    [$s, $d] = $rest('POST', "/tickets/$tA/status", ['status' => 'valid', 'note' => 'war doch richtig']);
    $eq('Ticket wieder gültig, note als Begründung im Verlauf', [200, 'valid', true], [$s, (string) get_post_meta($tA, '_tix_ticket_status', true), (bool) array_filter(TIX_Order_Manual::notes($o1), function ($x) { return strpos($x['text'], 'war doch richtig') !== false; })]);
    update_post_meta($tA, '_tix_ticket_status', 'cancelled'); // wie nach Erstattung (ohne Markierung)
    [$s, , $c] = $rest('POST', "/tickets/$tA/status", ['status' => 'valid']);
    $eq('nach Erstattung storniert → 409 tix_not_reactivatable', [409, 'tix_not_reactivatable'], [$s, $c]);
    [$s] = $rest('POST', "/tickets/$tA/status", ['status' => 'kaputt']);
    $eq('ungültiger Status → 400', 400, $s);

    // ═══ Vorkasse als bezahlt setzen ═══
    $mk = function ($method, $status) use ($ev, $mail, $wpdb) {
        TIX_Native_Checkout::save_cart(['items' => [['event_id' => $ev, 'cat_index' => 0, 'name' => 'Normal', 'event_title' => 'T', 'price' => 20.0, 'qty' => 1, 'meta' => []]], 'coupon' => null]);
        $oid = TIX_Native_Checkout::create_order(['billing_first_name' => 'Vor', 'billing_last_name' => 'Kasse', 'billing_email' => $mail, 'payment_method' => $method, 'total' => 20.0,
            'items' => [['event_id' => $ev, 'cat_index' => 0, 'name' => 'Normal', 'event_title' => 'T', 'price' => 20.0, 'qty' => 1, 'meta' => []]]]);
        TIX_Native_Checkout::clear_cart();
        if ($oid && $status !== 'pending') $wpdb->update($wpdb->prefix . 'tix_orders', ['status' => $status], ['id' => $oid]);
        return intval($oid);
    };
    $ob = $mk('bank', 'on-hold'); $orders[] = $ob;
    $total = floatval($wpdb->get_var($wpdb->prepare("SELECT total FROM {$wpdb->prefix}tix_orders WHERE id = %d", $ob)));
    [, $d] = $rest('GET', "/orders/$ob/detail");
    $eq('Detail Vorkasse: als bezahlt setzbar, Bankdaten-Knopf', [true, !$multi || true], [$d['order']['manual']['can_mark_paid'] ?? null, $d['order']['manual']['can_send_bank_info'] ?? null]);
    $eq('Detail Vorkasse: Bankblock mit Verwendungszweck und Betrag', [true, $total], [is_array($d['order']['manual']['bank'] ?? null) && ($d['order']['manual']['bank']['reference'] ?? '') !== '', $d['order']['manual']['bank']['amount'] ?? null]);
    [$s, , $c] = $rest('POST', "/orders/$ob/mark-paid", ['paid_at' => wp_date('Y-m-d', time() + 3 * DAY_IN_SECONDS)]);
    $eq('Eingangsdatum in der Zukunft → 400', 400, $s);
    [$s, , $c] = $rest('POST', "/orders/$ob/mark-paid", ['received' => $total - 5]);
    $eq('Teilbetrag → 409, bleibt offen', [409, 'tix_partial_payment', 'on-hold'], [$s, $c, $st($ob)]);
    [$s, , $c] = $rest('POST', "/orders/$ob/send-bank-info");
    $bs = tix_get_settings();
    if (trim((string) ($bs['bank_iban'] ?? '')) !== '' && trim((string) ($bs['bank_holder'] ?? '')) !== '') $eq('Bankdaten gesendet', 200, $s);
    else $eq('Bankdaten ohne IBAN → 400 tix_bank_missing', [400, 'tix_bank_missing'], [$s, $c]);
    $mails = [];
    [$s, $d] = $rest('POST', "/orders/$ob/mark-paid", ['received' => $total, 'note' => 'Eingang Sparkasse 17.11.']);
    $eq('bezahlt: completed, 1 Ticket', [200, 'completed', 1], [$s, $d['order']['status'] ?? null, $d['tickets'] ?? null]);
    $to = array_column($mails, 'to');
    $eq('bezahlt: Kundenmail ja (Demo: verworfen), Team-Mail nein', [!$demo, false], [in_array($mail, $to, true), in_array(get_option('admin_email'), $to, true)]);
    [$s, , $c] = $rest('POST', "/orders/$ob/mark-paid");
    $eq('zweimal → 409 tix_not_open', [409, 'tix_not_open'], [$s, $c]);
    $audit = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Customers::audit_table() . " WHERE action = 'order_paid' AND subject_id = %s", (string) $ob));
    $eq('Änderungsprotokoll order_paid', '1', (string) $audit);
    $ob2 = $mk('bank', 'on-hold'); $orders[] = $ob2;
    $mails = [];
    [$s, $d] = $rest('POST', "/orders/$ob2/mark-paid", ['notify' => false, 'paid_at' => wp_date('Y-m-d')]);
    $eq('bezahlt ohne Benachrichtigung: Tickets ja, keine Kundenmail, Datum im Verlauf', [200, 1, false, true],
        [$s, $d['tickets'] ?? null, in_array($mail, array_column($mails, 'to'), true), (bool) array_filter(TIX_Order_Manual::notes($ob2), function ($x) { return strpos($x['text'], 'Zahlung eingegangen am') !== false && strpos($x['text'], 'ohne Mail') !== false; })]);
    $om = $mk('mollie', 'pending'); $orders[] = $om;
    [$s, , $c] = $rest('POST', "/orders/$om/mark-paid");
    $eq('Mollie offen → 409 tix_online_payment', [409, 'tix_online_payment'], [$s, $c]);
    $oc = $mk('bank', 'cancelled'); $orders[] = $oc;
    [$s, , $c] = $rest('POST', "/orders/$oc/mark-paid");
    $eq('storniert → 409 tix_not_open', [409, 'tix_not_open'], [$s, $c]);
    [$s, , $c] = $rest('POST', "/orders/$oc/regenerate-tickets");
    $eq('Tickets nachholen nur bezahlt → 409', [409, 'tix_not_paid'], [$s, $c]);

    // ═══ Vorkasse von Hand (nur mit eingerichteter Überweisung) ═══
    if (class_exists('TIX_Gateway_Bank') && TIX_Gateway_Bank::is_available()) {
        $mails = [];
        [$s, $d] = $rest('POST', '/orders/manual', ['event_id' => $ev, 'kind' => 'transfer', 'items' => [['cat_index' => 0, 'qty' => 1]], 'customer' => ['first_name' => 'Ueber', 'email' => $mail]]);
        $ot = intval($d['order_id'] ?? 0); $orders[] = $ot;
        $eq('transfer: wartet (on-hold), Zahlart bank, noch keine Tickets', [200, 'on-hold', 'bank', 0], [$s, $d['status'] ?? null, $d['payment_method'] ?? null, $d['tickets'] ?? null]);
        $eq('transfer: Mail „Bestellung wird geprüft“ an den Kunden (Demo: verworfen)', !$demo, in_array($mail, array_column($mails, 'to'), true));
        [$s, $d] = $rest('POST', "/orders/$ot/mark-paid");
        $eq('transfer → bezahlt: Tickets da', [200, 'completed', 1], [$s, $d['order']['status'] ?? null, $d['tickets'] ?? null]);
    } else echo "(Überweisung nicht eingerichtet: transfer-Teil übersprungen)\n";

    // ═══ Rechte ═══
    wp_set_current_user(0);
    [$s] = $rest('POST', "/orders/$o1/notes", ['text' => 'x']);
    $eq('ohne Anmeldung → 401', 401, $s);
    $sub = wp_insert_user(['user_login' => 'kunde_' . $sfx, 'user_pass' => wp_generate_password(), 'user_email' => "k2_$sfx@example.test", 'role' => 'subscriber']);
    wp_set_current_user($sub);
    [$s] = $rest('POST', "/orders/$ob/mark-paid");
    [$s2] = $rest('POST', '/orders/manual', ['event_id' => $ev, 'kind' => 'free', 'items' => [['cat_index' => 0, 'qty' => 1]], 'customer' => ['first_name' => 'X', 'email' => $mail]]);
    $eq('Kunde ohne Team-Rolle → 403', [403, 403], [$s, $s2]);
    wp_set_current_user($adm->ID);
    wp_delete_user($sub);
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    remove_filter('wp_mail', $cap, 1); remove_filter('pre_wp_mail', '__return_true', 99);
    foreach (array_filter($orders) as $oid) {
        foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => (string) $oid]) as $t) wp_delete_post($t, true);
        $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $oid]);
        $wpdb->delete($wpdb->prefix . 'tix_order_notes', ['order_id' => $oid]);
        $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $oid]);
    }
    if ($ev) wp_delete_post($ev, true);
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
