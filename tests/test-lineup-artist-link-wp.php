<?php
/** Act ↔ Künstler robust (1.38.736, Fund Party Schwelm). wp eval-file …/test-lineup-artist-link-wp.php (Einzel-Seite und Plattform) */
if (!defined('ABSPATH') || !class_exists('TIX_Artists') || !method_exists('TIX_Artists', 'resolve_line')) { echo "Tixomat ab 1.38.736 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = json_decode(wp_json_encode(rest_get_server()->response_to_data($res, false)), true); return [$res->get_status(), is_array($d) ? $d : []]; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$a = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user($a[0]->ID);
$posts = [];
$sfx = 'q' . wp_rand(100, 999);
$org = $multi ? wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => "LAL Veranstalter $sfx"]) : 0; if ($org) $posts[] = $org;
$img = function ($t) use (&$posts) { $x = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => $t, 'post_status' => 'inherit'], ABSPATH . 'wp-admin/images/w-logo-blue.png'); $posts[] = $x; return $x; };
$mk = function ($title, $slug = '', $image = true) use (&$posts, $img, $org) {
    $id = wp_insert_post(['post_type' => 'tix_artist', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug ?: sanitize_title($title)]); $posts[] = $id;
    update_post_meta($id, TIX_Artists::META, ['status' => 'public', 'managed_by' => $org ? ['type' => 'organizer', 'id' => $org] : ['type' => 'site', 'id' => 0], 'ids' => ['spotify' => '', 'apple' => ''], 'links' => [], 'tours' => [],
        'images' => $image ? ['portrait' => ['id' => $img($title), 'credit' => '']] : []]);
    update_post_meta($id, TIX_Artists::META_NORM, TIX_Artists::norm($title));
    TIX_Artists::flush_cache();
    return $id;
};
try {
    $mau_noimg = $mk("Maurice Die Partykanone $sfx", "maurice-die-partykanone-$sfx-alt", false);
    $mau = $mk("Maurice Die Partykanone $sfx", "maurice-die-partykanone-$sfx");
    $micha = $mk("Micha Schue $sfx", "micha-schuh-$sfx");             // Tippfehler im Titel, Slug richtig
    $monchi = $mk("Monchi $sfx");
    $juergen = $mk("Jürgen Drews $sfx");
    $alm = $mk("Almklausi $sfx");
    $lineup = "<p>Maurice Die Partykanone $sfx</p><p>Micha Schuh $sfx</p><p>DJ Monchi $sfx</p><p>Jurgen Drews $sfx</p><p>Almklauzi $sfx</p>";
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "LAL Party $sfx", 'meta_input' => ['_tix_date_start' => wp_date('Y-m-d', time() + 30 * DAY_IN_SECONDS), '_tix_info_lineup' => $lineup] + ($org ? ['_tix_organizer_id' => $org] : [])]); $posts[] = $ev;
    TIX_Artists::flush_cache();
    $r = function ($name) use ($ev) { TIX_Artists::flush_cache(); return TIX_Artists::resolve_line($ev, $name); };
    $eq('Dublette: der mit Bild gewinnt (Maurice, Name gleich)', [$mau, 'name'], [$r("Maurice Die Partykanone $sfx")['id'], $r("Maurice Die Partykanone $sfx")['via']]);
    $eq('Tippfehler im Künstler-Titel, Slug passt (Micha Schuh → „Micha Schue“)', $micha, $r("Micha Schuh $sfx")['id']);
    $eq('„DJ“ vorne, Groß/Klein egal', $monchi, $r("dj MONCHI $sfx")['id']);
    $eq('Umlaute (Jurgen → Jürgen)', $juergen, $r("Jurgen Drews $sfx")['id']);
    $t = $r("Almklauzi $sfx");
    $eq('Tippfehler im Act: nur Vorschlag, nicht automatisch', [0, $alm], [$t['id'], $t['suggestion']['id'] ?? 0]);
    // Zeilen wie im Editor
    $rows = array_column(TIX_Artists::link_lineup(TIX_Lineup_Links::for_event($ev, true), $ev), null, 'name');
    $eq('Editor: Maurice + Micha mit Bild und Künstler, Almklauzi mit Vorschlag', [$mau, 'artist', $micha, 'auto', null, $alm],
        [intval($rows["Maurice Die Partykanone $sfx"]['artist_id'] ?? 0), $rows["Maurice Die Partykanone $sfx"]['picture_source'] ?? '', intval($rows["Micha Schuh $sfx"]['artist_id'] ?? 0), $rows["Micha Schuh $sfx"]['artist_link'] ?? '',
         array_key_exists('artist_id', (array) ($rows["Almklauzi $sfx"] ?? [])) ? $rows["Almklauzi $sfx"]['artist_id'] : 'x', intval($rows["Almklauzi $sfx"]['artist_suggestion']['id'] ?? 0)]);
    // Studio: Künstler verknüpfen
    [$s, $d] = $rest('POST', "/events/$ev/lineup-artist", ['name' => "Almklauzi $sfx", 'artist_id' => $alm]);
    $rows = array_column((array) ($d['lineup_links'] ?? []), null, 'name');
    $eq('POST lineup-artist: verknüpft, Bild da', [200, $alm, 'linked', 'artist'], [$s, intval($rows["Almklauzi $sfx"]['artist_id'] ?? 0), $rows["Almklauzi $sfx"]['artist_link'] ?? '', $rows["Almklauzi $sfx"]['picture_source'] ?? '']);
    $rest('POST', "/events/$ev/lineup-artist", ['name' => "Monchi $sfx", 'artist_id' => 0]);
    TIX_Artists::flush_cache();
    $eq('artist_id 0: bewusst keiner', [0, 'none'], [$r("DJ Monchi $sfx")['id'], $r("DJ Monchi $sfx")['via']]);
    $rest('POST', "/events/$ev/lineup-artist", ['name' => "Monchi $sfx", 'artist_id' => null]);
    $eq('artist_id null: wieder automatisch', $monchi, $r("DJ Monchi $sfx")['id']);
    $eq('Fehler: artist_id fehlt / unbekannt', [400, 400], [$rest('POST', "/events/$ev/lineup-artist", ['name' => 'x'])[0], $rest('POST', "/events/$ev/lineup-artist", ['name' => "Monchi $sfx", 'artist_id' => 999999])[0]]);
    // Verknüpfung bleibt, auch wenn später eine bessere Dublette auftaucht
    $rep = TIX_Artists::repair_links($ev);
    $map = TIX_Artists::line_map($ev);
    $eq('Reparatur speichert eindeutige Zuordnungen fest', [true, $mau, $micha], [$rep['linked'] >= 3, intval($map[TIX_Artists::act_key("Maurice Die Partykanone $sfx")] ?? 0), intval($map[TIX_Artists::act_key("Micha Schuh $sfx")] ?? 0)]);
    $mau3 = $mk("Maurice Die Partykanone $sfx", "maurice-die-partykanone-$sfx-neu");
    $eq('… neue Dublette verschiebt nichts', $mau, $r("Maurice Die Partykanone $sfx")['id']);
    // öffentlich: lineup_acts mit Bild + Künstler
    if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
    wp_cache_flush();
    [, $pe] = $rest('GET', "/public/events/$ev");
    $acts = array_column((array) ($pe['event']['lineup_acts'] ?? []), null, 'line');
    $eq('Öffentlich: Maurice und Micha mit Bild', [true, true], [!empty($acts["Maurice Die Partykanone $sfx"]['image']), !empty($acts["Micha Schuh $sfx"]['image'])]);
} finally {
    foreach (array_reverse($posts) as $p) wp_delete_post($p, true);
    TIX_Artists::flush_cache();
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
