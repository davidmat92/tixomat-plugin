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
$tq = [['max' => 5000, 'price' => 790], ['max' => 10000, 'price' => 1690]];
$y0 = 1794819600; $y1 = $y0 + 365 * 86400; $mid = $y0 + (int) (182.5 * 86400);
$q = $T::upgrade_quote(6000, 5000, $tq, $y0, $y1, $mid);
$eq('Hochstufen anteilig: 6.000 > 5.000 → 10.000, Differenz 900 €/Jahr × 183/365 Tage = 451,23 €', [10000, false, 900.0, 183, 365, 451.23], [$q['to_tier'], $q['on_request'], $q['diff_year'], $q['remaining_days'], $q['total_days'], $q['amount_net']]);
$eq('Paket reicht → kein Hochstufen; über der Staffel → auf Anfrage', [null, true, null], [$T::upgrade_quote(4000, 5000, $tq, $y0, $y1, $mid), $T::upgrade_quote(12000, 5000, $tq, $y0, $y1, $mid)['on_request'], $T::upgrade_quote(12000, 5000, $tq, $y0, $y1, $mid)['amount_net']]);
$eq('Am letzten Tag: anteilig 1 Tag; nach Ablauf 0 €', [round(900 / 365, 2), 0.0], [$T::upgrade_quote(6000, 5000, $tq, $y0, $y1, $y1 - 3600)['amount_net'], $T::upgrade_quote(6000, 5000, $tq, $y0, $y1, $y1 + 10)['amount_net']]);
$eq('evendis-Adressen (auch Subdomains, Großschreibung) erkannt, ähnliche Domains nicht', [true, true, true, false, false], [$T::is_own_domain('max@evendis.de'), $T::is_own_domain('a@mail.evendis.de'), $T::is_own_domain(' Max@EVENDIS.DE '), $T::is_own_domain('a@notevendis.de'), $T::is_own_domain('a@evendis.de.example.com')]);
$pv = $T::progress_view('provisioning', ['progress' => ['step' => 'plugin', 'index' => 5, 'total' => 14], 'progress_pct' => 35, 'retried' => true, 'url' => 'https://x.evendis.de']);
$eq('Fortschritt rein: Stufe aus internem Schritt, Prozent, Neuversuch, URLs erst bei fertig', ['einrichtung', 3, 'Shop-System wird installiert', 35, true, false, 6],
    [$pv['phase'], $pv['stage'], $pv['step_label'], $pv['percent'], $pv['retrying'], isset($pv['shop_url']), count($pv['stages'])]);
