<?php
/** Promoter-Ausbau Paket 1 (1.38.660): Rabatt per Link, System-Events ohne Provision, alter AJAX-Weg geschlossen,
 *  Veranstalter-Mitteilung bei Anmeldung, Liste „Wartet auf Freigabe“, Zuordnung aus der Kasse nur für diese Bestellung.
 *  wp eval-file …/tests/test-promoter-p1-wp.php (beide Modi). */
if (!defined('ABSPATH') || !class_exists('TIX_Promoter_REST') || !method_exists('TIX_Promoter', 'excluded_event')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function p1_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function p1_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
TIX_Order::create_tables(); TIX_Order_Attribution::maybe_create();
TIX_Promoter_DB::create_tables(); TIX_Promoter_DB::ensure_email_column(); TIX_Promoter_DB::ensure_org_column(); TIX_Promoter_DB::ensure_clicks_table();
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $orders = []; $proms = [];
$tc = TIX_Promoter_DB::table_commissions(); $tp = TIX_Promoter_DB::table_promoters();
$mails = []; add_filter('pre_wp_mail', function ($n, $a) use (&$mails) { $mails[] = $a; return true; }, 10, 2);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_p%' OR option_name LIKE '\\_transient\\_timeout\\_%tix\\_app\\_rl\\_p%'");
$settings_before = get_option('tix_settings', []);
$q_before = get_option(TIX_Staff_Push::OPT_EVENT_Q, null);
$admin = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
$owner = wp_insert_user(['user_login' => "p1_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "p1_owner_$sfx@example.test", 'role' => $multi ? 'tix_organizer' : 'administrator']); $users[] = $owner;
$org = 0;
if ($multi) { $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'P1 Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org; }
$mkev = function ($t, $meta = []) use (&$posts, $org) { $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "P1 $t", 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS)] + $meta]); $posts[] = $id; return $id; };
$ev = $mkev("Party $sfx");
$gift = $mkev("Gutscheine $sfx", ['_tix_system_giftcard_event' => '1', '_tix_date_start' => '']);
$wal = $mkev("Guthaben $sfx", ['_tix_system_wallet_event' => '1']);
$pid = TIX_Promoter_DB::insert_promoter(['user_id' => 0, 'organizer_id' => $org, 'email' => "p1_prom_$sfx@example.test", 'promoter_code' => "p1prom$sfx", 'display_name' => 'P1 Promo', 'status' => 'active']); $proms[] = $pid;
TIX_Promoter_DB::assign_event(['promoter_id' => $pid, 'event_id' => $ev, 'commission_type' => 'fixed', 'commission_value' => 2, 'discount_type' => 'percent', 'discount_value' => 10, 'promo_code' => "P1RABATT$sfx", 'status' => 'active']);
TIX_Promoter_DB::assign_event(['promoter_id' => $pid, 'event_id' => $gift, 'commission_type' => 'percent', 'commission_value' => 10, 'status' => 'active']);
TIX_Promoter_DB::assign_event(['promoter_id' => $pid, 'event_id' => 0, 'commission_type' => 'percent', 'commission_value' => 5, 'status' => 'active']);

// ═══ 1. Rabatt per Link ═══
$l = TIX_Promoter_REST::links("p1prom$sfx", 'https://example.test/events/party/', "P1RABATT$sfx");
p1_eq('Link mit Rabatt-Code: ref + coupon an Link und QR-Link', [true, true, "P1RABATT$sfx"], [strpos($l['link'], "coupon=P1RABATT$sfx") !== false && strpos($l['link'], "ref=p1prom$sfx") !== false, strpos($l['qr_link'], 'coupon=') !== false && strpos($l['qr_link'], 'utm_medium=qr') !== false, $l['coupon']]);
$l0 = TIX_Promoter_REST::links("p1prom$sfx", 'https://example.test/');
p1_eq('Ohne Rabatt: kein coupon', [false, null], [strpos($l0['link'], 'coupon') !== false, $l0['coupon']]);
wp_set_current_user($multi ? $owner : $admin);
[$st, $d] = p1_rest('GET', "/organizer/promoters/$pid");
$as = array_column((array) ($d['promoter']['assignments'] ?? []), null, 'event_id');
$cp = function ($a) { return (is_array($a) && array_key_exists('coupon', (array) ($a['links'] ?? []))) ? $a['links']['coupon'] : 'fehlt'; };
p1_eq('Studio: Event-Link trägt den Rabatt-Code, Event ohne Rabatt und „Alle Events“ nicht', [200, "P1RABATT$sfx", null, null],
    [$st, $cp($as[$ev] ?? null), $cp($as[$gift] ?? null), $cp($as[0] ?? null)]);
