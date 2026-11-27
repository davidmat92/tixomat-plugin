<?php
/**
 * 1.38.923: Kunden-Antwort → Team-Push + Mail, ein Feed-Eintrag je Anfrage (rückt nach oben), Team-Mail mit Studio-Link,
 * keine Doppel-Mails, „Anfragen“ nach letzter Aktivität.
 *   wp eval-file …/test-support-reply-notify-wp.php
 * Kein echter Versand: pre_wp_mail fängt alles ab, Push ohne Geräte.
 */
if (!defined('ABSPATH') || !class_exists('TIX_Support_Model') || !method_exists('TIX_Support_Model', 'on_customer_reply')) { echo "Tixomat ab 1.38.923 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
global $wpdb;
$M = 'TIX_Support_Model';
$sfx = strtolower(wp_generate_password(5, false, false));
$mails = [];
$rec = function ($atts) use (&$mails) { $mails[] = ['to' => strtolower(is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to']), 'subject' => (string) $atts['subject'], 'body' => (string) $atts['message']]; return $atts; };
add_filter('wp_mail', $rec, -9999);
$catch = function () { return true; };
add_filter('pre_wp_mail', $catch, 1, 2);
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();

// Umgebung: eigenes Team-Mitglied als einziger Empfänger, Studio-Adresse, Modus „self“ (Einzel-Seite)
$bak = ['notify' => get_option('tix_support_notify', null), 'studio' => get_option('tix_studio_url', null), 'mode' => get_option('tix_support_mode', null), 'admin' => get_option('admin_email')];
$staff = wp_insert_user(['user_login' => "srn_$sfx", 'user_pass' => wp_generate_password(20), 'user_email' => "srn-team-$sfx@example.test", 'role' => 'administrator']);
update_option('tix_support_notify', [$staff]);
update_option('tix_studio_url', "https://studio-$sfx.example.test");
if (!$multi) update_option('tix_support_mode', 'self');
update_option('admin_email', "srn-admin-$sfx@example.test");
$tickets = [];
$feed = function () use ($staff) {
    return array_values(array_filter((array) get_user_meta($staff, TIX_Notifications::META_USER, true), function ($it) { return strpos((string) ($it['action'] ?? ''), 'staff:support:') === 0; }));
};
$to = function ($who) use (&$mails, $sfx) { return array_values(array_filter($mails, function ($m) use ($who, $sfx) { return $m['to'] === "srn-$who-$sfx@example.test"; })); };
$run_new = function () use ($M) { $M::process_new(); };
// Doppel-Sperre gilt je Aufruf; der Test simuliert mehrere Aufrufe
$next_request = function () { $rp = new ReflectionProperty('TIX_Support_Model', 'mailed'); $rp->setAccessible(true); $rp->setValue(null, []); };

echo "── Neue Anfrage ──\n";
$mails = [];
$a = TIX_Support::app_create(['email' => "srn-kunde-a-$sfx@example.test", 'name' => 'Kunde A', 'subject' => "SRN A $sfx", 'category' => 'other', 'content' => 'Hallo A']);
$tickets[] = $a;
$run_new();
$fa = $feed();
$eq('Feed: ein Eintrag „Neue Support-Anfrage“', [1, 'Neue Support-Anfrage', 'staff:support:' . $a], [count($fa), $fa[0]['title'] ?? null, $fa[0]['action'] ?? null]);
$eq('Mail an Admin und Team je genau einmal', [1, 1], [count($to('admin')), count($to('team'))]);
$eq('Mail mit Studio-Link /anfragen/<id>/', true, strpos($to('team')[0]['body'] ?? '', "https://studio-$sfx.example.test/anfragen/$a/") !== false);
$eq('Mail ohne „Tixomat“', false, stripos(implode(' ', array_column($mails, 'body')) . implode(' ', array_column($mails, 'subject')), 'tixomat') !== false);
sleep(1);
$b = TIX_Support::app_create(['email' => "srn-kunde-b-$sfx@example.test", 'name' => 'Kunde B', 'subject' => "SRN B $sfx", 'category' => 'other', 'content' => 'Hallo B']);
$tickets[] = $b;
$run_new();
$eq('Feed: B oben, A darunter', ['staff:support:' . $b, 'staff:support:' . $a], array_slice(array_column($feed(), 'action'), 0, 2));
$old_id_a = $fa[0]['id'];

echo "── Kunden-Antwort (App) ──\n";
sleep(1);
$mails = []; $next_request();
$r = TIX_Support::app_reply($a, "srn-kunde-a-$sfx@example.test", 'Noch eine Frage zu A');
$eq('app_reply ok', false, is_wp_error($r));
$fe = $feed();
$cnt_a = count(array_filter($fe, function ($it) use ($a) { return $it['action'] === 'staff:support:' . $a; }));
$eq('Feed: A wieder oben, nur ein Eintrag für A, neue id (ungelesen), Titel „Neue Antwort“', ['staff:support:' . $a, 1, true, 'Neue Antwort'], [$fe[0]['action'], $cnt_a, $fe[0]['id'] !== $old_id_a, $fe[0]['title']]);
$eq('Text „#id · Betreff“', '#' . $a . ' · SRN A ' . $sfx, $fe[0]['body']);
$eq('Antwort-Mail an Admin + Team je einmal', [1, 1], [count($to('admin')), count($to('team'))]);
$tm = $to('team')[0] ?? ['subject' => '', 'body' => ''];
$eq('Betreff „Neue Antwort #id: …“, Inhalt + Studio-Link', [true, true, true], [strpos($tm['subject'], "Neue Antwort #$a:") === 0, strpos($tm['body'], 'Noch eine Frage zu A') !== false, strpos($tm['body'], "/anfragen/$a/") !== false]);

echo "── Antwort des Teams löst nichts aus ──\n";
$mails = [];
$before = $feed();
wp_set_current_user($staff);
TIX_Support::staff_reply($a, 'Antwort vom Team', ['deliver' => false]);
wp_set_current_user(0);
$eq('keine Team-Mail, Feed unverändert', [0, 0, array_column($before, 'id')], [count($to('admin')), count($to('team')), array_column($feed(), 'id')]);

echo "── Kunden-Antwort per Mail und Partner-Brücke ──\n";
$mails = [];
TIX_Support::add_email_message($b, ['id' => 'n' . $sfx, 'type' => 'customer', 'author' => 'Kunde B', 'email' => "srn-kunde-b-$sfx@example.test", 'content' => 'Import', 'date' => current_time('c')], ['notify' => false]);
$eq('Mail-Import ohne notify: nichts', 0, count($mails));
sleep(1);
$mails = []; $next_request();
TIX_Support::add_email_message($b, ['id' => 'm' . $sfx, 'type' => 'customer', 'author' => 'Kunde B', 'email' => "srn-kunde-b-$sfx@example.test", 'content' => 'Antwort per Mail', 'date' => current_time('c')], ['notify' => true]);
$eq('Mail-Antwort: B oben, Team-Mail', ['staff:support:' . $b, 1], [$feed()[0]['action'], count($to('team'))]);
sleep(1);
$mails = []; $next_request();
TIX_Support::add_customer_message($a, 'Über die Brücke', 'Kunde A', "srn-kunde-a-$sfx@example.test");
$eq('Brücke: A oben, Team-Mail + Admin', ['staff:support:' . $a, 1, 1], [$feed()[0]['action'], count($to('team')), count($to('admin'))]);

echo "── Anfragen nach letzter Aktivität ──\n";
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? $staff;
wp_set_current_user($staff);
$res = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/support/inbox'));
$ids = array_map('intval', array_column((array) ($res->get_data()['tickets'] ?? []), 'id'));
$pa = array_search($a, $ids, true); $pb = array_search($b, $ids, true);
$eq('A (letzte Kunden-Antwort) vor B, obwohl B neuer angelegt', true, $pa !== false && $pb !== false && $pa < $pb);
sleep(1);
TIX_Support::app_reply($b, "srn-kunde-b-$sfx@example.test", 'B meldet sich');
$res = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/support/inbox'));
$ids = array_map('intval', array_column((array) ($res->get_data()['tickets'] ?? []), 'id'));
$eq('nach Antwort von B: B vor A', true, array_search($b, $ids, true) < array_search($a, $ids, true));
wp_set_current_user(0);
wp_set_current_user($staff);
$res = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/notifications'));
$cr = array_column((array) ($res->get_data()['notifications'] ?? []), 'created');
$sorted = $cr; rsort($sorted);
$eq('… Feed-Antwort absteigend nach created', $sorted, $cr);
wp_set_current_user(0);

echo "── Awareness: kein Team-Hinweis ──\n";
$mails = [];
$aw = TIX_Support::app_create(['email' => "srn-kunde-c-$sfx@example.test", 'name' => 'C', 'subject' => "SRN C $sfx", 'category' => 'other', 'content' => 'x']);
$tickets[] = $aw;
update_post_meta($aw, '_tix_sp_category', 'awareness');
$run_new();
$mails = []; $next_request();
TIX_Support::app_reply($aw, "srn-kunde-c-$sfx@example.test", 'y');
$eq('Awareness-Antwort: kein Feed-Eintrag, keine Team-Mail', [false, 0], [in_array('staff:support:' . $aw, array_column($feed(), 'action'), true), count($to('team'))]);

echo "── Migration Datum ──\n";
$old = gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS);
$wpdb->update($wpdb->posts, ['post_modified_gmt' => $old, 'post_modified' => $old], ['ID' => $a]); clean_post_cache($a);
update_post_meta($a, '_tix_sp_last_reply', wp_date('c', time() - 3600));
delete_option('tix_sp_activity_migrated');
$n_m = $M::migrate_activity();
$eq('migrate_activity zieht das Datum auf die letzte Nachricht vor', true, $n_m >= 1 && abs(strtotime(get_post($a)->post_modified_gmt . ' UTC') - (time() - 3600)) < 5);

// Aufräumen
remove_filter('pre_wp_mail', $catch, 1); remove_filter('wp_mail', $rec, -9999);
foreach ($bak as $k => $v) {
    $opt = ['notify' => 'tix_support_notify', 'studio' => 'tix_studio_url', 'mode' => 'tix_support_mode', 'admin' => 'admin_email'][$k];
    if ($v === null) delete_option($opt); else update_option($opt, $v);
}
foreach ($tickets as $t) wp_delete_post($t, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($staff);
echo "\n" . ($n - $f) . "/$n ok" . ($f ? " – $f FEHLER" : '') . "\n";
