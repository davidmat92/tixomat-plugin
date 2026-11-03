<?php
/** Integrationstest Statistik Stufe 1 – Zählung mit Quelle/Kampagne, App-Treffer, Besuche, Kennzahlen (GET /organizer/stats/audience).
 *  wp eval-file …/tests/test-stats-audience-wp.php – einmal ohne, einmal mit tix_multi_organizer=1 */
if (!defined('ABSPATH') || !class_exists('TIX_Stats_Audience')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function sa_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function sa_rest($m, $r, array $b = [], $ua = 'Mozilla/5.0 Test') {
    $q = new WP_REST_Request($m, '/tixomat/v1' . $r);
    $q->set_header('user-agent', $ua);
    if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); }
    $res = rest_do_request($q); $d = $res->get_data();
    return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : ''];
}
global $wpdb;
TIX_Order::create_tables(); TIX_Order_Attribution::maybe_create(); TIX_Web_Stats::maybe_create(); TIX_Web_Stats::maybe_create_dim();
delete_transient('tix_ws_rate_' . gmdate('YmdHi'));
$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $orders = [];
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$mk = function ($role, $tag) use (&$users, $sfx) { $id = wp_insert_user(['user_login' => "sa_{$tag}_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "sa_{$tag}_$sfx@example.test", 'role' => $role]); $users[] = $id; return $id; };
$owner = $mk('tix_organizer', 'owner'); $staff = $mk('tix_staff', 'staff');
$org = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'SA Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    update_user_meta($staff, '_tix_team_organizer_id', $org);
}
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SA Party', 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d'), '_tix_time_start' => '22:00',
    '_tix_ticket_categories' => [['name' => 'Regulär', 'price' => 10, 'qty' => 100]]]]); $posts[] = $ev;
$today = wp_date('Y-m-d');
// Stand vor dem Test (die Tabellen können schon Treffer anderer Tests enthalten)
$t = TIX_Web_Stats::table(); $d = TIX_Web_Stats::dim_table();
$wpdb->query($wpdb->prepare("DELETE FROM $t WHERE ref = %d", $ev));
$wpdb->query($wpdb->prepare("DELETE FROM $d WHERE event_id = %d", $ev));

// ── Treffer ──
for ($i = 0; $i < 5; $i++) sa_rest('POST', '/public/stats/hit', ['step' => 'event', 'event_id' => $ev, 'utm_source' => 'Instagram', 'utm_medium' => 'social', 'utm_campaign' => 'Herbst 2026']);
for ($i = 0; $i < 2; $i++) sa_rest('POST', '/public/stats/hit', ['step' => 'event', 'event_id' => $ev]);
sa_rest('POST', '/public/stats/hit', ['step' => 'event', 'event_id' => $ev, 'ref_host' => 'www.google.com']);
sa_rest('POST', '/public/stats/hit', ['step' => 'event', 'event_id' => $ev], 'Googlebot/2.1');
for ($i = 0; $i < 3; $i++) sa_rest('POST', '/public/stats/hit', ['step' => 'quote', 'event_id' => $ev]);
for ($i = 0; $i < 2; $i++) sa_rest('POST', '/public/stats/hit', ['step' => 'checkout', 'event_id' => $ev]);
sa_rest('POST', '/public/stats/hit', ['step' => 'event', 'event_id' => $ev, 'src' => 'app'], 'KitchenKlub/1.0 Dart');
sa_rest('POST', '/public/stats/hit', ['step' => 'view', 'src' => 'app'], 'KitchenKlub/1.0 Dart'); // Apps melden keine Seitenaufrufe
sa_eq('Treffer: Event-Aufrufe Website (ohne Bot, ohne App)', 8, intval($wpdb->get_var($wpdb->prepare("SELECT SUM(n) FROM $t WHERE step = 'event' AND ref = %d AND day = %s", $ev, $today))));
sa_eq('Treffer: Quelle normalisiert', 5, intval($wpdb->get_var($wpdb->prepare("SELECT SUM(n) FROM $d WHERE event_id = %d AND step = 'event' AND source = 'instagram' AND campaign = 'herbst-2026'", $ev))));
sa_eq('Treffer: Referrer → Quelle google.com/referral', 1, intval($wpdb->get_var($wpdb->prepare("SELECT SUM(n) FROM $d WHERE event_id = %d AND source = 'google.com' AND medium = 'referral'", $ev))));
sa_eq('Treffer: App getrennt', 1, intval($wpdb->get_var($wpdb->prepare("SELECT SUM(n) FROM $d WHERE event_id = %d AND channel = 'app'", $ev))));
sa_eq('Treffer: Veranstalter in den Dimensionen', 8, intval($wpdb->get_var($wpdb->prepare("SELECT SUM(n) FROM $d WHERE event_id = %d AND step = 'event' AND channel = 'web' AND org_id = %d", $ev, $org))));

