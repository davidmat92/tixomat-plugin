<?php
/**
 * Audit 15.11., zweite Runde Teil A (1.38.840): M2 Saalplan-Bereiche ohne Preis, M4 alte Web-Kassen-Schalter, M5 Kasse mit Platz-Pflicht,
 * M8 Kassen-Einstellungen in der Web-Kasse, Bereichs-Kapazität atomar. (M11 Partner-Gutschrift: test-promoter-partner.)
 *   wp eval-file …/tests/test-audit-1511-a-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Seatmap') || !method_exists('TIX_Seatmap', 'online_price')) { echo "Tixomat ab 1.38.840 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$T = $wpdb->prefix . TIX_Seatmap::TABLE_SUFFIX;
TIX_Seatmap::maybe_upgrade();
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand(20, 200);
$keep = ['tix_settings' => get_option('tix_settings'), 'ss' => get_option(TIX_Sale_Settings::OPT, null), 'inv' => get_option('tix_invoices_enabled', null)];
foreach ([TIX_Seating::OPT_BOOKED, TIX_Seating::OPT_SETTINGS] as $o) $keep[$o] = get_option($o, null);
$posts = []; $orders = [];
$held = function ($ev, $holder) use ($wpdb, $T) { return array_map('strval', (array) $wpdb->get_col($wpdb->prepare("SELECT seat_id FROM $T WHERE event_id = %d AND status = 'reserved' AND session_id = %s ORDER BY seat_id", $ev, $holder))); };
$sold = function ($ev) use ($wpdb, $T) { return array_map('strval', (array) $wpdb->get_col($wpdb->prepare("SELECT seat_id FROM $T WHERE event_id = %d AND status = 'sold' ORDER BY seat_id", $ev))); };
try {
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    wp_set_current_user(0);
    $org = 0;
    if ($multi) {
        $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'Audit A Veranstalter']); $posts[] = $org;
        TIX_Plan::save_org($org, ['seating' => true]);
        update_post_meta($org, '_tix_org_approval', 'approved');
    } else update_option(TIX_Seating::OPT_BOOKED, '1', false);
    $sm = wp_insert_post(['post_type' => 'tix_seatmap', 'post_status' => 'publish', 'post_title' => 'Audit-A-Saal']); $posts[] = $sm;
    if ($multi) update_post_meta($sm, TIX_Seatmap::META_ORG, $org);
    TIX_Seatmap::store($sm, ['layout' => 'theater', 'sections' => [
        ['id' => 'teuer', 'label' => 'Teuer', 'price' => 30, 'block' => ['rows' => 2, 'seats' => 8]],
        ['id' => 'ohne', 'label' => 'Ohne Preis', 'price' => 0, 'block' => ['rows' => 1, 'seats' => 4, 'origin' => ['x' => 500, 'y' => 300]]],
        ['id' => 'katfrei', 'label' => 'Kategorie frei', 'price' => 0, 'block' => ['rows' => 1, 'seats' => 4, 'origin' => ['x' => 500, 'y' => 400]]],
        ['id' => 'schalter', 'label' => 'Freiplatz', 'price' => 0, 'free' => true, 'block' => ['rows' => 1, 'seats' => 4, 'origin' => ['x' => 500, 'y' => 500]]],
        ['id' => 'katpreis', 'label' => 'Kategorie 25', 'price' => 0, 'block' => ['rows' => 1, 'seats' => 4, 'origin' => ['x' => 500, 'y' => 600]]],
    ]]);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Audit-A-Konzert', 'meta_input' => [
        '_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() + 14 * DAY_IN_SECONDS), '_tix_time_start' => '20:00', '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1',
        '_tix_ticket_categories' => [['name' => 'Teuer', 'price' => 30, 'qty' => 16, 'seatmap_section' => 'teuer'], ['name' => 'Gratis', 'price' => 0, 'qty' => 4, 'seatmap_section' => 'katfrei'],
            ['name' => 'Rang', 'price' => 25, 'qty' => 4, 'seatmap_section' => 'katpreis'], ['name' => 'Stehplatz', 'price' => 10, 'qty' => 50]]]]); $posts[] = $ev;
    TIX_Seatmap::assign($ev, $sm);
    $eq('Plan zugewiesen, Modul an', [$sm, true], [TIX_Seatmap::event_seatmap($ev), TIX_Seatmap::module_on($ev)]);

    // ── M2 ──
    $line = function ($seats, $pos = false) use ($ev) { $l = TIX_Native_Checkout::seat_line($ev, $seats, 'audit_a_' . md5(implode(',', $seats)), $pos); return is_array($l) ? floatval($l['price']) : $l; };
    $eq('M2: Bereich mit Preis 30 €', 30.0, $line(['teuer_A_1']));
    $eq('M2: Bereich ohne Preis und ohne 0-€-Kategorie → nicht online', 'Für diesen Bereich gibt es keine Online-Tickets.', $line(['ohne_A_1']));
    $eq('M2: Bereich einer Kategorie mit 0 € → Freiplatz', 0.0, $line(['katfrei_A_1']));
    $eq('M2: ausdrücklicher Freiplatz-Schalter (free) → 0 €', 0.0, $line(['schalter_A_1']));
    $eq('M2: Plan ohne Preis, Kategorie 25 € → 25 €', 25.0, $line(['katpreis_A_1']));
    $eq('M2: Kasse vor Ort darf den Bereich ohne Preis (0 €) verkaufen', 0.0, $line(['ohne_A_2'], true));
    $rp = TIX_Cart_Pricing::reprice([['event_id' => $ev, 'cat_index' => 2, 'price' => 25, 'qty' => 1, 'meta' => ['seats' => ['katpreis_A_2'], 'seatmap_id' => $sm, 'section' => 'katpreis']]]);
    $eq('M2: Neuberechnung beim Bezahlen hält den Kategorie-Preis (vorher 0 € aus dem Plan)', 25.0, floatval($rp[0]['price']));
    $err = TIX_Seatmap::secure_cart([['event_id' => $ev, 'cat_index' => -1, 'price' => 0, 'qty' => 1, 'meta' => ['seats' => ['ohne_A_3'], 'seatmap_id' => $sm, 'section' => 'ohne']]], 'audit_a_alt');
    $eq('M2: alter Warenkorb mit Bereich ohne Preis wird beim Bezahlen abgelehnt', 'Für diesen Bereich gibt es keine Online-Tickets.', $err);
    $map = []; foreach ((array) (TIX_Seatmap::event_data($ev)['sections'] ?? []) as $s) $map[$s['id']] = !empty($s['free']);
    $eq('M2: Schalter free bleibt im Plan erhalten', [false, true], [$map['ohne'] ?? null, $map['schalter'] ?? null]);
    TIX_Seatmap::release($ev, ['teuer_A_1'], 'audit_a_' . md5('teuer_A_1'));

    // ── M5: Kasse ohne Online-Grenzen, Freigabe bei Fehlern ──
    wp_set_current_user($adm);
    $twelve = []; foreach (['A', 'B'] as $r) for ($i = 1; $i <= 6; $i++) $twelve[] = "teuer_{$r}_$i";
    $hold = TIX_Seatmap::hold($ev, $sm, $twelve, 'audit_a_online');
    $eq('M5: online höchstens ' . TIX_Seatmap::limits($ev)['holder'] . ' Plätze je Halter', 'limit_holder', $hold['error']);
    update_post_meta($ev, '_tix_presale_active', '0'); // Vorverkauf beendet: gilt nicht für die Kasse
    [$st, $po] = $rest('POST', '/pos/orders', ['event_id' => $ev, 'items' => [['seats' => $twelve]], 'payment' => 'cash']);
    $oP = intval($po['order_id'] ?? ($po['order']['id'] ?? 0)); if ($oP) $orders[] = $oP;
    $eq('M5: Kasse verkauft 12 Plätze trotz Online-Grenze und Vorverkaufsende', [200, 12], [$st, count(array_intersect($twelve, $sold($ev)))]);
    update_post_meta($ev, '_tix_presale_active', '1');
    $pos_holder = 'pos_' . $adm;
    [$st, , $c] = $rest('POST', '/pos/orders', ['event_id' => $ev, 'items' => [['seats' => ['teuer_A_7', 'teuer_A_8']], ['seats' => ['teuer_A_1']]], 'payment' => 'cash']);
    $eq('M5: zweite Sitzzeile belegt → 409, Plätze der ersten Zeile wieder frei', [409, 'tix_seat_taken', []], [$st, $c, $held($ev, $pos_holder)]);
    [$st, , $c] = $rest('POST', '/pos/orders', ['event_id' => $ev, 'items' => [['seats' => ['teuer_B_7']], ['index' => 99, 'qty' => 1]], 'payment' => 'cash']);
    $eq('M5: Fehler nach dem Halten (Kategorie fehlt) → Platz wieder frei', [400, []], [$st, $held($ev, $pos_holder)]);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.250';
    $ok = 0; for ($i = 0; $i < 32; $i++) { [$st] = $rest('POST', '/customer/seats/best', ['event_id' => $ev, 'qty' => 1, 'section_id' => 'teuer', 'preview' => true]); if ($st === 200) $ok++; }
    $eq('M5: angemeldete Kasse ohne Ratenbegrenzung bei „Beste Plätze“ (32 × 200)', 32, $ok);
    if ($oP) $rest('POST', "/pos/orders/$oP/void");
    wp_set_current_user(0);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.251';
    $codes = []; for ($i = 0; $i < 32; $i++) { [$st] = $rest('POST', '/customer/seats/best', ['event_id' => $ev, 'qty' => 1, 'section_id' => 'teuer', 'preview' => true, 'hold_token' => 'auditatoken' . str_repeat('x', 12)]); $codes[] = $st; }
    $eq('M5: anonym bleibt die Ratenbegrenzung (429 nach 30)', true, in_array(429, $codes, true));

    // ── M4: alte Schalter der Web-Kasse vor Ort gelten nicht für die App-Kasse ──
    if (!$multi) {
        $s = (array) get_option('tix_settings', []);
        $s['pos_allow_free'] = 0; $s['pos_require_name'] = 1;
        update_option('tix_settings', $s);
        delete_option(TIX_Sale_Settings::OPT);
        $pos = TIX_Sale_Settings::get(0)['pos'];
        $eq('M4: App-Kasse ignoriert alte pos_*-Schalter (Vorgaben)', [true, false], [$pos['allow_free'], $pos['require_name']]);
        wp_set_current_user($adm);
        [$st, $d] = $rest('POST', '/studio/checkout-settings', ['pos' => ['require_name' => true]]);
        $eq('M4: Studio-Einstellung wirkt in der App-Kasse', true, TIX_Sale_Settings::get(0)['pos']['require_name']);
        wp_set_current_user(0);
        update_option('tix_settings', $keep['tix_settings']);
        if ($keep['ss'] === null) delete_option(TIX_Sale_Settings::OPT); else update_option(TIX_Sale_Settings::OPT, $keep['ss'], false);
    }

    // ── M8: Kassen-Einstellungen in der Web-Kasse ──
    $cart = ['items' => [['event_id' => $ev, 'cat_index' => 3, 'price' => 10, 'qty' => 1]]];
    $base = TIX_Native_Checkout::web_sale_settings($cart);
    $eq('M8: Vorgabe wie bisher (Adresse Pflicht, Konto anlegen an)', [true, true], [$base['address_required'], $base['account_offer']]);
    if ($multi) {
        TIX_Object_Data::write(TIX_Sale_Settings::OBJ, $org, ['checkout' => ['address_required' => false, 'phone_field' => 'required', 'account_offer' => false]]);
    } else {
        update_option(TIX_Sale_Settings::OPT, ['checkout' => ['address_required' => false, 'phone_field' => 'required', 'account_offer' => false]], false);
    }
    update_option('tix_invoices_enabled', '0');
    $ws = TIX_Native_Checkout::web_sale_settings($cart);
    $eq('M8: Adresse aus, Telefon Pflicht, Konto anlegen aus', [false, 'required', false], [$ws['address_required'], $ws['phone_field'], $ws['account_offer']]);
    update_option('tix_invoices_enabled', '1');
    $eq('M8: E-Rechnung an → Adresse immer Pflicht', true, TIX_Native_Checkout::web_sale_settings($cart)['address_required']);
    update_option('tix_invoices_enabled', '0');
    // Formular: Adresse ohne required, Telefon mit, kein „Konto anlegen“
    TIX_Native_Checkout::save_cart(['items' => [['event_id' => $ev, 'cat_index' => 3, 'name' => 'Stehplatz', 'event_title' => 'Audit-A-Konzert', 'price' => 10, 'qty' => 1, 'meta' => []]], 'coupon' => null]);
    $html = TIX_Native_Checkout::render_checkout();
    $eq('M8: Web-Formular folgt den Einstellungen', [false, true, false], [
        (bool) preg_match('/name="billing_address_1" required/', $html), (bool) preg_match('/name="billing_phone" required/', $html), strpos($html, 'name="createaccount"') !== false]);
    TIX_Native_Checkout::clear_cart();
    if ($multi) TIX_Object_Data::remove(TIX_Sale_Settings::OBJ, $org); else { if ($keep['ss'] === null) delete_option(TIX_Sale_Settings::OPT); else update_option(TIX_Sale_Settings::OPT, $keep['ss'], false); }

    // ── Bereichs-Kapazität atomar: Sperre je Event und Bereich ──
    if (class_exists('TIX_Checkin_Areas')) {
        $rl = new ReflectionMethod('TIX_Checkin_Areas', 'lock'); $rl->setAccessible(true);
        $ru = new ReflectionMethod('TIX_Checkin_Areas', 'unlock'); $ru->setAccessible(true);
        $a = $rl->invoke(null, $ev, 1);
        $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $busy = intval($other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', 'tix_area_' . $ev . '_1')));
        $ru->invoke(null, $ev, 1);
        $free = intval($other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', 'tix_area_' . $ev . '_1')));
        $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', 'tix_area_' . $ev . '_1'));
        $eq('Bereich: Sperre hält zweite Verbindung fern, danach frei', [true, 0, 1], [$a, $busy, $free]);
    }
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($orders as $oid) {
        foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => [['key' => '_tix_ticket_order_id', 'value' => $oid]]]) as $t) wp_delete_post($t, true);
        foreach (['tix_order_items', 'tix_order_notes'] as $tb) $wpdb->delete($wpdb->prefix . $tb, ['order_id' => $oid]);
        $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $oid]);
        delete_option('_tix_pos_order_' . $oid); delete_option('_tix_order_source_' . $oid);
    }
    if (!empty($ev)) $wpdb->delete($T, ['event_id' => $ev]);
    foreach (array_reverse($posts) as $p) wp_delete_post($p, true);
    update_option('tix_settings', $keep['tix_settings']);
    if ($keep['ss'] === null) delete_option(TIX_Sale_Settings::OPT); else update_option(TIX_Sale_Settings::OPT, $keep['ss'], false);
    if ($keep['inv'] === null) delete_option('tix_invoices_enabled'); else update_option('tix_invoices_enabled', $keep['inv']);
    foreach ([TIX_Seating::OPT_BOOKED, TIX_Seating::OPT_SETTINGS] as $o) { if ($keep[$o] === null) delete_option($o); else update_option($o, $keep[$o], false); }
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
