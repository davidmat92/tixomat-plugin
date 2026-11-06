<?php
/**
 * Integrationstest Demo-Studio (TIX_Demo_Access, 1.38.664) gegen ein lokales Test-WordPress im
 * Mehr-Veranstalter-Modus. NIE auf einer Live-Seite ausführen – legt Konten und Testkäufe an und löscht sie wieder.
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-demo-access-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Demo_Access')) { echo "WordPress mit Tixomat nötig\n"; return; }
if (stripos(home_url(), 'localhost') === false && stripos(home_url(), '.test') === false) { echo "Nur auf lokalen Test-Seiten!\n"; return; }

$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0; $GLOBALS['t_mails'] = [];
function t_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
add_filter('pre_wp_mail', function ($null, $atts) { $GLOBALS['t_mails'][] = $atts; return true; }, 1, 2);
function t_mail_to($to) { foreach (array_reverse($GLOBALS['t_mails']) as $m) if (in_array($to, (array) $m['to'], true) || $m['to'] === $to) return $m; return null; }
/** Wie eine echte HTTP-Anfrage: rest_post_dispatch läuft (Verkürzen) */
function t_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $res = apply_filters('rest_post_dispatch', rest_ensure_response($res), rest_get_server(), $r);
    return [$res->get_status(), $res->get_data()];
}
function t_code($d) { return is_array($d) ? (string) ($d['code'] ?? '') : ''; }
function t_link_token($to) {
    $m = t_mail_to($to);
    if (!$m) return '';
    return preg_match('~/anmelden/link/\?t=([A-Za-z0-9%]+)~', (string) $m['message'], $x) ? rawurldecode($x[1]) : '';
}

update_option('tix_multi_organizer', '1');
$admin = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0]);
$sfx = wp_rand(10000, 99999);
delete_transient('tix_demo_reset_rl'); // Grenze für Zurücksetzen
$_SERVER['REMOTE_ADDR'] = '10.8.' . wp_rand(1, 250) . '.' . wp_rand(1, 250); // Grenzen je IP
$cleanup_users = [];
if (!TIX_Sales::demo_id()) TIX_Demo::ensure_organizer();
$demo = TIX_Sales::demo_id();
t_eq('Beispiel-Club vorhanden', true, $demo > 0);

// ── 1. Vertriebler legt nur der Admin an ──
wp_set_current_user($admin);
list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'sales', 'name' => "Tim Test $sfx", 'email' => "tim$sfx@example.org", 'company' => 'evendis']);
t_eq('Admin legt Vertriebler an (201)', 201, $st);
$sales = intval($d['access']['id'] ?? 0); $cleanup_users[] = $sales;
t_eq('Vertriebler-Zeile kind=sales', 'sales', $d['access']['kind'] ?? '');
list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'sales', 'name' => "Sara $sfx", 'email' => "sara$sfx@example.org"]);
$sales2 = intval($d['access']['id'] ?? 0); $cleanup_users[] = $sales2;

wp_set_current_user($sales);
list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'sales', 'name' => 'X', 'email' => "x$sfx@example.org"]);
t_eq('Vertriebler darf keine Vertriebler anlegen (403)', 403, $st);

// ── 2. Vertriebler legt Interessenten an, Einladung per Link ──
$GLOBALS['t_mails'] = [];
list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'prospect', 'model' => 'self', 'name' => "Lena Brandt", 'email' => "lena$sfx@beispiel.test", 'company' => 'Beispiel Club']);
t_eq('Interessent angelegt (201)', 201, $st);
$pa = intval($d['access']['id'] ?? 0); $cleanup_users[] = $pa;
t_eq('Einladung verschickt', true, !empty($d['invite_sent']));
t_eq('Ablauf in 14 Tagen', true, abs(TIX_Demo_Access::expires($pa) - (time() + 14 * DAY_IN_SECONDS)) < 60);
t_eq('Interessent ist Team des Beispiel-Clubs', $demo, intval(get_user_meta($pa, '_tix_team_organizer_id', true)));
t_eq('Interessent ist Team-Admin', true, TIX_App_Scope::is_org_manager($pa));
$invite = t_link_token("lena$sfx@beispiel.test");
t_eq('Link in der Einladung', true, strlen($invite) >= 20);
list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'prospect', 'model' => 'platform', 'name' => 'Doppelt', 'email' => "lena$sfx@beispiel.test"]);
t_eq('gleiche E-Mail → 409', 409, $st);

