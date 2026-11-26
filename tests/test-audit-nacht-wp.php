<?php
/**
 * Audit 26.11. (Nachtschicht): Sicherheitsfixes.
 *   wp eval-file …/test-audit-nacht-wp.php
 */
if (!defined('ABSPATH')) return;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
global $wpdb;
$sfx = wp_generate_password(6, false, false);
$users = []; $posts = []; $orders = [];
$mk = function ($role, $email = null) use (&$users, $sfx) {
    $id = wp_insert_user(['user_login' => "an_{$role}_" . count($users) . "_$sfx", 'user_pass' => wp_generate_password(20), 'user_email' => $email ?: "an_{$role}_" . count($users) . "_$sfx@example.test", 'role' => $role]);
    $users[] = $id; return $id;
};
// AJAX-Abbruch (wp_send_json_error) als Ausnahme abfangen
$ajax = function (callable $fn) {
    add_filter('wp_doing_ajax', '__return_true');
    $h = function () { return function ($msg) { throw new RuntimeException('die'); }; };
    add_filter('wp_die_ajax_handler', $h);
    ob_start(); $out = null;
    try { $fn(); } catch (RuntimeException $e) { }
    $out = ob_get_clean();
    remove_filter('wp_die_ajax_handler', $h); remove_filter('wp_doing_ajax', '__return_true');
    return json_decode((string) $out, true);
};

echo "── Einlass: Zugang ──\n";
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "AN Event $sfx"]); $posts[] = $ev;
wp_set_current_user(0);
$r = $ajax(function () use ($ev) { TIX_Checkin::door_ok($ev, '', ''); });
$eq('Ohne Passwort, nicht angemeldet → abgewiesen mit Hinweis', [false, true], [$r['success'] ?? null, strpos((string) ($r['data']['message'] ?? ''), 'kein Einlass-Passwort') !== false]);
$eq('Mit Passwort: richtig → ja, falsch → nein', [true, false], [TIX_Checkin::door_ok($ev, 'geheim', 'geheim'), TIX_Checkin::door_ok($ev, 'geheim', 'falsch')]);
$admin = $mk('administrator');
wp_set_current_user($admin);
$eq('Admin angemeldet: ohne Passwort ja', true, TIX_Checkin::door_ok($ev, '', ''));
$sub = $mk('subscriber');
wp_set_current_user($sub);
$r = $ajax(function () use ($ev) { TIX_Checkin::door_ok($ev, '', ''); });
$eq('Gast-Konto angemeldet ohne Zugriff: abgewiesen', false, $r['success'] ?? null);
// Liste über AJAX ohne Passwort
wp_set_current_user(0);
$_POST = ['nonce' => wp_create_nonce('tix_checkin_action'), 'event_id' => $ev, 'password' => ''];
$_REQUEST = $_POST;
$r = $ajax(function () { TIX_Checkin::ajax_combined_list(); });
$eq('Gästeliste ohne Passwort/Anmeldung: keine Daten', [false, false], [$r['success'] ?? null, isset($r['data']['list']) || isset($r['data']['tickets'])]);
$_POST = $_REQUEST = [];

echo "── Einstellungen im selben Request ──\n";
$old = get_option('tix_settings', null);
$s = is_array($old) ? $old : [];
$s['btn_text_buy'] = "AN-$sfx"; update_option('tix_settings', $s);
$eq('tix_get_settings sieht die Änderung sofort', "AN-$sfx", tix_get_settings('btn_text_buy'));
if ($old === null) delete_option('tix_settings'); else update_option('tix_settings', $old);

echo "── Benutzer-Wechsel ──\n";
$cust = $mk('subscriber');
$rm = new ReflectionMethod('TIX_User_Switch', 'sign'); $rm->setAccessible(true);
$_COOKIE[TIX_User_Switch::COOKIE_NAME] = $admin . '|' . hash_hmac('sha256', 'tix-switch|' . $admin, wp_salt('auth'));
wp_set_current_user($cust);
$eq('Altes Cookie-Format (ohne Ablauf) gilt nicht mehr', 0, TIX_User_Switch::get_original_admin_id());
$exp = time() + 3600; $tok = 'tok' . $sfx;
update_user_meta($admin, TIX_User_Switch::META_TOKEN, ['hash' => hash('sha256', $tok), 'target' => $cust, 'exp' => $exp]);
$_COOKIE[TIX_User_Switch::COOKIE_NAME] = "$admin|$cust|$exp|$tok|" . $rm->invoke(null, $admin, $cust, $exp, $tok);
$eq('Gültig im Ziel-Konto', $admin, TIX_User_Switch::get_original_admin_id());
wp_set_current_user(0);
$eq('Abgemeldet: kein Rückweg', 0, TIX_User_Switch::get_original_admin_id());
wp_set_current_user($cust);
delete_user_meta($admin, TIX_User_Switch::META_TOKEN);
$eq('Einmal-Schlüssel verbraucht: kein Rückweg', 0, TIX_User_Switch::get_original_admin_id());
$exp2 = time() - 10;
update_user_meta($admin, TIX_User_Switch::META_TOKEN, ['hash' => hash('sha256', $tok), 'target' => $cust, 'exp' => $exp2]);
$_COOKIE[TIX_User_Switch::COOKIE_NAME] = "$admin|$cust|$exp2|$tok|" . $rm->invoke(null, $admin, $cust, $exp2, $tok);
$eq('Abgelaufen: kein Rückweg', 0, TIX_User_Switch::get_original_admin_id());
unset($_COOKIE[TIX_User_Switch::COOKIE_NAME]); delete_user_meta($admin, TIX_User_Switch::META_TOKEN);

