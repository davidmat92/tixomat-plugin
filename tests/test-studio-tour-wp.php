<?php
/** Integrationstest geführte Tour – /studio/tour (Gesehen-Stand je Konto). wp eval-file …/tests/test-studio-tour-wp.php
 *  Läuft auf Einzel-Seite und Plattform gleich (kein Veranstalter-Bezug). Legt zwei Testkonten an und räumt sie wieder auf. */
if (!defined('ABSPATH')) { echo "WordPress nötig\n"; return; }
if (!class_exists('TIX_Studio_Tour')) { require_once dirname(__DIR__) . '/includes/class-tix-studio-tour.php'; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function tt_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function tt_rest($m, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1/studio/tour'); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '', $res->get_headers()]; }
$sfx = wp_rand(1000, 9999);
$a = wp_insert_user(['user_login' => "tt_a_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "tt_a_$sfx@example.test", 'role' => 'subscriber']);
$b = wp_insert_user(['user_login' => "tt_b_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "tt_b_$sfx@example.test", 'role' => 'subscriber']);

wp_set_current_user(0);
[$st, , $code] = tt_rest('GET');
tt_eq('Ohne Anmeldung → 401', [401, 'rest_not_logged_in'], [$st, $code]);

wp_set_current_user($a);
[$st, $d, , $hd] = tt_rest('GET');
tt_eq('Erster Abruf: leer, Willkommen „new“, since = heute', [200, [], [], [], 'new', 0, current_time('Y-m-d'), ''], [$st, $d['seen'], $d['offered'], $d['done'], $d['welcome']['state'], $d['welcome']['step'], $d['since'], $d['hidden_new']]);
tt_eq('Nicht zwischenspeichern', 'no-store', $hd['Cache-Control'] ?? '');
tt_eq('since ist gespeichert', current_time('Y-m-d'), json_decode((string) get_user_meta($a, TIX_Studio_Tour::META, true), true)['since'] ?? '');

[$st, $d] = tt_rest('POST', ['seen' => ['links-qr', 'Links-Anlegen', 'böse id', '', str_repeat('x', 80)], 'offered' => ['statistik'], 'welcome' => ['state' => 'running', 'step' => 6]]);
tt_eq('Speichern: IDs klein, ungültige raus, Willkommen läuft bei 6', [200, ['links-qr', 'links-anlegen'], ['statistik'], 'running', 6], [$st, $d['seen'], $d['offered'], $d['welcome']['state'], $d['welcome']['step']]);
[$st, $d] = tt_rest('POST', ['seen' => ['links-qr', 'newsletter-neu'], 'done' => ['links']]);
tt_eq('Ergänzen statt ersetzen, doppelte nur einmal; Willkommen bleibt', [['links-qr', 'links-anlegen', 'newsletter-neu'], ['links'], 'running', 6], [$d['seen'], $d['done'], $d['welcome']['state'], $d['welcome']['step']]);
[$st, $d, $code] = tt_rest('POST', ['welcome' => ['state' => 'kaputt']]);
tt_eq('Unbekannter Stand → 400', [400, 'tix_invalid_field'], [$st, $code]);
[$st, $d] = tt_rest('POST', ['welcome' => ['step' => 500], 'hidden_new' => '2026-11-04']);
tt_eq('Schritt begrenzt, Stand bleibt; hidden_new gesetzt', [99, 'running', '2026-11-04'], [$d['welcome']['step'], $d['welcome']['state'], $d['hidden_new']]);
[$st, $d] = tt_rest('POST', ['hidden_new' => 'gestern']);
tt_eq('hidden_new nur als Datum', '', $d['hidden_new']);
[$st, $d] = tt_rest('GET');
tt_eq('Stand bleibt über Abrufe (anderes Gerät)', [['links-qr', 'links-anlegen', 'newsletter-neu'], 'running'], [$d['seen'], $d['welcome']['state']]);

wp_set_current_user($b);
[$st, $d] = tt_rest('GET');
tt_eq('Anderes Konto sieht nichts vom ersten', [[], 'new'], [$d['seen'], $d['welcome']['state']]);

wp_set_current_user($a);
$since = tt_rest('GET')[1]['since'];
[$st, $d] = tt_rest('POST', ['reset' => true]);
tt_eq('Zurücksetzen: alles leer, since bleibt', [[], [], [], 'new', $since], [$d['seen'], $d['offered'], $d['done'], $d['welcome']['state'], $d['since']]);
[$st, $d] = tt_rest('POST', ['seen' => array_map(function ($i) { return "s$i"; }, range(1, 2100))]);
tt_eq('Höchstens 2000 je Liste, die neuesten bleiben', [2000, 's101', 's2100'], [count($d['seen']), $d['seen'][0], end($d['seen'])]);

wp_set_current_user(0);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($a); wp_delete_user($b);
echo "\n{$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
