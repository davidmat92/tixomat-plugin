<?php
/**
 * Mehrere Co-Veranstalter je Event (1.38.716, Vertrag `uebergaben/vertrag-co-mehrere.md`).
 *   plat eval-file …/test-co-multi-wp.php   (Teil A: Liste, Alt-Stand, Zugriff je Co, Anteile, Sperre, Lösen je Co, Spiegel-Liste)
 *   kk   eval-file …/test-co-multi-wp.php setup | run | cleanup   (Teil B Lizenzseite: Liste, Alt-Stand, Schlüssel je Co, Lösen je Co;
 *        tix_get_settings speichert je Prozess zwischen, daher eigene Schritte)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Co_Money') || !method_exists('TIX_Co_Organizer', 'entries')) { echo "Tixomat ab 1.38.716 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
global $wpdb;
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function mu_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
/** Tests vor 1.38.717: alle Beteiligten stimmen sofort zu (den echten Ablauf prüft test-co-multi-wp „Zustimmung“). */
function co_test_consent_all($route) {
    if (!class_exists('TIX_Co_Money') || !method_exists('TIX_Co_Money', 'pending') || empty($GLOBALS['co_auto_consent'])) return;
    if (!preg_match('#/(?:events|syndicate|platform/events|partner/co-events)/(\d+)#', $route, $m)) return;
    $p = TIX_Co_Money::pending(intval($m[1]));
    if (!$p) return;
    foreach ((array) $p['needs'] as $party) if (empty($p['consents'][$party])) TIX_Co_Money::consent(intval($m[1]), $party, true);
}
$GLOBALS['co_auto_consent'] = true;
function mu_rest($method, $route, array $body = [], array $headers = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    foreach ($headers as $k => $v) $r->set_header($k, $v);
    if ($body && in_array($method, ['GET', 'DELETE'], true)) $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    if ($method !== 'GET') co_test_consent_all($route);
    $d = rest_get_server()->response_to_data($res, false);
    return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : ''];
}
function mu_last_action() { // letzte Co-Meldung an die Plattform (Event-Verteilung sendet beim Speichern zusätzlich)
    foreach (array_reverse((array) ($GLOBALS['mu_sent'] ?? [])) as $b) if (isset($b['action'])) return $b;
    return [];
}
function mu_ev($d) { return $d['event'] ?? $d; }
function mu_ids($list) { return array_map('intval', array_column((array) $list, 'id')); }
add_filter('pre_wp_mail', function () { return true; });
$admin = get_users(['role' => 'administrator', 'number' => 1]);
$admin_id = intval($admin[0]->ID);
$tmp_p = []; $tmp_u = [];
$sold = false;
add_filter('tix_co_money_sold', function ($s) use (&$sold) { return $s || $sold; });

