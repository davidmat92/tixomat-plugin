<?php
/** Personalisierte Tickets und Zusatzfragen (TIX_Ticket_Fields, 1.38.647). wp eval-file …/tests/test-ticket-fields-wp.php
 *  (Einzel-Seite und Plattform). Kasse über /pos/orders (bar), App-Kasse bis zur Prüfung, Mails abgefangen. */
if (!defined('ABSPATH') || !class_exists('TIX_Ticket_Fields')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function tf_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function tf_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
TIX_Order::create_tables(); TIX_Ticket_Fields::maybe_create();
add_filter('pre_wp_mail', '__return_true');
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_ticket\\_holder\\_%'"); wp_cache_flush(); // Sperre früherer Läufe
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = []; $posts = [];
$admin = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
$owner = wp_insert_user(['user_login' => "tf_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "tf_owner_$sfx@example.test", 'role' => $multi ? 'tix_organizer' : 'administrator']); $users[] = $owner;
$other = wp_insert_user(['user_login' => "tf_other_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "tf_other_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $other;
$org = 0; $org2 = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'TF Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'TF Andere', 'meta_input' => ['_tix_org_user_id' => $other]]); $posts[] = $org2;
}
$cats = [['name' => 'Regulär', 'price' => 10, 'qty' => 100, 'stock' => 100], ['name' => 'VIP', 'price' => 0, 'qty' => 20, 'stock' => 20]];
$mkev = function ($t, $o) use (&$posts, $cats) { $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "TF $t", 'meta_input' => ['_tix_organizer_id' => $o, '_tix_date_start' => wp_date('Y-m-d', time() + 5 * DAY_IN_SECONDS), '_tix_time_start' => '20:00', '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1', '_tix_ticket_categories' => $cats]]); $posts[] = $id; return $id; };
$ev = $mkev("Gala $sfx", $org); $ev2 = $mkev("Zweites $sfx", $org);
$def_before = get_option(TIX_Ticket_Fields::OPT_DEFAULT, null);

// ═══ Einstellung: Vorgabe je Veranstalter, je Event änderbar ═══
wp_set_current_user($owner);
[$st, $r] = tf_rest('POST', '/organizer/ticket-fields-default', ['config' => ['personalize' => 'required', 'age_confirm' => 18]]);
tf_eq('Vorgabe gespeichert', [200, true, 'required'], [$st, $r['set'] ?? null, $r['config']['personalize'] ?? '']);
[$st, $r] = tf_rest('GET', "/events/$ev2/ticket-fields");
tf_eq('Event ohne eigene Einstellung übernimmt die Vorgabe', [200, true, 'required', 18], [$st, $r['inherit'] ?? null, $r['config']['personalize'] ?? '', $r['config']['age_confirm'] ?? 0]);
$cfg = ['personalize' => 'required', 'categories' => [1], 'ask_email' => true, 'age_confirm' => 16, 'transfer' => 'until_hours', 'transfer_hours' => 24, 'retention_days' => 30, 'questions' => [
    ['label' => 'T-Shirt-Größe', 'type' => 'select', 'options' => ['S', 'M', 'L'], 'required' => true, 'scope' => 'ticket', 'categories' => [1], 'at_checkin' => true],
    ['label' => 'Allergien', 'type' => 'text', 'scope' => 'ticket', 'categories' => [1]],
    ['label' => 'Wie hast du von uns erfahren?', 'type' => 'radio', 'options' => ['Instagram', 'Freunde'], 'required' => true, 'scope' => 'order'],
    ['type' => 'accessibility', 'scope' => 'ticket', 'categories' => [1], 'at_checkin' => true]]];
[$st, $r] = tf_rest('POST', "/events/$ev/ticket-fields", ['config' => $cfg]);
$qs = $r['config']['questions'] ?? [];
tf_eq('Event-Einstellung: Schlüssel q1–q4, Standardtext Barrierefreiheit, Hinweis bei Allergien (Art. 9)', [200, false, ['q1', 'q2', 'q3', 'q4'], 'Ich brauche einen barrierefreien Platz', ['q2']],
    [$st, $r['inherit'] ?? null, array_column($qs, 'key'), $qs[3]['label'] ?? '', array_column((array) ($r['warnings'] ?? []), 'key')]);
[$st, $d, $code] = tf_rest('POST', "/events/$ev/ticket-fields", ['config' => ['questions' => [['label' => 'X', 'type' => 'select', 'options' => ['nur eins']]]]]);
tf_eq('Auswahl mit einer Antwort → 400', 400, $st);
[$st, $d] = tf_rest('POST', "/events/$ev/ticket-fields", ['config' => ['questions' => array_fill(0, 11, ['label' => 'Frage', 'type' => 'text'])]]);
tf_eq('Mehr als 10 Fragen → 400', 400, $st);
$pub = TIX_Public_Events::payload($ev, true)['ticket_fields'] ?? null;
tf_eq('Öffentlich: Einstellung ohne Löschfrist und Einlass-Kennzeichen', [true, false, false], [is_array($pub), isset($pub['retention_days']), isset($pub['questions'][0]['at_checkin'])]);
if ($multi) {
    wp_set_current_user($other);
    [$st] = tf_rest('GET', "/events/$ev/ticket-fields");
    tf_eq('Anderer Veranstalter → kein Zugriff', true, in_array($st, [403, 404], true));
    [$st, $r] = tf_rest('GET', '/organizer/ticket-fields-default');
    tf_eq('Vorgabe gilt je Veranstalter (anderer hat keine)', false, $r['set'] ?? null);
    wp_set_current_user($owner);
}

// ═══ Prüfung (Kasse) ═══
$items = [['event_id' => $ev, 'cat_index' => 0, 'qty' => 1, 'price' => 10, 'meta' => []], ['event_id' => $ev, 'cat_index' => 1, 'qty' => 2, 'price' => 30, 'meta' => []]];
$vip = function ($f, $l, $size = 'M') { return ['index' => 1, 'first_name' => $f, 'last_name' => $l, 'answers' => ['q1' => $size, 'q4' => false]]; };
list($p, $e) = TIX_Ticket_Fields::validate($items, [$vip('Anna', 'A')], ['q3' => 'Instagram'], true);
tf_eq('VIP 2 Tickets, nur 1 Name → tix_holder_missing für Ticket 2', ['tix_holder_missing', 1, 1], [$e ? $e->get_error_code() : '', $e ? $e->get_error_data()['index'] : null, $e ? $e->get_error_data()['seq'] : null]);
list($p, $e) = TIX_Ticket_Fields::validate($items, [$vip('Anna', 'A'), $vip('Ben', 'B', 'XXL')], ['q3' => 'Instagram'], true);
tf_eq('Antwort nicht in der Auswahl → tix_answer_invalid', ['tix_answer_invalid', 'q1'], [$e ? $e->get_error_code() : '', $e ? $e->get_error_data()['field'] : '']);
list($p, $e) = TIX_Ticket_Fields::validate($items, [$vip('Anna', 'A'), $vip('Ben', 'B')], [], true);
tf_eq('Bestellfrage fehlt → tix_answer_invalid q3', ['tix_answer_invalid', 'q3'], [$e ? $e->get_error_code() : '', $e ? $e->get_error_data()['field'] : '']);
list($p, $e) = TIX_Ticket_Fields::validate($items, [$vip('Anna', 'A'), $vip('Ben', 'B')], ['q3' => 'Instagram'], false);
tf_eq('Altersbestätigung fehlt → tix_age_confirm', 'tix_age_confirm', $e ? $e->get_error_code() : '');
list($p, $e) = TIX_Ticket_Fields::validate($items, [$vip('Anna', 'A'), $vip('Ben', 'B')], ['q3' => 'Instagram'], true);
tf_eq('Vollständig: Regulär ohne Name (Kategorie nicht personalisiert), 2 VIP-Plätze', [null, false, 2, 16], [$e, isset($p['slots'][$ev . ':0']), count($p['slots'][$ev . ':1'] ?? []), $p['age'] ?? 0]);
list($p0, $e0) = TIX_Ticket_Fields::validate([['event_id' => $ev, 'cat_index' => 0, 'qty' => 1, 'meta' => ['gift' => 1]]], [], [], false);
$evx = $mkev("Alle $sfx", $org); update_post_meta($evx, TIX_Ticket_Fields::META, TIX_Ticket_Fields::sanitize(['personalize' => 'required', 'questions' => [['label' => 'Frage', 'type' => 'text', 'required' => true]]]));
list($px, $ex) = TIX_Ticket_Fields::validate([['event_id' => $evx, 'cat_index' => -1, 'qty' => 2, 'meta' => ['special' => 1, 'special_id' => 9]], ['event_id' => $evx, 'cat_index' => 0, 'qty' => 1, 'meta' => []]], [['index' => 0, 'first_name' => 'E', 'last_name' => 'X', 'answers' => ['q1' => 'a']]], [], true);
tf_eq('Extras (cat_index −1, special) brauchen keinen Namen, nur das Ticket', [null, 1, false], [$ex, count($px['slots'][$evx . ':0'] ?? []), isset($px['slots'][$evx . ':-1'])]);
tf_eq('Gutschein-Zeile ist kein Ticket → keine Prüfung', [null, null], [$p0, $e0]);

// App-Kasse: Prüfung greift vor dem Anlegen der Bestellung
wp_set_current_user(0);
[$st, $d, $code] = tf_rest('POST', '/customer/orders', ['event_id' => $ev, 'items' => [['index' => 1, 'qty' => 1]], 'billing' => ['first_name' => 'Kim', 'last_name' => 'Käufer', 'email' => "kim_$sfx@example.test", 'address_1' => 'Weg 1', 'postcode' => '12345', 'city' => 'Stadt', 'country' => 'DE'],
    'terms_accepted' => true, 'accept_terms' => true, 'payment_method' => 'free', 'answers' => ['q3' => 'Freunde'], 'age_confirmed' => true, 'idempotency_token' => wp_generate_password(20, false)]);
tf_eq('App-Kasse ohne Inhaber → 400 tix_holder_missing (keine Bestellung)', [400, 'tix_holder_missing'], [$st, $code]);
$app_body = ['event_id' => $ev, 'items' => [['index' => 1, 'qty' => 1]], 'billing' => ['first_name' => 'Kim', 'last_name' => 'Käufer', 'email' => "kim_$sfx@example.test", 'address_1' => 'Weg 1', 'postcode' => '12345', 'city' => 'Stadt', 'country' => 'DE'],
    'terms_accepted' => true, 'accept_terms' => true, 'payment_method' => 'free', 'answers' => ['q3' => 'Freunde'], 'age_confirmed' => true, 'idempotency_token' => wp_generate_password(20, false),
    'holders' => [['index' => 1, 'first_name' => 'Lea', 'last_name' => 'App', 'answers' => ['q1' => 'S']]]];
[$st, $ra] = tf_rest('POST', '/customer/orders', $app_body);
$aoid = intval($ra['order']['id'] ?? ($ra['order_id'] ?? 0));
$atix = $aoid ? get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $aoid]) : [];
tf_eq('App-Kasse mit Inhaber: Ticket auf „Lea App“, Antwort gespeichert', [true, 'Lea App', 'S'], [$aoid > 0, $atix ? get_post_meta($atix[0], '_tix_ticket_owner_name', true) : '', $atix ? (TIX_Ticket_Fields::ticket_answers($atix[0])['q1']['value'] ?? '') : '']);

