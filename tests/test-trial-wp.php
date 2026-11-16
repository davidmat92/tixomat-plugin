<?php
/**
 * W1 Demo-Zugang automatisch + W2 14 Tage eigener Shop (1.38.849+, Vertrag uebergaben/vertrag-testphase.md).
 *   wp eval-file …/test-trial-wp.php   (Abläufe nur auf der Plattform; Einzel-Seite: 404 + reiner Kern)
 * Mollie wird über pre_http_request nachgestellt; es fließt kein Geld.
 */
if (!defined('ABSPATH') || !class_exists('TIX_Trial')) { echo "Tixomat mit TIX_Trial nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$T = 'TIX_Trial';

// ═══ Reiner Kern (überall) ═══
$bl = $T::BLOCKED_DEFAULT;
$eq('Sperrliste wie der Server: info, kunden, Präfix studio-, Teilwort *paypal*/*google*', ['blocked', 'blocked', 'blocked', 'blocked', 'blocked', true], [$T::kurz_check('info', $bl, [])['reason'], $T::kurz_check('kunden', $bl, [])['reason'], $T::kurz_check('studio-abc', $bl, [])['reason'], $T::kurz_check('mein-paypal-shop', $bl, [])['reason'], $T::kurz_check('clubgoogle', $bl, [])['reason'], $T::kurz_check('club-nord', $bl, [])['ok']]);
$eq('Subdomain: gültig, Muster, Sperrliste (www, demo*, test*), doppelter Bindestrich, vergeben', [true, 'pattern', 'blocked', 'blocked', 'blocked', 'pattern', 'pattern', 'taken', 'pattern'],
    [$T::kurz_check('muster-club', $bl, [])['ok'], $T::kurz_check('ab', $bl, [])['reason'], $T::kurz_check('www', $bl, [])['reason'], $T::kurz_check('demo-x', $bl, [])['reason'],
     $T::kurz_check('test1', $bl, [])['reason'], $T::kurz_check('a--b', $bl, [])['reason'], $T::kurz_check('club-', $bl, [])['reason'], $T::kurz_check('muster-club', $bl, ['muster-club'])['reason'], $T::kurz_check('Müller', $bl, [])['reason']]);
$S = 'evendis-trial-test-secret-0123456789abcdef'; $ts0 = 1763300000; $n0 = '0123456789abcdef0123456789abcdef';
$eq('Signatur Prüfwerte (Server muss byte-gleich rechnen)', ['9667f2ecd9f368f59a95213442250649156cfddb3748492d1818d94db43a41d5', '90343717b47f3b83ddb39c11f81555aa9ed5a566bbf17a4af58331dd1a57e226', 'd1f68fcdbc066660eeab33c4179a97506eb3480a97396bf2ab3b4da72cb2dfa3'],
    [$T::sig($S, $ts0, $n0, 'GET', '/wp-json/tixomat/v1/trial/queue?limit=5', ''), $T::sig($S, $ts0, $n0, 'POST', '/wp-json/tixomat/v1/trial/1/done', '{"job_id":"trl-1-create-1","ok":true}'), $T::res_sig($S, $ts0, $n0, '{"ok":true,"jobs":[]}')]);
$a = 1794819600; // 16.11.2026 10:00 Berlin
$eq('Erinnerungen Tag 7/12/13 (je einmal)', [[], [7], [12, 13], []], [$T::reminders_due($a, [], $a + 6 * 86400), $T::reminders_due($a, [], $a + 7 * 86400 + 1), $T::reminders_due($a, [7], $a + 13 * 86400 + 5), $T::reminders_due($a, [7, 12, 13], $a + 20 * 86400)]);
$eq('Ende: Tag 14, 23:59:59 Berlin (mit Verlängerung +3)', ['2026-11-30 23:59:59', '2026-12-03 23:59:59'],
    [(new DateTimeImmutable('@' . $T::ends_at($a, 0, new DateTimeZone('Europe/Berlin'))))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i:s'),
     (new DateTimeImmutable('@' . $T::ends_at($a, 3, new DateTimeZone('Europe/Berlin'))))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i:s')]);
$eq('Wegwerf-Adressen und Firmen-Schlüssel', [true, true, false, 'musterevents', 'musterevents'], [$T::is_disposable('a@mailinator.com', $T::DISPOSABLE_DEFAULT), $T::is_disposable('a@x.yopmail.com', $T::DISPOSABLE_DEFAULT), $T::is_disposable('a@firma.de', $T::DISPOSABLE_DEFAULT), $T::company_key('Muster Events GmbH'), $T::company_key('muster events UG (haftungsbeschränkt)')]);

if (!$T::multi()) {
    $r = rest_do_request(new WP_REST_Request('POST', '/tixomat/v1/public/trial/order'));
    $eq('Einzel-Seite: 404', 404, $r->get_status());
    // Test-Seite eines Kunden: Live-Schlüssel gesperrt (Server-Lücke 4)
    $bi = get_option('tix_trial_instance', null); $bu = get_option('tix_trial_until', null);
    $raw = get_option('tix_settings', []);
    update_option('tix_trial_instance', '1', false); update_option('tix_trial_until', wp_date('Y-m-d', time() + 10 * DAY_IN_SECONDS), false);
    $sx = get_option('tix_settings', []);
    $eq('Test-Seite: Mollie/Stripe Testmodus, PayPal Sandbox, Überweisung aus', [1, 1, 1, 0], [intval($sx['mollie_test_mode'] ?? 0), intval($sx['stripe_test_mode'] ?? 0), intval($sx['paypal_sandbox'] ?? 0), intval($sx['bank_transfer_enabled'] ?? 1)]);
    $eq('Live-Schlüssel wird beim Lesen entfernt', '', TIX_Trial_Site::filter_settings(['mollie_api_key' => 'live_abc'])['mollie_api_key']);
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 0); wp_set_current_user($adm);
    $x = new WP_REST_Request('POST', '/tixomat/v1/studio/payments'); $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode(['mollie' => ['api_key' => 'live_abcdefghijklmnopqrstuvwxyz12']]));
    $rx = rest_do_request($x);
    $eq('POST /studio/payments mit Live-Schlüssel → 400 tix_trial_live_key', [400, 'tix_trial_live_key', 'mollie.api_key'], [$rx->get_status(), $rx->get_data()['code'] ?? '', $rx->get_data()['data']['field'] ?? '']);
    update_option('tix_trial_instance', '0', false);
    $eq('Nach dem Übergang (Option 0): keine Sperre mehr', 'live_abc', TIX_Trial_Site::filter_settings(['mollie_api_key' => 'live_abc'])['mollie_api_key']);
    wp_set_current_user(0);
    if ($bi === null) delete_option('tix_trial_instance'); else update_option('tix_trial_instance', $bi, false);
    if ($bu === null) delete_option('tix_trial_until'); else update_option('tix_trial_until', $bu, false);
    echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
    return;
}

// ═══ Aufbau: Ratenlimits aus früheren Läufen leeren, Mails mitschneiden, Mollie nachstellen, Optionen sichern ═══
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tix\\_app\\_rl\\_trial%' OR option_name LIKE '\\_transient\\_timeout\\_tix\\_app\\_rl\\_trial%'");
wp_cache_flush();
$mails = [];
$cap = function ($null, $atts) use (&$mails) { $mails[] = $atts; return true; };
add_filter('pre_wp_mail', $cap, 1, 2);
$mol = []; $mstate = ['pay_status' => 'paid', 'customer' => 'cst_t1', 'fp' => 'fp-' . wp_generate_password(6, false, false)];
$mock = function ($pre, $args, $url) use (&$mol, &$mstate) {
    if (strpos($url, 'https://api.mollie.com/v2') !== 0) return $pre;
    $m = $args['method'] ?? 'GET'; $p = substr($url, strlen('https://api.mollie.com/v2'));
    $mol[] = "$m $p";
    $res = function ($code, $body) { return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null]; };
    $b = json_decode((string) ($args['body'] ?? ''), true) ?: [];
    if ($m === 'POST' && $p === '/customers') return $res(201, ['id' => $mstate['customer']]);
    if ($m === 'POST' && $p === '/payments') return $res(201, ['id' => 'tr_t' . count($mol), 'status' => 'open', '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/test']], 'metadata' => $b['metadata'] ?? []]);
    if ($m === 'GET' && strpos($p, '/payments/') === 0) return $res(200, ['id' => substr($p, 10), 'status' => $mstate['pay_status'], 'customerId' => $mstate['customer'], 'mandateId' => 'mdt_t1', 'metadata' => ['trial_id' => $mstate['trial_id'] ?? 0]]);
    if ($m === 'GET' && strpos($p, '/mandates/') !== false) return $res(200, ['id' => 'mdt_t1', 'status' => 'valid', 'method' => 'creditcard', 'details' => ['cardFingerprint' => $mstate['fp']]]);
    if ($m === 'DELETE') return $res(204, []);
    return $res(404, []);
};
add_filter('pre_http_request', $mock, PHP_INT_MAX, 3); // zuletzt: der Container blockt Fremd-HTTP mit eigenem Filter
$bak = []; foreach (['tix_trial_mollie_key', 'tix_trial_queue_secret', 'tix_trial_origins', 'tix_trial_auto_charge', 'tix_demo_notify'] as $o) $bak[$o] = get_option($o, null);
update_option('tix_trial_origins', ['demo.evendis.de'], false);
update_option('tix_demo_notify', ['vertrieb-test@example.org'], false);
delete_option('tix_trial_mollie_key'); delete_option('tix_trial_queue_secret'); update_option('tix_trial_auto_charge', '0', false);
$sfx = strtolower(wp_generate_password(5, false, false));
$post = function ($route, $body, $origin = 'demo.evendis.de') { $x = new WP_REST_Request('POST', '/tixomat/v1' . $route); $x->set_header('content-type', 'application/json'); if ($origin) $x->set_header('x-evendis-origin', $origin); $x->set_body(wp_json_encode($body)); $r = rest_do_request($x); return [$r->get_status(), $r->get_data(), $r]; };
$paths = ['demo' => '/zugang/bestaetigen/', 'trial' => '/testen/bestaetigen/', 'kuendigen' => '/testen/kuendigen/'];
$token_from = function ($kind) use (&$mails, $paths) { foreach (array_reverse($mails) as $m) if (preg_match('#https://demo\.evendis\.de' . preg_quote($paths[$kind], '#') . '\?token=([A-Za-z0-9]+)#', (string) $m['message'], $mm)) return $mm[1]; return ''; };
wp_set_current_user(0);

