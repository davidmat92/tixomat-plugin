<?php
/**
 * Mail-Protokoll fürs Studio (P2 Teil A, 1.38.785): Art/Bezüge aus der sendenden Funktion bzw. Kontext, Routen, Rechte,
 * Schwärzen, erneut senden (auch an andere Adresse, Begrenzung), Bestellung/Kunde, Kontolöschung.   wp eval-file …/test-mail-log-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Mail_Log')) { echo "Tixomat ab 1.38.785 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); return [$res->get_status(), $res->get_data()]; };
$T = TIX_Email_Log::table_name();
$last = function () use ($wpdb, $T) { return $wpdb->get_row("SELECT * FROM $T ORDER BY id DESC LIMIT 1"); };
add_filter('pre_wp_mail', '__return_true', 99);
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$posts = []; $orders = []; $users = []; $rows = [];
try {
    TIX_Email_Log::ensure_columns();
    $cols = $wpdb->get_col("SHOW COLUMNS FROM $T");
    $eq('Spalten kind/order_id/event_id/organizer_id/user_id/transport/resent_from', 7, count(array_intersect(['kind', 'order_id', 'event_id', 'organizer_id', 'user_id', 'transport', 'resent_from'], $cols)));
    $eq('Art aus Quelle', ['order_confirmation', 'invoice', 'login_code', 'password_reset', 'support', 'system'],
        [TIX_Mail_Log::classify('TIX_Emails::send_native_completed'), TIX_Mail_Log::classify('TIX_Invoices::send'), TIX_Mail_Log::classify('TIX_App_Account::send_code_email'), TIX_Mail_Log::classify('WordPress: retrieve_password'), TIX_Mail_Log::classify('TIX_Support::send_customer_mail'), TIX_Mail_Log::classify('WordPress')]);

    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'ML Veranstalter']); $posts[] = $org;
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'ML Event']); $posts[] = $ev;
    update_post_meta($ev, '_tix_organizer_id', $org); update_post_meta($ev, '_tix_date_start', '2027-05-01');
    $oid = TIX_Native_Checkout::create_order(['billing_first_name' => 'Mia', 'billing_last_name' => 'Mail', 'billing_email' => 'mia.mail@example.test', 'payment_method' => 'demo', 'total' => 0,
        'items' => [['event_id' => $ev, 'cat_index' => -1, 'name' => 'T', 'event_title' => 'ML Event', 'price' => 0, 'qty' => 1, 'meta' => []]]]);
    $orders[] = $oid;
    echo "== Erfassen\n";
    // echte sendende Funktion: Art + Bestellung + Event + Veranstalter aus Quelle/Argumenten
    TIX_Mail_Log::context([], function () {}); // leerer Kontext ändert nichts
    // Kontext ausdrücklich
    TIX_Mail_Log::context(['kind' => 'tickets', 'order_id' => $oid], function () { wp_mail('mia.mail@example.test', 'Deine Tickets', '<html><body><p>Tickets</p></body></html>', ['Content-Type: text/html; charset=UTF-8']); });
    $r = $last(); $rows[] = intval($r->id);
    $eq('Kontext: Art tickets, Bestellung, Event, Veranstalter aus dem Event', ['tickets', $oid, $ev, $org], [$r->kind, intval($r->order_id), intval($r->event_id), intval($r->organizer_id)]);
    // ohne Kontext: Quelle = sendende TIX-Funktion (früher immer „WordPress: log_outgoing“)
    TIX_Probe_Mail::send_reminder($oid, $ev);
    $r = $last(); $rows[] = intval($r->id);
    $eq('Quelle TIX_Probe_Mail::send_reminder, Art reminder, Bezüge aus den Argumenten', ['TIX_Probe_Mail::send_reminder', 'reminder', $oid, $ev], [$r->source, $r->kind, intval($r->order_id), intval($r->event_id)]);
    TIX_Mail_Log::context(['kind' => 'login_code', 'user_id' => 0], function () { wp_mail('mia.mail@example.test', 'Dein Code', '<p>Dein Code: 482913 – <a href="https://x.test/login?k=abcdefghijklmnopqrstuvwxyz">anmelden</a></p>'); });
    $secret = $last(); $rows[] = intval($secret->id);

    echo "== Routen\n";
    $adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);
    [$st, $d] = $rest('GET', '/mails/log', null, ['order_id' => $oid]);
    $eq('Liste nach Bestellung: 2 Mails, Kopf mit Bestellnummer, Event, customer_id, kinds', [200, 2, true, 'ML Event', true, true],
        [$st, count($d['items'] ?? []), !empty($d['items'][0]['order_number']), $d['items'][0]['event_title'] ?? null, strpos((string) ($d['items'][0]['customer_id'] ?? ''), 'e') === 0 || strpos((string) ($d['items'][0]['customer_id'] ?? ''), 'u') === 0, is_array($d['kinds'] ?? null)]);
    [$st, $d] = $rest('GET', '/mails/log', null, ['q' => 'Dein Code', 'per_page' => 1]);
    $eq('Suche + per_page + has_more', [200, 1, false], [$st, count($d['items'] ?? []), $d['has_more'] ?? null]);
    [$st, $d] = $rest('GET', '/mails/log/' . $secret->id);
    $eq('Anmelde-Code: geschwärzt, nicht erneut sendbar', [200, false, false, false, false], [$st, $d['resendable'] ?? null, strpos((string) $d['html'], '482913') !== false, strpos((string) $d['html'], 'abcdefghijklmnopqrstuvwxyz') !== false, strpos((string) $d['html'], 'https://x.test') !== false]);
    $eq('erneut senden: 409 tix_mail_not_resendable', 409, $rest('POST', '/mails/log/' . $secret->id . '/resend', [])[0]);
    $eq('unbekannt: 404 tix_mail_not_found', [404, 'tix_mail_not_found'], (function () use ($rest) { [$s, $d] = $rest('GET', '/mails/log/999999999'); return [$s, $d['code'] ?? null]; })());
    $tick = $rows[0];
    [$st, $d] = $rest('POST', "/mails/log/$tick/resend", []);
    $new = $last(); $rows[] = intval($new->id);
    $eq('erneut senden: neue Zeile mit resent_from, gleiche Art/Bestellung', [200, true, $tick, 'tickets', $oid], [$st, $d['ok'] ?? null, intval($new->resent_from), $new->kind, intval($new->order_id)]);
    [$st, $d] = $rest('POST', "/mails/log/$tick/resend", ['to' => 'andere@example.test']);
    $new = $last(); $rows[] = intval($new->id);
    $eq('an andere Adresse: nur dorthin', [200, 'andere@example.test'], [$st, $new->to_email]);
    $eq('ungültige Adresse: 400', 400, $rest('POST', "/mails/log/$tick/resend", ['to' => 'kaputt'])[0]);
    for ($i = 0; $i < 3; $i++) { $rest('POST', "/mails/log/$tick/resend", []); $rows[] = intval($last()->id); }
    $eq('6. Mal in der Stunde: 429 tix_mail_rate', 429, $rest('POST', "/mails/log/$tick/resend", [])[0]);
    delete_transient('tix_mail_rs_' . $tick);
    $wpdb->update($T, ['body' => ''], ['id' => $rows[1]]);
    $eq('Inhalt abgelaufen: 409 tix_mail_gone', 409, $rest('POST', '/mails/log/' . $rows[1] . '/resend', [])[0]);

    echo "== Bestellung, Kunde\n";
    $md = TIX_Mail_Log::for_order($oid);
    $eq('Bestellung: mails (neueste zuerst)', true, count($md) >= 2 && isset($md[0]['kind_label']));
    $mc = TIX_Mail_Log::for_customer(0, ['mia.mail@example.test']);
    $eq('Kunde: mails nach E-Mail', true, count($mc) >= 3);

    echo "== Rechte\n";
    $cash = wp_insert_user(['user_login' => 'mlcash' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(20), 'user_email' => 'mlcash' . wp_rand(1000, 9999) . '@example.test', 'role' => 'tix_cashier']);
    $users[] = $cash;
    if (!is_wp_error($cash) && get_role('tix_cashier')) { wp_set_current_user($cash); $eq('Kasse: 403', 403, $rest('GET', '/mails/log')[0]); }
    wp_set_current_user(0);
    $eq('ohne Anmeldung: 401', 401, $rest('GET', '/mails/log')[0]);
    if ($multi) {
        $other = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'ML Fremd']); $posts[] = $other;
        $om = wp_insert_user(['user_login' => 'mlorg' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(20), 'user_email' => 'mlorg' . wp_rand(1000, 9999) . '@example.test', 'role' => 'tix_organizer']);
        $users[] = $om; update_user_meta($om, TIX_App_Scope::META_TEAM_ORG, $other); update_user_meta($om, '_tix_team_role', 'admin');
        wp_set_current_user($om);
        [$st, $d] = $rest('GET', '/mails/log', null, ['order_id' => $oid]);
        $eq('evendis: fremder Veranstalter sieht die Mails nicht', 0, count($d['items'] ?? []));
        $eq('evendis: fremde Zeile → 404', 404, $rest('GET', '/mails/log/' . $tick)[0]);
        wp_set_current_user(0);
    }

    echo "== Kontolöschung\n";
    $del = TIX_Email_Log::delete_for_emails(['Mia.Mail@example.test']);
    $eq('Mails der Adresse gelöscht', [true, 0], [$del >= 3, intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $T WHERE LOWER(to_email) = %s", 'mia.mail@example.test')))]);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    remove_filter('pre_wp_mail', '__return_true', 99);
    foreach ($rows as $id) $wpdb->delete($T, ['id' => $id]);
    $wpdb->query("DELETE FROM $T WHERE to_email IN ('mia.mail@example.test', 'andere@example.test')");
    foreach ($orders as $o) { $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $o]); $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $o]); }
    foreach ($posts as $p) wp_delete_post($p, true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $u) if ($u && !is_wp_error($u)) wp_delete_user($u);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";

class TIX_Probe_Mail { public static function send_reminder($order_id, $event_id) { wp_mail('mia.mail@example.test', 'Erinnerung', '<html><body>Bald geht es los</body></html>', ['Content-Type: text/html; charset=UTF-8']); } }