// ═══ Studio-Kasse (POS): keine Ausnahme, dann Tickets mit Inhaber ═══
wp_set_current_user($admin);
[$st, $d, $code] = tf_rest('POST', '/pos/orders', ['event_id' => $ev, 'items' => [['index' => 1, 'qty' => 2]], 'payment' => 'cash', 'checkin' => true, 'answers' => ['q3' => 'Freunde'], 'age_confirmed' => true]);
tf_eq('Kasse ohne Namen → 400 tix_holder_missing (kein „Name später“)', [400, 'tix_holder_missing'], [$st, $code]);
[$st, $r] = tf_rest('POST', '/pos/orders', ['event_id' => $ev, 'items' => [['index' => 1, 'qty' => 2]], 'payment' => 'cash', 'checkin' => false, 'customer_email' => "kunde_$sfx@example.test",
    'holders' => [$vip('Anna', 'Alpha', 'S') + ['email' => "anna_$sfx@example.test"], ['index' => 1, 'first_name' => 'Ben', 'last_name' => 'Beta', 'answers' => ['q1' => 'L', 'q2' => 'Nüsse', 'q4' => true]]],
    'answers' => ['q3' => 'Freunde'], 'age_confirmed' => true]);
$oid = intval($r['order_id'] ?? ($r['order']['id'] ?? 0));
$tix = get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $oid, 'orderby' => 'ID', 'order' => 'ASC']);
$names = array_map(function ($t) { return get_post_meta($t, '_tix_ticket_owner_name', true); }, $tix);
tf_eq('Kasse mit Namen: Bestellung, 2 Tickets mit Inhaber in Reihenfolge', [200, true, ['Anna Alpha', 'Ben Beta']], [$st, $oid > 0, $names]);
$a2 = TIX_Ticket_Fields::ticket_answers($tix[1] ?? 0);
tf_eq('Antworten je Ticket (Ben: L, Nüsse, barrierefrei ja)', ['L', 'Nüsse', 'ja'], [$a2['q1']['value'] ?? '', $a2['q2']['value'] ?? '', $a2['q4']['value'] ?? '']);
$raw = $wpdb->get_var($wpdb->prepare("SELECT value FROM " . TIX_Ticket_Fields::table() . " WHERE ticket_id = %d AND q_key = 'q2'", $tix[1] ?? 0));
tf_eq('Antworten verschlüsselt gespeichert, Ablauf gesetzt', [false, true], [strpos((string) $raw, 'Nüsse') !== false, (bool) $wpdb->get_var($wpdb->prepare("SELECT expires_at FROM " . TIX_Ticket_Fields::table() . " WHERE ticket_id = %d LIMIT 1", $tix[1] ?? 0))]);
tf_eq('Bestellfrage und Altersbestätigung an der Bestellung', ['Freunde', 16], [TIX_Ticket_Fields::order_answers($oid)[0]['value'] ?? '', intval((get_option('_tix_order_age_' . $oid) ?: [])['age'] ?? 0)]);
tf_eq('Inhaber-E-Mail getrennt vom Käufer (Konto bleibt beim Käufer)', ["anna_$sfx@example.test", "kunde_$sfx@example.test"], [get_post_meta($tix[0] ?? 0, '_tix_ticket_holder_email', true), get_post_meta($tix[0] ?? 0, '_tix_ticket_owner_email', true)]);

