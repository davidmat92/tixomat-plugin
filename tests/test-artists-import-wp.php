<?php
/** Künstler aus dem Line-up übernehmen (POST /artists/import-lineup, 1.38.656). wp eval-file …/tests/test-artists-import-wp.php (beide Modi). */
if (!defined('ABSPATH') || !class_exists('TIX_Artists') || !method_exists('TIX_Artists', 'import_lineup')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function ai2_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function ai2_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $arts = [];
$owner = wp_insert_user(['user_login' => "ia_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "ia_owner_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $owner;
$other = wp_insert_user(['user_login' => "ia_other_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "ia_other_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $other;
$org = 0; $org2 = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'IA Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'IA Andere', 'meta_input' => ['_tix_org_user_id' => $other]]); $posts[] = $org2;
}
$A = "Ikke Hüftgold $sfx"; $B = "Lorenz Büffel $sfx"; $C = "Oli P. $sfx"; $D = "Tobee $sfx";
$mkev = function ($t, $o, $lineup) use (&$posts) { $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "IA $t", 'meta_input' => ['_tix_organizer_id' => $o, '_tix_date_start' => wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS), '_tix_info_lineup' => $lineup]]); $posts[] = $id; return $id; };
$ev = $mkev("Festival $sfx", $org, "$A\n$B\n$C");
// Spotify-Link (manuell) und Act-Foto für Ikke
$att = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'ikke', 'post_status' => 'inherit'], ABSPATH . 'wp-admin/images/w-logo-blue.png'); $posts[] = $att;
$k = TIX_Lineup_Links::norm(TIX_Lineup_Links::artist($A));
update_post_meta($ev, TIX_Lineup_Links::META, [$k => ['source' => 'manual', 'spotify' => 'https://open.spotify.com/artist/1a2B3c4D5e6F7g8H9i0J1k', 'apple_music' => '']]);
update_post_meta($ev, TIX_Lineup_Links::META_PHOTOS, [$k => $att]);
$count = function ($name) use ($wpdb) { return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tix_artist' AND post_status = 'publish' AND post_title = %s", $name))); };
wp_set_current_user($multi ? $owner : intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1));

// ═══ Vorschau ═══
[$st, $r] = ai2_rest('POST', '/artists/import-lineup', ['event_id' => $ev, 'dry_run' => true]);
ai2_eq('Vorschau: 3 neue Acts, nichts angelegt', [200, true, 3, 0], [$st, $r['dry_run'] ?? null, count((array) ($r['new'] ?? [])), $count($A)]);
// ═══ Übernehmen ═══
[$st, $r] = ai2_rest('POST', '/artists/import-lineup', ['event_id' => $ev]);
$new = array_column((array) ($r['new'] ?? []), null, 'name');
$aid = intval($new[$A]['id'] ?? 0); foreach ($new as $n) $arts[] = intval($n['id']);
$raw = get_post_meta($aid, TIX_Artists::META, true);
ai2_eq('Übernommen: 3 Künstler, Spotify-Kennung und Link, Act-Foto als Porträt', [200, 3, '1a2B3c4D5e6F7g8H9i0J1k', 'https://open.spotify.com/artist/1a2B3c4D5e6F7g8H9i0J1k', $att],
    [$st, count($new), (string) ($raw['ids']['spotify'] ?? ''), (string) ($raw['links']['spotify'] ?? ''), intval($raw['images']['portrait']['id'] ?? 0)]);
