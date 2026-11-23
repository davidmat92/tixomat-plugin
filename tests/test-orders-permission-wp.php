<?php
/**
 * Bestellungen für Inhaber/Veranstalter auf Einzel-Seiten (1.38.909, Fund mallorcaparty-schwelm.de: Studio „Bestellungen – gerade nicht erreichbar“,
 * GET /orders 403 für tix_organizer ohne edit_posts). wp eval-file …/test-orders-permission-wp.php (Einzel-Seite und Plattform)
 */
if (!defined('ABSPATH') || !class_exists('TIX_REST_API') || !method_exists('TIX_REST_API', 'check_orders')) { echo "Tixomat ab 1.38.909 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$get = function ($uid, $r) { wp_set_current_user($uid); [$p, $qs] = array_pad(explode('?', $r, 2), 2, ''); $q = new WP_REST_Request('GET', '/tixomat/v1' . $p); if ($qs !== '') { parse_str($qs, $qq); $q->set_query_params($qq); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), is_array($d) ? $d : []]; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$bak = get_option('tix_demo_instance', null); update_option('tix_demo_instance', '0', false);
$adm = get_users(['role' => 'administrator', 'number' => 1])[0]->ID;
$sfx = 'op' . wp_rand(1000, 9999);
$users = []; $posts = []; $orders = [];
$mku = function ($role, $meta = []) use (&$users, $sfx) { $id = wp_insert_user(['user_login' => "$role-$sfx-" . count($users), 'user_email' => "$role-$sfx-" . count($users) . '@example.org', 'user_pass' => wp_generate_password(), 'role' => $role]); foreach ($meta as $k => $v) update_user_meta($id, $k, $v); $users[] = $id; return $id; };
try {
    if ($multi) {
        [$s] = $get(0, '/orders'); $eq('Plattform: ohne Anmeldung 401', 401, $s);
        [$s] = $get($adm, '/orders'); $eq('Plattform: Admin 200', 200, $s);
        $o = $mku('tix_organizer');
        [$s] = $get($o, '/orders/1');
        $eq('Plattform: Veranstalter ohne freigegebenen Veranstalter auf /orders/{id} → 403 (wie /orders), nicht mehr nur edit_posts', 403, $s);
    } else {
        $orgp = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => "OP Club $sfx"]); $posts[] = $orgp;
        $ev1 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "OP Eigen $sfx", 'meta_input' => ['_tix_date_start' => wp_date('Y-m-d', time() + 20 * DAY_IN_SECONDS), '_tix_organizer_id' => $orgp]]); $posts[] = $ev1;
        $ev2 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "OP Fremd $sfx", 'meta_input' => ['_tix_date_start' => wp_date('Y-m-d', time() + 20 * DAY_IN_SECONDS)]]); $posts[] = $ev2;
        $mk = function ($ev) use ($sfx) {
            $it = [['event_id' => $ev, 'cat_index' => 0, 'name' => 'Normal', 'event_title' => 'T', 'price' => 10.0, 'qty' => 1, 'meta' => []]];
            TIX_Native_Checkout::save_cart(['items' => $it, 'coupon' => null]);
            $oid = TIX_Native_Checkout::create_order(['billing_first_name' => 'O', 'billing_last_name' => 'P', 'billing_email' => "kauf-$sfx@example.org", 'payment_method' => 'bank', 'total' => 10.0, 'items' => $it]);
            TIX_Native_Checkout::clear_cart();
            return intval($oid);
        };
        $o1 = $mk($ev1); $o2 = $mk($ev2); $orders = [$o1, $o2];
        $ids = function ($d) { return array_map('intval', array_column((array) ($d['orders'] ?? []), 'id')); };
        $q = '/orders?per_page=200';
        [$s] = $get(0, $q); $eq('Ohne Anmeldung: 401', 401, $s);
        $org = $mku('tix_organizer');
        [$s, $d] = $get($org, $q);
        $eq('Veranstalter (tix_organizer, ohne edit_posts, unverknüpft = Ein-Club): 200, sieht beide', [200, true, true], [$s, in_array($o1, $ids($d), true), in_array($o2, $ids($d), true)]);
        $owner = $mku('subscriber', ['_tix_site_owner' => 1]);
        [$s] = $get($owner, $q); $eq('Inhaber (_tix_site_owner): 200', 200, $s);
        $lk = $mku('tix_organizer'); update_post_meta($orgp, '_tix_org_user_id', $lk);
        [$s, $d] = $get($lk, $q);
        $eq('Verknüpfter Veranstalter: nur Bestellungen eigener Events', [200, true, false], [$s, in_array($o1, $ids($d), true), in_array($o2, $ids($d), true)]);
        $eq('… Einzelbestellung fremd 403, eigen 200', [403, 200], [$get($lk, "/orders/$o2")[0], $get($lk, "/orders/$o1")[0]]);
        $own2 = $mku('tix_organizer', ['_tix_site_owner' => 1]); delete_post_meta($orgp, '_tix_org_user_id'); update_post_meta($orgp, '_tix_org_user_id', $own2);
        [$s, $d] = $get($own2, $q);
        $eq('Inhaber, auch wenn verknüpft: sieht alles', [200, true, true], [$s, in_array($o1, $ids($d), true), in_array($o2, $ids($d), true)]);
        $st = $mku('tix_staff');
        [$s] = $get($st, $q); $eq('App-Team (tix_staff, Einlass): weiter 403', 403, $s);
        $sub = $mku('subscriber');
        [$s] = $get($sub, $q); $eq('Kunde: 403', 403, $s);
        [$s] = $get($adm, $q); $eq('Admin: 200 wie bisher', 200, $s);
    }
} finally {
    wp_set_current_user(0);
    foreach ($orders as $o) { $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $o]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $o]); }
    foreach ($posts as $p) wp_delete_post($p, true);
    require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ($users as $u) wp_delete_user($u);
    if ($bak === null) delete_option('tix_demo_instance'); else update_option('tix_demo_instance', $bak, false);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
