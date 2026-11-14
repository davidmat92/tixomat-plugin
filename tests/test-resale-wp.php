<?php
/**
 * Zweitmarkt (Weiterverkauf, §223). Nur lokal: bash tests/test-resale.sh <container> (setzt einen Test-Mollie-Schlüssel,
 * weil tix_get_settings() je Prozess zwischenspeichert) – läuft auf Einzel-Seite und Plattform.
 * Netz: Mollie wird per pre_http_request nachgebildet (Zahlung + Erstattung), Mails abgefangen.
 */
if (!defined('ABSPATH') || !class_exists('TIX_Resale')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function rs_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function rs_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
TIX_Order::create_tables(); TIX_Resale::maybe_install();
delete_option('tix_resale_settings'); // Rest früherer Läufe
if (class_exists('TIX_Ticket_Fields')) TIX_Ticket_Fields::maybe_create();
add_filter('pre_wp_mail', '__return_true');
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_resale\\_rl\\_%' OR option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_%'"); wp_cache_flush();
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
rs_eq('Test-Mollie-Schlüssel gesetzt (über test-resale.sh)', true, TIX_Gateway_Mollie::is_available());

// Netz: Mollie-Erstattung steuerbar ($GLOBALS['rs_refund'] = ok | fail), Rest übernimmt mu-mollietest bzw. diese Attrappe
$GLOBALS['rs_refund'] = 'ok'; $GLOBALS['rs_refunds'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, 'https://api.mollie.com/v2') !== 0) return $pre;
    $r = function ($code, $body) { return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null]; };
    if (preg_match('#/payments/([^/]+)/refunds$#', $url, $m)) {
        $b = json_decode((string) ($args['body'] ?? ''), true);
        $GLOBALS['rs_refunds'][] = [$m[1], $b['amount']['value'] ?? null];
        return $GLOBALS['rs_refund'] === 'ok' ? $r(201, ['id' => 're_test', 'status' => 'pending']) : $r(422, ['detail' => 'The payment is too old to be refunded']);
    }
    if (strpos($url, '/methods') !== false) return $r(200, ['_embedded' => ['methods' => []], 'count' => 0]);
    if (preg_match('#/payments$#', $url)) { $b = json_decode((string) $args['body'], true); $oid = intval($b['metadata']['order_id'] ?? 0); return $r(201, ['id' => 'tr_rs' . $oid, 'status' => 'open', '_links' => ['checkout' => ['href' => 'https://pay.mollie.test/' . $oid]]]); }
    return $r(404, ['detail' => 'test']);
}, 99, 3); // nach mu-mollietest: letzte Antwort zählt

