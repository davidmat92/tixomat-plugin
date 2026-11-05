<?php
/** Promoter-Ausbau Paket 4 (1.38.661): Promoter an der Abendkasse (/pos/orders promoter_code, /pos/promoters), AASA ?ref.
 *  wp eval-file …/tests/test-promoter-p4-wp.php (beide Modi). */
if (!defined('ABSPATH') || !method_exists('TIX_Promoter', 'resolve_code')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function p4_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function p4_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
TIX_Order::create_tables(); TIX_Order_Attribution::maybe_create();
TIX_Promoter_DB::create_tables(); TIX_Promoter_DB::ensure_email_column(); TIX_Promoter_DB::ensure_org_column(); TIX_Promoter_DB::ensure_clicks_table();
add_filter('pre_wp_mail', '__return_true');
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $proms = [];
$tc = TIX_Promoter_DB::table_commissions(); $tp = TIX_Promoter_DB::table_promoters();
$admin = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
$owner = wp_insert_user(['user_login' => "p4_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "p4_owner_$sfx@example.test", 'role' => $multi ? 'tix_organizer' : 'administrator']); $users[] = $owner;
$org = 0; $org2 = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'P4 Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'P4 Andere']); $posts[] = $org2;
}
$cats = [['name' => 'Regulär', 'price' => 10, 'qty' => 100, 'stock' => 100]];
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "P4 Party $sfx", 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() + 5 * DAY_IN_SECONDS), '_tix_time_start' => '20:00', '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1', '_tix_ticket_categories' => $cats]]); $posts[] = $ev;
$pa = TIX_Promoter_DB::insert_promoter(['user_id' => 0, 'organizer_id' => $org, 'email' => "p4_a_$sfx@example.test", 'promoter_code' => "p4anna$sfx", 'display_name' => "Anna P4 $sfx", 'status' => 'active']); $proms[] = $pa;
TIX_Promoter_DB::assign_event(['promoter_id' => $pa, 'event_id' => $ev, 'commission_type' => 'fixed', 'commission_value' => 2, 'discount_type' => 'fixed', 'discount_value' => 1, 'promo_code' => "P4ANNA$sfx", 'status' => 'active']);
$pb = TIX_Promoter_DB::insert_promoter(['user_id' => 0, 'organizer_id' => $org2, 'email' => "p4_b_$sfx@example.test", 'promoter_code' => "p4bert$sfx", 'display_name' => "Bert P4 $sfx", 'status' => 'active']); $proms[] = $pb;
$pc = TIX_Promoter_DB::insert_promoter(['user_id' => 0, 'organizer_id' => $org, 'email' => "p4_c_$sfx@example.test", 'promoter_code' => "p4cleo$sfx", 'display_name' => "Cleo P4 $sfx", 'status' => 'inactive']); $proms[] = $pc;
$com = function ($oid) use ($wpdb, $tc) { return $wpdb->get_results($wpdb->prepare("SELECT promoter_id, commission_amount a, attribution FROM $tc WHERE order_id = %d", $oid)); };
$orders = [];
$sale = function (array $extra = []) use ($ev, &$orders) {
    [$st, $d, $c] = p4_rest('POST', '/pos/orders', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 2]], 'payment' => 'cash', 'checkin' => true] + $extra);
    if (!empty($d['order_id'])) $orders[] = intval($d['order_id']);
    return [$st, $d, $c];
};
wp_set_current_user($multi ? $owner : $admin);

// ═══ Suche ═══
[$st, $d] = p4_rest('GET', '/pos/promoters', ['event_id' => $ev, 'q' => "P4 $sfx"]);
$names = array_column((array) ($d['promoters'] ?? []), 'name');
p4_eq('Suche: aktive Promoter, inaktive nie', [200, true, false], [$st, in_array("Anna P4 $sfx", $names, true), in_array("Cleo P4 $sfx", $names, true)]);
if ($multi) p4_eq('Plattform: Promoter anderer Veranstalter nicht in der Suche', false, in_array("Bert P4 $sfx", $names, true));
[$st] = p4_rest('GET', '/pos/promoters', []);
p4_eq('Suche ohne Event → 400', 400, $st);

