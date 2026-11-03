<?php
/** Integrationstest Studio-Audit 2026-11-03 Paket 3 (M3, M14, M15, M17, S9, S10, S12). wp eval-file …/tests/test-audit-fixes-2-wp.php – einmal ohne, einmal mit tix_multi_organizer=1 */
if (!defined('ABSPATH') || !class_exists('TIX_Raffle') || !class_exists('TIX_Awareness_Live')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function af_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function af_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
$sfx = wp_rand(1000, 9999); $users = []; $posts = [];
$mk = function ($role, $tag) use (&$users, $sfx) { $id = wp_insert_user(['user_login' => "af2_{$tag}_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "af2_{$tag}_$sfx@example.test", 'role' => $role]); $users[] = $id; return $id; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$owner = $mk('tix_organizer', 'owner');
$staff = $mk('tix_staff', 'staff');
$aw = get_role('tix_awareness') ? $mk('tix_awareness', 'aware') : 0;
$org = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'AF2 Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    update_user_meta($staff, '_tix_team_organizer_id', $org);
    if ($aw) update_user_meta($aw, '_tix_team_organizer_id', $org);
}
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'AF2 Party', 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() + 5 * 86400), '_tix_time_start' => '22:00']]); $posts[] = $ev;

// ── M3: neue Gast-IDs kurz und groß, Scan auch mit langen/kleinen Bestands-IDs ──
wp_set_current_user($owner);
[$st, $d] = af_rest('POST', "/events/$ev/guestlist", ['name' => 'AF2 Gast']);
af_eq('M3 Gast angelegt', 200, $st);
$gl = get_post_meta($ev, '_tix_guest_list', true);
$gid = (string) ($gl[0]['id'] ?? '');
af_eq('M3 neue Gast-ID 6 Zeichen groß', 1, preg_match('/^[A-Z0-9]{6}$/', $gid));
[$st, $d] = af_rest('POST', '/checkin/scan', ['code' => "GL-$ev-$gid", 'event_id' => $ev]);
af_eq('M3 neuer Gast scannbar', 'ok', $d['status'] ?? ($d['code'] ?? ''));
[$st] = af_rest('POST', "/events/$ev/guestlist", ['guests' => array_merge((array) get_post_meta($ev, '_tix_guest_list', true), [['name' => 'AF2 Liste ohne ID']])]);
$gl = get_post_meta($ev, '_tix_guest_list', true);
af_eq('M3 Liste ersetzen: Gast ohne ID bekommt kurze ID', 1, preg_match('/^[A-Z0-9]{6}$/', (string) ($gl[1]['id'] ?? '')));
af_eq('M3 Liste ersetzen: vorhandene ID bleibt', $gid, (string) ($gl[0]['id'] ?? ''));
$uuid = wp_generate_uuid4();
$gl[] = ['id' => $uuid, 'name' => 'AF2 Alt', 'plus' => 0, 'checked_in' => false, 'checked_in_count' => 0];
update_post_meta($ev, '_tix_guest_list', $gl);
[$st, $d] = af_rest('POST', '/checkin/scan', ['code' => "GL-$ev-$uuid", 'event_id' => $ev]);
af_eq('M3 Bestands-UUID (klein) scannbar', 'ok', $d['status'] ?? ($d['code'] ?? ''));
[$st, $d] = af_rest('POST', '/checkin/scan', ['code' => strtolower("gl-$ev-$gid")]);
af_eq('M3 Code klein geschrieben = bereits eingecheckt', 'already', $d['status'] ?? ($d['code'] ?? ''));

// ── M15: Awareness-Meldungen nur Admin, Inhaber, Awareness-Rolle ──
$st_list = class_exists('TIX_Support') ? array_keys(TIX_Support::get_statuses()) : ['tix_open'];
$rep = wp_insert_post(['post_type' => 'tix_support_ticket', 'post_status' => $st_list[0], 'post_title' => 'AF2 Meldung', 'meta_input' => ['_tix_sp_category' => 'awareness', '_tix_sp_event_id' => $ev, '_tix_sp_name' => 'Anonym']]); $posts[] = $rep;
wp_set_current_user($staff);
[$st] = af_rest('GET', '/awareness/reports', ['event_id' => $ev]);
af_eq('M15 Mitarbeiter sieht keine Meldungen', 403, $st);
af_eq('M15 Mitarbeiter darf Meldung nicht öffnen', false, TIX_Awareness_Live::report_access($rep));
wp_set_current_user($owner);
[$st] = af_rest('GET', '/awareness/reports', ['event_id' => $ev]);
af_eq('M15 Inhaber sieht Meldungen', 200, $st);
af_eq('M15 Inhaber darf Meldung öffnen', true, TIX_Awareness_Live::report_access($rep));
if ($aw) {
    wp_set_current_user($aw);
    [$st] = af_rest('GET', '/awareness/reports', ['event_id' => $ev]);
    af_eq('M15 Awareness-Rolle sieht Meldungen', 200, $st);
    af_eq('M15 Awareness-Rolle darf Meldung öffnen', true, TIX_Awareness_Live::report_access($rep));
}

