<?php
/**
 * Kundenkonten zusammenführen + Rückgängig (P2 Teil B, 1.38.785).   wp eval-file …/test-customer-merge-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Customer_Merge')) { echo "Tixomat ab 1.38.785 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); return [$res->get_status(), $res->get_data()]; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$sfx = wp_rand(1000, 9999);
$users = []; $posts = []; $orders = []; $audits = [];
$mk = function ($login, $role = 'subscriber') use (&$users, $sfx) { $id = wp_insert_user(['user_login' => $login . $sfx, 'user_pass' => wp_generate_password(20), 'user_email' => $login . $sfx . '@example.test', 'role' => $role, 'display_name' => ucfirst($login)]); $users[] = $id; return $id; };
$order = function ($email, $uid) use ($wpdb, &$orders) { $wpdb->insert($wpdb->prefix . 'tix_orders', ['order_number' => 'CM-' . wp_rand(), 'status' => 'completed', 'total' => 10, 'subtotal' => 10, 'tax' => 0, 'discount' => 0, 'payment_method' => 'demo', 'payment_method_title' => 'D',
    'billing_first_name' => 'X', 'billing_last_name' => 'Y', 'billing_email' => $email, 'customer_id' => $uid, 'wc_order_id' => 0, 'order_key' => 'k' . wp_rand(), 'date_created' => current_time('mysql')]); $orders[] = $wpdb->insert_id; return $wpdb->insert_id; };
try {
    TIX_Customer_Merge::install();
    $A = $mk('annaprim'); $B = $mk('bertasek');
    $aem = get_userdata($A)->user_email; $bem = get_userdata($B)->user_email;
    $ob = $order($bem, $B);
    $t = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'T']); $posts[] = $t; update_post_meta($t, '_tix_ticket_owner_email', $bem); update_post_meta($t, '_tix_ticket_order_id', $ob);
    $sp = wp_insert_post(['post_type' => 'tix_support_ticket', 'post_status' => 'publish', 'post_title' => 'Frage']); $posts[] = $sp; update_post_meta($sp, '_tix_sp_email', $bem);
    $wl = 0; if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}tix_waitlist'")) { $wpdb->insert($wpdb->prefix . 'tix_waitlist', ['event_id' => 1, 'email' => $bem, 'name' => 'B', 'type' => 'waitlist', 'notified' => 0, 'created_at' => current_time('mysql')]); $wl = $wpdb->insert_id; }
    $wal = class_exists('TIX_Wallet_Balance') && TIX_Wallet_Balance::ready();
    if ($wal) { $bw = TIX_Wallet_Balance::get_or_create($B, 0); TIX_Wallet_Balance::post_tx($bw['id'], ['type' => 'topup', 'amount_paid' => 1000, 'channel' => 'admin']); }
    update_user_meta($B, '_tix_loyalty_points', 7); update_user_meta($B, '_tix_loyalty_stamped', [5]); update_user_meta($A, '_tix_loyalty_points', 3);
    update_user_meta($B, '_tix_saved_events', [11]); update_user_meta($A, '_tix_saved_events', [12]); update_user_meta($B, '_tix_app_following', [3]);
    update_user_meta($B, '_tix_app_tokens', [['token' => hash('sha256', 'x' . $sfx), 'created' => time(), 'expires' => time() + 3600]]);
    $adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);

    echo "== Vorschau, Sperren\n";
    [$st, $pv] = $rest('POST', '/customers/merge/preview', ['primary' => "u$A", 'secondary' => "u$B"]);
    $eq('Vorschau: Bereiche gezählt, Token, nicht gesperrt', [200, 1, 1, 1, $wal ? 1000 : 0, 7, 1, 1, true, null],
        [$st, $pv['moves']['orders'] ?? null, $pv['moves']['tickets'] ?? null, $pv['moves']['support'] ?? null, $pv['moves']['wallet_cents'] ?? null, $pv['moves']['loyalty_points'] ?? null, $pv['moves']['favorites'] ?? null, $pv['moves']['app_sessions'] ?? null, strlen((string) ($pv['token'] ?? '')) === 32, array_key_exists('blocked', (array) $pv) ? $pv['blocked'] : 'x']);
    $staff = $mk('teamtom', $multi ? 'tix_organizer' : 'editor');
    $eq('Team-Konto gesperrt', 'tix_merge_staff', $rest('POST', '/customers/merge/preview', ['primary' => "u$A", 'secondary' => "u$staff"])[1]['blocked']['code'] ?? null);
    $eq('dasselbe Konto gesperrt', 'tix_merge_same', $rest('POST', '/customers/merge/preview', ['primary' => "u$A", 'secondary' => "u$A"])[1]['blocked']['code'] ?? null);
    $gem = 'gast' . $sfx . '@example.test'; $og = $order($gem, 0); $gid = TIX_Customers::guest_id($gem);
    $eq('Gast als Hauptkonto, Konto als Zweitkonto: gesperrt', 'tix_merge_primary_guest', $rest('POST', '/customers/merge/preview', ['primary' => $gid, 'secondary' => "u$B"])[1]['blocked']['code'] ?? null);
    [$st, $d] = $rest('POST', '/customers/merge', ['primary' => "u$A", 'secondary' => "u$B", 'token' => 'falsch']);
    $eq('falsches Token: 409 tix_merge_changed', [409, 'tix_merge_changed'], [$st, $d['code'] ?? null]);

    echo "== Zusammenführen\n";
    [$st, $d] = $rest('POST', '/customers/merge', ['primary' => "u$A", 'secondary' => "u$B", 'keep_email' => 'primary', 'token' => $pv['token']]);
    $audit = intval($d['audit_id'] ?? 0); $audits[] = $audit;
    $eq('ausgeführt: audit_id, undo_until in 7 Tagen', [200, true, true], [$st, $audit > 0, abs(strtotime((string) ($d['undo_until'] ?? '')) - (time() + 7 * DAY_IN_SECONDS)) < 120]);
    clean_user_cache($B); clean_user_cache($A); wp_cache_flush();
    $eq('Bestellung → Hauptkonto, Rechnungs-E-Mail unverändert', [$A, $bem], [intval($wpdb->get_var("SELECT customer_id FROM {$wpdb->prefix}tix_orders WHERE id = $ob")), (string) $wpdb->get_var("SELECT billing_email FROM {$wpdb->prefix}tix_orders WHERE id = $ob")]);
    $eq('Ticket-Inhaber und Support → E-Mail des Hauptkontos', [$aem, $aem], [get_post_meta($t, '_tix_ticket_owner_email', true), get_post_meta($sp, '_tix_sp_email', true)]);
    if ($wl) $eq('Warteliste → Hauptkonto', $aem, (string) $wpdb->get_var("SELECT email FROM {$wpdb->prefix}tix_waitlist WHERE id = $wl"));
    if ($wal) $eq('Guthaben als Buchung übertragen (A 10 €, B 0 €)', [1000, 0], [intval(TIX_Wallet_Balance::find_wallet($A, 0)['balance_paid']), intval(TIX_Wallet_Balance::find_wallet($B, 0)['balance_paid'])]);
    $eq('Prämien addiert, Stempel/Merkliste/Folgen übernommen', [10, [5], [12, 11], [3]], [intval(get_user_meta($A, '_tix_loyalty_points', true)), array_values((array) get_user_meta($A, '_tix_loyalty_stamped', true)), array_map('intval', (array) get_user_meta($A, '_tix_saved_events', true)), array_map('intval', (array) get_user_meta($A, '_tix_app_following', true))]);
    $bu = get_userdata($B);
    $eq('Zweitkonto gesperrt: E-Mail merged_…, keine Rolle, merged_into', [true, [], $A], [strpos($bu->user_email, 'merged_') === 0, array_values((array) $bu->roles), intval(get_user_meta($B, TIX_Customer_Merge::META_INTO, true))]);
    $eq('Zweit-E-Mail am Hauptkonto', [strtolower($bem)], TIX_Customer_Merge::secondary_emails($A));
    wp_set_current_user($B);
    $e = apply_filters('rest_authentication_errors', null);
    $eq('App mit dem Zweitkonto: 401 tix_account_merged mit login_email', ['tix_account_merged', 401, $aem], [is_wp_error($e) ? $e->get_error_code() : null, is_wp_error($e) ? ($e->get_error_data()['status'] ?? 0) : 0, is_wp_error($e) ? ($e->get_error_data()['login_email'] ?? null) : null]);
    wp_set_current_user($adm->ID);
    [$st, $d] = $rest('GET', "/customers/u$A");
    $eq('Kunden-Detail: secondary_emails', [200, [strtolower($bem)]], [$st, $d['customer']['secondary_emails'] ?? null]);
    [$st, $d] = $rest('GET', '/customers/merges');
    $it = array_values(array_filter((array) ($d['items'] ?? []), function ($x) use ($audit) { return $x['audit_id'] === $audit; }))[0] ?? [];
    $eq('Liste: can_undo, Namen', [200, true, 'Annaprim'], [$st, $it['can_undo'] ?? null, $it['primary']['name'] ?? null]);

    echo "== Rückgängig\n";
    if ($wal) {
        $aw = TIX_Wallet_Balance::find_wallet($A, 0);
        TIX_Wallet_Balance::post_tx($aw['id'], ['type' => 'charge', 'amount' => -500, 'channel' => 'admin']);
        [$st, $d] = $rest('POST', "/customers/merge/$audit/undo", []);
        $eq('Guthaben ausgegeben: 409 Konflikt, nichts geändert', [409, 'tix_merge_undo_conflict', 'wallet', $A], [$st, $d['code'] ?? null, $d['data']['conflicts'][0]['area'] ?? null, intval($wpdb->get_var("SELECT customer_id FROM {$wpdb->prefix}tix_orders WHERE id = $ob"))]);
        TIX_Wallet_Balance::post_tx($aw['id'], ['type' => 'topup', 'amount_paid' => 500, 'channel' => 'admin']);
    }
    [$st, $d] = $rest('POST', "/customers/merge/$audit/undo", []);
    clean_user_cache($B); clean_user_cache($A); wp_cache_flush();
    $bu = get_userdata($B);
    $eq('rückgängig: Zweitkonto wieder da (E-Mail, Rolle), Bestellung/Ticket/Support zurück', [200, $bem, ['subscriber'], 0, $B, $bem, $bem],
        [$st, $bu->user_email, array_values((array) $bu->roles), intval(get_user_meta($B, TIX_Customer_Merge::META_INTO, true)), intval($wpdb->get_var("SELECT customer_id FROM {$wpdb->prefix}tix_orders WHERE id = $ob")), get_post_meta($t, '_tix_ticket_owner_email', true), get_post_meta($sp, '_tix_sp_email', true)]);
    if ($wal) $eq('Guthaben zurück (B 10 €)', 1000, intval(TIX_Wallet_Balance::find_wallet($B, 0)['balance_paid']));
    $eq('Prämien/Listen zurück', [3, 7, [12], [11]], [intval(get_user_meta($A, '_tix_loyalty_points', true)), intval(get_user_meta($B, '_tix_loyalty_points', true)), array_map('intval', (array) get_user_meta($A, '_tix_saved_events', true)), array_map('intval', (array) get_user_meta($B, '_tix_saved_events', true))]);
    $eq('Zweit-E-Mail wieder weg', [], TIX_Customer_Merge::secondary_emails($A));
    $eq('noch einmal: 409 tix_merge_undone', [409, 'tix_merge_undone'], (function () use ($rest, $audit) { [$s, $d] = $rest('POST', "/customers/merge/$audit/undo", []); return [$s, $d['code'] ?? null]; })());

    echo "== Gast in Konto, Frist\n";
    $pv = $rest('POST', '/customers/merge/preview', ['primary' => "u$A", 'secondary' => $gid])[1];
    [$st, $d] = $rest('POST', '/customers/merge', ['primary' => "u$A", 'secondary' => $gid, 'token' => $pv['token'] ?? '']);
    $a2 = intval($d['audit_id'] ?? 0); $audits[] = $a2;
    $eq('Gast-Kauf → Konto (customer_id), Kundenliste führt ihn unter uA', [200, $A], [$st, intval($wpdb->get_var("SELECT customer_id FROM {$wpdb->prefix}tix_orders WHERE id = $og"))]);
    $wpdb->update(TIX_Customer_Merge::table(), ['undo_until' => gmdate('Y-m-d H:i:s', time() - 60)], ['audit_id' => $a2]);
    $eq('nach 7 Tagen: 409 tix_merge_undo_expired', [409, 'tix_merge_undo_expired'], (function () use ($rest, $a2) { [$s, $d] = $rest('POST', "/customers/merge/$a2/undo", []); return [$s, $d['code'] ?? null]; })());
    TIX_Customer_Merge::cleanup();
    $eq('Aufräumen: Journal (alte Daten) nach Frist gelöscht', null, $wpdb->get_var($wpdb->prepare("SELECT journal FROM " . TIX_Customer_Merge::table() . " WHERE audit_id = %d", $a2)));

    echo "== Rechte\n";
    wp_set_current_user(0);
    $eq('ohne Anmeldung: 401', 401, $rest('POST', '/customers/merge/preview', ['primary' => "u$A", 'secondary' => "u$B"])[0]);
    if ($multi) {
        $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'CM Org']); $posts[] = $org;
        $om = $mk('cmorg', 'tix_organizer'); update_user_meta($om, TIX_App_Scope::META_TEAM_ORG, $org); update_user_meta($om, '_tix_team_role', 'admin');
        wp_set_current_user($om);
        $eq('evendis: Veranstalter darf nicht zusammenführen (403), Kandidaten lesen ja', [403, 200], [$rest('POST', '/customers/merge/preview', ['primary' => "u$A", 'secondary' => "u$B"])[0], $rest('GET', '/customers/merge/candidates')[0]]);
    }
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    wp_set_current_user(0);
    foreach ($orders as $o) $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $o]);
    foreach ($posts as $p) wp_delete_post($p, true);
    if (!empty($wl)) $wpdb->delete($wpdb->prefix . 'tix_waitlist', ['id' => $wl]);
    foreach ($audits as $a) $wpdb->delete(TIX_Customer_Merge::table(), ['audit_id' => $a]);
    if (class_exists('TIX_Wallet_Balance') && TIX_Wallet_Balance::ready()) foreach ($users as $u) { $w = TIX_Wallet_Balance::find_wallet($u, 0); if ($w) { $wpdb->delete(TIX_Wallet_Balance::t('wallet_tx'), ['wallet_id' => $w['id']]); $wpdb->delete(TIX_Wallet_Balance::t('wallets'), ['id' => $w['id']]); } }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $u) if ($u && !is_wp_error($u)) wp_delete_user($u);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
