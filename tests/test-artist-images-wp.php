<?php
/** Künstlerbilder aus einer Quelle (1.38.669): Act-Foto ↔ Künstlerprofil. wp eval-file …/tests/test-artist-images-wp.php (beide Modi). */
if (!defined('ABSPATH') || !class_exists('TIX_Artists') || !method_exists('TIX_Artists', 'adopt_photo')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function aim_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function aim_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d]; }
global $wpdb;
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = []; $posts = [];
$admin = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
$owner = wp_insert_user(['user_login' => "aim_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "aim_owner_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $owner;
$org = 0; $org2 = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'AIM Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'AIM Andere']); $posts[] = $org2;
}
$img = function ($t) use (&$posts) { $a = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => $t, 'post_status' => 'inherit'], ABSPATH . 'wp-admin/images/w-logo-blue.png'); $posts[] = $a; return $a; };
$url = function ($a) { return (string) wp_get_attachment_image_url($a, 'large'); };
$mkart = function ($name, $o, $image = 0, $status = 'public') use (&$posts) {
    $id = wp_insert_post(['post_type' => 'tix_artist', 'post_status' => 'publish', 'post_title' => $name, 'post_name' => sanitize_title($name) . '-' . wp_rand(100, 999)]); $posts[] = $id;
    update_post_meta($id, TIX_Artists::META, ['status' => $status, 'managed_by' => $o ? ['type' => 'organizer', 'id' => $o] : ['type' => 'site', 'id' => 0], 'ids' => ['spotify' => '', 'apple' => ''], 'links' => [], 'tours' => [],
        'images' => $image ? ['portrait' => ['id' => $image, 'credit' => 'Foto: Test']] : []]);
    update_post_meta($id, TIX_Artists::META_NORM, TIX_Artists::norm($name));
    TIX_Artists::flush_cache();
    return $id;
};
$mkev = function ($t, $o, $lineup) use (&$posts) { $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "AIM $t", 'meta_input' => ['_tix_organizer_id' => $o, '_tix_date_start' => wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS), '_tix_info_lineup' => $lineup]]); $posts[] = $id; return $id; };
$pub = function ($eid) { TIX_Artists::flush_cache(); TIX_Public_Events::flush(); wp_cache_flush(); [$st, $d] = aim_rest('GET', "/public/events/$eid"); $rows = []; foreach ((array) ($d['event']['lineup_links'] ?? []) as $r) $rows[$r['artist']] = $r; return [$rows, (array) ($d['event']['artists'] ?? [])]; };
$portrait = function ($aid) { return intval((get_post_meta($aid, TIX_Artists::META, true)['images']['portrait']['id'] ?? 0)); };
$o = $multi ? $org : 0;

// ═══ Namen ═══
aim_eq('act_name: Emoji und „Music by“ vorne, Zusatz in Klammern hinten weg', ['DJ Alex Alive', 'Andreas Köhler', 'Monchi'], [TIX_Artists::act_name('🎧 Music by DJ Alex Alive'), TIX_Artists::act_name('Andreas Köhler (DJ/Moderation)'), TIX_Artists::act_name('Monchi')]);
aim_eq('act_key: „🎧 DJ Manu V“ = „DJ Manu V“ = „Manu V“', true, TIX_Artists::act_key('🎧 DJ Manu V') === TIX_Artists::act_key('DJ Manu V') && TIX_Artists::act_key('Manu V') === TIX_Artists::act_key('🎧 DJ Manu V'));

// ═══ Richtung 1: Künstlerbild → Line-up (ohne Zuordnung, wie DJ Manu V) ═══
$P1 = $img('manu');
$manu = $mkart("🎧 DJ Manu $sfx", $o, $P1);
$ev = $mkev("Party $sfx", $o, "🎧 DJ Manu $sfx\nAndreas Kohl $sfx (DJ/Moderation)\nMusic by DJ Lexi $sfx");
[$rows] = $pub($ev);
$r = $rows["🎧 DJ Manu $sfx"] ?? [];
aim_eq('Nicht zugeordneter eigener Künstler: Zeile verknüpft, Bild vom Künstler', [$manu, "🎧 DJ Manu $sfx", 'artist', $url($P1)], [$r['artist_id'] ?? null, $r['artist_name'] ?? null, $r['picture_source'] ?? null, $r['picture'] ?? null]);
aim_eq('Künstler ohne veröffentlichtes Event: kein Link zur Künstlerseite (artist_slug null)', null, (array_key_exists('artist_slug', $r) ? $r['artist_slug'] : 'fehlt'));
[$st, $ar] = aim_rest('GET', "/public/events/$ev/artists");
$ar = array_column((array) ($ar['artists'] ?? []), null, 'artist');
aim_eq('/public/events/{id}/artists: gleiches Bild', [$manu, $url($P1)], [$ar["🎧 DJ Manu $sfx"]['artist_id'] ?? null, $ar["🎧 DJ Manu $sfx"]['picture'] ?? null]);