// ── M14: Meldungen mit Event 30 Tage nach dem Event löschen ──
wp_set_current_user(1);
$evOld = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'AF2 Alt', 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() - 45 * 86400), '_tix_time_start' => '22:00']]); $posts[] = $evOld;
$evNear = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'AF2 Neu', 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() - 10 * 86400), '_tix_time_start' => '22:00']]); $posts[] = $evNear;
$repOld = wp_insert_post(['post_type' => 'tix_support_ticket', 'post_status' => $st_list[0], 'post_title' => 'AF2 alt', 'meta_input' => ['_tix_sp_category' => 'awareness', '_tix_sp_event_id' => $evOld]]); $posts[] = $repOld;
$repNear = wp_insert_post(['post_type' => 'tix_support_ticket', 'post_status' => $st_list[0], 'post_title' => 'AF2 neu', 'meta_input' => ['_tix_sp_category' => 'awareness', '_tix_sp_event_id' => $evNear]]); $posts[] = $repNear;
TIX_Awareness_Live::cleanup();
clean_post_cache($repOld); clean_post_cache($repNear); clean_post_cache($rep);
af_eq('M14 Meldung 45 Tage nach dem Event gelöscht (obwohl erst heute eingegangen)', null, get_post($repOld));
af_eq('M14 Meldung 10 Tage nach dem Event bleibt', true, (bool) get_post($repNear));
af_eq('M14 Meldung zu kommendem Event bleibt', true, (bool) get_post($rep));

// ── S10: Gewinnspiel-Teilnehmerdaten, Auslosen, Zurücksetzen nur Manager ──
global $wpdb;
TIX_Raffle::create_table();
$rt = $wpdb->prefix . TIX_Raffle::TABLE;
update_post_meta($ev, '_tix_raffle_enabled', '1');
update_post_meta($ev, '_tix_raffle_prizes', [['name' => 'AF2 Preis', 'qty' => 1, 'type' => 'text']]);
update_post_meta($ev, '_tix_raffle_status', 'open');
for ($i = 0; $i < 3; $i++) $wpdb->insert($rt, ['event_id' => $ev, 'name' => "AF2 T$i", 'email' => "af2_t{$i}_$sfx@example.test"]);
wp_set_current_user($staff);
[$st] = af_rest('GET', "/events/$ev/raffle/entries");
af_eq('S10 Mitarbeiter: keine Teilnehmerliste', 403, $st);
[$st] = af_rest('POST', "/events/$ev/raffle/draw", ['notify' => false]);
af_eq('S10 Mitarbeiter: nicht auslosen', 403, $st);
[$st] = af_rest('POST', "/events/$ev/raffle/reset");
af_eq('S10 Mitarbeiter: nicht zurücksetzen', 403, $st);
[$st] = af_rest('GET', "/events/$ev/raffle");
af_eq('S10 Mitarbeiter: Gewinnspiel ansehen geht weiter', 200, $st);
wp_set_current_user($owner);
[$st] = af_rest('GET', "/events/$ev/raffle/entries");
af_eq('S10 Inhaber: Teilnehmerliste', 200, $st);