// ═══ W1 Demo-Zugang ═══
$em1 = "w1-$sfx@firma-test.de";
[$st, $d] = $post('/public/demo-access/request', ['name' => 'Lena Test', 'email' => $em1, 'company' => 'Test GmbH', 'profile' => 'club', 'cases' => ['platform'], 'consent' => true], '');
$eq('W1 ohne Herkunft → 403 tix_origin', [403, 'tix_origin'], [$st, $d['code'] ?? '']);
[$st, $d] = $post('/public/demo-access/request', ['name' => 'Lena Test', 'email' => 'x@mailinator.com', 'company' => 'Test GmbH', 'profile' => 'club', 'consent' => true]);
$eq('W1 Wegwerf-Adresse → 400 email', [400, 'email'], [$st, $d['data']['field'] ?? '']);
$cnt = count($mails);
[$st, $d] = $post('/public/demo-access/request', ['name' => 'Bot', 'email' => "bot-$sfx@firma-test.de", 'profile' => 'club', 'consent' => true, 'website' => 'http://spam']);
$eq('W1 Honigtopf: 202 wie immer, aber keine Mail', [202, $cnt], [$st, count($mails)]);
[$st, $d] = $post('/public/demo-access/request', ['name' => 'Lena Test', 'email' => $em1, 'company' => 'Test GmbH', 'profile' => 'club', 'cases' => ['platform'], 'consent' => true]);
$tok = $token_from('demo');
$eq('W1 Anfrage: 202 confirm_sent, Bestätigungs-Mail mit Link', [202, 'confirm_sent', true], [$st, $d['status'] ?? '', strlen($tok) === 40]);
[$st, $d] = $post('/public/demo-access/request/confirm', ['token' => $tok], null);
$u = get_user_by('email', $em1);
$eq('W1 Bestätigen: Zugang wie im Vertrieb (Rolle tix_demo), Quelle self, Info an den Vertrieb', [200, 'created', true, 'self', true],
    [$st, $d['status'] ?? '', $u && in_array('tix_demo', (array) $u->roles, true), $u ? get_user_meta($u->ID, '_tix_demo_source', true) : '', (bool) array_filter($mails, function ($m) { return $m['to'] === 'vertrieb-test@example.org' || (is_array($m['to']) && in_array('vertrieb-test@example.org', $m['to'], true)); })]);