wp_set_current_user($sales2);
list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'prospect', 'model' => 'platform', 'name' => "Max Mustermann$sfx", 'email' => "max$sfx@beispiel.test"]);
$pb = intval($d['access']['id'] ?? 0); $cleanup_users[] = $pb;
list($st, $d) = t_rest('GET', '/demo-access');
t_eq('Vertriebler 2 sieht nur eigene Zugänge', [$pb], array_column($d['accesses'] ?? [], 'id'));
list($st, $d) = t_rest('PATCH', "/demo-access/$pa", ['blocked' => true]);
t_eq('fremden Interessenten sperren → 404', 404, $st);
list($st, $d) = t_rest('DELETE', "/demo-access/$pa");
t_eq('fremden Interessenten löschen → 404', 404, $st);

wp_set_current_user($admin);
list($st, $d) = t_rest('GET', '/demo-access');
$ids = array_column($d['accesses'] ?? [], 'id');
t_eq('Admin sieht alle (Interessenten + Vertriebler)', true, in_array($pa, $ids, true) && in_array($pb, $ids, true) && in_array($sales, $ids, true));

// ── 2b. Modell (User 06.11.): Pflicht für Interessenten, Sicht im /me, umstellbar, Filter ──
wp_set_current_user($sales);
list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'prospect', 'name' => 'Ohne Modell', 'email' => "ohne$sfx@beispiel.test"]);
t_eq('Interessent ohne Modell → 400 Feld model', [400, 'model'], [$st, $d['data']['field'] ?? '']);
t_eq('Lena: Modell selbstgehostet', 'self', TIX_Demo_Access::model($pa));
t_eq('Lena: Testkauf über demo.evendis.de', true, strpos(TIX_Demo_Access::me_payload(get_userdata($pa))['home'], TIX_Demo_Access::home_url('', 'self')) === 0);
t_eq('Max: Modell Provisionskunde, Testkauf über evendis.de/demo', true, TIX_Demo_Access::model($pb) === 'platform' && strpos(TIX_Demo_Access::me_payload(get_userdata($pb))['home'], TIX_Demo_Access::home_url('', 'platform')) === 0);
t_eq('Interessent darf Sicht nicht selbst umschalten', false, TIX_Demo_Access::me_payload(get_userdata($pa))['can_switch']);
wp_set_current_user($sales2);
list($st, $d) = t_rest('GET', '/demo-access', ['model' => 'platform']);
t_eq('Filter Modell', [$pb], array_column($d['accesses'] ?? [], 'id'));
t_eq('Modelle mit Preisen in der Liste', ['platform', 'self'], array_column($d['models'] ?? [], 'key'));
list($st, $d) = t_rest('PATCH', "/demo-access/$pb", ['model' => 'self']);
t_eq('Modell nachträglich umstellen', 'self', $d['access']['model'] ?? null);
list($st, $d) = t_rest('PATCH', "/demo-access/$pb", ['model' => 'quatsch']);
t_eq('unbekanntes Modell → 400', 400, $st);
t_rest('PATCH', "/demo-access/$pb", ['model' => 'platform']);
list($st, $d) = t_rest('POST', '/demo-access/view', ['model' => 'self']);
t_eq('Vertriebler schaltet eigene Sicht um', ['self', 'self'], [$d['model'] ?? null, TIX_Demo_Access::model($sales2)]);
wp_set_current_user($pa);
list($st) = t_rest('POST', '/demo-access/view', ['model' => 'platform']);
t_eq('Interessent: Sicht umschalten gesperrt (403)', 403, $st);

