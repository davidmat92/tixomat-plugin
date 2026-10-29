<?php
/**
 * Integrationstest Verkaufs-Mitteilungen (1.38.592): Modus je Konto (sofort, alle X Stunden, feste Uhrzeiten, aus),
 * Bestand stündlich, Fälligkeit je Konto, Sammelmeldung mit Anzahl/Umsatz/Top-Events, Sofort-Warteschlange. Räumt auf.
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-sales-push-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Staff_Push') || !method_exists('TIX_Staff_Push', 'sales')) { echo "Tixomat ab 1.38.592 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function sp_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function sp_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = $res->get_data();
    return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : ''];
}
global $wpdb;
$keep_tokens = get_option(TIX_Staff_Push::OPT_TOKENS, null);
$keep_q = get_option(TIX_Staff_Push::OPT_INSTANT_Q, null);
$uid = wp_insert_user(['user_login' => 'sp_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'user_email' => 'sp' . wp_rand() . '@example.test', 'role' => 'administrator']);
$t = (array) get_option(TIX_Staff_Push::OPT_TOKENS, []);
$t['sp-token-' . $uid] = ['user' => $uid, 'platform' => 'ios', 'bundle' => 'de.test.app'];
update_option(TIX_Staff_Push::OPT_TOKENS, $t, false);
// nur Meldungen an das Test-Konto mitschneiden
$sent = [];
add_filter('tix_staff_push_pre_send', function ($pre, $uids, $title, $body, $data) use (&$sent, $uid) {
    if (in_array($uid, $uids, true)) $sent[] = compact('title', 'body', 'data');
    return count($uids);
}, 10, 5);

echo "── Vorgaben ──\n";
sp_eq('Bestand: stündlich', ['interval', 1], [TIX_Staff_Push::sales($uid)['mode'], TIX_Staff_Push::sales($uid)['hours']]);
update_user_meta($uid, TIX_Staff_Push::META_PREFS, ['sales_digest' => false]);
sp_eq('Bestand mit „Verkäufe aus“: aus', 'off', TIX_Staff_Push::sales($uid)['mode']);
delete_user_meta($uid, TIX_Staff_Push::META_PREFS);

echo "── Speichern ──\n";
wp_set_current_user($uid);
[$st, $d] = sp_rest('GET', '/staff/notify-prefs');
sp_eq('GET: sales mit Stunden-Auswahl', [1, 2, 3, 4, 6, 12, 24], $d['sales']['hours_options'] ?? null);
[$st, $d] = sp_rest('POST', '/staff/notify-prefs', ['sales' => ['mode' => 'times', 'times' => ['18:00', '12:00', '25:00', '12:00']]]);
sp_eq('feste Uhrzeiten: sortiert, ungültige weg', ['times', ['12:00', '18:00']], [$d['sales']['mode'] ?? null, $d['sales']['times'] ?? null]);
[$st, , $code] = sp_rest('POST', '/staff/notify-prefs', ['sales' => ['mode' => 'interval', 'hours' => 5]]);
sp_eq('5 Stunden abgelehnt', [400, 'tix_invalid_field'], [$st, $code]);
[$st, , $code] = sp_rest('POST', '/staff/notify-prefs', ['sales' => ['mode' => 'times', 'times' => []]]);
sp_eq('feste Uhrzeiten ohne Zeit abgelehnt', 400, $st);
[$st, $d] = sp_rest('POST', '/staff/notify-prefs', ['prefs' => ['sales_digest' => false]]);
sp_eq('alter Schalter aus → Modus aus', 'off', $d['sales']['mode'] ?? null);
[$st, $d] = sp_rest('POST', '/staff/notify-prefs', ['prefs' => ['sales_digest' => true]]);
sp_eq('alter Schalter an → stündlich', ['interval', 1], [$d['sales']['mode'] ?? null, $d['sales']['hours'] ?? null]);

echo "── Fälligkeit ──\n";
$now = time();
sp_eq('alle 2 Std., zuletzt vor 3 Std.: fällig', true, TIX_Staff_Push::sales_due(['mode' => 'interval', 'hours' => 2, 'times' => [], 'last' => $now - 3 * HOUR_IN_SECONDS], $now));
sp_eq('alle 2 Std., zuletzt vor 1 Std.: nicht fällig', false, TIX_Staff_Push::sales_due(['mode' => 'interval', 'hours' => 2, 'times' => [], 'last' => $now - HOUR_IN_SECONDS], $now));
$tz = new DateTimeZone('Europe/Berlin');
$noon = (new DateTime('today 12:03', $tz))->getTimestamp();
$at12 = (new DateTime('today 12:00', $tz))->getTimestamp();
sp_eq('12:00, jetzt 12:03, zuletzt 11:00: fällig', true, TIX_Staff_Push::sales_due(['mode' => 'times', 'hours' => 1, 'times' => ['12:00', '18:00'], 'last' => $at12 - HOUR_IN_SECONDS], $noon));
sp_eq('12:00, zuletzt 12:01: nicht fällig', false, TIX_Staff_Push::sales_due(['mode' => 'times', 'hours' => 1, 'times' => ['12:00', '18:00'], 'last' => $at12 + 60], $noon));
$early = (new DateTime('today 08:00', $tz))->getTimestamp();
sp_eq('8 Uhr: gestern 18:00 noch offen → fällig', true, TIX_Staff_Push::sales_due(['mode' => 'times', 'hours' => 1, 'times' => ['12:00', '18:00'], 'last' => $early - 20 * HOUR_IN_SECONDS], $early));

echo "── Gesammelt ──\n";
$e1 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SP Halloween']);
$e2 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SP Ü30']);
// Plattform: Meldungen nur für Konten eines Veranstalters (Plattform-Admins ohne Veranstalter nie)
$org = 0;
if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'SP Veranstalter']);
    update_post_meta($org, '_tix_org_user_id', $uid);
    foreach ([$e1, $e2] as $e) update_post_meta($e, '_tix_organizer_id', $org);
    (new WP_User($uid))->set_role('tix_organizer');
}
$local = function ($ts) { return wp_date('Y-m-d H:i:s', $ts); };
$o1 = TIX_Order::create_legacy(['status' => 'completed', 'total' => 24, 'date_created' => $local($now - 1800)], [['event_id' => $e1, 'qty' => 2, 'price' => 12]]);
update_user_meta($uid, TIX_Staff_Push::META_SALES, ['mode' => 'interval', 'hours' => 1, 'times' => [], 'last' => $now - 2 * HOUR_IN_SECONDS]);
$sent = [];
TIX_Staff_Push::cron_digest($now);
sp_eq('eine Meldung', 1, count($sent));
sp_eq('Inhalt: 2 Tickets, Event, 24 €', true, isset($sent[0]) && strpos($sent[0]['body'], '2 Tickets') !== false && strpos($sent[0]['body'], 'SP Halloween') !== false && strpos($sent[0]['body'], '24 €') !== false);
sp_eq('Antippen öffnet das Event', 'staff:event:' . $e1 . '@' . strtolower(preg_replace('/^www\./', '', $h)), $sent[0]['data']['action'] ?? null);
sp_eq('Merker je Konto gesetzt', $now, TIX_Staff_Push::sales($uid)['last']);
$sent = [];
TIX_Staff_Push::cron_digest($now + 300);
sp_eq('5 Min. später nichts (nicht fällig)', 0, count($sent));
update_user_meta($uid, TIX_Staff_Push::META_SALES, ['mode' => 'off', 'times' => [], 'hours' => 1, 'last' => $now - 5 * HOUR_IN_SECONDS]);
$sent = [];
TIX_Staff_Push::cron_digest($now);
sp_eq('Modus aus: nichts', 0, count($sent));

echo "── Sofort ──\n";
update_user_meta($uid, TIX_Staff_Push::META_SALES, ['mode' => 'instant', 'times' => [], 'hours' => 1, 'last' => 0]);
$o2 = TIX_Order::create_legacy(['status' => 'completed', 'total' => 30, 'date_created' => $local($now)], [['event_id' => $e2, 'qty' => 1, 'price' => 30]]);
delete_option(TIX_Staff_Push::OPT_INSTANT_Q);
wp_clear_scheduled_hook(TIX_Staff_Push::CRON_INSTANT);
TIX_Staff_Push::queue_instant($o1);
TIX_Staff_Push::queue_instant($o2);
sp_eq('Warteschlange: 2 Bestellungen, Versand geplant', [2, true], [count((array) get_option(TIX_Staff_Push::OPT_INSTANT_Q, [])), (bool) wp_next_scheduled(TIX_Staff_Push::CRON_INSTANT)]);
$sent = [];
TIX_Staff_Push::flush_instant();
sp_eq('eine zusammengefasste Meldung', 1, count($sent));
sp_eq('3 Tickets · 54 € im Titel, Top-Events im Text', true, isset($sent[0]) && strpos($sent[0]['title'], '3 Tickets') !== false && strpos($sent[0]['title'], '54 €') !== false && strpos($sent[0]['body'], 'SP Halloween: 2') !== false);
sp_eq('mehrere Events → Start', 'staff:start@' . strtolower(preg_replace('/^www\./', '', $h)), $sent[0]['data']['action'] ?? null);
sp_eq('Warteschlange leer', [], (array) get_option(TIX_Staff_Push::OPT_INSTANT_Q, []));
update_user_meta($uid, TIX_Staff_Push::META_SALES, ['mode' => 'interval', 'times' => [], 'hours' => 1, 'last' => 0]);
TIX_Staff_Push::queue_instant($o2);
$sent = [];
TIX_Staff_Push::flush_instant();
sp_eq('Konto „stündlich“ bekommt keine Sofort-Meldung', 0, count($sent));

echo "── Cron ──\n";
do_action('init');
sp_eq('Prüfung alle 5 Minuten', 'tix_5min', wp_get_schedule(TIX_Staff_Push::CRON));

// Aufräumen
wp_clear_scheduled_hook(TIX_Staff_Push::CRON_INSTANT);
foreach ([$o1, $o2] as $o) { $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $o]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $o]); }
foreach ([$e1, $e2, $org] as $p) if ($p) wp_delete_post($p, true);
if ($keep_tokens === null) delete_option(TIX_Staff_Push::OPT_TOKENS); else update_option(TIX_Staff_Push::OPT_TOKENS, $keep_tokens, false);
if ($keep_q === null) delete_option(TIX_Staff_Push::OPT_INSTANT_Q); else update_option(TIX_Staff_Push::OPT_INSTANT_Q, $keep_q, false);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($uid);
wp_set_current_user(0);
echo $GLOBALS['t_fails'] === 0 ? "\nALLES GRÜN ({$GLOBALS['t_count']} Prüfungen)\n" : "\n{$GLOBALS['t_fails']} von {$GLOBALS['t_count']} FEHLGESCHLAGEN\n";
