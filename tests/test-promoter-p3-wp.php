<?php
/** Promoter-Ausbau Paket 3 (1.38.663): Regeln, Kategorie-Provision, Staffel-Bonus, Obergrenzen, Teamleiter, Mindest-Auszahlung,
 *  Auto-Freigabe/-Zuordnung, Erst-/Letzt-Klick, Cookie-Dauer, CSV, Rangliste, Kurzlink. wp eval-file …/tests/test-promoter-p3-wp.php (beide Modi). */
if (!defined('ABSPATH') || !class_exists('TIX_Promoter_Rules')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function p3_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function p3_rest($m, $r, array $b = [], $tok = '') { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($tok) $q->set_header('X-Tix-Promoter-Token', $tok); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
TIX_Order::create_tables(); TIX_Order_Attribution::maybe_create();
TIX_Promoter_DB::create_tables(); TIX_Promoter_DB::ensure_email_column(); TIX_Promoter_DB::ensure_org_column(); TIX_Promoter_DB::ensure_clicks_table(); TIX_Promoter_Portal::maybe_create(); TIX_Promoter_Rules::ensure_columns();
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
add_filter('pre_wp_mail', '__return_true');
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_p%' OR option_name LIKE '\\_transient\\_timeout\\_%tix\\_app\\_rl\\_p%'");
$keep = []; foreach (['tix_settings', TIX_Promoter_Rules::OPT, TIX_Promoter_Notify::OPT_Q] as $o) $keep[$o] = get_option($o, null);
$s = get_option('tix_settings', []); $s['promoter_enabled'] = 1; $s['promoter_self_signup'] = 1; $s['promoter_cookie_days'] = 30; update_option('tix_settings', $s);
$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $proms = []; $orders = [];
$tc = TIX_Promoter_DB::table_commissions(); $tp = TIX_Promoter_DB::table_promoters();
$admin = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
$owner = wp_insert_user(['user_login' => "p3_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "p3_owner_$sfx@example.test", 'role' => $multi ? 'tix_organizer' : 'administrator']); $users[] = $owner;
$other = wp_insert_user(['user_login' => "p3_other_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "p3_other_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $other;
$org = 0; $org2 = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'P3 Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'P3 Andere', 'meta_input' => ['_tix_org_user_id' => $other]]); $posts[] = $org2;
} else {
    delete_option(TIX_Promoter_Rules::OPT);
}
$cats = [['name' => 'Regulär', 'price' => 10, 'qty' => 100, 'stock' => 100], ['name' => 'VIP', 'price' => 30, 'qty' => 20, 'stock' => 20]];
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "P3 Party $sfx", 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS), '_tix_ticket_categories' => $cats]]); $posts[] = $ev;
$mk = function ($name, $code) use ($org, &$proms, $sfx) { $id = TIX_Promoter_DB::insert_promoter(['user_id' => 0, 'organizer_id' => $org, 'email' => "p3_{$code}_$sfx@example.test", 'promoter_code' => "p3$code$sfx", 'display_name' => $name, 'status' => 'active']); $proms[] = $id; return $id; };
$pa = $mk('Anna Leiterin', 'anna'); $pb = $mk('Ben Becker', 'ben'); $pc = $mk('Cleo Clark', 'cleo');
TIX_Promoter_DB::assign_event(['promoter_id' => $pb, 'event_id' => $ev, 'commission_type' => 'fixed', 'commission_value' => 2, 'status' => 'active']);
$ot = TIX_Order::table_name(); $oi = TIX_Order::items_table_name();
$order = function ($pid, $qty, $cat) use ($wpdb, $ot, $oi, $ev, $cats, &$orders, $sfx) {
    $price = $cats[$cat]['price'] * $qty;
    $wpdb->insert($ot, ['order_number' => 'P3' . wp_rand(), 'status' => 'completed', 'order_key' => wp_generate_password(12, false), 'total' => $price, 'subtotal' => $price, 'payment_method' => 'mollie', 'billing_email' => "p3_kunde_$sfx@example.test", 'date_created' => current_time('mysql')]);
    $id = intval($wpdb->insert_id); $orders[] = $id;
    $wpdb->insert($oi, ['order_id' => $id, 'event_id' => $ev, 'quantity' => $qty, 'total' => $price, 'name' => 'P3 – ' . $cats[$cat]['name'], 'cat_name' => $cats[$cat]['name'], 'meta' => wp_json_encode(['cat_index' => $cat])]);
    TIX_Order_Attribution::set_meta($id, '_tix_promoter_id', $pid);
    TIX_Order_Attribution::set_meta($id, '_tix_promoter_attribution', 'referral');
    TIX_Promoter::calculate_commissions($id);
    return $id;
};
$rows = function ($oid) use ($wpdb, $tc) { return array_map(function ($r) { return [intval($r->promoter_id), floatval($r->a), (string) $r->attribution, intval($r->t)]; }, (array) $wpdb->get_results($wpdb->prepare("SELECT promoter_id, commission_amount a, attribution, tickets_qty t FROM $tc WHERE order_id = %d ORDER BY id", $oid))); };
wp_set_current_user($multi ? $owner : $admin);

// ═══ 1. Regeln ═══
[$st, $d] = p3_rest('GET', '/organizer/promoter-rules');
p3_eq('Vorgaben: letzter Klick, keine Mindest-Auszahlung, Team aus', [200, 'last', 0.0, false], [$st, $d['rules']['attribution'] ?? '', floatval($d['rules']['min_payout'] ?? -1), $d['rules']['team']['enabled'] ?? null]);
[$st, $d, $c] = p3_rest('POST', '/organizer/promoter-rules', ['tiers' => [['min_tickets' => 1, 'type' => 'fixed', 'value' => 1]]]);
p3_eq('Staffel ab 1 Ticket → 400 am Feld', [400, 'tiers'], [$st, $d['data']['field'] ?? '']);
[$st, $d] = p3_rest('POST', '/organizer/promoter-rules', ['tiers' => [['min_tickets' => 3, 'type' => 'fixed', 'value' => 1]], 'cap_tickets' => 5, 'team' => ['enabled' => true, 'share' => 10],
    'min_payout' => 20, 'attribution' => 'first', 'leaderboard' => true, 'auto_approve' => true, 'auto_assign' => ['enabled' => true, 'type' => 'fixed', 'value' => 1.5]]);
p3_eq('Regeln gespeichert', [200, 5, 'first', true, 20.0], [$st, $d['rules']['cap_tickets'] ?? 0, $d['rules']['attribution'] ?? '', $d['rules']['team']['enabled'] ?? null, floatval($d['rules']['min_payout'] ?? 0)]);
[$st, $d] = p3_rest('POST', "/organizer/events/$ev/promoter-categories", ['categories' => [['index' => 1, 'commission' => ['type' => 'fixed', 'value' => 5]]]]);
p3_eq('Kategorie-Provision: VIP 5 € je Ticket, Regulär ohne', [200, null, 5.0], [$st, array_key_exists('commission', (array) ($d['categories'][0] ?? [])) ? $d['categories'][0]['commission'] : 'fehlt', floatval($d['categories'][1]['commission']['value'] ?? 0)]);
[$st, $d] = p3_rest('POST', "/organizer/promoters/$pb/rules", ['leader_id' => $pa, 'cookie_days' => 7]);
p3_eq('Teamleiter und eigene Cookie-Dauer', [200, $pa, 7], [$st, $d['leader']['id'] ?? 0, $d['cookie_days'] ?? 0]);
[$st, $d] = p3_rest('POST', "/organizer/promoters/$pa/rules", ['leader_id' => $pb]);
p3_eq('Kreis verhindert: Teamleiter kann keinen Leiter bekommen', 400, $st);

// ═══ 2. Provision ═══
$o1 = $order($pb, 2, 0);
p3_eq('2 × Regulär: 2 × 2 €, Teamleiterin 10 % (eigene Zeile, ohne Tickets)', [[$pb, 4.0, 'referral', 2], [$pa, 0.4, 'team', 0]], $rows($o1));
$o2 = $order($pb, 2, 1);
p3_eq('2 × VIP: Kategorie 5 € + Staffel ab Ticket 3 (+1 €) = 12 €, Leiterin 1,20 €', [[$pb, 12.0, 'referral', 2], [$pa, 1.2, 'team', 0]], $rows($o2));
$o3 = $order($pb, 3, 0);
p3_eq('Obergrenze 5 Tickets: nur 1 von 3 zählt (2 € + 1 € Bonus)', [[$pb, 3.0, 'referral', 3], [$pa, 0.3, 'team', 0]], $rows($o3));
[$st, $d] = p3_rest('GET', "/organizer/promoters/$pa");
p3_eq('Teamleiterin: Team-Provision zählt nicht als Bestellung, Teamgröße 1', [0, 1.9, 1], [intval($d['promoter']['orders'] ?? -1), floatval($d['promoter']['commission']['total'] ?? 0), intval($d['promoter']['team_size'] ?? 0)]);

// ═══ 3. Mindest-Auszahlung ═══
update_post_meta($ev, '_tix_date_start', wp_date('Y-m-d', time() - 3 * DAY_IN_SECONDS));
[$st, $d, $c] = p3_rest('POST', '/organizer/promoter-payouts', ['promoter_id' => $pb]);
p3_eq('19 € < 20 € Mindest-Auszahlung → 409', [409, 'tix_payout_min'], [$st, $c]);
p3_rest('POST', '/organizer/promoter-rules', ['min_payout' => 10]);
[$st, $d] = p3_rest('POST', '/organizer/promoter-payouts', ['promoter_id' => $pb]);
p3_eq('Ab 10 €: Auszahlung über 19 €', [201, 19.0], [$st, floatval($d['payout']['commission'] ?? 0)]);
update_post_meta($ev, '_tix_date_start', wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS));

