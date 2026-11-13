<?php
/**
 * Kasse & Einlass einstellen (P3 Expertenmodus, 1.38.799): Pflegen, Lesen (App-Kasse, Scanner), Durchsetzung.
 *   wp eval-file …/test-sale-settings-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Sale_Settings')) { echo "Tixomat ab 1.38.799 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$keep = ['tix_settings' => get_option('tix_settings'), TIX_Sale_Settings::OPT => get_option(TIX_Sale_Settings::OPT, null), 'tix_invoices_enabled' => get_option('tix_invoices_enabled', null)];
// Schlüssel, die der Test anlegt, beim Aufräumen wieder entfernen (nicht nur den alten Wert zurückschreiben)
$legacy_keys = ['show_company_field', 'show_coupon_selector', 'newsletter_enabled', 'checkout_countdown', 'checkout_countdown_minutes', 'pos_require_name', 'pos_require_email', 'pos_allow_free', 'pos_default_payment', 'pos_auto_reset_seconds'];
$had = array_intersect_key((array) get_option('tix_settings', []), array_flip($legacy_keys));
$posts = []; $orders = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand(20, 200);
try {
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    wp_set_current_user($adm);
    update_option('tix_invoices_enabled', '0');
    $org = 0;
    if ($multi) { $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'P3 Veranstalter']); $posts[] = $org; update_post_meta($org, '_tix_org_approval', 'approved'); }
    $q = $multi ? ['organizer_id' => $org] : [];
    if ($multi) { TIX_Object_Data::remove(TIX_Sale_Settings::OBJ, $org); }
    else delete_option(TIX_Sale_Settings::OPT);

    // Reine Prüfung
    $eq('Prüfung: unbekanntes Feld / falscher Wert', ['checkin.dupe', 'pos.default_payment', 'checkout.countdown_minutes'],
        [TIX_Sale_Settings::validate(['checkin' => ['dupe' => 1]])['error'] ?? null, TIX_Sale_Settings::validate(['pos' => ['default_payment' => 'paypal']])['error'] ?? null, TIX_Sale_Settings::validate(['checkout' => ['countdown_minutes' => 99]])['error'] ?? null]);

    [$st, $d] = $rest('GET', '/studio/checkout-settings', null, $q);
    $eq('GET: drei Bereiche, Vorgaben = heutiges Verhalten (warn, 2 s, Ton/Vibration an), Optionen', [200, true, 'warn', 2, true, 'off', ['warn', 'block', 'reentry']], [$st, isset($d['checkout'], $d['pos'], $d['checkin']), $d['checkin']['duplicate'] ?? null, $d['checkin']['result_seconds'] ?? null, $d['checkin']['vibration'] ?? null, $d['checkout']['phone_field'] ?? null, $d['options']['checkin.duplicate'] ?? null]);
    [$st, $d] = $rest('POST', '/studio/checkout-settings', ['checkout' => ['company_field' => true, 'address_required' => false, 'phone_field' => 'required', 'account_offer' => false, 'countdown_minutes' => 12],
        'pos' => ['require_name' => true, 'allow_free' => false, 'default_payment' => 'card', 'auto_reset_seconds' => 20], 'checkin' => ['duplicate' => 'reentry', 'vibration' => false, 'result_seconds' => 3]], $q);
    $eq('POST speichert alle Bereiche', [200, true, false, 'required', 'card', 'reentry', 3, $multi ? 'organizer' : 'site'],
        [$st, $d['checkout']['company_field'] ?? null, $d['checkout']['address_required'] ?? null, $d['checkout']['phone_field'] ?? null, $d['pos']['default_payment'] ?? null, $d['checkin']['duplicate'] ?? null, $d['checkin']['result_seconds'] ?? null, $d['scope'] ?? '']);
    [$st, $d, $c] = $rest('POST', '/studio/checkout-settings', ['checkin' => ['duplicate' => 'egal']], $q);
    $eq('Ungültiger Wert → 400 mit Feld', [400, 'checkin.duplicate'], [$st, $d['data']['field'] ?? null]);
    if (!$multi) {
        $s = (array) get_option('tix_settings', []);
        $eq('Einzel-Seite: alte Schlüssel für Web-Kasse und Web-Kasse vor Ort mitgeschrieben', [1, 1, 0, 'card', 1, 12, 3], [intval($s['show_company_field'] ?? -1), intval($s['pos_require_name'] ?? -1), intval($s['pos_allow_free'] ?? -1), $s['pos_default_payment'] ?? '', intval($s['checkout_countdown'] ?? -1), intval($s['checkout_countdown_minutes'] ?? -1), 3]);
    } else {
        $eq('evendis: je Veranstalter, Plattform-Vorgabe unberührt', ['reentry', 'warn'], [TIX_Sale_Settings::get($org)['checkin']['duplicate'], TIX_Sale_Settings::get(0)['checkin']['duplicate']]);
    }

    // Event
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'P3 Abend', 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() + 8 * DAY_IN_SECONDS), '_tix_time_start' => '20:00',
        '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1', '_tix_ticket_categories' => [['name' => 'Gratis', 'price' => 0, 'qty' => 50], ['name' => 'Normal', 'price' => 10, 'qty' => 50]]]]); $posts[] = $ev;

    [$st, $d] = $rest('GET', '/pos/config', null, ['event_id' => $ev]);
    $eq('/pos/config je Event (enabled/pin_required fest)', [200, true, false, true, false, 'card', 20], [$st, $d['pos']['enabled'] ?? null, $d['pos']['pin_required'] ?? null, $d['pos']['require_name'] ?? null, $d['pos']['allow_free'] ?? null, $d['pos']['default_payment'] ?? null, $d['pos']['auto_reset_seconds'] ?? null]);
    [$st, $d] = $rest('GET', '/checkin/config', null, ['event_id' => $ev]);
    $eq('/checkin/config je Event', [200, 'reentry', false, 3, true], [$st, $d['checkin']['duplicate'] ?? null, $d['checkin']['vibration'] ?? null, $d['checkin']['result_seconds'] ?? null, $d['checkin']['sound'] ?? null]);

    // Online-Kasse (App/Website)
    wp_set_current_user(0);
    [$st, $d] = $rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 1]]]);
    $eq('Angebot: checkout_fields (Namen vom Apps-Chat)', [200, 'required', false, false, true, true], [$st, $d['checkout_fields']['phone'] ?? null, $d['checkout_fields']['address_required'] ?? null, $d['checkout_fields']['offer_account'] ?? null, $d['checkout_fields']['company'] ?? null, $d['fields']['company'] ?? null]);
    $order = function ($billing) use ($rest, $ev, &$orders) {
        [$st, $d, $c] = $rest('POST', '/customer/orders', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 1]], 'billing' => $billing, 'payment_method' => 'free', 'accept_terms' => 1, 'idempotency_token' => wp_generate_password(20, false)]);
        $oid = intval($d['order']['id'] ?? 0); if ($oid) $orders[] = $oid;
        return [$st, $d, $c];
    };
    [$st, $d, $c] = $order(['first_name' => 'Kim', 'last_name' => 'P3', 'email' => 'p3_' . wp_rand(1000, 9999) . '@example.test']);
    $eq('Telefon Pflicht → 400 field phone', [400, 'phone'], [$st, $d['data']['field'] ?? null]);
    [$st] = $order(['first_name' => 'Kim', 'last_name' => 'P3', 'email' => 'p3_' . wp_rand(1000, 9999) . '@example.test', 'phone' => '0171 1234567']);
    $eq('Ohne Adresse (address_required aus) → Bestellung geht', true, in_array($st, [200, 201], true));
    update_option('tix_invoices_enabled', '1');
    [$st, $d] = $order(['first_name' => 'Kim', 'last_name' => 'P3', 'email' => 'p3_' . wp_rand(1000, 9999) . '@example.test', 'phone' => '0171 1234567']);
    $eq('E-Rechnung an → Adresse bleibt Pflicht', [400, 'address_1'], [$st, $d['data']['field'] ?? null]);
    update_option('tix_invoices_enabled', '0');

    // Kasse vor Ort
    wp_set_current_user($adm);
    [$st, , $c] = $rest('POST', '/pos/orders', ['event_id' => $ev, 'items' => [['index' => 1, 'qty' => 1]], 'payment' => 'cash']);
    $eq('Kasse: Name Pflicht → 400 tix_pos_field', [400, 'tix_pos_field'], [$st, $c]);
    [$st, , $c] = $rest('POST', '/pos/orders', ['event_id' => $ev, 'items' => [['index' => 1, 'qty' => 1]], 'payment' => 'free', 'customer_name' => 'Gast']);
    $eq('Kasse: Kostenlos aus → 403 tix_pos_free_off', [403, 'tix_pos_free_off'], [$st, $c]);
    [$st, $d] = $rest('POST', '/pos/orders', ['event_id' => $ev, 'items' => [['index' => 1, 'qty' => 1]], 'payment' => 'card', 'customer_name' => 'Gast']);
    $po = intval($d['order_id'] ?? ($d['order']['id'] ?? 0)); if ($po) $orders[] = $po;
    $eq('Kasse: mit Name und Karte → verkauft', 200, $st);

    // Einlass: Wiedereinlass
    $code = strtoupper(wp_generate_password(12, false, false));
    $tk = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'P3 Ticket', 'meta_input' => ['_tix_ticket_code' => $code, '_tix_ticket_event_id' => $ev, '_tix_ticket_status' => 'valid', '_tix_ticket_owner_name' => 'Kim']]); $posts[] = $tk;
    [$st, $d1] = $rest('POST', '/checkin/scan', ['code' => $code, 'event_id' => $ev]);
    [$st, $d2] = $rest('POST', '/checkin/scan', ['code' => $code, 'event_id' => $ev]);
    $eq('Wiedereinlass: zweiter Scan eigener Status reentry, Zähler 1', ['ok', 'reentry', true, 1], [$d1['status'] ?? '', $d2['status'] ?? '', $d2['reentry'] ?? null, $d2['reentry_count'] ?? null]);
    $rest('POST', '/studio/checkout-settings', ['checkin' => ['duplicate' => 'block']], $q);
    [, $d3] = $rest('POST', '/checkin/scan', ['code' => $code, 'event_id' => $ev]);
    $eq('Sperren: already + block', ['already', true], [$d3['status'] ?? '', $d3['block'] ?? null]);
    $rest('POST', '/studio/checkout-settings', ['checkin' => ['duplicate' => 'warn']], $q);
    [, $d4] = $rest('POST', '/checkin/scan', ['code' => $code, 'event_id' => $ev]);
    $eq('Warnen (Vorgabe, wie bisher): already ohne block', ['already', false], [$d4['status'] ?? '', $d4['block'] ?? null]);

    // Rechte
    $staff = wp_insert_user(['user_login' => 'p3staff' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'user_email' => 'p3staff' . wp_rand(1000, 9999) . '@example.test', 'role' => 'tix_staff']);
    wp_set_current_user($staff);
    $eq('Mitarbeiter: ändern verboten', true, in_array($rest('POST', '/studio/checkout-settings', ['checkin' => ['sound' => false]], $q)[0], [401, 403], true));
    require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($staff);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($orders as $oid) {
        foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $oid]) as $t) wp_delete_post($t, true);
        foreach (['tix_order_items', 'tix_order_notes'] as $tb) $wpdb->delete($wpdb->prefix . $tb, ['order_id' => $oid]);
        $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $oid]);
    }
    foreach (array_reverse($posts) as $p) { if (get_post_type($p) === 'tix_organizer' && class_exists('TIX_Object_Data')) TIX_Object_Data::remove(TIX_Sale_Settings::OBJ, $p); wp_delete_post($p, true); }
    foreach ($keep as $k => $v) { if ($v === null) delete_option($k); else update_option($k, $v); }
    $sx = (array) get_option('tix_settings', []);
    foreach ($legacy_keys as $lk) { if (array_key_exists($lk, $had)) $sx[$lk] = $had[$lk]; else unset($sx[$lk]); }
    update_option('tix_settings', $sx);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