// ═══ Einlass ═══
$code0 = get_post_meta($tix[1] ?? 0, '_tix_ticket_code', true);
[$st, $c] = tf_rest('POST', '/checkin/scan', ['code' => $code0, 'event_id' => $ev]);
tf_eq('Einlass: Name, Ausweis prüfen, Alter, nur Einlass-Fragen (keine Allergien)', [200, 'Ben Beta', true, 16, ['T-Shirt-Größe', 'Ich brauche einen barrierefreien Platz']],
    [$st, $c['holder']['name'] ?? '', $c['holder']['check_id'] ?? null, $c['holder']['age_confirmed'] ?? 0, array_column((array) ($c['holder']['answers'] ?? []), 'label')]);

// ═══ Teilnehmerliste, Korrektur, Kunde ═══
wp_set_current_user($owner);
[$st, $L] = tf_rest('GET', "/events/$ev/attendees");
$row = array_column((array) ($L['attendees'] ?? []), null, 'name')['Anna Alpha'] ?? [];
tf_eq('Teilnehmerliste: Anna mit Antworten, Bestellfrage, E-Mail (Inhaber)', [200, 'S', 'Freunde', "anna_$sfx@example.test"], [$st, $row['answers']['q1'] ?? '', ((array) ($row['order_answers'] ?? []))['q3'] ?? '', $row['email'] ?? '']);
[$st, $X] = tf_rest('GET', "/events/$ev/attendees", ['format' => 'csv']);
$csv = base64_decode((string) ($X['data'] ?? ''));
tf_eq('CSV mit Fragen als Spalten', [200, true, true], [$st, strpos($csv, 'T-Shirt-Größe') !== false, strpos($csv, 'Ben;Beta') !== false]);
[$st, $r] = tf_rest('POST', '/tickets/' . ($tix[0] ?? 0) . '/holder', ['first_name' => 'Anna', 'last_name' => 'Alpha-Neu', 'answers' => ['q1' => 'M']]);
tf_eq('Team korrigiert Name und eine Antwort', [200, 'Anna Alpha-Neu', 'M'], [$st, $r['holder']['name'] ?? '', $r['answers']['q1'] ?? '']);
wp_set_current_user(0);
$okey = (string) TIX_Order::get($oid)->get_order_key();
[$st] = tf_rest('POST', '/customer/tickets/' . ($tix[0] ?? 0) . '/holder', ['first_name' => 'X', 'last_name' => 'Y', 'key' => 'falsch']);
tf_eq('Kunde mit falschem Schlüssel → 404', 404, $st);
[$st, $d, $code] = tf_rest('POST', '/customer/tickets/' . ($tix[0] ?? 0) . '/holder', ['first_name' => 'Fremde', 'last_name' => 'Person', 'answers' => ['q1' => 'S'], 'key' => $okey]);
tf_eq('Kunde schreibt um (anderer Name) – erlaubt bis 24 h vorher (Event in 5 Tagen)', 200, $st);
update_post_meta($ev, '_tix_date_start', wp_date('Y-m-d', time() + 3600)); update_post_meta($ev, '_tix_time_start', wp_date('H:i', time() + 3600));
[$st, $d, $code] = tf_rest('POST', '/customer/tickets/' . ($tix[0] ?? 0) . '/holder', ['first_name' => 'Noch', 'last_name' => 'Jemand', 'answers' => ['q1' => 'S'], 'key' => $okey]);
tf_eq('1 h vor Beginn → anderer Name gesperrt (tix_transfer)', [403, 'tix_transfer'], [$st, $code]);
tf_eq('Umschreiben gesperrt (can_transfer)', false, class_exists('TIX_Ticket_Transfer') ? TIX_Ticket_Transfer::can_transfer($tix[0] ?? 0) : false);
[$st, $d, $code] = tf_rest('POST', '/customer/tickets/' . ($tix[1] ?? 0) . '/holder', ['first_name' => 'Ben', 'last_name' => 'Beta', 'key' => $okey]);
tf_eq('Eingechecktes Ticket nicht änderbar → 409', [409, 'tix_holder_locked'], [$st, $code]);
[$st, $d, $code] = tf_rest('POST', '/customer/tickets/' . ($tix[0] ?? 0) . '/holder', ['first_name' => 'Fremde', 'last_name' => 'Person', 'key' => $okey]);
tf_eq('Gleicher Name ohne Antworten: gespeicherte Pflichtantwort bleibt (q1 = S)', [200, 'S'], [$st, $d['answers']['q1'] ?? '']);
$ch = TIX_Ticket_Fields::customer_holder($tix[0] ?? 0);
tf_eq('Meine Tickets: cat_index, Fragen des Tickets (ohne Einlass-Kennzeichen), Antworten', [1, ['q1', 'q2', 'q4'], false, 'S'], [$ch['cat_index'] ?? null, array_column((array) ($ch['questions'] ?? []), 'key'), isset($ch['questions'][0]['at_checkin']), ((array) ($ch['answers'] ?? []))['q1'] ?? '']);
tf_eq('Meine Tickets: Inhaber, Pflicht, nicht fehlend', ['Fremde Person', 'required', false], [$ch['name'] ?? '', $ch['mode'] ?? '', $ch['missing'] ?? null]);

