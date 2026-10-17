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
// Gegen das echte Projekt nur mit ausdrücklicher Freigabe und eigener Test-Kennung (nie eine Live-Seite).
if (stripos(TIX_Mirror_Client::url(), 'supabase.co') !== false && !(getenv('MIRROR_TEST_REMOTE') === '1' && preg_match('/\.local$/', TIX_Mirror::site()))) {
    echo "Gegen Supabase nur mit MIRROR_TEST_REMOTE=1 und Kennung *.local!\n"; return;
}
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

// Event mit Gästen endgültig löschen → Gäste im Spiegel weg
$ev2 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "Gäste-Löschen $sfx"]);
update_post_meta($ev2, '_tix_guest_list', [['id' => 'zz1', 'name' => 'Weg', 'email' => '']]);
m2_run();
t2_eq('Gast des zweiten Events im Spiegel', 1, count(m2_rows('guests', ['event_id' => 'eq.' . $ev2])));
wp_delete_post($ev2, true);
m2_run();
t2_eq('Event gelöscht → Gäste weg', 0, count(m2_rows('guests', ['event_id' => 'eq.' . $ev2])));

// Papierkorb und Wiederherstellen (z. B. DELETE /events/{id}, 1.38.462): Status im Spiegel, nichts löschen
$ev3 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "Papierkorb $sfx"]);
$posts[] = $ev3;
update_post_meta($ev3, '_tix_ticket_categories', [['name' => 'N', 'price' => '10', 'qty' => 10, 'stock' => 10]]);
update_post_meta($ev3, '_tix_guest_list', [['id' => 'pk1', 'name' => 'Paula', 'email' => '']]);
m2_run();
wp_trash_post($ev3);
m2_run();
t2_eq('Papierkorb: Status trash im Spiegel', 'trash', m2_one('events', $ev3)['status'] ?? null);
t2_eq('Papierkorb: Kategorie bleibt', 1, count(m2_rows('ticket_categories', ['event_id' => 'eq.' . $ev3])));
t2_eq('Papierkorb: Gast bleibt', 1, count(m2_rows('guests', ['event_id' => 'eq.' . $ev3])));
wp_untrash_post($ev3);
m2_run();
t2_eq('Wiederhergestellt: Status wie WordPress', get_post_status($ev3), m2_one('events', $ev3)['status'] ?? null);

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

// ── 3b. Konto-Meta nur per Erlaubnisliste (1.38.470, Fund KitchenKlub: Kassen-PIN-Hash) ──
update_user_meta($uid, '_tix_pos_pin', wp_hash_password('1234'));
update_user_meta($uid, '_tix_irgendwas_neues', 'x');
update_user_meta($uid, '_tix_phone', '0221 99');
update_user_meta($uid, 'billing_company', '$wp$2y$10$nichtwirklicheinhashabervonderform');
m2_run();
$cj = wp_json_encode(m2_one('customers', $uid));
t2_eq('PIN-Hash nicht im Spiegel', false, strpos($cj, '_tix_pos_pin') !== false);
t2_eq('unbekanntes _tix_-Feld nicht im Spiegel', false, strpos($cj, '_tix_irgendwas_neues') !== false);
t2_eq('erlaubtes Feld im Spiegel', '0221 99', m2_one('customers', $uid)['data']['_tix_phone'] ?? null);
t2_eq('Hash-artiger Wert unter erlaubtem Feld verworfen', false, strpos($cj, '$wp$2y$') !== false);
t2_eq('kein Hash im Verlauf', 0, count(array_filter(m2_rows('change_log', ['entity' => 'eq.customers', 'object_id' => 'eq.' . $uid]), function ($r) { return strpos(wp_json_encode($r), '$wp$') !== false || strpos(wp_json_encode($r), '_tix_pos_pin') !== false; })));
delete_user_meta($uid, 'billing_company');
// Update auf DB 2: alle Konten einmal neu senden
update_option(TIX_Mirror::DB_OPTION, '1');
TIX_Mirror::maybe_install();
t2_true('Update merkt Neu-Senden der Konten vor', isset(get_option(TIX_Mirror::REQUEUE, [])['customer']));
m2_run();
t2_eq('Neu-Senden erledigt', false, (bool) get_option(TIX_Mirror::REQUEUE, false));

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
    // Plattform-Cashless (1.38.466): Buchung trägt den Veranstalter
    $tx2 = TIX_Wallet_Balance::post_tx($wid, ['type' => 'charge', 'amount' => -500, 'channel' => 'pos', 'organizer_id' => $org, 'idempotency_key' => "c$sfx"]);
    m2_run();
    if (!is_wp_error($tx2) && isset($tx2['id'])) t2_eq('Buchung mit Veranstalter', $org, (int) (m2_one('wallet_transactions', (int) $tx2['id'])['organizer_id'] ?? 0));
    else t2_true('Cashless-Buchung möglich', false);
    t2_eq('Geldbörse nach Abbuchung', 2000, (int) (m2_one('wallets', $wid)['balance_paid'] ?? -1));
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

