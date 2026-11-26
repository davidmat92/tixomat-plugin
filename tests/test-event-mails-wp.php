<?php
/**
 * 1.38.919: Erinnerung/Nachbefragung per Sammel-Cron statt Einzel-Event je Bestellung (TIX_Event_Mails) + Migration.
 *   wp eval-file …/test-event-mails-wp.php
 * Kein echter Versand: pre_wp_mail fängt alle Mails ab.
 */
if (!defined('ABSPATH') || !class_exists('TIX_Event_Mails')) { echo "Tixomat ab 1.38.919 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
global $wpdb;
$M = 'TIX_Event_Mails';
$sfx = wp_generate_password(5, false, false);
$mails = [];
// Aufzeichnen vor allen anderen wp_mail-Filtern (Demo-Shop verwirft Testadressen), dann nicht senden
$rec = function ($atts) use (&$mails) { $mails[] = ['to' => is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to'], 'subject' => (string) $atts['subject']]; return $atts; };
add_filter('wp_mail', $rec, -9999);
$catch = function () { return true; };
add_filter('pre_wp_mail', $catch, 1, 2);
$cron_size = function () { return strlen(serialize(get_option('cron', []))); };
$count_hooks = function () use ($M) { $c = 0; foreach ((array) _get_cron_array() as $h) foreach ($M::HOOKS as $k) $c += count((array) ($h[$k] ?? [])); return $c; };

// Einstellungen: Erinnerung + Nachbefragung an
$old_s = get_option('tix_settings', null);
$s = is_array($old_s) ? $old_s : [];
$s['email_reminder'] = 1; $s['email_followup'] = 1;
update_option('tix_settings', $s);

$now = time(); $M::$now = $now;
$posts = []; $orders = [];
$mkev = function ($start_ts, $title) use (&$posts) {
    $e = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => $title]);
    update_post_meta($e, '_tix_date_start', gmdate('Y-m-d', $start_ts));
    update_post_meta($e, '_tix_time_start', gmdate('H:i', $start_ts));
    $posts[] = $e; return $e;
};
$T = TIX_Order::table_name(); $I = TIX_Order::items_table_name();
$mko = function ($eid, $created_ts, $status, $tag) use ($wpdb, $T, $I, &$orders, $sfx) {
    $wpdb->insert($T, ['order_number' => "EM-$sfx-" . count($orders), 'status' => $status, 'total' => 10, 'payment_method' => 'mollie',
        'billing_email' => "em-$tag-$sfx@example.test", 'billing_first_name' => 'Test', 'order_key' => 'k' . wp_generate_password(16, false, false),
        'date_created' => gmdate('Y-m-d H:i:s', $created_ts), 'date_modified' => gmdate('Y-m-d H:i:s', $created_ts)]);
    $oid = intval($wpdb->insert_id);
    $wpdb->insert($I, ['order_id' => $oid, 'event_id' => $eid, 'quantity' => 1, 'total' => 10, 'name' => 'Ticket']);
    $orders[] = $oid; return $oid;
};

$e1 = $mkev($now + 10 * HOUR_IN_SECONDS, "EM Morgen $sfx");       // Erinnerungs-Fenster offen
$e2 = $mkev($now - 30 * HOUR_IN_SECONDS, "EM Gestern $sfx");      // Nachbefragungs-Fenster offen
$e3 = $mkev($now + 5 * DAY_IN_SECONDS, "EM Später $sfx");         // noch nichts fällig
$a  = $mko($e1, $now - 3 * DAY_IN_SECONDS, 'completed', 'a');     // offenes altes Einzel-Event → muss kommen
$a2 = $mko($e1, $now - 3 * DAY_IN_SECONDS, 'completed', 'a2');    // altes Einzel-Event schon gelaufen → nicht doppelt
$b  = $mko($e1, $now - HOUR_IN_SECONDS, 'completed', 'b');        // nach Beginn − 24 h gekauft → keine Erinnerung (wie vorher)
$c  = $mko($e1, $now - 3 * DAY_IN_SECONDS, 'cancelled', 'c');     // storniert → nichts
$d  = $mko($e2, $now - 3 * DAY_IN_SECONDS, 'completed', 'd');     // Nachbefragung
$g  = $mko($e3, $now - DAY_IN_SECONDS, 'completed', 'g');         // später

echo "── Migration ──\n";
delete_option($M::OPT_MIGRATED);
wp_schedule_single_event($now - 60, 'tix_send_reminder_email', [$a, $e1]);              // fällig, noch offen
wp_schedule_single_event($now + 4 * DAY_IN_SECONDS, 'tix_send_reminder_email', [$g, $e3]);
wp_schedule_single_event($now + 7 * DAY_IN_SECONDS, 'tix_send_followup_email', [$g, $e3]);
// Last wie auf den Live-Seiten: 2.000 Einzel-Events
for ($i = 0; $i < 1000; $i++) {
    wp_schedule_single_event($now + 9 * DAY_IN_SECONDS + $i, 'tix_send_reminder_email', [900000 + $i, 1]);
    wp_schedule_single_event($now + 11 * DAY_IN_SECONDS + $i, 'tix_send_followup_email', [900000 + $i, 1]);
}
$before = $cron_size(); $hooks_before = $count_hooks();
$r = $M::migrate();
$after = $cron_size();
echo "info  Cron-Option vorher " . round($before / 1024) . " KB ($hooks_before Einzel-Events), nachher " . round($after / 1024) . " KB\n";
$eq('Alle Einzel-Events entfernt', 0, $count_hooks());
$eq('Option deutlich kleiner (< 1/3)', true, $after < $before / 3);
$eq('Fenster ohne offenes Einzel-Event → Merker (a2, d); a bleibt offen', [true, true, false],
    [(bool) get_post_meta($a2, $M::marker_key('r', $e1), true), (bool) get_post_meta($d, $M::marker_key('f', $e2), true), (bool) get_post_meta($a, $M::marker_key('r', $e1), true)]);
// d hatte kein offenes Einzel-Event → gilt als versandt; für den Versandtest eine neue Bestellung mit offenem Fenster
delete_post_meta($d, $M::marker_key('f', $e2));

echo "── Sammel-Cron ──\n";
$mails = [];
$M::run();
$to = array_column($mails, 'to');
$has = function ($tag) use ($to, $sfx) { return in_array("em-$tag-$sfx@example.test", $to, true); };
$eq('Erinnerung an a (offenes Einzel-Event übernommen)', true, $has('a'));
$eq('Keine Erinnerung an a2 (schon versandt), b (spät gekauft), c (storniert), g (später)', [false, false, false, false], [$has('a2'), $has('b'), $has('c'), $has('g')]);
$eq('Nachbefragung an d', true, $has('d'));
$eq('Betreffe', [true, true], [(bool) array_filter($mails, function ($m) { return strpos($m['subject'], 'Erinnerung:') === 0; }), (bool) array_filter($mails, function ($m) { return strpos($m['subject'], 'Danke für deinen Besuch') === 0; })]);
$mails = [];
$M::run();
$eq('Zweiter Lauf: nichts doppelt', 0, count(array_filter($mails, function ($m) use ($sfx) { return strpos($m['to'], $sfx) !== false; })));
$mails = [];
TIX_Emails::send_reminder($a, $e1);
TIX_Emails::send_followup($d, $e2);
$eq('Alter Einzel-Event-Aufruf danach: nichts doppelt', 0, count($mails));

echo "── Später fällig ──\n";
$M::$now = $now + 4 * DAY_IN_SECONDS + 3600; // g: Beginn − 23 h
$mails = [];
$M::run();
$eq('g bekommt die Erinnerung, sobald das Fenster offen ist', true, $has('g') || in_array("em-g-$sfx@example.test", array_column($mails, 'to'), true));
$M::$now = $now;

echo "── Neue Bestellungen planen nichts mehr ──\n";
$rm = new ReflectionMethod('TIX_Emails', 'schedule_native_event_emails'); $rm->setAccessible(true);
$rm->invoke(null, TIX_Order::get($g));
$eq('schedule_native_event_emails legt kein Einzel-Event an', 0, $count_hooks());
$eq('Sammel-Cron ist geplant (alle 15 min)', true, (bool) wp_next_scheduled($M::CRON) || (do_action('init') || true) && (bool) wp_next_scheduled($M::CRON));

echo "── Schalter aus ──\n";
$s['email_reminder'] = 0; $s['email_followup'] = 0; update_option('tix_settings', $s);
$h = $mko($e1, $now - 3 * DAY_IN_SECONDS, 'completed', 'h');
$mails = [];
$M::run();
$eq('Erinnerung aus: nichts', false, in_array("em-h-$sfx@example.test", array_column($mails, 'to'), true));

// Aufräumen
remove_filter('pre_wp_mail', $catch, 1); remove_filter('wp_mail', $rec, -9999);
if ($old_s === null) delete_option('tix_settings'); else update_option('tix_settings', $old_s);
foreach ($orders as $o) { $wpdb->delete($T, ['id' => $o]); $wpdb->delete($I, ['order_id' => $o]); $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $o, '_tix_evmail_%')); }
foreach ($posts as $p) wp_delete_post($p, true);
$M::$now = null;
echo "\n" . ($n - $f) . "/$n ok" . ($f ? " – $f FEHLER" : '') . "\n";