echo "── Bestellungen ──\n";
$T = TIX_Order::table_name();
$mko = function ($pm, $email, $cust = 0) use ($wpdb, $T, &$orders, $sfx) {
    $wpdb->insert($T, ['order_number' => 'AN-' . $sfx . '-' . count($orders), 'status' => 'completed', 'total' => 10, 'payment_method' => $pm, 'billing_email' => $email,
        'customer_id' => $cust, 'order_key' => 'k' . wp_generate_password(20, false, false), 'date_created' => current_time('mysql'), 'date_modified' => current_time('mysql')]);
    $orders[] = intval($wpdb->insert_id); return intval($wpdb->insert_id);
};
$online = $mko('mollie', "opfer$sfx@example.test");
wp_set_current_user($admin);
$req = new WP_REST_Request('POST', "/tixomat/v1/pos/orders/$online/email");
$req->set_header('content-type', 'application/json'); $req->set_body(wp_json_encode(['email' => "dieb$sfx@example.test"]));
$res = rest_do_request($req);
$eq('Kasse: E-Mail einer Online-Bestellung ändern → 403', [403, 'tix_pos_email_locked'], [$res->get_status(), $res->get_data()['code'] ?? null]);
$eq('… Rechnungs-E-Mail unverändert', "opfer$sfx@example.test", (string) $wpdb->get_var($wpdb->prepare("SELECT billing_email FROM $T WHERE id = %d", $online)));

// Name nachtragen: Konto mit fremder, unbestätigter E-Mail
$victim_mail = "holder$sfx@example.test";
$o2 = $mko('mollie', $victim_mail);
$t2 = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'AN Ticket']); $posts[] = $t2;
update_post_meta($t2, '_tix_ticket_order_id', $o2); update_post_meta($t2, '_tix_ticket_owner_email', $victim_mail); update_post_meta($t2, '_tix_ticket_event_id', $ev);
$fake = $mk('subscriber', $victim_mail);
delete_user_meta($fake, '_tix_email_confirmed');
if (class_exists('TIX_App_Account')) delete_user_meta($fake, TIX_App_Account::META_EMAIL_CONFIRMED);
wp_set_current_user($fake);
$req = new WP_REST_Request('POST', "/tixomat/v1/customer/tickets/$t2/holder");
$req->set_body_params(['first_name' => 'Fremd', 'last_name' => 'Name']);
$res = rest_do_request($req);
$eq('Ticket-Name: unbestätigtes Konto mit fremder E-Mail → 404', 404, $res->get_status());
if (class_exists('TIX_App_Account')) {
    TIX_App_Account::mark_confirmed(get_userdata($fake), 'test');
    $res = rest_do_request($req);
    $eq('… nach Bestätigung der E-Mail erlaubt (kein 404)', true, $res->get_status() !== 404);
}

echo "── Co-Sale-Status ──\n";
if (class_exists('TIX_Co_Sale')) {
    $map = (array) get_option(TIX_Co_Sale::MAP, []);
    $lid = 987654321;
    $bak = $map;
    $map[$lid] = ['pid' => 1, 'key' => 'schluessel', 'event' => $ev, 'base' => 'https://plattform.invalid/wp-json/tixomat/v1', 'email' => "co$sfx@example.test", 'created' => gmdate('c')];
    update_option(TIX_Co_Sale::MAP, $map, false);
    $co = $mk('subscriber', "co$sfx@example.test");
    if (class_exists('TIX_App_Account')) delete_user_meta($co, TIX_App_Account::META_EMAIL_CONFIRMED);
    wp_set_current_user($co);
    $r = TIX_Co_Sale::site_status($lid, new WP_REST_Request('GET', '/x'));
    $eq('Unbestätigte E-Mail ohne Schlüssel → 404', 'tix_order_missing', is_wp_error($r) ? $r->get_error_code() : 'kein Fehler');
    update_option(TIX_Co_Sale::MAP, $bak, false);
}

echo "── Meta-Ads nur Admin ──\n";
if (class_exists('TIX_Meta_Ads')) {
    $org = $mk('editor');
    wp_set_current_user($org);
    $_POST = $_REQUEST = ['nonce' => wp_create_nonce('tix_admin_nonce'), 'event_id' => $ev, 'spend' => 5];
    $before = get_option('tix_meta_adspend', null);
    $r = $ajax(function () { TIX_Meta_Ads::ajax_update_adspend(); });
    $eq('Redakteur: Werbebudget ändern abgewiesen', [false, $before], [$r['success'] ?? null, get_option('tix_meta_adspend', null)]);
    $r = $ajax(function () { TIX_Meta_Ads::ajax_dashboard_data(); });
    $eq('Redakteur: Umsatz-Dashboard abgewiesen', false, $r['success'] ?? null);
    $_POST = $_REQUEST = [];
}

// Aufräumen
wp_set_current_user(0);
foreach ($orders as $o) $wpdb->delete($T, ['id' => $o]);
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($users as $u) wp_delete_user($u);
echo "\n" . ($n - $f) . "/$n ok" . ($f ? " – $f FEHLER" : '') . "\n";
