<?php
/** Audit Promoter-System (1.38.643): Provision, Erstattung, Selbstkauf, Mandanten, Anmeldung, Sitzungen, Studio-REST.
 *  wp eval-file …/tests/test-promoter-audit-wp.php (Einzel-Seite und Plattform). Mails über pre_wp_mail abgefangen. */
if (!defined('ABSPATH') || !class_exists('TIX_Promoter_REST')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function pa_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function pa_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
TIX_Order::create_tables(); TIX_Order_Attribution::maybe_create();
TIX_Promoter_DB::create_tables(); TIX_Promoter_DB::ensure_email_column(); TIX_Promoter_DB::ensure_org_column(); TIX_Promoter_DB::ensure_clicks_table();
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $orders = []; $proms = [];
$tc = TIX_Promoter_DB::table_commissions(); $tp = TIX_Promoter_DB::table_promoters();
$GLOBALS['pa_mails'] = [];
add_filter('pre_wp_mail', function ($n, $a) { $GLOBALS['pa_mails'][] = $a; return true; }, 10, 2);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_promoter\\_signup\\_%' OR option_name LIKE '\\_transient\\_%tix\\_promoter\\_auth\\_rl\\_%' OR option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_pp\\_signup\\_%'");
$coupons_before = get_option('tix_coupons', null);

$owner = wp_insert_user(['user_login' => "pa_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "pa_owner_$sfx@example.test", 'role' => $multi ? 'tix_organizer' : 'administrator']); $users[] = $owner;
$other = wp_insert_user(['user_login' => "pa_other_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "pa_other_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $other;
$org = 0; $org2 = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'PA Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'PA Andere', 'meta_input' => ['_tix_org_user_id' => $other]]); $posts[] = $org2;
}
$mkev = function ($t, $o) use (&$posts) { $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "PA $t", 'meta_input' => ['_tix_organizer_id' => $o, '_tix_date_start' => wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS)]]); $posts[] = $id; return $id; };
$ev = $mkev("Party $sfx", $org); $ev2 = $mkev("Fremd $sfx", $org2);
$ot = TIX_Order::table_name(); $oi = TIX_Order::items_table_name();
$mk_order = function ($email, $pid, $event, $total = 40.0, $discount = 0.0, $cid = 0) use ($wpdb, $ot, $oi, &$orders) {
    $wpdb->insert($ot, ['order_number' => 'PA' . wp_rand(), 'status' => 'completed', 'order_key' => wp_generate_password(12, false), 'total' => $total - $discount, 'subtotal' => $total, 'discount' => $discount, 'payment_method' => 'mollie', 'billing_email' => $email, 'customer_id' => $cid, 'date_created' => current_time('mysql')]);
    $id = intval($wpdb->insert_id); $orders[] = $id;
    $wpdb->insert($oi, ['order_id' => $id, 'event_id' => $event, 'quantity' => 2, 'total' => $total, 'name' => 'PA – Regulär', 'cat_name' => 'Regulär']);
    TIX_Order_Attribution::set_meta($id, '_tix_promoter_id', $pid);
    TIX_Order_Attribution::set_meta($id, '_tix_promoter_attribution', 'referral');
    return $id;
};
$com = function ($oid) use ($wpdb, $tc) { return $wpdb->get_results($wpdb->prepare("SELECT status, commission_amount a, order_total t, attribution, payout_id FROM $tc WHERE order_id = %d ORDER BY id", $oid)); };
$net = function ($oid) use ($wpdb, $tc) { return round(floatval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(commission_amount),0) FROM $tc WHERE order_id = %d AND status != 'cancelled'", $oid))), 2); };

// ═══ Studio: Promoter anlegen ═══
wp_set_current_user($owner);
[$st, $r, $code] = pa_rest('POST', '/organizer/promoters', ['name' => 'Lisa Promo', 'email' => "lisa_$sfx@example.test", 'commission' => ['type' => 'percent', 'value' => 10], 'send_access' => true]);
$P = $r['promoter'] ?? []; $pid = intval($P['id'] ?? 0); $proms[] = $pid;
pa_eq('Anlegen: 201, Code erzeugt, Links mit ?ref=, Zugang verschickt', [201, true, true, true, true], [$st, (bool) preg_match('/^lisapromo\d+$/', $P['code'] ?? ''), strpos($P['links']['link'] ?? '', 'ref=' . ($P['code'] ?? 'x')) !== false, strpos($P['links']['qr_link'] ?? '', 'utm_medium=qr') !== false, $r['access_sent'] ?? false]);
// seit 1.38.662 können vorher „Neues Event“-Mails an vorhandene Promoter rausgehen → Lisas Mail suchen
$lisa = []; foreach ($GLOBALS['pa_mails'] as $mm) if ((array) $mm['to'] === ["lisa_$sfx@example.test"] || ($mm['to'] ?? '') === "lisa_$sfx@example.test") { $lisa = $mm; break; }
pa_eq('Login-Mail an die Promoter-Adresse mit Magic-Link', [true, true], [!empty($lisa), strpos(html_entity_decode((string) ($lisa['message'] ?? '')), '/promoter/anmelden/?t=') !== false]);
pa_eq('Promoter gehört dem Bereich', $org, intval($wpdb->get_var($wpdb->prepare("SELECT organizer_id FROM $tp WHERE id = %d", $pid))));
[$st, $d, $code] = pa_rest('POST', '/organizer/promoters', ['name' => 'Doppelt', 'email' => "lisa_$sfx@example.test"]);
pa_eq('Gleiche E-Mail zweimal → 409', [409, 'tix_promoter_exists'], [$st, $code]);
[$st, $d, $code] = pa_rest('POST', '/organizer/promoters', ['name' => 'Code', 'code' => $P['code']]);
pa_eq('Vergebener Code → 409 tix_code_taken', [409, 'tix_code_taken'], [$st, $code]);
[$st, $d] = pa_rest('POST', "/organizer/promoters/$pid/events", ['event_id' => $ev, 'commission_type' => 'fixed', 'commission_value' => 2, 'discount_type' => 'percent', 'discount_value' => 10]);
pa_eq('Rabatt ohne Promo-Code → 400 promo_code', [400, 'promo_code'], [$st, $d['data']['field'] ?? '']);
[$st, $d] = pa_rest('POST', "/organizer/promoters/$pid/events", ['event_id' => $ev, 'commission_type' => 'percent', 'commission_value' => 80]);
pa_eq('Provision über 50 % → 400', 400, $st);
if ($multi) {
    [$st] = pa_rest('POST', "/organizer/promoters/$pid/events", ['event_id' => $ev2, 'commission_type' => 'fixed', 'commission_value' => 2]);
    pa_eq('Fremdes Event zuordnen → 404', 404, $st);
}
[$st, $g] = pa_rest('POST', "/organizer/promoters/$pid/events", ['event_id' => $ev, 'commission_type' => 'fixed', 'commission_value' => 3, 'discount_type' => 'percent', 'discount_value' => 10, 'promo_code' => "LISA$sfx"]);
$as = array_column((array) ($g['promoter']['assignments'] ?? []), null, 'event_id');
pa_eq('Event-Provision mit Promo-Code; Standard für alle (Event 0) bleibt', [200, 3.0, "LISA$sfx", 10.0], [$st, $as[$ev]['commission_value'] ?? null, $as[$ev]['promo_code'] ?? '', $as[0]['commission_value'] ?? null]);
update_option('tix_coupons', array_merge((array) $coupons_before, ["BELEGT$sfx" => ['code' => "BELEGT$sfx", 'discount_type' => 'percent', 'value' => 5]]));
[$st, $d, $code] = pa_rest('POST', "/organizer/promoters/$pid/events", ['event_id' => $ev, 'commission_type' => 'fixed', 'commission_value' => 3, 'promo_code' => "BELEGT$sfx", 'discount_type' => 'percent', 'discount_value' => 5]);
pa_eq('Promo-Code = vorhandener Gutschein → 409', [409, 'tix_code_taken'], [$st, $code]);

// ═══ Provision ═══
$o1 = $mk_order("kunde1_$sfx@example.test", $pid, $ev, 40.0);
TIX_Promoter::calculate_commissions($o1);
pa_eq('Kauf: 3 € je Ticket × 2 = 6 €', 6.0, $net($o1));
TIX_Promoter::calculate_commissions($o1);
pa_eq('Doppelt abgeschlossen → keine zweite Provision', 1, count($com($o1)));
// Gutschein-Rabatt anteilig: Standard 10 % auf das Fremd-Event? Nein – dafür ein zweites eigenes Event mit Standard-Provision
$ev3 = $mkev("Zweites $sfx", $org);
$o2 = $mk_order("kunde2_$sfx@example.test", $pid, $ev3, 40.0, 10.0);
update_option('_tix_order_coupon_' . $o2, ['code' => "RABATT$sfx", 'discount' => 10], false);
TIX_Promoter::calculate_commissions($o2);
pa_eq('Gutschein-Rabatt mindert die Basis: 10 % von 30 € = 3 €', [3.0, 30.0], [$net($o2), floatval($com($o2)[0]->t ?? 0)]);
update_option('tix_coupons', array_merge((array) get_option('tix_coupons', []), ["GIFT$sfx" => ['code' => "GIFT$sfx", 'discount_type' => 'giftcard', 'value' => 50]]));
$o3 = $mk_order("kunde3_$sfx@example.test", $pid, $ev3, 40.0, 15.0);
update_option('_tix_order_coupon_' . $o3, ['code' => "GIFT$sfx", 'discount' => 15], false);
TIX_Promoter::calculate_commissions($o3);
pa_eq('Geschenkgutschein (Teilzahlung mit Guthaben) mindert nicht: 10 % von 40 €', 4.0, $net($o3));
$o4 = $mk_order("lisa_$sfx@example.test", $pid, $ev, 40.0);
TIX_Promoter::calculate_commissions($o4);
pa_eq('Selbstkauf mit eigener E-Mail → keine Provision, markiert', [0, $pid], [count($com($o4)), intval(get_option('_tix_promoter_self_' . $o4))]);
if ($multi) {
    $o5 = $mk_order("kunde5_$sfx@example.test", $pid, $ev2, 40.0);
    TIX_Promoter::calculate_commissions($o5);
    pa_eq('Mandanten: Event eines anderen Veranstalters → keine Provision (trotz Standard-Provision)', 0, count($com($o5)));
    $pa = TIX_Promoter_DB::insert_promoter(['organizer_id' => 0, 'email' => "plat_$sfx@example.test", 'promoter_code' => "plat$sfx", 'display_name' => 'Plattform', 'status' => 'active']); $proms[] = $pa;
    TIX_Promoter_DB::assign_event(['promoter_id' => $pa, 'event_id' => 0, 'commission_type' => 'percent', 'commission_value' => 10]);
    $o6 = $mk_order("kunde6_$sfx@example.test", $pa, $ev, 40.0);
    TIX_Promoter::calculate_commissions($o6);
    pa_eq('Plattform-Promoter ohne Event-Zuordnung → keine Provision', 0, count($com($o6)));
    TIX_Promoter_DB::assign_event(['promoter_id' => $pa, 'event_id' => $ev, 'commission_type' => 'fixed', 'commission_value' => 1]);
    $o7 = $mk_order("kunde7_$sfx@example.test", $pa, $ev, 40.0);
    TIX_Promoter::calculate_commissions($o7);
    pa_eq('… mit ausdrücklicher Event-Zuordnung → 2 €', 2.0, $net($o7));
    $scope = TIX_Promoter::scope_event_ids(TIX_Promoter_DB::get_promoter($pid));
    pa_eq('Dashboard-Events: eigene ja, fremde nein', [true, false], [in_array($ev, $scope, true), in_array($ev2, $scope, true)]);
}

// ═══ Erstattung, Storno, Rückbuchung ═══
TIX_Promoter::on_refund($o3, 10.0, false);
pa_eq('Teil-Erstattung 10 € von 25 € → 40 % zurück (4 € → 2,40 €)', 2.4, $net($o3));
TIX_Promoter::cancel_commissions($o3);
pa_eq('Danach Storno: Rest auf 0, offene Zeilen storniert', [0.0, ['cancelled', 'cancelled']], [$net($o3), array_column($com($o3), 'status')]);
TIX_Promoter::cancel_commissions($o3);
pa_eq('Zweites Storno ändert nichts', 0.0, $net($o3));
// Auszahlung, danach Erstattung → Gegenbuchung mindert die nächste
[$st, $d, $code] = pa_rest('POST', '/organizer/promoter-payouts', ['promoter_id' => $pid]);
[$st2, $Lw] = pa_rest('GET', '/organizer/promoters');
$mw = array_column((array) ($Lw['promoters'] ?? []), null, 'id')[$pid] ?? [];
pa_eq('Event steht noch aus → keine Auszahlung (409 tix_payout_waiting), 9 € warten', [409, 'tix_payout_waiting', 0.0, 9.0], [$st, $code, $mw['balance'] ?? null, $mw['balance_waiting'] ?? null]);
// Events vorbei (Party bis 5 Uhr am Folgetag zählt erst danach)
$past = wp_date('Y-m-d', time() - 3 * DAY_IN_SECONDS);
foreach ([$ev, $ev3] as $e) { update_post_meta($e, '_tix_date_start', $past); update_post_meta($e, '_tix_time_start', '22:00'); update_post_meta($e, '_tix_time_end', '05:00'); }
$evn = $mkev("Nacht $sfx", $org); update_post_meta($evn, '_tix_date_start', wp_date('Y-m-d')); update_post_meta($evn, '_tix_time_start', '00:01'); update_post_meta($evn, '_tix_time_end', '00:00');
$evy = $mkev("Gestern $sfx", $org); update_post_meta($evy, '_tix_date_start', wp_date('Y-m-d', time() - DAY_IN_SECONDS)); update_post_meta($evy, '_tix_time_start', '23:58'); update_post_meta($evy, '_tix_time_end', '23:59');
pa_eq('Event-Ende: vorbei; Ende 0 Uhr am Folgetag noch nicht; gestern 23:59 vorbei', [true, false, true], [TIX_Promoter_DB::event_ended($ev), TIX_Promoter_DB::event_ended($evn), TIX_Promoter_DB::event_ended($evy)]);
[$st, $po] = pa_rest('POST', '/organizer/promoter-payouts', ['promoter_id' => $pid, 'note' => 'Test']);
$poid = intval($po['payout']['id'] ?? 0);
pa_eq('Auszahlung: offene Provision 6 € + 3 € = 9 €', [201, 9.0], [$st, $po['payout']['commission'] ?? null]);
[$st] = pa_rest('POST', "/organizer/promoter-payouts/$poid/paid", ['note' => 'überwiesen']);
pa_eq('Als bezahlt markiert', [200, 'paid'], [$st, $com($o1)[0]->status ?? '']);
TIX_Promoter::cancel_commissions($o1);
$c1 = $com($o1);
pa_eq('Storno nach Auszahlung: Gegenbuchung −6 € offen, bezahlte Zeile bleibt', [2, 'paid', -6.0, 'pending', 0.0], [count($c1), $c1[0]->status, floatval($c1[1]->a), $c1[1]->status, $net($o1)]);
[$st, $d, $code] = pa_rest('POST', '/organizer/promoter-payouts', ['promoter_id' => $pid]);
pa_eq('Nur Gegenbuchung offen → keine Auszahlung (409 tix_payout_empty)', [409, 'tix_payout_empty'], [$st, $code]);
TIX_Promoter::on_chargeback($o2, 30.0);
pa_eq('Rückbuchung des ganzen Betrags → Provision 0', 0.0, $net($o2));
[$st, $L] = pa_rest('GET', '/organizer/promoters');
$me = array_column((array) ($L['promoters'] ?? []), null, 'id')[$pid] ?? [];
pa_eq('Liste: Zahlen nach Stornos, Guthaben negativ (Rückforderung −6 € und −3 €)', [200, 9.0, -9.0], [$st, $me['commission']['paid'] ?? null, $me['balance'] ?? null]);
[$st, $D] = pa_rest('GET', "/organizer/promoters/$pid");
pa_eq('Detail: Verkäufe mit Art (sale/refund/chargeback/cancel), Auszahlungen', [200, true, 1], [$st, in_array('cancel', array_column((array) ($D['promoter']['sales'] ?? []), 'type'), true) || in_array('refund', array_column((array) ($D['promoter']['sales'] ?? []), 'type'), true), count((array) ($D['promoter']['payouts'] ?? []))]);

// ═══ Fremder Zugriff ═══
if ($multi) {
    wp_set_current_user($other);
    [$st] = pa_rest('GET', "/organizer/promoters/$pid");
    pa_eq('Anderer Veranstalter sieht den Promoter nicht → 404', 404, $st);
    [$st] = pa_rest('POST', "/organizer/promoter-payouts/$poid/cancel");
    pa_eq('… und keine fremde Auszahlung → 404', 404, $st);
    [$st, $L2] = pa_rest('GET', '/organizer/promoters');
    pa_eq('… Liste leer', 0, count((array) ($L2['promoters'] ?? [])));
    [$st] = pa_rest('POST', '/organizer/promoter-settings', ['enabled' => true]);
    pa_eq('Plattform-Einstellungen nur Admin → 403', 403, $st);
    wp_set_current_user($owner);
}
wp_set_current_user(0);
[$st] = pa_rest('GET', '/organizer/promoters');
pa_eq('Ohne Anmeldung → 401', 401, $st);
wp_set_current_user($owner);

// ═══ Sitzungen ═══
TIX_Promoter_Auth::set_session_cookie($pid);
pa_eq('Sitzung gültig', $pid, TIX_Promoter_Auth::get_session_promoter_id());
[$st] = pa_rest('POST', "/organizer/promoters/$pid/logout-all");
pa_eq('Überall abmelden → alte Sitzung ungültig', [200, 0], [$st, TIX_Promoter_Auth::get_session_promoter_id()]);
TIX_Promoter_Auth::set_session_cookie($pid);
[$st] = pa_rest('POST', "/organizer/promoters/$pid", ['status' => 'inactive']);
pa_eq('Deaktivieren beendet die Sitzung', [200, 0], [$st, TIX_Promoter_Auth::get_session_promoter_id()]);
unset($_COOKIE[TIX_Promoter_Auth::COOKIE_NAME]);
[$st] = pa_rest('POST', "/organizer/promoters/$pid", ['status' => 'active']);
for ($i = 0; $i < 3; $i++) pa_rest('POST', "/organizer/promoters/$pid/access");
[$st, $d, $code] = pa_rest('POST', "/organizer/promoters/$pid/access");
pa_eq('Login-Links: höchstens 3 je 15 Minuten → 429', [429, 'tix_rate_limit'], [$st, $code]);

// ═══ Selbst-Anmeldung (Formular) ═══
if (!class_exists('TIX_Promoter_Signup')) require_once TIXOMAT_PATH . 'includes/class-tix-promoter-signup.php';
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', function () { return function () { throw new Exception('die'); }; });
// seit 1.38.660 läuft der Formular-Weg über die Portal-Anmeldung: nur mit Selbst-Anmeldung, Status pending
$pa_set = get_option('tix_settings', []); update_option('tix_settings', array_merge((array) $pa_set, ['promoter_enabled' => 1, 'promoter_self_signup' => 1]));
$signup = function ($name, $email) use ($multi, $org) {
    $_POST = ['display_name' => $name, 'email' => $email] + ($multi ? ['organizer_id' => $org] : []); $_REQUEST = $_POST + ['tix_ps_nonce' => wp_create_nonce('tix_promoter_signup')]; $_POST['tix_ps_nonce'] = $_REQUEST['tix_ps_nonce'];
    ob_start(); try { TIX_Promoter_Signup::handle_signup(); } catch (Exception $e) {} $o = ob_get_clean();
    return json_decode($o, true);
};
wp_set_current_user(0);
$n0 = count($GLOBALS['pa_mails']);
$res = $signup('Fremder', "pa_owner_$sfx@example.test");
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tp WHERE email = %s ORDER BY id DESC LIMIT 1", "pa_owner_$sfx@example.test")); // wartet (pending), get_promoter_by_email liefert nur aktive
pa_eq('Anmeldung mit fremder Konto-E-Mail: kein Code in der Antwort, kein Konto-Link, Mail an die Adresse', [true, true, false, 0, true],
    [$res['success'] ?? false, !empty($res['data']['sent']), isset($res['data']['code']), intval($row->user_id ?? -1), count($GLOBALS['pa_mails']) > $n0]);
if ($row) $proms[] = intval($row->id);
$res2 = $signup('Neu', "neu_$sfx@example.test");
$row2 = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tp WHERE email = %s ORDER BY id DESC LIMIT 1", "neu_$sfx@example.test"));
pa_eq('Neue E-Mail: Promoter ohne WordPress-Konto angelegt (vorher Absturz: create_promoter fehlte)', [true, true, false], [$res2['success'] ?? false, (bool) $row2, (bool) get_user_by('email', "neu_$sfx@example.test")]);
if ($row2) $proms[] = intval($row2->id);
pa_eq('Formular-Weg legt nur wartende Promoter an', ['pending', 'pending'], [(string) ($row->status ?? ''), (string) ($row2->status ?? '')]);
update_option('tix_settings', $pa_set);
remove_all_filters('wp_doing_ajax');

// ═══ Klicks: Bots zählen nicht ═══
$_GET['ref'] = $P['code']; $_SERVER['HTTP_USER_AGENT'] = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php) bot';
$before = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Promoter_DB::table_clicks() . " WHERE promoter_id = %d", $pid)));
TIX_Promoter::detect_referral();
pa_eq('Vorschau-Bot setzt keinen Klick', $before, intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Promoter_DB::table_clicks() . " WHERE promoter_id = %d", $pid))));
unset($_GET['ref']);

// Aufräumen
wp_set_current_user(1);
foreach ($orders as $o) { $wpdb->delete($ot, ['id' => $o]); $wpdb->delete($oi, ['order_id' => $o]); $wpdb->delete(TIX_Order_Attribution::table(), ['order_id' => $o]); $wpdb->delete($tc, ['order_id' => $o]);
    foreach (['_tix_order_coupon_', '_tix_promoter_self_', '_tix_promoter_reversed_'] as $k) delete_option($k . $o); }
foreach (array_unique(array_filter($proms)) as $p) { $wpdb->delete($tp, ['id' => $p]); $wpdb->delete(TIX_Promoter_DB::table_events(), ['promoter_id' => $p]); $wpdb->delete(TIX_Promoter_DB::table_payouts(), ['promoter_id' => $p]); $wpdb->delete(TIX_Promoter_DB::table_clicks(), ['promoter_id' => $p]); delete_option('tix_promoter_epoch_' . $p); }
if ($coupons_before === null) delete_option('tix_coupons'); else update_option('tix_coupons', $coupons_before);
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