// ═══ Verkauf mit Promoter ═══
[$st, $d] = $sale(['promoter_code' => "p4anna$sfx"]);
$c1 = $com(intval($d['order_id'] ?? 0));
p4_eq('Kassenverkauf mit Promoter-Code: Promoter in der Antwort, Provision 2 × 2 € als „pos“', [200, $pa, 1, 4.0, 'pos'],
    [$st, $d['promoter']['id'] ?? 0, count($c1), floatval($c1[0]->a ?? 0), (string) ($c1[0]->attribution ?? '')]);
[$st, $d] = $sale(['promoter_code' => "P4ANNA$sfx"]);
p4_eq('Rabatt-Code im Promoter-Feld findet denselben Promoter', [200, $pa], [$st, $d['promoter']['id'] ?? 0]);
$n0 = count($orders);
[$st, $d, $c] = $sale(['promoter_code' => "gibtsnicht$sfx"]);
p4_eq('Unbekannter Code → 409 am Feld, kein Verkauf', [409, 'tix_promoter_unknown', 'promoter_code', $n0], [$st, $c, $d['data']['field'] ?? '', count($orders)]);
[$st, $d, $c] = $sale(['promoter_code' => "p4cleo$sfx"]);
p4_eq('Inaktiver Promoter → 409', [409, 'tix_promoter_unknown'], [$st, $c]);
if ($multi) {
    [$st, $d, $c] = $sale(['promoter_code' => "p4bert$sfx"]);
    p4_eq('Plattform: Promoter eines anderen Veranstalters → 409', [409, 'tix_promoter_not_allowed'], [$st, $c]);
}
// Cookie am Kassengerät ordnet nichts zu
$_COOKIE[TIX_Promoter::COOKIE_NAME] = "p4anna$sfx";
[$st, $d] = $sale();
unset($_COOKIE[TIX_Promoter::COOKIE_NAME]);
p4_eq('Ohne Feld: Cookie am Kassengerät ordnet keinen Kassenverkauf zu', [200, null, 0], [$st, array_key_exists('promoter', (array) $d) ? $d['promoter'] : 'fehlt', count($com(intval($d['order_id'] ?? 0)))]);

// ═══ AASA ?ref ═══
$ref_before = get_option(TIX_App_Links::OPTION_REF, null);
$paths_before = get_option(TIX_App_Links::OPTION_PATHS, null);
update_option(TIX_App_Links::OPTION_PATHS, ['events'], false);
update_option(TIX_App_Links::OPTION_REF, '0', false);
$doc = wp_json_encode(TIX_App_Links::document(['ABCDE12345.de.test.app']));
p4_eq('AASA: Promoter-Startseitenlink aus (Vorgabe), Event-Links mit ?ref über /events/*', [false, true], [strpos($doc, '"ref":"?*"') !== false, strpos($doc, '"\/events\/*"') !== false || strpos($doc, '"/events/*"') !== false]);
update_option(TIX_App_Links::OPTION_REF, '1', false);
$doc = TIX_App_Links::document(['ABCDE12345.de.test.app']);
$refc = array_values(array_filter($doc['applinks']['details'][0]['components'], function ($c) { return ($c['?']['ref'] ?? '') === '?*' && empty($c['exclude']); }));
p4_eq('AASA mit Schalter: / mit ?ref freigegeben (nach den Ausschlüssen)', [1, '/'], [count($refc), $refc[0]['/'] ?? '']);
if ($ref_before === null) delete_option(TIX_App_Links::OPTION_REF); else update_option(TIX_App_Links::OPTION_REF, $ref_before, false);
if ($paths_before === null) delete_option(TIX_App_Links::OPTION_PATHS); else update_option(TIX_App_Links::OPTION_PATHS, $paths_before, false);

// Aufräumen
wp_set_current_user($admin);
foreach ($orders as $o) {
    foreach ((array) get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $o]) as $t) wp_delete_post($t, true);
    $wpdb->delete($tc, ['order_id' => $o]); $wpdb->delete(TIX_Order::items_table_name(), ['order_id' => $o]); $wpdb->delete(TIX_Order::table_name(), ['id' => $o]); $wpdb->delete(TIX_Order_Attribution::table(), ['order_id' => $o]);
}
foreach (array_unique(array_filter($proms)) as $p) { $wpdb->delete(TIX_Promoter_DB::table_events(), ['promoter_id' => $p]); $wpdb->delete($tc, ['promoter_id' => $p]); $wpdb->delete($tp, ['id' => $p]); }
foreach (array_unique(array_filter($posts)) as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