// ── 6b. Rechnungs-Kern (1.38.485): Beleg + Nummernkreis, PDF nur als Pfad/Hash, IBAN im Text maskiert ──
if (class_exists('TIX_Invoices')) {
    TIX_Invoices::maybe_install();
    $ts_inv = get_option('tix_settings', []);
    $ts_new = is_array($ts_inv) ? $ts_inv : [];
    $ts_new['invoice_company_name'] = "Spiegel GmbH $sfx";
    $ts_new['invoice_company_address'] = "Teststraße 1\n50667 Köln";
    $ts_new['invoice_company_tax_id'] = '214/5678/0000';
    $ts_new['invoice_footer_text'] = 'Bank: DE89 3704 0044 0532 0130 00';
    update_option('tix_settings', $ts_new);
    $en_prev = get_option('tix_invoices_enabled', null);
    update_option('tix_invoices_enabled', '1');
    $ioid = TIX_Native_Checkout::create_order(['total' => 30.0, 'payment_method' => 'bank', 'billing_first_name' => 'Rita', 'billing_last_name' => 'Rechnung',
        'billing_email' => "rechnung$sfx@example.test", 'billing_address_1' => 'Weg 2', 'billing_postcode' => '50667', 'billing_city' => 'Köln',
        'items' => [['event_id' => $ev, 'cat_index' => 0, 'qty' => 3, 'price' => 10.0, 'name' => 'N', 'event_title' => 'x']]]);
    TIX_Native_Checkout::update_order_status($ioid, 'completed', 'admin');
    $invs = TIX_Invoices::for_order($ioid);
    t2_true('Beleg in WordPress angelegt', count($invs) >= 1);
    m2_run();
    if ($invs) {
        $inv = $invs[0];
        $mi = m2_one('invoices', (int) $inv->id);
        t2_eq('Beleg im Spiegel (Nummer)', (string) $inv->number, $mi['number'] ?? null);
        t2_eq('Brutto', 30.0, (float) ($mi['gross'] ?? 0));
        t2_eq('PDF-Prüfsumme gespiegelt', (string) $inv->pdf_sha256, $mi['pdf_sha256'] ?? null);
        t2_true('keine PDF-Datei im Spiegel', strlen(wp_json_encode($mi)) < 200000 && strpos(wp_json_encode($mi), '%PDF') === false);
        t2_true('IBAN im Text maskiert', strpos(wp_json_encode($mi), 'DE89370400440532013000') === false && strpos(wp_json_encode($mi), 'DE89 3704 0044 0532 0130 00') === false);
        $cid = $inv->issuer_key . '|' . $inv->series . '|' . (int) $inv->year;
        t2_eq('Nummernkreis gespiegelt', (int) $inv->seq, (int) (m2_one('invoice_counters', $cid)['last'] ?? -1));
    }
    // Übergabe an die Buchhaltung (1.38.490): export_* per $wpdb ohne Hook → Nachlauf über export_at; Fehlertext maskiert
    if ($invs) {
        $it = $wpdb->prefix . 'tix_invoices';
        if (!$wpdb->get_var("SHOW COLUMNS FROM $it LIKE 'export_status'")) {
            echo "      (Test baut die 490-Spalten lokal nach)\n";
            $wpdb->query("ALTER TABLE $it ADD COLUMN export_provider VARCHAR(20) NOT NULL DEFAULT '', ADD COLUMN export_status VARCHAR(12) NOT NULL DEFAULT '',
                ADD COLUMN export_ref VARCHAR(80) NOT NULL DEFAULT '', ADD COLUMN export_error VARCHAR(255) NOT NULL DEFAULT '',
                ADD COLUMN export_tries TINYINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN export_at DATETIME NULL");
        }
        m2_run(); // Marke des neuen Nachlaufs setzen
        sleep(1);
        $secret = str_repeat('Ab3', 12);
        $wpdb->query($wpdb->prepare("UPDATE $it SET export_provider = 'lexoffice', export_status = 'error', export_tries = export_tries + 1, export_at = %s,
            export_error = %s WHERE id = %d", current_time('mysql'),
            "HTTP 400: Kontakt ungültig, IBAN DE89370400440532013000; Authorization: Bearer $secret; api_key=$secret", (int) $inv->id));
        m2_run();
        $me = m2_one('invoices', (int) $inv->id);
        t2_eq('Übergabe-Status über den Nachlauf', 'error', $me['export_status'] ?? null);
        t2_eq('Versuche', 1, (int) ($me['export_tries'] ?? 0));
        t2_true('Fehlertext ohne IBAN', strpos((string) ($me['export_error'] ?? ''), 'DE89370400440532013000') === false);
        t2_true('Fehlertext ohne Schlüssel', strpos((string) ($me['export_error'] ?? ''), $secret) === false && strpos((string) ($me['export_error'] ?? ''), 'Kontakt ungültig') !== false);
        t2_true('kein Schlüssel im Verlauf', strpos(wp_json_encode(m2_rows('change_log', ['entity' => 'eq.invoices', 'object_id' => 'eq.' . (int) $inv->id])), $secret) === false);
        sleep(1);
        $wpdb->query($wpdb->prepare("UPDATE $it SET export_status = 'done', export_error = '', export_ref = 'v-123', export_at = %s WHERE id = %d", current_time('mysql'), (int) $inv->id));
        m2_run();
        t2_eq('Übergabe erledigt', 'done', m2_one('invoices', (int) $inv->id)['export_status'] ?? null);
        // Abgleich erkennt eine Abweichung im Status
        TIX_Mirror_Client::upsert('invoices', [array_merge(array_diff_key(m2_one('invoices', (int) $inv->id), ['synced_at' => 1]), ['export_status' => 'pending'])]);
    }

    // Konto löschen lässt Belege stehen (10 Jahre)
    $rep = m2_reconcile();
    if ($invs) t2_eq('Abgleich erkennt abweichenden Übergabe-Status', 1, (int) ($rep['tables']['invoices']['differ'] ?? 0));
    m2_run();
    if ($invs) t2_eq('Status korrigiert', 'done', m2_one('invoices', (int) $inv->id)['export_status'] ?? null);
    $rep = m2_reconcile();
    t2_eq('Abgleich: Belege ohne Abweichung', 0, (int) ($rep['tables']['invoices']['missing'] ?? 0) + (int) ($rep['tables']['invoices']['differ'] ?? 0));
    if ($en_prev === null) delete_option('tix_invoices_enabled'); else update_option('tix_invoices_enabled', $en_prev);
    update_option('tix_settings', $ts_inv);
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
if (isset($wid)) t2_eq('Geldbörse korrigiert', 2000, (int) (m2_one('wallets', $wid)['balance_paid'] ?? -1));
$rep = m2_reconcile();
t2_eq('danach wieder 0 Abweichungen', 0, (int) ($rep['differences'] ?? -1));
// Verwaiste Gäste (Event fehlt im Spiegel) räumt der Abgleich weg
TIX_Mirror_Client::upsert('guests', [['site' => $site, 'id' => '999999:waise', 'event_id' => 999999, 'name' => 'Waise']]);
$rep = m2_reconcile();
t2_eq('verwaister Gast entfernt', null, m2_one('guests', '999999:waise'));
t2_true('im Bericht gezählt', (int) ($rep['tables']['orphans']['guests'] ?? 0) >= 1);

// Abgleich startet nicht, solange der Postausgang nicht leer ist (sonst Fehlalarm direkt nach der Erstbefüllung)
update_option(TIX_Mirror_Reconcile::OPTION, ['state' => 'idle', 'finished_at' => 0, 'started_at' => 0], false);
TIX_Mirror::touch('event', $ev); TIX_Mirror::flush_buffer();
t2_eq('Abgleich wartet auf leeren Postausgang', false, TIX_Mirror_Reconcile::due());
m2_run();
t2_eq('nach dem Senden fällig', (int) wp_date('G') >= TIX_Mirror_Reconcile::HOUR, TIX_Mirror_Reconcile::due());
update_option(TIX_Mirror_Reconcile::OPTION, ['state' => 'idle', 'finished_at' => time(), 'started_at' => time()], false);

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

// ── 10. Kontolöschung über den Kern (TIX_Account_Delete, 1.38.460): anonymisiert, Verlauf getilgt ──
if (class_exists('TIX_Account_Delete')) {
    $mail = "kern$sfx@example.test";
    $kuid = wp_create_user("kern$sfx", wp_generate_password(), $mail);
    wp_update_user(['ID' => $kuid, 'first_name' => 'Klara', 'last_name' => 'Kern']);
    if (class_exists('TIX_App_Account')) TIX_App_Account::mark_confirmed(get_userdata($kuid), 'test');
    wp_set_current_user($kuid);
    $koid = TIX_Native_Checkout::create_order(['total' => 10.0, 'payment_method' => 'bank', 'billing_first_name' => 'Klara', 'billing_last_name' => 'Kern',
        'billing_email' => $mail, 'billing_city' => 'Bonn', 'items' => [['event_id' => $ev, 'cat_index' => 0, 'qty' => 1, 'price' => 10.0, 'name' => 'N', 'event_title' => 'x']]]);
    wp_set_current_user(0);
    TIX_Native_Checkout::update_order_status($koid, 'completed', 'admin');
    $ktix = get_posts(['post_type' => 'tix_ticket', 'numberposts' => 1, 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $koid, 'fields' => 'ids']);
    update_post_meta($ktix[0], '_tix_ticket_owner_email', $mail);
    update_post_meta($ktix[0], '_tix_ticket_owner_name', 'Klara Kern');
    m2_run();
    sleep(1);
    $wpdb->update($wpdb->prefix . 'tix_orders', ['billing_phone' => '0228 1'], ['id' => $koid]); // zweiter Verlaufseintrag
    m2_run();
    t2_true('vorher: Verlauf der Bestellung mit Klarnamen', count(m2_rows('change_log', ['entity' => 'eq.orders', 'object_id' => 'eq.' . $koid])) >= 2);
    $res = TIX_Account_Delete::delete($kuid, 'app');
    t2_true('Kern hat gelöscht', is_array($res));
    m2_run(2);
    $ko = m2_one('orders', $koid);
    t2_true('Bestellung im Spiegel anonymisiert', TIX_Mirror_Map::is_anonymized($ko['billing_email'] ?? ''));
    t2_eq('Bestellung behält Betrag', 10.0, (float) ($ko['total'] ?? 0));
    $hist = m2_rows('change_log', ['entity' => 'eq.orders', 'object_id' => 'eq.' . $koid]);
    t2_eq('Verlauf der Bestellung: nur der anonyme Stand', 1, count($hist));
    t2_eq('keine E-Mail im Bestell-Verlauf', false, strpos(wp_json_encode($hist), $mail) !== false);
    if (class_exists('TIX_Account_Delete') && method_exists('TIX_Account_Delete', 'mode') && TIX_Account_Delete::mode() === 'retain') {
        // 1.38.461: bezahlte Bestellung behält Rechnungsname/-anschrift bis 31.12.(Jahr+10)
        t2_eq('Rechnungsname aufbewahrt (wie WordPress)', 'Klara', $ko['billing_first_name'] ?? null);
        t2_true('retained_until gesetzt', !empty($ko['retained_until']));
    } else {
        t2_eq('kein Klarname im Bestell-Verlauf', false, strpos(wp_json_encode($hist), 'Kern') !== false);
    }
    t2_true('Ticket anonymisiert, Code bleibt', TIX_Mirror_Map::is_anonymized(m2_one('tickets', $ktix[0])['owner_email'] ?? '') && (m2_one('tickets', $ktix[0])['code'] ?? '') !== '');
    t2_eq('kein Klarname im Ticket-Verlauf', false, strpos(wp_json_encode(m2_rows('change_log', ['entity' => 'eq.tickets', 'object_id' => 'eq.' . $ktix[0]])), 'Klara') !== false);
    t2_eq('Konto im Spiegel gelöscht', null, m2_one('customers', $kuid));
    t2_eq('kein Verlaufseintrag mit der E-Mail', 0, count(m2_rows('change_log', ['email' => 'eq.' . $mail])));
    // Aufbewahrung (1.38.461): WordPress behält Rechnungsdaten → der Spiegel zeigt den Stand von WordPress
    $wpdb->update($wpdb->prefix . 'tix_orders', ['billing_first_name' => 'Klara', 'billing_last_name' => 'Kern', 'billing_city' => 'Bonn'], ['id' => $koid]);
    m2_run();
    t2_eq('aufbewahrte Rechnungsdaten folgen WordPress', 'Bonn', m2_one('orders', $koid)['billing_city'] ?? null);
    $year = (int) substr((string) $wpdb->get_var($wpdb->prepare("SELECT date_created FROM {$wpdb->prefix}tix_orders WHERE id = %d", $koid)), 0, 4);
    t2_eq('retained_until = 31.12.(Jahr+10)', ($year + 10) . '-12-31', m2_one('orders', $koid)['retained_until'] ?? null);
    sleep(1);
    $wpdb->update($wpdb->prefix . 'tix_orders', ['billing_first_name' => '[Gelöscht]', 'billing_last_name' => '', 'billing_city' => ''], ['id' => $koid]);
    m2_run();
    $hist = m2_rows('change_log', ['entity' => 'eq.orders', 'object_id' => 'eq.' . $koid]);
    t2_eq('nach Ablauf: Verlauf wieder nur anonym', false, strpos(wp_json_encode($hist), 'Bonn') !== false);
    $kr = m2_one('orders', $koid);
    t2_eq('nach Ablauf: retained_until leer', null, is_array($kr) && array_key_exists('retained_until', $kr) ? $kr['retained_until'] : 'fehlt');
    foreach ($ktix as $tid) wp_delete_post($tid, true);
    $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $koid]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $koid]);
    TIX_Mirror::touch('order', $koid);
}