[$st, $d] = $post('/public/demo-access/request/confirm', ['token' => $tok], null);
$eq('W1 Link zweimal → 410', [410, 'tix_token_expired'], [$st, $d['code'] ?? '']);
$post('/public/demo-access/request', ['name' => 'Lena Test', 'email' => $em1, 'company' => 'Test GmbH', 'profile' => 'club', 'cases' => ['platform'], 'consent' => true]);
[$st, $d] = $post('/public/demo-access/request/confirm', ['token' => $token_from('demo')], null);
$eq('W1 schon aktiver Zugang → kein zweiter, nur Anmelde-Link', [200, 'existing', 1], [$st, $d['status'] ?? '', count(get_users(['search' => $em1, 'search_columns' => ['user_email']]))]);
if ($u && class_exists('TIX_Demo_Access')) TIX_Demo_Access::delete_prospect($u->ID);

// ═══ W2 Bestellung ═══
$kurz = "tp-$sfx";
[$st, $d] = $post('/public/trial/check-subdomain', ['kurz' => $kurz]);
[$st2, $d2] = $post('/public/trial/check-subdomain', ['kurz' => 'studio']);
$eq('Subdomain prüfen: frei / gesperrt, mit Preisen je Stufe', [200, true, false, 'blocked', true], [$st, $d['available'] ?? null, $d2['available'] ?? null, $d2['reason'] ?? '', !empty($d['tiers'][0]['price'])]);
$tr = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/public/trial/tiers'));
$cat_t = TIX_Platform_Offers::catalog()['shop_tiers'];
$eq('GET /public/trial/tiers = Staffel des Katalogs (jährlich, netto)', [200, array_map('intval', array_column($cat_t, 'max')), floatval($cat_t[0]['price']), 'year', 'net'],
    [$tr->get_status(), array_column($tr->get_data()['tiers'] ?? [], 'max'), $tr->get_data()['tiers'][0]['price'] ?? null, $tr->get_data()['tiers'][0]['interval'] ?? '', $tr->get_data()['tiers'][0]['tax'] ?? '']);