// ── Bestellungen ──
$ot = TIX_Order::table_name(); $oi = TIX_Order::items_table_name();
$mk_order = function ($status, $pm, $mail, array $attr) use ($wpdb, $ot, $oi, $ev, &$orders) {
    $wpdb->insert($ot, ['order_number' => 'SA' . wp_rand(), 'status' => $status, 'order_key' => wp_generate_password(12, false), 'total' => 20, 'subtotal' => 20, 'payment_method' => $pm, 'billing_email' => $mail, 'date_created' => current_time('mysql')]);
    $id = intval($wpdb->insert_id); $orders[] = $id;
    $wpdb->insert($oi, ['order_id' => $id, 'event_id' => $ev, 'quantity' => 2, 'total' => 20, 'name' => 'SA Party – Regulär', 'cat_name' => 'Regulär']);
    if ($attr) TIX_Order_Attribution::set($id, $attr);
    update_option('_tix_order_source_' . $id, $pm === 'pos_cash' ? 'pos' : 'app', false);
    return $id;
};
$A = $mk_order('completed', 'mollie', 'Anna@Example.test', ['channel' => 'web', 'source' => 'instagram', 'medium' => 'social', 'campaign' => 'Herbst 2026']);
$B = $mk_order('pending', 'mollie', 'bert@example.test', ['channel' => 'web', 'source' => 'direct']);
$C = $mk_order('completed', 'pos_cash', 'carla@example.test', []);
$D = $mk_order('completed', 'mollie', 'anna@example.test', ['channel' => 'web', 'source' => 'direct']); // gleiche Käuferin wie A
$tk = function ($order, $checked) use ($ev, &$posts) {
    $p = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'SA Ticket']); $posts[] = $p;
    foreach (['_tix_ticket_event_id' => $ev, '_tix_ticket_order_id' => $order, '_tix_ticket_cat_index' => 0, '_tix_ticket_status' => $checked ? 'used' : 'valid', '_tix_ticket_checked_in' => $checked ? '1' : '0'] as $k => $v) update_post_meta($p, $k, $v);
    if ($checked) update_post_meta($p, '_tix_ticket_checkin_time', current_time('mysql'));
};
$tk($A, true); $tk($A, false); $tk($C, true); $tk($C, false); $tk($D, false); $tk($D, false);
update_post_meta($ev, '_tix_guest_list', [['id' => 'SAG001', 'name' => 'Gast', 'plus' => 1, 'checked_in' => true, 'checked_in_count' => 2, 'checkin_time' => current_time('mysql')]]);

// ── Kennzahlen ──
wp_set_current_user($staff);
[$st] = sa_rest('GET', '/organizer/stats/audience');
sa_eq('Rechte: Mitarbeiter sieht keine Statistik', 403, $st);
wp_set_current_user($owner);
[$st, $r] = sa_rest('GET', '/organizer/stats/audience', ['event_id' => $ev]);
sa_eq('Kennzahlen: 200', 200, $st);
$k = $r['kpis'] ?? [];
sa_eq('Event-Aufrufe', 8, $k['event_views'] ?? null);
sa_eq('Käufer (verschiedene E-Mails, alle Kanäle)', 2, $k['buyers'] ?? null);
sa_eq('Ticket-Gäste', 6, $k['ticket_guests'] ?? null);
sa_eq('Gäste vor Ort (2 Tickets + Gast mit Begleitung)', 4, $k['guests_on_site'] ?? null);
sa_eq('Website-Bestellungen bezahlt', 2, $k['web_orders'] ?? null);
sa_eq('Conversion = 2 / 8', 25.0, $k['conversion'] ?? null);
sa_eq('Besuche ohne Schalter = null', [true, null], [array_key_exists('visits', $k), $k['visits'] ?? null]);
$fn = []; foreach ($r['funnel'] ?? [] as $x) $fn[$x['key']] = $x['n'];
sa_eq('Trichter', ['view' => null, 'event' => 8, 'checkout' => 2, 'quote' => 3, 'order' => 3, 'purchase' => 2, 'checkin' => 1], $fn);
$src = []; foreach ($r['by_source'] ?? [] as $x) $src[$x['source']] = [$x['views'], $x['orders']];
sa_eq('Quelle instagram: 5 Aufrufe, 1 Kauf', [5, 1], $src['instagram'] ?? null);
sa_eq('Quelle direct: 2 Aufrufe, 1 Kauf', [2, 1], $src['direct'] ?? null);
sa_eq('kleine Gruppe google.com zusammengefasst', [false, [1, 0]], [isset($src['google.com']), $src['weitere'] ?? null]);
$cmp = []; foreach ($r['by_campaign'] ?? [] as $x) $cmp[$x['campaign']] = [$x['views'], $x['orders'], $x['revenue']];
sa_eq('Kampagne herbst-2026', [5, 1, 20.0], $cmp['herbst-2026'] ?? null);
sa_eq('App-Aufrufe', 1, $r['app']['event_views'] ?? null);
sa_eq('Marketing-Modul: Plattform frei, Einzel-Seite aus', $multi, $r['modules']['marketing'] ?? null);
sa_eq('Platz für Matomo', ['available' => false, 'connected' => false], $r['matomo'] ?? null);
sa_eq('Vorperiode vorhanden', true, isset($r['previous']['event_views']));

