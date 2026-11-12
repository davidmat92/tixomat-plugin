<?php
/**
 * Event-Verlauf (1.38.776): Revision je Speichern, Diff, kein Rauschen, Bestand vorher, Routen, Wiederherstellen mit Schutz,
 * Kopien (sync, kein lokales Wiederherstellen), Person aus dem Durchgriff, Aufbewahrung, gelöschtes Konto.
 *   wp eval-file …/test-event-history-wp.php
 * Hinweis: Ein „Aufruf“ endet hier mit TIX_Event_History::flush() (im Betrieb am Ende jeder Anfrage).
 */
if (!defined('ABSPATH') || !class_exists('TIX_Event_History')) { echo "Tixomat ab 1.38.776 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); return [$res->get_status(), $res->get_data()]; };
$rows = function ($id) use ($wpdb) { return $wpdb->get_results($wpdb->prepare("SELECT rev, channel, actor_name, site, origin, areas, restored_from FROM " . TIX_Event_History::table() . " WHERE event_id = %d ORDER BY rev", $id), ARRAY_A); };
$posts = []; $users = [];
TIX_Event_History::flush();
try {
    TIX_Event_History::install();
    $adm = get_users(['role' => 'administrator', 'number' => 1])[0];
    $u = wp_insert_user(['user_login' => 'evh' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(20), 'user_email' => 'evh' . wp_rand(1000, 9999) . '@example.test', 'role' => 'administrator', 'display_name' => 'Vera Verlauf']);
    $users[] = $u;
    wp_set_current_user($u);
    $_SERVER['HTTP_X_TIX_CLIENT'] = 'studio';

    echo "== Anlegen und Speichern\n";
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Verlauf Party']); $posts[] = $ev;
    update_post_meta($ev, '_tix_date_start', '2027-06-01');
    update_post_meta($ev, '_tix_info_description', '<p>Erste Beschreibung</p>');
    update_post_meta($ev, '_tix_ticket_categories', [['name' => 'Normal', 'price' => 15, 'qty' => 100, 'stock' => 100], ['name' => 'VIP', 'price' => 40, 'qty' => 10, 'stock' => 10]]);
    TIX_Event_History::flush();
    $r = $rows($ev);
    $eq('neu angelegt: genau eine Revision „neu“ von Vera', [1, 'neu', 'Vera Verlauf'], [count($r), $r[0]['channel'] ?? null, $r[0]['actor_name'] ?? null]);

    wp_update_post(['ID' => $ev, 'post_title' => 'Verlauf Party XXL']);
    $cats = get_post_meta($ev, '_tix_ticket_categories', true); $cats[0]['price'] = 18; $cats[0]['stock'] = 97;
    update_post_meta($ev, '_tix_ticket_categories', $cats);
    update_post_meta($ev, '_tix_price_range', '18–40 €'); // abgeleitet
    update_post_meta($ev, '_tix_lineup_order', ['honk', 'monchi']);
    TIX_Event_History::flush();
    $r = $rows($ev);
    $eq('ein Speichern mit mehreren Feldern = eine Revision (Kanal hier cli, im Studio „studio“)', [2, 'cli'], [count($r), $r[1]['channel'] ?? null]);
    $eq('Bereiche: basis, tickets, lineup', 'basis,tickets,lineup', $r[1]['areas'] ?? null);
    [$st, $d] = $rest('GET', "/events/$ev/history/2");
    $labels = array_column((array) ($d['diff'] ?? []), 'label');
    $eq('Diff lesbar: Titel alt→neu, Preis Normal 15→18, Line-up', [200, true, true, true], [$st, in_array('Titel', $labels, true), (bool) array_filter((array) $d['diff'], function ($x) { return $x['label'] === 'Ticket „Normal“: Preis' && $x['old'] === '15' && $x['new'] === '18'; }), in_array('Line-up-Reihenfolge', $labels, true)]);

    $cats = get_post_meta($ev, '_tix_ticket_categories', true); $cats[0]['stock'] = 90; update_post_meta($ev, '_tix_ticket_categories', $cats);
    update_post_meta($ev, '_tix_price_card', 'ab 18 €'); update_post_meta($ev, '_tix_sold_total', 10);
    wp_update_post(['ID' => $ev]);
    TIX_Event_History::flush();
    $eq('nur Bestand/abgeleitete Felder/leeres Speichern: keine Revision', 2, count($rows($ev)));

    echo "== Bestand vor dem Verlauf\n";
    $old = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Altes Event']); $posts[] = $old;
    update_post_meta($old, '_tix_info_description', 'Alt');
    TIX_Event_History::flush();
    $wpdb->delete(TIX_Event_History::table(), ['event_id' => $old]); // wie vor 1.38.776: kein Verlauf
    update_post_meta($old, '_tix_info_description', 'Neu');
    TIX_Event_History::flush();
    $r = $rows($old);
    $eq('erste Änderung: zuerst „bestand“, dann die Änderung', ['bestand', 'cli'], array_column($r, 'channel'));
    $snap1 = json_decode($wpdb->get_var($wpdb->prepare("SELECT snapshot FROM " . TIX_Event_History::table() . " WHERE event_id = %d AND rev = 1", $old)), true);
    $eq('Bestand hält den alten Text', 'Alt', trim(wp_strip_all_tags((string) ($snap1['texte']['_tix_info_description'] ?? ''))));

    echo "== Routen\n";
    [$st, $d] = $rest('GET', "/events/$ev/history");
    $eq('Liste: neueste zuerst, Person, Seite, E-Mail für Admin', [200, 2, 'Vera Verlauf', $h, true], [$st, $d['items'][0]['rev'] ?? null, $d['items'][0]['actor']['name'] ?? null, $d['items'][0]['site'] ?? null, !empty($d['items'][0]['actor']['email'])]);
    wp_set_current_user(0);
    $eq('ohne Anmeldung 401', 401, $rest('GET', "/events/$ev/history")[0]);
    wp_set_current_user($u);

    echo "== Wiederherstellen\n";
    update_post_meta($ev, '_tix_info_description', '<p>Zweite Beschreibung</p>');
    TIX_Event_History::flush();
    [$st, $d] = $rest('POST', "/events/$ev/history/1/restore", ['areas' => ['basis']]);
    TIX_Event_History::flush();
    wp_cache_delete($ev, 'post_meta'); clean_post_cache($ev);
    $eq('nur Bereich basis: Titel zurück, Text bleibt', [200, 'Verlauf Party', '<p>Zweite Beschreibung</p>'], [$st, get_the_title($ev), get_post_meta($ev, '_tix_info_description', true)]);
    $r = $rows($ev); $last = end($r);
    $eq('neue Revision restore, restored_from 1, Person', ['restore', '1', 'Vera Verlauf'], [$last['channel'], $last['restored_from'], $last['actor_name']]);
    [, $d] = $rest('GET', "/events/$ev/history");
    $eq('Zusammenfassung', 'Stand von Version 1 wiederhergestellt (Grunddaten)', $d['items'][0]['summary'] ?? null);

    // Schutz: VIP hat verkaufte Tickets, Version 1 … Kategorie VIP entfernen und dann zurück auf einen Stand ohne VIP
    $t = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'T']); $posts[] = $t;
    update_post_meta($t, '_tix_ticket_event_id', (string) $ev); update_post_meta($t, '_tix_ticket_cat_index', '1'); update_post_meta($t, '_tix_ticket_status', 'valid');
    $snapNoVip = TIX_Event_History::snapshot($ev);
    $snapNoVip['tickets']['_tix_ticket_categories'] = [['name' => 'Normal', 'price' => 12, 'qty' => 50]];
    $plan = TIX_Event_History::restore_plan(TIX_Event_History::snapshot($ev), $snapNoVip, ['tickets'], [1 => 1], true);
    $eq('Plan: VIP (1 verkauft) geschützt', 1, count($plan['protected']));
    $wpdb->update(TIX_Event_History::table(), ['snapshot' => wp_json_encode($snapNoVip)], ['event_id' => $ev, 'rev' => 1]);
    [$st, $d] = $rest('POST', "/events/$ev/history/1/restore", ['areas' => ['tickets']]);
    TIX_Event_History::flush();
    wp_cache_delete($ev, 'post_meta');
    $c = get_post_meta($ev, '_tix_ticket_categories', true);
    $eq('Kategorien: Normal aus der Version, VIP bleibt an Stelle 2 mit Bestand qty−verkauft', [200, 'Normal', 12, 'VIP', 9, 1], [$st, $c[0]['name'] ?? null, intval($c[0]['price'] ?? 0), $c[1]['name'] ?? null, intval($c[1]['stock'] ?? -1), count($d['protected'] ?? [])]);
    $past = TIX_Event_History::restore_plan(['termin' => ['_tix_date_start' => '2027-06-01']], ['termin' => ['_tix_date_start' => '2020-01-01']], ['termin'], [0 => 3], true);
    $eq('Plan: vergangener Termin bei Verkäufen geschützt', [[], 1], [$past['set'], count($past['protected'])]);
    $eq('unbekannte Version: 404', 404, $rest('POST', "/events/$ev/history/999/restore", [])[0]);

    echo "== Kopien und Weitergabe\n";
    $cp = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Kopie']); $posts[] = $cp;
    update_post_meta($cp, '_tix_syndicated', '1'); update_post_meta($cp, '_tix_source_site', 'KitchenKlub');
    TIX_Event_History::flush();
    TIX_Event_History::incoming($cp, ['origin' => 'kitchenklub.de#0f8fad5b-d9cb-469f-a165-70867728950e', 'actor' => ['name' => 'Kim Quelle'], 'site' => 'kitchenklub.de', 'site_name' => 'KitchenKlub', 'channel' => 'studio']);
    update_post_meta($cp, '_tix_info_description', 'Von der Quelle');
    TIX_Event_History::flush();
    $r = $rows($cp); $last = end($r);
    $eq('Kopie: Revision sync mit Person, Seite und origin der Quelle', ['sync', 'Kim Quelle', 'kitchenklub.de', 'kitchenklub.de#0f8fad5b-d9cb-469f-a165-70867728950e'], [$last['channel'], $last['actor_name'], $last['site'], $last['origin']]);
    [$st, $d] = $rest('POST', "/events/$cp/history/" . intval($last['rev']) . "/restore", []);
    $eq('Kopie: lokal nicht wiederherstellbar (409, Quelle genannt)', [409, 'tix_history_copy', 'KitchenKlub'], [$st, $d['code'] ?? null, $d['data']['source_site'] ?? null]);
    [, $d] = $rest('GET', "/events/$cp/history");
    $eq('Kopie: Liste mit copy + restorable false', [true, false], [$d['source']['copy'] ?? null, $d['items'][0]['restorable'] ?? null]);
    $out = TIX_Event_History::outgoing($ev);
    $eq('Weitergabe: origin, Person, Seite', [true, 'Vera Verlauf', $h], [(bool) preg_match('/^[a-z0-9.-]+#[a-f0-9-]+$/', $out['origin']), $out['actor']['name'], $out['site']]);

    // Durchgriff: technisches Konto handelt, Person kommt aus dem Umschlag
    $tech = wp_insert_user(['user_login' => 'evhtech' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(20), 'user_email' => 'evhtech' . wp_rand(1000, 9999) . '@x.ohne-mail.invalid', 'role' => 'tix_organizer']);
    $users[] = $tech; update_user_meta($tech, '_tix_technical', '1');
    wp_set_current_user($tech);
    TIX_Event_History::context_from_actor(['name' => 'Co Carla', 'email' => 'carla@co.test', 'site' => 'https://www.mallorcaparty-schwelm.de', 'channel' => 'studio']);
    update_post_meta($ev, '_tix_info_description', '<p>Von Carla</p>');
    TIX_Event_History::flush();
    $r = $rows($ev); $last = end($r);
    $eq('Durchgriff: Person und Seite statt technischem Konto', ['Co Carla', 'mallorcaparty-schwelm.de'], [$last['actor_name'], $last['site']]);
    wp_set_current_user($u);

    echo "== Aufbewahrung, Konto gelöscht\n";
    $wpdb->query($wpdb->prepare("UPDATE " . TIX_Event_History::table() . " SET created_at = %s WHERE event_id = %d AND rev = 1", gmdate('Y-m-d H:i:s', time() - 800 * DAY_IN_SECONDS), $ev));
    TIX_Event_History::cleanup();
    $eq('Snapshot nach 2 Jahren weg, Kopf bleibt; Detail sagt „nicht mehr gespeichert“', [1, 410], [intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Event_History::table() . " WHERE event_id = %d AND rev = 1 AND snapshot IS NULL", $ev))), $rest('POST', "/events/$ev/history/1/restore", [])[0]]);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_set_current_user($adm->ID);
    wp_delete_user($u); $users = array_diff($users, [$u]);
    $eq('Konto gelöscht: „Gelöschtes Konto“, keine E-Mail', [0], [intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Event_History::table() . " WHERE actor_user_id = %d OR actor_name = 'Vera Verlauf'", $u)))]);
    wp_delete_post($ev, true); $posts = array_diff($posts, [$ev]);
    $eq('Event endgültig gelöscht → Verlauf weg', 0, intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Event_History::table() . " WHERE event_id = %d", $ev))));
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    TIX_Event_History::flush();
    unset($_SERVER['HTTP_X_TIX_CLIENT']);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($posts as $p) wp_delete_post($p, true);
    foreach ($users as $x) wp_delete_user($x);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