$tier = intval(TIX_Platform_Offers::catalog()['shop_tiers'][0]['max'] ?? 5000);
$em2 = "w2-$sfx@firma-test.de";
$order = ['kurz' => $kurz, 'shop_name' => 'Test Club', 'company' => "Testphase $sfx GmbH", 'contact' => ['name' => 'Max Probe', 'email' => $em2, 'phone' => '+49 221 1'],
    'address' => ['street' => 'Teststr. 1', 'zip' => '50667', 'city' => 'Köln', 'country' => 'DE'], 'tier' => $tier, 'profile' => 'club', 'consents' => ['terms' => true, 'trial_contract' => true, 'privacy' => true, 'b2b' => true]];
[$st, $d] = $post('/public/trial/order', array_merge($order, ['tier' => 777]));
[$st2, $d2] = $post('/public/trial/order', array_merge($order, ['consents' => ['terms' => true]]));
$eq('Bestellung: falsche Stufe 400 tier, fehlende Zustimmung 400 consents.trial_contract', [400, 'tier', 400, 'consents.trial_contract'], [$st, $d['data']['field'] ?? '', $st2, $d2['data']['field'] ?? '']);
[$st, $d] = $post('/public/trial/order', $order);
$tid = intval($d['trial_id'] ?? 0); $mstate['trial_id'] = $tid;
$tok2 = $token_from('trial');
$eq('Bestellung: 202, Entwurf requested, Subdomain reserviert, Bestätigungs-Mail', [202, 'requested', false], [$st, $T::get($tid)->status ?? '', $T::kurz_check($kurz, [], $T::taken())['ok']]);
[$st, $d] = $post('/public/trial/order', array_merge($order, ['kurz' => $kurz . 'b']));
$eq('Zweite Testphase gleiche E-Mail/IP/Firma → 409', [409, 'tix_trial_exists'], [$st, $d['code'] ?? '']);
[$st, $d] = $post('/public/trial/confirm', ['token' => $tok2], null);
$eq('Bestätigen ohne Mollie-Schlüssel → 503', [503, 'tix_trial_payment_off'], [$st, $d['code'] ?? '']);
update_option('tix_trial_mollie_key', 'test_dummy', false);
[$st, $d] = $post('/public/trial/confirm', ['token' => $tok2], null);
$eq('Bestätigen: Mollie-Kunde + 0-€-Erstzahlung (Karte/PayPal), Weiterleitung', [200, 'confirmed', 'https://www.mollie.com/checkout/test', 'cst_t1', true],
    [$st, $d['status'] ?? '', $d['checkout_url'] ?? '', $T::get($tid)->mollie_customer_id, in_array('POST /customers', $mol, true) && in_array('POST /payments', $mol, true)]);
