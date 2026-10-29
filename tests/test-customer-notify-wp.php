<?php
/** Integrationstest Kunden-Mitteilungen (1.38.582) + Team-Aktionen. wp eval-file …/tests/test-customer-notify-wp.php */
if (!defined('ABSPATH') || !class_exists('TIX_Customer_Notify')) { echo "TIX_Customer_Notify nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function cn_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
$items = function ($uid) { $l = get_user_meta($uid, TIX_Notifications::META_USER, true); return is_array($l) ? $l : []; };
$find = function ($uid, $title) use ($items) { foreach ($items($uid) as $it) if (($it['title'] ?? '') === $title) return $it; return null; };
$last = function ($uid) use ($items) { $l = $items($uid); return $l ? $l[0] : null; };
$sfx = wp_rand(1000, 9999); $users = []; $posts = [];
$mk = function ($name, $confirmed = true) use (&$users, $sfx) {
    $id = wp_insert_user(['user_login' => "cn_{$name}_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "cn_{$name}_$sfx@example.test", 'role' => 'subscriber']);
    if ($confirmed) update_user_meta($id, TIX_App_Account::META_EMAIL_CONFIRMED, "cn_{$name}_$sfx@example.test");
    $users[] = $id; return $id;
};
$kunde = $mk('kunde'); $neu = $mk('neu'); $fremd = $mk('fremd', false);
$mail = function ($uid) { return get_userdata($uid)->user_email; };

echo "── Anfrage erledigt ──\n";
$t = wp_insert_post(['post_type' => 'tix_support_ticket', 'post_status' => 'tix_open', 'post_title' => 'CN Frage']); $posts[] = $t;
update_post_meta($t, '_tix_sp_email', $mail($kunde));
TIX_Support::set_status($t, 'tix_resolved', false);
$i = $last($kunde);
cn_eq('Eintrag „erledigt“', 'Deine Anfrage ist erledigt', $i['title'] ?? null);
cn_eq('öffnet die Anfrage', 'support:ticket:' . $t, $i['action'] ?? null);
TIX_Support::set_status($t, 'tix_closed', false);
cn_eq('erledigt → geschlossen: kein zweiter Eintrag', 1, count($items($kunde)));
$t2 = wp_insert_post(['post_type' => 'tix_support_ticket', 'post_status' => 'tix_open', 'post_title' => 'CN Fremd']); $posts[] = $t2;
update_post_meta($t2, '_tix_sp_email', $mail($fremd));
TIX_Support::set_status($t2, 'tix_resolved', false);
cn_eq('unbestätigte E-Mail: kein Eintrag', 0, count($items($fremd)));

echo "── Ticket umgeschrieben ──\n";
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'CN Party', 'meta_input' => ['_tix_syndicate' => '0']]); $posts[] = $ev;
$tk = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'CNTK', 'meta_input' => ['_tix_ticket_event_id' => $ev, '_tix_ticket_owner_email' => $mail($kunde), '_tix_ticket_status' => 'valid']]); $posts[] = $tk;
TIX_Customer_Notify::remember_owner(null, $tk, '_tix_ticket_owner_email', $mail($neu));
update_post_meta($tk, '_tix_ticket_owner_email', $mail($neu));
do_action('tix_ticket_transferred', $tk, $mail($neu), 'Neu Person');
TIX_Customer_Notify::flush_transfers();
cn_eq('neuer Inhaber: „erhalten“', 'Du hast ein Ticket erhalten', $last($neu)['title'] ?? null);
cn_eq('… öffnet die Tickets', 'tab:tickets', $last($neu)['action'] ?? null);
cn_eq('bisheriger Inhaber: „umgeschrieben“', true, $find($kunde, 'Ticket umgeschrieben') !== null);
cn_eq('… nennt den neuen Namen', true, strpos($find($kunde, 'Ticket umgeschrieben')['body'] ?? '', 'Neu Person') !== false);

echo "── Gewinnspiel ──\n";
do_action('tix_raffle_won', $ev, ['email' => $mail($neu), 'prize_name' => '2 Freikarten', 'ticket_ids' => [123]]);
cn_eq('Gewinn-Eintrag', true, $find($neu, 'Du hast gewonnen!') !== null);
cn_eq('… zu den Tickets', 'tab:tickets', $find($neu, 'Du hast gewonnen!')['action'] ?? null);
do_action('tix_raffle_won', $ev, ['email' => $mail($kunde), 'prize_name' => 'Getränk']);
cn_eq('ohne Freikarte: zum Event', 'event:' . $ev, $find($kunde, 'Du hast gewonnen!')['action'] ?? null);

echo "── Team-Aktionen ──\n";
$src = file_get_contents(TIXOMAT_PATH . 'includes/class-tix-support-model.php');
cn_eq('Support-Team-Einträge ohne support:inbox', false, strpos($src, "'support:inbox:'") !== false);
cn_eq('Support-Team-Einträge mit staff:support', 3, substr_count($src, "'staff:support:'"));

foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($users as $u) wp_delete_user($u);
echo "\n" . ($GLOBALS['t_fails'] ? $GLOBALS['t_fails'] . ' FEHLER' : 'ALLES GRÜN') . " ({$GLOBALS['t_count']} Prüfungen)\n";