if ($multi) {
    echo "== Plattform (Teil A)\n";
    try {
        wp_set_current_user($admin_id);
        $mk = function ($n, $kind = '') use (&$tmp_p, &$tmp_u) {
            $u = wp_insert_user(['user_login' => 'mu_' . wp_rand(1000, 999999), 'user_pass' => wp_generate_password(20), 'user_email' => 'mu' . wp_rand(1000, 999999) . '@example.test', 'role' => 'tix_organizer']);
            $tmp_u[] = $u;
            $o = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => $n, 'meta_input' => ['_tix_org_user_id' => $u] + ($kind ? ['_tix_org_kind' => $kind] : [])]);
            $tmp_p[] = $o;
            return [$o, $u];
        };
        [$oa, $ua] = $mk('Mutest Ersteller'); [$ob, $ub] = $mk('Mutest Bar'); [$oc, $uc] = $mk('Mutest Club'); [$od, $ud] = $mk('Mutest Drei');
        // Alt-Stand (vor 1.38.716): ein Co in den Einzelfeldern
        $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Mutest Party', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_date_start' => '2027-05-01',
            '_tix_co_organizer_ref' => $ob, '_tix_co_organizer_id' => $ob, '_tix_co_organizer_access' => '1', '_tix_co_organizer_consent' => ['at' => '2026-11-01T10:00:00+00:00', 'by' => 1],
            '_tix_co_organizer_since' => '2026-11-01T10:00:00+00:00']]);
        $tmp_p[] = $ev;
        mu_eq('Alt-Stand lesbar: ein Co mit Zugriff und Einwilligung', [[$ob], true, '2026-11-01T10:00:00+00:00'], [TIX_Co_Organizer::ids($ev), TIX_Co_Organizer::has_access($ev, $ob), TIX_Co_Organizer::entries($ev)[0]['consent']['at'] ?? '']);
        [$st, $d] = mu_rest('GET', "/events/$ev/edit");
        mu_eq('Editor: Liste mit dem Alt-Co, mehrere möglich', [200, [$ob], true], [$st, mu_ids(mu_ev($d)['co_organizers'] ?? []), mu_ev($d)['co_organizer_multiple'] ?? null]);
        // zweiter Co: hinzufügen, ersetzt nie
        [$st, $d] = mu_rest('POST', "/events/$ev", ['co_organizer' => ['id' => $oc, 'access' => true]]);
        mu_eq('zweiter Co über den Editor: beide da, Alt-Felder = erster', [200, [$ob, $oc], $ob, '1'], [$st, TIX_Co_Organizer::ids($ev), intval(get_post_meta($ev, '_tix_co_organizer_ref', true)), get_post_meta($ev, '_tix_co_organizer_access', true)]);
        mu_eq('Liste gespeichert, Einwilligung des Alt-Co bleibt', ['2026-11-01T10:00:00+00:00', 2], [TIX_Co_Organizer::entries($ev)[0]['consent']['at'] ?? '', count((array) get_post_meta($ev, '_tix_co_list', true))]);
        // dritter per Plattform, ohne Zugriff
        [$st, $d] = mu_rest('POST', "/platform/events/$ev/co-organizer", ['organizer_id' => $od, 'access' => false]);
        mu_eq('dritter über die Plattform: 201 added, drei Co', [201, 'added', [$ob, $oc, $od]], [$st, $d['changed'] ?? '', mu_ids($d['event']['co_organizers'] ?? [])]);
        mu_eq('Zugriffs-Zeilen nur für Co mit Zugriff', [$ob, $oc], array_map('intval', get_post_meta($ev, '_tix_co_organizer_id', false)));
        mu_eq('Zugriff je Co', [true, true, false], [TIX_Co_Organizer::has_access($ev, $ob), TIX_Co_Organizer::has_access($ev, $oc), TIX_Co_Organizer::has_access($ev, $od)]);
        foreach ([[$ub, 'co'], [$uc, 'co'], [$ud, null]] as [$u, $exp]) { wp_set_current_user($u); mu_eq("Rolle Nutzer $u", $exp, TIX_Co_Organizer::role($ev)); }
        wp_set_current_user($uc);
        mu_eq('Scope: Club darf das Event, sieht alle anderen Beteiligten', [true, [$oa, $ob, $od]], [TIX_App_Scope::event_allowed($ev), mu_ids(TIX_Co_Organizer::list_fields($ev)['shared_with_all'] ?? [])]);
        wp_set_current_user($admin_id);
        mu_eq('Öffentlich: alle drei Co', [$ob, $oc, $od], mu_ids(TIX_Co_Organizer::public_refs($ev)));
        // Zugriff umschalten betrifft nur diesen Co
        mu_rest('POST', "/events/$ev", ['co_organizer' => ['id' => $oc, 'access' => false]]);
        mu_eq('Zugriff Club aus, Bar behält', [false, true, [$ob]], [TIX_Co_Organizer::has_access($ev, $oc), TIX_Co_Organizer::has_access($ev, $ob), array_map('intval', get_post_meta($ev, '_tix_co_organizer_id', false))]);
        [$st, , $c] = mu_rest('POST', "/events/$ev", ['co_organizer' => ['access' => true]]);
        mu_eq('ohne id bei mehreren Co: 400', 400, $st);
        mu_rest('POST', "/events/$ev", ['co_organizer' => ['id' => $oc, 'access' => true]]);

        echo "-- Geld: ein Geldempfänger, Anteile je Beteiligtem\n";
        [$st, $d] = mu_rest('GET', "/events/$ev/edit");
        $m = mu_ev($d)['co_money'] ?? [];
        mu_eq('Start: Ersteller 100 %, wählbar Ersteller + Co mit Zugriff', ['creator', 0, [0, $ob, $oc], [100.0, 0.0, 0.0, 0.0]], [$m['owner'] ?? '', $m['owner_id'] ?? -1, $m['choosable_ids'] ?? [], array_column($m['shares'] ?? [], 'percent')]);
        [$st, , $c] = mu_rest('POST', "/events/$ev", ['co_money' => ['owner_id' => $oc, 'shares' => [['party' => 'creator', 'percent' => 50], ['organizer_id' => $ob, 'percent' => 20], ['organizer_id' => $oc, 'percent' => 20]]]]);
        mu_eq('Summe 90 %: 400 tix_co_shares_sum', [400, 'tix_co_shares_sum'], [$st, $c]);
        [$st, , $c] = mu_rest('POST', "/events/$ev", ['co_money' => ['owner_id' => $od]]);
        mu_eq('Co ohne Zugriff als Geldempfänger: 409', [409, 'tix_co_money_unsupported'], [$st, $c]);
        [$st] = mu_rest('POST', "/events/$ev", ['co_money' => ['owner_id' => $oc, 'shares' => [['party' => 'creator', 'percent' => 50], ['organizer_id' => $ob, 'percent' => 20], ['organizer_id' => $oc, 'percent' => 30]]]]);
        $m = TIX_Co_Money::payload($ev);
        mu_eq('Club nimmt ein, Alt-Feld share 70, Liste mit Geldempfänger-Markierung', [200, 'co', $oc, 70.0, [false, false, true, false]], [$st, $m['owner'], $m['owner_id'], $m['share'], array_column($m['shares'], 'owner')]);
        mu_eq('Anteile gespeichert', ['o:' . $oc => 30.0, 'creator' => 50.0, 'o:' . $ob => 20.0], TIX_Co_Money::shares($ev));
        mu_eq('Abrechnung an den Club, Anteile an Ersteller + Bar', [$oc, [$oa, $ob], [50.0, 20.0]], [TIX_Co_Money::settlement_org($ev), array_column(TIX_Co_Money::share_parties($ev), 0), array_column(TIX_Co_Money::share_parties($ev), 1)]);
        mu_eq('Verkauf über die Plattform selbst (Club ist kein Seiten-Profil)', 'self', TIX_Co_Seller::seller($ev));
        // Nachverkauf: Anteile als offene Posten je Beteiligtem
        if (class_exists('TIX_Settlement')) {
            $si = TIX_Settlement::items_table();
            TIX_Co_Money::late_share($ev, $oc, ['amount' => 100.0], 0);
            $rows = $wpdb->get_results($wpdb->prepare("SELECT organizer_id, amount FROM {$si} WHERE event_id = %d AND type = 'co_share' AND settlement_id = 0 ORDER BY id", $ev), ARRAY_A);
            mu_eq('Nachverkauf 100 €: −50/+50 Ersteller, −20/+20 Bar', [[$oc, -50.0], [$oa, 50.0], [$oc, -20.0], [$ob, 20.0]], array_map(function ($r) { return [intval($r['organizer_id']), floatval($r['amount'])]; }, $rows));
            $wpdb->query($wpdb->prepare("DELETE FROM {$si} WHERE event_id = %d AND type = 'co_share'", $ev));
        }
        $b = TIX_Co_Money::balance($ev);
        mu_eq('Ausgleich: Zahler Club, zwei Empfänger', ['Mutest Club', ['Mutest Ersteller', 'Mutest Bar'], [50.0, 20.0]], [$b['payer']['name'], array_column($b['parts'], 'name'), array_column($b['parts'], 'percent')]);
        // Sperre nach Verkauf
        $sold = true;
        [$st, , $c] = mu_rest('POST', "/events/$ev", ['co_money' => ['owner' => 'creator']]);
        mu_eq('nach Verkauf: Geldempfänger gesperrt', [409, 'tix_co_money_locked'], [$st, $c]);
        mu_eq('nach Verkauf: Geldempfänger lösen 409, Bar (Anteil) lösen 409', [409, 409], [mu_rest('DELETE', "/events/$ev/co-organizer", ['organizer_id' => $oc])[0], mu_rest('DELETE', "/events/$ev/co-organizer", ['organizer_id' => $ob])[0]]);
        mu_eq('nach Verkauf: Zugriff des Geldempfängers aus → 409', 409, mu_rest('POST', "/events/$ev", ['co_organizer' => ['id' => $oc, 'access' => false]])[0]);
        mu_eq('nach Verkauf: Co ohne Anteil (Drei) lösen geht', [200, 'removed'], (function ($r) { return [$r[0], $r[1]['changed'] ?? '']; })(mu_rest('DELETE', "/events/$ev/co-organizer", ['organizer_id' => $od])));
        [$st, $d] = mu_rest('POST', "/platform/events/$ev/co-organizer", ['organizer_id' => $od]);
        mu_eq('nach Verkauf: neuer Co kommt mit 0 % dazu', [201, 0.0, 70.0], [$st, TIX_Co_Money::shares($ev)['o:' . $od] ?? 0.0, TIX_Co_Money::share($ev)]);
        $sold = false;
        // vor Verkauf: Bar lösen → ihr Anteil fällt an den Geldempfänger
        $r = mu_rest('DELETE', "/events/$ev/co-organizer", ['organizer_id' => $ob]);
        mu_eq('Bar gelöst: Anteil an den Club', [200, ['o:' . $oc => 50.0, 'creator' => 50.0]], [$r[0], TIX_Co_Money::shares($ev)]);
        // Geldempfänger lösen → zurück an den Ersteller
        $r = mu_rest('DELETE', "/events/$ev/co-organizer", ['organizer_id' => $oc]);
        mu_eq('Club (Geldempfänger) gelöst: Ersteller nimmt ein, 100 %', [200, 'creator', ['creator' => 100.0]], [$r[0], TIX_Co_Money::owner($ev), TIX_Co_Money::shares($ev)]);
        mu_eq('übrig: Drei', [$od], TIX_Co_Organizer::ids($ev));

        echo "-- Alt-Schnittstelle (ein Co)\n";
        $ev2 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Mutest Einzel', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_date_start' => '2027-05-02']]);
        $tmp_p[] = $ev2;
        [$st, $d] = mu_rest('POST', "/events/$ev2/co-organizer/invite", ['email' => get_userdata($ub)->user_email, 'money_owner' => 'co', 'share' => 25]);
        $m = TIX_Co_Money::payload($ev2);
        mu_eq('Einladen mit money_owner=co + share=25: Bar nimmt ein, 25 % an den Ersteller', [201, 'co', $ob, 25.0, 'creator'], [$st, $m['owner'], $m['owner_id'], $m['share'], $m['share_to']]);
        mu_rest('POST', "/events/$ev2", ['co_money' => ['owner' => 'creator']]);
        mu_eq('nur Geldempfänger zurück: Seiten tauschen, 25 % an die Bar', ['creator', ['creator' => 75.0, 'o:' . $ob => 25.0]], [TIX_Co_Money::owner($ev2), TIX_Co_Money::shares($ev2)]);
        [$st, $d] = mu_rest('POST', "/events/$ev2/co-organizer/invite", ['email' => get_userdata($uc)->user_email, 'share' => 10]);
        mu_eq('zweiter Co mit share=10: kommt aus dem Rest des Erstellers', [201, ['creator' => 65.0, 'o:' . $ob => 25.0, 'o:' . $oc => 10.0]], [$st, TIX_Co_Money::shares($ev2)]);
        [$st, , $c] = mu_rest('POST', "/events/$ev2", ['co_money' => ['share' => 30]]);
        mu_eq('Alt-Feld share bei mehreren Co: 400', 400, $st);

        echo "-- Alt-Geldempfänger „co“ (bis 1.38.715)\n";
        $ev4 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Mutest Alt-Geld', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_date_start' => '2027-05-06',
            '_tix_co_organizer_ref' => $ob, '_tix_co_organizer_id' => $ob, '_tix_co_organizer_access' => '1', '_tix_co_money' => 'co', '_tix_co_share' => '20']]);
        $tmp_p[] = $ev4;
        mu_eq('Alt: Bar nimmt ein, 20 % an den Ersteller', [$ob, ['o:' . $ob => 80.0, 'creator' => 20.0]], [TIX_Co_Money::owner_id($ev4), TIX_Co_Money::shares($ev4)]);
        mu_rest('POST', "/platform/events/$ev4/co-organizer", ['organizer_id' => $oc]);
        mu_eq('zweiter Co: Bar bleibt Geldempfänger, auf ID umgeschrieben', [$ob, 'o:' . $ob, ['o:' . $ob => 80.0, 'creator' => 20.0]], [TIX_Co_Money::owner_id($ev4), get_post_meta($ev4, '_tix_co_money', true), TIX_Co_Money::shares($ev4)]);
        mu_rest('DELETE', "/platform/events/$ev4/co-organizer", ['organizer_id' => $oc]);
        mu_eq('zweiten Co lösen: Geld unverändert', [$ob, ['o:' . $ob => 80.0, 'creator' => 20.0]], [TIX_Co_Money::owner_id($ev4), TIX_Co_Money::shares($ev4)]);

        echo "-- Kunden-Seiten am selben Event (Abgleich)\n";
        [$sa] = $mk('Mutest Seite A', 'site'); [$sb] = $mk('Mutest Seite B', 'site');
        $pbk = get_option(TIX_Partners::OPTION, null);
        $all = (array) get_option(TIX_Partners::OPTION, []);
        $all['mutest-a'] = ['id' => 'mutest-a', 'name' => 'A', 'api_base' => 'http://a.test/wp-json/tixomat/v1', 'key_in' => 'mua-' . str_repeat('a', 30), 'key_out' => str_repeat('a', 32), 'organizer_id' => $sa];
        $all['mutest-b'] = ['id' => 'mutest-b', 'name' => 'B', 'api_base' => 'http://b.test/wp-json/tixomat/v1', 'key_in' => 'mub-' . str_repeat('b', 30), 'key_out' => str_repeat('b', 32), 'organizer_id' => $sb];
        update_option(TIX_Partners::OPTION, $all, false);
        add_filter('pre_http_request', function ($pre, $a, $url) { return strpos($url, '.test/') !== false ? ['headers' => [], 'body' => '{}', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null] : $pre; }, 10, 3);
        $ev3 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Mutest Zwei Seiten', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_date_start' => '2027-05-05',
            '_tix_tickets_enabled' => '1', '_tix_ticket_categories' => [['name' => 'Normal', 'price' => 10, 'qty' => 50, 'stock' => 50]]]]);
        $tmp_p[] = $ev3;
        TIX_Co_Organizer::set($ev3, $sa, true, 0); TIX_Co_Organizer::set($ev3, $sb, false, 0);
        TIX_Co_Money::apply($ev3, TIX_Co_Money::check_values($ev3, ['owner' => $sa, 'shares' => ['creator' => 80, 'o:' . $sa => 15, 'o:' . $sb => 5]]));
        $items = function ($oid) { $r = new WP_REST_Request('GET', '/tixomat/v1/partner/co-events'); $r->set_param('_partner_oid', $oid); $d = TIX_Site_Move::rest_partner_co_events($r)->get_data(); return array_values(array_filter($d['items'], function ($i) { return intval($i['id']) === $GLOBALS['mu_ev3']; }))[0] ?? []; };
        $GLOBALS['mu_ev3'] = $ev3;
        $ia = $items($sa); $ib = $items($sb);
        mu_eq('Seite A (Geldempfänger): verkauft, Zugriff, Anteile an Ersteller + B', [true, true, 'site', ['Mutest Ersteller', 'Mutest Seite B'], [80.0, 5.0]], [!empty($ia['sell']), $ia['access'] ?? null, TIX_Co_Seller::seller($ev3), array_column($ia['parts'] ?? [], 'name'), array_column($ia['parts'] ?? [], 'percent')]);
        mu_eq('Seite B: sieht das Event, ohne Zugriff, verkauft nicht', [true, false, false], [!empty($ib), $ib['access'] ?? null, !empty($ib['sell'])]);
        mu_eq('Seite B darf das Event (gemeinsames Event)', true, TIX_Site_Move::partner_event_ok($sb, $ev3));
        if ($pbk === null) delete_option(TIX_Partners::OPTION); else update_option(TIX_Partners::OPTION, $pbk, false);

        echo "-- Zustimmung (1.38.717): Co als Geldempfänger + Anteile\n";
        $GLOBALS['co_auto_consent'] = false;
        $ev5 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Mutest Zustimmung', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_date_start' => '2027-05-07']]);
        $tmp_p[] = $ev5;
        TIX_Co_Organizer::set($ev5, $ob, true, 0); TIX_Co_Organizer::set($ev5, $oc, true, 0);
        update_post_meta($ob, '_tix_payout_data', ['billing' => ['company' => 'Mutest Bar GmbH', 'street' => 'Barweg 1', 'zip' => '42103', 'city' => 'Wuppertal', 'country' => 'DE']]);
        update_post_meta($ob, '_tix_org_imprint_url', 'https://bar.example.test/impressum');
        wp_set_current_user($ua);
        $shares = ['owner_id' => $ob, 'shares' => [['party' => 'creator', 'percent' => 60], ['organizer_id' => $ob, 'percent' => 30], ['organizer_id' => $oc, 'percent' => 10]]];
        [$st, $d] = mu_rest('POST', "/events/$ev5", ['co_money' => $shares]);
        $m = TIX_Co_Money::payload($ev5);
        mu_eq('Vorschlag: wartet auf Zustimmung, bisher gilt der Ersteller', [200, 'waiting_consent', 'creator', $ob], [$st, $m['status'], $m['owner'], $m['pending']['owner_id'] ?? 0]);
        mu_eq('nötig: Bar (Geldempfänger), Ersteller (schon durch die Änderung), Club', [['o:' . $ob, false], ['creator', true], ['o:' . $oc, false]], array_map(function ($n) { return [$n['party_key'], $n['done']]; }, $m['pending']['needs'] ?? []));
        wp_set_current_user($ub);
        [, $d] = mu_rest('GET', '/organizer/co-consents');
        $it = $d['items'][0] ?? [];
        mu_eq('Bar: offene Zustimmung mit beiden Pflicht-Texten', [1, $ev5, true, true, TIX_Co_Money::CONSENT_VERSION], [count($d['items'] ?? []), $it['event_id'] ?? 0, isset($it['texts']['owner']), isset($it['texts']['shares']), $it['version'] ?? '']);
        mu_eq('Text Geldempfänger wie Prüfmappe', true, strpos($it['texts']['owner'] ?? '', 'Ich übernehme für Mutest Zustimmung den Ticketverkauf.') === 0);
        [$st, , $c] = mu_rest('POST', "/events/$ev5/co-money/consent", ['accept' => true, 'version' => 'alt']);
        mu_eq('alte Textfassung: 409', [409, 'tix_co_consent_version'], [$st, $c]);
        [$st, $d] = mu_rest('POST', "/events/$ev5/co-money/consent", ['accept' => true, 'version' => TIX_Co_Money::CONSENT_VERSION]);
        mu_eq('Bar stimmt zu: noch pending (Club fehlt), Ersteller nimmt weiter ein', [200, 'pending', 'creator'], [$st, $d['state'] ?? '', TIX_Co_Money::owner($ev5)]);
        wp_set_current_user($ud);
        mu_eq('Unbeteiligter: 403', 403, mu_rest('POST', "/events/$ev5/co-money/consent", ['accept' => true])[0]);
        wp_set_current_user($uc);
        [$st, $d] = mu_rest('POST', "/events/$ev5/co-money/consent", ['accept' => false]);
        mu_eq('Club lehnt ab: verworfen, alles wie vorher', [200, 'declined', null, 'creator', ['creator' => 100.0]], [$st, $d['state'] ?? '', TIX_Co_Money::pending($ev5), TIX_Co_Money::owner($ev5), TIX_Co_Money::shares($ev5)]);
        wp_set_current_user($ua);
        mu_rest('POST', "/events/$ev5", ['co_money' => $shares]);
        foreach ([[$ub, 'Bar'], [$uc, 'Club']] as [$u]) { wp_set_current_user($u); $r = mu_rest('POST', "/events/$ev5/co-money/consent", ['accept' => true, 'version' => TIX_Co_Money::CONSENT_VERSION]); }
        mu_eq('alle zugestimmt: wirksam', [200, 'active', $ob, ['o:' . $ob => 30.0, 'creator' => 60.0, 'o:' . $oc => 10.0]], [$r[0], $r[1]['state'] ?? '', TIX_Co_Money::owner_id($ev5), TIX_Co_Money::shares($ev5)]);
        $log = (array) get_post_meta($ev5, TIX_Co_Money::META_CONSENT_LOG, true);
        $last = end($log);
        mu_eq('Nachweis: Zeitpunkt, Konto, Fassung, Text', [true, $uc, TIX_Co_Money::CONSENT_VERSION, true], [!empty($last['at']), intval($last['by'] ?? 0), $last['version'] ?? '', strpos((string) ($last['text']['shares'] ?? ''), 'Club – 10 %') !== false]);
        // Verkäufer-Angaben (Art. 246a EGBGB)
        $sel = TIX_Co_Legal::for_event($ev5);
        mu_eq('Verkäufer = Bar: Name, Anschrift, Impressum, Datenschutz, vollständig', ['Mutest Bar GmbH', 'Barweg 1, 42103 Wuppertal', 'https://bar.example.test/impressum', true],
            [$sel['name'], $sel['address'], $sel['imprint_url'], $sel['complete'] || $sel['privacy_url'] === null]);
        wp_set_current_user($admin_id);
        $pe = TIX_Public_Events::payload($ev5, true);
        mu_eq('Öffentlich (Fall B/eigene Kasse): kein syndicated, seller = Bar', [true, null, 'Mutest Bar GmbH'], [array_key_exists('syndicated', $pe), $pe['syndicated'], $pe['seller']['name'] ?? '']);
        // Plattform-Admin ändert: auch der Ersteller muss zustimmen
        $ev6 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Mutest Admin-Geld', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_date_start' => '2027-05-08']]);
        $tmp_p[] = $ev6;
        TIX_Co_Organizer::set($ev6, $ob, true, 0);
        mu_rest('POST', "/platform/events/$ev6/co-organizer", ['organizer_id' => $ob, 'money_owner' => 'co', 'share' => 10]);
        $pp = TIX_Co_Money::payload($ev6)['pending'] ?? [];
        mu_eq('Plattform-Admin: Bar + Ersteller müssen zustimmen', [['o:' . $ob, false], ['creator', false]], array_map(function ($n) { return [$n['party_key'], $n['done']]; }, $pp['needs'] ?? []));
        $sold = true;
        wp_set_current_user($ub);
        [$st, , $c] = mu_rest('POST', "/events/$ev6/co-money/consent", ['accept' => true]);
        mu_eq('Verkauf vor der Zustimmung: 409, Vorschlag verworfen', [409, 'tix_co_money_locked', null], [$st, $c, TIX_Co_Money::pending($ev6)]);
        $sold = false;
        wp_set_current_user($admin_id);
        $GLOBALS['co_auto_consent'] = true;

        echo "-- Verkäufer bei geteilten Events\n";
        $mir2 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Mutest Verkäufer Quelle', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_date_start' => '2027-05-09',
            '_tix_syndicated' => '1', '_tix_source_site' => 'KitchenKlub', TIX_Co_Legal::M_SOURCE => TIX_Co_Legal::clean(['name' => 'KK Gastro GmbH', 'address' => 'Clubstr. 2, 42103 Wuppertal',
                'imprint_url' => 'https://kk.example.test/impressum', 'privacy_url' => 'https://kk.example.test/datenschutz', 'terms_url' => 'https://kk.example.test/agb', 'host' => 'kk.example.test'])]]);
        $tmp_p[] = $mir2;
        $si = TIX_App_Checkout::syndicated_info($mir2);
        mu_eq('syndicated: Verkäufer mit Anschrift, Impressum, Datenschutz, AGB', ['KitchenKlub', 'KK Gastro GmbH', 'Clubstr. 2, 42103 Wuppertal', 'https://kk.example.test/impressum', 'https://kk.example.test/datenschutz', 'https://kk.example.test/agb', true],
            [$si['site'] ?? '', $si['seller_info']['name'] ?? '', $si['address'] ?? '', $si['imprint_url'] ?? '', $si['privacy_url'] ?? '', $si['terms_url'] ?? '', $si['seller_info']['complete'] ?? null]);

        echo "-- Spiegel (Teil B auf der Plattform)\n";
        $mir = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Mutest Spiegel', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_date_start' => '2027-05-03',
            '_tix_syndicated' => '1', '_tix_co_remote_oid' => $ob, '_tix_co_remote_access' => '1', '_tix_co_remote_token' => str_repeat('b', 48)]]);
        $tmp_p[] = $mir;
        mu_eq('Alt-Spiegel lesbar', [[$ob], true], [TIX_Co_Remote::mirror_ids($mir), TIX_Co_Remote::mirror_has_access($mir, $ob)]);
        TIX_Co_Remote::mirror_save($mir, array_merge(TIX_Co_Remote::mirror_entries($mir), [['oid' => $oc, 'access' => false, 'token' => str_repeat('c', 48)]]));
        mu_eq('Spiegel mit zwei Co: Zeilen je Co, Zugriff je Co', [[$ob, $oc], true, false], [array_map('intval', get_post_meta($mir, '_tix_co_remote_oid', false)), TIX_Co_Remote::mirror_has_access($mir, $ob), TIX_Co_Remote::mirror_has_access($mir, $oc)]);
        mu_eq('Plattform-Zeile zeigt beide', [$ob, $oc], mu_ids(TIX_Co_Organizer::platform_event_row($mir)['co_organizers']));
        mu_eq('mirrors_of findet den zweiten Co', true, in_array($mir, TIX_Co_Remote::mirrors_of($oc), true));
        TIX_Co_Organizer::set($mir, $od, true, 0); // lokaler Co auf einem Spiegel (Alt-Fehler)
        $cf = array_values(array_filter(TIX_Co_Organizer::mirror_conflicts(), function ($x) use ($mir) { return $x['event'] === $mir; }));
        mu_eq('Widerspruch: lokal Drei, Quelle Bar + Club', [1, $od, [$ob, $oc], false], [count($cf), $cf[0]['local']['id'] ?? 0, array_column($cf[0]['sources'] ?? [], 'id'), $cf[0]['at_source'] ?? null]);
    } catch (Throwable $e) {
        $GLOBALS['t_fails']++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
    }
} else {
    $mode = (string) ($args[0] ?? 'run');
    if ($mode === 'setup') {
        $s = get_option('tix_settings', []);
        update_option('tix_mu_backup', ['settings' => $s, 'partners' => get_option(TIX_Partners::OPTION, null)], false);
        $s = array_merge(is_array($s) ? $s : [], ['syndication_enabled' => 1, 'syndication_api_url' => 'https://plat.test/wp-json/tixomat/v1', 'syndication_api_key' => str_repeat('s', 32),
            'partner_api_enabled' => 1, 'partner_api_key' => str_repeat('p', 32), 'co_remote_enabled' => 1]);
        update_option('tix_settings', $s);
        echo "setup ok\n"; return;
    }
    if ($mode === 'cleanup') {
        $b = get_option('tix_mu_backup');
        if (is_array($b)) { update_option('tix_settings', $b['settings']); if ($b['partners'] === null) delete_option(TIX_Partners::OPTION); else update_option(TIX_Partners::OPTION, $b['partners'], false); }
        delete_option('tix_mu_backup');
        echo "cleanup ok\n"; return;
    }
    if (!get_option('tix_mu_backup')) { echo "Erst `setup` ausführen.\n"; return; }
    echo "== Lizenzseite (Teil B)\n";
    try {
        wp_set_current_user($admin_id);
        $GLOBALS['mu_sent'] = [];
        add_filter('pre_http_request', function ($pre, $a, $url) {
            if ($pre !== false || strpos($url, '.test/') === false) return $pre;
            $b = json_decode((string) ($a['body'] ?? ''), true) ?: [];
            $GLOBALS['mu_sent'][] = $b;
            $oid = intval($b['organizer_id'] ?? 0);
            $reply = ($b['action'] ?? '') === 'invite' ? ['ok' => true, 'created' => false, 'co_organizer' => ['id' => $oid ?: 300 + count($GLOBALS['mu_sent']), 'name' => 'Co ' . ($oid ?: count($GLOBALS['mu_sent'])), 'status' => 'active']]
                : ['ok' => true, 'co_organizer' => null, 'co_organizers' => []];
            return ['headers' => [], 'body' => wp_json_encode($reply), 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
        }, 10, 3);
        $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Mutest KK', 'meta_input' => ['_tix_date_start' => '2027-05-04', '_tix_syndicate' => '1', '_tix_syndicate_remote_id' => 9001]]);
        $tmp_p[] = $ev;
        // Alt-Stand: ein Co im Einzelfeld + Hash
        $t1 = str_repeat('x', 48);
        update_post_meta($ev, '_tix_co_remote', ['organizer_id' => 501, 'name' => 'Alt Co', 'access' => true, 'status' => 'active', 'since' => gmdate('c')]);
        update_post_meta($ev, '_tix_co_token_hash', hash('sha256', $t1));
        mu_eq('Alt-Stand lesbar', [[501], true], [TIX_Co_Remote::ids($ev), TIX_Co_Remote::payload($ev, 501)['access'] ?? null]);
        [$st, $d] = mu_rest('POST', "/events/$ev/co-organizer/invite", ['organizer_id' => 502, 'access' => true, 'consent' => true]);
        mu_eq('zweiter Co: hinzugefügt, Alt-Co bleibt', [201, 'added', [501, 502]], [$st, $d['changed'] ?? '', mu_ids($d['co_organizers'] ?? [])]);
        mu_eq('Alt-Felder = erster Co, Liste mit Hash je Co', [501, hash('sha256', $t1), 2], [intval(get_post_meta($ev, '_tix_co_remote', true)['organizer_id'] ?? 0), get_post_meta($ev, '_tix_co_token_hash', true), count(array_filter(array_column((array) get_post_meta($ev, '_tix_co_remotes', true), 'token_hash')))]);
        $t2 = (string) ($GLOBALS['mu_sent'][count($GLOBALS['mu_sent']) - 1]['token'] ?? '');
        // Durchgriff je Schlüssel
        $call = function ($tok, $path) use ($ev) {
            wp_set_current_user(0);
            $r = mu_rest('POST', "/partner/co/$ev", ['method' => 'GET', 'path' => $path], ['X-Tix-Partner-Id' => 'plat.test', 'X-Tix-Partner-Key' => str_repeat('p', 32), 'X-Tix-Co-Token' => $tok]);
            wp_set_current_user($GLOBALS['mu_admin']);
            return $r;
        };
        $GLOBALS['mu_admin'] = $admin_id;
        $r1 = $call($t1, "/events/$ev/edit"); $r2 = $call($t2, "/events/$ev/edit"); $r3 = $call(str_repeat('z', 48), "/events/$ev/edit");
        mu_eq('Durchgriff: beide Schlüssel gelten, fremder nicht', [200, 200, 401], [$r1[0], $r2[0], $r3[0]]);
        $acts = get_users(['meta_key' => TIX_Co_Remote::USER_META, 'fields' => 'ID']);
        mu_eq('technisches Konto je Co', true, count(array_filter($acts, function ($u) { return in_array(intval(get_user_meta($u, TIX_Co_Remote::USER_META, true)), [501, 502], true); })) >= 2);
        // Zugriff je Co
        [$st] = mu_rest('POST', "/events/$ev", ['co_organizer' => ['id' => 502, 'access' => false]]);
        $last = mu_last_action();
        mu_eq('Zugriff 502 aus: Meldung mit organizer_id, 501 behält', [200, 'access', 502, false, true], [$st, $last['action'] ?? '', intval($last['organizer_id'] ?? 0), TIX_Co_Remote::payload($ev, 502)['access'] ?? null, TIX_Co_Remote::payload($ev, 501)['access'] ?? null]);
        mu_eq('Durchgriff 502 ohne Zugriff: 403, 501 weiter 200', [403, 200], [$call($t2, "/events/$ev/edit")[0], $call($t1, "/events/$ev/edit")[0]]);
        mu_rest('POST', "/events/$ev", ['co_organizer' => ['id' => 502, 'access' => true]]);
        // Geld: Co 502 nimmt ein, Anteile
        [$st] = mu_rest('POST', "/events/$ev", ['co_money' => ['owner_id' => 502, 'shares' => ['creator' => 60, 'o:501' => 10, 'o:502' => 30]]]);
        $last = mu_last_action();
        mu_eq('Geld an die Plattform: owner o:502, Anteile je Beteiligtem', [200, 'money', 'o:502', ['creator' => 60, 'o:501' => 10, 'o:502' => 30], 70], [$st, $last['action'] ?? '', $last['owner'] ?? '', (function ($x) { ksort($x); return $x; })((array) ($last['shares'] ?? [])), $last['share'] ?? null]);
        mu_eq('Vermittlung an die Plattform (Co verkauft)', '/partner/sale/9001', TIX_Co_Seller::source_base($ev));
        // Lösen per Schlüssel (Plattform → Lizenzseite)
        wp_set_current_user(0);
        $r = mu_rest('POST', "/partner/co/$ev/release", [], ['X-Tix-Partner-Id' => 'plat.test', 'X-Tix-Partner-Key' => str_repeat('p', 32), 'X-Tix-Co-Token' => $t1]);
        wp_set_current_user($admin_id);
        mu_eq('Plattform löst 501: nur 501 weg, Anteil an den Geldempfänger', [200, 501, [502], ['o:502' => 40.0, 'creator' => 60.0]], [$r[0], $r[1]['released_id'] ?? 0, TIX_Co_Remote::ids($ev), TIX_Co_Money::shares($ev)]);
        // Editor: lösen je Co
        [$st, $d] = mu_rest('DELETE', "/events/$ev/co-organizer", ['organizer_id' => 502]);
        $last = mu_last_action();
        mu_eq('Editor löst 502: clear mit organizer_id, Geld zurück', [200, 'clear', 502, [], 'creator'], [$st, $last['action'] ?? '', intval($last['organizer_id'] ?? 0), TIX_Co_Remote::ids($ev), TIX_Co_Money::owner($ev)]);
        mu_eq('alles leer: Alt-Felder weg', ['', ''], [get_post_meta($ev, '_tix_co_remote', true), get_post_meta($ev, '_tix_co_token_hash', true)]);
        // Zustimmung über die Plattform (1.38.717)
        $GLOBALS['co_auto_consent'] = false;
        $GLOBALS['mu_money_reply'] = ['ok' => true, 'co_money' => ['status' => 'waiting_consent', 'pending' => ['needs' => [['party_key' => 'o:601', 'done' => false], ['party_key' => 'creator', 'done' => true]]]]];
        add_filter('pre_http_request', function ($pre, $a, $url) {
            $b = json_decode((string) ($a['body'] ?? ''), true) ?: [];
            if (strpos($url, '.test/') === false || !in_array($b['action'] ?? '', ['money', 'consent'], true)) return $pre;
            $GLOBALS['mu_sent'][] = $b;
            return ['headers' => [], 'body' => wp_json_encode($b['action'] === 'money' ? $GLOBALS['mu_money_reply'] : ['ok' => true, 'state' => 'active', 'co_money' => ['owner_id' => 601, 'shares' => [['organizer_id' => 0, 'percent' => 80], ['organizer_id' => 601, 'percent' => 20, 'owner' => true]]]]), 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
        }, 5, 3);
        mu_rest('POST', "/events/$ev/co-organizer/invite", ['organizer_id' => 601, 'access' => true, 'consent' => true]);
        [$st] = mu_rest('POST', "/events/$ev", ['co_money' => ['owner_id' => 601, 'share' => 20]]);
        $m = TIX_Co_Money::payload($ev);
        mu_eq('Lizenzseite: Co als Geldempfänger wartet auf Zustimmung (Plattform), bisher Ersteller', [200, 'waiting_consent', 'creator', 'money', 'creator'], [$st, $m['status'] ?? '', TIX_Co_Money::owner($ev), mu_last_action()['action'] ?? '', mu_last_action()['via'] ?? '']);
        wp_set_current_user(0);
        $r = mu_rest('POST', "/partner/co/$ev/money", ['state' => 'active', 'owner' => 'o:601', 'shares' => ['o:601' => 80, 'creator' => 20], 'consents' => ['o:601' => ['at' => gmdate('c'), 'version' => TIX_Co_Money::CONSENT_VERSION]]],
            ['X-Tix-Partner-Id' => 'plat.test', 'X-Tix-Partner-Key' => str_repeat('p', 32)]);
        wp_set_current_user($admin_id);
        mu_eq('Rückmeldung der Plattform: wirksam, Nachweis übernommen', [200, 601, 'active', true], [$r[0], TIX_Co_Money::owner_id($ev), TIX_Co_Money::payload($ev)['status'] ?? '', count((array) get_post_meta($ev, TIX_Co_Money::META_CONSENT_LOG, true)) >= 1]);
        mu_eq('Verkäufer-Angaben dieser Seite', true, is_array(TIX_Co_Legal::site()) && array_key_exists('imprint_url', TIX_Co_Legal::site()));
    } catch (Throwable $e) {
        $GLOBALS['t_fails']++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
    }
}

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($tmp_p as $p) wp_delete_post($p, true);
foreach ($tmp_u as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