p1_eq('Rabatt-Code greift in der nativen Kasse (resolve_coupon)', [true, 10.0], [!empty(TIX_Native_Checkout::resolve_coupon("P1RABATT$sfx")['is_promoter']), floatval(TIX_Native_Checkout::resolve_coupon("P1RABATT$sfx")['value'] ?? 0)]);

// ═══ 2. Gutschein- und Guthaben-Events ohne Provision ═══
$s = get_option('tix_settings', []); $s['promoter_default_commission_value'] = 3; $s['promoter_default_commission_type'] = 'fixed'; update_option('tix_settings', $s);
p1_eq('System-Events: keine Provision (auch nicht über Zuordnung oder Vorgabe)', ['none', 'none', 'event'],
    [TIX_Promoter::get_effective_commission($pid, $gift)['source'], TIX_Promoter::get_effective_commission($pid, $wal)['source'], TIX_Promoter::get_effective_commission($pid, $ev)['source']]);
$ot = TIX_Order::table_name(); $oi = TIX_Order::items_table_name();
$wpdb->insert($ot, ['order_number' => 'P1' . wp_rand(), 'status' => 'completed', 'order_key' => wp_generate_password(12, false), 'total' => 90, 'subtotal' => 90, 'payment_method' => 'mollie', 'billing_email' => "p1_kunde_$sfx@example.test", 'date_created' => current_time('mysql')]);
$o1 = intval($wpdb->insert_id); $orders[] = $o1;
$wpdb->insert($oi, ['order_id' => $o1, 'event_id' => $gift, 'quantity' => 1, 'total' => 50, 'name' => 'Gutschein 50 €']);
$wpdb->insert($oi, ['order_id' => $o1, 'event_id' => $ev, 'quantity' => 2, 'total' => 40, 'name' => 'P1 – Regulär']);
TIX_Order_Attribution::set_meta($o1, '_tix_promoter_id', $pid);
TIX_Promoter::calculate_commissions($o1);
$rows = $wpdb->get_results($wpdb->prepare("SELECT event_id, commission_amount a FROM $tc WHERE order_id = %d", $o1));
p1_eq('Bestellung mit Gutschein + Ticket: Provision nur für das Ticket', [1, $ev, 4.0], [count($rows), intval($rows[0]->event_id ?? 0), floatval($rows[0]->a ?? 0)]);
p1_eq('System-Events fehlen in der Event-Liste des Promoters (Plattform)', true, !$multi || !in_array($gift, (array) TIX_Promoter::scope_event_ids(TIX_Promoter_DB::get_promoter($pid)), true));