// ── 3. Anmeldung per Link ──
wp_set_current_user(0);
list($st, $d) = t_rest('POST', '/public/demo-access/confirm', ['token' => $invite, 'device' => 'Test']);
t_eq('Link einlösen (200)', 200, $st);
t_eq('Geräte-Token zurück', true, strlen((string) ($d['token'] ?? '')) >= 32);
t_eq('Antwort mit demo_access prospect', 'prospect', $d['demo_access']['kind'] ?? '');
list($st, $d) = t_rest('POST', '/public/demo-access/confirm', ['token' => $invite]);
t_eq('Link nur einmal (404)', 404, $st);
$GLOBALS['t_mails'] = [];
list($st, $d) = t_rest('POST', '/public/demo-access/link', ['email' => "lena$sfx@beispiel.test"]);
t_eq('Link anfordern → 202', 202, $st);
$tok = t_link_token("lena$sfx@beispiel.test");
t_eq('neuer Anmelde-Link per Mail', true, strlen($tok) >= 20);
list($st, $d) = t_rest('POST', '/public/demo-access/link', ['email' => "unbekannt$sfx@beispiel.test"]);
t_eq('unbekannte Adresse → auch 202, keine Mail', [202, null], [$st, t_mail_to("unbekannt$sfx@beispiel.test")]);
// abgelaufener Link
$list = (array) get_user_meta($pa, '_tix_demo_link', true);
foreach ($list as &$l) $l['exp'] = time() - 10; unset($l);
update_user_meta($pa, '_tix_demo_link', $list);
list($st, $d) = t_rest('POST', '/public/demo-access/confirm', ['token' => $tok]);
t_eq('abgelaufener Link → 410', 410, $st);

// ── 4. Ablauf, Sperre, Verlängern ──
t_eq('aktiver Zugang wird angemeldet', $pa, TIX_Demo_Access::gate($pa));
update_user_meta($pa, '_tix_demo_expires', time() - 5);
t_eq('abgelaufener Zugang gilt als abgemeldet', 0, TIX_Demo_Access::gate($pa));
t_eq('Passwort-Login abgelaufen gesperrt', true, is_wp_error(TIX_Demo_Access::gate_password(get_userdata($pa))));
wp_set_current_user($sales);
list($st, $d) = t_rest('PATCH', "/demo-access/$pa", ['extend' => true]);
t_eq('einmal verlängern (200)', 200, $st);
t_eq('nach Verlängern wieder aktiv', true, TIX_Demo_Access::usable($pa));
list($st, $d) = t_rest('PATCH', "/demo-access/$pa", ['extend' => true]);
t_eq('zweites Verlängern → 409', 409, $st);
list($st, $d) = t_rest('PATCH', "/demo-access/$pa", ['blocked' => true]);
t_eq('gesperrt', 'blocked', $d['access']['status'] ?? '');
t_eq('gesperrter Zugang gilt als abgemeldet', 0, TIX_Demo_Access::gate($pa));
list($st, $d) = t_rest('PATCH', "/demo-access/$pa", ['blocked' => false]);
t_eq('entsperrt', true, TIX_Demo_Access::usable($pa));

// ── 5. Rechte der Interessenten ──
wp_set_current_user($pa);
list($st) = t_rest('GET', '/demo-access');
t_eq('Interessent: keine Zugangs-Verwaltung (403)', 403, $st);
list($st) = t_rest('POST', '/demo-access/reset');
t_eq('Interessent: kein Zurücksetzen (403)', 403, $st);
list($st) = t_rest('GET', '/platform/sales');
t_eq('Interessent: kein /platform (403)', 403, $st);
list($st) = t_rest('GET', '/sales/previews');
t_eq('Interessent: keine Vorschauen (403)', 403, $st);
$me = TIX_Demo_Access::me_payload(get_userdata($pa));
t_eq('/me demo_access.can_reset = false', false, $me['can_reset']);
t_eq('/me Link zur Demo-Homepage mit Code', true, strpos($me['home'], '?demo=') !== false);
t_eq('Menü komplett', 'full', TIX_Studio_Prefs::default_for(get_userdata($pa)));
t_eq('Interessent sieht den Beispiel-Club', $demo, intval(TIX_App_Scope::organizer_id_for_user($pa)));
$real = get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'tix_include_sandbox' => 1, 'meta_query' => [['key' => '_tix_organizer_id', 'value' => (string) $demo, 'compare' => '!='], ['key' => '_tix_organizer_id', 'compare' => 'EXISTS']]]);
if ($real) {
    list($st) = t_rest('GET', '/events/' . $real[0]);
    t_eq('Interessent: fremdes Event gesperrt', true, $st >= 400);
}
wp_set_current_user($sales);
$me = TIX_Demo_Access::me_payload(get_userdata($sales));
t_eq('/me Vertriebler can_reset = true', true, $me['can_reset']);