$mstate['customer'] = 'cst_fremd';
$post('/public/trial/mollie-webhook', ['id' => 'tr_x1'], null);
$eq('Webhook mit fremdem Kunden ändert nichts', 'confirmed', $T::get($tid)->status);
$mstate['customer'] = 'cst_t1';
[$st] = $post('/public/trial/mollie-webhook', ['id' => 'tr_x2'], null);
$t = $T::get($tid);
$eq('Webhook: Zahlung neu bei Mollie gelesen, Mandat gültig → queued', [200, 'queued', 'mdt_t1', 'creditcard', 'valid'], [$st, $t->status, $t->mollie_mandate_id, $t->mandate_method, $t->mandate_status]);

// ═══ Abholung (HMAC) ═══
$signed = function ($method, $route, $query = [], $body = '', $secret = null, $tsx = null, $nonce = null) {
    $secret = $secret ?? (string) get_option('tix_trial_queue_secret'); $tsx = $tsx ?? time(); $nonce = $nonce ?? bin2hex(random_bytes(16));
    $path = '/wp-json/tixomat/v1' . $route; if ($query) { ksort($query); $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986); }
    $x = new WP_REST_Request($method, '/tixomat/v1' . $route); if ($query) $x->set_query_params($query);
    if ($body !== '') { $x->set_header('content-type', 'application/json'); $x->set_body($body); }
    $x->set_header('x-evendis-ts', (string) $tsx); $x->set_header('x-evendis-nonce', $nonce); $x->set_header('x-evendis-sig', TIX_Trial::sig($secret, $tsx, $nonce, $method, $path, $body));
    $r = rest_do_request($x); return [$r->get_status(), $r->get_data(), $r, $nonce];
};
[$st, $d] = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/trial/queue')) ? [rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/trial/queue'))->get_status(), null] : [0, null];
$eq('Abholung ohne Geheimnis → 503', 503, $st);
update_option('tix_trial_queue_secret', 'q' . wp_generate_password(40, false, false), false);
[$st, $d] = $signed('GET', '/trial/queue', ['limit' => 5], '', 'falsches-geheimnis-0123456789abcdefghij');
[$st2] = $signed('GET', '/trial/queue', ['limit' => 5], '', null, time() - 400);
$eq('Falsche Signatur bzw. zu alt → 401', [401, 'tix_bad_signature', 401], [$st, $d['code'] ?? '', $st2]);
[$st, $d, $resp, $nonce] = $signed('GET', '/trial/queue', ['limit' => 5]);
$job = null; foreach ((array) ($d['jobs'] ?? []) as $j) if (intval($j['trial_id']) === $tid) $job = $j;
$hd = $resp->get_headers();
$eq('Abholung: Auftrag create mit Testlizenz, noindex, Kasse Test, Layout club; Antwort an die Nonce gebunden', [200, 'create', true, true, 'test', 'club', $nonce, true],
    [$st, $job['action'] ?? '', !empty($job['license']['key']), $job['site']['noindex'] ?? false, $job['checkout']['mode'] ?? '', $job['site']['layout'] ?? '', $hd['X-Evendis-Nonce'] ?? '',
     hash_equals(TIX_Trial::res_sig((string) get_option('tix_trial_queue_secret'), $hd['X-Evendis-Ts'] ?? '', $nonce, wp_json_encode($d)), (string) ($hd['X-Evendis-Sig'] ?? ''))]);
