<?php
/**
 * Integrationstest weitere Team-Mitteilungen (1.38.595): Ereignisse gruppiert und vorbelegt, nur mit aktivem Modul,
 * Warteschlange (sofort bzw. gesammelt), Storno, Warteliste, Tischanfrage, Musikwunsch, Gewinnspiel, Guthaben,
 * Konto-Prüfung (Plattform), Team-Einladung angenommen. Räumt auf.
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-staff-events-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Staff_Push') || !defined('TIX_Staff_Push::EVENTS')) { echo "Tixomat ab 1.38.595 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function se_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
global $wpdb;
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$keep = [];
foreach ([TIX_Staff_Push::OPT_TOKENS, TIX_Staff_Push::OPT_EVENT_Q, TIX_Staff_Push::OPT_BATCH_AT] as $o) $keep[$o] = get_option($o, null);
$uid = wp_insert_user(['user_login' => 'se_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'user_email' => 'se' . wp_rand() . '@example.test', 'role' => $multi ? 'tix_organizer' : 'administrator']);
$t = (array) get_option(TIX_Staff_Push::OPT_TOKENS, []);
$t['se-token-' . $uid] = ['user' => $uid, 'platform' => 'ios', 'bundle' => 'de.test.app'];
update_option(TIX_Staff_Push::OPT_TOKENS, $t, false);
$e1 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SE Party']);
$org = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'SE Veranstalter']);
    update_post_meta($org, '_tix_org_user_id', $uid);
    update_post_meta($e1, '_tix_organizer_id', $org);
    // gebuchte Module des Veranstalters (sonst erscheinen Musikwunsch/Guthaben/Gewinnspiel nicht)
    if (class_exists('TIX_Plan')) TIX_Plan::save_org($org, ['music' => true, 'wallet' => true, 'raffles' => true]);
}
$sent = [];
add_filter('tix_staff_push_pre_send', function ($pre, $uids, $title, $body, $data) use (&$sent, $uid) {
    if (in_array($uid, $uids, true)) $sent[] = compact('title', 'body', 'data');
    return count($uids);
}, 10, 5);
delete_option(TIX_Staff_Push::OPT_EVENT_Q);
wp_clear_scheduled_hook(TIX_Staff_Push::CRON_EVENTS);

echo "── Auswahl ──\n";
wp_set_current_user($uid);
$r = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/staff/notify-prefs'));
$d = $r->get_data();
$keys = array_column((array) ($d['labels'] ?? []), 'key');
se_eq('Gruppen dabei', ['sales', 'stock', 'guests', 'requests', 'account'], array_column((array) ($d['groups'] ?? []), 'key'));
se_eq('Ticketbestellungen und Storno wählbar', true, in_array('sales_digest', $keys, true) && in_array('refund', $keys, true));
se_eq('Abrechnung/Konto-Prüfung nur auf der Plattform', $multi, in_array('settlement', $keys, true) && in_array('approval', $keys, true));
se_eq('jede Zeile mit Gruppe', true, !array_filter((array) $d['labels'], function ($l) { return empty($l['group']); }));
se_eq('Vorbelegung: Storno an, Guthaben aus', [true, false], [$d['prefs']['refund'] ?? null, $d['prefs']['wallet_topup'] ?? null]);
if (class_exists('TIX_Plan') && !$multi) {
    update_option(TIX_Plan::SITE_OPT, ['raffles' => false], false);
    $r = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/staff/notify-prefs'));
    se_eq('Modul Gewinnspiele aus: Ereignis verschwindet', false, in_array('raffle', array_column((array) $r->get_data()['labels'], 'key'), true));
    delete_option(TIX_Plan::SITE_OPT);
}
update_user_meta($uid, TIX_Staff_Push::META_PREFS, ['raffle' => true, 'wallet_topup' => true, 'music' => true]);

echo "── Sofort ──\n";
do_action('tix_waitlist_joined', $e1, 'waitlist');
do_action('tix_waitlist_joined', $e1, 'waitlist');
se_eq('Warteliste eingereiht, Versand geplant', [2, true], [count((array) get_option(TIX_Staff_Push::OPT_EVENT_Q)), (bool) wp_next_scheduled(TIX_Staff_Push::CRON_EVENTS)]);
$sent = [];
TIX_Staff_Push::flush_events();
se_eq('eine Meldung „2 neue Einträge“', true, count($sent) === 1 && strpos($sent[0]['body'], '2 neue Einträge') !== false && strpos($sent[0]['title'], 'Warteliste') !== false);
se_eq('Antippen öffnet das Event', 0, strpos((string) ($sent[0]['data']['action'] ?? ''), 'staff:event:' . $e1));
$sent = [];
do_action('tix_music_requested', $e1, 'Mr. Brightside', 'The Killers');
TIX_Staff_Push::flush_events();
se_eq('Musikwunsch mit Titel', 'Mr. Brightside – The Killers', $sent[0]['body'] ?? null);
$sent = [];
do_action('tix_table_requested', $e1, 1);
TIX_Staff_Push::flush_events();
se_eq('Tischanfrage', true, strpos($sent[0]['title'] ?? '', 'Tischanfrage') !== false);
$o = TIX_Order::create_legacy(['status' => 'completed', 'total' => 20, 'date_created' => current_time('mysql')], [['event_id' => $e1, 'qty' => 2, 'price' => 10]]);
$sent = [];
do_action('tix_order_cancelled', $o);
TIX_Staff_Push::flush_events();
se_eq('Storno: 2 Tickets, 20 €', true, isset($sent[0]) && strpos($sent[0]['body'], '2 Tickets storniert') !== false && strpos($sent[0]['body'], '20 €') !== false);
update_user_meta($uid, TIX_Staff_Push::META_PREFS, ['refund' => false, 'raffle' => true, 'wallet_topup' => true]);
$sent = [];
do_action('tix_order_cancelled', $o);
TIX_Staff_Push::flush_events();
se_eq('Storno abgewählt: nichts', 0, count($sent));

echo "── Gesammelt ──\n";
$sent = [];
do_action('tix_raffle_entered', $e1);
do_action('tix_raffle_entered', $e1);
do_action('tix_raffle_entered', $e1);
do_action('tix_wallet_tx_posted', ['type' => 'topup', 'amount_paid' => 2000, 'organizer_id' => $org]);
TIX_Staff_Push::flush_events(false);
se_eq('ohne Sammelzeit: noch nichts', 0, count($sent));
TIX_Staff_Push::flush_events(true);
$by = []; foreach ($sent as $s) $by[$s['data']['type'] ?? ''] = $s;
se_eq('Gewinnspiel: 3 neue Teilnahmen', true, isset($by['raffle']) && strpos($by['raffle']['body'], '3 neue Teilnahmen') !== false);
se_eq('Guthaben: 1 Aufladung · 20 €', true, isset($by['wallet_topup']) && strpos($by['wallet_topup']['body'], '20 €') !== false);
se_eq('Warteschlange leer', [], (array) get_option(TIX_Staff_Push::OPT_EVENT_Q, []));

echo "── Konto und Team ──\n";
$mate = wp_insert_user(['user_login' => 'sem_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'user_email' => 'sem' . wp_rand() . '@example.test', 'role' => 'tix_staff', 'display_name' => 'Mia Team']);
if ($multi) update_user_meta($mate, '_tix_team_organizer_id', $org);
update_user_meta($mate, TIX_Team::META_INVITE, time());
$sent = [];
do_action('password_reset', get_user_by('id', $mate), 'x');
TIX_Staff_Push::flush_events();
se_eq('Team: Einladung angenommen', true, isset($sent[0]) && strpos($sent[0]['body'], 'Mia Team') !== false);
if ($multi) {
    $sent = [];
    do_action('tix_org_approval_changed', $org, 'approved', 'pending');
    TIX_Staff_Push::flush_events();
    se_eq('Konto-Prüfung: freigegeben', true, isset($sent[0]) && strpos($sent[0]['body'], 'freigegeben') !== false && strpos((string) $sent[0]['data']['action'], 'staff:approval') === 0);
    $sent = [];
    do_action('tix_settlement_status_changed', 77, 'paid', (object) ['organizer_id' => $org]);
    TIX_Staff_Push::flush_events();
    se_eq('Abrechnung: ausgezahlt → staff:settlement', 0, strpos((string) ($sent[0]['data']['action'] ?? ''), 'staff:settlement:77'));
}

// Aufräumen
$wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $o]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $o]);
foreach ([$e1, $org] as $p) if ($p) wp_delete_post($p, true);
wp_clear_scheduled_hook(TIX_Staff_Push::CRON_EVENTS);
foreach ($keep as $o2 => $v) { if ($v === null) delete_option($o2); else update_option($o2, $v, false); }
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($uid); wp_delete_user($mate);
wp_set_current_user(0);
echo $GLOBALS['t_fails'] === 0 ? "\nALLES GRÜN ({$GLOBALS['t_count']} Prüfungen)\n" : "\n{$GLOBALS['t_fails']} von {$GLOBALS['t_count']} FEHLGESCHLAGEN\n";