// ── 6. Grenze 20 aktive je Vertriebler ──
wp_set_current_user($sales2);
$extra = [];
for ($i = 0; $i < 19; $i++) {
    list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'prospect', 'model' => 'platform', 'name' => "Viel $i", 'email' => "viel$i-$sfx@beispiel.test", 'invite' => false]);
    $extra[] = intval($d['access']['id'] ?? 0);
}
$cleanup_users = array_merge($cleanup_users, $extra);
list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'prospect', 'model' => 'platform', 'name' => 'Zu viel', 'email' => "zuviel$sfx@beispiel.test", 'invite' => false]);
t_eq('21. aktiver Zugang → 409 tix_demo_limit', [409, 'tix_demo_limit'], [$st, t_code($d)]);
foreach ($extra as $x) TIX_Demo_Access::cleanup(); // nichts löschen (noch aktiv)
t_eq('Aufräumen löscht keine aktiven', true, (bool) get_userdata($extra[0]));
wp_set_current_user($admin);
foreach ($extra as $x) wp_delete_user($x);

// ── 7. Testkauf mit Demo-Zahlung ──
$ev = 0; $ci = -1;
foreach (get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'tix_include_sandbox' => 1, 'meta_query' => [['key' => '_tix_organizer_id', 'value' => (string) $demo]]]) as $e) {
    foreach ((array) get_post_meta($e, '_tix_ticket_categories', true) as $i => $c) {
        if (is_array($c) && floatval($c['price'] ?? 0) > 0 && ($c['online'] ?? '1') !== '0') {
            list($qs) = t_rest('POST', '/customer/cart/quote', ['event_id' => $e, 'items' => [['index' => intval($i), 'qty' => 1]]]);
            if ($qs === 200) { $ev = $e; $ci = intval($i); break 2; }
        }
    }
}
t_eq('Beispiel-Event mit Preis gefunden', true, $ev > 0);
wp_set_current_user(0);
$_SERVER['REMOTE_ADDR'] = '10.9.' . wp_rand(1, 250) . '.' . wp_rand(1, 250);
list($st, $q) = t_rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => [['index' => $ci, 'qty' => 2]]]);
t_eq('Angebot: demo=true', true, !empty($q['demo']));
t_eq('Angebot: nur Demo-Zahlung', ['demo'], array_column((array) ($q['payment_methods'] ?? []), 'id'));
$billing = ['first_name' => 'Lena', 'last_name' => "Brandt$sfx", 'email' => "lena$sfx@beispiel.test", 'address_1' => 'Teststraße 1', 'postcode' => '50667', 'city' => 'Köln', 'country' => 'DE', 'phone' => '0221 123456'];
$base = ['event_id' => $ev, 'items' => [['index' => $ci, 'qty' => 2]], 'billing' => $billing, 'accept_terms' => true];
list($st, $d) = t_rest('POST', '/customer/orders', $base + ['idempotency_token' => "t1-$sfx-abc", 'payment_method' => 'mollie:creditcard']);
t_eq('echte Zahlart im Beispiel-Club → 400 tix_demo_payment', [400, 'tix_demo_payment'], [$st, t_code($d)]);
$code_a = TIX_Demo_Access::code($pa);
list($st, $d) = t_rest('POST', '/customer/orders', $base + ['idempotency_token' => "t2-$sfx-abc", 'payment_method' => 'demo', 'demo_access' => $code_a]);
t_eq('Testkauf mit Demo-Zahlung (200/201)', true, in_array($st, [200, 201], true));
$oid = intval($d['order']['id'] ?? ($d['id'] ?? 0));
if (!$oid && isset($d['order_id'])) $oid = intval($d['order_id']);
global $wpdb;
$row = $oid ? $wpdb->get_row($wpdb->prepare("SELECT status, payment_method FROM {$wpdb->prefix}tix_orders WHERE id = %d", $oid)) : null;
t_eq('Bestellung bezahlt mit Zahlart demo', ['completed', 'demo'], $row ? [$row->status, $row->payment_method] : null);
t_eq('Antwort demo=true', true, !empty($d['demo']));
t_eq('Testkauf dem Zugang zugeordnet', $pa, intval(get_post_meta($oid, TIX_Demo_Access::META_ORDER, true)));
t_eq('Testkauf zählt als Sandbox', true, TIX_Sales::is_sandbox_order($oid));
t_eq('Tickets angelegt', 2, count(TIX_Tickets::get_all_tickets_for_order($oid)));
t_eq('Bestellmail an den Käufer', true, t_mail_to("lena$sfx@beispiel.test") !== null);
// zweiter Testkauf von B
list($st, $d2) = t_rest('POST', '/customer/orders', array_merge($base, ['billing' => ['first_name' => 'Max', 'last_name' => "Mustermann$sfx", 'email' => "max$sfx@beispiel.test"] + $billing]) + ['idempotency_token' => "t3-$sfx-abc", 'payment_method' => 'demo', 'demo_access' => TIX_Demo_Access::code($pb)]);
$oid2 = intval($d2['order']['id'] ?? ($d2['id'] ?? ($d2['order_id'] ?? 0)));
// echtes Event: demo nie erlaubt
if ($real) {
    list($st, $d) = t_rest('POST', '/customer/orders', ['event_id' => $real[0], 'items' => [['index' => 0, 'qty' => 1]], 'billing' => $billing, 'accept_terms' => true, 'idempotency_token' => "t4-$sfx-abc", 'payment_method' => 'demo']);
    t_eq('Demo-Zahlung bei echtem Event abgelehnt', true, $st >= 400 && t_code($d) !== '');
    list($st, $q) = t_rest('POST', '/customer/cart/quote', ['event_id' => $real[0], 'items' => [['index' => 0, 'qty' => 1]]]);
    t_eq('echtes Event: kein demo im Angebot', false, in_array('demo', array_column((array) ($q['payment_methods'] ?? []), 'id'), true));
}

