<?php
/** Mitteilungen planen (TIX_Notif_Schedule, 1.38.657). wp eval-file …/tests/test-notif-schedule-wp.php (beide Modi). */
if (!defined('ABSPATH') || !class_exists('TIX_Notif_Schedule')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function ns_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function ns_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = []; $posts = [];
$before = ['s' => get_option(TIX_Notif_Schedule::OPT, null), 'l' => get_option(TIX_Notifications::OPT_LOG, null), 'f' => get_option(TIX_Notifications::OPT_FEED, null)];
delete_option(TIX_Notif_Schedule::OPT);
$owner = wp_insert_user(['user_login' => "ns_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "ns_owner_$sfx@example.test", 'role' => $multi ? 'tix_organizer' : 'administrator', 'display_name' => 'Plan Person']); $users[] = $owner;
$other = wp_insert_user(['user_login' => "ns_other_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "ns_other_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $other;
$org = 0; $org2 = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'NS Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'NS Andere', 'meta_input' => ['_tix_org_user_id' => $other]]); $posts[] = $org2;
}
$start = time() + 30 * HOUR_IN_SECONDS;
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "NS Party $sfx", 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', $start), '_tix_time_start' => wp_date('H:i', $start)]]); $posts[] = $ev;
$evf = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "NS Fremd $sfx", 'meta_input' => ['_tix_organizer_id' => $org2, '_tix_date_start' => wp_date('Y-m-d', $start)]]); $posts[] = $evf;
wp_set_current_user($owner);
$in1h = wp_date('Y-m-d\TH:i', time() + HOUR_IN_SECONDS);

// ═══ Planen ═══
$logn = count((array) get_option(TIX_Notifications::OPT_LOG, []));
[$st, $r] = ns_rest('POST', '/notifications/broadcast', ['title' => "Bald $sfx", 'body' => 'Gleich gehts los', 'event_id' => $ev, 'target' => 'event', 'send_at' => $in1h]);
$sid = (string) ($r['scheduled']['id'] ?? ''); $cmp = (string) ($r['scheduled']['campaign'] ?? '');
ns_eq('Geplant: 201, Status scheduled, Kampagne vergeben, nichts gesendet', [201, 'scheduled', true, $logn], [$st, $r['scheduled']['status'] ?? '', (bool) preg_match('/^push-[a-z0-9]{8}$/', $cmp), count((array) get_option(TIX_Notifications::OPT_LOG, []))]);
ns_eq('send_at in Ortszeit (Europe/Berlin) gelesen', $in1h, substr((string) ($r['scheduled']['send_at'] ?? ''), 0, 16));
[$st, $d] = ns_rest('POST', '/notifications/broadcast', ['title' => 'X', 'send_at' => wp_date('Y-m-d\TH:i', time() - 600)]);
ns_eq('Vergangenheit → 400 send_at', [400, 'send_at'], [$st, $d['data']['field'] ?? '']);
[$st, $d] = ns_rest('POST', '/notifications/broadcast', ['title' => 'X', 'send_at' => wp_date('Y-m-d\TH:i', time() + 100 * DAY_IN_SECONDS)]);
ns_eq('Mehr als 90 Tage → 400', 400, $st);
[$st, $d] = ns_rest('POST', '/notifications/broadcast', ['title' => 'X', 'relative_hours' => 24]);
ns_eq('„Stunden vor Event“ ohne Event → 400 event_id', [400, 'event_id'], [$st, $d['data']['field'] ?? '']);
[$st, $d] = ns_rest('POST', '/notifications/broadcast', ['title' => 'X', 'event_id' => $ev, 'relative_hours' => 48]);
ns_eq('48 h vor einem Event in 30 h → 400 (schon vorbei)', [400, 'relative_hours'], [$st, $d['data']['field'] ?? '']);
[$st, $rr] = ns_rest('POST', '/notifications/broadcast', ['title' => "Morgen $sfx", 'event_id' => $ev, 'relative_hours' => 24]);
$rid = (string) ($rr['scheduled']['id'] ?? '');
ns_eq('24 h vor Event: Zeitpunkt aus dem Event-Beginn', [201, 24, wp_date('Y-m-d H:i', $start - DAY_IN_SECONDS)], [$st, $rr['scheduled']['relative_hours'] ?? 0, wp_date('Y-m-d H:i', strtotime((string) ($rr['scheduled']['send_at'] ?? '')))]);
update_post_meta($ev, '_tix_time_start', wp_date('H:i', $start + 2 * HOUR_IN_SECONDS));
[$st, $L] = ns_rest('GET', '/notifications/scheduled');
$byid = array_column((array) ($L['scheduled'] ?? []), null, 'id');
ns_eq('Event verschoben → geplanter Zeitpunkt folgt', wp_date('Y-m-d H:i', $start + 2 * HOUR_IN_SECONDS - DAY_IN_SECONDS), wp_date('Y-m-d H:i', strtotime((string) ($byid[$rid]['send_at'] ?? ''))));
if ($multi) {
    [$st] = ns_rest('POST', '/notifications/broadcast', ['title' => 'X', 'event_id' => $evf, 'send_at' => $in1h]);
    ns_eq('Fremdes Event planen → 403', 403, $st);
}

// ═══ Ändern, Abbrechen ═══
[$st, $u] = ns_rest('POST', "/notifications/scheduled/$sid", ['title' => "Bald geändert $sfx", 'send_at' => wp_date('Y-m-d\TH:i', time() + 2 * HOUR_IN_SECONDS)]);
ns_eq('Ändern: Titel und Zeit, Kampagne bleibt', [200, "Bald geändert $sfx", $cmp], [$st, $u['scheduled']['title'] ?? '', $u['scheduled']['campaign'] ?? '']);
[$st, $c] = ns_rest('POST', "/notifications/scheduled/$rid/cancel");
ns_eq('Abbrechen', [200, 'cancelled'], [$st, $c['scheduled']['status'] ?? '']);
[$st, $d, $code] = ns_rest('POST', "/notifications/scheduled/$rid", ['title' => 'Nein']);
ns_eq('Abgebrochene nicht mehr änderbar → 409', [409, 'tix_locked'], [$st, $code]);
TIX_Notif_Schedule::run();
ns_eq('Cron: noch nicht fällig → nichts gesendet', 'scheduled', (string) (array_column(get_option(TIX_Notif_Schedule::OPT), null, 'id')[$sid]['status'] ?? ''));

// ═══ Fällig → Versand per Cron ═══
$all = get_option(TIX_Notif_Schedule::OPT); foreach ($all as &$x) if ($x['id'] === $sid) $x['send_at'] = time() - 60; unset($x); update_option(TIX_Notif_Schedule::OPT, $all, false);
wp_set_current_user(0); // Cron läuft ohne Anmeldung
$n = TIX_Notif_Schedule::run();
$after = array_column(get_option(TIX_Notif_Schedule::OPT), null, 'id')[$sid] ?? [];
$log = (array) get_option(TIX_Notifications::OPT_LOG, []);
$hit = array_values(array_filter($log, function ($l) use ($cmp) { return ($l['campaign'] ?? '') === $cmp; }))[0] ?? [];
ns_eq('Cron sendet: Status sent, Verlauf mit gleicher Kampagne und Absender = wer geplant hat', [1, 'sent', "Bald geändert $sfx", $owner, 'event'], [$n, $after['status'] ?? '', $hit['title'] ?? '', intval($hit['sender_id'] ?? 0), (string) ($hit['target'] ?? '')]);
ns_eq('Nach dem Cron wieder ohne Anmeldung', 0, get_current_user_id());
TIX_Notif_Schedule::run();
ns_eq('Kein doppelter Versand', 1, count(array_filter((array) get_option(TIX_Notifications::OPT_LOG, []), function ($l) use ($cmp) { return ($l['campaign'] ?? '') === $cmp; })));
wp_set_current_user($owner);
// Zu spät
[$st, $lt] = ns_rest('POST', '/notifications/broadcast', ['title' => "Spät $sfx", 'send_at' => $in1h]);
$lid = (string) ($lt['scheduled']['id'] ?? '');
$all = get_option(TIX_Notif_Schedule::OPT); foreach ($all as &$x) if ($x['id'] === $lid) $x['send_at'] = time() - 7 * HOUR_IN_SECONDS; unset($x); update_option(TIX_Notif_Schedule::OPT, $all, false);
TIX_Notif_Schedule::run();
ns_eq('Mehr als 6 h zu spät → failed mit Grund', 'failed', (string) (array_column(get_option(TIX_Notif_Schedule::OPT), null, 'id')[$lid]['status'] ?? ''));
// Jetzt senden
[$st, $nw] = ns_rest('POST', '/notifications/broadcast', ['title' => "Sofort $sfx", 'send_at' => $in1h]);
[$st, $sn] = ns_rest('POST', '/notifications/scheduled/' . ($nw['scheduled']['id'] ?? '') . '/send');
ns_eq('„Jetzt senden“: sofort gesendet', [200, 'sent'], [$st, $sn['scheduled']['status'] ?? '']);
[$st, $S] = ns_rest('GET', '/notifications/scheduled', ['status' => 'all']);
ns_eq('Liste „alle“: geplant, gesendet, abgebrochen, fehlgeschlagen', true, count(array_intersect(['sent', 'cancelled', 'failed'], array_column((array) ($S['scheduled'] ?? []), 'status'))) === 3);
if ($multi) {
    wp_set_current_user($other);
    [$st, $S2] = ns_rest('GET', '/notifications/scheduled', ['status' => 'all']);
    [$st2] = ns_rest('POST', "/notifications/scheduled/$sid/cancel");
    ns_eq('Anderer Veranstalter: sieht nichts, darf nichts abbrechen', [0, 404], [count((array) ($S2['scheduled'] ?? [])), $st2]);
}
wp_set_current_user(0);
[$st] = ns_rest('GET', '/notifications/scheduled');
ns_eq('Ohne Anmeldung → 401', 401, $st);

// Aufräumen
wp_set_current_user(1);
foreach ($before as $k => $v) { $n = ['s' => TIX_Notif_Schedule::OPT, 'l' => TIX_Notifications::OPT_LOG, 'f' => TIX_Notifications::OPT_FEED][$k]; if ($v === null) delete_option($n); else update_option($n, $v, false); }
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