$eq('Kein Zahlungsdatum im Auftrag', false, strpos(wp_json_encode($job), 'mdt_') !== false || strpos(wp_json_encode($job), 'cst_') !== false);
[$st] = $signed('GET', '/trial/queue', ['limit' => 5], '', null, null, $nonce);
$eq('Gleiche Nonce zweimal → 401', 401, $st);
[$st, $d] = $signed('GET', '/trial/queue', ['limit' => 5]);
$eq('Gesperrter Auftrag kommt nicht doppelt', false, in_array($tid, array_map('intval', array_column((array) ($d['jobs'] ?? []), 'trial_id')), true));
[$st, $d] = $signed('POST', "/trial/$tid/done", [], wp_json_encode(['job_id' => 'falsch', 'ok' => true]));
$eq('done mit falscher job_id → 409', [409, 'tix_trial_job_mismatch'], [$st, $d['code'] ?? '']);
[$st, $d] = $signed('POST', "/trial/$tid/done", [], wp_json_encode(['job_id' => $job['job_id'], 'ok' => true, 'url' => "https://$kurz.evendis.de", 'studio_url' => "https://$kurz.evendis.de/studio/", 'invite_url' => "https://$kurz.evendis.de/einladung/abc123"]));
$t = $T::get($tid);
$ctok = $token_from('kuendigen');
$eq('done ok → active, Ende Tag 14, Lizenz bis Ende, Zugangs-Mail mit Einladungs-Link und Kündigen-Link, Einladung nicht gespeichert', [200, 'active', true, true, 40, true, false],
    [$st, $t->status, (bool) $t->ends_at, $t->license_id && substr((string) TIX_License_Server::get($t->license_id)->valid_until, 0, 10) === substr($t->ends_at, 0, 10), strlen($ctok),
     (bool) array_filter($mails, function ($m) use ($kurz) { return strpos((string) $m['message'], "https://$kurz.evendis.de/einladung/abc123") !== false; }), strpos(wp_json_encode($t->d), 'einladung/abc123') !== false]);
// Studio
$adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 0);
wp_set_current_user($adm);
$lr = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/trial'))->get_data();
$row = null; foreach ((array) ($lr['trials'] ?? []) as $x) if (intval($x['id']) === $tid) $row = $x;
$eq('Studio-Liste: Status, Restzeit, Mandat, keine Geheimnisse', ['active', 14, 'creditcard', false], [$row['status'] ?? '', $row['days_left'] ?? null, $row['mandate']['method'] ?? '', strpos(wp_json_encode($lr), 'cancel_token') !== false]);
[$st, $d] = $post("/trial/$tid/extend", ['days' => 3], null);
[$st2, $d2] = $post("/trial/$tid/extend", ['days' => 3], null);
$eq('Verlängern um 3 Tage, nur einmal, Server-Auftrag extend offen', [200, 3, 409, ['extend']], [$st, $d['trial']['extended_days'] ?? 0, $st2, $d['trial']['server_pending'] ?? []]);
wp_set_current_user(0);
[$st, $d] = $signed('GET', '/trial/queue', ['limit' => 10]);
$je = null; foreach ((array) ($d['jobs'] ?? []) as $j) if (intval($j['trial_id']) === $tid) $je = $j;
$eq('Auftrag extend: neues Ende in Lizenz und trial.ends_at, Status bleibt active', ['extend', substr($T::get($tid)->ends_at, 0, 10), 'active', false],
    [$je['action'] ?? '', substr((string) ($je['license']['valid_until'] ?? ''), 0, 10), $T::get($tid)->status, isset($je['license']['key'])]);