// ── Geschätzte Besuche (Schalter) ──
$wpdb->query($wpdb->prepare("DELETE FROM " . TIX_Web_Stats::visits_table() . " WHERE day = %s", $today));
$vt = function () use ($wpdb, $t, $today, $org) { return intval($wpdb->get_var($wpdb->prepare("SELECT SUM(n) FROM $t WHERE day = %s AND step = %s AND ref = %d", $today, $org ? 'ovisit' : 'visit', $org))); };
$before = $vt();
$vh1 = hash('sha256', 'a' . $sfx); $vh2 = hash('sha256', 'b' . $sfx);
sa_rest('POST', '/public/stats/hit', ['step' => 'view', 'organizer_id' => $org, 'vh' => $vh1]);
sa_eq('Besuche aus: nichts gezählt', $before, $vt());
update_option('tix_web_visits', '1');
sa_rest('POST', '/public/stats/hit', ['step' => 'view', 'organizer_id' => $org, 'vh' => $vh1]);
sa_rest('POST', '/public/stats/hit', ['step' => 'view', 'organizer_id' => $org, 'vh' => $vh1]);
sa_rest('POST', '/public/stats/hit', ['step' => 'view', 'organizer_id' => $org, 'vh' => $vh2]);
sa_rest('POST', '/public/stats/hit', ['step' => 'view', 'organizer_id' => $org, 'vh' => 'kein-hash']);
sa_eq('Besuche an: zwei verschiedene', $before + 2, $vt());
sa_eq('Hash nicht im Klartext gespeichert', 0, intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Web_Stats::visits_table() . " WHERE h = %s OR h = %s", $vh1, substr($vh1, 0, 32)))));
[$st, $r] = sa_rest('GET', '/organizer/stats/audience');
sa_eq('Kennzahlen: Besuche mit Schalter', true, is_int($r['kpis']['visits'] ?? null) && $r['kpis']['visits'] >= 2);
$wpdb->query($wpdb->prepare("INSERT IGNORE INTO " . TIX_Web_Stats::visits_table() . " (day, scope, h) VALUES (%s, 0, %s)", '2000-01-01', md5('alt')));
$p = new ReflectionMethod('TIX_Web_Stats', 'purge'); $p->setAccessible(true); $p->invoke(null);
sa_eq('Hashes früherer Tage gelöscht', 0, intval($wpdb->get_var("SELECT COUNT(*) FROM " . TIX_Web_Stats::visits_table() . " WHERE day < '" . esc_sql($today) . "'")));
delete_option('tix_web_visits');
[$st, $fr] = sa_rest('GET', '/studio/stats/funnel', ['days' => 1]);
sa_eq('Trichter-Route kennt quote', true, $st === 200 && array_key_exists('quote', (array) ($fr['totals'] ?? [])));

// ── Modul Marketing ──
$map = TIX_Features::map($org);
sa_eq('Funktion marketing vorhanden (Plattform frei, sonst aus)', $multi, $map['marketing']['enabled'] ?? null);
update_option('tix_marketing_enabled', '1');
sa_eq('marketing an (Seite)', true, TIX_Features::site_on('marketing'));
delete_option('tix_marketing_enabled');
sa_eq('Modul in TIX_Plan bekannt', true, in_array('marketing', TIX_Plan::switchable(), true));

// Aufräumen
wp_set_current_user(1);
foreach ($orders as $o) { $wpdb->delete($ot, ['id' => $o]); $wpdb->delete($oi, ['order_id' => $o]); $wpdb->delete(TIX_Order_Attribution::table(), ['order_id' => $o]); delete_option('_tix_order_source_' . $o); }
$wpdb->query($wpdb->prepare("DELETE FROM $t WHERE ref = %d", $ev)); $wpdb->query($wpdb->prepare("DELETE FROM $d WHERE event_id = %d", $ev));
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