$eq('Fortschritt rein: wartet / queued Stufe 1 / fertig 100 mit URLs / failed ohne Technik / gelöscht', ['wartet', 1, 0, 100, 'https://x.evendis.de', 'fehler', false, 'beendet'],
    [$T::progress_view('confirmed', [])['phase'], $T::progress_view('queued', [])['stage'], $T::progress_view('queued', [])['percent'], $T::progress_view('active', ['url' => 'https://x.evendis.de'])['percent'], $T::progress_view('active', ['url' => 'https://x.evendis.de'])['shop_url'],
     $T::progress_view('failed', ['error' => 'technik: geheim'])['phase'], strpos(wp_json_encode($T::progress_view('failed', ['error' => 'technik: geheim', 'progress_pct' => 40])), 'geheim') !== false, $T::progress_view('deleted', [])['phase']]);
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
    // W2c: Zählung für die Lizenzprüfung (bezahlte Tickets valid/used im Vertragsjahr)
    global $wpdb;
    $tt = $wpdb->prefix . TIX_Ticket_DB::TABLE_SUFFIX;
    $since = wp_date('Y-m-d', time() - 10 * DAY_IN_SECONDS);
    $base = TIX_Trial_Site::tickets_since($since);
    $mk = function ($code, $price, $status) use ($wpdb, $tt) { $wpdb->insert($tt, ['ticket_code' => $code, 'event_id' => 999999, 'ticket_price' => $price, 'ticket_status' => $status, 'created_at' => current_time('mysql')]); };
    $cx = 'W2C' . strtoupper(wp_generate_password(6, false, false));
    $mk($cx . 'A', 25, 'valid'); $mk($cx . 'B', 0, 'valid'); $mk($cx . 'C', 25, 'cancelled'); $mk($cx . 'D', 25, 'resold'); $mk($cx . 'E', 25, 'used');
    $eq('Zählung: bezahlt valid/used (+2), ohne Freiticket, Storno und Weiterverkauft', 2, TIX_Trial_Site::tickets_since($since) - $base);
    update_option('tix_trial_count_free', '1', false); // früherer Schalter (entfernt): darf nichts mehr ändern
    $eq('Freitickets zählen nie (User-Entscheid), auch nicht mit dem alten Schalter', 2, TIX_Trial_Site::tickets_since($since) - $base);
    delete_option('tix_trial_count_free');
    $lsb = get_option('tix_license_status', null);
    update_option('tix_license_status', array_merge(is_array($lsb) ? $lsb : [], ['expires_at' => wp_date('Y-m-d', time() + 200 * DAY_IN_SECONDS)]), false);
    update_option('tix_trial_instance', '0', false);
    $up = TIX_Trial_Site::usage_params();
    $eq('Lizenzprüfung meldet Tickets seit Laufzeitende − 1 Jahr', [wp_date('Y-m-d', time() - 165 * DAY_IN_SECONDS), true], [$up['tickets_since'] ?? '', isset($up['tickets_count'])]);
    update_option('tix_trial_instance', '1', false);
    $eq('In der Testphase keine Meldung', [], TIX_Trial_Site::usage_params());
    if ($lsb === null) delete_option('tix_license_status'); else update_option('tix_license_status', $lsb, false);
    $wpdb->query($wpdb->prepare("DELETE FROM $tt WHERE ticket_code LIKE %s", $cx . '%'));
    // W2b: /me.trial für das Band (Format des Studios), Stand live von der Plattform
    $binfo = get_option('tix_trial_info', null);
    $surl = 'https://veranstalter.evendis.de/wp-json/tixomat/v1/public/trial/status?token=abc' . wp_rand();
    update_option('tix_trial_info', ['ends_at' => wp_date('c', time() + 5 * DAY_IN_SECONDS), 'cancel_url' => 'https://demo.evendis.de/testen/kuendigen/?token=abc', 'start_url' => 'https://demo.evendis.de/testen/starten/?token=abc', 'status_url' => $surl], false);
    $smock = function ($pre, $args, $url) use ($surl) { if ($url !== $surl) return $pre; return ['headers' => [], 'body' => wp_json_encode(['ok' => true, 'status' => 'cancelled', 'days_left' => null, 'delete_at' => '2026-12-01T10:00:00+01:00', 'can_cancel' => false]), 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null]; };
    add_filter('pre_http_request', $smock, PHP_INT_MAX, 3);
    wp_set_current_user($adm);
    $me = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/me'))->get_data();
    $eq('/me.trial: Links aus tix_trial_info, Stand gekündigt mit Löschdatum von der Plattform', ['https://demo.evendis.de/testen/kuendigen/?token=abc', 'https://demo.evendis.de/testen/starten/?token=abc', 'cancelled', '2026-12-01T10:00:00+01:00', false, true],
        [$me['trial']['cancel_url'] ?? '', $me['trial']['start_url'] ?? '', $me['trial']['status'] ?? '', $me['trial']['delete_at'] ?? '', $me['trial']['can_cancel'] ?? null, isset($me['trial']['ends_at'])]);
    $kasse = wp_insert_user(['user_login' => 'trialkasse' . wp_rand(), 'user_pass' => wp_generate_password(20), 'user_email' => 'tk' . wp_rand() . '@example.test', 'role' => 'subscriber']);
    wp_set_current_user($kasse);
    $eq('/me.trial nur für Inhaber/Admin', false, array_key_exists('trial', (array) rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/me'))->get_data()));
    require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($kasse);
    remove_filter('pre_http_request', $smock, PHP_INT_MAX);
    delete_transient('tix_trial_status_' . md5($surl));
    if ($binfo === null) delete_option('tix_trial_info'); else update_option('tix_trial_info', $binfo, false);
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
    if ($m === 'POST' && $p === '/payments') $mstate['redirect'] = (string) ($b['redirectUrl'] ?? '');
    if ($m === 'POST' && $p === '/payments') return $res(201, ['id' => 'tr_t' . count($mol), 'status' => 'open', '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/test']], 'metadata' => $b['metadata'] ?? []]);
    if ($m === 'GET' && strpos($p, '/payments/') === 0) return $res(200, ['id' => substr($p, 10), 'status' => $mstate['pay_status'], 'customerId' => $mstate['customer'], 'mandateId' => 'mdt_t1', 'metadata' => ['trial_id' => $mstate['trial_id'] ?? 0] + (!empty($mstate['kind']) ? ['kind' => $mstate['kind']] : [])]);
    if ($m === 'GET' && strpos($p, '/mandates/') !== false) return $res(200, ['id' => 'mdt_t1', 'status' => 'valid', 'method' => 'creditcard', 'details' => ['cardFingerprint' => $mstate['fp']]]);
    if ($m === 'DELETE') return $res(204, []);
    return $res(404, []);
};
add_filter('pre_http_request', $mock, PHP_INT_MAX, 3); // zuletzt: der Container blockt Fremd-HTTP mit eigenem Filter
$bak = []; foreach (['tix_trial_mollie_key', 'tix_trial_queue_secret', 'tix_trial_origins', 'tix_trial_auto_charge', 'tix_demo_notify', 'tix_trial_upgrade_charge', 'tix_trial_invoice'] as $o) $bak[$o] = get_option($o, null);
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
[$st, $d] = $post('/public/trial/order', array_merge($order, ['contact' => array_merge($order['contact'], ['email' => "w2-$sfx@evendis.de"])]));
[$st2, $d2] = $post('/public/trial/order', array_merge($order, ['contact' => array_merge($order['contact'], ['email' => "w2-$sfx@team.evendis.de"])]));
$eq('Bestellung mit evendis-Adresse (auch Subdomain): 400 tix_invalid_field contact.email, nichts angelegt', [400, 'tix_invalid_field', 'contact.email', 400, true, true],
    [$st, $d['code'] ?? '', $d['data']['field'] ?? '', $st2, strpos($d['message'] ?? '', 'evendis-Adressen sind nicht möglich') !== false, $T::kurz_check($kurz, [], $T::taken())['ok']]);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tix\\_app\\_rl\\_trial\\_order%' OR option_name LIKE '\\_transient\\_timeout\\_tix\\_app\\_rl\\_trial\\_order%'"); wp_cache_flush(); // IP-Grenze 5/h nicht durch die Prüfungen oben verbrauchen
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
$getp = function ($p) { $x = new WP_REST_Request('GET', '/tixomat/v1/public/trial/progress'); $x->set_query_params(['p' => $p]); $r = rest_do_request($x); return [$r->get_status(), $r->get_data(), $r->get_headers()]; };
$p1 = preg_match('/[?&]p=([A-Za-z0-9]+)/', (string) ($mstate['redirect'] ?? ''), $mm) ? $mm[1] : '';
[$st, $d, $hd] = $getp($p1);
[$st2, $d2] = $getp('x' . $p1);
$eq('redirectUrl: /testen/fertig/?trial=<id>&p=<Token>; Fortschritt „wartet“, no-store, ohne Personendaten; falscher Token 404', [true, 40, 200, 'wartet', 'no-store', false, 404],
    [strpos((string) $mstate['redirect'], '/testen/fertig/?trial=' . $tid . '&p=') !== false, strlen($p1), $st, $d['phase'] ?? '', $hd['Cache-Control'] ?? '',
     (bool) preg_grep('/' . preg_quote($em2, '/') . '|Testphase ' . $sfx . '|Max Probe|cst_|mdt_/', [wp_json_encode($d)]), $st2]);