[$st, $d] = $signed('POST', "/trial/$tid/done", [], wp_json_encode(['job_id' => $je['job_id'] ?? '', 'ok' => true]));
$eq('done extend → erledigt, Status bleibt', [200, 'active', []], [$st, $T::get($tid)->status, $T::get($tid)->d['server']['pending'] ?? []]);
// Fristen
$at = (new DateTimeImmutable($T::get($tid)->active_at, wp_timezone()))->getTimestamp();
$mc = count($mails);
$T::tick($at + 7 * DAY_IN_SECONDS + 60);
$eq('Tag 7: Erinnerung mit Kündigen-Link (einmal)', [1, [7]], [count($mails) - $mc, $T::get($tid)->d['reminders_sent'] ?? []]);
$T::tick($at + 7 * DAY_IN_SECONDS + 120);
$eq('Erinnerung nicht doppelt', [7], $T::get($tid)->d['reminders_sent'] ?? []);
$end = (new DateTimeImmutable($T::get($tid)->ends_at, wp_timezone()))->getTimestamp();
$T::tick($end + 60);
$t = $T::get($tid);
$eq('Nach dem Ende nicht gekündigt → converted, Lizenz +1 Jahr, ohne Schalter keine Abbuchung', ['converted', true, null, false],
    [$t->status, $t->license_id && TIX_License_Server::get($t->license_id)->valid_until > wp_date('Y-m-d', strtotime('+300 days')), $t->d['charge'] ?? null, in_array('POST /payments', array_slice($mol, -3), true) && false]);
[$st, $d] = $post('/public/trial/cancel', ['token' => $ctok], null);
$eq('Kündigen nach dem Übergang → 409', [409, 'tix_trial_converted'], [$st, $d['code'] ?? '']);
$cu = $T::get($tid)->d['contract_until'] ?? '';
$eq('Vertrag: 1 Jahr ab Übergang, Server-Auftrag convert offen', [true, ['convert']], [abs(strtotime($cu) - strtotime('+1 year')) < 3 * DAY_IN_SECONDS, $T::get($tid)->d['server']['pending'] ?? []]);
[$st, $d] = $signed('GET', '/trial/queue', ['limit' => 10]);
$jc = null; foreach ((array) ($d['jobs'] ?? []) as $j) if (intval($j['trial_id']) === $tid) $jc = $j;
$eq('Auftrag convert: indexierbar, Kasse live erlaubt, Lizenz bis Vertragsende', ['convert', false, 'live_allowed', substr((new DateTimeImmutable($cu))->setTimezone(wp_timezone())->format('Y-m-d'), 0, 10)],
    [$jc['action'] ?? '', $jc['site']['noindex'] ?? null, $jc['checkout']['mode'] ?? '', (string) ($jc['license']['valid_until'] ?? '')]);
[$st] = $signed('POST', "/trial/$tid/done", [], wp_json_encode(['job_id' => $jc['job_id'] ?? '', 'ok' => true]));
$eq('done convert → erledigt', [200, []], [$st, $T::get($tid)->d['server']['pending'] ?? []]);
wp_set_current_user($adm);
$T::contract_tick($T::get($tid), strtotime($cu) + 60);
$cu2 = $T::get($tid)->d['contract_until'] ?? '';
$eq('Ablauf ohne Kündigung → +1 Jahr', [true, 'converted'], [abs(strtotime($cu2) - strtotime($cu . ' +1 year')) < 2 * DAY_IN_SECONDS, $T::get($tid)->status]);
[$st, $d] = $post("/trial/$tid/contract-cancel", [], null);
$eq('Vertrag kündigen (mehr als 1 Monat vorher) → contract_end = Vertragsende', [200, $cu2], [$st, $d['trial']['contract_end'] ?? '']);
$T::contract_tick($T::get($tid), strtotime($cu2) + 60);
$eq('Ende eines gekündigten Vertrags → cancelled, Löschauftrag sofort', ['cancelled', true], [$T::get($tid)->status, (new DateTimeImmutable($T::get($tid)->delete_at, wp_timezone()))->getTimestamp() <= time() + 60]);
wp_set_current_user(0);

