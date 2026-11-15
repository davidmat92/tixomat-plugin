<?php
/**
 * Audit 15.11., zweite Runde Teil B (1.38.839): M7 Kategorie-Felder beim Speichern im WP-Admin, M12 Mailchimp (eigener Test),
 * M13 Serien (test-guest-flows), M14 Sitzplatz-Label vorgeladen, /wp/v2/users nur angemeldet, CSV-Formel-Schutz,
 * Zweitmarkt-Aufräumen gedrosselt, Rechnungsliste vorgeladen.
 *   wp eval-file …/tests/test-audit-1511-b-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !function_exists('tix_csv_cell')) { echo "Tixomat ab 1.38.839 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$posts = []; $users = [];
$adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
try {
    // ── CSV ──
    $eq('CSV: Formel bekommt Apostroph', ["'=HYPERLINK(\"x\")", "'+cmd|' /C calc'!A0", "'-1+1", "'@SUM(A1)", "'\tx", "'\rx"],
        array_map('tix_csv_cell', ['=HYPERLINK("x")', "+cmd|' /C calc'!A0", '-1+1', '@SUM(A1)', "\tx", "\rx"]));
    $eq('CSV: Zahlen, Beträge, Telefon, Text bleiben', [-5, 1.5, '-12,50 EUR', '+49 171 1234567', '-3 %', 'Max', '', 'a=b'],
        array_map('tix_csv_cell', [-5, 1.5, '-12,50 EUR', '+49 171 1234567', '-3 %', 'Max', '', 'a=b']));
    $fh = fopen('php://temp', 'w+'); tix_fputcsv($fh, ['=1+1', 'ok'], ';'); rewind($fh); $line = stream_get_contents($fh); fclose($fh);
    $eq('CSV: tix_fputcsv schreibt entschärft', "'=1+1;ok\n", $line);
    if (class_exists('TIX_Export')) $eq('CSV: TIX_Export::csv (Kunden, Gewinnspiel, Guthaben, Export)', true, strpos(TIX_Export::csv(['A'], [['=2*3']]), "'=2*3") !== false);

    // ── /wp/v2/users ──
    wp_set_current_user(0);
    $r = rest_do_request(new WP_REST_Request('GET', '/wp/v2/users'));
    $eq('users: anonym 404 rest_no_route', [404, 'rest_no_route'], [$r->get_status(), $r->get_data()['code'] ?? '']);
    $r = rest_do_request(new WP_REST_Request('GET', '/wp/v2/users/' . $adm));
    $eq('users/{id}: anonym 404', 404, $r->get_status());
    $r = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/info'));
    $eq('App-Route anonym weiter erreichbar', 200, $r->get_status());
    wp_set_current_user($adm);
    $GLOBALS['wp_rest_server'] = null; // Routen neu aufbauen (Filter hängt am Anmeldestatus)
    $r = rest_do_request(new WP_REST_Request('GET', '/wp/v2/users/me'));
    $eq('users/me: angemeldet 200', 200, $r->get_status());
    $eq('Autoren-Archiv-Sperre eingehängt', 1, has_action('template_redirect', ['TIX_REST_API', 'block_author_scan']));

    // ── M7: unbekannte Kategorie-Felder bleiben beim Speichern im WP-Admin ──
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'draft', 'post_title' => 'Audit B Event', 'meta_input' => ['_tix_date_start' => wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS), '_tix_time_start' => '20:00']]); $posts[] = $ev;
    update_post_meta($ev, '_tix_ticket_categories', [
        ['name' => 'Normal', 'price' => 10, 'qty' => 100, 'areas' => [3], 'custom_x' => 'a'],
        ['name' => 'Begleitung', 'price' => 0, 'qty' => 50, 'access' => 'companion', 'areas' => [4, 5], 'stock' => 7],
    ]);
    $_POST = ['tix_nonce' => wp_create_nonce('tix_save_event'), 'tix_tickets' => [
        0 => ['_orig' => '1', 'name' => 'Begleitung neu', 'price' => '0', 'qty' => '40', 'sale_mode' => 'online'], // umsortiert + umbenannt
        1 => ['_orig' => '0', 'name' => 'Normal', 'price' => '12', 'qty' => '100', 'sale_mode' => 'online'],
        2 => ['name' => 'Neu', 'price' => '5', 'qty' => '10', 'sale_mode' => 'online'],
    ]];
    TIX_Metabox::save($ev, get_post($ev));
    $_POST = [];
    wp_cache_delete($ev, 'post_meta');
    $c = get_post_meta($ev, '_tix_ticket_categories', true);
    $eq('M7: access/areas wandern mit der Zeile', ['companion', [4, 5], 'Begleitung neu', 40], [$c[0]['access'] ?? null, $c[0]['areas'] ?? null, $c[0]['name'] ?? null, $c[0]['qty'] ?? null]);
    $eq('M7: Felder der anderen Zeile erhalten, Formularwerte gelten', [[3], 'a', 12.0], [$c[1]['areas'] ?? null, $c[1]['custom_x'] ?? null, $c[1]['price'] ?? null]);
    $eq('M7: neue Zeile ohne fremde Felder, stock nicht übernommen', [false, false, false], [isset($c[2]['areas']), isset($c[2]['access']), ($c[0]['stock'] ?? null) === 7]);

    // ── M14: Sitzplatz-Label aus der vorgeladenen Karte = bisheriges Ergebnis ──
    if (class_exists('TIX_Seatmap')) {
        $plan = ['width' => 400, 'height' => 300, 'sections' => [['id' => 'parkett', 'label' => 'Parkett', 'price' => 10, 'rows' => [
            ['id' => 'A', 'seats' => [['id' => 'parkett_A_1', 'x' => 10, 'y' => 10, 'label' => '1'], ['id' => 'parkett_A_2', 'x' => 30, 'y' => 10, 'label' => '2']]],
            ['id' => 'B', 'label' => 'Reihe Bravo', 'seats' => [['id' => 'parkett_B_1', 'x' => 10, 'y' => 30]]],
        ]]]];
        $sm = wp_insert_post(['post_type' => TIX_Seatmap::CPT, 'post_status' => 'publish', 'post_title' => 'Audit B Plan']); $posts[] = $sm;
        update_post_meta($sm, '_tix_seatmap_data', wp_slash(wp_json_encode(TIX_Seatmap::sanitize_seatmap($plan), JSON_UNESCAPED_UNICODE)));
        update_post_meta($ev, '_tix_seatmap_id', $sm);
        $data = TIX_Seatmap::event_data($ev);
        $tk = [];
        foreach (['parkett_A_1', 'parkett_A_2', 'parkett_B_1', 'unbekannt_X_9'] as $sid) {
            $t = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'T', 'meta_input' => ['_tix_ticket_event_id' => $ev, '_tix_ticket_seat_id' => $sid]]); $posts[] = $t; $tk[$sid] = $t;
        }
        $old = array_map(function ($sid) use ($data) { return TIX_Seatmap::seat_label($sid, $data); }, array_keys($tk));
        $q0 = $wpdb->num_queries;
        $new = array_map(function ($t) { return TIX_Seatmap::ticket_seat_label($t); }, array_values($tk));
        $eq('M14: gleiche Platzangaben wie bisher', $old, $new);
        $q1 = $wpdb->num_queries;
        foreach ($tk as $t) TIX_Seatmap::ticket_seat_label($t);
        $eq('M14: zweiter Durchlauf ohne Plan-Laden (≤ 2 Abfragen je Ticket)', true, ($wpdb->num_queries - $q1) <= 2 * count($tk));
    }

    // ── Zweitmarkt: Aufräumen bei öffentlichen GETs gedrosselt ──
    if (class_exists('TIX_Resale')) {
        delete_transient('tix_resale_expire_ran');
        $rf = new ReflectionProperty('TIX_Resale', 'now'); $rf->setAccessible(true); $keep = $rf->getValue();
        $rf->setValue(null, null);
        TIX_Resale::expire_due();
        $eq('Zweitmarkt: Drossel gesetzt (eine Minute)', true, (bool) get_transient('tix_resale_expire_ran'));
        $rf->setValue(null, $keep);
    }

    // ── Rechnungsliste: Ausgabe wie bisher (Korrekturen vorgeladen) ──
    if (class_exists('TIX_Invoices') && !(class_exists('TIX_App_Scope') && TIX_App_Scope::multi())) {
        wp_set_current_user($adm);
        $r = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/organizer/invoices'));
        $eq('Rechnungsliste antwortet', 200, $r->get_status());
        $d = $r->get_data();
        $rows = (array) ($d['invoices'] ?? []);
        $ok = true;
        $rm = new ReflectionMethod('TIX_Invoices', 'remaining'); $rm->setAccessible(true);
        foreach (array_slice($rows, 0, 10) as $row) {
            $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Invoices::table() . " WHERE id = %d", $row['id']));
            if ($row['kind'] !== 'correction' && round($rm->invoke(null, $inv)['total'], 2) !== round((float) $row['open_amount'], 2)) $ok = false;
        }
        $eq('Rechnungsliste: offener Betrag wie Einzelabfrage (' . count($rows) . ' Zeilen)', true, $ok);
    }
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    $_POST = [];
    foreach ($posts as $p) wp_delete_post($p, true);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