// ═══ 3. Alter AJAX-Weg und Portal-Anmeldung ═══
if (!class_exists('TIX_Promoter_Signup')) require_once TIXOMAT_PATH . 'includes/class-tix-promoter-signup.php'; // lädt nur bei eingeschaltetem Promoter-System
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', function () { return function () { throw new Exception('die'); }; });
$ajax = function (array $post) {
    $_POST = $post + ['tix_ps_nonce' => wp_create_nonce('tix_promoter_signup')]; $_REQUEST = $_POST;
    ob_start(); try { TIX_Promoter_Signup::handle_signup(); } catch (Exception $e) {} $out = ob_get_clean();
    return json_decode($out, true);
};
wp_set_current_user(0);
$s = get_option('tix_settings', []); $s['promoter_enabled'] = 1; $s['promoter_self_signup'] = 0; $s['promoter_signup_auto_events'] = 1; update_option('tix_settings', $s);
$r = $ajax(['display_name' => 'Ajax Eins', 'email' => "p1_ajax1_$sfx@example.test"] + ($multi ? ['organizer_id' => $org] : []));
$n1 = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tp WHERE email = %s", "p1_ajax1_$sfx@example.test")));
p1_eq('AJAX bei abgeschalteter Selbst-Anmeldung: Fehler, kein Promoter', [false, 0], [$r['success'] ?? null, $n1]);
$s['promoter_self_signup'] = 1; update_option('tix_settings', $s);
$mails = [];
$GLOBALS['p1_q0'] = count((array) get_option(TIX_Staff_Push::OPT_EVENT_Q, []));
$r = $ajax(['display_name' => 'Ajax Zwei', 'email' => "p1_ajax2_$sfx@example.test"] + ($multi ? ['organizer_id' => $org] : []));
$pr = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tp WHERE email = %s", "p1_ajax2_$sfx@example.test")); if ($pr) $proms[] = intval($pr->id);
$asg = $pr ? count((array) TIX_Promoter_DB::get_promoter_events(intval($pr->id))) : -1;
p1_eq('AJAX: wartet auf Freigabe statt aktiv, keine Events, kein Code in der Antwort', [true, 'pending', 0, false], [$r['success'] ?? null, $pr->status ?? '', $asg, isset($r['data']['code'])]);
$subjects = array_map(function ($m) { return (string) $m['subject']; }, $mails);
$orgmail = array_values(array_filter($mails, function ($m) { return strpos((string) $m['subject'], 'Neue Promoter-Anmeldung') === 0; }));
$to = $orgmail ? (array) $orgmail[0]['to'] : [];
p1_eq('Veranstalter bekommt eine Mail mit Knopf zur Freigabe', [1, $multi ? "p1_owner_$sfx@example.test" : true, true],
    [count($orgmail), $multi ? ($to[0] ?? '') : !empty($to), strpos((string) ($orgmail[0]['message'] ?? ''), 'Anmeldung prüfen') !== false]);
$q = array_slice((array) get_option(TIX_Staff_Push::OPT_EVENT_Q, []), $GLOBALS['p1_q0']);
p1_eq('Push-Warteschlange: Neue Promoter-Anmeldung', ['promoter_signup', 'Ajax Zwei'], [$q[0]['type'] ?? '', $q[0]['x']['name'] ?? '']);
// Portal-Weg (REST) ebenfalls mit Mitteilung
$mails = [];
[$st, $d] = p1_rest('POST', '/promoter/signup', ['name' => 'Portal Drei', 'email' => "p1_portal_$sfx@example.test"] + ($multi ? ['organizer_id' => $org] : []));
$pr3 = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tp WHERE email = %s", "p1_portal_$sfx@example.test")); if ($pr3) $proms[] = intval($pr3->id);
p1_eq('Portal-Anmeldung: pending + Mitteilung', [200, 'pending', 1], [$st, $pr3->status ?? '', count(array_filter($mails, function ($m) { return strpos((string) $m['subject'], 'Neue Promoter-Anmeldung') === 0; }))]);