// ═══ 4. Auto-Freigabe und Auto-Zuordnung ═══
wp_set_current_user(0);
[$st] = p3_rest('POST', '/promoter/signup', ['name' => 'Dora Neu', 'email' => "p3_dora_$sfx@example.test"] + ($multi ? ['organizer_id' => $org] : []));
$dora = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tp WHERE email = %s", "p3_dora_$sfx@example.test")); if ($dora) $proms[] = intval($dora->id);
$g = $dora ? TIX_Promoter_DB::get_global_assignment(intval($dora->id)) : null;
p3_eq('Selbst-Anmeldung sofort frei, „Alle Events“ mit 1,50 €', [200, 'active', 'fixed', 1.5], [$st, $dora->status ?? '', (string) ($g->commission_type ?? ''), floatval($g->commission_value ?? 0)]);

// ═══ 5. Erst- bzw. Letzt-Klick, Cookie-Dauer ═══
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone) P3';
$_COOKIE = ['tix_cart_session' => 'p3sess' . $sfx];
$_GET['ref'] = "p3ben$sfx"; TIX_Promoter::detect_referral();
$_GET['ref'] = "p3cleo$sfx"; TIX_Promoter::detect_referral();
p3_eq('Erst-Klick: der erste Promoter bleibt', "p3ben$sfx", TIX_Promoter::session_ref());
wp_set_current_user($multi ? $owner : $admin);
p3_rest('POST', '/organizer/promoter-rules', ['attribution' => 'last']);
wp_set_current_user(0);
$_GET['ref'] = "p3cleo$sfx"; TIX_Promoter::detect_referral();
p3_eq('Letzt-Klick: der neue Promoter zählt', "p3cleo$sfx", TIX_Promoter::session_ref());
unset($_GET['ref']); $_COOKIE = [];
[$st, $d] = p3_rest('GET', '/public/promoter-config', ['ref' => "p3ben$sfx"]);
[$st2, $d2] = p3_rest('GET', '/public/promoter-config', ['ref' => "p3cleo$sfx"]);
p3_eq('Öffentliche Konfiguration: Cookie-Dauer je Promoter (7) bzw. Seite (30), gültig', [200, 7, 30, true, 'last'], [$st, $d['cookie_days'] ?? 0, $d2['cookie_days'] ?? 0, $d['valid'] ?? null, $d2['attribution'] ?? '']);