$mstate['customer'] = 'cst_fremd';
$post('/public/trial/mollie-webhook', ['id' => 'tr_x1'], null);
$eq('Webhook mit fremdem Kunden ändert nichts', 'confirmed', $T::get($tid)->status);
$mstate['customer'] = 'cst_t1';
[$st] = $post('/public/trial/mollie-webhook', ['id' => 'tr_x2'], null);
$t = $T::get($tid);
$pm = array_values(array_filter($mails, function ($m) { return ($m['subject'] ?? '') === 'Dein Testshop wird eingerichtet'; }));
$p2 = $pm && preg_match('/(?:[?&;])p=([A-Za-z0-9]{40})/', (string) $pm[0]['message'], $mm) ? $mm[1] : '';
[$st2, $d2] = $getp($p2);
[$st3, $d3] = $getp($p1);
$eq('Mail „Dein Testshop wird eingerichtet“ mit eigenem Fortschritts-Link; beide Tokens zeigen Stufe 1', [1, true, 200, 'einrichtung', 1, 'Zahlungsmittel bestätigt', 1], [count($pm), $p2 !== '' && $p2 !== $p1, $st2, $d2['phase'] ?? '', $d2['stage'] ?? null, $d2['step_label'] ?? '', $d3['stage'] ?? null]);
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
[$st, $d] = $signed('POST', "/trial/$tid/progress", [], wp_json_encode(['job_id' => 'falsch', 'step' => 'dns', 'index' => 3, 'total' => 14]));
[$st2, $d2] = $signed('POST', "/trial/$tid/progress", [], wp_json_encode(['job_id' => $job['job_id'], 'step' => 'plugin', 'index' => 5, 'total' => 14]));
[$st3, $d3] = $signed('POST', "/trial/$tid/progress", [], wp_json_encode(['job_id' => $job['job_id'], 'step' => 'plugin', 'index' => 99, 'total' => 14]));
[, $g] = $getp($p2);
$eq('progress: falsche job_id 409, gültig gespeichert, unsinniger index 400; Seite Stufe 3, 35 %, 5/14', [409, 200, true, 400, 3, 35, 5, 14],
    [$st, $st2, $d2['stored'] ?? null, $st3, $g['stage'] ?? null, $g['percent'] ?? null, $g['index'] ?? null, $g['total'] ?? null]);
