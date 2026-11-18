<?php
/**
 * Vertriebs-Meldungen (1.38.903): /platform/sales-notify, Ereignisse aus Demo/Testphase, Mail-Liste, Push an Plattform-Admins.
 *   wp eval-file …/test-sales-notify-wp.php   (Plattform: alles; Einzel-Seite: 404 und kein Versand)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Sales_Notify')) { echo "Tixomat ab 1.38.903 nötig\n"; return; }
$f = 0; $n = 0;
$ok = function ($l, $c) use (&$f, &$n) { $n++; echo ($c ? 'ok    ' : 'FAIL  ') . $l . "\n"; if (!$c) $f++; };
$call = function ($m, $route, $body = null, $origin = null) { $r = new WP_REST_Request($m, '/tixomat/v1' . $route); if ($body !== null) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); } if ($origin) $r->set_header('x-evendis-origin', $origin); $res = rest_do_request($r); return [$res->get_status(), $res->get_data()]; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$bak = []; foreach (['tix_sales_notify', 'tix_demo_notify', 'tix_trial_origins'] as $o) $bak[$o] = get_option($o, null);
$mails = []; $pushes = [];
$capm = function ($null, $a) use (&$mails) { $mails[] = ['to' => is_array($a['to']) ? implode(',', $a['to']) : (string) $a['to'], 'subject' => (string) $a['subject']]; return true; };
$capp = function ($pre, $uids, $title, $body, $data) use (&$pushes) { $pushes[] = compact('uids', 'title', 'body', 'data'); return count($uids); };
add_filter('pre_wp_mail', $capm, 1, 2);
add_filter('tix_staff_push_pre_send', $capp, 10, 5);
$users = [];
$mk = function ($role) use (&$users) { $id = wp_insert_user(['user_login' => 'sn_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'user_email' => 'sn' . wp_rand() . '@example.test', 'role' => $role]); $users[] = $id; return $id; };
try {
    $admin = $mk('administrator');
    wp_set_current_user($admin);
    delete_option('tix_sales_notify'); delete_option('tix_demo_notify');
    [$st, $d] = $call('GET', '/platform/sales-notify');
    if (!$multi) {
        $ok('Einzel-Seite: Route 404', $st === 404);
        $mails = []; $r = TIX_Sales_Notify::send('demo_requested', ['x', ['y']], ['T', 'B', 'staff:sales']);
        $ok('Einzel-Seite: kein Versand', $r === ['mail' => 0, 'push' => 0] && !$mails);
    } else {
        $ok('GET: Vorgabe-Adresse, Quelle default', $st === 200 && $d['emails'] === TIX_Sales_Notify::DEFAULT_EMAILS && $d['emails_source'] === 'default');
        $ok('GET: push an, 6 Ereignisse, trial_confirmed aus', $d['push'] === true && count($d['events']) === 6 && array_column($d['events'], 'enabled', 'key')['trial_confirmed'] === false);
        $ok('GET: push_devices Zahl', is_int($d['push_devices']));
        update_option('tix_demo_notify', ['alt@example.org'], false);
        $ok('nicht gespeichert + alte Option → legacy', TIX_Sales_Notify::settings()['emails'] === ['alt@example.org'] && TIX_Sales_Notify::settings()['emails_source'] === 'legacy');
        [$st, $d] = $call('POST', '/platform/sales-notify', ['emails' => ['ok@example.org', 'kaputt']]);
        $ok('400 ungültige Adresse, field emails.1', $st === 400 && ($d['data']['field'] ?? '') === 'emails.1');
        [$st, $d] = $call('POST', '/platform/sales-notify', ['events' => ['gibtsnicht' => true]]);
        $ok('400 unbekanntes Ereignis', $st === 400 && ($d['data']['field'] ?? '') === 'events.gibtsnicht');
        [$st, $d] = $call('POST', '/platform/sales-notify', ['emails' => ['Vertrieb@Example.org', 'vertrieb@example.org', 'chef@example.org'], 'events' => ['trial_confirmed' => true, 'other' => false]]);
        $ev = array_column($d['events'] ?? [], 'enabled', 'key');
        $ok('POST: Liste klein + entdoppelt, Ereignisse', $st === 200 && $d['emails'] === ['vertrieb@example.org', 'chef@example.org'] && $d['emails_source'] === 'saved' && $ev['trial_confirmed'] === true && $ev['other'] === false);
        [$st, $d] = $call('POST', '/platform/sales-notify', ['events' => [['key' => 'other', 'enabled' => true]]]);
        $ok('POST: Antwort-Form [{key, enabled}] geht auch', $st === 200 && array_column($d['events'], 'enabled', 'key')['other'] === true);
        // Versand
        $mails = []; $pushes = [];
        $r = TIX_Sales_Notify::send('demo_requested', ['Demo angefordert: Club X', ['Name: A', 'E-Mail: a@b.de']], ['Demo angefordert', 'Club X', 'staff:sales:demo']);
        $ok('Mail an beide Adressen', $r['mail'] === 2 && count($mails) === 2 && strpos($mails[0]['subject'], 'Demo angefordert') !== false);
        $p = $pushes[0] ?? null;
        $ok('Push an alle Admins, Text nur Firma, Aktion staff:sales:demo@<host>', $p && in_array($admin, $p['uids'], true) && $p['body'] === 'Club X' && $p['data']['action'] === 'staff:sales:demo@' . $p['data']['site'] && $p['data']['type'] === 'sales_demo_requested');
        $nonadmin = $mk('tix_organizer');
        $ok('Veranstalter bekommt keinen Push', $p && !in_array($nonadmin, $p['uids'], true));
        $items = (array) get_user_meta($admin, '_tix_notifications', true);
        $meta = class_exists('TIX_Notifications') && defined('TIX_Notifications::META_USER') ? (array) get_user_meta($admin, TIX_Notifications::META_USER, true) : $items;
        $ok('Feed-Eintrag beim Admin mit Aktion', (bool) array_filter($meta, function ($i) { return is_array($i) && ($i['action'] ?? '') === 'staff:sales:demo' && ($i['title'] ?? '') === 'Demo angefordert'; }));
        $call('POST', '/platform/sales-notify', ['push' => false, 'events' => ['trial_active' => false]]);
        $mails = []; $pushes = [];
        TIX_Sales_Notify::send('trial_active', ['x', []], ['T', 'B', 'staff:sales:trial:1']);
        $ok('Ereignis aus → nichts', !$mails && !$pushes);
        TIX_Sales_Notify::send('trial_requested', ['x', []], ['T', 'B', 'staff:sales:trial:1']);
        $ok('push aus → nur Mail', count($mails) === 2 && !$pushes);
        $call('POST', '/platform/sales-notify', ['emails' => []]);
        $mails = [];
        TIX_Sales_Notify::send('trial_requested', ['x', []]);
        $tos = array_column($mails, 'to');
        $ok('leere Liste → Rückfall Admins/tix_sales', in_array(get_userdata($admin)->user_email, $tos, true));
        // Ende-zu-Ende: Demo-Anfrage über die öffentliche Route
        $call('POST', '/platform/sales-notify', ['emails' => ['vertrieb@example.org'], 'push' => true, 'events' => ['demo_requested' => true]]);
        update_option('tix_trial_origins', ['demo.evendis.de'], false);
        $mails = []; $pushes = [];
        wp_set_current_user(0);
        $sfx = wp_generate_password(6, false, false);
        [$st, $d] = $call('POST', '/public/demo-access/request', ['name' => 'Lena Test', 'email' => "sn-$sfx@firma-test.de", 'company' => 'Sn GmbH', 'profile' => 'club', 'consent' => true], 'demo.evendis.de');
        $intern = array_values(array_filter($mails, function ($m) { return $m['to'] === 'vertrieb@example.org'; }));
        $ok('Demo-Anfrage: 202 + Meldung vor der Bestätigung', $st === 202 && count($intern) === 1 && strpos($intern[0]['subject'], 'Demo angefordert: Sn GmbH') === 0);
        $ok('Demo-Anfrage: Push „Sn GmbH“ ohne E-Mail', ($pushes[0]['body'] ?? '') === 'Sn GmbH' && strpos(wp_json_encode($pushes), 'firma-test.de') === false);
        global $wpdb; $wpdb->query($wpdb->prepare('DELETE FROM ' . TIX_Trial::t('tix_trial_requests') . ' WHERE email_hash = %s', hash('sha256', "sn-$sfx@firma-test.de")));
        wp_set_current_user($admin);
        $o = $mk('tix_organizer'); wp_set_current_user($o);
        [$st] = $call('GET', '/platform/sales-notify');
        $ok('Veranstalter: 403', $st === 403);
    }
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    remove_filter('pre_wp_mail', $capm, 1); remove_filter('tix_staff_push_pre_send', $capp, 10);
    foreach ($bak as $k => $v) { if ($v === null) delete_option($k); else update_option($k, $v, false); }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $u) wp_delete_user($u);
    wp_set_current_user(0);
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
