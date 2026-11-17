<?php
/**
 * 1.38.872: Demo-Zugang mit Benutzername + Passwort statt Einmal-Link (Mail-Scanner verbrauchten die Links).
 * Läuft auf einer Demo-Instanz (TIX_Demo_Shop::on) bzw. prüft dort die Instanz-Seite (Hash von der Plattform übernehmen).
 *   wp eval-file …/test-demo-credentials-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Demo_Access') || !method_exists('TIX_Demo_Access', 'send_credentials')) { echo "Tixomat ab 1.38.872 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$A = 'TIX_Demo_Access';
global $wpdb;

$pws = []; for ($i = 0; $i < 30; $i++) $pws[] = $A::readable_password();
$eq('Passwort: 14 Zeichen (3 × 4 mit Bindestrich), keine verwechselbaren Zeichen, jedes Mal anders', [true, true, 30],
    [!array_filter($pws, function ($p) { return !preg_match('/^[a-zA-Z2-9]{4}-[a-zA-Z2-9]{4}-[a-zA-Z2-9]{4}$/', $p); }), !array_filter($pws, function ($p) { return preg_match('/[0O1lI]/', $p); }), count(array_unique($pws))]);

if (!$A::instance()) {
    if (!$A::multi()) { echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen, nur rein)\n"; return; }
    // ═══ 1.38.874 Plattform: jeder Zugang immer in allen drei Demos, Mail nennt beide Instanz-Studios ═══
    $ob = []; foreach (['tix_demo_shop_url', 'tix_demo_shop_key', 'tix_demo_club_url', 'tix_demo_club_key'] as $o) $ob[$o] = get_option($o, null);
    update_option('tix_demo_shop_url', 'https://demoshop.test/wp-json/tixomat/v1', false); update_option('tix_demo_club_url', 'https://democlub.test/wp-json/tixomat/v1', false);
    update_option('tix_demo_shop_key', str_repeat('s', 40), false); update_option('tix_demo_club_key', str_repeat('c', 40), false);
    $calls = [];
    $mock = function ($pre, $args, $url) use (&$calls) {
        if (!preg_match('#^https://(demoshop|democlub)\.test/wp-json/tixomat/v1(/demo-access/remote[^?]*)#', $url, $m)) return $pre;
        $b = json_decode((string) ($args['body'] ?? ''), true) ?: [];
        $calls[] = [$m[1], $args['method'], $m[2], $b];
        $res = function ($code, $body) { return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null]; };
        if ($args['method'] === 'POST' && $m[2] === '/demo-access/remote') return $res(201, ['ok' => true, 'login_url' => 'https://studio.x/anmelden/link/?t=abc']);
        if ($args['method'] === 'POST' && preg_match('#/link$#', $m[2])) return $res(200, ['ok' => true] + (!empty($b['password_hash']) ? ['password_set' => true] : ['login_url' => 'x']));
        return $res(200, ['ok' => true]);
    };
    add_filter('pre_http_request', $mock, PHP_INT_MAX, 3);
    $mails = []; $cap = function ($pre, $atts) use (&$mails) { $mails[] = $atts; return true; }; add_filter('pre_wp_mail', $cap, 1, 2);
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 0);
    wp_set_current_user($adm);
    $em = 'drei' . wp_rand() . '@example.test';
    $x = new WP_REST_Request('POST', '/tixomat/v1/demo-access'); foreach (['kind' => 'prospect', 'name' => 'Drei Demos', 'email' => $em, 'company' => '', 'profile' => 'festival', 'cases' => ['shop']] as $k => $v) $x->set_param($k, $v);
    $res = $A::rest_create($x);
    $uu = get_user_by('email', $em);
    $creates = array_values(array_unique(array_column(array_filter($calls, function ($c) { return $c[1] === 'POST' && $c[2] === '/demo-access/remote'; }), 0)));
    $hashes = array_values(array_filter($calls, function ($c) { return !empty($c[3]['password_hash']); }));
    $eq('Auswahl „Ein Event“: Konto auf Plattform, demo-shop und demo-club; gleicher Hash auf beiden Instanzen; Variante shop gemerkt', [true, ['platform', 'shop', 'club'], ['democlub', 'demoshop'], 2, true, 'shop', true],
        [!is_wp_error($res) && $uu instanceof WP_User, $uu ? $A::cases($uu->ID) : [], (function ($a) { sort($a); return $a; })($creates), count($hashes), count($hashes) === 2 && $hashes[0][3]['password_hash'] === $hashes[1][3]['password_hash'] && $hashes[0][3]['password_hash'] === get_userdata($uu->ID)->user_pass,
         (string) get_user_meta($uu->ID, '_tix_demo_primary', true), in_array('tix_organizer', (array) $uu->roles, true)]);
    $mm = (string) (end($mails)['message'] ?? '');
    $eq('Mail: Hauptknopf → studio.demo-shop, beide Instanz-Studios als „Ein Event … / Mehrere Events …: Studio · Demo-Shop“, „Dein Zugang gilt in beiden.“, kein Plattform-Studio', [true, true, true, true, true, false, 1],
        [(bool) preg_match('#href="https://studio\.demo-shop\.evendis\.de/anmelden/"[^>]*class="tm-btn-sm"#', $mm), strpos($mm, 'Ein Event (z. B. Festival):</b> <a href="https://studio.demo-shop.evendis.de/anmelden/">Studio</a> · <a href="https://demo-shop.evendis.de/">Demo-Shop</a>') !== false,
         strpos($mm, 'Mehrere Events (z. B. Club, Location):</b> <a href="https://studio.demo-club.evendis.de/anmelden/">Studio</a> · <a href="https://demo-club.evendis.de/">Demo-Shop</a>') !== false,
         strpos($mm, 'Dein Zugang gilt in beiden.') !== false, strpos($mm, 'Ein Event') < strpos($mm, 'Mehrere Events'), strpos($mm, 'demo-studio.evendis.de') !== false, substr_count($mm, 'class="tm-btn-sm"')]);
    // Bestand: nur „shop“ → complete ergänzt club und Plattform mit demselben Hash, ohne Mail
    $em2 = 'alt' . wp_rand() . '@example.test';
    $old = $A::insert_prospect('Alt Zugang', $em2, '', time() + 5 * DAY_IN_SECONDS, $adm, false);
    update_user_meta($old->ID, '_tix_demo_cases', ['shop']); update_user_meta($old->ID, '_tix_demo_profile', 'festival');
    wp_set_password('Alt-Pass-1234', $old->ID); $old = get_userdata($old->ID);
    $calls = []; $mc = count($mails);
    $cr = $A::complete_cases($old);
    $hc = array_values(array_filter($calls, function ($c) { return !empty($c[3]['password_hash']); }));
    $eq('Bestand ergänzen: demo-club + Plattform dazu, gleicher Hash, keine Mail, Passwort bleibt', [['club', 'platform'], ['platform', 'shop', 'club'], 'democlub', true, 0, true],
        [$cr['added'] ?? null, $A::cases($old->ID), $hc[0][0] ?? '', ($hc[0][3]['password_hash'] ?? '') === get_userdata($old->ID)->user_pass, count($mails) - $mc, wp_check_password('Alt-Pass-1234', get_userdata($old->ID)->user_pass, $old->ID)]);
    remove_filter('pre_http_request', $mock, PHP_INT_MAX); remove_filter('pre_wp_mail', $cap, 1);
    foreach ($ob as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
    require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ([$uu->ID ?? 0, $old->ID] as $id) if ($id) wp_delete_user($id);
    wp_set_current_user(0);
    echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen, Plattform)\n";
    return;
}

$mails = [];
$cap = function ($pre, $atts) use (&$mails) { $mails[] = $atts; return true; };
add_filter('pre_wp_mail', $cap, 1, 2);
$email = 'demo' . wp_rand() . '@example.test';
$u = $A::insert_prospect('Dora Demo', $email, 'Demo GmbH', time() + 3 * DAY_IN_SECONDS);
$r = $A::send_credentials($u, true);
$m = (string) ($mails[0]['message'] ?? '');
$pw = preg_match('#Passwort: <b[^>]*>([a-zA-Z2-9]{4}-[a-zA-Z2-9]{4}-[a-zA-Z2-9]{4})</b>#', $m, $mm) ? $mm[1] : '';
$auth = wp_authenticate($email, $pw);
$eq('Zugangs-Mail: Benutzername = E-Mail, Passwort, Knopf auf die normale Anmeldeseite (ohne Einmal-Link); Anmeldung mit E-Mail + Passwort klappt', [true, true, true, true, false, true],
    [$r, strpos($m, 'Benutzername: <b>' . esc_html($email) . '</b>') !== false, $pw !== '', strpos($m, '/anmelden/') !== false, strpos($m, '/anmelden/link/') !== false, $auth instanceof WP_User && $auth->ID === $u->ID]);
$eq('1.38.873: kompakt – ein kleiner Hauptknopf „Zum Studio“, kein großer Knopf, vier Schritte, Reset-Hinweis, Textlinks Demo-Shop/Studio und „Eigenen Shop 14 Tage testen“', [1, false, true, true, true, true, true],
    [substr_count($m, 'class="tm-btn-sm"'), strpos($m, 'class="tm-btn"') !== false, strpos($m, 'Die Tour zeigt dir das Wichtigste.') !== false && strpos($m, 'Am Einlass das Ticket mit dem Handy scannen.') !== false,
     strpos($m, 'Alles wird jede Nacht zurückgesetzt – probier ruhig alles aus.') !== false, strpos($m, '>Demo-Shop</a>') !== false, strpos($m, '>Studio</a>') !== false, strpos($m, '/testen/">Eigenen Shop 14 Tage testen</a>') !== false]);
// Mail-Protokoll: Passwort nie gespeichert
$lt = class_exists('TIX_Email_Log') ? TIX_Email_Log::table_name() : '';
$row = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lt)) === $lt ? $wpdb->get_var($wpdb->prepare("SELECT body FROM $lt WHERE to_email LIKE %s ORDER BY id DESC LIMIT 1", '%' . $wpdb->esc_like($email) . '%')) : null;
if ($row !== null) $eq('Mail-Protokoll: Passwort ersetzt durch [nicht gespeichert]', [false, true], [strpos((string) $row, $pw) !== false, strpos((string) $row, '[nicht gespeichert]') !== false]);
else echo "skip  Mail-Protokoll-Tabelle nicht gefunden\n";
$eq('Gespeichert nur der Hash; kein Passwort in Metadaten', [true, false], [wp_check_password($pw, get_userdata($u->ID)->user_pass, $u->ID), strpos(wp_json_encode(get_user_meta($u->ID)), $pw) !== false]);
// Erneut angefordert → neues Passwort, altes gilt nicht mehr
$mails = [];
$A::send_credentials(get_userdata($u->ID), false);
$m2 = (string) ($mails[0]['message'] ?? '');
$pw2 = preg_match('#Passwort: <b[^>]*>([a-zA-Z2-9]{4}-[a-zA-Z2-9]{4}-[a-zA-Z2-9]{4})</b>#', $m2, $mm) ? $mm[1] : '';
$eq('Neu angefordert: neues Passwort, altes ungültig, Hinweis „Das alte Passwort gilt nicht mehr“', [true, true, false, true],
    [$pw2 !== '' && $pw2 !== $pw, wp_authenticate($email, $pw2) instanceof WP_User, wp_authenticate($email, $pw) instanceof WP_User, strpos($m2, 'Das alte Passwort gilt nicht mehr') !== false]);
// Abgelaufener Zugang: Anmeldung gesperrt
update_user_meta($u->ID, '_tix_demo_expires', time() - 60);
$eq('Abgelaufener Zugang: Anmeldung mit Passwort gesperrt', true, is_wp_error(wp_authenticate($email, $pw2)));
update_user_meta($u->ID, '_tix_demo_expires', time() + DAY_IN_SECONDS);
// Instanz übernimmt den Hash der Plattform (signierter Aufruf, hier direkt)
update_user_meta($u->ID, '_tix_demo_remote_id', 990000 + $u->ID);
$plat = 'Plat-form-Pass';
$x = new WP_REST_Request('POST', '/tixomat/v1/demo-access/remote/' . (990000 + $u->ID) . '/link'); $x->set_url_params(['rid' => 990000 + $u->ID]); $x->set_param('password_hash', wp_hash_password($plat));
$rr = TIX_Demo_Remote::r_link($x);
$x2 = new WP_REST_Request('POST', '/tixomat/v1/demo-access/remote/' . (990000 + $u->ID) . '/link'); $x2->set_url_params(['rid' => 990000 + $u->ID]); $x2->set_param('password_hash', 'kein-hash');
$rr2 = TIX_Demo_Remote::r_link($x2);
$eq('Instanz: Hash der Plattform übernommen (password_set), Anmeldung mit dem Plattform-Passwort; ungültiger Hash abgelehnt', [true, true, false],
    [!is_wp_error($rr) && !empty($rr->get_data()['password_set']), wp_authenticate($email, $plat) instanceof WP_User, !is_wp_error($rr2) && !empty($rr2->get_data()['password_set'])]);

remove_filter('pre_wp_mail', $cap, 1);
if ($row !== null) $wpdb->query($wpdb->prepare("DELETE FROM $lt WHERE to_email LIKE %s", '%' . $wpdb->esc_like($email) . '%'));
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($u->ID);
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