$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $orders = [];
$owner = wp_insert_user(['user_login' => "rs_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "rs_owner_$sfx@example.test", 'role' => $multi ? 'tix_organizer' : 'administrator']); $users[] = $owner;
$other = wp_insert_user(['user_login' => "rs_other_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "rs_other_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $other;
$mkcust = function ($n) use (&$users, $sfx) { $id = wp_insert_user(['user_login' => "rs_{$n}_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "rs_{$n}_$sfx@example.test", 'role' => 'subscriber', 'first_name' => ucfirst($n), 'last_name' => 'Tester']); $users[] = $id; TIX_App_Account::mark_confirmed(get_userdata($id)); return $id; };
$seller = $mkcust('seller'); $buyer = $mkcust('buyer'); $buyer2 = $mkcust('buyertwo');
$org = 0; $org2 = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'RS Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'RS Andere', 'meta_input' => ['_tix_org_user_id' => $other]]); $posts[] = $org2;
}
$mkev = function ($t, $o, $cats, $days = 5) use (&$posts) { $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "RS $t", 'meta_input' => ['_tix_organizer_id' => $o, '_tix_date_start' => wp_date('Y-m-d', time() + $days * DAY_IN_SECONDS), '_tix_time_start' => '20:00', '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1', '_tix_ticket_categories' => $cats]]); $posts[] = $id; return $id; };
$cats = [['name' => 'Regulär', 'price' => 20, 'qty' => 4, 'stock' => 4], ['name' => 'VIP', 'price' => 50, 'qty' => 10, 'stock' => 10]];
$ev = $mkev("Gala $sfx", $org, $cats);
// Bestellung des Verkäufers (bezahlt mit Mollie) → Tickets über den normalen Abschluss
$mkorder = function ($uid, $eid, $ci, $qty, $price, $pm = 'mollie') use ($wpdb, &$orders, $cats) {
    $u = get_userdata($uid);
    $wpdb->insert($wpdb->prefix . 'tix_orders', ['order_number' => 'RS-' . wp_rand(100000, 999999), 'status' => 'pending', 'total' => $price * $qty, 'subtotal' => $price * $qty, 'tax' => 0, 'discount' => 0,
        'payment_method' => $pm, 'payment_method_title' => $pm, 'billing_first_name' => $u->first_name, 'billing_last_name' => $u->last_name, 'billing_email' => $u->user_email,
        'customer_id' => $uid, 'order_key' => 'tix_' . wp_generate_password(16, false), 'date_created' => current_time('mysql')]);
    $oid = intval($wpdb->insert_id); $orders[] = $oid;
    $wpdb->insert($wpdb->prefix . 'tix_order_items', ['order_id' => $oid, 'event_id' => $eid, 'quantity' => $qty, 'total' => $price * $qty, 'name' => 'RS', 'cat_name' => $cats[$ci]['name'], 'meta' => wp_json_encode(['cat_index' => $ci])]);
    update_option('_tix_mollie_payment_' . $oid, 'tr_seller' . $oid, false);
    TIX_Native_Checkout::update_order_status($oid, 'completed', 'mollie');
    return [$oid, array_map('intval', get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $oid, 'orderby' => 'ID', 'order' => 'ASC']))];
};
$setstock = function ($eid, $ci, $n) { $c = get_post_meta($eid, '_tix_ticket_categories', true); $c[$ci]['stock'] = $n; update_post_meta($eid, '_tix_ticket_categories', $c); };
list($so, $st) = $mkorder($seller, $ev, 0, 3, 20.0);
list($so2, $st2) = $mkorder($seller, $ev, 1, 1, 50.0);
rs_eq('Verkäufer hat 3 Regulär + 1 VIP', [3, 1], [count($st), count($st2)]);
$setstock($ev, 0, 0); // Regulär ausverkauft

// ═══ Regeln (reine Funktionen) ═══
list($r0) = TIX_Resale_Rules::sanitize([]);
rs_eq('Vorgabe: aus, nur ausverkauft, Originalpreis, 2 h', [false, true, 'original', 2], [$r0['enabled'], $r0['sold_out_only'], $r0['max_price']['mode'], $r0['deadline_hours']]);
rs_eq('Prozent außerhalb 100–200 → max_price.percent', 'max_price.percent', TIX_Resale_Rules::sanitize(['max_price' => ['mode' => 'percent', 'percent' => 250]])[1]['field'] ?? '');
rs_eq('Frist über 168 h → deadline_hours', 'deadline_hours', TIX_Resale_Rules::sanitize(['deadline_hours' => 200])[1]['field'] ?? '');
rs_eq('Höchstpreis 120 % von 20 = 24', 24.0, TIX_Resale_Rules::max_price(20, ['max_price' => ['mode' => 'percent', 'percent' => 120]]));
rs_eq('Frist = Beginn − 2 h', 1000000 - 7200, TIX_Resale_Rules::deadline_ts(1000000, $r0));
rs_eq('Preis über Originalpreis → tix_resale_price', 'tix_resale_price', TIX_Resale_Rules::price_error(21, 20, $r0)['code'] ?? '');
rs_eq('Preis mit 3 Nachkommastellen → Fehler', true, TIX_Resale_Rules::price_error(10.555, 20, $r0) !== null);
rs_eq('Auszahlungsweg: Mollie mit genug Rest → gateway, Kasse → fallback, Guthaben → wallet_back', ['gateway', 'fallback', 'wallet_back', 'fallback'],
    [TIX_Resale_Rules::payout_way('mollie', 60, 20), TIX_Resale_Rules::payout_way('pos_cash', 60, 20), TIX_Resale_Rules::payout_way('wallet', 0, 20), TIX_Resale_Rules::payout_way('stripe', 10, 20)]);
rs_eq('Zustandsautomat: sold ist endgültig, locked → open erlaubt', [false, true], [TIX_Resale_Rules::can_move('sold', 'open'), TIX_Resale_Rules::can_move('locked', 'open')]);

// ═══ Einstellungen (Studio-Routen) ═══
wp_set_current_user($seller);
[$s] = rs_rest('GET', '/studio/resale/settings');
rs_eq('Gast ohne Rolle → keine Studio-Einstellungen', true, in_array($s, [401, 403], true));
wp_set_current_user($owner);
[$s, $d] = rs_rest('GET', "/public/events/$ev/resale");
rs_eq('Vorgabe aus → öffentlich enabled=false, reason disabled', [200, false, 'disabled'], [$s, $d['enabled'] ?? null, $d['reason'] ?? '']);
[$s, $d, $c] = rs_rest('POST', '/studio/resale/settings', ['max_price' => ['mode' => 'percent', 'percent' => 50]]);
rs_eq('Ungültiger Prozentwert → 400 tix_invalid_field', [400, 'tix_invalid_field', 'max_price.percent'], [$s, $c, $d['data']['field'] ?? '']);
[$s, $d] = rs_rest('POST', '/studio/resale/settings', ['enabled' => true]);
rs_eq('Seite: an, Rest Vorgabe', [200, true, true, 'original', 2], [$s, $d['enabled'] ?? null, $d['sold_out_only'] ?? null, $d['max_price']['mode'] ?? '', $d['deadline_hours'] ?? 0]);
[$s, $d] = rs_rest('GET', "/events/$ev/resale/settings");
rs_eq('Event folgt der Seite, Kategorien mit ausverkauft-Kennzeichen', [200, true, true, [true, false]], [$s, $d['inherit'] ?? null, $d['rules']['enabled'] ?? null, array_column((array) ($d['categories'] ?? []), 'sold_out')]);
if ($multi) {
    wp_set_current_user($other);
    [$s] = rs_rest('GET', "/events/$ev/resale/settings");
    rs_eq('Anderer Veranstalter → kein Zugriff auf Event-Regeln', true, in_array($s, [403, 404], true));
    [$s, $d] = rs_rest('GET', '/studio/resale/settings');
    rs_eq('Regeln gelten je Veranstalter (anderer: Vorgabe aus)', false, $d['enabled'] ?? null);
    wp_set_current_user($owner);
}

// ═══ Anbieten ═══
wp_set_current_user($seller);
$t1 = $st[0]; $t2 = $st[1]; $t3 = $st[2]; $tv = $st2[0];
[$s, $d] = rs_rest('GET', "/customer/tickets/$t1/resale");
rs_eq('Eigenes Ticket, Kategorie ausverkauft → allowed, Höchstpreis 20', [200, true, 20.0, 1.0], [$s, $d['allowed'] ?? null, $d['max_price'] ?? null, $d['min_price'] ?? null]);
[$s, $d] = rs_rest('GET', "/customer/tickets/$tv/resale");
rs_eq('VIP nicht ausverkauft → not_sold_out mit Text', [false, 'not_sold_out', true], [$d['allowed'] ?? null, $d['reason'] ?? '', ($d['reason_text'] ?? '') !== '']);
[$s, $d, $c] = rs_rest('POST', "/customer/tickets/$t1/resale", ['price' => 25, 'accept_terms' => true]);
rs_eq('Über Höchstpreis → 422 tix_resale_price mit max_price', [422, 'tix_resale_price', 20.0], [$s, $c, $d['data']['max_price'] ?? null]);
[$s, $d, $c] = rs_rest('POST', "/customer/tickets/$t1/resale", ['price' => 18]);
rs_eq('Ohne Bedingungen → 400 tix_resale_terms', [400, 'tix_resale_terms'], [$s, $c]);
[$s, $d, $c] = rs_rest('POST', "/customer/tickets/$tv/resale", ['price' => 40, 'accept_terms' => true]);
rs_eq('Nicht ausverkauft → 403 tix_resale_not_allowed', [403, 'tix_resale_not_allowed', 'not_sold_out'], [$s, $c, $d['data']['reason'] ?? '']);
wp_set_current_user($buyer);
[$s, $d, $c] = rs_rest('POST', "/customer/tickets/$t1/resale", ['price' => 18, 'accept_terms' => true]);
rs_eq('Fremdes Ticket → 404', 404, $s);
wp_set_current_user(0);
[$s] = rs_rest('GET', "/customer/tickets/$t1/resale");
rs_eq('Ohne Anmeldung → 401', 401, $s);
wp_set_current_user($seller);
[$s, $d] = rs_rest('POST', "/customer/tickets/$t1/resale", ['price' => 19, 'accept_terms' => true]);
rs_eq('Angebot angelegt (open)', [200, 'open', 19.0], [$s, $d['offer']['status'] ?? '', $d['offer']['price'] ?? null]);
$oid1 = intval($d['offer']['id'] ?? 0);
[$s, $d] = rs_rest('POST', "/customer/tickets/$t1/resale", ['price' => 18, 'accept_terms' => true]);
rs_eq('Preis ändern: gleiches Angebot', [$oid1, 18.0], [intval($d['offer']['id'] ?? 0), $d['offer']['price'] ?? null]);
rs_eq('Ticket mit offenem Angebot: nicht umschreibbar, Merker open', [false, 'open'], [TIX_Ticket_Transfer::can_transfer($t1), TIX_Resale::ticket_flag($t1)]);
$tl = TIX_App_Checkout::ticket_from_post($t1);
rs_eq('Ticketliste: resale_status open, Status bleibt valid', ['open', 'valid'], [$tl['resale_status'] ?? '', $tl['status'] ?? '']);
wp_set_current_user(0);
[$s, $d] = rs_rest('GET', "/public/events/$ev/resale");
$of = $d['offers'][0] ?? [];
rs_eq('Öffentlich: 1 Angebot, Regulär, total = Preis + Gebühr', [200, true, 1, 'Regulär', 18.0, round(18 + floatval($of['fee'] ?? 0), 2)], [$s, $d['visible'] ?? null, $d['count'] ?? 0, $of['category'] ?? '', $of['price'] ?? null, $of['total'] ?? null]);
rs_eq('Öffentlich: keine Verkäuferdaten (keine E-Mail, kein Name, kein Ticket)', [false, false, false], [strpos(wp_json_encode($d), '@') !== false, isset($of['seller']), isset($of['ticket_id'])]);
rs_eq('checkout_url zeigt auf die eigene Kasse mit ?resale=', true, strpos((string) ($of['checkout_url'] ?? ''), 'resale=' . $oid1) !== false);

// ═══ Kasse ═══
$items = [['resale_offer' => $oid1, 'qty' => 1]];
[$s, $d, $c] = rs_rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => $items]);
$fee = round(floatval($d['totals']['fee'] ?? 0), 2);
rs_eq('Angebot: Preis 18 + normale Gebühr, Zeile resale', [200, 18.0, round(18 + $fee, 2), true, $oid1], [$s, $d['totals']['subtotal'] ?? null, $d['totals']['total'] ?? null, $d['totals']['lines'][0]['resale'] ?? null, $d['resale']['offer_id'] ?? 0]);
rs_eq('Gebühr in Angebot und Kasse gleich', $fee, round(floatval($of['fee'] ?? -1), 2));
rs_eq('Keine Vorkasse beim Weiterverkauf', false, in_array('bank', array_column((array) ($d['payment_methods'] ?? []), 'id'), true));
[$s, $d, $c] = rs_rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => $items, 'coupon' => 'RABATT']);
rs_eq('Gutschein → 400 tix_resale_coupon', [400, 'tix_resale_coupon'], [$s, $c]);
[$s, $d, $c] = rs_rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => [['resale_offer' => $oid1, 'qty' => 1], ['index' => 1, 'qty' => 1]]]);
rs_eq('Gemischt mit normalen Tickets → 400 tix_resale_mixed', [400, 'tix_resale_mixed'], [$s, $c]);
wp_set_current_user($seller);
[$s, $d, $c] = rs_rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => $items]);
rs_eq('Eigenes Angebot → 400 tix_resale_own', [400, 'tix_resale_own'], [$s, $c]);
wp_set_current_user(0);
$billing = function ($uid) { $u = get_userdata($uid); return ['first_name' => $u->first_name ?: 'Gast', 'last_name' => 'Tester', 'address_1' => 'Weg 1', 'postcode' => '42103', 'city' => 'Wuppertal', 'country' => 'DE', 'email' => $u->user_email]; };
$order = function ($uid, $guest_email = null, array $extra = []) use ($ev, $items, $billing) {
    wp_set_current_user($guest_email === null ? $uid : 0);
    $b = $billing($uid); if ($guest_email !== null) $b['email'] = $guest_email;
    return rs_rest('POST', '/customer/orders', $extra + ['event_id' => $ev, 'items' => $items, 'billing' => $b, 'payment_method' => 'mollie', 'accept_terms' => true, 'idempotency_token' => 'rs' . wp_generate_password(12, false)]);
};
[$s, $d, $c] = $order($buyer, get_userdata($seller)->user_email);
rs_eq('Gast mit der E-Mail des Verkäufers → tix_resale_own', [400, 'tix_resale_own'], [$s, $c]);
[$s, $d, $c] = $order($buyer, null, ['payment_method' => 'bank']);
rs_eq('Vorkasse → abgelehnt', 400, $s);
[$s, $d, $c] = $order($buyer);
$bo1 = intval($d['order']['id'] ?? 0); $orders[] = $bo1;
rs_eq('Käufer 1: Bestellung angelegt, Zahlung offen, Angebot gesperrt', [200, 'pending', 'locked', $bo1], [$s, $d['order']['status'] ?? '', TIX_Resale::get($oid1)['status'] ?? '', intval(TIX_Resale::get($oid1)['lock_order_id'] ?? 0)]);
[$s, $d, $c] = $order($buyer2);
rs_eq('Doppelkauf: zweiter Käufer während der Zahlung → 409 tix_resale_taken', [409, 'tix_resale_taken'], [$s, $c]);
// Zwei Sperren direkt hintereinander (bedingtes UPDATE): nur eine gewinnt
$cart = TIX_Resale::build_cart($ev, $items);
rs_eq('Gesperrtes Angebot ist nicht in den Warenkorb legbar', 'tix_resale_taken', is_wp_error($cart) ? $cart->get_error_code() : '');
wp_set_current_user(0);
[$s, $d] = rs_rest('GET', "/public/events/$ev/resale");
rs_eq('Gesperrt: öffentlich nicht gelistet', 0, $d['count'] ?? -1);
wp_set_current_user($seller);
[$s, $d, $c] = rs_rest('POST', "/customer/tickets/$t1/resale/withdraw");
rs_eq('Zurückziehen während des Kaufs → 409 tix_resale_reserved', [409, 'tix_resale_reserved'], [$s, $c]);
[$s, $d] = rs_rest('GET', "/customer/tickets/$t1/resale");
rs_eq('Verkäufer sieht reserved', 'reserved', $d['offer']['status'] ?? '');
// Zahlungsabbruch → Angebot wieder frei
TIX_Native_Checkout::update_order_status($bo1, 'failed', 'mollie');
rs_eq('Zahlungsabbruch: Angebot wieder offen, ohne Sperre', ['open', 0], [TIX_Resale::get($oid1)['status'] ?? '', intval(TIX_Resale::get($oid1)['lock_order_id'] ?? -1)]);
wp_set_current_user(0); $cart2 = TIX_Resale::build_cart($ev, $items); $two = is_wp_error($cart2) ? $cart2 : TIX_Resale::lock($cart2, 'x@example.test');
$three = TIX_Resale::lock(['resale' => $oid1], 'y@example.test');
rs_eq('Zwei Sperren nacheinander: zweite → tix_resale_taken', [true, 'tix_resale_taken'], [is_array($two), is_wp_error($three) ? $three->get_error_code() : '']);
if (is_array($two)) TIX_Resale::unlock($two);
if (is_array($three)) TIX_Resale::unlock($three);
rs_eq('Sperre gelöst → wieder offen', 'open', TIX_Resale::get($oid1)['status'] ?? '');