$signed('POST', "/trial/$tid/progress", [], wp_json_encode(['job_id' => $job['job_id'], 'step' => 'dns', 'index' => 2, 'total' => 14]));
[, $g] = $getp($p2);
$eq('progress: Balken fällt nie zurück (35 % bleibt bei kleinerem Index)', [35, 2], [$g['percent'] ?? null, $g['stage'] ?? null]);
[$st, $d] = $signed('POST', "/trial/$tid/done", [], wp_json_encode(['job_id' => 'falsch', 'ok' => true]));
$eq('done mit falscher job_id → 409', [409, 'tix_trial_job_mismatch'], [$st, $d['code'] ?? '']);
[$st, $d] = $signed('POST', "/trial/$tid/done", [], wp_json_encode(['job_id' => $job['job_id'], 'ok' => true, 'url' => "https://$kurz.evendis.de", 'studio_url' => "https://$kurz.evendis.de/studio/", 'invite_url' => "https://$kurz.evendis.de/einladung/abc123"]));
$t = $T::get($tid);
$ctok = $token_from('kuendigen');
$eq('done ok → active, Ende Tag 14, Lizenz bis Ende, Zugangs-Mail mit Einladungs-Link und Kündigen-Link, Einladung nicht gespeichert', [200, 'active', true, true, 40, true, false],
    [$st, $t->status, (bool) $t->ends_at, $t->license_id && substr((string) TIX_License_Server::get($t->license_id)->valid_until, 0, 10) === substr($t->ends_at, 0, 10), strlen($ctok),
     (bool) array_filter($mails, function ($m) use ($kurz) { return strpos((string) $m['message'], "https://$kurz.evendis.de/einladung/abc123") !== false; }), strpos(wp_json_encode($t->d), 'einladung/abc123') !== false]);
[$st, $g] = $getp($p2);
[$st2] = $signed('POST', "/trial/$tid/progress", [], wp_json_encode(['job_id' => $job['job_id'], 'step' => 'melden', 'index' => 14, 'total' => 14]));
$eq('Nach done: Seite „fertig“, 100 %, Shop- und Studio-Link; progress danach 409', ['fertig', 100, "https://$kurz.evendis.de", "https://$kurz.evendis.de/studio/", 409], [$g['phase'] ?? '', $g['percent'] ?? null, $g['shop_url'] ?? '', $g['studio_url'] ?? '', $st2]);
// 1.38.858: Knöpfe der Testphasen-Mails (User-Fund aus der Probe)
$rm = array_values(array_filter($mails, function ($m) { return ($m['subject'] ?? '') === 'Dein Shop ist bereit'; }));
$h = (string) ($rm[0]['message'] ?? '');
preg_match_all('#<a [^>]*class="(tm-btn[a-z-]*)"[^>]*>([^<]+)</a>#', $h, $bm);
$eq('„Dein Shop ist bereit“: eine rote Hauptaktion (Passwort festlegen), Studio als Umriss, Kündigen nur als Textlink, kein großer Knopf', [['tm-btn-sm', 'tm-btn-ol'], ['Passwort festlegen', 'Zum Studio'], true, true, false],
    [$bm[1], $bm[2], strpos($h, 'Du willst doch nicht? <a href="' . esc_url($T::page_url('kuendigen', $ctok))) !== false, strpos($h, 'font-size:16px') !== false && strpos($h, 'padding:12px 24px') !== false, strpos($h, 'min-width:200px') !== false]);
$pm2 = array_values(array_filter($mails, function ($m) { return ($m['subject'] ?? '') === 'Dein Testshop wird eingerichtet'; }));
$eq('„Dein Testshop wird eingerichtet“: kleiner Hauptknopf; andere Mails behalten den großen Knopf', [true, false, true],
    [strpos((string) ($pm2[0]['message'] ?? ''), 'class="tm-btn-sm"') !== false, strpos((string) ($pm2[0]['message'] ?? ''), 'class="tm-btn"') !== false, strpos(TIX_Mail_Layout::button('X', 'https://example.org'), 'min-width:200px') !== false]);
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
// W2b: Stand fürs Band, Token aus dem Auftrag create = Token der Mails
$stt = rest_do_request((function () use ($ctok) { $x = new WP_REST_Request('GET', '/tixomat/v1/public/trial/status'); $x->set_query_params(['token' => $ctok]); return $x; })());
$eq('Stand per Token: active, Tage, kündbar, startbar, keine Personendaten; Auftrag create trug cancel/start/status_url', [200, 'active', 'trial', true, true, false, true],
    [$stt->get_status(), $stt->get_data()['status'] ?? '', $stt->get_data()['phase'] ?? '', $stt->get_data()['can_cancel'] ?? null, $stt->get_data()['can_start'] ?? null, strpos(wp_json_encode($stt->get_data()), '@') !== false,
     strpos((string) ($job['trial']['cancel_url'] ?? ''), '/testen/kuendigen/?token=' . $ctok) !== false && strpos((string) ($job['trial']['start_url'] ?? ''), '/testen/starten/?token=' . $ctok) !== false && strpos((string) ($job['trial']['status_url'] ?? ''), 'token=' . $ctok) !== false]);