// ── S9: zweiter gleichzeitiger Lauf bricht ab ──
$db2 = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$lock = substr('tix_raffle_' . $wpdb->prefix . intval($ev), 0, 64);
af_eq('S9 Sperre auf zweiter Verbindung gesetzt', '1', (string) $db2->get_var($db2->prepare('SELECT GET_LOCK(%s, 0)', $lock)));
$r = TIX_Raffle::draw_winners($ev, false);
af_eq('S9 paralleler Lauf bricht ab', 'draw_running', is_wp_error($r) ? $r->get_error_code() : 'gezogen');
af_eq('S9 dabei nichts gezogen', 0, intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rt WHERE event_id = %d AND is_winner = 1", $ev))));
[$st, , $code] = af_rest('POST', "/events/$ev/raffle/draw", ['notify' => false]);
af_eq('S9 REST meldet 409 draw_running', [409, 'draw_running'], [$st, $code]);
$db2->query($db2->prepare('SELECT RELEASE_LOCK(%s)', $lock));
$db2->close();
$r = TIX_Raffle::draw_winners($ev, false);
af_eq('S9 nach Freigabe wird gezogen', 1, is_array($r) ? count($r) : -1);
update_post_meta($ev, '_tix_raffle_status', 'closed'); // wie ein später Cron-Lauf, der den Status überschreibt
$r = TIX_Raffle::draw_winners($ev, false);
af_eq('S9 zweite Auslosung trotz überschriebenem Status abgelehnt', 'already_drawn', is_wp_error($r) ? $r->get_error_code() : 'gezogen');
af_eq('S9 genau ein Gewinner', 1, intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rt WHERE event_id = %d AND is_winner = 1", $ev))));
af_eq('S9 Sperre wieder frei', '1', (string) $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', $lock)));
$wpdb->delete($rt, ['event_id' => $ev], ['%d']);

// ── M17: POST /studio/config prüft alles vor dem ersten Schreiben ──
$ts = get_option('tix_settings', []);
$arch_before = intval(is_array($ts) ? ($ts['auto_archive_enabled'] ?? 0) : 0);
$grace_before = get_option('tix_archive_grace_hours', null);
wp_set_current_user(1);
[$st, , $code] = af_rest('POST', '/studio/config', ['archive' => ['enabled' => !$arch_before], 'af_unbekannt' => 1]);
af_eq('M17 unbekanntes Feld → 400', 400, $st);
$ts = get_option('tix_settings', []);
af_eq('M17 Archiv nach 400 unverändert', $arch_before, intval(is_array($ts) ? ($ts['auto_archive_enabled'] ?? 0) : 0));
[$st] = af_rest('POST', '/studio/config', ['archive' => ['enabled' => !$arch_before], 'order_number_code' => 'X']);
af_eq('M17 falsches Kürzel → 400', 400, $st);
$ts = get_option('tix_settings', []);
af_eq('M17 Archiv nach falschem Kürzel unverändert', $arch_before, intval(is_array($ts) ? ($ts['auto_archive_enabled'] ?? 0) : 0));
if (!$multi) {
    wp_set_current_user($owner);
    [$st] = af_rest('POST', '/studio/config', ['archive' => ['enabled' => !$arch_before], 'app_layout' => 'club']);
    af_eq('M17 Inhaber ohne Admin-Recht für app_layout → 403', 403, $st);
    $ts = get_option('tix_settings', []);
    af_eq('M17 Archiv nach 403 unverändert', $arch_before, intval(is_array($ts) ? ($ts['auto_archive_enabled'] ?? 0) : 0));
    [$st] = af_rest('POST', '/studio/config', ['archive' => ['enabled' => !$arch_before], 'has_app' => true]);
    af_eq('M17 Inhaber ohne Admin-Recht für has_app → 403', 403, $st);
    $ts = get_option('tix_settings', []);
    af_eq('M17 Archiv nach has_app-403 unverändert', $arch_before, intval(is_array($ts) ? ($ts['auto_archive_enabled'] ?? 0) : 0));
    [$st] = af_rest('POST', '/studio/config', ['archive' => ['grace_hours' => 7]]);
    af_eq('M17 gültige Archiv-Änderung geht weiter', 200, $st);
    if ($grace_before === null) delete_option('tix_archive_grace_hours'); else update_option('tix_archive_grace_hours', $grace_before, false);
}

// ── S12: Website-Links ohne //fremd ──
if (class_exists('TIX_Website')) {
    $u = new ReflectionMethod('TIX_Website', 'url'); $u->setAccessible(true);
    af_eq('S12 //fremd abgelehnt', true, is_wp_error($u->invoke(null, '//evil.example/', 'nav.url')));
    af_eq('S12 /\\fremd abgelehnt', true, is_wp_error($u->invoke(null, '/\\evil.example/', 'nav.url')));
    af_eq('S12 /pfad geht', '/events/', $u->invoke(null, '/events/', 'nav.url'));
    af_eq('S12 https geht', 'https://example.org/x', $u->invoke(null, 'https://example.org/x', 'nav.url'));
}

// Aufräumen
wp_set_current_user(1);
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