// Kauf durch Käufer 2, Zahlung kommt an
$sold_before = intval(TIX_REST_API::ticket_counts($ev)['total'] ?? 0);
$stock_before = $wpdb->get_var($wpdb->prepare("SELECT available FROM {$wpdb->prefix}tix_stock WHERE event_id = %d AND cat_index = 0", $ev));
[$s, $d] = $order($buyer2);
$bo2 = intval($d['order']['id'] ?? 0); $orders[] = $bo2;
$GLOBALS['rs_refunds'] = [];
TIX_Native_Checkout::update_order_status($bo2, 'completed', 'mollie');
$o = TIX_Resale::get($oid1);
$new = intval($o['new_ticket_id'] ?? 0);
rs_eq('Bezahlt: Angebot sold, neues Ticket verknüpft', ['sold', $bo2, true], [$o['status'] ?? '', intval($o['buyer_order_id'] ?? 0), $new > 0]);
rs_eq('Altes Ticket resold, neues gültig mit neuem Code, gleiche Kategorie', ['resold', 'valid', true, 0],
    [get_post_meta($t1, '_tix_ticket_status', true), get_post_meta($new, '_tix_ticket_status', true), get_post_meta($new, '_tix_ticket_code', true) !== get_post_meta($t1, '_tix_ticket_code', true), intval(get_post_meta($new, '_tix_ticket_cat_index', true))]);
