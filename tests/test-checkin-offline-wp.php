<?php
/** Integrationstest Offline-Einlass (1.38.583). wp eval-file …/tests/test-checkin-offline-wp.php */
if (!defined('ABSPATH') || !class_exists('TIX_Checkin_Offline')) { echo "TIX_Checkin_Offline nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function co_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function co_rest($m, $r, array $b = [], array $hd = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); foreach ($hd as $k => $v) $q->set_header($k, $v); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
$sfx = wp_rand(1000, 9999); $users = []; $posts = [];
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$door = wp_insert_user(['user_login' => "co_door_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "co_door_$sfx@example.test", 'role' => $multi ? 'tix_organizer' : 'tix_entrance']); $users[] = $door;
$guest = wp_insert_user(['user_login' => "co_g_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "co_g_$sfx@example.test", 'role' => 'subscriber']); $users[] = $guest;
$org = 0;
if ($multi) { $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'CO Veranstalter', 'meta_input' => ['_tix_org_user_id' => $door]]); $posts[] = $org; }
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'CO Party', 'meta_input' => ['_tix_syndicate' => '0', '_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d'),
  '_tix_ticket_categories' => [['name' => 'Normal', 'price' => 10, 'qty' => 10, 'stock' => 7]],
  '_tix_guest_list' => [['id' => 'Ab12CD34-ef56-7890-aaaa-bbbbccccdddd', 'name' => 'Gast Plus', 'plus' => 1]]]]); $posts[] = $ev;
$mk = function ($code, $st = 'valid') use ($ev, &$posts) { $id = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => $code, 'meta_input' => ['_tix_ticket_event_id' => $ev, '_tix_ticket_code' => $code, '_tix_ticket_status' => $st, '_tix_ticket_owner_name' => 'Gast ' . substr($code, 0, 1), '_tix_ticket_cat_index' => 0]]); $posts[] = $id; return $id; };
$t1 = $mk('AAAA11112222'); $t2 = $mk('BBBB11112222'); $t3 = $mk('CCCC11112222', 'cancelled');

wp_set_current_user($guest);
[$st] = co_rest('GET', "/checkin/$ev/offline");
co_eq('Gast: kein Zugriff', 403, $st);
wp_set_current_user($door);
[$st, $L] = co_rest('GET', "/checkin/$ev/offline");
co_eq('Einlass: Liste 200', 200, $st);
co_eq('3 Tickets', 3, count($L['tickets'] ?? []));
co_eq('Code nur als Hash', TIX_Checkin_Offline::hash($ev, 'aaaa11112222'), $L['tickets'][array_search($t1, array_column($L['tickets'], 'id'))]['h'] ?? null);
co_eq('kein Klartext-Code', false, strpos(wp_json_encode($L), 'AAAA11112222') !== false);
co_eq('storniert erkannt', 'cancelled', $L['tickets'][array_search($t3, array_column($L['tickets'], 'id'))]['st'] ?? null);
co_eq('Gast mit +1', 2, $L['guests'][0]['total'] ?? null);
co_eq('Ablauf in der Zukunft', true, ($L['expires_at'] ?? 0) > time());
update_post_meta($ev, '_tix_checkin_password', 'geheim');
[$st] = co_rest('GET', "/checkin/$ev/offline");
co_eq('Passwort nötig', 401, $st);
[$st] = co_rest('GET', "/checkin/$ev/offline", [], ['X-Checkin-Password' => 'geheim']);
co_eq('mit Passwort 200', 200, $st);
$pw = ['X-Checkin-Password' => 'geheim'];
$base = ['event_id' => $ev, 'list_id' => $L['list_id'], 'generated_at' => $L['generated_at'], 'sig' => $L['sig']];

[$st, $d] = co_rest('POST', '/checkin/batch', $base + ['device' => 'A', 'items' => [['type' => 'ticket', 'id' => $t1, 'at' => '2026-10-30T22:00:00'], ['type' => 'ticket', 'id' => $t3], ['type' => 'guest', 'id' => 'Ab12CD34-ef56-7890-aaaa-bbbbccccdddd', 'at' => 'x1']]], $pw);
co_eq('Abgleich 200', 200, $st);
co_eq('Ticket eingelassen', 'ok', $d['results'][0]['status'] ?? null);
co_eq('storniertes Ticket', 'cancelled', $d['results'][1]['status'] ?? null);
co_eq('Gast 1 von 2', 1, $d['results'][2]['count'] ?? null);
co_eq('Server: eingecheckt', true, (bool) get_post_meta($t1, '_tix_ticket_checked_in', true));
[$st, $d] = co_rest('POST', '/checkin/batch', $base + ['device' => 'A', 'items' => [['type' => 'ticket', 'id' => $t1], ['type' => 'guest', 'id' => 'Ab12CD34-ef56-7890-aaaa-bbbbccccdddd', 'at' => 'x1']]], $pw);
co_eq('gleiches Gerät erneut: ok', 'ok', $d['results'][0]['status'] ?? null);
co_eq('Gast-Wiederholung zählt nicht doppelt', 1, $d['results'][1]['count'] ?? null);
[$st, $d] = co_rest('POST', '/checkin/batch', $base + ['device' => 'B', 'items' => [['type' => 'ticket', 'id' => $t1], ['type' => 'ticket', 'id' => $t2], ['type' => 'guest', 'id' => 'Ab12CD34-ef56-7890-aaaa-bbbbccccdddd', 'at' => 'y1', 'n' => 2]]], $pw);
co_eq('anderes Gerät: Konflikt', 'conflict', $d['results'][0]['status'] ?? null);
co_eq('Konflikt nennt Gerät A', 'offline:A', $d['results'][0]['by'] ?? null);
co_eq('zweites Ticket ok', 'ok', $d['results'][1]['status'] ?? null);
co_eq('Gast über Anzahl: Konflikt', 'conflict', $d['results'][2]['status'] ?? null);
[$st, , $c] = co_rest('POST', '/checkin/batch', ['sig' => 'falsch'] + $base + ['device' => 'A', 'items' => []], $pw);
co_eq('falsche Signatur', 'tix_offline_list', $c);
[$st, , $c] = co_rest('POST', '/checkin/batch', array_merge($base, ['sig' => 'x']) + ['device' => 'A', 'items' => []], $pw);
co_eq('manipulierte Liste', 'tix_offline_list', $c);
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($users as $u) wp_delete_user($u);
echo "\n" . ($GLOBALS['t_fails'] ? $GLOBALS['t_fails'] . ' FEHLER' : 'ALLES GRÜN') . " ({$GLOBALS['t_count']} Prüfungen)\n";