// ═══ Richtung 2: Act-Foto → Künstlerprofil (wie MallorcaParty) ═══
$kohl = $mkart("Andreas Kohl $sfx", $o);
TIX_Artists::apply($ev, [['artist_id' => $kohl, 'role' => 'headliner', 'tour' => null, 'by_organizer' => $o, 'at' => current_time('c'), 'notified' => true]]);
$P2 = $img('kohl');
TIX_Lineup_Links::set_photo($ev, "Andreas Kohl $sfx (DJ/Moderation)", $P2);
aim_eq('Act-Foto hochgeladen: Künstler ohne Bild bekommt es als Porträt', $P2, $portrait($kohl));
[$rows, $arts] = $pub($ev);
$r = $rows["Andreas Kohl $sfx (DJ/Moderation)"] ?? [];
aim_eq('Zeile mit Klammer-Zusatz verknüpft, Bild aus dem Act, Künstlerseite verlinkt', [$kohl, 'act', $url($P2), true], [$r['artist_id'] ?? null, $r['picture_source'] ?? null, $r['picture'] ?? null, !empty($r['artist_slug'])]);
$P3 = $img('anderes');
TIX_Lineup_Links::set_photo($ev, "🎧 DJ Manu $sfx", $P3);
aim_eq('Künstler mit eigenem Bild: Act-Foto überschreibt nichts', $P1, $portrait($manu));
[$rows] = $pub($ev);
aim_eq('Act-Foto hat in der Zeile Vorrang', ['act', $url($P3)], [$rows["🎧 DJ Manu $sfx"]['picture_source'] ?? null, $rows["🎧 DJ Manu $sfx"]['picture'] ?? null]);

// ═══ artists[].image: Rückfall auf das Act-Foto ═══
$nina = $mkart("Nina Pop $sfx", $o);
$ev2 = $mkev("Zwei $sfx", $o, "Nina Pop $sfx");
TIX_Artists::apply($ev2, [['artist_id' => $nina, 'role' => 'headliner', 'tour' => null, 'by_organizer' => $o, 'at' => current_time('c'), 'notified' => true]]);
$P4 = $img('nina');
update_post_meta($ev2, TIX_Lineup_Links::META_PHOTOS, [TIX_Lineup_Links::norm("Nina Pop $sfx") => $P4]); // altes Foto ohne Übernahme (vor 1.38.669)
[$rows, $arts] = $pub($ev2);
$a = array_column($arts, null, 'id')[$nina] ?? [];
aim_eq('artists[]: Künstler ohne Bild zeigt das Act-Foto (image_source act)', ['act', true], [$a['image_source'] ?? null, !empty($a['image'])]);
aim_eq('Profil selbst ist noch leer (Nachtrag fehlt)', 0, $portrait($nina));

// ═══ Nachtrag per WP-CLI-Funktion ═══
$dry = TIX_Artists::sync_photos($ev2, true);
aim_eq('sync-photos trocken: zeigt den Künstler, ändert nichts', [1, $nina, 0], [$dry['adopted'], intval($dry['rows'][0]['artist_id'] ?? 0), $portrait($nina)]);
$run = TIX_Artists::sync_photos($ev2, false);
aim_eq('sync-photos: Profil bekommt das Act-Foto', [1, $P4], [$run['adopted'], $portrait($nina)]);
aim_eq('sync-photos erneut: nichts mehr zu tun', 0, TIX_Artists::sync_photos($ev2, false)['adopted']);

