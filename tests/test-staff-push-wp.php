<?php
/**
 * Integrationstest TIX_Staff_Push (Veranstalter-Mitteilungen, P6, 1.38.574) gegen ein lokales Test-WordPress.
 *   wp eval-file wp-content/plugins/tixomat/tests/test-staff-push-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Staff_Push')) { echo "WordPress mit Tixomat (TIX_Staff_Push) nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
$at = '@' . strtolower(preg_replace('/^www\\./', '', $h));
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function sp_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function sp_rest($m, $r, array $b = [], array $hd = [], $raw = null) {
    $q = new WP_REST_Request($m, '/tixomat/v1' . $r);
    foreach ($hd as $k => $v) $q->set_header($k, $v);
    if ($raw !== null) { $q->set_header('content-type', 'application/json'); $q->set_body($raw); }
    elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); }
    $res = rest_do_request($q); $d = $res->get_data();
    return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : ''];
}
$keep = ['tok' => get_option(TIX_Staff_Push::OPT_TOKENS, null), 'guest' => get_option('_tix_push_tokens', null), 'digest' => get_option(TIX_Staff_Push::OPT_DIGEST_AT, null),
    'sa' => get_option(TIX_Staff_Push::OPT_RELAY_SA, null), 'sites' => get_option(TIX_Staff_Push::OPT_RELAY_SITES, null)];
$sfx = wp_rand(1000, 9999); $users = []; $posts = [];
$mk = function ($role) use (&$users, $sfx) { $id = wp_insert_user(['user_login' => "sp_{$role}_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "sp_{$role}_$sfx@example.test", 'role' => $role]); $users[] = $id; return $id; };
$staff = $mk('tix_staff'); $guest = $mk('subscriber'); $aw = $mk('tix_awareness');
// Plattform: Konten gehören zu einem Veranstalter (Inhaber bzw. Team), Events und Anfragen tragen ihn
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$org = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'SP Veranstalter', 'meta_input' => ['_tix_org_user_id' => $staff]]);
    update_user_meta($aw, '_tix_team_organizer_id', $org);
}
$sent = [];
add_filter('tix_staff_push_pre_send', function ($pre, $uids, $title, $body, $data) use (&$sent) { $sent[] = compact('uids', 'title', 'body', 'data'); return count($uids); }, 10, 5);
$ios = str_repeat('ab', 32);

echo "── Geräte ──\n";
wp_set_current_user(0);
[$st] = sp_rest('POST', '/push/register', ['token' => $ios, 'platform' => 'ios', 'app' => 'staff', 'bundle' => TIX_Staff_Push::APP_BUNDLE]);
sp_eq('ohne Anmeldung 401', 401, $st);
wp_set_current_user($guest);
[$st] = sp_rest('POST', '/push/register', ['token' => $ios, 'platform' => 'ios', 'app' => 'staff']);
sp_eq('Gast-Konto 403', 403, $st);
wp_set_current_user($staff);
[$st, $d] = sp_rest('POST', '/push/register', ['token' => $ios, 'platform' => 'ios', 'app' => 'staff', 'bundle' => TIX_Staff_Push::APP_BUNDLE]);
sp_eq('Team-Konto 200', 200, $st);
sp_eq('Antwort app=staff', 'staff', $d['app'] ?? null);
sp_eq('Token in der Veranstalter-Liste', true, isset(TIX_Staff_Push::tokens()[$ios]));
sp_eq('nicht bei den Gast-Pushes', false, isset(((array) get_option('_tix_push_tokens', []))[$ios]));
wp_set_current_user($aw);
sp_rest('POST', '/push/register', ['token' => str_repeat('cd', 32), 'platform' => 'ios', 'app' => 'staff', 'bundle' => 'de.kitchenklub.app']);

echo "── Einstellungen ──\n";
wp_set_current_user($staff);
[$st, $d] = sp_rest('GET', '/staff/notify-prefs');
sp_eq('Vorgabe: alles an', [true, true, true, true, true], array_values($d['prefs'] ?? []));
sp_eq('ein Gerät', 1, $d['devices'] ?? null);
[$st, $d] = sp_rest('POST', '/staff/notify-prefs', ['prefs' => ['sales_digest' => false, 'unbekannt' => true]]);
sp_eq('speichern: Verkäufe aus', false, $d['prefs']['sales_digest'] ?? null);
sp_eq('unbekannte Schlüssel ignoriert', false, array_key_exists('unbekannt', $d['prefs'] ?? []));
wp_set_current_user($guest);
[$st] = sp_rest('GET', '/staff/notify-prefs');
sp_eq('Gast: 403', 403, $st);

echo "── Bestand ──\n";
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SP Testevent', 'meta_input' => ['_tix_syndicate' => '0', '_tix_organizer_id' => $org]]); $posts[] = $ev;
if ($org) $posts[] = $org;
update_post_meta($ev, '_tix_ticket_categories', [['name' => 'A', 'price' => 10, 'qty' => 100, 'stock' => 50]]);
sp_eq('genug da: kein Zustand', null, TIX_Staff_Push::stock_state($ev));
update_post_meta($ev, '_tix_ticket_categories', [['name' => 'A', 'price' => 10, 'qty' => 100, 'stock' => 8]]);
sp_eq('8 von 100: knapp', 'low_stock', TIX_Staff_Push::stock_state($ev));
update_post_meta($ev, '_tix_ticket_categories', [['name' => 'A', 'price' => 10, 'qty' => 100, 'stock' => 0], ['name' => 'B', 'price' => 5, 'qty' => 0]]);
sp_eq('unbegrenzte Kategorie: nie ausverkauft', null, TIX_Staff_Push::stock_state($ev));
update_post_meta($ev, '_tix_ticket_categories', [['name' => 'A', 'price' => 10, 'qty' => 100, 'stock' => 0]]);
sp_eq('0 übrig: ausverkauft', 'sold_out', TIX_Staff_Push::stock_state($ev));
$sent = [];
TIX_Staff_Push::check_stock($ev);
TIX_Staff_Push::check_stock($ev);
sp_eq('„Ausverkauft“ genau einmal', 1, count($sent));
sp_eq('an das Team-Konto', [$staff], $sent[0]['uids'] ?? null);
sp_eq('Titel mit Seitenname', true, strpos($sent[0]['title'] ?? '', 'Ausverkauft') !== false);
sp_eq('Aktion staff:event mit Shop', 'staff:event:' . $ev . $at, $sent[0]['data']['action'] ?? null);
sp_eq('Awareness-Konto bekommt Verkauf nicht', false, in_array($aw, $sent[0]['uids'] ?? [], true));

echo "── Sammelmeldung ──\n";
wp_set_current_user($staff);
sp_rest('POST', '/staff/notify-prefs', ['prefs' => ['sales_digest' => true]]);
// Verkäufe kommen seit 1.38.592 aus den Bestellungen (mit Umsatz); Merker je Konto
update_user_meta($staff, TIX_Staff_Push::META_SALES, ['mode' => 'interval', 'hours' => 1, 'times' => [], 'last' => time() - 3600]);
$sp_order = TIX_Order::create_legacy(['status' => 'completed', 'total' => 30, 'date_created' => wp_date('Y-m-d H:i:s', time() - 60)], [['event_id' => $ev, 'qty' => 3, 'price' => 10]]);
$sent = [];
TIX_Staff_Push::cron_digest(time() + 5);
sp_eq('eine Sammelmeldung', 1, count($sent));
sp_eq('3 Tickets', true, strpos($sent[0]['body'] ?? '', '3 Tickets') !== false);
sp_eq('Event und Umsatz im Text', true, strpos($sent[0]['body'] ?? '', 'SP Testevent') !== false && strpos($sent[0]['body'] ?? '', '30 €') !== false);
$sent = [];
TIX_Staff_Push::cron_digest(time() + 10);
sp_eq('keine neuen Tickets: keine Meldung', 0, count($sent));
wp_set_current_user($staff);
sp_rest('POST', '/staff/notify-prefs', ['prefs' => ['sales_digest' => false]]);
update_user_meta($staff, TIX_Staff_Push::META_SALES, ['mode' => 'off', 'hours' => 1, 'times' => [], 'last' => time() - 3600]);
$sent = [];
TIX_Staff_Push::cron_digest(time() + 15);
sp_eq('Verkäufe abgeschaltet: keine Meldung', 0, count($sent));

echo "── Kundenanfrage / Awareness ──\n";
$sent = [];
$sp = wp_insert_post(['post_type' => 'tix_support_ticket', 'post_status' => 'tix_open', 'post_title' => 'Frage zum Einlass']); $posts[] = $sp;
update_post_meta($sp, '_tix_sp_organizer_id', $org);
TIX_Staff_Push::flush_support_queue();
sp_eq('Kundenanfrage gemeldet', 'staff:support:' . $sp . $at, $sent[0]['data']['action'] ?? null);
$sent = [];
$a2 = wp_insert_post(['post_type' => 'tix_support_ticket', 'post_status' => 'tix_open', 'post_title' => 'Belästigung an der Bar']); $posts[] = $a2;
update_post_meta($a2, '_tix_sp_category', 'awareness');
update_post_meta($a2, '_tix_sp_organizer_id', $org);
TIX_Staff_Push::flush_support_queue();
sp_eq('Awareness gemeldet', 'staff:awareness:' . $a2 . $at, $sent[0]['data']['action'] ?? null);
sp_eq('Awareness auch an Awareness-Team', true, in_array($aw, $sent[0]['uids'] ?? [], true));
TIX_Staff_Push::notify_support($a2);
sp_eq('nicht doppelt', 1, count($sent));

if ($multi) {
    $other = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'SP Fremd']); $posts[] = $other;
    $ev2 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SP Fremdes Event', 'meta_input' => ['_tix_syndicate' => '0', '_tix_organizer_id' => $other, '_tix_ticket_categories' => [['name' => 'A', 'price' => 1, 'qty' => 10, 'stock' => 0]]]]); $posts[] = $ev2;
    $sent = [];
    TIX_Staff_Push::check_stock($ev2);
    sp_eq('Plattform: fremder Veranstalter → keine Mitteilung an mein Team', [], $sent[0]['uids'] ?? []);
}

echo "── Weiterleitung ──\n";
update_option(TIX_Staff_Push::OPT_RELAY_SA, '{"type":"service_account"}', false);
update_option(TIX_Staff_Push::OPT_RELAY_SITES, ['shop.test' => 'geheim123'], false);
$raw = wp_json_encode(['devices' => [], 'title' => 'T', 'body' => 'B', 'data' => ['action' => 'staff:start', 'site' => 'gefälscht.de']]);
$ts = time();
[$st, $d] = sp_rest('POST', '/push/relay', [], ['x-tix-site' => 'shop.test', 'x-tix-ts' => (string) $ts, 'x-tix-sig' => TIX_Staff_Push::sign('geheim123', 'shop.test', $ts, $raw)], $raw);
sp_eq('signiert: 200', 200, $st);
[$st] = sp_rest('POST', '/push/relay', [], ['x-tix-site' => 'shop.test', 'x-tix-ts' => (string) $ts, 'x-tix-sig' => 'falsch'], $raw);
sp_eq('falsche Signatur: 403', 403, $st);
$old = time() - 900;
[$st] = sp_rest('POST', '/push/relay', [], ['x-tix-site' => 'shop.test', 'x-tix-ts' => (string) $old, 'x-tix-sig' => TIX_Staff_Push::sign('geheim123', 'shop.test', $old, $raw)], $raw);
sp_eq('alte Anfrage: 403', 403, $st);
[$st] = sp_rest('POST', '/push/relay', [], ['x-tix-site' => 'fremd.test', 'x-tix-ts' => (string) $ts, 'x-tix-sig' => TIX_Staff_Push::sign('geheim123', 'fremd.test', $ts, $raw)], $raw);
sp_eq('unbekannte Seite: 403', 403, $st);

// Aufräumen
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($users as $u) wp_delete_user($u);
global $wpdb;
if (!empty($sp_order)) { $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $sp_order]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $sp_order]); }
foreach (['tok' => TIX_Staff_Push::OPT_TOKENS, 'guest' => '_tix_push_tokens', 'digest' => TIX_Staff_Push::OPT_DIGEST_AT, 'sa' => TIX_Staff_Push::OPT_RELAY_SA, 'sites' => TIX_Staff_Push::OPT_RELAY_SITES] as $k => $o) {
    if ($keep[$k] === null) delete_option($o); else update_option($o, $keep[$k], false);
}
echo "\n" . ($GLOBALS['t_fails'] ? $GLOBALS['t_fails'] . ' FEHLER' : 'ALLES GRÜN') . " ({$GLOBALS['t_count']} Prüfungen)\n";