// ── 11. Gast ohne Konto löschen (1.38.494, tix_guest_deleted) + Team-Protokoll (tix_audit_logged) ──
if (method_exists('TIX_Account_Delete', 'delete_guest')) {
    $gmail = "gast$sfx@example.test";
    $goid = TIX_Native_Checkout::create_order(['total' => 10.0, 'payment_method' => 'bank', 'billing_first_name' => 'Gerda', 'billing_last_name' => 'Gast',
        'billing_email' => $gmail, 'items' => [['event_id' => $ev, 'cat_index' => 0, 'qty' => 1, 'price' => 10.0, 'name' => 'N', 'event_title' => 'x']]]);
    TIX_Native_Checkout::update_order_status($goid, 'completed', 'admin');
    m2_run();
    t2_true('Gast-Bestellung im Spiegel mit E-Mail', (m2_one('orders', $goid)['billing_email'] ?? '') === $gmail);
    t2_true('Verlauf enthält die Gast-E-Mail', count(m2_rows('change_log', ['email' => 'eq.' . $gmail])) > 0);
    sleep(1);
    $gr = TIX_Account_Delete::delete_guest($gmail, 'studio');
    t2_true('Gast gelöscht', is_array($gr));
    m2_run(2);
    t2_true('Gast-Bestellung anonymisiert', TIX_Mirror_Map::is_anonymized(m2_one('orders', $goid)['billing_email'] ?? ''));
    t2_eq('kein Verlauf mehr mit der Gast-E-Mail', 0, count(m2_rows('change_log', ['email' => 'eq.' . $gmail])));
    t2_eq('Gast-E-Mail nirgends im Bestell-Verlauf', false, strpos(wp_json_encode(m2_rows('change_log', ['entity' => 'eq.orders', 'object_id' => 'eq.' . $goid])), $gmail) !== false);
    foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $goid, 'fields' => 'ids']) as $tid) wp_delete_post($tid, true);
    $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $goid]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $goid]);
    TIX_Mirror::touch('order', $goid);
}
if (class_exists('TIX_Customers') && method_exists('TIX_Customers', 'audit')) {
    $aid = TIX_Customers::audit('customer_wallet', 'u123', ['scope' => 'site', 'oid' => 0], ['amount' => 5.0, 'reason' => 'Kulanz, Konto DE89370400440532013000', 'as' => 'bonus']);
    m2_run();
    $a = m2_one('audit_log', $aid);
    t2_eq('Team-Protokoll gespiegelt', 'customer_wallet', $a['action'] ?? null);
    t2_eq('Betroffener nur als ID', 'u123', $a['subject_id'] ?? null);
    t2_true('Freitext im Protokoll maskiert', strpos(wp_json_encode($a), 'DE89370400440532013000') === false && strpos(wp_json_encode($a), 'Kulanz') !== false);
    $wpdb->delete($wpdb->prefix . 'tix_audit_log', ['id' => $aid]);
    TIX_Mirror::touch('audit', $aid);
}
if (isset($wid) || (class_exists('TIX_Wallet_Balance') && TIX_Wallet_Balance::ready())) {
    $wu = wp_create_user("wadj$sfx", wp_generate_password(), "wadj$sfx@example.test");
    $ww = TIX_Wallet_Balance::get_or_create($wu, 0);
    $adj = TIX_Wallet_Balance::post_tx((int) $ww['id'], ['type' => 'adjust', 'channel' => 'admin', 'amount_bonus' => 300, 'staff_user_id' => 1, 'allow_locked' => true,
        'idempotency_key' => "team:adj$sfx", 'meta' => ['reason' => 'Kulanz', 'by' => 'Team Test', 'manual' => 'credit']]);
    m2_run();
    $mt = !is_wp_error($adj) ? m2_one('wallet_transactions', (int) $adj['id']) : null;
    t2_eq('Gutschrift adjust/admin', 'adjust/admin', ($mt['type'] ?? '') . '/' . ($mt['channel'] ?? ''));
    t2_eq('Grund und Mitarbeiter in meta', 'Kulanz|Team Test', ($mt['meta']['reason'] ?? '') . '|' . ($mt['meta']['by'] ?? ''));
    $wpdb->delete($wpdb->prefix . 'tix_wallet_tx', ['wallet_id' => (int) $ww['id']]); $wpdb->delete($wpdb->prefix . 'tix_wallets', ['id' => (int) $ww['id']]);
    wp_delete_user($wu);
}

