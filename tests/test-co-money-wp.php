<?php
/**
 * Co-Veranstalter: Geldempfänger + Anteil, Paket 1 (1.38.711, Vertrag `uebergaben/vertrag-co-geldempfaenger.md`).
 *   wp eval-file …/test-co-money-wp.php   (Plattform und Einzel-Seite)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Co_Money')) { echo "Tixomat ab 1.38.711 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
global $wpdb;
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function cm_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
/** Tests vor 1.38.717: alle Beteiligten stimmen sofort zu (den echten Ablauf prüft test-co-multi-wp „Zustimmung“). */
function co_test_consent_all($route) {
    if (!class_exists('TIX_Co_Money') || !method_exists('TIX_Co_Money', 'pending') || empty($GLOBALS['co_auto_consent'])) return;
    if (!preg_match('#/(?:events|syndicate|platform/events|partner/co-events)/(\d+)#', $route, $m)) return;
    $p = TIX_Co_Money::pending(intval($m[1]));
    if (!$p) return;
    foreach ((array) $p['needs'] as $party) if (empty($p['consents'][$party])) TIX_Co_Money::consent(intval($m[1]), $party, true);
}
$GLOBALS['co_auto_consent'] = true;
function cm_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && in_array($method, ['GET', 'DELETE'], true)) $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    if ($method !== 'GET') co_test_consent_all($route);
    $d = rest_get_server()->response_to_data($res, false);
    return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : ''];
}
function cm_ev($d) { return $d['event'] ?? $d; }
add_filter('pre_http_request', function ($pre, $a, $url) {
    if (strpos($url, '.test/') === false) return $pre;
    return ['headers' => [], 'body' => wp_json_encode(['ok' => true, 'co_organizer' => null]), 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
}, 10, 3);
add_filter('pre_wp_mail', function () { return true; });
$GLOBALS['cm_orders'] = [];
$order = function ($eid, $total, $pfee = 0.0) {
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'tix_orders', ['order_number' => 'CMT-' . wp_rand(1000, 99999), 'status' => 'completed', 'total' => $total, 'subtotal' => $total, 'tax' => 0, 'discount' => 0,
        'payment_method' => 'test_gateway', 'payment_method_title' => 'Test', 'billing_email' => 'cm@example.test', 'customer_id' => 0, 'wc_order_id' => 0, 'order_key' => wp_generate_password(12, false),
        'checked_in' => 0, 'date_created' => current_time('mysql'), 'date_modified' => current_time('mysql')]);
    $oid = intval($wpdb->insert_id);
    $wpdb->insert($wpdb->prefix . 'tix_order_items', ['order_id' => $oid, 'product_id' => 0, 'event_id' => $eid, 'quantity' => 2, 'total' => $total, 'tax' => 0, 'name' => 'Ticket', 'cat_name' => 'Normal', 'meta' => '']);
    if ($pfee > 0) update_option('_tix_order_fees_' . $oid, ['platform_fee' => $pfee, 'platform_fee_mode' => 'organizer'], false);
    $GLOBALS['cm_orders'][] = $oid;
    return $oid;
};
$cleanup_orders = function () {
    global $wpdb;
    foreach ($GLOBALS['cm_orders'] as $oid) {
        $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $oid]);
        $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $oid]);
        delete_option('_tix_order_fees_' . $oid);
    }
};
$admin = get_users(['role' => 'administrator', 'number' => 1]);
$admin_id = intval($admin[0]->ID);
$tmp_p = []; $tmp_u = [];

