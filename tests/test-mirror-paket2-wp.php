<?php
/**
 * Integrationstest Datenspiegel Paket 2 (Veranstalter, Gästelisten, Prämien, Gutscheine,
 * Guthaben, Abrechnungen, täglicher Abgleich, Aufbewahrung) gegen ein Test-WordPress und
 * einen LOKALEN Supabase-Ersatz (siehe supabase/README.md). Nie auf Live oder gegen das echte Projekt.
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-mirror-paket2-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Mirror_Extra')) { echo "WordPress mit Tixomat (Datenspiegel Paket 2) nötig\n"; return; }
if (stripos(home_url(), 'localhost') === false && stripos(home_url(), '.test') === false) { echo "Nur auf lokalen Test-Seiten!\n"; return; }
if (stripos(TIX_Mirror_Client::url(), 'supabase.co') !== false) { echo "Nur gegen einen lokalen Spiegel!\n"; return; }
if (!defined('TIX_MIRROR_ENC_KEY')) define('TIX_MIRROR_ENC_KEY', 'nur-fuer-den-lokalen-test');

$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function t2_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function t2_true($label, $c) { t2_eq($label, true, (bool) $c); }
function m2_rows($table, array $f = []) {
    $r = TIX_Mirror_Client::select($table, array_merge(['site' => 'eq.' . TIX_Mirror::site()], $f));
    return $r['ok'] ? (array) $r['body'] : ['ERROR' => $r['error']];
}
function m2_one($table, $id) { $r = m2_rows($table, ['id' => 'eq.' . $id]); return $r[0] ?? null; }
function m2_run($n = 1) { TIX_Mirror::flush_buffer(); for ($i = 0; $i < $n; $i++) { delete_option(TIX_Mirror::LOCK); TIX_Mirror::cron_run(); } }
function m2_reconcile() {
    TIX_Mirror_Reconcile::start();
    for ($i = 0; $i < 50 && TIX_Mirror_Reconcile::get()['state'] === 'running'; $i++) {
        TIX_Mirror::flush_buffer();
        TIX_Mirror_Reconcile::step(30);
    }
    return TIX_Mirror_Reconcile::report();
}
add_filter('pre_wp_mail', function () { return true; });

global $wpdb;
$site = TIX_Mirror::site();
$sfx = wp_rand(1000, 9999);
$posts = []; $users = [];
update_option(TIX_Mirror_Backfill::OPTION, ['state' => 'done', 'finished_at' => time(), 'steps_total' => count(TIX_Mirror_Backfill::STEPS)], false);
update_option(TIX_Mirror_Reconcile::OPTION, ['state' => 'idle', 'finished_at' => time(), 'started_at' => time()], false); // kein Auto-Abgleich mitten im Test
$coupons_backup = get_option('tix_coupons', null);
if (class_exists('TIX_Wallet_Balance')) TIX_Wallet_Balance::maybe_install();
if (class_exists('TIX_Settlement')) TIX_Settlement::create_tables();
m2_run(); // Nachlauf-Marken setzen

// ── 1. Veranstalter mit Auszahlungsdaten: IBAN nie im Klartext ──
$iban = 'DE89370400440532013000';
$org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => "Spiegel-Veranstalter $sfx"]);
$posts[] = $org;
update_post_meta($org, '_tix_payout_data', ['holder' => 'Max Muster', 'bic' => 'COBADEFFXXX', 'billing' => ['city' => 'Köln'], 'email' => 'kasse@example.test']);
update_post_meta($org, '_tix_payout_iban', TIX_Payout_Details::encrypt($iban));
update_post_meta($org, '_tix_payout_pending', ['iban' => TIX_Payout_Details::encrypt('DE02120300000000202051'), 'hash' => 'x']);
update_post_meta($org, '_tix_org_notify_pushover', ['user_key' => 'geheim', 'app_token' => 'geheim']);
update_post_meta($org, '_tix_org_city', 'Köln');
m2_run();
$o = m2_one('organizers', $org);
t2_eq('Veranstalter gespiegelt', "Spiegel-Veranstalter $sfx", $o['name'] ?? null);
t2_eq('IBAN maskiert', TIX_Payout_Details::mask_iban($iban), $o['payout']['iban_masked'] ?? null);
t2_true('IBAN mit Spiegel-Schlüssel verschlüsselt (m1:)', strpos((string) ($o['payout']['iban_enc'] ?? ''), 'm1:') === 0);
t2_eq('IBAN mit Spiegel-Schlüssel lesbar', $iban, TIX_Mirror_Extra::iban_from_mirror($o['payout']['iban_enc'] ?? ''));
$json = wp_json_encode($o);
t2_true('kein Klartext-IBAN im Spiegel', strpos($json, $iban) === false && strpos($json, 'DE02120300000000202051') === false);
t2_true('kein Pushover, keine ausstehende IBAN', strpos($json, 'pushover') === false && strpos($json, 'payout_pending') === false);
t2_true('ohne Spiegel-Schlüssel nie Klartext', strpos(TIX_Mirror_Extra::iban_for_mirror($iban), 'DE89') !== 0);

// ── 2. Gästelisten ──
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "Gäste-Test $sfx"]);
$posts[] = $ev;
update_post_meta($ev, '_tix_guest_list', [
    ['id' => 'a1b2c3', 'name' => 'Gina Gast', 'email' => "gina$sfx@example.test", 'plus' => 1, 'note' => 'VIP', 'checked_in' => false, 'checked_in_count' => 0, 'checkin_time' => '', 'checkin_by' => ''],
    ['id' => 'd4e5f6', 'name' => 'Paul Plus', 'email' => '', 'plus' => 0, 'note' => '', 'checked_in' => false, 'checked_in_count' => 0, 'checkin_time' => '', 'checkin_by' => ''],
]);
update_post_meta($ev, '_tix_guestlist', [['name' => 'Dora Dashboard', 'email' => '', 'tickets' => 3, 'checked_in' => false]]);
update_post_meta($ev, '_tix_guests', [['ticket_code' => "TC$sfx", 'name' => 'Tom Ticket', 'email' => "tom$sfx@example.test"]]);
m2_run();
t2_eq('4 Gäste im Spiegel (drei Listen)', 4, count(m2_rows('guests', ['event_id' => 'eq.' . $ev])));
t2_eq('Liste je Ticket', 'tickets', m2_one('guests', $ev . ':t0')['source'] ?? null);
t2_eq('alte Liste nicht in events.data', false, strpos(wp_json_encode(m2_one('events', $ev)), 'Tom Ticket') !== false);
t2_eq('Dashboard-Gast: Begleitung aus tickets', 2, (int) (m2_one('guests', $ev . ':d0')['plus'] ?? -1));
t2_eq('Gästeliste nicht in events.data', false, strpos(wp_json_encode(m2_one('events', $ev)), 'Gina') !== false);
$gl = get_post_meta($ev, '_tix_guest_list', true);
$gl[0]['checked_in'] = true; $gl[0]['checked_in_count'] = 2; $gl[0]['checkin_time'] = current_time('c'); $gl[0]['checkin_by'] = 'door';
unset($gl[1]);
update_post_meta($ev, '_tix_guest_list', array_values($gl));
m2_run();
t2_eq('Gast eingecheckt', true, m2_one('guests', $ev . ':a1b2c3')['checked_in'] ?? null);
t2_eq('entfernter Gast gelöscht', null, m2_one('guests', $ev . ':d4e5f6'));

// ── 3. Prämien (Stempelkarte) und Löschweg ──
$uid = wp_create_user("praemie$sfx", wp_generate_password(), "praemie$sfx@example.test");
$users[] = $uid;
update_user_meta($uid, '_tix_loyalty_points', 5);
update_user_meta($uid, '_tix_loyalty_log', [['ts' => time(), 'type' => 'stamp', 'delta' => 1, 'label' => 'Besuch']]);
update_user_meta($uid, '_tix_loyalty_points_o' . $org, 2);
m2_run();
t2_eq('2 Stempelkarten', 2, count(m2_rows('loyalty', ['user_id' => 'eq.' . $uid])));
t2_eq('Punkte', 5, (int) (m2_one('loyalty', $uid . ':0')['points'] ?? -1));
t2_eq('Stempel nicht doppelt in customers.data', false, strpos(wp_json_encode(m2_one('customers', $uid)), 'loyalty') !== false);
delete_user_meta($uid, '_tix_loyalty_points_o' . $org);
m2_run();
t2_eq('entfernte Karte gelöscht', null, m2_one('loyalty', $uid . ':' . $org));

// ── 4. Guthaben: Geldbörse und Buchungen über den Nachlauf ──
if (class_exists('TIX_Wallet_Balance') && TIX_Wallet_Balance::ready()) {
    $w = TIX_Wallet_Balance::get_or_create($uid, 0);
    $wid = (int) ($w['id'] ?? 0);
    $tx = TIX_Wallet_Balance::post_tx($wid, ['type' => 'topup', 'amount_paid' => 2500, 'channel' => 'admin', 'idempotency_key' => "t$sfx"]);
    t2_true('Aufladung gebucht', !is_wp_error($tx));
    m2_run();
    t2_eq('Geldbörse gespiegelt', 2500, (int) (m2_one('wallets', $wid)['balance_paid'] ?? -1));
    $txs = m2_rows('wallet_transactions', ['wallet_id' => 'eq.' . $wid]);
    t2_eq('Buchung gespiegelt', 1, count($txs));
    t2_eq('Buchungsart', 'topup', $txs[0]['type'] ?? null);
} else {
    echo "      (Guthaben-Tabellen fehlen – übersprungen)\n";
}

// ── 5. Gutscheine + Geschenkkarten: nur geänderte Codes ──
$c = is_array($coupons_backup) ? $coupons_backup : [];
$c["SPIEGEL$sfx"] = ['code' => "SPIEGEL$sfx", 'discount_type' => 'percent', 'value' => 10, 'used' => 0, 'max_uses' => 5];
$c["GS-$sfx-TEST"] = ['code' => "GS-$sfx-TEST", 'discount_type' => 'giftcard', 'value' => 50, 'balance' => 50, 'used' => 0, 'organizer_id' => $org, 'redemptions' => []];
update_option('tix_coupons', $c);
m2_run();
t2_eq('Gutschein gespiegelt', 10.0, (float) (m2_one('coupons', "SPIEGEL$sfx")['value'] ?? 0));
t2_eq('Geschenkkarte mit Restwert', 50.0, (float) (m2_one('coupons', "GS-$sfx-TEST")['balance'] ?? 0));
$c["SPIEGEL$sfx"]['used'] = 1;
update_option('tix_coupons', $c);
$plan = TIX_Mirror_Extra::plan('coupons', 'all', 'upsert', null);
$up = array_values(array_filter($plan, function ($op) { return $op[0] === 'upsert'; }));
t2_eq('nur der geänderte Code wird gesendet', 1, count($up[0][2] ?? []));
m2_run();
t2_eq('Einlösung gezählt', 1, (int) (m2_one('coupons', "SPIEGEL$sfx")['used'] ?? 0));
unset($c["SPIEGEL$sfx"]);
update_option('tix_coupons', $c);
m2_run();
t2_eq('gelöschter Code entfernt', null, m2_one('coupons', "SPIEGEL$sfx"));

// ── 6. Abrechnungen: Nachlauf, IBAN im Beleg verschlüsselt ──
if ($wpdb->get_var("SHOW TABLES LIKE '" . TIX_Settlement::table() . "'")) {
    $wpdb->insert(TIX_Settlement::table(), [
        'number' => "AB-$sfx", 'type' => 'event', 'organizer_id' => $org, 'event_id' => $ev, 'status' => 'ready',
        'gross' => 100, 'payout_amount' => 90.5, 'snapshot' => wp_json_encode(['holder' => 'Max Muster', 'iban_enc' => TIX_Payout_Details::encrypt($iban), 'iban_masked' => 'DE89 **** 3000']),
        'created' => current_time('mysql'), 'updated' => current_time('mysql'),
    ]);
    $sid = (int) $wpdb->insert_id;
    $wpdb->insert(TIX_Settlement::items_table(), ['settlement_id' => $sid, 'organizer_id' => $org, 'event_id' => $ev, 'order_id' => 0, 'type' => 'order', 'gross' => 100, 'amount' => 90.5, 'created' => gmdate('Y-m-d H:i:s')]);
    $iid = (int) $wpdb->insert_id;
    m2_run();
    $s = m2_one('settlements', $sid);
    t2_eq('Abrechnung gespiegelt', 90.5, (float) ($s['payout_amount'] ?? 0));
    t2_true('Beleg-IBAN verschlüsselt (m1:)', strpos((string) ($s['data']['snapshot']['iban_enc'] ?? ''), 'm1:') === 0);
    t2_true('kein Klartext-IBAN in der Abrechnung', strpos(wp_json_encode($s), $iban) === false);
    t2_eq('Position gespiegelt', $sid, (int) (m2_one('settlement_items', $iid)['settlement_id'] ?? 0));
    sleep(1);
    $wpdb->update(TIX_Settlement::table(), ['status' => 'paid', 'updated' => current_time('mysql')], ['id' => $sid]);
    m2_run();
    t2_eq('Statuswechsel über den Nachlauf', 'paid', m2_one('settlements', $sid)['status'] ?? null);
} else {
    echo "      (Abrechnungs-Tabellen fehlen – übersprungen)\n";
}

// ── 7. Täglicher Abgleich: erkennt Fehlendes, Überzähliges, Abweichendes ──
m2_run(2);
$rep = m2_reconcile();
t2_eq('Abgleich nach Senden: 0 Abweichungen', 0, (int) ($rep['differences'] ?? -1));
t2_true('Bericht in sync_reports', m2_one('sync_reports', wp_date('Y-m-d')) !== null);
// Spiegel manipulieren
TIX_Mirror_Client::delete('organizers', ['site' => 'eq.' . $site, 'id' => 'eq.' . $org]);
TIX_Mirror_Client::upsert('events', [['site' => $site, 'id' => 987654321, 'title' => 'Geist', 'status' => 'publish']]);
if (isset($wid)) {
    $wrow = m2_one('wallets', $wid); $wrow['balance_paid'] = 1; unset($wrow['synced_at']);
    TIX_Mirror_Client::upsert('wallets', [$wrow]);
}
$rep = m2_reconcile();
t2_eq('fehlender Veranstalter erkannt', 1, (int) ($rep['tables']['organizers']['missing'] ?? 0));
t2_eq('überzähliges Event erkannt', 1, (int) ($rep['tables']['events']['extra'] ?? 0));
if (isset($wid)) t2_eq('abweichende Geldbörse erkannt', 1, (int) ($rep['tables']['wallets']['differ'] ?? 0));
m2_run(2);
t2_true('Veranstalter nachgeschickt', m2_one('organizers', $org) !== null);
t2_eq('Geist-Event gelöscht', null, m2_one('events', 987654321));
if (isset($wid)) t2_eq('Geldbörse korrigiert', 2500, (int) (m2_one('wallets', $wid)['balance_paid'] ?? -1));
$rep = m2_reconcile();
t2_eq('danach wieder 0 Abweichungen', 0, (int) ($rep['differences'] ?? -1));

// ── 8. Aufbewahrung: Verlauf älter als die Einstellung wird gekürzt ──
$old = TIX_Mirror_Client::request('POST', '/rest/v1/change_log', [
    ['site' => $site, 'entity' => 'test', 'object_id' => "alt$sfx", 'op' => 'insert', 'changed_at' => gmdate('c', strtotime('-11 years'))],
    ['site' => $site, 'entity' => 'test', 'object_id' => "jung$sfx", 'op' => 'insert', 'changed_at' => gmdate('c', strtotime('-5 years'))],
], ['Prefer' => 'return=minimal']);
t2_true('Test-Verlauf angelegt', $old['ok']);
t2_eq('Standard: 10 Jahre', 3653, TIX_Mirror::log_days());
$rep = m2_reconcile();
t2_eq('11 Jahre alter Eintrag gekürzt', 0, count(m2_rows('change_log', ['object_id' => "eq.alt$sfx"])));
t2_eq('5 Jahre alter Eintrag bleibt', 1, count(m2_rows('change_log', ['object_id' => "eq.jung$sfx"])));
TIX_Mirror_Client::delete('change_log', ['site' => 'eq.' . $site, 'entity' => 'eq.test']);

// ── 8b. Pixel-Einwilligung an der Bestellung (1.38.458, Post-Meta an der Bestell-ID) ──
$oid = TIX_Native_Checkout::create_order(['total' => 10.0, 'payment_method' => 'bank', 'billing_first_name' => 'E', 'billing_last_name' => 'W',
    'billing_email' => "einw$sfx@example.test", 'items' => [['event_id' => $ev, 'cat_index' => 0, 'qty' => 1, 'price' => 10.0, 'name' => 'N', 'event_title' => 'x']]]);
m2_run();
update_post_meta($oid, '_tix_meta_consent', '1');
m2_run();
t2_eq('Einwilligung an der Bestellung', true, m2_one('orders', $oid)['data']['meta_consent'] ?? null);
$wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $oid]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $oid]);
delete_post_meta($oid, '_tix_meta_consent');
TIX_Mirror::touch('order', $oid);

// ── 9. Konto löschen: Prämien weg, Geldbörse anonymisiert ──
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($uid);
$users = [];
m2_run(2);
t2_eq('Prämien im Spiegel gelöscht', 0, count(m2_rows('loyalty', ['user_id' => 'eq.' . $uid])));
t2_eq('Verlauf der Prämien getilgt', 0, count(m2_rows('change_log', ['entity' => 'eq.loyalty', 'customer_id' => 'eq.' . $uid])));
if (isset($wid)) t2_eq('Geldbörse anonymisiert (wie WordPress)', -$wid, (int) (m2_one('wallets', $wid)['user_id'] ?? 0));

// ── Aufräumen ──
if (isset($sid)) { $wpdb->delete(TIX_Settlement::items_table(), ['settlement_id' => $sid]); $wpdb->delete(TIX_Settlement::table(), ['id' => $sid]); }
if (isset($wid)) { $wpdb->delete($wpdb->prefix . 'tix_wallet_tx', ['wallet_id' => $wid]); $wpdb->delete($wpdb->prefix . 'tix_wallets', ['id' => $wid]); }
if ($coupons_backup === null) delete_option('tix_coupons'); else update_option('tix_coupons', $coupons_backup);
foreach ($posts as $p) wp_delete_post($p, true);
foreach ($users as $u) wp_delete_user($u);
m2_run();
m2_reconcile();
m2_run(2);
t2_eq('Aufräumen gespiegelt', null, m2_one('organizers', $org));

echo "\n" . ($GLOBALS['t_count'] - $GLOBALS['t_fails']) . "/" . $GLOBALS['t_count'] . " ok\n";