rs_eq('Neues Ticket gehört dem Käufer', get_userdata($buyer2)->user_email, (string) get_post_meta($new, '_tix_ticket_owner_email', true));
rs_eq('Auszahlung: Teil-Erstattung 18,00 € auf die Mollie-Zahlung des Verkäufers', [[['tr_seller' . $so, '18.00']], 'original', 'paid', 18.0],
    [$GLOBALS['rs_refunds'], $o['payout_method'] ?? '', $o['payout_status'] ?? '', floatval($o['payout_amount'] ?? 0)]);
rs_eq('Erstattung an der Verkäufer-Bestellung gebucht, Bestellung bleibt bezahlt', [18.0, 'completed'], [TIX_Order_Admin::refunded_total($so), (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tix_orders WHERE id = %d", $so))]);
rs_eq('Bestand gleich, verkaufte Tickets gleich', [$stock_before, $sold_before], [$wpdb->get_var($wpdb->prepare("SELECT available FROM {$wpdb->prefix}tix_stock WHERE event_id = %d AND cat_index = 0", $ev)), intval(TIX_REST_API::ticket_counts($ev)['total'] ?? 0)]);
rs_eq('_tix_sold_total = 4 (3 + 1 VIP, Weiterverkauf zählt nicht doppelt)', 4, intval(TIX_Sync::recompute_event_sold_count($ev)));
rs_eq('Ereignis tix_ticket_resold gefeuert', true, did_action('tix_ticket_resold') >= 1);
$tl = TIX_App_Checkout::ticket_from_post($t1);
rs_eq('Verkäufer-Liste: altes Ticket „transferred“ + resale_status sold (Apps im Store)', ['transferred', 'sold'], [$tl['status'] ?? '', $tl['resale_status'] ?? '']);
wp_set_current_user($seller);
[$s, $d] = rs_rest('GET', "/customer/tickets/$t1/resale");
rs_eq('Verkäufer: offer sold, payout original/paid, nicht erneut anbietbar', ['sold', 'original', 'paid', false, 'resold'], [$d['offer']['status'] ?? '', $d['offer']['payout']['method'] ?? '', $d['offer']['payout']['status'] ?? '', $d['allowed'] ?? null, $d['reason'] ?? '']);
[$s, $d, $c] = rs_rest('POST', "/customer/tickets/$t1/resale", ['price' => 10, 'accept_terms' => true]);
rs_eq('Weiterverkauftes Ticket erneut anbieten → 403', 403, $s);
// Einlass: altes Ticket ungültig
wp_set_current_user($owner);
[$s, $d] = rs_rest('POST', '/checkin/scan', ['code' => (string) get_post_meta($t1, '_tix_ticket_code', true), 'event_id' => $ev]);
rs_eq('Scanner: altes Ticket → cancelled/resold, nicht eingecheckt', ['cancelled', true, '0'], [$d['status'] ?? '', $d['resold'] ?? false, (string) get_post_meta($t1, '_tix_ticket_checked_in', true)]);
$off = rest_do_request(new WP_REST_Request('GET', "/tixomat/v1/checkin/$ev/offline"));
$offd = $off->get_data(); $by = [];
foreach ((array) ($offd['tickets'] ?? []) as $x) $by[intval($x['id'])] = $x['st'];
rs_eq('Offline-Einlass-Liste: altes Ticket ungültig, neues gültig', ['cancelled', 'valid'], [$by[$t1] ?? ($off->get_status() . '?'), $by[$new] ?? '?']);
// Ticket des Käufers kann er selbst wieder anbieten (Grenze bleibt der Originalpreis 20)
wp_set_current_user($buyer2);
[$s, $d] = rs_rest('GET', "/customer/tickets/$new/resale");
rs_eq('Käufer: eigenes neues Ticket anbietbar, Höchstpreis = erster Originalpreis', [true, 20.0], [$d['allowed'] ?? null, $d['max_price'] ?? null]);

// ═══ Erstattungsfehler → Rückfall (Gutschein bzw. Guthaben) ═══
wp_set_current_user($seller);
[$s, $d] = rs_rest('POST', "/customer/tickets/$t2/resale", ['price' => 15, 'accept_terms' => true]);
$oid2 = intval($d['offer']['id'] ?? 0);
$GLOBALS['rs_refund'] = 'fail';
[$s, $d] = $order($buyer, null, ['items' => [['resale_offer' => $oid2, 'qty' => 1]]]);
$bo3 = intval($d['order']['id'] ?? 0); $orders[] = $bo3;
TIX_Native_Checkout::update_order_status($bo3, 'completed', 'mollie');
$o = TIX_Resale::get($oid2);
rs_eq('Erstattung abgelehnt: verkauft, Auszahlung wartet (1. Versuch)', ['sold', 'pending', 1], [$o['status'] ?? '', $o['payout_status'] ?? '', intval($o['payout_tries'] ?? 0)]);
TIX_Resale::payout($oid2); TIX_Resale::payout($oid2);
$o = TIX_Resale::get($oid2);
$codes = get_option('tix_coupons', []); $vc = (string) ($o['payout_ref'] ?? '');
rs_eq('Nach 3 Versuchen: Rückfall Gutschein über 15 € (Guthaben aus)', ['voucher', 'paid', 15.0, 15.0], [$o['payout_method'] ?? '', $o['payout_status'] ?? '', floatval($o['payout_amount'] ?? 0), floatval($codes[$vc]['value'] ?? 0)]);
rs_eq('Rückfall als Erstattung gebucht (18 + 15)', 33.0, TIX_Order_Admin::refunded_total($so));
rs_eq('Erneuter Aufruf zahlt nicht doppelt', $vc, (string) (TIX_Resale::payout($oid2)['payout_ref'] ?? ''));
$GLOBALS['rs_refund'] = 'ok';
// Guthaben als Rückfall (wenn Guthaben aktiv)
if (method_exists('TIX_Wallet_Balance', 'set_site_enabled') && TIX_Wallet_Balance::ready()) {
    $prev_site = get_option(TIX_Wallet_Balance::OPT, null); $prev_plat = get_option('tix_wallet_platform', null);
    TIX_Wallet_Balance::set_site_enabled(true); if ($multi) update_option('tix_wallet_platform', '1');
    list($so3, $st3) = $mkorder($seller, $ev, 0, 1, 20.0, 'pos_cash');
    wp_set_current_user($seller);
    [$s, $d] = rs_rest('POST', "/customer/tickets/{$st3[0]}/resale", ['price' => 12, 'accept_terms' => true]);
    $oid3 = intval($d['offer']['id'] ?? 0);
    [$s, $d] = $order($buyer, null, ['items' => [['resale_offer' => $oid3, 'qty' => 1]]]);
    $bo4 = intval($d['order']['id'] ?? 0); $orders[] = $bo4;
    TIX_Native_Checkout::update_order_status($bo4, 'completed', 'mollie');
    $o = TIX_Resale::get($oid3);
    $w = TIX_Wallet_Balance::find_wallet($seller, 0);
    rs_eq('Barzahlung (Kasse) → Rückfall Guthaben 12 €', ['wallet', 'paid', 1200], [$o['payout_method'] ?? '', $o['payout_status'] ?? '', intval($w['balance_paid'] ?? 0)]);
    if ($prev_site === null) delete_option(TIX_Wallet_Balance::OPT); else update_option(TIX_Wallet_Balance::OPT, $prev_site);
    if ($prev_plat === null) delete_option('tix_wallet_platform'); else update_option('tix_wallet_platform', $prev_plat);
}

// ═══ Konflikt: Sperre abgelaufen, anderer kauft, erste Zahlung kommt danach ═══
wp_set_current_user($seller);
[$s, $d] = rs_rest('POST', "/customer/tickets/$t3/resale", ['price' => 16, 'accept_terms' => true]);
$oid4 = intval($d['offer']['id'] ?? 0);
[$s, $d] = $order($buyer, null, ['items' => [['resale_offer' => $oid4, 'qty' => 1]]]);
$bo5 = intval($d['order']['id'] ?? 0); $orders[] = $bo5;
TIX_Resale::$now = time() + 2 * HOUR_IN_SECONDS; TIX_Resale::expire_due(); TIX_Resale::$now = null;
rs_eq('Sperre nach Ablauf wieder frei', 'open', TIX_Resale::get($oid4)['status'] ?? '');
[$s, $d] = $order($buyer2, null, ['items' => [['resale_offer' => $oid4, 'qty' => 1]]]);
$bo6 = intval($d['order']['id'] ?? 0); $orders[] = $bo6;
TIX_Native_Checkout::update_order_status($bo6, 'completed', 'mollie');
TIX_Native_Checkout::update_order_status($bo5, 'completed', 'mollie');
$late = get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $bo5]);
rs_eq('Späte Zahlung: Angebot bleibt beim ersten Bezahlten, späte Bestellung erstattet + Ticket storniert',
    [$bo6, 'refunded', 'cancelled'], [intval(TIX_Resale::get($oid4)['buyer_order_id'] ?? 0), (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tix_orders WHERE id = %d", $bo5)), (string) get_post_meta($late[0] ?? 0, '_tix_ticket_status', true)]);

// ═══ Gescanntes Ticket, Einlass während Angebot, personalisiert, Frist, nur ausverkauft ═══
list($so4, $st4) = $mkorder($seller, $ev, 0, 3, 20.0);
TIX_Tickets::checkin_ticket($st4[0], 'test');
wp_set_current_user($seller);
[$s, $d] = rs_rest('GET', "/customer/tickets/{$st4[0]}/resale");
rs_eq('Eingechecktes Ticket → checked_in', [false, 'checked_in'], [$d['allowed'] ?? null, $d['reason'] ?? '']);
[$s, $d] = rs_rest('POST', "/customer/tickets/{$st4[1]}/resale", ['price' => 10, 'accept_terms' => true]);
$oid5 = intval($d['offer']['id'] ?? 0);
TIX_Tickets::checkin_ticket($st4[1], 'test');
rs_eq('Einlass mit offenem Angebot → Angebot zurückgezogen', ['withdrawn', 'checked_in'], [TIX_Resale::get($oid5)['status'] ?? '', TIX_Resale::get($oid5)['close_reason'] ?? '']);
if (class_exists('TIX_Ticket_Fields')) {
    update_post_meta($ev, TIX_Ticket_Fields::META, TIX_Ticket_Fields::sanitize(['personalize' => 'required']));
    [$s, $d] = rs_rest('POST', "/customer/tickets/{$st4[2]}/resale", ['price' => 10, 'accept_terms' => true]);
    $oid6 = intval($d['offer']['id'] ?? 0);
    [$s, $d, $c] = $order($buyer, null, ['items' => [['resale_offer' => $oid6, 'qty' => 1]]]);
    rs_eq('Personalisiert: ohne Inhaber → tix_holder_missing, Angebot bleibt frei', ['tix_holder_missing', 'open'], [$c, TIX_Resale::get($oid6)['status'] ?? '']);
    [$s, $d, $c] = $order($buyer, null, ['items' => [['resale_offer' => $oid6, 'qty' => 1]], 'holders' => [['index' => 0, 'first_name' => 'Neue', 'last_name' => 'Inhaberin']]]);
    $bo7 = intval($d['order']['id'] ?? 0); $orders[] = $bo7;
    TIX_Native_Checkout::update_order_status($bo7, 'completed', 'mollie');
    $nt = intval(TIX_Resale::get($oid6)['new_ticket_id'] ?? 0);
    rs_eq('Personalisiert: neues Ticket mit neuen Inhaberdaten', 'Neue Inhaberin', trim((string) (TIX_Ticket_Fields::holder($nt)['name'] ?? '')));
    delete_post_meta($ev, TIX_Ticket_Fields::META);
}
// Frist: 2 h vor Beginn
$ev2 = $mkev("Bald $sfx", $org, [['name' => 'Regulär', 'price' => 20, 'qty' => 2, 'stock' => 2]]);
list($so5, $st5) = $mkorder($seller, $ev2, 0, 2, 20.0); $setstock($ev2, 0, 0);
wp_set_current_user($seller);
[$s, $d] = rs_rest('POST', "/customer/tickets/{$st5[0]}/resale", ['price' => 10, 'accept_terms' => true]);
$oid7 = intval($d['offer']['id'] ?? 0);
TIX_Resale::$now = TIX_Resale::start_ts($ev2) - HOUR_IN_SECONDS;
rs_eq('1 h vor Beginn: nicht mehr anbietbar (deadline)', 'deadline', TIX_Resale::check_ticket($st5[1], ['user_id' => $seller, 'email' => get_userdata($seller)->user_email])['reason']);
TIX_Resale::expire_due();
$cart = TIX_Resale::build_cart($ev2, [['resale_offer' => $oid7, 'qty' => 1]]);
rs_eq('Offenes Angebot nach Frist: expired, Kasse → tix_resale_expired', ['expired', 'tix_resale_expired'], [TIX_Resale::get($oid7)['status'] ?? '', is_wp_error($cart) ? $cart->get_error_code() : '']);
TIX_Resale::$now = null;
// Frist je Event änderbar + nur-ausverkauft je Event abschaltbar
wp_set_current_user($owner);
[$s, $d] = rs_rest('POST', "/events/$ev/resale/settings", ['inherit' => false, 'rules' => ['enabled' => true, 'sold_out_only' => false, 'deadline_hours' => 24]]);
rs_eq('Event: eigene Regeln (nicht nur ausverkauft, 24 h)', [200, false, false, 24], [$s, $d['inherit'] ?? null, $d['rules']['sold_out_only'] ?? null, $d['rules']['deadline_hours'] ?? 0]);
wp_set_current_user($seller);
[$s, $d] = rs_rest('GET', "/customer/tickets/$tv/resale");
rs_eq('VIP jetzt anbietbar (nur-ausverkauft aus)', true, $d['allowed'] ?? null);
[$s, $d] = rs_rest('POST', "/customer/tickets/$tv/resale", ['price' => 45, 'accept_terms' => true]);
$oid8 = intval($d['offer']['id'] ?? 0);
wp_set_current_user($owner);
[$s, $d] = rs_rest('POST', "/events/$ev/resale/settings", ['inherit' => false, 'rules' => ['enabled' => true, 'sold_out_only' => false], 'categories_off' => [1]]);
rs_eq('Kategorie VIP abgeschaltet → offenes VIP-Angebot zurückgezogen, verkaufte bleiben', ['withdrawn', 'sold'], [TIX_Resale::get($oid8)['status'] ?? '', TIX_Resale::get($oid1)['status'] ?? '']);
[$s, $d, $c] = rs_rest('POST', "/events/$ev/resale/settings", ['inherit' => false, 'categories_off' => [9]]);
rs_eq('Unbekannte Kategorie → 400 tix_invalid_field', [400, 'categories_off'], [$s, $d['data']['field'] ?? '']);

// ═══ Studio-Übersicht ═══
[$s, $d] = rs_rest('GET', '/studio/resale', ['event' => $ev]);
$sold = array_values(array_filter((array) ($d['items'] ?? []), function ($x) { return $x['status'] === 'sold'; }));
rs_eq('Übersicht: Summen und Liste', [200, true, true], [$s, ($d['totals']['sold'] ?? 0) >= 3, count($sold) >= 3]);
$one = null; foreach ($sold as $x) if ($x['id'] === $oid1) $one = $x;
rs_eq('Übersicht: Code gekürzt, Namen kurz, Erstattung done/original, keine E-Mail', [true, 'Seller T.', 'Buyertwo T.', 'original', 'done', false],
    [strpos((string) ($one['ticket_code'] ?? ''), '••••') === 0, $one['seller']['name'] ?? '', $one['buyer']['name'] ?? '', $one['refund']['way'] ?? '', $one['refund']['state'] ?? '', strpos(wp_json_encode($d), '@') !== false]);
[$s, $d] = rs_rest('GET', '/studio/resale', ['event' => $ev, 'status' => 'closed', 'per_page' => 1]);
rs_eq('Filter closed + Seiten (totals gelten für alle)', [true, 1, true], [count((array) $d['items']) === 1 && in_array($d['items'][0]['status'], ['withdrawn', 'expired'], true), count((array) $d['items']), ($d['totals']['sold'] ?? 0) >= 3]);
if ($multi) {
    wp_set_current_user($other);
    [$s, $d] = rs_rest('GET', '/studio/resale');
    rs_eq('Anderer Veranstalter sieht keine fremden Angebote', 0, count(array_filter((array) ($d['items'] ?? []), function ($x) use ($ev) { return $x['event_id'] === $ev; })));
    wp_set_current_user($owner);
}

// ═══ RS5: Durchgriff der Plattform bei der Quelle (/partner/resale) ═══
if (class_exists('TIX_Partners') && TIX_Partners::source_api_enabled()) {
    $pr = function ($m, $route, array $b = [], $key = null) {
        $q = new WP_REST_Request($m, '/tixomat/v1' . $route);
        $q->set_header('X-Tix-Partner-Id', TIX_Partners::source_platform_id());
        $q->set_header('X-Tix-Partner-Key', $key ?? (string) tix_get_settings('partner_api_key'));
        if ($m === 'GET') $q->set_query_params($b); else { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); }
        $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : ''];
    };
    wp_set_current_user(0);
    [$s, $d, $c] = $pr('GET', '/partner/resale', ['event_id' => $ev]);
    rs_eq('Partner: Event nicht geteilt → 403', 403, $s);
    update_post_meta($ev, '_tix_syndicate', '1');
    [$s, $d] = $pr('GET', '/partner/resale', ['event_id' => $ev]);
    rs_eq('Partner: Angebote ohne checkout_url (die Plattform setzt ihre eigene)', [200, true], [$s, array_reduce((array) ($d['offers'] ?? []), function ($ok, $x) { return $ok && $x['checkout_url'] === ''; }, true)]);
    [$s] = $pr('GET', '/partner/resale', ['event_id' => $ev], str_repeat('x', 32));
    rs_eq('Partner: falscher Schlüssel → 401', 401, $s);
    list($so6, $st6) = $mkorder($buyer, $ev, 0, 1, 20.0);
    $o6 = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tix_orders WHERE id = %d", $so6));
    update_option('_tix_order_partner_' . $so6, ['partner' => TIX_Partners::source_platform_id(), 'ref' => '800000001', 'created' => time()], false);
    [$s, $d, $c] = $pr('POST', '/partner/resale/ticket', ['ticket_id' => $st6[0], 'order_id' => $so6, 'key' => 'falsch', 'action' => 'info']);
    rs_eq('Partner: falscher Bestellschlüssel → 404', 404, $s);
    [$s, $d, $c] = $pr('POST', '/partner/resale/ticket', ['ticket_id' => $st[0], 'order_id' => $so6, 'key' => $o6->order_key, 'action' => 'info']);
    rs_eq('Partner: Ticket einer anderen Bestellung → 404', 404, $s);
    [$s, $d] = $pr('POST', '/partner/resale/ticket', ['ticket_id' => $st6[0], 'order_id' => $so6, 'key' => $o6->order_key, 'action' => 'offer', 'price' => 14, 'accept_terms' => true]);
    $poid = intval($d['offer']['id'] ?? 0);
    rs_eq('Partner: anbieten für den Käufer der Partner-Bestellung', [200, 'open', 'partner'], [$s, $d['offer']['status'] ?? '', TIX_Resale::get($poid)['channel'] ?? '']);
    [$s, $d] = $pr('POST', '/partner/resale/ticket', ['ticket_id' => $st6[0], 'order_id' => $so6, 'key' => $o6->order_key, 'action' => 'withdraw']);
    rs_eq('Partner: zurückziehen', [200, null, 'withdrawn'], [$s, $d['offer'] ?? null, TIX_Resale::get($poid)['status'] ?? '']);
    delete_option('_tix_order_partner_' . $so6);
    delete_post_meta($ev, '_tix_syndicate');
} else {
    echo "(RS5-Partner-Prüfungen übersprungen: Partner-API aus)\n";
}

// ═══ Rückweg: Schalter aus ═══
wp_set_current_user($owner);
rs_rest('POST', "/events/$ev/resale/settings", ['inherit' => true, 'categories_off' => []]);
wp_set_current_user($buyer2);
[$s, $d] = rs_rest('POST', "/customer/tickets/$new/resale", ['price' => 17, 'accept_terms' => true]);
$oid9 = intval($d['offer']['id'] ?? 0);
wp_set_current_user($owner);
rs_rest('POST', '/studio/resale/settings', ['enabled' => false]);
rs_eq('Seite aus → offene Angebote zurückgezogen, verkaufte bleiben gültig', ['withdrawn', 'sold', 'valid'], [TIX_Resale::get($oid9)['status'] ?? '', TIX_Resale::get($oid1)['status'] ?? '', (string) get_post_meta($new, '_tix_ticket_status', true)]);

// ═══ Datenspiegel (RS7) ═══
$plan = TIX_Mirror_Map::plan('resale_offer', $oid1, 'upsert', null);
$row = is_array($plan) ? ($plan[0][2][0] ?? []) : [];
rs_eq('Spiegel: Upsert resale_offers ohne E-Mail', ['upsert', 'resale_offers', 'sold', false], [$plan[0][0] ?? '', $plan[0][1] ?? '', $row['status'] ?? '', strpos(wp_json_encode($row), '@') !== false]);

// ═══ Aufräumen ═══
foreach ($orders as $id) { $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $id]); $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $id]); delete_option('_tix_mollie_payment_' . $id); }
$evs = array_filter($posts, function ($p) { return get_post_type($p) === 'event'; });
foreach ($evs as $e) {
    foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_event_id', 'meta_value' => $e]) as $t) wp_delete_post($t, true);
    $wpdb->delete(TIX_Resale::table(), ['event_id' => $e]);
    TIX_Object_Data::remove('tix_resale_event', $e);
    $wpdb->delete($wpdb->prefix . 'tix_stock', ['event_id' => $e]);
}
if ($org) TIX_Object_Data::remove('tix_resale_org', $org);
$c = get_option('tix_coupons', []); if (is_array($c)) { foreach ($c as $k => $v) if (!empty($v['resale_offer'])) unset($c[$k]); update_option('tix_coupons', $c); }
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($users as $u) wp_delete_user($u);
delete_option(TIX_Resale::OPT_SITE);
echo "\n" . ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']} von {$GLOBALS['t_count']}" : "ALLES OK: {$GLOBALS['t_count']}/{$GLOBALS['t_count']}") . "\n";