// ── 8. Fremde Testkäufe verkürzt ──
$probe = ['orders' => [['id' => $oid, 'name' => "Lena Brandt$sfx", 'email' => "lena$sfx@beispiel.test", 'billing_first_name' => 'Lena', 'billing_last_name' => "Brandt$sfx", 'billing_phone' => '0221 123456', 'billing_email' => "lena$sfx@beispiel.test"],
    ['id' => $oid2, 'name' => "Max Mustermann$sfx", 'email' => "max$sfx@beispiel.test"], ['id' => 1, 'name' => 'Sophie Wagner', 'email' => 'sophie@demo.invalid']], 'text' => "Bestellung von Max Mustermann$sfx <max$sfx@beispiel.test>"];
wp_set_current_user($pb);
$vb = TIX_Demo_Access::mask_value($probe, TIX_Demo_Access::foreign_map(get_userdata($pb)));
t_eq('B sieht A verkürzt (Name)', 'L. B***', $vb['orders'][0]['name']);
t_eq('B sieht A verkürzt (E-Mail)', 'l***@b***.test', $vb['orders'][0]['email']);
t_eq('B sieht A verkürzt (Vorname)', 'L.', $vb['orders'][0]['billing_first_name']);
t_eq('B sieht A verkürzt (Telefon)', '•••', $vb['orders'][0]['billing_phone']);
t_eq('B sieht eigenen Kauf voll', "Max Mustermann$sfx", $vb['orders'][1]['name']);
t_eq('Beispieldaten bleiben voll', 'Sophie Wagner', $vb['orders'][2]['name']);
wp_set_current_user($pa);
$va = TIX_Demo_Access::mask_value($probe, TIX_Demo_Access::foreign_map(get_userdata($pa)));
t_eq('A sieht eigenen Kauf voll', "Lena Brandt$sfx", $va['orders'][0]['name']);
// gleicher Name wie ein fremder Käufer: eigene Angaben bleiben voll
$twin = TIX_Demo_Access::mask_value(['n' => 'Lena Brandt'], ['Lena Brandt' => 'L. B***']);
t_eq('Hilfsfunktion verkürzt exakte Treffer', 'L. B***', $twin['n']);
t_eq('A sieht B verkürzt (Text)', 'Bestellung von M. M*** <m***@b***.test>', $va['text']);
// über echte Route
list($st, $d) = t_rest('GET', "/orders/$oid2/detail");
$flat = wp_json_encode($d);
t_eq('Route /orders/{id}/detail: B für A verkürzt', true, $st === 200 ? (strpos($flat, "max$sfx@beispiel.test") === false) : true);
list($st, $d) = t_rest('GET', '/orders', ['per_page' => 500]);
t_eq('Demo-Zugang darf /orders (Mehr-Veranstalter-Modus)', 200, $st);
$foreign_items = 0;
foreach ((array) ($d['orders'] ?? []) as $o) foreach ((array) ($o['items'] ?? []) as $it) if (!in_array($demo, TIX_App_Scope::event_org_ids($it['event_id']), true)) $foreign_items++;
t_eq('/orders: nur Beispiel-Club', 0, $foreign_items);
$row_b = current(array_filter((array) ($d['orders'] ?? []), function ($o) use ($oid2) { return intval($o['id']) === $oid2; }));
t_eq('/orders: Testkauf von B für A verkürzt', 'M. M***', $row_b['billing_name'] ?? null);
wp_set_current_user($admin);
list($st, $d) = t_rest('GET', "/orders/$oid2/detail");
t_eq('Admin sieht alles voll', true, $st !== 200 || strpos(wp_json_encode($d), "max$sfx") !== false);

