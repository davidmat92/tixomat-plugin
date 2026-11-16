<?php
/**
 * Integrationstest Demo-Shop „Eigene Website“ (TIX_Demo_Shop, 1.38.841) gegen lokale Test-WordPress-Seiten.
 * NIE auf einer Live-Seite: setzt die Instanz zurück (löscht ALLE Events, Bestellungen, Tickets) – vorher DB sichern
 * (tests/run-demo-shop.sh macht das). Phasen über TIX_T_PHASE:
 *   off  – Einzel-Seite ohne Schalter: alles wie bisher
 *   on   – Einzel-Seite mit tix_demo_instance=1: Zahlung, Mails, Abschottung, Zugänge, Kasse, Seed/Reset
 *   plat – Mehr-Veranstalter-Seite mit Schalter: wirkt nicht; Lizenz-Art intern/Demo
 *
 *   TIX_T_PHASE=on wp eval-file wp-content/plugins/tixomat/tests/test-demo-shop-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Demo_Shop')) { echo "WordPress mit Tixomat nötig\n"; return; }
$host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($host, '.') !== false && !preg_match('/\.(test|localhost)$/', $host)) { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$phase = getenv('TIX_T_PHASE') ?: 'on';

$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0; $GLOBALS['t_mails'] = [];
function t_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
// Nur Mails festhalten, die wirklich rausgingen (kein früheres pre_wp_mail hat sie abgefangen)
add_filter('pre_wp_mail', function ($null, $atts) { if ($null === null) $GLOBALS['t_mails'][] = $atts; return true; }, 3, 2);
function t_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $res = apply_filters('rest_post_dispatch', rest_ensure_response($res), rest_get_server(), $r);
    return [$res->get_status(), $res->get_data()];
}
function t_code($d) { return is_array($d) ? (string) ($d['code'] ?? '') : ''; }
function t_field($d) { return is_array($d) ? (string) ($d['data']['field'] ?? '') : ''; }
function t_mail_to($addr) { foreach ($GLOBALS['t_mails'] as $m) { $to = (array) $m['to']; foreach ($to as $t) if (stripos((string) $t, $addr) !== false) return $m; } return null; }
function t_headers($m) { return implode("\n", (array) ($m['headers'] ?? [])); }

$admin = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0]);
$sfx = wp_rand(10000, 99999);
$_SERVER['REMOTE_ADDR'] = '10.9.' . wp_rand(1, 250) . '.' . wp_rand(1, 250);
$settings_before = get_option('tix_settings', []);
$cleanup_users = [];
global $wpdb;

// ═══════════════════════════════ Phase OFF ═══════════════════════════════
if ($phase === 'off') {
    update_option('tix_demo_instance', '0');
    t_eq('Schalter aus → on() false', false, TIX_Demo_Shop::on());
    // Einstellungen unverändert (Live-Schlüssel bleiben stehen)
    $s = is_array($settings_before) ? $settings_before : [];
    $s['mollie_api_key'] = 'live_' . str_repeat('a', 30);
    $s['stripe_secret_key_live'] = 'sk_live_abc';
    update_option('tix_settings', $s);
    $g = get_option('tix_settings');
    t_eq('ohne Schalter: Live-Schlüssel unverändert', ['live_' . str_repeat('a', 30), 'sk_live_abc'], [$g['mollie_api_key'] ?? '', $g['stripe_secret_key_live'] ?? '']);
    update_option('tix_settings', $settings_before);
    wp_set_current_user($admin);
    list($st, $me) = t_rest('GET', '/me');
    t_eq('/me ohne demo_instance', false, array_key_exists('demo_instance', (array) $me));
    list($st, $inf) = t_rest('GET', '/info');
    t_eq('/info ohne demo_instance', false, array_key_exists('demo_instance', (array) $inf));
    list($st, $cfg) = t_rest('GET', '/studio/config');
    t_eq('/studio/config ohne demo_instance', false, array_key_exists('demo_instance', (array) $cfg));
    list($st, $d) = t_rest('GET', '/demo-access');
    t_eq('Demo-Zugänge auf Einzel-Seite ohne Schalter → 404', 404, $st);
    list($st, $d) = t_rest('POST', '/public/demo-access/link', ['email' => 'x@example.org']);
    t_eq('öffentlicher Link ohne Schalter → 404', 404, $st);
    // Mail an fremde Adresse geht wie bisher raus, kein Reply-To vom Vertrieb
    $GLOBALS['t_mails'] = [];
    wp_mail("fremd$sfx@example.org", 'Test', 'Hallo');
    $m = t_mail_to("fremd$sfx@example.org");
    t_eq('ohne Schalter: Mail an fremde Adresse geht raus', true, $m !== null);
    t_eq('ohne Schalter: kein Reply-To vertrieb@', false, $m && stripos(t_headers($m), 'vertrieb@evendis.de') !== false);
    t_eq('ohne Schalter: Absender-Filter unverändert', 'a@b.example', TIX_Demo_Shop::mail_from('a@b.example'));
    t_eq('ohne Schalter: Zahlart-Prüfung neutral', null, TIX_Demo_Shop::payment_error('stripe:card'));
    $ev = get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
    if ($ev) {
        t_eq('ohne Schalter: Event kein Demo-Event', false, TIX_Demo_Access::is_demo_event($ev[0]));
        t_eq('ohne Schalter: Event keine Sandbox', false, TIX_Sales::is_sandbox_event($ev[0]));
    }
    // Nicht-Admin mit Veranstalter-Rolle: Zahlungen wie bisher erreichbar (perm_brand)
    $org = wp_insert_user(['user_login' => "orgt$sfx", 'user_email' => "orgt$sfx@example.org", 'user_pass' => wp_generate_password(20), 'role' => 'tix_organizer']);
    $cleanup_users[] = $org;
    wp_set_current_user($org);
    list($st, $d) = t_rest('GET', '/studio/payments');
    t_eq('ohne Schalter: keine Demo-Sperre für Veranstalter', false, t_code($d) === 'tix_demo_forbidden');
    $r = TIX_Demo_Shop::run('reset');
    t_eq('Reset ohne Schalter verweigert (nichts geändert)', [false, 'tix_demo_off'], [$r['ok'], $r['error'] ?? '']);
}

// ═══════════════════════════════ Phase PLAT ═══════════════════════════════
if ($phase === 'plat') {
    update_option('tix_demo_instance', '1');
    t_eq('Mehr-Veranstalter-Modus', true, TIX_Sales::multi());
    t_eq('Plattform: Schalter wirkt nicht', false, TIX_Demo_Shop::on());
    wp_set_current_user($admin);
    list($st, $me) = t_rest('GET', '/me');
    t_eq('Plattform: /me ohne demo_instance', false, array_key_exists('demo_instance', (array) $me));
    $r = TIX_Demo_Shop::run('reset');
    t_eq('Plattform: Reset verweigert', [false, 'tix_demo_off'], [$r['ok'], $r['error'] ?? '']);
    t_eq('Plattform: Meldung nennt den Grund', true, strpos((string) ($r['message'] ?? ''), 'Mehr-Veranstalter') !== false);
    delete_option('tix_demo_instance');
    // Lizenz-Art intern/Demo
    $had = get_option('tix_license_server', '0');
    update_option('tix_license_server', '1');
    TIX_License_Server::maybe_install();
    $lic = TIX_License_Server::create(['customer' => "Demo-Shop Test $sfx", 'email' => 'vertrieb@evendis.de', 'kind' => 'demo', 'valid_until' => '2027-01-01', 'enforce' => true,
        'note' => "Host demo-shop$sfx.evendis.de [kunden-hetzner:demo$sfx]"]);
    t_eq('Demo-Lizenz angelegt', true, (bool) $lic);
    t_eq('Demo-Lizenz: Art demo, kein Laufzeitende, nicht durchgesetzt', ['demo', null, 0], [(string) $lic->kind, $lic->valid_until, intval($lic->enforce)]);
    $p = TIX_License_Server::admin_payload($lic);
    t_eq('admin_payload kind demo', ['demo', 'intern/Demo'], [$p['kind'], $p['kind_label']]);
    t_eq('Stufe aktiv ohne Ablauf', 'active', TIX_License_Server::stage_for($lic));
    $req = new WP_REST_Request('POST', '/tixomat/v1/license/admin/licenses/' . $lic->id);
    $req->set_url_params(['id' => $lic->id]);
    $req->set_header('content-type', 'application/json');
    $req->set_body(wp_json_encode(['valid_until' => '2027-05-05', 'enforce' => true]));
    $res = TIX_License_Server::rest_admin_save($req);
    $l2 = TIX_License_Server::get($lic->id);
    t_eq('Demo-Lizenz bleibt ohne Laufzeitende', [null, 0], [$l2->valid_until, intval($l2->enforce)]);
    $req->set_body(wp_json_encode(['kind' => 'quatsch']));
    $res = TIX_License_Server::rest_admin_save($req);
    t_eq('unbekannte Art → 400', 'tix_invalid_field', is_wp_error($res) ? $res->get_error_code() : '');
    if (class_exists('TIX_Platform_Customers')) {
        TIX_Platform_Customers::flush();
        list($st, $d) = t_rest('GET', '/platform/customers', ['art' => 'demo']);
        $mine = array_values(array_filter((array) ($d['customers'] ?? []), function ($c) use ($lic) { return intval($c['license_id'] ?? 0) === intval($lic->id); }));
        t_eq('Plattform-Akte: Art demo', 'demo', $mine[0]['art'] ?? '');
        t_eq('Zähler demo vorhanden', true, intval($d['counts']['demo'] ?? 0) >= 1);
        list($st, $all) = t_rest('GET', '/platform/customers');
        $n_listed = count(array_filter((array) ($all['customers'] ?? []), function ($c) { return ($c['art'] ?? '') !== 'demo'; }));
        t_eq('„alle“ zählt den Demo-Shop nicht', $n_listed, intval($all['counts']['all'] ?? -1));
    }
    $wpdb->delete($wpdb->prefix . 'tix_licenses', ['id' => $lic->id]);
    $wpdb->delete($wpdb->prefix . 'tix_license_log', ['license_id' => $lic->id]);
    update_option('tix_license_server', $had);
}

// ═══════════════════════════════ Phase ON ═══════════════════════════════
if ($phase === 'on') {
    update_option('tix_demo_instance', '1');
    delete_option(TIX_Demo_Shop::OPT_MAIL_LOG);
    delete_transient('tix_demo_reset_rl');
    t_eq('Schalter an → on() true', true, TIX_Demo_Shop::on());
    t_eq('fremder Host nie Demo', false, TIX_Demo_Shop::host_ok('kunde-beispiel.de'));
    t_eq('demo-shop.evendis.de ist Demo-Host', true, TIX_Demo_Shop::host_ok('demo-shop.evendis.de'));

    // ── 1. Nie echtes Geld ──
    $s = is_array($settings_before) ? $settings_before : [];
    $s['mollie_api_key'] = 'live_' . str_repeat('a', 30);
    $s['stripe_secret_key_live'] = 'sk_live_abc'; $s['stripe_publishable_key_live'] = 'pk_live_abc';
    $s['stripe_test_mode'] = 0; $s['paypal_sandbox'] = 0; $s['bank_transfer_enabled'] = 1; $s['bank_iban'] = 'DE89370400440532013000';
    update_option('tix_settings', $s);
    $raw = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'tix_settings'");
    $g = get_option('tix_settings');
    t_eq('Live-Schlüssel gar nicht erst gespeichert', false, strpos((string) $raw, 'live_aaaa') !== false || strpos((string) $raw, 'sk_live_abc') !== false);
    t_eq('gelesen: Mollie live leer, Stripe live leer', ['', ''], [$g['mollie_api_key'] ?? '', $g['stripe_secret_key_live'] ?? '']);
    t_eq('gelesen: Testmodus/Sandbox fest, Überweisung aus', [1, 1, 0], [intval($g['stripe_test_mode']), intval($g['paypal_sandbox']), intval($g['bank_transfer_enabled'])]);
    // Schon vorher (ohne Schalter) gespeicherte Live-Schlüssel: beim Lesen entfernt
    $wpdb->update($wpdb->options, ['option_value' => maybe_serialize(['mollie_api_key' => 'live_' . str_repeat('b', 30), 'stripe_secret_key_live' => 'sk_live_x', 'stripe_enabled' => 1])], ['option_name' => 'tix_settings']);
    wp_cache_delete('tix_settings', 'options'); wp_cache_delete('alloptions', 'options');
    $g = get_option('tix_settings');
    t_eq('alte Live-Schlüssel in der DB werden beim Lesen entfernt', ['', ''], [$g['mollie_api_key'] ?? '', $g['stripe_secret_key_live'] ?? '']);
    t_eq('Mollie mit Live-Schlüssel nicht verfügbar', false, TIX_Demo_Shop::provider_test_ok('mollie'));
    t_eq('Zahlart stripe ohne Test-Schlüssel abgelehnt', 'tix_demo_test_only', ($e = TIX_Demo_Shop::payment_error('stripe:card')) ? $e->get_error_code() : '');
    t_eq('Zahlart bank abgelehnt', 'tix_demo_test_only', ($e = TIX_Demo_Shop::payment_error('bank')) ? $e->get_error_code() : '');
    t_eq('Zahlart demo erlaubt', null, TIX_Demo_Shop::payment_error('demo'));
    update_option('tix_settings', ['mollie_api_key' => 'test_' . str_repeat('c', 30), 'stripe_secret_key_test' => 'sk_test_abc', 'stripe_test_mode' => 1]);
    t_eq('Mollie-Test-Schlüssel erlaubt', null, TIX_Demo_Shop::payment_error('mollie:ideal'));
    t_eq('Stripe-Test-Schlüssel erlaubt', null, TIX_Demo_Shop::payment_error('stripe:card'));
    update_option('tix_settings', $settings_before ?: []);
    wp_set_current_user($admin);
    foreach ([
        [['mollie' => ['api_key' => 'live_' . str_repeat('d', 30)]], 'mollie.api_key'],
        [['stripe' => ['secret_live' => 'sk_live_zzz']], 'stripe.secret_live'],
        [['stripe' => ['publishable_live' => 'pk_live_zzz']], 'stripe.publishable_live'],
        [['stripe' => ['secret_test' => 'sk_live_zzz']], 'stripe.secret_test'],
        [['stripe' => ['test_mode' => false]], 'stripe.test_mode'],
        [['paypal' => ['sandbox' => false]], 'paypal.sandbox'],
        [['bank' => ['enabled' => true]], 'bank.enabled'],
    ] as $c) {
        list($st, $d) = t_rest('POST', '/studio/payments', $c[0]);
        t_eq('Admin speichert ' . $c[1] . ' → 400 tix_demo_live_key', [400, 'tix_demo_live_key', $c[1]], [$st, t_code($d), t_field($d)]);
    }
    list($st, $d) = t_rest('POST', '/studio/payments', ['stripe' => ['test_mode' => true]]);
    t_eq('Admin: Testmodus speichern geht', 200, $st);

    // ── 2. Kennzeichen ──
    list($st, $me) = t_rest('GET', '/me');
    t_eq('/me demo_instance', true, $me['demo_instance'] ?? null);
    t_eq('/me demo_shop.studio', 'https://studio.' . $host . '/', $me['demo_shop']['studio'] ?? '');
    list($st, $inf) = t_rest('GET', '/info');
    t_eq('/info demo_instance (Wache)', true, $inf['demo_instance'] ?? null);
    wp_set_current_user(0);
    list($st, $cfg) = t_rest('GET', '/studio/config');
    t_eq('/studio/config öffentlich mit demo_instance', true, $cfg['demo_instance'] ?? null);
    wp_set_current_user($admin);

    // ── 3. Zugänge ──
    $GLOBALS['t_mails'] = [];
    list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'sales', 'name' => "Vera Vertrieb $sfx", 'email' => "vera$sfx@example.org"]);
    t_eq('Admin legt Vertriebler an (201)', 201, $st);
    $sales = intval($d['access']['id'] ?? 0); $cleanup_users[] = $sales;
    t_eq('kein Beispiel-Club auf der Instanz angelegt', 0, TIX_Sales::demo_id());
    t_eq('Einladung an den Vertriebler geht raus (Zugang der Instanz)', true, t_mail_to("vera$sfx@example.org") !== null);
    list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'prospect', 'name' => "Paula Probe $sfx", 'email' => "paula$sfx@example.org", 'company' => 'Probe GmbH']);
    t_eq('Admin legt Interessent ohne Modell/Profil an (201)', 201, $st);
    $pa = intval($d['access']['id'] ?? 0); $cleanup_users[] = $pa;
    t_eq('Profil folgt der Vorlage', TIX_Demo_Shop::profile(), $d['access']['profile'] ?? '');
    t_eq('Interessent ohne Team-Veranstalter', '', (string) get_user_meta($pa, '_tix_team_organizer_id', true));
    $inv = t_mail_to("paula$sfx@example.org");
    t_eq('Einladung an den Interessenten', true, $inv !== null);
    t_eq('Einladung nennt den Demo-Shop und das Studio der Instanz', [true, true], [$inv && stripos((string) $inv['message'], 'Demo-Shop') !== false, $inv && stripos((string) $inv['message'], 'studio.' . $host) !== false]);
    t_eq('Reply-To Vertrieb', true, $inv && stripos(t_headers($inv), 'Reply-To: vertrieb@evendis.de') !== false);
    t_eq('Absender noreply@evendis.de', 'noreply@evendis.de', apply_filters('wp_mail_from', 'wordpress@' . $host));
    wp_set_current_user($sales);
    list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'prospect', 'name' => "Bernd Probe $sfx", 'email' => "bernd$sfx@example.org"]);
    t_eq('Vertriebler legt Interessent an (201)', 201, $st);
    $pb = intval($d['access']['id'] ?? 0); $cleanup_users[] = $pb;
    list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'sales', 'name' => 'X', 'email' => "x$sfx@example.org"]);
    t_eq('Vertriebler legt keine Vertriebler an (403)', 403, $st);
    list($st, $d) = t_rest('GET', '/demo-access');
    t_eq('Vertriebler sieht nur eigene Zugänge, Studio-Adresse der Instanz', [[$pb], 'https://studio.' . $host . '/'], [array_column($d['accesses'] ?? [], 'id'), $d['studio'] ?? '']);
    list($st, $me) = t_rest('GET', '/me');
    t_eq('/me Vertriebler: demo_access sales auf der Instanz', ['sales', true, true], [$me['demo_access']['kind'] ?? '', $me['demo_access']['instance'] ?? null, $me['demo_access']['can_reset'] ?? null]);

    // ── 4. Abschottung ──
    foreach ([$pa => 'Interessent', $sales => 'Vertriebler'] as $uid => $who) {
        wp_set_current_user($uid);
        foreach ([['GET', '/studio/payments'], ['POST', '/studio/payments'], ['GET', '/license/status'], ['GET', '/team'], ['POST', '/team'], ['GET', '/studio/domain'],
                  ['GET', '/studio/mail-transport'], ['GET', '/site/users'], ['POST', '/studio/legal'], ['POST', '/studio/fees'], ['GET', '/organizer/payout-details'], ['POST', '/marketing/newsletter/settings'], ['POST', '/marketing/newsletter/campaigns/7/send'], ['POST', '/marketing/newsletter/campaigns/7/test']] as $rq) {
            list($st, $d) = t_rest($rq[0], $rq[1], $rq[0] === 'POST' ? ['x' => 1] : []);
            t_eq("$who: {$rq[0]} {$rq[1]} gesperrt", [403, 'tix_demo_forbidden'], [$st, t_code($d)]);
        }
        list($st, $d) = t_rest('GET', '/studio/legal');
        t_eq("$who: Rechtstexte lesen nicht demo-gesperrt", false, t_code($d) === 'tix_demo_forbidden');
        list($st, $d) = t_rest('GET', '/me');
        t_eq("$who: /me geht", 200, $st);
        t_eq("$who: Newsletter-Entwurf nicht demo-gesperrt", false, TIX_Demo_Shop::blocked('/marketing/newsletter/campaigns/7', 'POST'));
    }
    wp_set_current_user($pa);
    list($st, $me) = t_rest('GET', '/me');
    t_eq('/me Interessent: prospect, kein Zurücksetzen', ['prospect', false, true], [$me['demo_access']['kind'] ?? '', $me['demo_access']['can_reset'] ?? null, $me['demo_instance'] ?? null]);
    list($st, $d) = t_rest('POST', '/demo-access/reset');
    t_eq('Interessent darf nicht zurücksetzen (403)', 403, $st);

    // ── 5. Seed / Reset ──
    wp_set_current_user($admin);
    $GLOBALS['t_mails'] = [];
    $r = TIX_Demo_Shop::run('reset', 'openair');
    t_eq('Seed/Reset verschickt keine Mails', 0, count($GLOBALS['t_mails']));
    t_eq('Reset openair ok', true, !empty($r['ok']));
    $titles = get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']);
    $titles = array_map('get_the_title', $titles);
    // Demo A (1.38.844): genau EIN Event – Festival Fr–So im Sommer 2027
    t_eq('openair: genau ein Event, das Festival', [1, [TIX_Demo_Content::OPENAIR_TITLE]], [count($titles), array_values($titles)]);
    t_eq('seeded.events = 1, kein today_event mehr', [1, false], [$r['seeded']['events'] ?? 0, array_key_exists('today_event', (array) $r['seeded'])]);
    $fe = intval($r['seeded']['scan_event'] ?? 0);
    t_eq('scan_event = das Festival', [true, TIX_Demo_Content::OPENAIR_TITLE], [$fe > 0, get_the_title($fe)]);
    t_eq('Termin Fr 9. bis So 11.07.2027 (mehrtägig mit Ende)', ['2027-07-09', '2027-07-11', '5', '2027-07-09'], [(string) get_post_meta($fe, '_tix_date_start', true), (string) get_post_meta($fe, '_tix_date_end', true), wp_date('w', strtotime('2027-07-09 12:00')), $r['seeded']['scan_event_date'] ?? '']);
    t_eq('Termin-Regel: nach dem Wochenende → dasselbe Wochenende 2028', ['2028-07-14', '2028-07-16'], TIX_Demo_Content::openair_dates(strtotime('2027-07-12 12:00:00')));
    $tt = (array) get_post_meta($fe, '_tix_timetable', true);
    $slots = array_merge(...array_values(array_map('array_values', $tt)));
    $stg = array_values(array_unique(array_map('intval', array_column($slots, 'stage')))); sort($stg);
    t_eq('Programm: drei Tage, drei Bühnen, 21 Acts mit Zeiten', [['2027-07-09', '2027-07-10', '2027-07-11'], [0, 1, 2], 21, true],
        [array_keys($tt), $stg, count($slots), !array_filter($slots, function ($x) { return empty($x['time']) || empty($x['end']); })]);
    t_eq('drei Bühnen angelegt', 3, count((array) get_post_meta($fe, '_tix_stages', true)));
    $heads = (array) get_post_meta($fe, '_tix_lineup_headliners', true);
    t_eq('drei Headliner (Stern), Line-up-Text mit Headlinern zuerst', [3, 0], [count($heads), strpos(trim(wp_strip_all_tags((string) get_post_meta($fe, '_tix_info_lineup', true))), 'Nordlicht Kollektiv')]);
    if (class_exists('TIX_Artists')) {
        $roles = array_count_values(array_column((array) get_post_meta($fe, TIX_Artists::EV_META, true), 'role'));
        t_eq('Künstler: 3 Headliner, 18 weitere', [3, 18], [$roles['headliner'] ?? 0, $roles['support'] ?? 0]);
    }
    $cn = array_column((array) get_post_meta($fe, '_tix_ticket_categories', true), 'name');
    t_eq('Kategorien: Wochenende, Tagestickets Fr/Sa/So, VIP, Camping, Parken', ['Wochenend-Ticket', 'Tagesticket Freitag', 'Tagesticket Samstag', 'Tagesticket Sonntag', 'VIP Wochenende', 'Camping-Ticket', 'Parkticket Wochenende'], $cn);
    // Mehr Inhalt (1.38.847): Gewinnspiel, Awareness, Barrierefreiheit, Specials, Dresscode, Tipps, Mitteilungen, Kacheln
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_pub%' OR option_name LIKE '\\_transient\\_timeout\\_%tix\\_pub%'");
    $fd = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/public/events/' . $fe))->get_data();
    $fd = $fd['event'] ?? $fd;
    t_eq('Festival: Gewinnspiel offen, Awareness-Team, Barrierefreiheit, Specials, Dresscode, Galerie ≥ 6', [true, true, true, true, true, true],
        [($fd['raffle']['status'] ?? '') === 'open', !empty($fd['awareness']['team']), count($fd['accessibility']['items'] ?? []) >= 3, (string) ($fd['specials'] ?? '') !== '', (string) ($fd['dresscode'] ?? '') !== '', count((array) ($fd['gallery'] ?? [])) >= 4]);
    t_eq('Festival: Tipps 3, Mitteilungen 3, Kacheln mit Line-up/Guide, Seiten faq/u16/guide/kontakt', [3, 3, true, ['faq', 'u16', 'guide', 'kontakt']],
        [$r['seeded']['tips'] ?? 0, $r['seeded']['notifications'] ?? 0, in_array('page:guide', array_column((array) (TIX_App_Config::stored()['home']['tiles'] ?? []), 'action'), true), array_keys((array) (TIX_App_Config::stored()['pages'] ?? []))]);
    $tips = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/public/tips'))->get_data();
    t_eq('/public/tips liefert die Demo-Tipps, einer mit dem Festival', [3, true], [count($tips['tips'] ?? []), in_array($fe, array_map('intval', array_column($tips['tips'] ?? [], 'event_id')), true)]);
    $nf = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/notifications'))->get_data();
    $dn = array_values(array_filter($nf['notifications'] ?? [], function ($x) { return strpos($x['id'], 'b_demo_') === 0; }));
    t_eq('/notifications: 3 Demo-Mitteilungen, verlinkt aufs Festival', [3, 'event:' . $fe], [count($dn), $dn[0]['action'] ?? '']);
    t_eq('Festival-Bild = KI-Beispielbild (Band)', [true, TIX_Demo_Content::ki_image('band')], [(bool) get_post_meta(get_post_thumbnail_id($fe), '_tix_demo_ki', true), get_post_thumbnail_id($fe)]);
    t_eq('Festival-Guide: Anreise/Camping, Einlass-Regeln, FAQ, Galerie', [true, true, true, true], [strpos((string) get_post_meta($fe, '_tix_info_extra_info', true), 'Camping') !== false, (string) get_post_meta($fe, '_tix_info_entry_rules', true) !== '', count((array) get_post_meta($fe, '_tix_faq', true)) >= 5, count((array) get_post_meta($fe, '_tix_gallery', true)) >= 3]);
    t_eq('Gästeliste', 10, count((array) get_post_meta($fe, '_tix_guest_list', true)));
    t_eq('rund 50 Bestellungen', true, ($r['seeded']['orders'] ?? 0) >= 40 && ($r['seeded']['orders'] ?? 0) <= 60);
    t_eq('alle Seed-Adressen @demo.invalid', 0, intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tix_orders WHERE billing_email NOT LIKE '%@demo.invalid'")));
    // Einlass-Ausnahme: das Festival (2027) steht auf der Instanz in „heute“ und ist scannbar
    wp_set_current_user($admin);
    list($st, $d) = t_rest('GET', '/events', ['filter' => 'today']);
    t_eq('/events?filter=today enthält das scannbare Festival', true, in_array($fe, array_map('intval', array_column((array) ($d['events'] ?? []), 'id')), true));
    list($st, $d) = t_rest('GET', '/events', ['filter' => 'today', 'search' => 'gibtesnicht' . $sfx]);
    t_eq('… nicht bei einer Suche', false, in_array($fe, array_map('intval', array_column((array) ($d['events'] ?? []), 'id')), true));
    list($st, $me) = t_rest('GET', '/me');
    t_eq('/me demo_shop.scan_event {id, date_start, date_end}', [$fe, '2027-07-09', '2027-07-11'], [intval($me['demo_shop']['scan_event']['id'] ?? 0), $me['demo_shop']['scan_event']['date_start'] ?? '', $me['demo_shop']['scan_event']['date_end'] ?? '']);
    $tk = get_posts(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'posts_per_page' => 1, 'meta_query' => [['key' => '_tix_ticket_event_id', 'value' => (string) $fe]]]);
    $code = $tk ? (string) get_post_meta($tk[0]->ID, '_tix_ticket_code', true) : '';
    list($st, $d) = t_rest('POST', '/checkin/scan', ['code' => $code, 'event_id' => $fe]);
    t_eq('Ticket des Festivals vor dem Termin einchecken → ok', [200, 'ok'], [$st, $d['status'] ?? '']);
    t_eq('Gutscheine', 3, $r['seeded']['giftcards'] ?? 0);
    t_eq('Sponsoren', function_exists('imagecreatetruecolor') ? 4 : 0, $r['seeded']['sponsors'] ?? -1);
    $bad = 0;
    foreach (get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1]) as $p) {
        $txt = mb_strtolower($p->post_title . ' ' . $p->post_excerpt . ' ' . get_post_meta($p->ID, '_tix_info_description', true) . ' ' . get_post_meta($p->ID, '_tix_info_lineup', true) . ' ' . get_post_meta($p->ID, '_tix_location', true));
        foreach (array_merge(TIX_Demo_Content::FORBIDDEN, ['schwelm']) as $w) if (strpos($txt, $w) !== false) $bad++;
    }
    $more = mb_strtolower(wp_json_encode([TIX_Demo_Content::tips('club'), TIX_Demo_Content::tips('openair'), TIX_Demo_Content::notifications('club'), TIX_Demo_Content::notifications('openair'),
        TIX_Demo_Content::instance_content('club'), TIX_Demo_Content::instance_content('openair'), TIX_Demo_Content::club_more(), TIX_Demo_Content::club_extras(), TIX_Demo_Content::openair_festival(), TIX_Demo_Content::awareness('club')], JSON_UNESCAPED_UNICODE), 'UTF-8');
    foreach (array_merge(TIX_Demo_Content::FORBIDDEN, ['schwelm', 'tixomat', 'luisa']) as $w) if (strpos($more, $w) !== false) $bad++;
    t_eq('keine geschützten/echten Namen (auch nicht Schwelm, Tixomat) – Events, Tipps, Mitteilungen, Seiten', 0, $bad);
    $imgs = array_filter(array_map('get_post_thumbnail_id', get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids'])));
    t_eq('Bilder nur lizenzierte Demo-Fotos', true, $imgs && !array_filter($imgs, function ($a) { return !get_post_meta($a, '_tix_demo_image', true); }));
    // Demo-Instanz: Event- und Galeriebilder nur KI-Beispielbilder, nie die Adobe-Fotos (nur für die App lizenziert, User 15.11.)
    $all_imgs = $imgs;
    foreach (get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']) as $gid) $all_imgs = array_merge($all_imgs, array_map('intval', (array) get_post_meta($gid, '_tix_gallery', true)));
    t_eq('Demo-Instanz: Event- und Galeriebilder nur KI-Beispielbilder', [true, 0], [(bool) $all_imgs, count(array_filter(array_unique($all_imgs), function ($a) { return !get_post_meta($a, '_tix_demo_ki', true); }))]);
    $n_orders = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tix_orders"));
    $n_events = count(get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']));
    $n_media = count(get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids']));
    $r2 = TIX_Demo_Shop::run('reset');
    t_eq('Reset legt keine neuen Bilder an (KI-Bilder wiederverwendet)', $n_media, count(get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids'])));
    t_eq('Reset idempotent: Tipps und Mitteilungen nicht doppelt', [3, 3], [count(get_posts(['post_type' => 'tix_app_tip', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids'])), count(array_filter((array) get_option('_tix_app_notifications', []), function ($x) { return strpos((string) ($x['id'] ?? ''), 'b_demo_') === 0; }))]);
    t_eq('Reset idempotent: gleiche Zahl Events und Bestellungen', [$n_events, $n_orders], [count(get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids'])), intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tix_orders"))]);
    t_eq('Reset löscht die vorigen Bestellungen', $n_orders, intval($r2['wiped']['orders'] ?? -1));
    t_eq('Vorlage bleibt openair', 'openair', $r2['vorlage'] ?? '');
    $r3 = TIX_Demo_Shop::run('seed');
    t_eq('seed bei vorhandenen Daten: übersprungen', [true, true], [!empty($r3['ok']), !empty($r3['skipped'])]);
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_pub%' OR option_name LIKE '\\_transient\\_timeout\\_%tix\\_pub%'");
    wp_cache_flush();
    $pl = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/public/events'))->get_data();
    t_eq('Demo-Events erscheinen in der öffentlichen Liste (Homepage)', true, intval($pl['count'] ?? 0) > 0);
    t_eq('Zugänge bleiben', [true, true, true], [(bool) get_userdata($pa), (bool) get_userdata($pb), (bool) get_userdata($sales)]);
    $wbak = get_option('tix_website', null);
    $rc = TIX_Demo_Shop::run('reset', 'club');
    $titles = array_map('get_the_title', get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']));
    t_eq('Vorlage club: Club-Events, kein Festival', [true, false], [in_array('Neon Night', $titles, true), in_array(TIX_Demo_Content::OPENAIR_TITLE, $titles, true)]);
    // Demo B (1.38.844): Programm jede Woche über die nächsten 8–10 Wochen
    $starts = array_map(function ($id) { return (string) get_post_meta($id, '_tix_date_start', true); }, get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']));
    $weeks = [];
    foreach ($starts as $d0) { $diff = intval(floor((strtotime($d0 . ' 12:00') - strtotime(wp_date('Y-m-d') . ' 12:00')) / DAY_IN_SECONDS)); if ($diff >= 0) $weeks[intdiv($diff, 7)] = true; }
    t_eq('club: in jeder der nächsten 9 Wochen mindestens ein Event, mehr als 10 Events', [true, true], [count(array_intersect(array_keys($weeks), range(0, 8))) === 9, count($starts) > 10]);
    $live = intval($rc['seeded']['scan_event'] ?? 0);
    t_eq('club: scan_event = Live-Abend heute', [wp_date('Y-m-d'), 'Live-Abend (Beispiel)'], [(string) get_post_meta($live, '_tix_date_start', true), get_the_title($live)]);
    t_eq('club: Vorverkauf + Gästeliste je Event, Gästeliste heute befüllt', [true, true], [!array_filter(get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']), function ($id) { return get_post_meta($id, '_tix_presale_active', true) !== '1' || get_post_meta($id, '_tix_guest_list_enabled', true) !== '1'; }), count((array) get_post_meta($live, '_tix_guest_list', true)) > 0]);
    t_eq('club: Musikwunsch an', true, class_exists('TIX_Music') ? TIX_Music::enabled() : true);
    t_eq('club: ~50 Bestellungen, Tickets teils eingecheckt, Gutscheine', [true, true, 3], [($rc['seeded']['orders'] ?? 0) >= 40, ($rc['seeded']['checked_in'] ?? 0) > 0, $rc['seeded']['giftcards'] ?? 0]);
    // Club wie ein echter Club (1.38.847): KI-Bild je Abend, 20+ Events, Ü16, freier Eintritt, zwei Gewinnspiele, Kacheln, Tipps, Mitteilungen
    $cids = get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']);
    $thumbs = array_filter(array_map('get_post_thumbnail_id', $cids));
    t_eq('club: mindestens 20 Events, jedes mit KI-Bild, alle sechs Motive genutzt', [true, true, 6], [count($cids) >= 20, count($thumbs) === count($cids) && !array_filter($thumbs, function ($a) { return !get_post_meta($a, '_tix_demo_ki', true); }), count(array_unique($thumbs))]);
    t_eq('club: Ü16-Party, Afterwork mit freiem Eintritt, zwei Gewinnspiele', [true, true, 2],
        [in_array('16', array_map(function ($id) { return (string) get_post_meta($id, '_tix_info_age_limit', true); }, $cids), true), (bool) array_filter($cids, function ($id) { return get_post_meta($id, '_tix_free_entry', true) === '1' && get_post_meta($id, '_tix_tickets_enabled', true) === '0'; }), $rc['seeded']['raffles'] ?? 0]);
    t_eq('club: Tipps 3, Mitteilungen 3, 10 Kacheln, Hashtag, Seiten faq/u16/musikwunsch/kontakt', [3, 3, 10, '#ostkainacht', ['faq', 'u16', 'musikwunsch', 'kontakt']],
        [$rc['seeded']['tips'] ?? 0, $rc['seeded']['notifications'] ?? 0, count((array) (TIX_App_Config::stored()['home']['tiles'] ?? [])), TIX_App_Config::stored()['home']['hashtag'] ?? '', array_keys((array) (TIX_App_Config::stored()['pages'] ?? []))]);
    t_eq('club: Neue Abende auf ihrem Wochentag (Brunch = Sonntag, Ü16 = Freitag)', ['0', '5'], array_map(function ($t) use ($cids) { foreach ($cids as $id) if (html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8') === $t) return wp_date('w', strtotime(get_post_meta($id, '_tix_date_start', true) . ' 12:00')); return ''; }, ['Sunday Beats & Brunch', 'Ü16 Freitag – Teens Night']));
    t_eq('Vorlage gemerkt', 'club', TIX_Demo_Shop::vorlage());
    // 1.38.861: Startseite der Club-Demo wie kitchenklub.de – Highlights ganz oben, übersteht jeden Reset
    $pw = function () { wp_cache_flush(); $d = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/public/website'))->get_data(); return $d; };
    $wd = $pw();
    $keys = array_column((array) ($wd['website']['sections'] ?? []), 'key');
    t_eq('club: Website veröffentlicht, Vorlage club, Highlights zuerst, dann Bühne und „Jetzt live“', [1, 'published', 'club', ['highlights', 'stage', 'live_now']],
        [$rc['seeded']['website'] ?? 0, $wd['website']['source'] ?? '', $wd['website']['template'] ?? '', array_slice($keys, 0, 3)]);
    $o = get_option('tix_website'); $o['published']['sections'] = array_reverse($o['published']['sections']); update_option('tix_website', $o, false); // Demo-Besucher ändert die Reihenfolge
    TIX_Demo_Shop::run('reset', 'club');
    t_eq('club: Reset stellt die Reihenfolge wieder her', 'highlights', array_column((array) ($pw()['website']['sections'] ?? []), 'key')[0] ?? '');
    $wclub = get_option('tix_website');
    list($st, $d) = t_rest('POST', '/demo-access/reset', ['vorlage' => 'quatsch']);
    t_eq('falsche Vorlage → 400', 400, $st);
    wp_set_current_user($sales);
    list($st, $d) = t_rest('POST', '/demo-access/reset', ['vorlage' => 'openair']);
    t_eq('Vertriebler setzt zurück (200, last_reset, studio, home)', [200, true, 'https://studio.' . $host . '/', home_url('/')], [$st, !empty($d['last_reset']), $d['studio'] ?? '', $d['home'] ?? '']);
    t_eq('openair: Website-Einstellung bleibt unberührt', wp_json_encode($wclub), wp_json_encode(get_option('tix_website')));
    if ($wbak === null) delete_option('tix_website'); else update_option('tix_website', $wbak, false);
    wp_set_current_user($admin);

    // ── 6. Kasse ──
    $rr = TIX_Demo_Shop::run('reset');
    $live = intval($rr['seeded']['scan_event'] ?? 0);
    $cats = (array) get_post_meta($live, '_tix_ticket_categories', true);
    $paid = 0; foreach ($cats as $i => $c) if (floatval($c['price'] ?? 0) > 0) { $paid = intval($i); break; }
    update_option('tix_settings', array_merge(is_array($settings_before) ? $settings_before : [], ['mollie_api_key' => 'live_' . str_repeat('e', 30), 'stripe_enabled' => 0, 'paypal_enabled' => 0, 'bank_transfer_enabled' => 1, 'bank_iban' => 'DE89370400440532013000']));
    wp_set_current_user(0);
    list($st, $q) = t_rest('POST', '/customer/cart/quote', ['event_id' => $live, 'items' => [['index' => $paid, 'qty' => 2]]]);
    $ids = array_column((array) ($q['payment_methods'] ?? []), 'id');
    t_eq('Angebot: demo, keine Überweisung, kein Mollie-Live', [true, false, false], [in_array('demo', $ids, true), in_array('bank', $ids, true), (bool) array_filter($ids, function ($i) { return strpos($i, 'mollie') === 0; })]);
    t_eq('Angebot: demo-Kennzeichen', true, $q['demo'] ?? null);
    $billing = ['first_name' => 'Paula', 'last_name' => "Probe$sfx", 'email' => "paula$sfx@example.org", 'address_1' => 'Weg 1', 'postcode' => '12345', 'city' => 'Musterstadt', 'country' => 'DE'];
    $base = ['event_id' => $live, 'items' => [['index' => $paid, 'qty' => 2]], 'billing' => $billing, 'accept_terms' => true];
    list($st, $d) = t_rest('POST', '/customer/orders', $base + ['idempotency_token' => "ds1-$sfx-abc", 'payment_method' => 'mollie:creditcard']);
    t_eq('Mollie mit Live-Schlüssel → 400 tix_demo_test_only', [400, 'tix_demo_test_only'], [$st, t_code($d)]);
    list($st, $d) = t_rest('POST', '/customer/orders', $base + ['idempotency_token' => "ds2-$sfx-abc", 'payment_method' => 'bank']);
    t_eq('Überweisung → 400 tix_demo_test_only', [400, 'tix_demo_test_only'], [$st, t_code($d)]);
    $GLOBALS['t_mails'] = [];
    list($st, $d) = t_rest('POST', '/customer/orders', $base + ['idempotency_token' => "ds3-$sfx-abc", 'payment_method' => 'demo', 'demo_access' => TIX_Demo_Access::code($pa)]);
    $oid = intval($d['order']['id'] ?? 0);
    $row = $oid ? $wpdb->get_row($wpdb->prepare("SELECT status, payment_method FROM {$wpdb->prefix}tix_orders WHERE id = %d", $oid)) : null;
    t_eq('Testkauf mit demo sofort bezahlt', ['completed', 'demo'], $row ? [$row->status, $row->payment_method] : null);
    t_eq('Testkauf dem Zugang zugeordnet', $pa, intval(get_post_meta($oid, TIX_Demo_Access::META_ORDER, true)));
    t_eq('Ticket-Mail an den eigenen Zugang', true, t_mail_to("paula$sfx@example.org") !== null);
    t_eq('Bestellung zählt als Sandbox (keine Rechnung, kein Spiegel)', [true, true], [TIX_Sales::is_sandbox_order($oid), TIX_Mirror_Map::is_sandbox_order($oid)]);
    $GLOBALS['t_mails'] = [];
    list($st, $d) = t_rest('POST', '/customer/orders', array_merge($base, ['billing' => ['email' => "fremd$sfx@example.org"] + $billing]) + ['idempotency_token' => "ds4-$sfx-abc", 'payment_method' => 'demo']);
    t_eq('Testkauf mit fremder Adresse geht', true, in_array($st, [200, 201], true));
    t_eq('Mail an fremde Adresse verworfen', null, t_mail_to("fremd$sfx@example.org"));
    $log = (array) get_option(TIX_Demo_Shop::OPT_MAIL_LOG, []);
    t_eq('verworfene Mail protokolliert (maskiert)', true, (bool) array_filter($log, function ($e) { return strpos((string) $e['to'], 'f***@') === 0; }));
    $oid_b = intval($d['order']['id'] ?? 0);
    update_option('tix_settings', $settings_before ?: []);
    // Fremde Testkäufe verkürzt
    $map = TIX_Demo_Access::foreign_map(get_userdata($pb));
    t_eq('Interessent B sieht Paulas Testkauf verkürzt', true, isset($map["paula$sfx@example.org"]));
    t_eq('Staff-Mitteilungen nie an Demo-Zugänge', [$admin], TIX_Demo_Access::push_recipients([$admin, $pa, $sales], [], 'order'));

    // ── 7. Mails direkt ──
    $GLOBALS['t_mails'] = [];
    wp_mail(["paula$sfx@example.org", "fremd2$sfx@example.org"], 'Mix', 'x', ['Cc: fremd3' . $sfx . '@example.org, vera' . $sfx . '@example.org', 'Reply-To: kunde@example.org']);
    $m = t_mail_to("paula$sfx@example.org");
    t_eq('gemischt: nur der Zugang bleibt', ["paula$sfx@example.org"], $m ? array_values((array) $m['to']) : null);
    t_eq('Cc: fremd raus, Vertriebler bleibt', [false, true], [$m && stripos(t_headers($m), "fremd3$sfx") !== false, $m && stripos(t_headers($m), "vera$sfx@example.org") !== false]);
    t_eq('Reply-To ersetzt', [false, true], [$m && stripos(t_headers($m), 'kunde@example.org') !== false, $m && stripos(t_headers($m), 'Reply-To: vertrieb@evendis.de') !== false]);
    $GLOBALS['t_mails'] = [];
    wp_mail('lena.becker1.2@demo.invalid', 'Seed', 'x');
    t_eq('*.invalid nie versendet', 0, count($GLOBALS['t_mails']));
    update_option(TIX_Demo_Shop::OPT_MAIL_ALLOW, ['@agentur-test.example']);
    wp_mail("chef$sfx@agentur-test.example", 'Erlaubt', 'x');
    t_eq('Erlaubnisliste (Domain) erlaubt', true, t_mail_to("chef$sfx@agentur-test.example") !== null);
    delete_option(TIX_Demo_Shop::OPT_MAIL_ALLOW);
    update_user_meta($pa, '_tix_demo_blocked', 1);
    $GLOBALS['t_mails'] = [];
    wp_mail("paula$sfx@example.org", 'Gesperrt', 'x');
    t_eq('gesperrter Zugang bekommt keine Mail mehr', null, t_mail_to("paula$sfx@example.org"));

    // ── 8. CLI-Zeile ──
    $r = TIX_Demo_Shop::run('reset');
    t_eq('JSON für die Wache: ok, site, version, vorlage, last_reset, ms', [true, $host, TIXOMAT_VERSION, true, true], [$r['ok'], $r['site'], $r['version'], in_array($r['vorlage'], ['openair', 'club'], true), isset($r['ms'])]);
    t_eq('Reset räumt Testkäufe ab', null, $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}tix_orders WHERE id IN (%d, %d)", $oid, $oid_b)));
}

// ═══════════════════════════════ Aufräumen ═══════════════════════════════
if (!function_exists('wp_delete_user')) require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_filter($cleanup_users) as $u) wp_delete_user($u);
update_option('tix_settings', $settings_before);
update_option('tix_demo_instance', $phase === 'on' ? '1' : '0');
delete_transient('tix_demo_reset_rl');
echo "\n" . ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']} von {$GLOBALS['t_count']}" : "ALLES OK ({$GLOBALS['t_count']} Prüfungen, Phase $phase)") . "\n";