// Rechnung + Abbuchung mit Schaltern (Aussteller-Angaben ggf. für den Test ergänzen)
$set_bak = get_option('tix_settings', []);
$set = is_array($set_bak) ? $set_bak : [];
foreach (['invoice_company_name' => 'evendis Test GmbH', 'invoice_company_address' => "Teststr. 1\n50667 Köln", 'invoice_company_ust_id' => 'DE123456789'] as $k => $v) if (trim((string) ($set[$k] ?? '')) === '') $set[$k] = $v;
update_option('tix_settings', $set);
update_option('tix_trial_invoice', '1', false); update_option('tix_trial_auto_charge', '1', false);
$mstate['kind'] = 'contract';
$end = (new DateTimeImmutable($T::get($tid)->ends_at, wp_timezone()))->getTimestamp();
$mc = count($mails);
$T::tick($end + 60);
$t = $T::get($tid);
$inv = $t->d['invoices'][0] ?? [];
$price = 0.0; foreach (TIX_Platform_Offers::catalog()['shop_tiers'] as $x) if (intval($x['max']) === $tier) $price = floatval($x['price']);
$eq('Übergang: converted, Lizenz +1 Jahr, Abbuchung Jahresbetrag brutto über das Mandat, Rechnung RV-… netto + 19 % per Mail mit PDF', ['converted', true, round($price * 1.19, 2), true, $price, round($price * 1.19, 2), true],
    [$t->status, $t->license_id && TIX_License_Server::get($t->license_id)->valid_until > wp_date('Y-m-d', strtotime('+300 days')), $t->d['charge']['amount'] ?? null,
     (bool) preg_match('/^RV-([A-Z0-9]+-)?\d{4}-\d{4}$/', (string) ($inv['number'] ?? '')), floatval($inv['net'] ?? 0), floatval($inv['gross'] ?? 0),
     (bool) array_filter(array_slice($mails, $mc), function ($m) use ($inv) { return strpos((string) $m['subject'], (string) ($inv['number'] ?? 'x')) !== false && !empty($m['attachments']); })]);
$pid = (string) ($t->d['charge']['payment_id'] ?? '');
$post('/public/trial/mollie-webhook', ['id' => $pid], null);
$eq('Webhook der Abbuchung (bezahlt) → Rechnung bezahlt, Abbuchung paid', [true, 'paid'], [!empty($T::get($tid)->d['invoices'][0]['paid_at']), $T::get($tid)->d['charge']['status'] ?? '']);
wp_set_current_user($adm);
$pr = rest_do_request(new WP_REST_Request('GET', "/tixomat/v1/trial/$tid/invoices/0/pdf"));
$eq('Studio: Rechnungs-PDF abrufbar', [200, '%PDF'], [$pr->get_status(), substr(base64_decode((string) ($pr->get_data()['data'] ?? '')), 0, 4)]);
wp_set_current_user(0);
$cu = $T::get($tid)->d['contract_until'] ?? '';
$eq('Vertrag: 1 Jahr ab Übergang, Server-Auftrag convert offen', [true, ['convert']], [abs(strtotime($cu) - strtotime('+1 year')) < 3 * DAY_IN_SECONDS, $T::get($tid)->d['server']['pending'] ?? []]);
[$st, $d] = $signed('GET', '/trial/queue', ['limit' => 10]);
$jc = null; foreach ((array) ($d['jobs'] ?? []) as $j) if (intval($j['trial_id']) === $tid) $jc = $j;
$eq('Auftrag convert: indexierbar, Kasse live erlaubt, Lizenz bis Vertragsende', ['convert', false, 'live_allowed', substr((new DateTimeImmutable($cu))->setTimezone(wp_timezone())->format('Y-m-d'), 0, 10)],
    [$jc['action'] ?? '', $jc['site']['noindex'] ?? null, $jc['checkout']['mode'] ?? '', (string) ($jc['license']['valid_until'] ?? '')]);