if (!$multi) {
    echo "== Einzel-Seite\n";
    try {
        wp_set_current_user($admin_id);
        $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Cmtest KK-Event', 'meta_input' => ['_tix_date_start' => '2027-02-27']]);
        $tmp_p[] = $ev;
        delete_post_meta($ev, '_tix_co_remotes'); update_post_meta($ev, '_tix_co_remote', ['organizer_id' => 77, 'name' => 'KMT Test', 'access' => true, 'status' => 'active', 'since' => gmdate('c')]);
        // Lizenzseite meldet Geldempfänger/Anteil an die Plattform (Paket 3) – Antwort nachgestellt
        add_filter('pre_http_request', function ($pre, $a, $url) { return strpos($url, '/syndicate/') !== false ? ['headers' => [], 'body' => wp_json_encode(['ok' => true]), 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null] : $pre; }, 5, 3);
        $m = cm_ev(cm_rest('GET', "/events/$ev/edit")[1])['co_money'] ?? [];
        cm_eq('Editor: Ersteller, Co wählbar (Paket 3)', ['creator', 0.0, ['creator', 'co'], true], [$m['owner'] ?? '', $m['share'] ?? null, $m['choosable'] ?? [], $m['editable'] ?? null]);
        [$st] = cm_rest('POST', "/events/$ev", ['co_money' => ['share' => 15]]);
        cm_eq('Anteil 15 % speichern', [200, 15.0], [$st, TIX_Co_Money::share($ev)]);
        [$st] = cm_rest('POST', "/events/$ev", ['co_money' => ['owner' => 'co']]);
        cm_eq('Co als Geldempfänger (über die Plattform)', [200, 'co'], [$st, TIX_Co_Money::owner($ev)]);
        [$st] = cm_rest('POST', "/events/$ev", ['co_money' => ['owner' => 'creator']]);
        cm_eq('zurück auf Ersteller', [200, 'creator'], [$st, TIX_Co_Money::owner($ev)]);
        $order($ev, 50.0);
        $b = cm_rest('GET', "/events/$ev/co-balance")[1];
        cm_eq('Ausgleich: Netto, Anteil, selbst überweisen', [1, 50.0, 7.5, false, 'KMT Test'], [$b['orders'] ?? 0, $b['net'] ?? null, $b['share_amount'] ?? null, $b['settled_by_platform'] ?? null, $b['payee']['name'] ?? '']);
        $pdf = cm_rest('GET', "/events/$ev/co-balance/pdf")[1];
        cm_eq('Ausgleich als PDF', true, strpos((string) base64_decode((string) ($pdf['data'] ?? '')), '%PDF') === 0);
        cm_eq('Durchgriff darf den Ausgleich lesen', true, TIX_Co_Remote::allowed('GET', "/events/$ev/co-balance", [], [], $ev) === true);
        [$st, , $c] = cm_rest('POST', "/events/$ev", ['co_money' => ['share' => 10]]);
        cm_eq('Nach Verkauf gesperrt', [409, 'tix_co_money_locked'], [$st, $c]);
        cm_eq('Editor: gesperrt wegen Verkauf', ['sold', true], [cm_ev(cm_rest('GET', "/events/$ev/edit")[1])['co_money']['locked_reason'] ?? '', cm_ev(cm_rest('GET', "/events/$ev/edit")[1])['co_money']['locked'] ?? null]);
        [$st, , $c] = cm_rest('DELETE', "/events/$ev/co-organizer");
        cm_eq('Lösen mit Anteil + Verkauf gesperrt', [409, 'tix_co_money_locked'], [$st, $c]);
    } catch (Throwable $e) { $GLOBALS['t_fails']++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n"; }
    finally { $cleanup_orders(); foreach ($tmp_p as $id) wp_delete_post($id, true); wp_set_current_user(0); }
    echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
    return;
}

echo "== Plattform\n";
$mku = function ($role) use (&$tmp_u) {
    $id = wp_insert_user(['user_login' => 'cm_' . $role . '_' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(20), 'user_email' => 'cm_' . wp_rand(1000, 999999) . '@example.test', 'role' => $role]);
    $tmp_u[] = $id; return $id;
};
$mko = function ($uid, $name) use (&$tmp_p) {
    $id = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => $name, 'meta_input' => ['_tix_org_user_id' => $uid]]);
    $tmp_p[] = $id; return $id;
};
$sids = [];
try {
    $ua = $mku('tix_organizer'); $oa = $mko($ua, 'Cmtest Ersteller');
    $ub = $mku('tix_organizer'); $ob = $mko($ub, 'Cmtest Co');
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Cmtest Party', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_date_start' => '2020-05-01', '_tix_time_start' => '20:00']]);
    $tmp_p[] = $ev;
    TIX_Co_Organizer::set($ev, $ob, true, $ua);
    wp_set_current_user($ua);
    $m = cm_ev(cm_rest('GET', "/events/$ev/edit")[1])['co_money'] ?? [];
    cm_eq('Editor: Ersteller, beide wählbar, änderbar', ['creator', ['creator', 'co'], false, true], [$m['owner'] ?? '', $m['choosable'] ?? [], $m['locked'] ?? null, $m['editable'] ?? null]);
    [$st] = cm_rest('POST', "/events/$ev", ['co_money' => ['owner' => 'co', 'share' => 20]]);
    cm_eq('Co nimmt ein, 20 % an Ersteller', [200, 'co', 20.0], [$st, TIX_Co_Money::owner($ev), TIX_Co_Money::share($ev)]);
    $m = cm_ev(cm_rest('GET', "/events/$ev/edit")[1])['co_money'] ?? [];
    cm_eq('Editor: Verkäufer = Co, Anteil an Ersteller', ['co', 'Cmtest Co', 'creator'], [$m['seller']['side'] ?? '', $m['seller']['name'] ?? '', $m['share_to'] ?? '']);
    cm_eq('Anteil 150 → 400', [400, 'tix_invalid_field'], [cm_rest('POST', "/events/$ev", ['co_money' => ['share' => 150]])[0], cm_rest('POST', "/events/$ev", ['co_money' => ['share' => 150]])[2]]);
    wp_set_current_user($ub);
    cm_eq('Co darf nicht ändern: 403', 403, cm_rest('POST', "/events/$ev", ['co_money' => ['share' => 5]])[0]);
    wp_set_current_user($ua);
    cm_eq('Settlement-Empfänger = Co', $ob, TIX_Co_Money::settlement_org($ev));

    $order($ev, 100.0, 4.0);
    cm_eq('Verkauf erkannt, gesperrt', [true, 'sold'], [TIX_Co_Money::sold($ev), cm_ev(cm_rest('GET', "/events/$ev/edit")[1])['co_money']['locked_reason'] ?? '']);
    [$st, , $c] = cm_rest('POST', "/events/$ev", ['co_money' => ['owner' => 'creator']]);
    cm_eq('Nach Verkauf: 409 locked', [409, 'tix_co_money_locked'], [$st, $c]);
    [$st, , $c] = cm_rest('DELETE', "/events/$ev/co-organizer");
    cm_eq('Lösen nach Verkauf mit Co-Geld: 409', [409, 'tix_co_money_locked'], [$st, $c]);

    // Abrechnung
    $sid = TIX_Settlement::create_for_event($ev, true);
    $sids[] = $sid;
    $s = TIX_Settlement::get($sid);
    $items = TIX_Settlement::items($sid);
    $share = array_values(array_filter($items, function ($i) { return $i->type === 'co_share'; }));
    cm_eq('Abrechnung an den Co', $ob, intval($s->organizer_id ?? 0));
    cm_eq('Position co_share −19,20 (20 % von 96)', [1, -19.2], [count($share), round(floatval($share[0]->amount ?? 0), 2)]);
    cm_eq('Auszahlung 76,80', 76.8, round(floatval($s->payout_amount), 2));
    $open = round(floatval($wpdb->get_var($wpdb->prepare("SELECT SUM(amount) FROM " . TIX_Settlement::items_table() . " WHERE settlement_id = 0 AND organizer_id = %d AND type = 'co_share'", $oa))), 2);
    cm_eq('Ersteller: offener Posten +19,20', 19.2, $open);
    $lines = TIX_Settlement::detail_payload($s)['lines'] ?? [];
    cm_eq('Detail: Zeile co_share mit Text', true, (bool) array_filter($lines, function ($l) { return $l['type'] === 'co_share' && strpos($l['label'], 'Anteil an Cmtest Ersteller (20 %)') !== false; }));
    TIX_Settlement::recalculate($sid, false);
    $open2 = round(floatval($wpdb->get_var($wpdb->prepare("SELECT SUM(amount) FROM " . TIX_Settlement::items_table() . " WHERE settlement_id = 0 AND organizer_id = %d AND type = 'co_share'", $oa))), 2);
    cm_eq('Neu berechnen: kein doppelter Posten', [19.2, 1], [$open2, count(array_filter(TIX_Settlement::items($sid), function ($i) { return $i->type === 'co_share'; }))]);
    $bal = TIX_Settlement::create_balance($oa);
    $sids[] = $bal;
    cm_eq('Saldo-Abrechnung Ersteller: 19,20', 19.2, round(floatval(TIX_Settlement::get($bal)->payout_amount ?? 0), 2));
    TIX_Settlement::recalculate($sid, false);
    cm_eq('Neu berechnen nach Übernahme: kein neuer Posten', 0.0, round(floatval($wpdb->get_var($wpdb->prepare("SELECT SUM(amount) FROM " . TIX_Settlement::items_table() . " WHERE settlement_id = 0 AND organizer_id = %d AND type = 'co_share'", $oa))), 2));

    // Ausgleichs-Übersicht auf der Plattform
    $b = cm_rest('GET', "/events/$ev/co-balance")[1];
    cm_eq('co-balance: Netto 96, Anteil 19,20, über Abrechnung', [96.0, 19.2, true, 'Cmtest Co'], [$b['net'] ?? null, $b['share_amount'] ?? null, $b['settled_by_platform'] ?? null, $b['payer']['name'] ?? '']);
    wp_set_current_user($ub);
    cm_eq('co-balance: Co darf lesen', 200, cm_rest('GET', "/events/$ev/co-balance")[0]);

    // Beim Verbinden
    wp_set_current_user($ua);
    $ev2 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Cmtest Zwei', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_date_start' => '2027-05-01']]);
    $tmp_p[] = $ev2;
    $mail = 'cm_neu_' . wp_rand(1000, 99999) . '@example.test';
    [$st, , $c] = cm_rest('POST', "/events/$ev2/co-organizer/invite", ['email' => $mail, 'organizer' => 'Cmtest Neu', 'access' => false, 'consent' => true, 'money_owner' => 'co']);
    cm_eq('Einladen ohne Zugriff, Co-Geld: 409', [409, 'tix_co_money_unsupported'], [$st, $c]);
    [$st] = cm_rest('POST', "/events/$ev2/co-organizer/invite", ['email' => $mail, 'organizer' => 'Cmtest Neu', 'access' => true, 'consent' => true, 'money_owner' => 'co', 'share' => 10]);
    $no = TIX_Co_Organizer::ref_id($ev2); if ($no) $tmp_p[] = $no;
    $nu = get_user_by('email', $mail); if ($nu) $tmp_u[] = $nu->ID;
    cm_eq('Einladen mit Co-Geld + 10 %', [201, 'co', 10.0], [$st, TIX_Co_Money::owner($ev2), TIX_Co_Money::share($ev2)]);
    [$st] = cm_rest('DELETE', "/events/$ev2/co-organizer");
    cm_eq('Lösen ohne Verkauf: zurück auf Ersteller', [200, 'creator', 0.0], [$st, TIX_Co_Money::owner($ev2), TIX_Co_Money::share($ev2)]);

    // Plattform-Admin
    wp_set_current_user($admin_id);
    [$st, $d] = cm_rest('POST', "/platform/events/$ev2/co-organizer", ['organizer_id' => $ob, 'money_owner' => 'co', 'share' => 5]);
    cm_eq('Plattform: Co-Geld + 5 % (wirksam nach Zustimmung)', [201, 'waiting_consent', 'co', 5.0], [$st, $d['event']['co_money']['status'] ?? '', TIX_Co_Money::owner($ev2), TIX_Co_Money::share($ev2)]);
    [$st, $d] = cm_rest('POST', "/platform/events/$ev2/co-organizer", ['organizer_id' => $ob, 'share' => 7.5]);
    cm_eq('Plattform: nur Anteil ändern', [200, 'co', 7.5], [$st, TIX_Co_Money::owner($ev2), TIX_Co_Money::share($ev2)]);
} catch (Throwable $e) {
    $GLOBALS['t_fails']++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    $cleanup_orders();
    foreach ($sids as $sid) if ($sid) { $wpdb->delete(TIX_Settlement::items_table(), ['settlement_id' => $sid]); $wpdb->delete(TIX_Settlement::table(), ['id' => $sid]); }
    foreach ($tmp_p as $id) if ($id) { $wpdb->query($wpdb->prepare("DELETE FROM " . TIX_Settlement::items_table() . " WHERE organizer_id = %d OR event_id = %d", $id, $id)); }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($tmp_p as $id) if ($id) wp_delete_post($id, true);
    foreach ($tmp_u as $id) if ($id) wp_delete_user($id);
    wp_set_current_user(0);
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
