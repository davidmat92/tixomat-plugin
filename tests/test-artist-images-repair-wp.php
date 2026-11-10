<?php
/**
 * Künstlerbilder nach Umzug und an Kopien (1.38.743, Fund KMT: 1 von 12 Künstlern mit Bild).
 *   kk   eval-file …/test-artist-images-repair-wp.php setup | run | cleanup   (Kunden-Seite, Plattform per pre_http_request)
 *   plat eval-file …/test-artist-images-repair-wp.php                          (Plattform: GET /partner/move-artists)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Shared_Objects') || !method_exists('TIX_Site_Move', 'repair_artist_images')) { echo "Tixomat ab 1.38.743 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$mode = isset($args[0]) ? (string) $args[0] : 'run';
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
if (!$multi && $mode === 'setup') {
    $s = get_option('tix_settings', []);
    update_option('tix_air_backup', ['had' => is_array($s), 'settings' => $s, 'imp' => get_option(TIX_Site_Move::OPT_IMPORT, null)], false);
    update_option('tix_settings', array_merge(is_array($s) ? $s : [], ['syndication_enabled' => 1, 'syndication_api_url' => 'https://plat.test/wp-json/tixomat/v1', 'syndication_api_key' => str_repeat('s', 32)]));
    echo "setup ok\n"; return;
}
if (!$multi && $mode === 'cleanup') {
    $b = get_option('tix_air_backup');
    if (is_array($b)) {
        if ($b['had']) update_option('tix_settings', $b['settings']); else delete_option('tix_settings');
        if ($b['imp'] === null) delete_option(TIX_Site_Move::OPT_IMPORT); else update_option(TIX_Site_Move::OPT_IMPORT, $b['imp'], false);
    }
    delete_option('tix_air_backup');
    echo "cleanup ok\n"; return;
}
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$adm = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user($adm[0]->ID);
$sfx = 'r' . wp_rand(100, 999);
$posts = []; $atts = [];
$mkatt = function ($parent = 0, $date = null) use (&$atts) {
    $up = wp_upload_bits('air-' . wp_generate_password(5, false) . '.png', null, (string) file_get_contents(ABSPATH . 'wp-admin/images/w-logo-blue.png'));
    $arr = ['post_mime_type' => 'image/png', 'post_title' => 'AIR', 'post_status' => 'inherit', 'post_parent' => $parent];
    if ($date) { $arr['post_date_gmt'] = $date; $arr['post_date'] = get_date_from_gmt($date); }
    $aid = wp_insert_attachment($arr, $up['file']);
    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $up['file']));
    $atts[] = $aid;
    return intval($aid);
};
$artist = function ($name, array $images = [], $from = 0) use (&$posts, $multi) {
    $id = wp_insert_post(['post_type' => 'tix_artist', 'post_status' => 'publish', 'post_title' => $name]);
    $posts[] = $id;
    update_post_meta($id, TIX_Artists::META, ['status' => 'public', 'managed_by' => ['type' => 'site', 'id' => 0], 'links' => [], 'tours' => [], 'images' => $images, 'ids' => ['spotify' => '', 'apple' => '']]);
    if ($from) update_post_meta($id, TIX_Site_Move::META_FROM, $from);
    return intval($id);
};

try {
    if ($multi) {
        // ═══ Plattform: GET /partner/move-artists liefert nur Künstler aus Events des Veranstalters ═══
        $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => "AIR Org $sfx"]); $posts[] = $org;
        $a1 = $artist("AIR Mit Bild $sfx", []); $p = $mkatt($a1);
        $d = get_post_meta($a1, TIX_Artists::META, true); $d['images']['portrait'] = ['id' => $p, 'credit' => 'Foto X']; update_post_meta($a1, TIX_Artists::META, $d);
        $a2 = $artist("AIR Fremd $sfx", []);
        $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "AIR Event $sfx", 'meta_input' => ['_tix_organizer_id' => $org, '_tix_artists' => [['artist_id' => $a1, 'role' => 'headliner']]]]); $posts[] = $ev;
        $req = new WP_REST_Request('GET', '/tixomat/v1/partner/move-artists');
        $req->set_param('ids', "$a1,$a2,999999");
        $req->set_param('_partner_oid', $org);
        $d = json_decode(wp_json_encode(TIX_Site_Move::rest_partner_move_artists($req)->get_data()), true);
        $it = $d['items'] ?? [];
        $eq('Plattform: nur eigener Künstler, mit Bildadresse + Nachweis', [1, $a1, true, 'Foto X', null], [count($it), intval($it[0]['id'] ?? 0), strpos((string) ($it[0]['images']['portrait']['url'] ?? ''), '/wp-content/uploads/') !== false, $it[0]['images']['portrait']['credit'] ?? '', array_key_exists('cover', (array) ($it[0]['images'] ?? [])) ? $it[0]['images']['cover'] : 'x']);
        $q = new WP_REST_Request('GET', '/tixomat/v1/partner/move-artists');
        $eq('Plattform: Route ohne Partner-Schlüssel gesperrt', 401, rest_do_request($q)->get_status());
        $eq('Umzugs-Export enthält images_src', true, isset(TIX_Site_Move::artist_images_src($a1)['portrait']['url']));
    } else {
        // ═══ Kunden-Seite ═══
        $import_at = gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS);
        update_option(TIX_Site_Move::OPT_IMPORT, ['status' => 'done', 'from' => 'https://plat.test/wp-json/tixomat/v1', 'at' => gmdate('c', strtotime($import_at . ' UTC'))], false);
        $before = gmdate('Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS);
        $flyer = $mkatt(0, $before);                       // fremder Anhang, den die kopierte ID zufällig trifft (Flyer o. Ä.)
        $own_up = $mkatt(0, gmdate('Y-m-d H:i:s'));        // nach dem Umzug hier hochgeladen
        // umgezogene Künstler: A fremde ID (Plattform hat Bild), B ins Leere (Plattform ohne Bild), C eigenes Bild nach dem Umzug, D fremd + Plattform kennt ihn nicht
        $A = $artist("AIR Umzug A $sfx", ['portrait' => ['id' => $flyer, 'credit' => '']], 7001);
        $B = $artist("AIR Umzug B $sfx", ['portrait' => ['id' => 99999999, 'credit' => '']], 7002);
        $C = $artist("AIR Umzug C $sfx", ['portrait' => ['id' => $own_up, 'credit' => 'eigen']], 7003);
        $D = $artist("AIR Umzug D $sfx", ['cover' => ['id' => $flyer, 'credit' => '']], 7004);
        $eq('Kopierte fremde ID zählt nicht als Bild, eigenes nach dem Umzug schon', [false, false, true], [TIX_Shared_Objects::own_has_image($A), TIX_Shared_Objects::own_has_image($B), TIX_Shared_Objects::own_has_image($C)]);
        $GLOBALS['air_calls'] = [];
        add_filter('pre_http_request', function ($pre, $a, $url) {
            if (strpos($url, 'plat.test') === false) return $pre;
            $GLOBALS['air_calls'][] = $url;
            if (strpos($url, '/partner/move-artists') === false) return $pre;
            $items = [['id' => 7001, 'name' => 'A', 'images' => ['portrait' => ['url' => 'https://plat.test/a.jpg', 'credit' => 'Foto A'], 'cover' => null]],
                      ['id' => 7002, 'name' => 'B', 'images' => ['portrait' => null, 'cover' => null]],
                      ['id' => 7003, 'name' => 'C', 'images' => ['portrait' => ['url' => 'https://plat.test/c.jpg', 'credit' => ''], 'cover' => null]]];
            return ['headers' => [], 'body' => wp_json_encode(['ok' => true, 'items' => $items]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        }, 10, 3);
        $GLOBALS['air_dl'] = [];
        add_filter('tix_shared_objects_sideload', function ($pre, $url, $parent) use ($mkatt) {
            $GLOBALS['air_dl'][] = $url;
            if (strpos($url, 'kaputt') !== false) return new WP_Error('tix_mirror_img', 'Download fehlgeschlagen: blockiert');
            return $mkatt($parent);
        }, 10, 3);
        $dry = TIX_Site_Move::repair_artist_images(true);
        $eq('Trockenlauf: nichts geladen, nichts geändert', [0, $flyer], [count($GLOBALS['air_dl']), intval(get_post_meta($A, TIX_Artists::META, true)['images']['portrait']['id'] ?? 0)]);
        $r = TIX_Site_Move::repair_artist_images();
        $eq('Reparatur Umzug: Plattform gefragt, 1 geladen, 1 behalten, 2 entfernt', [true, 1, 1, 2, 0], [$r['platform'], $r['loaded'], $r['kept'], $r['cleared'], $r['failed']]);
        $dA = get_post_meta($A, TIX_Artists::META, true);
        $eq('A: Bild der Plattform statt Flyer, Nachweis, als eigenes Bild markiert', [true, 'Foto A', true, true], [intval($dA['images']['portrait']['id'] ?? 0) !== $flyer, $dA['images']['portrait']['credit'] ?? '', TIX_Shared_Objects::own_has_image($A), (bool) get_post($flyer)]);
        $eq('B: ID ins Leere entfernt; C: eigenes Bild bleibt; D: fremdes Titelbild entfernt', [[], $own_up, []], [(array) (get_post_meta($B, TIX_Artists::META, true)['images'] ?? []), intval(get_post_meta($C, TIX_Artists::META, true)['images']['portrait']['id'] ?? 0), (array) (get_post_meta($D, TIX_Artists::META, true)['images'] ?? [])]);
        $dl = count($GLOBALS['air_dl']);
        $r2 = TIX_Site_Move::repair_artist_images();
        $eq('Zweiter Lauf: nichts mehr zu tun', [0, 0, $dl], [$r2['loaded'], $r2['cleared'], count($GLOBALS['air_dl'])]);

        // ═══ Eigener Künstler (z. B. umgezogen ohne Bild) an einer Kopie: übernimmt das Bild der Quelle ═══
        $snap = [['source_id' => 501, 'name' => "AIR Umzug B $sfx", 'portrait' => ['large' => 'https://plat.test/b-1024.jpg', 'medium' => 'https://plat.test/b-300.jpg', 'credit' => 'Foto B']],
                 ['source_id' => 503, 'name' => "AIR Umzug C $sfx", 'portrait' => ['large' => 'https://plat.test/c2.jpg', 'medium' => 'https://plat.test/c2.jpg']],
                 ['source_id' => 504, 'name' => "AIR Kaputt $sfx", 'image' => 'https://plat.test/kaputt.jpg']];
        $K = $artist("AIR Kaputt $sfx", []);
        $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "AIR Kopie $sfx", 'meta_input' => ['_tix_platform_co' => 7777, '_tix_platform_co_source' => 'plat.test', '_tix_date_start' => wp_date('Y-m-d', time() + 20 * DAY_IN_SECONDS)]]);
        $posts[] = $ev;
        TIX_Artists::store_snapshot($ev, $snap);
        $refs = array_map('intval', (array) get_post_meta($ev, TIX_Artists::EV_REF));
        $eq('Eigene verknüpft, kein Spiegel angelegt', [true, true, true, 0], [in_array($B, $refs, true), in_array($C, $refs, true), in_array($K, $refs, true), count(array_filter($refs, function ($r) { return (bool) TIX_Shared_Objects::mirror($r); }))]);
        $dB = get_post_meta($B, TIX_Artists::META, true);
        $pB = intval($dB['images']['portrait']['id'] ?? 0);
        $eq('B übernimmt das Bild der Quelle (Mediathek, Nachweis)', [true, 'Foto B', true, true], [$pB > 0, $dB['images']['portrait']['credit'] ?? '', TIX_Shared_Objects::own_has_image($B), TIX_Artists::has_image($B)]);
        TIX_Artists::flush_cache();
        $eq('Karte in /public/artists hat jetzt ein Bild', true, (string) (TIX_Artists::card($B)['image'] ?? '') !== '');
        $eq('C behält sein eigenes Bild', $own_up, intval(get_post_meta($C, TIX_Artists::META, true)['images']['portrait']['id'] ?? 0));
        $eq('Download gescheitert: Fehler am Künstler vermerkt', true, (string) get_post_meta($K, '_tix_adopted_error', true) !== '');
        $dl = count($GLOBALS['air_dl']);
        TIX_Artists::store_snapshot($ev, $snap);
        $eq('Erneuter Abgleich: übernommenes Bild nicht neu geladen, gescheitertes erneut versucht', $dl + 1, count($GLOBALS['air_dl']));
        $snap[0]['portrait']['large'] = 'https://plat.test/b-neu.jpg';
        TIX_Artists::store_snapshot($ev, $snap);
        $pB2 = intval(get_post_meta($B, TIX_Artists::META, true)['images']['portrait']['id'] ?? 0);
        $eq('Bild an der Quelle geändert: übernommenes Bild nachgezogen, altes weg', [true, false], [$pB2 !== $pB && $pB2 > 0, (bool) get_post($pB)]);
        $rep = TIX_Shared_Objects::artist_report();
        $rows = array_column($rep['items'], null, 'id');
        $eq('Prüfbericht: Herkunft und Bildstand', ['umgezogen', true, 'eigen', false], [$rows[$B]['type'] ?? '', strpos((string) ($rows[$B]['image'] ?? ''), 'übernommen') === 0, $rows[$K]['type'] ?? '', $rows[$K]['has_image'] ?? null]);
        $full = TIX_Shared_Objects::repair(true);
        $eq('Vollständige Reparatur: Bericht mit vorher/nachher', [true, true, true], [$full['events'] >= 1, isset($full['before']['without_image']), is_array($full['moved'])]);

        // ═══ „Künstler aus Line-up übernehmen“ überspringt Kopien ═══
        update_post_meta($ev, '_tix_info_lineup', "AIR Nur Kopie $sfx");
        $m = new ReflectionMethod('TIX_Artists', 'import_events'); $m->setAccessible(true);
        $eq('Import aus dem Line-up: Kopien ausgenommen', false, in_array($ev, (array) $m->invoke(null, 0), true));
    }
} finally {
    foreach (array_reverse($posts) as $p) {
        if (get_post_type($p) === 'tix_artist') foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'post_parent' => $p]) as $c) wp_delete_attachment($c, true);
        wp_delete_post($p, true);
    }
    foreach ($atts as $a) if (get_post($a)) wp_delete_attachment($a, true);
    TIX_Shared_Objects::cleanup();
}
echo "\n$n Prüfungen, $f Fehler\n";