// ═══ 6. Rangliste, CSV, Kurzlink ═══
$issue = new ReflectionMethod('TIX_Promoter_Portal', 'issue'); $issue->setAccessible(true);
$tokB = $issue->invoke(null, $pb, 'test')[0];
[$st, $d] = p3_rest('GET', '/promoter/leaderboard', [], $tokB);
p3_eq('Rangliste: Ben vorn mit 7 Tickets, Name gekürzt, Teamzeilen zählen nicht', [200, true, 'Ben B.', 7, true], [$st, $d['enabled'] ?? null, $d['top'][0]['name'] ?? '', $d['top'][0]['tickets'] ?? 0, $d['me']['me'] ?? null]);
wp_set_current_user($multi ? $owner : $admin);
[$st, $d] = p3_rest('GET', '/organizer/promoters/export', ['type' => 'promoters']);
p3_eq('CSV Promoter: Kopf, Ben mit Code', [200, true, true], [$st, strpos((string) ($d['csv'] ?? ''), 'Name;Code;E-Mail') !== false, strpos((string) ($d['csv'] ?? ''), "p3ben$sfx") !== false]);
[$st, $d] = p3_rest('GET', '/organizer/promoters/export', ['type' => 'sales']);
$mine = count(array_filter(explode("\n", (string) ($d['csv'] ?? '')), function ($l) use ($sfx) { return strpos($l, "P3 Party $sfx") !== false; }));
p3_eq('CSV Verkäufe: 6 eigene Zeilen inkl. Team, keine Käufer-Adresse', [200, 6, false], [$st, $mine, strpos((string) ($d['csv'] ?? ''), "p3_kunde_$sfx") !== false]);
[$st, $d] = p3_rest('GET', "/public/go/p3ben$sfx", ['e' => $ev]);
p3_eq('Kurzlink (Rückfall der Marketing-Kurzlinks): Event-Ziel mit ?ref', [200, true, true], [$st, strpos((string) ($d['url'] ?? ''), "ref=p3ben$sfx") !== false, strpos((string) ($d['url'] ?? ''), (string) wp_parse_url(get_permalink($ev), PHP_URL_PATH)) !== false]);
[$st] = p3_rest('GET', '/public/go/gibtsnicht' . $sfx);
p3_eq('Unbekannter Kurzlink → 404', 404, $st);
[$st, $d] = p3_rest('GET', "/organizer/promoters/$pb");
p3_eq('Links tragen den Kurzlink /go/', true, strpos((string) ($d['promoter']['links']['short'] ?? ''), "/go/p3ben$sfx") !== false);