[$st] = $signed('POST', "/trial/$tid/done", [], wp_json_encode(['job_id' => $jc['job_id'] ?? '', 'ok' => true]));
$eq('done convert → erledigt', [200, []], [$st, $T::get($tid)->d['server']['pending'] ?? []]);
$T::contract_tick($T::get($tid), strtotime($cu) + 60);
$cu2 = $T::get($tid)->d['contract_until'] ?? '';
$eq('Ablauf ohne Kündigung → +1 Jahr, zweite Rechnung', [true, 'converted', 2], [abs(strtotime($cu2) - strtotime($cu . ' +1 year')) < 2 * DAY_IN_SECONDS, $T::get($tid)->status, count($T::get($tid)->d['invoices'] ?? [])]);
// W2b: Kunde kündigt den Vertrag über denselben Link
[$st, $d] = $post('/public/trial/cancel', ['token' => $ctok], null);
$eq('Kündigen-Link nach dem Übergang kündigt den Vertrag (Frist ok) → contract_end = Vertragsende', [200, $cu2], [$st, $d['contract_end'] ?? '']);
[$st2, $d2] = $post('/public/trial/cancel', ['token' => $ctok], null);
$eq('Zweiter Klick: gleich, keine zweite Kündigung', [200, $cu2], [$st2, $d2['contract_end'] ?? '']);
$T::contract_tick($T::get($tid), strtotime($cu2) + 60);
$eq('Ende eines gekündigten Vertrags → cancelled, Löschauftrag sofort', ['cancelled', true], [$T::get($tid)->status, (new DateTimeImmutable($T::get($tid)->delete_at, wp_timezone()))->getTimestamp() <= time() + 60]);
update_option('tix_settings', $set_bak); delete_option('tix_trial_invoice'); update_option('tix_trial_auto_charge', '0', false);
// Frist vorbei: dritte Testphase (Vertrag läuft in 2 Wochen ab) – „Jetzt starten“ und Fristprüfung
$wpdb->insert(TIX_Trial::t(), ['kurz' => "tr-$sfx", 'status' => 'active', 'email' => "w2c-$sfx@firma-test.de", 'email_hash' => hash('sha256', "w2c-$sfx@firma-test.de"), 'company' => 'Drei GmbH', 'company_key' => 'drei' . $sfx,
    'data' => wp_json_encode(['contact' => ['name' => 'Ida'], 'tier' => $tier, 'cancel_token' => 'stok' . $sfx]), 'cancel_token_hash' => hash('sha256', 'stok' . $sfx),
    'active_at' => current_time('mysql'), 'ends_at' => wp_date('Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS), 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
$t3 = intval($wpdb->insert_id);
[$st, $d] = $post('/public/trial/start', ['token' => 'stok' . $sfx], null);
$eq('„Jetzt starten“ → Vertrag beginnt sofort (converted, Auftrag convert)', [200, 'converted', ['convert']], [$st, $d['status'] ?? '', $T::get($t3)->d['server']['pending'] ?? []]);
$d3 = $T::get($t3)->d; $d3['contract_until'] = wp_date('c', time() + 14 * DAY_IN_SECONDS); $wpdb->update(TIX_Trial::t(), ['data' => wp_json_encode($d3)], ['id' => $t3]);
[$st, $d] = $post('/public/trial/cancel', ['token' => 'stok' . $sfx], null);
$eq('Kündigung weniger als 1 Monat vor Ablauf → 409 tix_trial_notice', [409, 'tix_trial_notice'], [$st, $d['code'] ?? '']);
$wpdb->delete(TIX_Trial::t(), ['id' => $t3]);
// ═══ W2c: Lizenzprüfung meldet mehr Tickets → sofort hochstufen ═══
$tiers_c = TIX_Platform_Offers::catalog()['shop_tiers']; usort($tiers_c, function ($a, $b) { return $a['max'] <=> $b['max']; });
if (count($tiers_c) >= 2) {
    $low = intval($tiers_c[0]['max']); $high = intval($tiers_c[1]['max']); $top = intval(end($tiers_c)['max']);
    $wpdb->insert(TIX_Trial::t(), ['kurz' => "tu-$sfx", 'status' => 'converted', 'email' => "w2u-$sfx@firma-test.de", 'email_hash' => hash('sha256', "w2u-$sfx@firma-test.de"), 'company' => 'Vier GmbH', 'company_key' => 'vier' . $sfx,
        'data' => wp_json_encode(['contact' => ['name' => 'Uli'], 'tier' => $low, 'address' => ['street' => 'X 1', 'zip' => '50667', 'city' => 'Köln', 'country' => 'DE'], 'contract_until' => wp_date('c', time() + 182 * DAY_IN_SECONDS)]),
        'mollie_customer_id' => 'cst_t1', 'mollie_mandate_id' => 'mdt_t1', 'mandate_status' => 'valid', 'license_id' => 987654, 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
    $t4 = intval($wpdb->insert_id);
    update_option('tix_trial_invoice', '1', false); update_option('tix_trial_upgrade_charge', '1', false);
    $set = is_array($set_bak) ? $set_bak : []; foreach (['invoice_company_name' => 'evendis Test GmbH', 'invoice_company_address' => "Teststr. 1\n50667 Köln", 'invoice_company_ust_id' => 'DE123456789'] as $k => $v) if (trim((string) ($set[$k] ?? '')) === '') $set[$k] = $v; update_option('tix_settings', $set);
    $mc = count($mails); $molc = count($mol);
    $rq = new WP_REST_Request('POST', '/tixomat/v1/license/check'); $rq->set_param('tickets_count', (string) ($low + 1)); $rq->set_param('tickets_since', wp_date('Y-m-d', time() - 183 * DAY_IN_SECONDS));
    do_action('tix_license_checked', (object) ['id' => 987654], $rq);
    $u4 = $T::get($t4)->d;
    $eq('Lizenzprüfung meldet ' . ($low + 1) . ' Tickets → sofort Stufe ' . $high . ', Differenz anteilig, Rechnung + Abbuchung (Schalter an), Mails an Kunde und Vertrieb',
        [$high, $low + 1, true, true, true, true, true],
        [intval($u4['tier'] ?? 0), intval($u4['usage']['tickets'] ?? 0), ($u4['upgrades'][0]['amount_net'] ?? 0) > 0, (bool) preg_match('/^RV-/', (string) ($u4['upgrades'][0]['invoice'] ?? '')), in_array('POST /payments', array_slice($mol, $molc), true),
         (bool) array_filter(array_slice($mails, $mc), function ($m) { return strpos((string) $m['subject'], 'hochgestuft') !== false && $m['to'] !== 'vertrieb-test@example.org'; }),
         (bool) array_filter(array_slice($mails, $mc), function ($m) { return $m['to'] === 'vertrieb-test@example.org' || (is_array($m['to']) && in_array('vertrieb-test@example.org', $m['to'], true)); })]);
    do_action('tix_license_checked', (object) ['id' => 987654], $rq);
    $eq('Gleiche Meldung noch einmal → kein zweites Hochstufen', 1, count($T::get($t4)->d['upgrades'] ?? []));
    $rq->set_param('tickets_count', (string) ($top + 1));
    $mc = count($mails);
    do_action('tix_license_checked', (object) ['id' => 987654], $rq);
    do_action('tix_license_checked', (object) ['id' => 987654], $rq);
    $eq('Über der größten Stufe → „auf Anfrage“ einmal gemeldet, Stufe bleibt', [true, $high, 1], [!empty($T::get($t4)->d['usage_on_request']), intval($T::get($t4)->d['tier']), count(array_filter(array_slice($mails, $mc), function ($m) { return strpos((string) $m['subject'], 'Mehr Tickets') !== false; }))]);
    update_option('tix_trial_upgrade_charge', '0', false); delete_option('tix_trial_invoice'); update_option('tix_settings', $set_bak);
    $wpdb->delete(TIX_Trial::t(), ['id' => $t4]);
}

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

// ═══ 1.38.853: Mollie-Schlüssel der Testphase – Studio-Feld (nur schreiben) und Rückfall nur auf live_-Schlüssel des Ticketverkaufs ═══
$kb = get_option('tix_trial_mollie_key', null); $sb = get_option('tix_settings', []);
delete_option('tix_trial_mollie_key');
$ss = is_array($sb) ? $sb : [];
update_option('tix_settings', array_merge($ss, ['mollie_api_key' => 'test_ticketkasse1234567890abcdefgh']));
$eq('Ticketverkauf mit test_-Schlüssel → kein Rückfall (keine Test-Kasse für echte Kunden)', ['none', false, 'test'], [$T::mollie_source()['source'], $T::mollie_status()['set'], $T::mollie_status()['ticket_key']]);
update_option('tix_settings', array_merge($ss, ['mollie_api_key' => 'live_ticketkasse1234567890abcdefgh']));
$eq('Ticketverkauf mit live_-Schlüssel → Rückfall, Anzeige ohne Schlüssel', ['ticket', true, 'live', 'efgh', false], [$T::mollie_source()['source'], $T::mollie_status()['set'], $T::mollie_status()['mode'], $T::mollie_status()['ends_with'], strpos(wp_json_encode($T::mollie_status()), 'live_ticketkasse') !== false]);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tix\\_app\\_rl\\_trial\\_mollie%'"); wp_cache_flush();
$mv = function ($pre, $args, $url) { if (strpos($url, 'https://api.mollie.com/v2/methods') !== 0) return $pre; $ok = strpos((string) ($args['headers']['Authorization'] ?? ''), 'gutergutergutergutergut') !== false;
    return ['headers' => [], 'body' => '{}', 'response' => ['code' => $ok ? 200 : 401, 'message' => ''], 'cookies' => [], 'filename' => null]; };
add_filter("pre_http_request", $mv, PHP_INT_MAX, 3); // nach dem allgemeinen Mollie-Mock (gleiche Priorität, später angemeldet)
$ks = function ($b) { $x = new WP_REST_Request('POST', '/tixomat/v1/platform/trial-settings'); $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); $r = rest_do_request($x); return [$r->get_status(), $r->get_data()]; };
wp_set_current_user(0);
[$st] = $ks(['mollie_key' => 'live_gutergutergutergutergut']);
$eq('Ohne Anmeldung → 401/403', true, in_array($st, [401, 403], true));
wp_set_current_user($adm);
[$st, $d] = $ks(['mollie_key' => 'falsch']);
[$st2, $d2] = $ks(['mollie_key' => 'live_unbekanntunbekanntunbekannt']);
$eq('Format falsch → 400; Mollie kennt ihn nicht → 400 tix_trial_mollie_invalid; nichts gespeichert', [400, 400, 'tix_trial_mollie_invalid', ''], [$st, $st2, $d2['code'] ?? '', (string) get_option('tix_trial_mollie_key', '')]);
[$st, $d] = $ks(['mollie_key' => 'live_gutergutergutergutergut']);
$eq('Gültiger Schlüssel: gespeichert (vor dem Rückfall), Antwort nur gesetzt · live · endet auf', [200, true, 'own', 'live', 'rgut', false], [$st, $d['mollie']['set'] ?? null, $d['mollie']['source'] ?? '', $d['mollie']['mode'] ?? '', $d['mollie']['ends_with'] ?? '', strpos(wp_json_encode($d), 'gutergutergut') !== false]);
$g = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/platform/trial-settings'))->get_data();
$eq('GET zeigt nie den Schlüssel; Prüfzeit gesetzt', [false, true], [strpos(wp_json_encode($g), 'gutergutergut') !== false, !empty($g['mollie']['checked_at'])]);
[$st, $d] = $ks(['mollie_key' => '']);
$eq('Leer → entfernt, zurück auf Rückfall (live-Ticketkasse)', [200, 'ticket'], [$st, $d['mollie']['source'] ?? '']);
remove_filter("pre_http_request", $mv, PHP_INT_MAX);
if ($kb === null) delete_option('tix_trial_mollie_key'); else update_option('tix_trial_mollie_key', $kb, false);
update_option('tix_settings', $sb);
wp_set_current_user(0);

// ═══ 1.38.856: „Neu versuchen“ gibt eine neue job_id aus (Kunden-Dienst zählt Versuche je job_id) ═══
$eq('job_seq: zählt weiter, Altbestand über den Verlauf hinaus, nie unter job.n', [4, 2, 6],
    [$T::next_job_seq(['job_seq' => 3]), $T::next_job_seq(['history' => [['to' => 'queued'], ['to' => 'provisioning']]]), $T::next_job_seq(['job_seq' => 2], ['n' => 5])]);
$wpdb->insert(TIX_Trial::t(), ['kurz' => "tf-$sfx", 'status' => 'queued', 'email' => "w2f-$sfx@firma-test.de", 'email_hash' => hash('sha256', "w2f-$sfx@firma-test.de"), 'company' => 'Fünf GmbH', 'company_key' => 'fuenf' . $sfx,
    'data' => wp_json_encode(['contact' => ['name' => 'Fe'], 'shop_name' => 'Fünf', 'color' => '#FC2F55', 'logo_url' => '', 'address' => ['street' => 'A 1', 'zip' => '50667', 'city' => 'Köln', 'country' => 'DE'], 'vat_id' => '', 'tier' => $tier]),
    'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
$t5 = intval($wpdb->insert_id);
$pick = function ($d) use ($t5) { foreach ((array) ($d['jobs'] ?? []) as $j) if (intval($j['trial_id']) === $t5) return $j; return null; };
[, $d] = $signed('GET', '/trial/queue', ['limit' => 10]); $j1 = $pick($d);
[$st] = $signed('POST', "/trial/$t5/done", [], wp_json_encode(['job_id' => $j1['job_id'] ?? '', 'ok' => false, 'error' => 'technik']));
wp_set_current_user($adm);
[$st2] = $post("/trial/$t5/retry", [], null);
wp_set_current_user(0);
[, $d] = $signed('GET', '/trial/queue', ['limit' => 10]); $j2b = $pick($d);
[$st3] = $signed('POST', "/trial/$t5/done", [], wp_json_encode(['job_id' => $j1['job_id'] ?? '', 'ok' => true]));
$eq('Fehlschlag → Neu versuchen → neue job_id (…-create-1 → …-create-2), alte job_id danach 409', ["trl-$t5-create-1", 200, 200, "trl-$t5-create-2", 409, 'provisioning'],
    [$j1['job_id'] ?? '', $st, $st2, $j2b['job_id'] ?? '', $st3, $T::get($t5)->status]);
$lic5 = intval($T::get($t5)->license_id);
$wpdb->delete(TIX_Trial::t(), ['id' => $t5]); if ($lic5) $wpdb->delete($wpdb->prefix . 'tix_licenses', ['id' => $lic5]);

// ═══ Aufräumen ═══
remove_filter('pre_wp_mail', $cap, 1); remove_filter('pre_http_request', $mock, PHP_INT_MAX);
$lic = intval($T::get($tid)->license_id);
$wpdb->query($wpdb->prepare('DELETE FROM ' . TIX_Trial::t() . ' WHERE id IN (%d, %d)', $tid, $t2));
if ($lic) $wpdb->delete($wpdb->prefix . 'tix_licenses', ['id' => $lic]);
$wpdb->query("DELETE FROM " . TIX_Trial::t('tix_trial_requests') . " WHERE data LIKE '%" . esc_sql($sfx) . "%'");
foreach ($bak as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