// ═══ Mehrdeutig bzw. fremd ═══
$mkart("Zwilling $sfx", $o); $mkart("🎧 Zwilling $sfx", $o);
$ev3 = $mkev("Drei $sfx", $o, "Zwilling $sfx");
[$rows] = $pub($ev3);
aim_eq('Zwei eigene Künstler gleichen Namens: keine Verknüpfung', [null, null], [array_key_exists('artist_id', $rows["Zwilling $sfx"] ?? []) ? $rows["Zwilling $sfx"]['artist_id'] : 'fehlt', array_key_exists('picture_source', $rows["Zwilling $sfx"] ?? []) ? $rows["Zwilling $sfx"]['picture_source'] : 'fehlt']);
if ($multi) {
    $P5 = $img('fremd');
    $fremd = $mkart("Fremdi $sfx", $org2, $P5);
    $ev4 = $mkev("Vier $sfx", $org, "Fremdi $sfx");
    [$rows] = $pub($ev4);
    aim_eq('Plattform: Künstler eines anderen Veranstalters wird nicht über den Namen verknüpft', null, array_key_exists('artist_id', $rows["Fremdi $sfx"] ?? []) ? $rows["Fremdi $sfx"]['artist_id'] : 'fehlt');
    $fl = $mkart("Fremdleer $sfx", $org2);
    TIX_Artists::apply($ev4, [['artist_id' => $fl, 'role' => 'headliner', 'tour' => null, 'by_organizer' => $org2, 'at' => current_time('c'), 'notified' => true]]);
    update_post_meta($ev4, '_tix_info_lineup', "Fremdi $sfx\nFremdleer $sfx");
    TIX_Lineup_Links::set_photo($ev4, "Fremdleer $sfx", $img('fl'));
    aim_eq('Plattform: zugeordneter fremder Künstler bekommt kein Act-Foto ans Profil', 0, $portrait($fl));
}

// ═══ Studio-Editor ═══
wp_set_current_user($multi ? $owner : $admin);
[$st, $ed] = aim_rest('GET', "/events/$ev/edit");
$er = []; foreach ((array) ($ed['lineup_links'] ?? ($ed['event']['lineup_links'] ?? [])) as $x) $er[$x['artist']] = $x;
aim_eq('Editor: Zeile mit artist_id, artist_name, picture_source und eigenem photo_id', [200, $kohl, "Andreas Kohl $sfx", 'act', $P2],
    [$st, $er["Andreas Kohl $sfx (DJ/Moderation)"]['artist_id'] ?? null, $er["Andreas Kohl $sfx (DJ/Moderation)"]['artist_name'] ?? null, $er["Andreas Kohl $sfx (DJ/Moderation)"]['picture_source'] ?? null, intval($er["Andreas Kohl $sfx (DJ/Moderation)"]['photo_id'] ?? 0)]);

// ═══ import-lineup: gesäuberte Namen, vorhandene Künstler, Fotos ═══
$count = function ($name) use ($wpdb) { return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tix_artist' AND post_status = 'publish' AND post_title = %s", $name))); };
$P6 = $img('lexi');
update_post_meta($ev, TIX_Lineup_Links::META_PHOTOS, TIX_Lineup_Links::photos($ev) + [TIX_Lineup_Links::norm("Music by DJ Lexi $sfx") => $P6]);
TIX_Artists::flush_cache();
[$st, $im] = aim_rest('POST', '/artists/import-lineup', ['event_id' => $ev]);
$new = array_column((array) ($im['new'] ?? []), null, 'name');
foreach ($new as $n) $posts[] = intval($n['id']);
aim_eq('Import: „Music by DJ Lexi“ wird „DJ Lexi“ mit Foto, keine Dublette von Manu und Kohl', [200, ["DJ Lexi $sfx"], $P6, 1, 0, 0],
    [$st, array_keys($new), $portrait(intval($new["DJ Lexi $sfx"]['id'] ?? 0)), $count("🎧 DJ Manu $sfx"), $count("DJ Manu $sfx"), $count("Andreas Kohl $sfx (DJ/Moderation)")]);
aim_eq('Import: Manu (bisher nicht zugeordnet) jetzt mit dem Event verknüpft', true, in_array($manu, array_map('intval', array_column(TIX_Artists::event_list($ev), 'artist_id')), true));

// Aufräumen
wp_set_current_user(1);
foreach ($posts as $p) { if (get_post_type($p) === 'attachment') wp_delete_attachment($p, true); else wp_delete_post($p, true); }
foreach ($users as $u) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($u); }
TIX_Public_Events::flush();
echo ($GLOBALS['t_fails'] ? 'ROT ' : 'GRÜN ') . $GLOBALS['t_count'] . ' Prüfungen, ' . $GLOBALS['t_fails'] . " Fehler\n";
