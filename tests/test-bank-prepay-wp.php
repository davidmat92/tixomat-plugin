<?php
/**
 * Vorkasse per Überweisung in der App-/Homepage-Kasse (1.38.915, Vertrag uebergaben/vertrag-vorkasse-ueberweisung-2026-11-23.md):
 * Frist-Regel, Angebot nur mit supports ["bank"], Bestellung on-hold mit Bankdaten, Mail mit Frist, Erinnerung, Auto-Storno
 * (Bestand zurück), Frist ändern, /orders-Felder. wp eval-file …/tests/test-bank-prepay-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Bank_Prepay')) { echo "Tixomat ab 1.38.915 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = json_decode(wp_json_encode(rest_get_server()->response_to_data($res, false)), true); return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : '']; };
$B = 'TIX_Bank_Prepay';
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$orig = get_option('tix_bp_test_settings', null); // erster Lauf hat die Einstellungen schon gesetzt (tix_get_settings() cached je Prozess)
$keep = ['tix_settings' => $orig !== null ? $orig : get_option('tix_settings'), 'tix_invoices_enabled' => get_option('tix_invoices_enabled', null), 'tix_demo_instance' => get_option('tix_demo_instance', null)];
$posts = []; $orders = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand(20, 200);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tix\\_app\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_tix\\_app\\_rl\\_%'");
$tz = new DateTimeZone('Europe/Berlin');
$ts = function ($s) use ($tz) { return (new DateTimeImmutable($s, $tz))->getTimestamp(); };
$mails = []; $mh = function ($pre, $atts) use (&$mails) { $mails[] = $atts; return true; };
try {
    // ── Reine Regel ──
    $o = $ts('2026-11-23 12:00');
    $r = $B::due_for($o, []);
    $eq('Ohne Event: Bestelltag + 7, Ende des Tages', [true, '2026-11-30', $ts('2026-11-30 23:59:59')], [$r['allowed'], $r['due_date'], $r['due_at']]);
    $eq('Event in 20 Tagen: 7 Tage', '2026-11-30', $B::due_for($o, [$ts('2026-12-13 20:00')])['due_date']);
    $eq('Event am 28.11. 20 Uhr: Event − 2 Tage = 26.11.', '2026-11-26', $B::due_for($o, [$ts('2026-11-28 20:00')])['due_date']);
    $eq('Frühestes Event zählt', '2026-11-26', $B::due_for($o, [$ts('2026-12-20 20:00'), $ts('2026-11-28 20:00')])['due_date']);
    $eq('Event < 72 h: keine Vorkasse; genau 72 h: ja (Frist am Folgetag)', [false, 'too_close', true, '2026-11-24'],
        [$B::due_for($o, [$o + 71 * 3600])['allowed'], $B::due_for($o, [$o + 71 * 3600])['reason'], $B::due_for($o, [$o + 72 * 3600])['allowed'], $B::due_for($o, [$o + 72 * 3600])['due_date']]);
    $eq('Label', 'bis 30.11.2026', $B::label('2026-11-30'));

    // ── Aufbau ──
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    update_option('tix_invoices_enabled', '0'); update_option('tix_demo_instance', '0', false);
    $s = (array) get_option('tix_settings', []);
    update_option('tix_settings', array_merge($s, ['bank_transfer_enabled' => 1, 'bank_iban' => 'DE89370400440532013000', 'bank_holder' => 'Muster Events GmbH', 'bank_bic' => 'COBADEFFXXX', 'bank_name' => 'Commerzbank']));
    $org = 0;
    if ($multi) { $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'Vorkasse Veranstalter']); $posts[] = $org; update_post_meta($org, '_tix_org_approval', 'approved'); }
    $mk = function ($t, $days) use ($org, &$posts) { $e = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => $t, 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() + $days * DAY_IN_SECONDS), '_tix_time_start' => '20:00',
        '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1', '_tix_ticket_categories' => [['name' => 'Normal', 'price' => 20, 'qty' => 50]]]]); $posts[] = $e; return $e; };
    $ev = $mk('Vorkasse Abend', 30); $evNear = $mk('Vorkasse Morgen', 1);
    if (!$B::enabled()) { update_option('tix_bp_test_settings', $keep['tix_settings'], false); $keep = []; echo "      Überweisung eingeschaltet – bitte denselben Test noch einmal starten (Einstellungs-Cache).\n"; throw new Exception('skip'); }
    delete_option('tix_bp_test_settings');
    wp_set_current_user(0);
    $quote = function ($eid, $sup = true) use ($rest) { return $rest('POST', '/customer/cart/quote', ['event_id' => $eid, 'items' => [['index' => 0, 'qty' => 2]]] + ($sup ? ['supports' => ['bank']] : [])); };
    $ids = function ($d) { return array_column((array) ($d['payment_methods'] ?? []), 'id'); };
    [$st, $d] = $quote($ev, false);
    $eq('Alter Client (ohne supports): keine Vorkasse', false, in_array('bank', $ids($d), true));
    [$st, $d] = $quote($ev);
    $bm = array_values(array_filter((array) $d['payment_methods'], function ($m) { return ($m['id'] ?? '') === 'bank'; }))[0] ?? [];
    $eq('Neuer Client: bank mit Titel, due_date, due_at', [200, 'Überweisung (Vorkasse)', wp_date('Y-m-d', time() + 7 * DAY_IN_SECONDS), true], [$st, $bm['title'] ?? '', $bm['due_date'] ?? '', !empty($bm['due_at'])]);
    [, $d] = $quote($evNear);
    $eq('Event morgen: keine Vorkasse', false, in_array('bank', $ids($d), true));
    $order = function ($eid, $pm, $sup = true) use ($rest, &$orders) {
        $b = ['event_id' => $eid, 'items' => [['index' => 0, 'qty' => 2]], 'billing' => ['first_name' => 'Vera', 'last_name' => 'Kasse', 'email' => 'vk_' . wp_rand(10000, 99999) . '@example.test', 'address_1' => 'Hauptstr. 1', 'postcode' => '40789', 'city' => 'Monheim', 'country' => 'DE'],
            'payment_method' => $pm, 'accept_terms' => 1, 'idempotency_token' => wp_generate_password(20, false)] + ($sup ? ['supports' => ['bank']] : []);
        [$st, $d, $c] = $rest('POST', '/customer/orders', $b);
        $oid = intval($d['order']['id'] ?? 0); if ($oid) $orders[] = $oid;
        return [$st, $d, $c, $oid];
    };
    [$st, , $c] = $order($ev, 'bank', false);
    $eq('Bestellung bank ohne supports → 400 tix_payment_method', [400, 'tix_payment_method'], [$st, $c]);
    [$st, , $c] = $order($evNear, 'bank');
    $eq('Bestellung bank bei Event morgen → 400', [400, 'tix_payment_method'], [$st, $c]);
    add_filter('pre_wp_mail', $mh, 1, 2);
    [$st, $d, $c, $oid] = $order($ev, 'bank');
    $bk = $d['order']['bank'] ?? [];
    $eq('Bestellung bank: on-hold, awaiting_payment, payment_url leer, keine Tickets', [true, 'on-hold', true, '', []], [in_array($st, [200, 201], true), $d['order']['status'] ?? '', !empty($d['order']['awaiting_payment']), $d['order']['payment_url'] ?? 'x', $d['order']['tickets'] ?? null]);
    $eq('Bankdaten: Empfänger, IBAN gruppiert, BIC, Betrag, Verwendungszweck = Bestellnummer, Frist, Status', ['Muster Events GmbH', 'DE89 3704 0044 0532 0130 00', 'COBADEFFXXX', 40.0, (string) ($d['order']['order_number'] ?? '-'), wp_date('Y-m-d', time() + 7 * DAY_IN_SECONDS), 'awaiting'],
        [$bk['holder'] ?? '', $bk['iban'] ?? '', $bk['bic'] ?? '', round(floatval($bk['amount'] ?? 0) - floatval($d['order']['total'] ?? 0) + 40, 2), $bk['reference'] ?? '', $bk['due_date'] ?? '', $bk['status'] ?? '']);
    $mail = implode("\n", array_map(function ($m) { return (string) ($m['message'] ?? ''); }, $mails));
    $eq('Mail „Bestellung wird geprüft“ mit Zahlungsfrist und Storno-Hinweis', [true, true], [strpos($mail, 'Zahlungsfrist') !== false, strpos($mail, 'automatisch storniert') !== false]);
    $get = function ($id, $key) use ($rest) {
        if (class_exists('TIX_Co_Sale') && TIX_Co_Sale::is_local_id($id)) { $m = new ReflectionMethod('TIX_App_Checkout', 'order_response'); $m->setAccessible(true); $r = $m->invoke(null, $id, ''); return [$r->get_status(), json_decode(wp_json_encode($r->get_data()), true)]; }
        return $rest('GET', "/customer/orders/$id", null, ['key' => $key]);
    };
    [$st, $d2] = $get($oid, (string) ($d['order']['key'] ?? ''));
    $eq('GET /customer/orders/{id}: bank + awaiting_payment', [200, true, 'awaiting'], [$st, !empty($d2['order']['awaiting_payment']), $d2['order']['bank']['status'] ?? '']);
    [$st, $dc] = $order($ev, 'free');
    // Studio-Felder
    wp_set_current_user($adm);
    [$st, $od] = $rest('GET', "/orders/$oid");
    $eq('/orders/{id}: awaiting_payment, bank_due_date, bank_status', [200, true, wp_date('Y-m-d', time() + 7 * DAY_IN_SECONDS), 'awaiting'], [$st, !empty($od['order']['awaiting_payment']), $od['order']['bank_due_date'] ?? '', $od['order']['bank_status'] ?? '']);
    // Frist ändern
    $nd = wp_date('Y-m-d', time() + 10 * DAY_IN_SECONDS);
    [$st, $dd] = $rest('POST', "/orders/$oid/bank-due", ['due_date' => $nd]);
    $eq('Frist ändern: 200, neues Datum' . ($multi ? ' (Plattform-Admin)' : ''), [200, $nd], [$st, $dd['bank_due_date'] ?? '']);
    $eq('Frist ändern: Vergangenheit / nach Event-Beginn → 400', [400, 400], [$rest('POST', "/orders/$oid/bank-due", ['due_date' => '2020-01-01'])[0], $rest('POST', "/orders/$oid/bank-due", ['due_date' => wp_date('Y-m-d', time() + 40 * DAY_IN_SECONDS)])[0]]);
    // Erinnerung
    $mails = [];
    $due = $B::get($oid);
    $B::$now = intval($due['due_at']) - DAY_IN_SECONDS;
    $c1 = $B::cron(); $c2 = $B::cron();
    $eq('Erinnerung 2 Tage vor Fristende: einmal', [1, 0, 1], [$c1['reminded'], $c2['reminded'], count(array_filter($mails, function ($m) { return strpos((string) ($m['subject'] ?? ''), 'Erinnerung') !== false; }))]);
    // Auto-Storno
    $mails = [];
    $taken = TIX_Object_Data::read('_tix_order_stock_taken', $oid, false);
    $B::$now = intval($due['due_at']) + 60;
    $c3 = $B::cron(); $B::$now = null;
    $stt = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tix_orders WHERE id = %d", $oid));
    $eq('Nach Fristende: storniert, expired_at, Bestand zurückgegeben (falls abgezogen), Storno-Mail „Zahlungsfrist abgelaufen“', [1, 'cancelled', true, true, 1],
        [$c3['expired'], $stt, !empty($B::get($oid)['expired_at']), !is_array($taken) || is_array(TIX_Object_Data::read('_tix_order_stock_restored', $oid, false)), count(array_filter($mails, function ($m) { return strpos((string) ($m['subject'] ?? ''), 'Zahlungsfrist abgelaufen') !== false; }))]);
    $notes = (string) wp_json_encode($wpdb->get_col($wpdb->prepare("SELECT note FROM {$wpdb->prefix}tix_order_notes WHERE order_id = %d", $oid)));
    $eq('Verlauf: Frist abgelaufen – automatisch storniert', true, strpos($notes, 'automatisch storniert') !== false);
    wp_set_current_user(0);
    [, $d3] = $get($oid, (string) ($d['order']['key'] ?? ''));
    $eq('Käufer sieht bank.status expired', ['expired', false], [$d3['order']['bank']['status'] ?? '', !empty($d3['order']['awaiting_payment'])]);
    // Bestellungen ohne Frist (WordPress-Kasse) fasst der Cron nicht an
    $o2 = $order($ev, 'bank')[3];
    TIX_Object_Data::remove($B::KEY, $o2);
    $B::$now = time() + 60 * DAY_IN_SECONDS; $B::cron(); $B::$now = null;
    $eq('Ohne Frist (wie WordPress-Kasse): bleibt on-hold', 'on-hold', (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tix_orders WHERE id = %d", $o2)));
    wp_set_current_user($adm);
    [, $pp] = $rest('GET', '/studio/payments');
    if (!$multi) $eq('/studio/payments: bank.hint, ready', [true, true], [($pp['providers']['bank']['hint'] ?? ($pp['bank']['hint'] ?? '')) !== '', (bool) ($pp['providers']['bank']['ready'] ?? ($pp['bank']['ready'] ?? false))]);
} catch (Exception $e) {
    if ($e->getMessage() !== 'skip') throw $e;
} finally {
    remove_filter('pre_wp_mail', $mh, 1);
    $B::$now = null;
    foreach ($orders as $oid) {
        foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $oid]) as $t) wp_delete_post($t, true);
        foreach (['tix_order_items', 'tix_order_notes'] as $tb) $wpdb->delete($wpdb->prefix . $tb, ['order_id' => $oid]);
        $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $oid]);
        foreach (['_tix_bank_due', '_tix_order_stock_taken', '_tix_order_stock_restored', '_tix_order_fields'] as $k) TIX_Object_Data::remove($k, $oid);
        delete_option('_tix_order_source_' . $oid);
    }
    foreach (array_reverse($posts) as $p) wp_delete_post($p, true);
    foreach ($keep as $k => $v) { if ($v === null) delete_option($k); else update_option($k, $v); }
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