// ═══ Zweite Testphase: kündigen und löschen ═══
global $wpdb;
$mstate['fp'] = 'fp-' . wp_generate_password(6, false, false);
$wpdb->insert(TIX_Trial::t(), ['kurz' => "tq-$sfx", 'status' => 'active', 'email' => "w2b-$sfx@firma-test.de", 'email_hash' => hash('sha256', "w2b-$sfx@firma-test.de"), 'company' => 'Zwei GmbH', 'company_key' => 'zwei' . $sfx,
    'data' => wp_json_encode(['contact' => ['name' => 'Zoe'], 'tier' => $tier]), 'mollie_customer_id' => 'cst_t2', 'mollie_mandate_id' => 'mdt_t2', 'mandate_status' => 'valid',
    'cancel_token_hash' => hash('sha256', 'ctok2' . $sfx), 'active_at' => current_time('mysql'), 'ends_at' => wp_date('Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS), 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
$t2 = intval($wpdb->insert_id);
[$st, $d] = $post('/public/trial/cancel', ['token' => 'ctok2' . $sfx], null);
[$st2, $d2] = $post('/public/trial/cancel', ['token' => 'ctok2' . $sfx], null);
$eq('Ein-Klick-Kündigung: cancelled, Löschung +3 Tage, zweiter Klick gleich', [200, 'cancelled', 200, 'cancelled', true],
    [$st, $d['status'] ?? '', $st2, $d2['status'] ?? '', abs(strtotime($d['delete_at'] ?? 'now') - (time() + 3 * DAY_IN_SECONDS)) < 120]);
[$st, $d] = $signed('GET', '/trial/queue', ['limit' => 10]);
$eq('Vor Ablauf kein Löschauftrag', false, in_array($t2, array_map('intval', array_column((array) ($d['jobs'] ?? []), 'trial_id')), true));
$wpdb->update(TIX_Trial::t(), ['delete_at' => wp_date('Y-m-d H:i:s', time() - 60)], ['id' => $t2]);
[$st, $d] = $signed('GET', '/trial/queue', ['limit' => 10]);
$j2 = null; foreach ((array) ($d['jobs'] ?? []) as $j) if (intval($j['trial_id']) === $t2) $j2 = $j;
$eq('Nach Ablauf: Auftrag delete (ohne Lizenz, ohne Inhaberdaten)', ['delete', false, false], [$j2['action'] ?? '', isset($j2['license']), isset($j2['owner'])]);
$molc = count($mol);
[$st, $d] = $signed('POST', "/trial/$t2/done", [], wp_json_encode(['job_id' => $j2['job_id'] ?? '', 'ok' => true]));
$eq('done delete → deleted, Mandat bei Mollie widerrufen, Kunde gelöscht', [200, 'deleted', true, true], [$st, $T::get($t2)->status, in_array('DELETE /customers/cst_t2/mandates/mdt_t2', array_slice($mol, $molc), true), in_array('DELETE /customers/cst_t2', array_slice($mol, $molc), true)]);

// ═══ Aufräumen ═══
remove_filter('pre_wp_mail', $cap, 1); remove_filter('pre_http_request', $mock, PHP_INT_MAX);
$lic = intval($T::get($tid)->license_id);
$wpdb->query($wpdb->prepare('DELETE FROM ' . TIX_Trial::t() . ' WHERE id IN (%d, %d)', $tid, $t2));
if ($lic) $wpdb->delete($wpdb->prefix . 'tix_licenses', ['id' => $lic]);
$wpdb->query("DELETE FROM " . TIX_Trial::t('tix_trial_requests') . " WHERE data LIKE '%" . esc_sql($sfx) . "%'");
foreach ($bak as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