// ═══ Umschreiben, Löschfrist, Konto-Löschung ═══
do_action('tix_ticket_transferred', $tix[0] ?? 0, "neu_$sfx@example.test", 'Neu Person');
tf_eq('Nach dem Umschreiben: alte Antworten weg', [], TIX_Ticket_Fields::ticket_answers($tix[0] ?? 0));
$wpdb->query($wpdb->prepare("UPDATE " . TIX_Ticket_Fields::table() . " SET expires_at = %s WHERE ticket_id = %d", gmdate('Y-m-d H:i:s', time() - 60), $tix[1] ?? 0));
TIX_Ticket_Fields::daily();
tf_eq('Cron: abgelaufene Antworten gelöscht, Bestellfrage (noch nicht abgelaufen) bleibt', [[], 1], [TIX_Ticket_Fields::ticket_answers($tix[1] ?? 0), count(TIX_Ticket_Fields::order_answers($oid))]);
do_action('tix_account_deleted', 0, "kunde_$sfx@example.test", []);
tf_eq('Konto-Löschung: Antworten der Bestellungen sofort gelöscht', 0, count(TIX_Ticket_Fields::order_answers($oid)));

// Aufräumen
wp_set_current_user($admin);
if (!empty($aoid)) { foreach ($atix as $t) { $wpdb->delete(TIX_Ticket_Fields::table(), ['ticket_id' => $t]); wp_delete_post($t, true); } $wpdb->delete(TIX_Order::table_name(), ['id' => $aoid]); $wpdb->delete(TIX_Order::items_table_name(), ['order_id' => $aoid]); $wpdb->delete(TIX_Ticket_Fields::table(), ['order_id' => $aoid]); foreach (['_tix_order_holders_', '_tix_order_age_', '_tix_order_source_'] as $k) delete_option($k . $aoid); }
foreach ($tix as $t) { $wpdb->delete(TIX_Ticket_Fields::table(), ['ticket_id' => $t]); wp_delete_post($t, true); }
if ($oid) { $wpdb->delete(TIX_Order::table_name(), ['id' => $oid]); $wpdb->delete(TIX_Order::items_table_name(), ['order_id' => $oid]); $wpdb->delete(TIX_Ticket_Fields::table(), ['order_id' => $oid]);
    foreach (['_tix_order_holders_', '_tix_order_age_', '_tix_order_source_', '_tix_pos_order_'] as $k) delete_option($k . $oid); }
if ($def_before === null) delete_option(TIX_Ticket_Fields::OPT_DEFAULT); else update_option(TIX_Ticket_Fields::OPT_DEFAULT, $def_before);
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
