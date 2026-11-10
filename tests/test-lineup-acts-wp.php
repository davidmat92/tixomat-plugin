<?php
/**
 * Eine Line-up-Liste mit Bild (1.38.707, Vertrag `uebergaben/vertrag-lineup-kuenstlerseite.md`), nachgestellt nach Party Schwelm:
 * Tippfehler im Programm, Line-up-Text + zugeordnete Künstler, Programm über Mitternacht.
 *   wp eval-file …/test-lineup-acts-wp.php            (beide Modi)
 *   Einzel-Seite zusätzlich: … setup | run | cleanup  (Verteilung nach reiner Programm-Änderung, braucht Einstellungen)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Lineup_Links') || !method_exists('TIX_Lineup_Links', 'acts')) { echo "Tixomat ab 1.38.707 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$mode = isset($args[0]) ? (string) $args[0] : 'run';
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();

if (!$multi && $mode === 'setup') {
    $s = get_option('tix_settings', []);
    update_option('tix_lx_test_backup', ['had' => is_array($s), 'settings' => $s], false);
    $s = array_merge(is_array($s) ? $s : [], ['syndication_enabled' => 1, 'syndication_api_url' => 'https://plat.test/wp-json/tixomat/v1', 'syndication_api_key' => str_repeat('s', 32)]);
    update_option('tix_settings', $s);
    echo "setup ok\n"; return;
}
if (!$multi && $mode === 'cleanup') {
    $b = get_option('tix_lx_test_backup');
    if (is_array($b)) { if ($b['had']) update_option('tix_settings', $b['settings']); else delete_option('tix_settings'); }
    delete_option('tix_lx_test_backup');
    echo "cleanup ok\n"; return;
}

$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function lx_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function lx_k(array $a, $k) { return array_key_exists($k, $a) ? $a[$k] : 'fehlt'; }
function lx_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = rest_get_server()->response_to_data($res, false);
    return [$res->get_status(), is_array($d) ? $d : []];
}
$GLOBALS['lx_calls'] = [];
add_filter('pre_http_request', function ($pre, $a, $url) {
    if (strpos($url, '.test/') === false) return $pre;
    $GLOBALS['lx_calls'][] = ['url' => $url, 'method' => $a['method'] ?? 'GET', 'body' => json_decode((string) ($a['body'] ?? ''), true)];
    return ['headers' => [], 'body' => wp_json_encode(['success' => true, 'event_id' => 9001]), 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
}, 10, 3);

echo $multi ? "== Plattform\n" : "== Einzel-Seite\n";
$posts = [];
$admin = get_users(['role' => 'administrator', 'number' => 1]);
wp_set_current_user(intval($admin[0]->ID));
$img = function ($t) use (&$posts) { $a = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => $t, 'post_status' => 'inherit'], ABSPATH . 'wp-admin/images/w-logo-blue.png'); $posts[] = $a; return $a; };
$mka = function ($name) use (&$posts, $img) {
    $id = wp_insert_post(['post_type' => 'tix_artist', 'post_status' => 'publish', 'post_title' => $name, 'post_name' => sanitize_title($name)]); $posts[] = $id;
    update_post_meta($id, TIX_Artists::META, ['status' => 'public', 'managed_by' => ['type' => 'site', 'id' => 0], 'ids' => ['spotify' => '', 'apple' => ''], 'links' => [], 'tours' => [],
        'images' => ['portrait' => ['id' => $img($name), 'credit' => '']]]);
    update_post_meta($id, TIX_Artists::META_NORM, TIX_Artists::norm($name));
    TIX_Artists::flush_cache();
    return $id;
};
$sfx = wp_rand(100, 999);
try {
    $alm = $mka("Lxt Almklausi $sfx"); $micha = $mka("Lxt Micha Schuh $sfx"); $mau = $mka("Lxt Maurice Die Partykanone $sfx"); $mon = $mka("Lxt Monchi $sfx"); $extra = $mka("Lxt Headliner Extra $sfx");
    $date = wp_date('Y-m-d', time() + 30 * DAY_IN_SECONDS);
    $tt = [$date => [
        ['time' => '00:00', 'end' => '01:00', 'stage' => 0, 'title' => "Lxt Almklausi $sfx"],
        ['time' => '15:00', 'end' => '15:30', 'stage' => 0, 'title' => "Lxt Monchi $sfx"],
        ['time' => '16:30', 'end' => '17:00', 'stage' => 0, 'title' => "Lxt Micha Schue $sfx"],
        ['time' => '15:30', 'end' => '16:00', 'stage' => 0, 'title' => "Lxt Maurice Die Partykanona $sfx"],
        ['time' => '12:30', 'end' => '03:00', 'stage' => 0, 'title' => "Lxt Andi (DJ/Moderation) $sfx"],
    ]];
    $lineup = "<p>Lxt Andi (DJ/Moderation) $sfx</p>\n<p>Lxt Monchi $sfx</p>\n<p>Lxt Micha Schuh $sfx</p>\n<p>Lxt Maurice Die Partykanone $sfx</p>\n<p>Lxt Almklausi $sfx</p>";
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "Lxt Party &#8211; Schwelm $sfx",
        'meta_input' => ['_tix_date_start' => $date, '_tix_time_start' => '12:30', '_tix_info_lineup' => $lineup, '_tix_timetable' => $tt, '_tix_stages' => [['name' => 'Programm', 'color' => '#f50']],
            '_tix_lineup_sort' => 'time']]); // 1.38.733: die Prüfungen bis „Spiegel“ gelten der Reihenfolge nach Auftrittszeit
    $posts[] = $ev;
    TIX_Artists::apply($ev, array_map(function ($a) use ($extra) { return ['artist_id' => $a, 'role' => $a === $extra ? 'headliner' : 'support']; }, [$alm, $micha, $mau, $mon, $extra]));
    $get = function ($eid) { TIX_Artists::flush_cache(); if (method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush(); wp_cache_flush(); return lx_rest('GET', "/public/events/$eid")[1]['event'] ?? []; };
    $e = $get($ev);
    $acts = (array) ($e['lineup_acts'] ?? []);
    $names = array_column($acts, 'name');
    lx_eq('Liste: 6 Acts, eindeutig, Programm-Reihenfolge + Rest', ["Lxt Andi (DJ/Moderation) $sfx", "Lxt Monchi $sfx", "Lxt Maurice Die Partykanone $sfx", "Lxt Micha Schuh $sfx", "Lxt Almklausi $sfx", "Lxt Headliner Extra $sfx"], $names);
    lx_eq('Positionen 1…6', [1, 2, 3, 4, 5, 6], array_column($acts, 'position'));
    $m = $acts[3] ?? [];
    lx_eq('Tippfehler: Zeile bleibt, Künstler + Bild + Zeit', ["Lxt Micha Schue $sfx", $micha, true, '16:30', 'artist'], [$m['line'] ?? '', $m['artist_id'] ?? 0, !empty($m['image']), $m['time'] ?? '', $m['image_source'] ?? '']);
    lx_eq('Künstlerseite-URL', true, (bool) preg_match('#/kuenstler/' . preg_quote(get_post_field('post_name', $micha), '#') . '/$#', (string) ($m['artist_url'] ?? '')));
    lx_eq('Über Mitternacht: Almklausi Folgetag', [true, '00:00'], [$acts[4]['next_day'] ?? null, $acts[4]['time'] ?? '']);
    lx_eq('Ohne Künstler: kein Bild, Bühne', [null, null, 'Programm'], [lx_k($acts[0] ?? [], 'artist_id'), lx_k($acts[0] ?? [], 'image'), $acts[0]['stage_name'] ?? '']);
    lx_eq('Rest: Headliner ohne Zeile, ohne Zeit', [$extra, 'headliner', null], [$acts[5]['artist_id'] ?? 0, $acts[5]['role'] ?? '', lx_k($acts[5] ?? [], 'time')]);
    lx_eq('artists in Line-up-Reihenfolge', [$mon, $mau, $micha, $alm, $extra], array_map('intval', array_column((array) ($e['artists'] ?? []), 'id')));
    $ll = (array) ($e['lineup_links'] ?? []);
    lx_eq('lineup_links: 5, keine Doppelten', 5, count($ll));
    $by = array_column($ll, null, 'name');
    lx_eq('lineup_links: Tippfehler-Zeile am Künstler', [$micha, $mau], [intval($by["Lxt Micha Schue $sfx"]['artist_id'] ?? 0), intval($by["Lxt Maurice Die Partykanona $sfx"]['artist_id'] ?? 0)]);
    lx_eq('ähnlich: kurze Namen nicht', [false, true, false], [TIX_Lineup_Links::similar('Honk', 'Hunk'), TIX_Lineup_Links::similar('Micha Schue', 'Micha Schuh'), TIX_Lineup_Links::similar('DJ Düse', 'Rick Arena')]);

    // ── 1.38.733: Eigene Reihenfolge, Headliner oben
    TIX_Lineup_Links::save_order($ev, ['lineup_sort' => 'manual', 'lineup_order' => ["Lxt Almklausi $sfx", "Lxt Micha Schuh $sfx", "Lxt Andi (DJ/Moderation) $sfx"]]);
    $mo = array_column((array) ($get($ev)['lineup_acts'] ?? []), 'name');
    lx_eq('Eigene Reihenfolge: Headliner oben, dann Studio-Reihenfolge, Rest wie bisher', ["Lxt Headliner Extra $sfx", "Lxt Almklausi $sfx", "Lxt Micha Schuh $sfx", "Lxt Andi (DJ/Moderation) $sfx", "Lxt Monchi $sfx", "Lxt Maurice Die Partykanone $sfx"], $mo);
    $ea = $get($ev);
    lx_eq('Positionen neu, artists in derselben Reihenfolge', [[1, 2, 3, 4, 5, 6], [$extra, $alm, $micha, $mon, $mau]], [array_column((array) ($ea['lineup_acts'] ?? []), 'position'), array_map('intval', array_column((array) ($ea['artists'] ?? []), 'id'))]);
    $w = TIX_Lineup_Links::save_order($ev, ['lineup_sort' => 'zufall']);
    lx_eq('Ungültiger Modus 400', 'lineup_sort', is_wp_error($w) ? ($w->get_error_data()['field'] ?? '') : '');
    // Studio: Editor liefert Felder, POST speichert
    [$st, $d] = lx_rest('GET', "/events/$ev/edit");
    $ed = $d['event'] ?? $d;
    lx_eq('Editor: lineup_sort, lineup_has_times, sortierte lineup_acts', [200, 'manual', true, "Lxt Headliner Extra $sfx"], [$st, $ed['lineup_sort'] ?? '', $ed['lineup_has_times'] ?? null, $ed['lineup_acts'][0]['name'] ?? '']);
    [$st, $d] = lx_rest('POST', "/events/$ev", ['lineup_order' => ["Lxt Monchi $sfx", "Lxt Andi (DJ/Moderation) $sfx"], 'lineup_sort' => 'manual']);
    $mo = array_column((array) ($get($ev)['lineup_acts'] ?? []), 'name');
    lx_eq('POST /events/{id} lineup_order: gespeichert und wirksam', [200, "Lxt Headliner Extra $sfx", "Lxt Monchi $sfx", "Lxt Andi (DJ/Moderation) $sfx"], [$st, $mo[0] ?? '', $mo[1] ?? '', $mo[2] ?? '']);
    TIX_Lineup_Links::save_order($ev, ['lineup_sort' => 'time', 'lineup_order' => []]);

    // Programm ausgeblendet: gleiche Reihenfolge, ohne Zeiten
    update_post_meta($ev, '_tix_timetable_hidden', '1');
    $acts2 = (array) ($get($ev)['lineup_acts'] ?? []);
    lx_eq('ausgeblendet: Reihenfolge bleibt, keine Zeiten', [$names, [null]], [array_column($acts2, 'name'), array_values(array_unique(array_column($acts2, 'time')))]);
    delete_post_meta($ev, '_tix_timetable_hidden');

    // Spiegel: Liste der Quelle, ohne Künstler-IDs
    $snap = TIX_Lineup_Links::acts($ev);
    lx_eq('Schnappschuss = API-Liste', $names, array_column($snap, 'name'));
    $mir = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "Lxt Spiegel $sfx",
        'meta_input' => ['_tix_date_start' => $date, '_tix_syndicated' => '1', '_tix_lineup_acts' => $snap, '_tix_info_lineup' => $lineup]]);
    $posts[] = $mir;
    $ma = (array) ($get($mir)['lineup_acts'] ?? []);
    lx_eq('Spiegel: Liste der Quelle', $names, array_column($ma, 'name'));
    lx_eq('Spiegel: ohne Künstler-Bezug, Bild bleibt', [[null], [null], true], [array_values(array_unique(array_column($ma, 'artist_id'))), array_values(array_unique(array_column($ma, 'artist_url'))), !empty($ma[3]['image'])]);

    // ── 1.38.723: Künstler der Quelle auf Kopien (Spiegel, Kunden-Seite)
    $asnap = TIX_Artists::snapshot($ev);
    lx_eq('Schnappschuss Künstler: Line-up-Reihenfolge, Künstlerseite der Quelle', [["Lxt Monchi $sfx", "Lxt Maurice Die Partykanone $sfx", "Lxt Micha Schuh $sfx", "Lxt Almklausi $sfx", "Lxt Headliner Extra $sfx"], true, true],
        [array_column($asnap, 'name'), (bool) preg_match('#/kuenstler/[^/]+/$#', (string) ($asnap[2]['url'] ?? '')), !empty($asnap[2]['portrait']['medium'] ?? null)]);
    update_post_meta($mir, '_tix_artists', [['artist_id' => $alm, 'role' => 'headliner']]); // alte, fremde Zuordnung
    // Empfang wie bei der Verteilung (apply_meta): fremde IDs fallen weg, Schnappschuss wird gespeichert, Doppelte zusammengeführt
    $am = new ReflectionMethod('TIX_Syndication_Receive', 'apply_meta'); $am->setAccessible(true);
    $am->invoke(null, $mir, ['meta' => ['_tix_artists' => [['artist_id' => $alm]], '_tix_artist_ref' => (string) $alm], 'artists' => array_merge($asnap, [$asnap[0]])]);
    $me = $get($mir);
    $ar = (array) ($me['artists'] ?? []);
    lx_eq('Spiegel: artists der Quelle in Line-up-Reihenfolge, ohne Doppelte', [5, ["Lxt Monchi $sfx", "Lxt Maurice Die Partykanone $sfx", "Lxt Micha Schuh $sfx", "Lxt Almklausi $sfx", "Lxt Headliner Extra $sfx"]], [count($ar), array_column($ar, 'name')]);
    lx_eq('Spiegel: ohne ID/Slug hier, extern, Bild + Seite der Quelle', [null, null, true, true, true], [lx_k($ar[2] ?? [], 'id'), lx_k($ar[2] ?? [], 'slug'), $ar[2]['external'] ?? null, !empty($ar[2]['image']), (bool) preg_match('#/kuenstler/#', (string) ($ar[2]['url'] ?? ''))]);
    lx_eq('Spiegel: fremde Zuordnung entfernt', ['', []], [get_post_meta($mir, '_tix_artists', true), get_post_meta($mir, '_tix_artist_ref')]);
    $ml = array_column((array) ($me['lineup_links'] ?? []), null, 'name');
    lx_eq('Spiegel: lineup_links ohne ID (null statt 0), Bild vom Künstler', [null, true], [lx_k($ml["Lxt Micha Schuh $sfx"] ?? [], 'artist_id'), !empty($ml["Lxt Micha Schuh $sfx"]['picture'] ?? null)]);
    $ma = (array) ($me['lineup_acts'] ?? []);
    lx_eq('Spiegel: Line-up-Liste kennt die Künstlerseite der Quelle', true, (bool) preg_match('#/kuenstler/#', (string) ($ma[3]['artist_source_url'] ?? '')));
    if (!$multi) {
        // Kunden-Seite: Kopie eines Plattform-Co-Events (Abgleich speichert Line-up-Text, Liste, Künstler)
        $kc = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "Lxt Kopie $sfx",
            'meta_input' => ['_tix_date_start' => $date, '_tix_platform_co' => 987654, '_tix_info_lineup' => $lineup, '_tix_lineup_acts' => $ma]]);
        $posts[] = $kc;
        TIX_Artists::store_snapshot($kc, $ar);
        $ke = $get($kc);
        lx_eq('Kunden-Seite: artists, lineup, lineup_acts der Plattform', [5, true, 6, null], [count((array) ($ke['artists'] ?? [])), trim(wp_strip_all_tags((string) ($ke['lineup'] ?? ''))) !== '', count((array) ($ke['lineup_acts'] ?? [])), lx_k((array) ($ke['artists'][0] ?? []), 'id')]);
    }

    // Studio „Termine“: Titel ohne Entities
    [$st, $d] = lx_rest('GET', "/artists/$micha/events");
    $titles = array_column((array) ($d['events'] ?? $d['items'] ?? []), 'title');
    lx_eq('Künstler-Termine: Titel dekodiert', true, in_array("Lxt Party – Schwelm $sfx", $titles, true));

    // Einzel-Seite: reine Programm-Änderung eines geteilten Events wird verteilt
    if (!$multi) {
        if (!get_option('tix_lx_test_backup')) { echo "      (Verteilung: erst `setup` ausführen)\n"; }
        else {
            update_post_meta($ev, '_tix_syndicate', '1');
            update_post_meta($ev, '_tix_syndicate_remote_id', 9001);
            $GLOBALS['lx_calls'] = [];
            $tt[$date][2]['title'] = "Lxt Micha Schuh $sfx";
            [$st] = lx_rest('POST', "/events/$ev", ['timetable' => ['days' => $tt]]);
            $push = array_values(array_filter($GLOBALS['lx_calls'], function ($c) { return strpos($c['url'], '/syndicate') !== false && $c['method'] !== 'GET'; }));
            lx_eq('Nur Programm gespeichert: neu verteilt', [200, true], [$st, count($push) > 0]);
            $meta = $push ? (array) ($push[count($push) - 1]['body']['meta'] ?? []) : [];
            lx_eq('… mit Line-up-Liste', true, is_array($meta['_tix_lineup_acts'] ?? null) && count($meta['_tix_lineup_acts']) === 6);
            $body = $push ? (array) ($push[count($push) - 1]['body'] ?? []) : [];
            lx_eq('… mit Künstlern (Schnappschuss), ohne Künstler-IDs dieser Seite in meta', [5, false, false], [count((array) ($body['artists'] ?? [])), array_key_exists('_tix_artists', $meta), array_key_exists('_tix_artist_ref', $meta)]);
            $GLOBALS['lx_calls'] = [];
            [$st] = lx_rest('POST', "/events/$ev", ['lineup' => $lineup . "\n<p>Lxt Neu $sfx</p>"]);
            $push = array_filter($GLOBALS['lx_calls'], function ($c) { return strpos($c['url'], '/syndicate') !== false && $c['method'] !== 'GET'; });
            lx_eq('Nur Line-up-Text gespeichert: neu verteilt', [200, true], [$st, count($push) > 0]);
        }
    }
} catch (Throwable $e) {
    $GLOBALS['t_fails']++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach (array_reverse($posts) as $id) if ($id) wp_delete_post($id, true);
    TIX_Artists::flush_cache();
    wp_set_current_user(0);
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