// ═══ 7. Mandanten ═══
if ($multi) {
    wp_set_current_user($other);
    [$st, $d] = p3_rest('GET', '/organizer/promoter-rules');
    p3_eq('Anderer Veranstalter: eigene Regeln (Vorgaben)', [200, 0], [$st, intval($d['rules']['cap_tickets'] ?? -1)]);
    [$st] = p3_rest('POST', "/organizer/promoters/$pb/rules", ['cookie_days' => 90]);
    [$st2] = p3_rest('GET', "/organizer/events/$ev/promoter-categories");
    p3_eq('Fremder Promoter bzw. fremdes Event → 404', [404, 404], [$st, $st2]);
    [$st, $d] = p3_rest('GET', '/organizer/promoters/export', ['type' => 'sales']);
    p3_eq('Fremder CSV-Export: leer', 0, intval($d['rows'] ?? -1));
}

// Aufräumen
wp_set_current_user($admin);
foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
if ($org) delete_post_meta($org, TIX_Promoter_Rules::META);
foreach ($orders as $o) { $wpdb->delete($oi, ['order_id' => $o]); $wpdb->delete($ot, ['id' => $o]); $wpdb->delete(TIX_Order_Attribution::table(), ['order_id' => $o]); }
foreach (array_unique(array_filter($proms)) as $p) {
    foreach (['table_events', 'table_commissions', 'table_payouts', 'table_clicks'] as $t) $wpdb->delete(TIX_Promoter_DB::$t(), ['promoter_id' => $p]);
    $wpdb->delete(TIX_Promoter_Portal::table(), ['promoter_id' => $p]); $wpdb->delete($tp, ['id' => $p]);
}
foreach (array_unique(array_filter($posts)) as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