// ═══ 4. Liste „Wartet auf Freigabe“ ═══
remove_all_filters('wp_doing_ajax');
wp_set_current_user($multi ? $owner : $admin);
[$st, $d] = p1_rest('GET', '/organizer/promoters/pending');
$names = array_column((array) ($d['promoters'] ?? []), 'name');
p1_eq('GET /organizer/promoters/pending: Zähler und Liste', [200, true, true], [$st, ($d['count'] ?? 0) >= 2, in_array('Ajax Zwei', $names, true) && in_array('Portal Drei', $names, true)]);
[$st, $d] = p1_rest('GET', '/organizer/promoters');
p1_eq('Liste trägt pending_count', true, intval($d['pending_count'] ?? 0) >= 2);
[$st, $d] = p1_rest('POST', '/organizer/promoters/' . intval($pr->id), ['status' => 'active']);
[$st2, $d2] = p1_rest('GET', '/organizer/promoters/pending');
p1_eq('Freigeben: aus der Liste raus', [200, 'active', false], [$st, $d['promoter']['status'] ?? '', in_array('Ajax Zwei', array_column((array) ($d2['promoters'] ?? []), 'name'), true)]);
wp_set_current_user(0);
[$st] = p1_rest('GET', '/organizer/promoters/pending');
p1_eq('Ohne Anmeldung → 401', 401, $st);

// ═══ 5. Zuordnung aus der Kasse ohne Einwilligung, nur für diese Bestellung ═══
$_COOKIE = [];
$wpdb->insert($ot, ['order_number' => 'P1' . wp_rand(), 'status' => 'pending', 'order_key' => wp_generate_password(12, false), 'total' => 20, 'payment_method' => 'mollie', 'billing_email' => "p1_kunde2_$sfx@example.test", 'date_created' => current_time('mysql')]);
$o2 = intval($wpdb->insert_id); $orders[] = $o2;
$m = new ReflectionMethod('TIX_App_Checkout', 'apply_attribution'); $m->setAccessible(true);
$tr_before = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tix\\_pref\\_%'"));
$m->invoke(null, $o2, ['promoter_code' => "p1prom$sfx"], []);
$tr_after = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tix\\_pref\\_%'"));
p1_eq('attribution.promoter_code ohne Einwilligung: Bestellung zugeordnet, kein Cookie, nichts gemerkt', [$pid, 'referral', false, 0],
    [intval(TIX_Order_Attribution::meta($o2, '_tix_promoter_id')), (string) TIX_Order_Attribution::meta($o2, '_tix_promoter_attribution'), isset($_COOKIE[TIX_Promoter::COOKIE_NAME]), $tr_after - $tr_before]);
$wpdb->insert($ot, ['order_number' => 'P1' . wp_rand(), 'status' => 'pending', 'order_key' => wp_generate_password(12, false), 'total' => 20, 'payment_method' => 'mollie', 'billing_email' => "p1_kunde3_$sfx@example.test", 'date_created' => current_time('mysql')]);
$o3 = intval($wpdb->insert_id); $orders[] = $o3;
$m->invoke(null, $o3, ['utm_source' => 'instagram'], []);
p1_eq('Nächste Bestellung ohne Code: kein Promoter', 0, intval(TIX_Order_Attribution::meta($o3, '_tix_promoter_id')));
$m->invoke(null, $o3, ['promoter_code' => 'gibt-es-nicht-' . $sfx], []);
p1_eq('Unbekannter Code: keine Zuordnung', 0, intval(TIX_Order_Attribution::meta($o3, '_tix_promoter_id')));

// Aufräumen
wp_set_current_user($admin);
update_option('tix_settings', $settings_before);
if ($q_before === null) delete_option(TIX_Staff_Push::OPT_EVENT_Q); else update_option(TIX_Staff_Push::OPT_EVENT_Q, $q_before, false);
foreach ($orders as $o) { $wpdb->delete($tc, ['order_id' => $o]); $wpdb->delete($oi, ['order_id' => $o]); $wpdb->delete($ot, ['id' => $o]); $wpdb->delete(TIX_Order_Attribution::table(), ["order_id" => $o]); }
foreach (array_unique(array_filter($proms)) as $p) { $wpdb->delete(TIX_Promoter_DB::table_events(), ['promoter_id' => $p]); $wpdb->delete($tc, ['promoter_id' => $p]); $wpdb->delete($tp, ['id' => $p]); }
foreach (array_unique(array_filter($posts)) as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