// ── 12. Geschenkgutschein mit Empfänger-Adresse (1.38.496): Spiegel, Löschweg, Anonymisierung ──
$rmail = "beschenkt$sfx@example.test";
$bmail = "kaeufer$sfx@example.test";
$boid = TIX_Native_Checkout::create_order(['total' => 25.0, 'payment_method' => 'bank', 'billing_first_name' => 'Kai', 'billing_last_name' => 'Kauf',
    'billing_email' => $bmail, 'items' => [['event_id' => $ev, 'cat_index' => 0, 'qty' => 1, 'price' => 25.0, 'name' => 'N', 'event_title' => 'x']]]);
$gcode = "GS-$sfx-GIFT";
$cp = get_option('tix_coupons', []); $cp = is_array($cp) ? $cp : [];
$cp[$gcode] = ['code' => $gcode, 'discount_type' => 'giftcard', 'value' => 25, 'balance' => 15, 'order_id' => $boid,
    'gift' => ['recipient_name' => 'Bea Schenk', 'recipient_email' => $rmail, 'from_name' => 'Kai', 'message' => 'Alles Gute'],
    'sent_to' => $rmail, 'sent_at' => gmdate('c'), 'sends' => [['ts' => gmdate('c'), 'to' => $rmail, 'by' => 'buyer', 'mode' => 'manual', 'ok' => true]],
    'redemptions' => [['id' => 'r1', 'ts' => time(), 'amount' => 10, 'channel' => 'pos', 'by' => 'Kasse 1', 'event_id' => $ev],
                      ['id' => 'r2', 'ts' => time(), 'amount' => -10, 'channel' => 'pos', 'by' => 'Kasse 1', 'event_id' => $ev, 'void_of' => 'r1']]];