ai2_eq('Verwalter: Seite bzw. eigener Veranstalter', $multi ? ['organizer', $org] : ['site', 0], [(string) ($raw['managed_by']['type'] ?? ''), intval($raw['managed_by']['id'] ?? -1)]);
$list = array_map('intval', array_column(TIX_Artists::event_list($ev), 'artist_id'));
ai2_eq('Zeilen verknüpft: Event kennt die 3 Künstler (Index gepflegt)', [3, true], [count(array_intersect($list, $arts)), in_array((string) $aid, get_post_meta($ev, TIX_Artists::EV_REF), true)]);
// ═══ Wiederholen ═══
[$st, $r2] = ai2_rest('POST', '/artists/import-lineup', ['event_id' => $ev]);
ai2_eq('Wiederholen: keine Dubletten, alle unverändert', [200, 0, 3, 1], [$st, count((array) ($r2['new'] ?? [])), count((array) ($r2['unchanged'] ?? [])), $count($A)]);
// ═══ Zweites Event: bestehender Künstler wird verknüpft, fehlendes Foto ergänzt ═══
$ev2 = $mkev("Zweites $sfx", $org, "$B\n$D");
$att2 = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'lorenz', 'post_status' => 'inherit'], ABSPATH . 'wp-admin/images/w-logo-blue.png'); $posts[] = $att2;
update_post_meta($ev2, TIX_Lineup_Links::META_PHOTOS, [TIX_Lineup_Links::norm(TIX_Lineup_Links::artist($B)) => $att2]);
if ($multi) {
    // Tobee gibt es schon – öffentlich, beim anderen Veranstalter
    $tob = wp_insert_post(['post_type' => 'tix_artist', 'post_status' => 'publish', 'post_title' => $D, 'post_name' => sanitize_title($D)]); $arts[] = $tob;
    update_post_meta($tob, TIX_Artists::META, ['status' => 'public', 'managed_by' => ['type' => 'organizer', 'id' => $org2], 'ids' => ['spotify' => '', 'apple' => ''], 'links' => [], 'images' => []]);
    update_post_meta($tob, TIX_Artists::META_NORM, TIX_Artists::norm($D));
}
[$st, $r3] = ai2_rest('POST', '/artists/import-lineup', []);
$bid = intval($new[$B]['id'] ?? 0);
$linked = array_column((array) ($r3['linked'] ?? []), null, 'name');
ai2_eq('Alle Events: Lorenz mit dem zweiten Event verknüpft, sein Foto ergänzt', [200, [$ev2], $att2], [$st, $linked[$B]['linked_events'] ?? null, intval((get_post_meta($bid, TIX_Artists::META, true)['images']['portrait']['id'] ?? 0))]);
if ($multi) {
    ai2_eq('Plattform: öffentlicher Künstler des anderen Veranstalters wird verknüpft, nicht neu angelegt', [false, 1, $tob], [isset(array_column((array) ($r3['new'] ?? []), null, 'name')[$D]), $count($D), intval($linked[$D]['id'] ?? 0)]);
} else {
    $nd = array_column((array) ($r3['new'] ?? []), null, 'name')[$D] ?? []; $arts[] = intval($nd['id'] ?? 0);
    ai2_eq('Einzel-Seite: neuer Act aus dem zweiten Event angelegt', 1, $count($D));
}
ai2_eq('Weiterhin keine Dubletten', [1, 1], [$count($A), $count($B)]);
if ($multi) {
    $evf = $mkev("Fremd $sfx", $org2, "Fremd Act $sfx");
    [$st, $rf] = ai2_rest('POST', '/artists/import-lineup', []);
    ai2_eq('Fremdes Event wird nicht gelesen', 0, $count("Fremd Act $sfx"));
    wp_set_current_user($other);
    [$st, $d, $c] = ai2_rest('POST', '/artists/import-lineup', ['event_id' => $ev]);
    ai2_eq('Anderer Veranstalter: kein Zugriff auf mein Event', 403, $st);
    wp_set_current_user($owner);
}
wp_set_current_user(0);
[$st] = ai2_rest('POST', '/artists/import-lineup', []);
ai2_eq('Ohne Anmeldung → 401', 401, $st);

// Aufräumen
wp_set_current_user(1);
foreach (array_unique(array_filter($arts)) as $a) wp_delete_post($a, true);
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