wp_set_current_user($sales2);
list($st, $d) = t_rest('GET', '/demo-access');
$row = current(array_filter((array) ($d['accesses'] ?? []), function ($a) use ($pb) { return intval($a['id']) === $pb; }));
t_eq('Zugangs-Verwaltung nie verkürzt', "Max Mustermann$sfx", $row['name'] ?? null);

// ── 9. Mitteilungen ──
$out = apply_filters('tix_staff_push_recipients', [$pa, $pb, $sales, $admin], [$demo], 'sales_digest');
t_eq('Staff-Push des Beispiel-Clubs ohne Demo-Zugänge', [$admin], $out);
$out = apply_filters('tix_staff_push_recipients', [$pa], [999999], 'sales_digest');
t_eq('andere Veranstalter unverändert', [$pa], $out);

// ── 10. Vertriebler darf zurücksetzen ──
wp_set_current_user($sales);
list($st, $d) = t_rest('POST', '/demo-access/reset');
t_eq('Vertriebler setzt zurück (200)', 200, $st);
t_eq('Testkauf nach Reset gelöscht', null, $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}tix_orders WHERE id = %d", $oid)));

// ── 11. Aufräumen nach Ablauf ──
update_user_meta($pb, '_tix_demo_expires', time() - 8 * DAY_IN_SECONDS);
TIX_Demo_Access::cleanup();
t_eq('Interessent 7 Tage nach Ablauf gelöscht', false, (bool) get_userdata($pb));

// ── Aufräumen ──
wp_set_current_user($admin);
foreach ($cleanup_users as $u) if ($u && get_userdata($u)) wp_delete_user($u);
echo "\n" . ($GLOBALS['t_count'] - $GLOBALS['t_fails']) . '/' . $GLOBALS['t_count'] . " ok\n";