update_option('tix_coupons', $cp);
m2_run();
$mc = m2_one('coupons', $gcode);
t2_eq('Gutschein mit Empfänger gespiegelt', $rmail, $mc['data']['gift']['recipient_email'] ?? null);
t2_eq('Bar-Abbuchung mit Storno (void_of)', 'r1', $mc['data']['redemptions'][1]['void_of'] ?? null);
t2_true('Verlauf enthält die Empfänger-Adresse', strpos(wp_json_encode(m2_rows('change_log', ['entity' => 'eq.coupons', 'object_id' => 'eq.' . $gcode])), $rmail) !== false);
// Beschenkte Person wird als Gast gelöscht → Gutschein-Verlauf mit ihrer Adresse getilgt (WordPress lässt den Gutschein heute stehen)
do_action('tix_guest_deleted', $rmail, ['source' => 'test']);
m2_run();
t2_eq('Gutschein-Verlauf mit Empfänger-Adresse getilgt', false, strpos(wp_json_encode(m2_rows('change_log', ['entity' => 'eq.coupons', 'object_id' => 'eq.' . $gcode])), $rmail) !== false);
// Käufer gelöscht → Bestellung anonymisiert; Gutschein noch mit Adresse → Vergleich meldet es
$wpdb->update($wpdb->prefix . 'tix_orders', ['billing_email' => "gelöscht-x$sfx@removed.invalid", 'billing_first_name' => '[Gelöscht]'], ['id' => $boid]);
$vout = (function () { ob_start(); include WP_PLUGIN_DIR . '/tixomat/supabase/tools/vergleich-wp.php'; return ob_get_clean(); })(); // eigener Gültigkeitsbereich
t2_true('Vergleich meldet Empfänger-Adresse trotz gelöschtem Käufer', strpos($vout, 'Gutscheine mit Empfänger-Adresse trotz gelöschtem Käufer: 1') !== false);
// Plugin anonymisiert (Vorschlag an den Plugin-Chat) → Spiegel behält nur den anonymen Stand
$cp = get_option('tix_coupons', []);
$anon = "gelöscht-x$sfx@removed.invalid";
$cp[$gcode]['gift'] = ['recipient_name' => '[Gelöscht]', 'recipient_email' => $anon, 'from_name' => '[Gelöscht]', 'message' => ''];
$cp[$gcode]['sent_to'] = $anon; $cp[$gcode]['sends'][0]['to'] = $anon;
update_option('tix_coupons', $cp);
m2_run();
$hist = m2_rows('change_log', ['entity' => 'eq.coupons', 'object_id' => 'eq.' . $gcode]);
t2_eq('nach Anonymisierung: nur der anonyme Verlaufseintrag', 1, count($hist));
t2_eq('keine Empfänger-Adresse mehr im Spiegel', false, strpos(wp_json_encode(m2_one('coupons', $gcode)), $rmail) !== false || strpos(wp_json_encode($hist), 'Bea Schenk') !== false);
t2_eq('Code und Restwert bleiben', 15.0, (float) (m2_one('coupons', $gcode)['balance'] ?? 0));
$vout = (function () { ob_start(); include WP_PLUGIN_DIR . '/tixomat/supabase/tools/vergleich-wp.php'; return ob_get_clean(); })(); // eigener Gültigkeitsbereich
t2_true('Vergleich danach ohne Treffer', strpos($vout, 'Gutscheine mit Empfänger-Adresse trotz gelöschtem Käufer: keine') !== false);
$cp = get_option('tix_coupons', []); unset($cp[$gcode]); update_option('tix_coupons', $cp);
foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $boid, 'fields' => 'ids']) as $tid) wp_delete_post($tid, true);
$wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $boid]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $boid]);
TIX_Mirror::touch('order', $boid);

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
