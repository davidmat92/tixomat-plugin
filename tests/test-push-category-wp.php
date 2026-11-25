<?php
/**
 * 1.38.917: Kategorie-Etikett in Push-Mitteilungen (APNs-Untertitel, Android-Titel, Feed category_label) und klarere Titel.
 *   wp eval-file …/test-push-category-wp.php
 * Es geht kein echter Push raus: APNs ist mit Platzhaltern „eingerichtet“, die JWT-Erzeugung scheitert vor dem Versand;
 * der Inhalt wird über die Aktion tix_apns_payload beobachtet.
 */
if (!defined('ABSPATH') || !class_exists('TIX_Notifications') || !method_exists('TIX_Notifications', 'category_of')) { echo "Tixomat ab 1.38.917 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$N = 'TIX_Notifications';

echo "── Zuordnung ──\n";
$lab = function ($it) use ($N) { return $N::category_label($N::category_of($it)); };
$eq('Support (Gast)', 'Support', $lab(['type' => 'support', 'action' => 'support:ticket:5']));
$eq('Support (Team)', 'Support', $lab(['type' => 'support_team', 'action' => 'staff:support:5']));
$eq('Ticketkauf ausdrücklich', 'Ticketkauf', $lab(['type' => 'ticket', 'category' => 'purchase']));
$eq('Ticket erhalten/umgeschrieben', 'Tickets', $lab(['type' => 'ticket']));
$eq('Erinnerung', 'Erinnerung', $lab(['type' => 'reminder']));
$eq('Tages-Alarm', 'Tages-Alarm', $lab(['type' => 'reminder', 'category' => 'day_alert']));
$eq('Broadcast', 'Nachricht vom Veranstalter', $lab(['type' => 'message', 'action' => 'event:3']));
$eq('Line-up (alter Eintrag über campaign)', 'Line-up', $lab(['type' => 'message', 'campaign' => 'reveal-3-abc123']));
$eq('Gast-Ablauf Vorverkauf', 'Vorverkauf', $lab(['type' => 'message', 'campaign' => 'flow-presale-3']));
$eq('Co-Veranstalter (staff:event)', 'Co-Veranstalter', $lab(['type' => 'message', 'action' => 'staff:event:9']));
$eq('Konto-Prüfung (organizer:approval)', 'Konto-Prüfung', $lab(['type' => 'message', 'action' => 'organizer:approval']));
$eq('Vertrag ausdrücklich', 'Vertrag', $lab(['type' => 'message', 'action' => 'organizer:approval', 'category' => 'contract']));
$eq('Domain', 'Website', $lab(['type' => 'message', 'action' => 'organizer:domain']));
$eq('Guthaben / Gutschein / Weiterverkauf', ['Guthaben', 'Gutschein', 'Weiterverkauf'], [$lab(['type' => 'wallet']), $lab(['type' => 'giftcard']), $lab(['type' => 'resale'])]);
$eq('Awareness-Team', 'Awareness', $lab(['type' => 'awareness']));
$eq('Awareness-Gast bleibt diskret (kein Etikett)', '', $lab(['type' => 'awareness_guest']));
$eq('Künstler / Abrechnung / Gewinnspiel', ['Künstler', 'Abrechnung', 'Gewinnspiel'], [$lab(['type' => 'artist']), $lab(['type' => 'settlement']), $lab(['type' => 'raffle'])]);
$eq('Veranstalter-App: Storno / Ausverkauft / Verkauf', ['Storno', 'Ausverkauft', 'Verkauf'], [$lab(['type' => 'refund']), $lab(['type' => 'sold_out']), $lab(['type' => 'sales'])]);
$eq('Unbekannter Typ → kein Etikett', '', $lab(['type' => 'xyz']));
$eq('category none → kein Etikett', '', $lab(['type' => 'support', 'category' => 'none']));
$eq('Kein Etikett nennt die Marke', 0, count(array_filter($N::CATEGORY_LABELS, function ($l) { return stripos($l, 'tixomat') !== false || stripos($l, 'evendis') !== false; })));

echo "── iPhone (APNs-Untertitel) ──\n";
$p = $N::apns_payload('Antwort auf deine Anfrage #12', 'Text', ['type' => 'support', 'action' => 'support:ticket:12']);
$eq('Untertitel Support', 'Support', $p['aps']['alert']['subtitle'] ?? null);
$eq('data trägt category/category_label', ['support', 'Support'], [$p['data']['category'] ?? null, $p['data']['category_label'] ?? null]);
$eq('Titel/Text unverändert', ['Antwort auf deine Anfrage #12', 'Text'], [$p['aps']['alert']['title'], $p['aps']['alert']['body']]);
$p = $N::apns_payload('KitchenKlub · Storno', '2 Tickets storniert', ['type' => 'refund', 'action' => 'staff:event:3']);
$eq('Etikett schon im Titel (Veranstalter-App) → kein Untertitel', false, isset($p['aps']['alert']['subtitle']));
$eq('Ohne data unverändert (kein Untertitel)', ['title' => 'T', 'body' => 'B'], $N::apns_payload('T', 'B')['aps']['alert']);
$p = $N::apns_payload('Neuigkeit zu deinem Hinweis', '', ['type' => 'awareness_guest']);
$eq('Awareness-Gast: kein Untertitel', false, isset($p['aps']['alert']['subtitle']));

echo "── Android (FCM) ──\n";
if (class_exists('TIX_FCM')) {
    $m = TIX_FCM::message('tok', 'Antwort auf deine Anfrage #12', 'Text', ['type' => 'support', 'action' => 'support:ticket:12'])['message'];
    $eq('Titel mit Etikett', 'Support · Antwort auf deine Anfrage #12', $m['notification']['title']);
    $eq('data.category_label', 'Support', $m['data']['category_label'] ?? null);
    $m = TIX_FCM::message('tok', 'KitchenKlub · Storno', 'x', ['type' => 'refund'])['message'];
    $eq('Etikett schon im Titel → Titel unverändert', 'KitchenKlub · Storno', $m['notification']['title']);
    $m = TIX_FCM::message('tok', 'Test', 'x', [])['message'];
    $eq('Ohne data unverändert', 'Test', $m['notification']['title']);
} else echo "skip  TIX_FCM fehlt\n";

echo "── Abläufe (Feed + Push) ──\n";
$keys = ['_tix_apns_key', '_tix_apns_key_id', '_tix_apns_team_id', '_tix_push_tokens'];
$bak = []; foreach ($keys as $k) $bak[$k] = get_option($k, null);
update_option('_tix_apns_key', "-----BEGIN PRIVATE KEY-----\nplatzhalter\n-----END PRIVATE KEY-----", false);
update_option('_tix_apns_key_id', 'TESTKEY123', false);
update_option('_tix_apns_team_id', 'TESTTEAM12', false);
$uid = wp_insert_user(['user_login' => 'pcat_' . wp_generate_password(6, false, false), 'user_pass' => wp_generate_password(20), 'user_email' => 'pcat' . wp_rand() . '@example.test', 'role' => 'subscriber']);
$tok = str_repeat('c', 64);
update_option('_tix_push_tokens', [$tok => ['user' => $uid, 'platform' => 'ios', 'at' => time()]], false);
$seen = [];
$obs = function ($payload) use (&$seen) { $seen[] = $payload; };
add_action('tix_apns_payload', $obs, 10, 1);

$N::add_user_item($uid, 'Guthaben gutgeschrieben', 'Dir wurden 5,00 € gutgeschrieben.', ['type' => 'wallet', 'action' => 'wallet']);
$eq('Etikett im Titel → kein Untertitel (Guthaben gutgeschrieben)', false, isset($seen[0]['aps']['alert']['subtitle']));
$seen = [];
$N::add_user_item($uid, 'Bezahlt mit Guthaben', '3,00 € bezahlt · Bar', ['type' => 'wallet', 'action' => 'wallet', 'category' => 'wallet']);
$eq('add_user_item → data.type + category', ['wallet', 'wallet'], [$seen[0]['data']['type'] ?? null, $seen[0]['data']['category'] ?? null]);
$seen = [];
$N::add_user_item($uid, '2 Tickets für Testfest', 'Bestellung 1 · Deine QR-Codes findest du im Tickets-Tab.', ['type' => 'ticket', 'action' => 'tab:tickets', 'category' => 'purchase']);
$eq('Ticketkauf → Untertitel „Ticketkauf“', 'Ticketkauf', $seen[0]['aps']['alert']['subtitle'] ?? null);

wp_set_current_user($uid);
$res = $N::rest_feed(new WP_REST_Request('GET', '/tixomat/v1/notifications'))->get_data();
$top = array_values(array_filter($res['notifications'], function ($i) { return $i['title'] === '2 Tickets für Testfest'; }))[0] ?? [];
$eq('Feed: category + category_label', ['purchase', 'Ticketkauf'], [$top['category'] ?? null, $top['category_label'] ?? null]);
// alter Eintrag ohne category (vor 917 gespeichert)
$list = get_user_meta($uid, $N::META_USER, true);
$list[] = ['id' => 'u_alt', 'type' => 'support', 'title' => 'Neue Antwort auf deine Anfrage', 'body' => '#1 · X', 'action' => 'support:ticket:1', 'created' => time() - 100];
update_user_meta($uid, $N::META_USER, $list);
$res = $N::rest_feed(new WP_REST_Request('GET', '/tixomat/v1/notifications'))->get_data();
$alt = array_values(array_filter($res['notifications'], function ($i) { return $i['id'] === 'u_alt'; }))[0] ?? [];
$eq('Feed: alter Eintrag wird abgeleitet', ['support', 'Support'], [$alt['category'] ?? null, $alt['category_label'] ?? null]);
wp_set_current_user(0);

echo "── Ticketkauf-Text ──\n";
if (method_exists($N, 'purchase_text')) {
    $e1 = wp_insert_post(['post_type' => 'event', 'post_status' => 'draft', 'post_title' => 'Testfest PC']);
    $e2 = wp_insert_post(['post_type' => 'event', 'post_status' => 'draft', 'post_title' => 'Zweites PC']);
    $item = function ($e, $q) { return new class($e, $q) { private $e, $q; function __construct($e, $q) { $this->e = $e; $this->q = $q; } function get_event_id() { return $this->e; } function get_quantity() { return $this->q; } }; };
    $order = function ($items) { return new class($items) { private $i; function __construct($i) { $this->i = $i; } function get_items() { return $this->i; } function get_order_number() { return 'KK-1042'; } }; };
    $eq('2 Tickets, ein Event', ['2 Tickets für Testfest PC', 'Bestellung KK-1042 · Deine QR-Codes findest du im Tickets-Tab.'], $N::purchase_text($order([$item($e1, 2)])));
    $eq('1 Ticket', '1 Ticket für Testfest PC', $N::purchase_text($order([$item($e1, 1)]))[0]);
    $eq('Zwei Events', '4 Tickets für Testfest PC und 1 weiteres Event', $N::purchase_text($order([$item($e2, 1), $item($e1, 3)]))[0]);
    $eq('Ohne Event-Posten: alter Titel + Bestellnummer', 'Deine Tickets liegen in der App', $N::purchase_text($order([]))[0]);
    wp_delete_post($e1, true); wp_delete_post($e2, true);
}

echo "── Support-Antwort (Titel mit #Nummer) ──\n";
if (class_exists('TIX_Support')) {
    $t = wp_insert_post(['post_type' => 'tix_support_ticket', 'post_status' => 'tix_open', 'post_title' => 'PC Frage', 'post_author' => $uid]);
    $rm = new ReflectionMethod('TIX_Support', 'notify_customer_app'); $rm->setAccessible(true);
    $rm->invoke(null, $t, 'PC Frage', get_userdata($uid)->user_email, 'Hallo, das klappt so.');
    $l = (array) get_user_meta($uid, $N::META_USER, true);
    usort($l, function ($a, $b) { return intval($b['created']) <=> intval($a['created']); });
    $hit = array_values(array_filter($l, function ($i) { return ($i['type'] ?? '') === 'support'; }))[0] ?? [];
    $eq('Titel „Antwort auf deine Anfrage #<id>“', 'Antwort auf deine Anfrage #' . $t, $hit['title'] ?? null);
    $eq('Text „Betreff: Antwort“', 'PC Frage: Hallo, das klappt so.', $hit['body'] ?? null);
    wp_delete_post($t, true);
}

remove_action('tix_apns_payload', $obs, 10);
foreach ($bak as $k => $v) { if ($v === null) delete_option($k); else update_option($k, $v, false); }
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($uid);
echo "\n" . ($n - $f) . "/$n ok" . ($f ? " – $f FEHLER" : '') . "\n";
