<?php
/**
 * Integrationstest Datenspiegel (TIX_Mirror) gegen ein Test-WordPress und einen
 * LOKALEN Supabase-Ersatz (Postgres + PostgREST unter /rest/v1/, Migrationen aus
 * supabase/migrations eingespielt, Zugang in der Option tix_mirror).
 * NIE auf einer Live-Seite oder gegen das echte Spiegel-Projekt ausführen:
 * legt Events, Nutzer, Bestellungen an, löscht Spiegel-Zeilen der Seite und räumt am Ende auf.
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-mirror-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Mirror')) { echo "WordPress mit Tixomat nötig\n"; return; }
if (stripos(home_url(), 'localhost') === false && stripos(home_url(), '.test') === false) { echo "Nur auf lokalen Test-Seiten!\n"; return; }
if (stripos(TIX_Mirror_Client::url(), 'supabase.co') !== false) { echo "Nur gegen einen lokalen Spiegel!\n"; return; }

$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function t_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function t_true($label, $cond) { t_eq($label, true, (bool) $cond); }
function m_rows($table, array $f = []) {
    $r = TIX_Mirror_Client::select($table, array_merge(['site' => 'eq.' . TIX_Mirror::site()], $f));
    return $r['ok'] ? (array) $r['body'] : ['ERROR' => $r['error']];
}
function m_one($table, $id) { $r = m_rows($table, ['id' => 'eq.' . $id]); return $r[0] ?? null; }
function m_run($times = 1) {
    TIX_Mirror::flush_buffer();
    for ($i = 0; $i < $times; $i++) { delete_option(TIX_Mirror::LOCK); TIX_Mirror::cron_run(); }
}
add_filter('pre_wp_mail', function () { return true; });

global $wpdb;
$site = TIX_Mirror::site();
$sfx = wp_rand(1000, 9999);
$created_posts = []; $created_users = []; $created_orders = [];
update_option(TIX_Mirror_Backfill::OPTION, ['state' => 'done', 'finished_at' => time()], false); // Erstbefüllung erst am Ende testen
TIX_Mirror::maybe_install();
$wpdb->query("DELETE FROM " . TIX_Mirror::table());
m_run();

// ── 0. Schalter aus → nichts erfasst ──
$s0 = TIX_Mirror::settings();
TIX_Mirror::save_settings(['enabled' => 0]);
TIX_Mirror::touch('event', 999999);
TIX_Mirror::flush_buffer();
t_eq('aus: nichts im Postausgang', 0, TIX_Mirror::pending_count());
TIX_Mirror::save_settings(['enabled' => 1]);

// ── 1. Event mit Kategorien ──
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "Spiegel-Test $sfx"]);
$created_posts[] = $ev;
update_post_meta($ev, '_tix_date_start', '2026-12-31');
update_post_meta($ev, '_tix_time_start', '20:00');
update_post_meta($ev, '_tix_checkin_password', 'geheim');
update_post_meta($ev, '_tix_ticket_categories', [
    ['name' => 'Normal', 'price' => '20', 'qty' => 100, 'stock' => 100, 'online' => '1'],
    ['name' => 'VIP', 'price' => '50', 'qty' => 10, 'stock' => 10, 'online' => '1'],
]);
t_true('Event im Postausgang (vor Senden)', TIX_Mirror::pending_count() >= 0);
m_run();
$e = m_one('events', $ev);
t_eq('Event gespiegelt', "Spiegel-Test $sfx", $e['title'] ?? null);
t_eq('Event-Beginn in UTC', '2026-12-31T19:00:00+00:00', $e['starts_at'] ?? null);
t_eq('Check-in-Passwort nicht gespiegelt', false, isset($e['data']['_tix_checkin_password']));
t_eq('2 Kategorien', 2, count(m_rows('ticket_categories', ['event_id' => 'eq.' . $ev])));
update_post_meta($ev, '_tix_ticket_categories', [['name' => 'Normal', 'price' => '22', 'qty' => 100, 'stock' => 100]]);
m_run();
$cats = m_rows('ticket_categories', ['event_id' => 'eq.' . $ev]);
t_eq('entfernte Kategorie gelöscht', 1, count($cats));
t_eq('Preis aktualisiert', 22.0, (float) ($cats[0]['price'] ?? 0));
t_eq('Postausgang leer', 0, TIX_Mirror::pending_count());

// ── 2. Kunde ──
$uid = wp_create_user("spiegel$sfx", wp_generate_password(), "spiegel$sfx@example.test");
$created_users[] = $uid;
wp_update_user(['ID' => $uid, 'first_name' => 'Sina', 'last_name' => 'Spiegel', 'role' => 'tix_customer']);
update_user_meta($uid, 'billing_city', 'Köln');
update_user_meta($uid, '_tix_app_tokens', ['abc' => 'geheim']);
m_run();
$c = m_one('customers', $uid);
t_eq('Kunde gespiegelt', "spiegel$sfx@example.test", $c['email'] ?? null);
t_eq('Rechnungsort', 'Köln', $c['billing']['city'] ?? null);
t_eq('App-Tokens nicht gespiegelt', false, isset($c['data']['_tix_app_tokens']));
t_eq('kein Passwort-Hash', false, strpos(wp_json_encode($c), '$P$') !== false || strpos(wp_json_encode($c), '$wp$') !== false);

// ── 3. Bestellung → bezahlt → Tickets ──
wp_set_current_user($uid);
$oid = TIX_Native_Checkout::create_order([
    'total' => 44.0, 'payment_method' => 'bank',
    'billing_first_name' => 'Sina', 'billing_last_name' => 'Spiegel', 'billing_email' => "spiegel$sfx@example.test",
    'billing_city' => 'Köln',
    'items' => [['event_id' => $ev, 'cat_index' => 0, 'qty' => 2, 'price' => 22.0, 'name' => 'Normal', 'event_title' => "Spiegel-Test $sfx"]],
]);
wp_set_current_user(0);
$created_orders[] = $oid;
t_true('Bestellung angelegt', $oid > 0);
if (!$oid) { echo "Abbruch: keine Bestellung (Tabellen fehlen? einmal admin_init laufen lassen)\n"; return; }
$t0 = microtime(true);
TIX_Native_Checkout::update_order_status($oid, 'completed', 'admin');
$dt = microtime(true) - $t0;
echo "      (Statuswechsel inkl. Tickets: " . round($dt * 1000) . " ms)\n";
m_run();
$o = m_one('orders', $oid);
t_eq('Bestellung gespiegelt', 'completed', $o['status'] ?? null);
t_eq('Veranstalter-unabhängig: Event-IDs', [$ev], $o['event_ids'] ?? null);
t_eq('kein order_key im Spiegel', false, array_key_exists('order_key', (array) $o) || strpos(wp_json_encode($o), 'order_key') !== false);
t_eq('1 Position', 1, count(m_rows('order_items', ['order_id' => 'eq.' . $oid])));
t_eq('Zahlung', 'bank', (m_one('payments', $oid)['provider'] ?? null));
$tickets = get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $oid, 'fields' => 'ids']);
t_eq('2 Tickets in WP', 2, count($tickets));
$mt = m_rows('tickets', ['order_id' => 'eq.' . $oid]);
t_eq('2 Tickets im Spiegel', 2, count($mt));
t_eq('Ticket-Code gespiegelt', get_post_meta($tickets[0], '_tix_ticket_code', true), m_one('tickets', $tickets[0])['code'] ?? null);
t_eq('Download-Token nicht gespiegelt', false, strpos(wp_json_encode($mt), 'download_token') !== false);

// ── 4. Direkter $wpdb-Schreibzugriff ohne Hook → Nachlauf über date_modified ──
sleep(1);
$wpdb->update($wpdb->prefix . 'tix_orders', ['billing_phone' => '0221 12345'], ['id' => $oid]);
m_run();
t_eq('Nachlauf holt direkte Änderung', '0221 12345', m_one('orders', $oid)['billing_phone'] ?? null);

// ── 5. Check-in und Rücknahme ──
TIX_Tickets::checkin_ticket($tickets[0], 'test');
m_run();
t_eq('Ticket eingecheckt', true, m_one('tickets', $tickets[0])['checked_in'] ?? null);
TIX_Tickets::reset_checkin($tickets[0]);
TIX_Tickets::checkin_ticket($tickets[0], 'test');
m_run();
$ci = m_rows('checkins', ['ticket_id' => 'eq.' . $tickets[0], 'order' => 'at.asc']);
t_eq('Check-in-Verlauf: in, reset, in', ['in', 'reset', 'in'], array_column($ci, 'action'));

// ── 6. Teil-Erstattung und Storno ──
$r = TIX_Order_Admin::process_refund($oid, 4.0, false, 'Test-Erstattung', 'admin');
if (is_wp_error($r)) echo "      (Erstattung: " . $r->get_error_message() . ")\n";
m_run();
$rf = m_rows('refunds', ['order_id' => 'eq.' . $oid, 'kind' => 'eq.refund']);
t_eq('Erstattung im Verlauf', 1, count($rf));
t_eq('Erstattungsbetrag', 4.0, (float) ($rf[0]['amount'] ?? 0));
t_eq('Bestellung: erstattet gesamt', 4.0, (float) (m_one('orders', $oid)['refunded_total'] ?? 0));
TIX_Native_Checkout::update_order_status($oid, 'cancelled', 'admin');
m_run();
t_eq('Storno im Verlauf', 1, count(m_rows('refunds', ['order_id' => 'eq.' . $oid, 'kind' => 'eq.cancel'])));
t_eq('Status storniert', 'cancelled', m_one('orders', $oid)['status'] ?? null);
t_eq('Tickets storniert', 'cancelled', m_one('tickets', $tickets[1])['post_status'] ?? null);

// ── 7. Änderungsprotokoll: nur echte Änderungen ──
$log = function () use ($ev) { return count(m_rows('change_log', ['entity' => 'eq.events', 'object_id' => 'eq.' . $ev])); };
$n1 = $log();
TIX_Mirror::touch('event', $ev);
m_run();
t_eq('gleicher Stand → kein neuer Verlaufseintrag', $n1, $log());
wp_update_post(['ID' => $ev, 'post_title' => "Spiegel-Test $sfx neu"]);
m_run();
t_eq('Titeländerung → ein Verlaufseintrag', $n1 + 1, $log());

// ── 8. Supabase nicht erreichbar → Bestellung läuft, Postausgang wartet ──
$good = TIX_Mirror::settings()['url'];
TIX_Mirror::save_settings(['url' => 'http://127.0.0.1:9']);
$t0 = microtime(true);
$oid2 = TIX_Native_Checkout::create_order([
    'total' => 22.0, 'payment_method' => 'bank', 'billing_first_name' => 'Ausfall', 'billing_last_name' => 'Test',
    'billing_email' => "ausfall$sfx@example.test",
    'items' => [['event_id' => $ev, 'cat_index' => 0, 'qty' => 1, 'price' => 22.0, 'name' => 'Normal', 'event_title' => 'x']],
]);
TIX_Native_Checkout::update_order_status($oid2, 'completed', 'admin');
TIX_Mirror::flush_buffer();
$dt = microtime(true) - $t0;
$created_orders[] = $oid2;
t_true('Bestellung trotz Ausfall angelegt', $oid2 > 0 && $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tix_orders WHERE id = %d", $oid2)) === 'completed');
t_true('Bestellen ohne Netzwerk-Wartezeit (< 2 s)', $dt < 2.0);
m_run();
$row = $wpdb->get_row($wpdb->prepare("SELECT attempts, last_error FROM " . TIX_Mirror::table() . " WHERE entity = 'order' AND object_id = %s", (string) $oid2), ARRAY_A);
t_eq('Eintrag bleibt mit Fehlversuch', 1, (int) ($row['attempts'] ?? 0));
t_true('Fehlertext ohne Schlüssel', ($row['last_error'] ?? '') !== '' && strpos($row['last_error'], TIX_Mirror::settings()['key']) === false);
TIX_Mirror::save_settings(['url' => $good]);
$wpdb->query("UPDATE " . TIX_Mirror::table() . " SET next_try = UTC_TIMESTAMP()");
m_run();
t_eq('Rückstand nachgeschickt', 0, TIX_Mirror::pending_count());
t_eq('Ausfall-Bestellung im Spiegel', 'completed', m_one('orders', $oid2)['status'] ?? null);

// ── 9. Löschweg: Konto löschen ──
$mail = "spiegel$sfx@example.test";
t_true('Verlauf mit E-Mail vorhanden', count(m_rows('change_log', ['email' => 'eq.' . $mail])) > 0);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($uid);
$created_users = [];
m_run();
t_eq('Kunde im Spiegel gelöscht', null, m_one('customers', $uid));
t_eq('Verlauf mit E-Mail getilgt', 0, count(m_rows('change_log', ['email' => 'eq.' . $mail])));
t_eq('Verlauf mit Kunden-ID getilgt', 0, count(m_rows('change_log', ['customer_id' => 'eq.' . $uid])));
t_true('Bestellung bleibt (wie in WordPress)', m_one('orders', $oid) !== null);

// ── 10. Ticket endgültig gelöscht → im Spiegel weg ──
wp_delete_post($tickets[1], true);
m_run();
t_eq('gelöschtes Ticket entfernt', null, m_one('tickets', $tickets[1]));

// ── 11. Erstbefüllung (unterbrechbar) ──
foreach (['events', 'ticket_categories', 'customers', 'orders', 'order_items', 'payments', 'tickets'] as $tb) {
    TIX_Mirror_Client::delete($tb, ['site' => 'eq.' . $site]);
}
t_eq('Spiegel geleert', 0, count(m_rows('orders')));
TIX_Mirror_Backfill::start();
TIX_Mirror_Backfill::step(0.0001);     // ein Paket
TIX_Mirror_Backfill::stop();
$paused = TIX_Mirror_Backfill::get();
t_eq('angehalten', 'paused', $paused['state']);
TIX_Mirror_Backfill::resume();
for ($i = 0; $i < 30 && (TIX_Mirror_Backfill::get()['state'] !== 'done' || TIX_Mirror::pending_count() > 0); $i++) m_run();
t_eq('Erstbefüllung fertig', 'done', TIX_Mirror_Backfill::get()['state']);
$wp_events  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'event' AND post_status <> 'auto-draft'");
$wp_tickets = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tix_ticket' AND post_status <> 'auto-draft'");
$wp_orders  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tix_orders");
$wp_users   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
t_eq('Events vollständig', $wp_events, count(m_rows('events', ['select' => 'id'])));
t_eq('Tickets vollständig', $wp_tickets, count(m_rows('tickets', ['select' => 'id'])));
t_eq('Bestellungen vollständig', $wp_orders, count(m_rows('orders', ['select' => 'id'])));
t_eq('Kunden vollständig', $wp_users, count(m_rows('customers', ['select' => 'id'])));
t_true('Seite gemeldet', m_rows('sites', ['site' => 'eq.' . $site]) !== []);

// ── 12. Alarm-Mail nach vielen Fehlversuchen (höchstens alle 6 Stunden) ──
$GLOBALS['t_mails'] = [];
add_filter('pre_wp_mail', function ($null, $atts) { $GLOBALS['t_mails'][] = $atts; return true; }, 5, 2);
TIX_Mirror::save_settings(['url' => 'http://127.0.0.1:9', 'alert_email' => 'alarm@example.test']);
TIX_Mirror::set_state(['alert_at' => 0]);
TIX_Mirror::touch('event', $ev);
TIX_Mirror::flush_buffer();
$wpdb->query("UPDATE " . TIX_Mirror::table() . " SET attempts = " . (TIX_Mirror::ALERT_TRIES - 1) . ", next_try = UTC_TIMESTAMP()");
m_run();
m_run();
$alarm = array_values(array_filter($GLOBALS['t_mails'], function ($m) { return $m['to'] === 'alarm@example.test'; }));
t_eq('eine Alarm-Mail', 1, count($alarm));
t_true('Alarm-Mail ohne Schlüssel', isset($alarm[0]) && strpos($alarm[0]['message'], TIX_Mirror::settings()['key']) === false);
TIX_Mirror::save_settings(['url' => $good, 'alert_email' => '']);
$wpdb->query("UPDATE " . TIX_Mirror::table() . " SET next_try = UTC_TIMESTAMP()");
m_run();
t_eq('nach Alarm wieder leer', 0, TIX_Mirror::pending_count());

// ── 13. Alte Ticket-Anbindung: Einstellungen werden vermerkt, nicht übernommen ──
$ts = get_option('tix_settings', []);
$ts_backup = $ts;
$ts['supabase_url'] = 'https://altes-projekt.supabase.co'; $ts['supabase_enabled'] = 1;
update_option('tix_settings', $ts);
$mirror_backup = TIX_Mirror::settings();
TIX_Mirror::save_settings(['legacy_url' => '']);
delete_option(TIX_Mirror::DB_OPTION);
TIX_Mirror::maybe_install();
t_eq('altes Projekt vermerkt', 'https://altes-projekt.supabase.co', TIX_Mirror::settings()['legacy_url'] ?? null);
t_eq('Spiegel-URL unverändert', $good, TIX_Mirror::settings()['url']);
t_eq('alte Anbindung aus', false, TIX_Sync_Supabase::is_enabled());
update_option('tix_settings', $ts_backup);
update_option(TIX_Mirror::OPTION, $mirror_backup, false);

// ── Aufräumen ──
foreach ($created_orders as $o_id) {
    foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $o_id, 'fields' => 'ids']) as $tid) wp_delete_post($tid, true);
    $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $o_id]);
    $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $o_id]);
    TIX_Mirror::touch('order', $o_id);
}
foreach ($created_posts as $p) wp_delete_post($p, true);
foreach ($created_users as $u) wp_delete_user($u);
m_run();
t_eq('Aufräumen gespiegelt', null, m_one('orders', $oid));
TIX_Mirror::save_settings($s0);

echo "\n" . ($GLOBALS['t_count'] - $GLOBALS['t_fails']) . "/" . $GLOBALS['t_count'] . " ok\n";
