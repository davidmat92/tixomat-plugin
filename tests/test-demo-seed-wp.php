<?php
/**
 * 1.38.860: Demodaten für Kunden-Shops (TIX_Demo_Seed) – seed, Kennzeichnung, Status, Löschen (nur Demodaten, geänderte/benutzte
 * Events bleiben), keine Wirkung nach außen, Hinweis vor dem Live-Schalten. Plan uebergaben/plan-demodaten-neukunden-2026-11-16.md.
 *   wp eval-file …/test-demo-seed-wp.php   (Einzel-Seite: voller Test; Plattform: nur „nicht verfügbar“)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Demo_Seed')) { echo "Tixomat ab 1.38.860 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$S = 'TIX_Demo_Seed';
global $wpdb;
$adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 0);
$req = function ($m, $route, $body = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $route); if ($body) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($body)); } $r = rest_do_request($x); return [$r->get_status(), $r->get_data()]; };

if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
    wp_set_current_user($adm);
    [$st] = $req('GET', '/studio/demo-data/status');
    $eq('Plattform: Routen 404, seed nicht verfügbar', [404, 'tix_demo_seed_unavailable'], [$st, $S::seed('club')['error'] ?? '']);
    wp_set_current_user(0);
    echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
    return;
}

// Einzel-Seite: Demo-Instanz-Schalter des Test-Containers vorübergehend aus
$bak = []; foreach (['tix_demo_instance', 'tix_coupons', TIX_Notifications::OPT_FEED, 'tix_demo_seed'] as $o) $bak[$o] = get_option($o, null);
update_option('tix_demo_instance', '0', false);
delete_option('tix_demo_seed');
$eq('Kunden-Shop: verfügbar, noch nichts da', [true, false], [$S::available(), $S::present()]);
$own = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Eigenes Event des Kunden'], true);
update_post_meta($own, '_tix_date_start', wp_date('Y-m-d', time() + 5 * DAY_IN_SECONDS));
$quota0 = class_exists('TIX_Trial_Site') ? TIX_Trial_Site::tickets_since('2000-01-01') : 0;
$mails = [];
$cap = function ($pre, $atts) use (&$mails) { $mails[] = $atts['to'] ?? ''; return $pre; };
add_filter('pre_wp_mail', $cap, 0, 2);

// ═══ Seed Club ═══
$r = $S::seed('club', 20);
$reg = $S::registry();
$evs = array_map('intval', array_keys((array) ($reg['events'] ?? [])));
$marked = array_filter($evs, function ($id) use ($S) { return $S::is_event($id); });
$pms = $reg['orders'] ? array_unique($wpdb->get_col("SELECT payment_method FROM {$wpdb->prefix}tix_orders WHERE id IN (" . implode(',', array_map('intval', $reg['orders'])) . ")")) : [];
$eq('seed club: ok, mehrere Events, Bestellungen, Tickets, Gutscheine; alle Events gekennzeichnet; Zahlart nur demo', [true, true, true, true, 3, true, ['demo']],
    [$r['ok'] ?? false, ($r['counts']['events'] ?? 0) >= 8, ($r['counts']['orders'] ?? 0) > 5, ($r['counts']['tickets'] ?? 0) > 5, $r['counts']['giftcards'] ?? 0, count($marked) === count($evs), array_values($pms)]);
$eq('Während des Seeds: keine Mail an echte Adressen, Filter danach wieder weg', [[], false],
    [array_values(array_filter($mails, function ($t) { return is_string($t) && $t !== '' && !preg_match('/\.invalid$/i', $t); })), has_filter('pre_wp_mail', '__return_true') !== false]);
$r2 = $S::seed('club', 20);
$eq('Zweiter seed: übersprungen, nichts doppelt', [true, true, count($evs)], [$r2['ok'] ?? false, $r2['skipped'] ?? false, count(array_keys((array) $S::registry()['events']))]);

// Keine Wirkung nach außen
$e1 = $evs[0];
$eq('Nach außen: nie im Datenspiegel, nie verteilt, nicht im Paket-Kontingent', [true, true, $quota0],
    [class_exists('TIX_Mirror_Map') ? TIX_Mirror_Map::is_sandbox_event($e1) : true, class_exists('TIX_Syndication_Push') ? TIX_Syndication_Push::is_system_event($e1) : true, class_exists('TIX_Trial_Site') ? TIX_Trial_Site::tickets_since('2000-01-01') : 0]);
$eq('Eigenes Event des Kunden ist kein Demo-Event', false, $S::is_event($own));

// Live-Schaltung
$x = new WP_REST_Request('POST', '/tixomat/v1/studio/go-live'); $x->set_param('action', 'live');
$g = $S::go_live_check($x);
$x->set_param('keep_demo_data', true);
$items = class_exists('TIX_Go_Live') ? array_column(TIX_Go_Live::checklist()['items'], null, 'key') : [];
$eq('go-live: 409 tix_demo_data_present, mit keep_demo_data weiter; Checkliste mit Hinweis demo_data (keine Pflicht)', ['tix_demo_data_present', 409, null, false, false],
    [is_wp_error($g) ? $g->get_error_code() : '', is_wp_error($g) ? ($g->get_error_data()['status'] ?? 0) : 0, $S::go_live_check($x), $items['demo_data']['ok'] ?? null, $items['demo_data']['required'] ?? null]);

// Status per REST (Rechte)
$sub = wp_insert_user(['user_login' => 'seedsub' . wp_rand(), 'user_pass' => wp_generate_password(20), 'user_email' => 'seedsub' . wp_rand() . '@example.test', 'role' => 'subscriber']);
wp_set_current_user($sub);
[$st403] = $req('GET', '/studio/demo-data/status');
wp_set_current_user($adm);
[$st, $d] = $req('GET', '/studio/demo-data/status');
$eq('GET status: Admin 200 mit present und IDs fürs Etikett; Abonnent 403', [200, true, count($evs), count($reg['orders']), [], [], 403],
    [$st, $d['present'] ?? null, count($d['ids']['events'] ?? []), count($d['ids']['orders'] ?? []), $d['edited'] ?? null, $d['in_use'] ?? null, $st403]);

// Kunde ändert ein Demo-Event, ein anderes bekommt eine echte Bestellung
$edited = $evs[1]; $used = $evs[2];
wp_update_post(['ID' => $edited, 'post_title' => 'Von mir umbenannt']);
$cats = (array) get_post_meta($used, '_tix_ticket_categories', true);
$real = TIX_Native_Checkout::create_order(['billing_first_name' => 'Echt', 'billing_last_name' => 'Kunde', 'billing_email' => 'echt' . wp_rand() . '@example.test', 'payment_method' => 'bar', 'allow_oversell' => true, 'total' => 10,
    'items' => [['event_id' => $used, 'cat_index' => 0, 'name' => (string) ($cats[0]['name'] ?? 'Ticket'), 'event_title' => get_the_title($used), 'price' => 10, 'qty' => 1, 'meta' => []]]]);
[, $d] = $req('GET', '/studio/demo-data/status');
$eq('Status: geändertes Event in edited, Event mit echter Bestellung in in_use', [[$edited], [$used]], [array_column($d['edited'] ?? [], 'id'), array_column($d['in_use'] ?? [], 'id')]);

// Löschen ohne include_edited
$tips = (array) $S::registry()['tips']; $gcs = (array) $S::registry()['giftcards']; $arts = (array) $S::registry()['artists'];
[$st, $d] = $req('POST', '/studio/demo-data/delete', []);
$left_events = array_values(array_filter($evs, function ($id) { return (bool) get_post($id); }));
sort($left_events);
$exp = [$edited, $used]; sort($exp);
wp_cache_delete('tix_coupons', 'options');
$cp = (array) get_option('tix_coupons', []);
$feed_demo = array_filter((array) get_option(TIX_Notifications::OPT_FEED, []), function ($it) { return is_array($it) && strpos((string) ($it['id'] ?? ''), 'b_demo_') === 0; });
$eq('Löschen: nur Demodaten weg; geändertes und benutztes Event bleiben (kept edited/in_use); eigenes Event, echte Bestellung bleiben', [200, $exp, ['edited', 'in_use'], true, true, 0, 0, [], true],
    [$st, $left_events, array_values(array_map(function ($k) { return $k['reason']; }, $d['kept'] ?? [])), (bool) get_post($own), (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}tix_orders WHERE id = %d", $real)),
     count(array_intersect($gcs, array_keys($cp))), count(array_filter($tips, function ($t) { return (bool) get_post($t); })), array_values($feed_demo), $d['present'] ?? null]);
$demo_left = $wpdb->get_col("SELECT DISTINCT o.id FROM {$wpdb->prefix}tix_orders o JOIN {$wpdb->prefix}tix_order_items i ON i.order_id = o.id WHERE o.payment_method = 'demo' AND i.event_id NOT IN (" . implode(',', $exp) . ") AND o.id IN (" . implode(',', array_map('intval', $reg['orders'])) . ")");
$eq('Keine Demo-Bestellung an gelöschten Events übrig', [], $demo_left);

// Mit include_edited: geändertes weg, benutztes bleibt
[$st, $d] = $req('POST', '/studio/demo-data/delete', ['include_edited' => true]);
$eq('include_edited: geändertes Event gelöscht, Event mit echter Bestellung bleibt immer', [200, false, true, [['id' => $used, 'reason' => 'in_use']]],
    [$st, (bool) get_post($edited), (bool) get_post($used), array_map(function ($k) { return ['id' => $k['id'], 'reason' => $k['reason']]; }, $d['kept'] ?? [])]);

// Aufräumen Club-Rest
TIX_Demo::delete_orders(array_merge([$real], array_map('intval', (array) ($S::registry()['orders'] ?? []))));
if (class_exists('TIX_Demo')) TIX_Demo::delete_event_tickets([$used]);
wp_delete_post($used, true);
foreach ($arts as $a) wp_delete_post(intval($a), true);
delete_option('tix_demo_seed');

// ═══ Festival ═══
$r = $S::seed('festival', 12);
$reg = $S::registry();
$fest = array_filter(array_map('intval', array_keys((array) $reg['events'])), function ($id) { return (bool) get_post_meta($id, '_tix_timetable', true); });
$eq('seed festival: Festival mit Programm und Künstlern plus weitere Events', [true, 1, true, true], [$r['ok'] ?? false, count($fest), ($r['counts']['artists'] ?? 0) > 5, ($r['counts']['events'] ?? 0) >= 3]);
[$st, $d] = $req('POST', '/studio/demo-data/delete', []);
$eq('Festival löschen: alles weg, nichts mehr da', [200, [], false, 0], [$st, $d['kept'] ?? null, $S::present(), count(array_filter((array) $reg['artists'], function ($a) { return (bool) get_post($a); }))]);

// Aufräumen
remove_filter('pre_wp_mail', $cap, 0);
wp_set_current_user(0);
wp_delete_post($own, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($sub);
foreach ($bak as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
