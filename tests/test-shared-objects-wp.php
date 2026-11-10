<?php
/** Geteilte Objekte als lokale Spiegel (1.38.738). wp eval-file …/test-shared-objects-wp.php (Kunden-Seite und Plattform) */
if (!defined('ABSPATH') || !class_exists('TIX_Shared_Objects')) { echo "Tixomat ab 1.38.738 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = json_decode(wp_json_encode(rest_get_server()->response_to_data($res, false)), true); return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : '']; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$a = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user($a[0]->ID);
$sfx = 'z' . wp_rand(100, 999);
$posts = [];
$partner_bak = $multi ? get_option(TIX_Partners::OPTION, null) : null;
$snap = [
    ['source_id' => 1162, 'name' => "Maurice SOT $sfx", 'role' => 'support', 'kind' => 'solo', 'short_bio' => 'Partykanone', 'links' => ['instagram' => 'https://instagram.com/maurice'],
     'portrait' => ['medium' => 'https://kk.test/m-300.jpg', 'medium_large' => 'https://kk.test/m-768.jpg', 'large' => 'https://kk.test/m-1024.jpg', 'credit' => 'Foto KK'], 'url' => 'https://kk.test/kuenstler/maurice/'],
    ['source_id' => 1170, 'name' => "Eigen SOT $sfx"],
];
try {
    $orgK = $multi ? wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => "SOT KK $sfx"]) : 0; if ($orgK) $posts[] = $orgK;
    // eigener Künstler gleichen Namens → verknüpfen statt spiegeln
    $own = wp_insert_post(['post_type' => 'tix_artist', 'post_status' => 'publish', 'post_title' => "Eigen SOT $sfx"]); $posts[] = $own;
    update_post_meta($own, TIX_Artists::META, ['status' => 'public', 'managed_by' => $orgK ? ['type' => 'organizer', 'id' => $orgK] : ['type' => 'site', 'id' => 0], 'links' => [], 'tours' => [], 'images' => [], 'ids' => ['spotify' => '', 'apple' => '']]);
    if ($multi) {
        $all = (array) get_option(TIX_Partners::OPTION, []);
        $all['sotkk'] = ['id' => 'sotkk', 'name' => 'SOT KitchenKlub', 'api_base' => 'https://kk.test/wp-json/tixomat/v1', 'key_in' => str_repeat('i', 32), 'key_out' => str_repeat('o', 32), 'organizer_id' => $orgK];
        update_option(TIX_Partners::OPTION, $all, false);
        $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "SOT Spiegel $sfx", 'meta_input' => ['_tix_syndicated' => '1', '_tix_source_partner' => 'sotkk', '_tix_source_id' => 1171, '_tix_organizer_id' => $orgK, '_tix_date_start' => wp_date('Y-m-d', time() + 20 * DAY_IN_SECONDS)]]);
    } else {
        $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "SOT Kopie $sfx", 'meta_input' => ['_tix_platform_co' => 6453, '_tix_platform_co_source' => 'kk.test', '_tix_platform_co_by' => 'KitchenKlub', '_tix_date_start' => wp_date('Y-m-d', time() + 20 * DAY_IN_SECONDS)]]);
    }
    $posts[] = $ev;
    $eq('Herkunft der Kopie', 'kk.test', (TIX_Shared_Objects::source_of($ev) ?? [])['host'] ?? '');
    TIX_Artists::store_snapshot($ev, $snap);
    $refs = array_map('intval', (array) get_post_meta($ev, TIX_Artists::EV_REF));
    $mir = 0; foreach ($refs as $r) if (TIX_Shared_Objects::mirror($r)) $mir = $r;
    $eq('Künstler: Spiegel angelegt + eigener verknüpft (nicht doppelt)', [2, true, true], [count($refs), $mir > 0, in_array($own, $refs, true)]);
    $m = TIX_Shared_Objects::mirror($mir);
    $eq('Spiegel markiert mit Herkunft, Link zur Künstlerseite', ['kk.test', 'https://kk.test/kuenstler/maurice/'], [$m['host'] ?? '', $m['url'] ?? '']);
    $img = TIX_Artists::images_public($mir);
    $eq('Bilder der Quelle als Adresse', ['https://kk.test/m-300.jpg', 'Foto KK', true], [$img['portrait']['medium'] ?? '', $img['credit'] ?? '', TIX_Artists::has_image($mir)]);
    TIX_Artists::store_snapshot($ev, $snap);
    $eq('erneuter Abgleich: kein zweiter Spiegel', 1, count(get_posts(['post_type' => 'tix_artist', 'post_status' => 'any', 'fields' => 'ids', 'meta_query' => [['key' => TIX_Shared_Objects::META . '_key', 'value' => 'kk.test|1162']]])));
    if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush(); TIX_Artists::flush_cache(); wp_cache_flush();
    [$s, $d] = $rest('GET', '/public/artists');
    $row = array_column((array) ($d['artists'] ?? $d['items'] ?? []), null, 'id')[$mir] ?? [];
    $eq('Öffentliche Künstlerliste: Spiegel dabei, source + readonly', [200, true, 'von kk.test'], [$s, $row['readonly'] ?? null, $row['source']['label'] ?? '']);
    if (!$multi) {
        [$s, $d] = $rest('GET', '/artists/mine');
        $row = array_column((array) ($d['artists'] ?? []), null, 'id')[$mir] ?? [];
        $eq('Studio „Künstler“: Spiegel dabei, readonly', [200, true], [$s, $row['readonly'] ?? null]);
    }
    $eq('Spiegel ändern 409', [409, 'tix_mirrored_readonly'], (function ($r) { return [$r[0], $r[2]]; })($rest('POST', "/artists/$mir", ['short_bio' => 'x'])));
    if (!$multi) {
        // Ort: eigener gleichen Namens + Adresse verknüpft, sonst Spiegel
        $ownloc = wp_insert_post(['post_type' => 'tix_location', 'post_status' => 'publish', 'post_title' => "SOT Halle $sfx", 'meta_input' => ['_tix_loc_address' => 'Hauptstr. 1']]); $posts[] = $ownloc;
        $eq('Ort: eigener gleichen Namens verknüpft', $ownloc, TIX_Shared_Objects::sync_location($ev, ['name' => "SOT Halle $sfx", 'address' => 'Hauptstr. 1', 'city' => 'Schwelm']));
        $lid = TIX_Shared_Objects::sync_location($ev, ['id' => 77, 'name' => "SOT Club $sfx", 'address' => 'Clubweg 2', 'zip' => '42103', 'city' => 'Wuppertal', 'lat' => 51.25, 'lng' => 7.15]);
        $eq('Ort: Spiegel mit Adresse/Koordinaten, am Event', [true, 'Wuppertal', '51.25', $lid], [(bool) TIX_Shared_Objects::mirror($lid), (string) get_post_meta($lid, '_tix_loc_city', true), (string) get_post_meta($lid, '_tix_loc_lat', true), intval(get_post_meta($ev, '_tix_location_id', true))]);
        [$s, $d] = $rest('GET', '/locations');
        $row = array_column((array) ($d['locations'] ?? []), null, 'id')[$lid] ?? [];
        $eq('Studio „Orte“: readonly, nicht editierbar', [200, true, false], [$s, $row['readonly'] ?? null, $row['editable'] ?? null]);
        $eq('Ort ändern 409', 409, $rest('POST', "/locations/$lid", ['title' => 'x'])[0]);
        TIX_Shared_Objects::sync_categories($ev, ["SOT Party $sfx", "SOT Schlager $sfx"]);
        $eq('Kategorien gesetzt', ["SOT Party $sfx", "SOT Schlager $sfx"], array_values(array_map(function ($t) { return $t->name; }, wp_get_post_terms($ev, 'event_category', ['orderby' => 'name']))));
        foreach (wp_get_post_terms($ev, 'event_category') as $t) $GLOBALS['sot_terms'][] = $t->term_id;
    }
    // Aufräumen: Event weg → Spiegel weg, eigene bleiben
    wp_trash_post($ev);
    TIX_Shared_Objects::cleanup();
    $eq('Event entfernt: Spiegel gelöscht, eigener Künstler bleibt', [false, true], [(bool) get_post($mir), (bool) get_post($own)]);
    if (!$multi) $eq('… Orts-Spiegel gelöscht, eigener Ort bleibt', [false, true], [(bool) get_post($lid), (bool) get_post($ownloc)]);
} finally {
    foreach (array_reverse($posts) as $p) if (get_post($p)) wp_delete_post($p, true);
    foreach ((array) ($GLOBALS['sot_terms'] ?? []) as $t) wp_delete_term($t, 'event_category');
    if ($multi) { if ($partner_bak === null) delete_option(TIX_Partners::OPTION); else update_option(TIX_Partners::OPTION, $partner_bak, false); }
    TIX_Shared_Objects::cleanup();
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
