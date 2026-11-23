<?php
/**
 * Listenmodus: lineup_list ist allein führend (1.38.908, Fund xxl-schlagerparty.evendis.de Event 7 – alte Acts blieben online).
 *   wp eval-file …/test-lineup-prune-wp.php   (Einzel-Seite und Plattform)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Artists') || !method_exists('TIX_Artists', 'prune_unlisted')) { echo "Tixomat ab 1.38.908 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = json_decode(wp_json_encode(rest_get_server()->response_to_data($res, false)), true); return [$res->get_status(), is_array($d) ? $d : []]; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$bak = get_option('tix_demo_instance', null); update_option('tix_demo_instance', '0', false);
$a = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user($a[0]->ID);
$posts = []; $sfx = 'pr' . wp_rand(1000, 9999);
$org = $multi ? wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => "PR Veranstalter $sfx"]) : 0; if ($org) $posts[] = $org;
$uorg = get_user_meta($a[0]->ID, '_tix_organizer_id', true); if ($org) update_user_meta($a[0]->ID, '_tix_organizer_id', $org);
$mk = function ($title) use (&$posts, $org) {
    $id = wp_insert_post(['post_type' => 'tix_artist', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => sanitize_title($title)]); $posts[] = $id;
    update_post_meta($id, TIX_Artists::META, ['status' => 'public', 'managed_by' => $org ? ['type' => 'organizer', 'id' => $org] : ['type' => 'site', 'id' => 0], 'ids' => ['spotify' => '', 'apple' => ''], 'links' => [], 'tours' => [], 'images' => []]);
    update_post_meta($id, TIX_Artists::META_NORM, TIX_Artists::norm($title));
    TIX_Artists::flush_cache();
    return $id;
};
$ids = function ($ev) { return array_map('intval', array_column(TIX_Artists::event_list($ev), 'artist_id')); };
$pub = function ($ev) use ($rest) { delete_transient('tix_pub_events_d' . $ev); wp_cache_delete('_transient_tix_pub_events_d' . $ev, 'options'); $u = get_current_user_id(); wp_set_current_user(0); [, $d] = $rest('GET', "/public/events/$ev"); wp_set_current_user($u); return $d['event'] ?? $d; };
$added = []; $cb = function ($aid) use (&$added) { $added[] = intval(is_array($aid) ? ($aid['artist_id'] ?? 0) : $aid); }; add_action('tix_artist_event_added', $cb, 10, 1);
try {
    $A = $mk("Alt Eins $sfx"); $B = $mk("Alt Zwei $sfx"); $C = $mk("Alt Drei $sfx"); $D = $mk("Neu Star $sfx");
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "PR Party $sfx", 'meta_input' => ['_tix_date_start' => wp_date('Y-m-d', time() + 30 * DAY_IN_SECONDS)] + ($org ? ['_tix_organizer_id' => $org] : [])]); $posts[] = $ev;
    [$s] = $rest('POST', "/events/$ev", ['lineup_list' => [['name' => "Alt Eins $sfx", 'artist_id' => $A], ['name' => "Alt Zwei $sfx", 'artist_id' => $B], ['name' => "Alt Drei $sfx", 'artist_id' => $C]]]);
    $eq('Ausgang: drei Acts mit Künstlern', [200, [$A, $B, $C]], [$s, $ids($ev)]);
    // ── Wie das Studio bis heute: neue Liste + versteckte alte artists ──
    $added = [];
    [$s] = $rest('POST', "/events/$ev", ['lineup_list' => [['name' => "Neu Star $sfx", 'artist_id' => $D], ['name' => "Gast Ohne $sfx"]],
        'artists' => [['artist_id' => $A, 'role' => 'support'], ['artist_id' => $B, 'role' => 'support'], ['artist_id' => $C, 'role' => 'support']]]);
    $map = TIX_Artists::line_map($ev);
    $eq('Liste führend: nur D zugeordnet, alte Verknüpfungen weg, `artists` ignoriert', [200, [$D], false, false],
        [$s, $ids($ev), isset($map[TIX_Artists::act_key("Alt Eins $sfx")]), (bool) array_intersect([$A, $B, $C], array_map('intval', array_values($map)))]);
    $p = $pub($ev);
    $eq('Öffentlich: lineup_acts und artists nur neue Acts', [["Neu Star $sfx", "Gast Ohne $sfx"], [$D]], [array_column((array) ($p['lineup_acts'] ?? []), 'name'), array_map('intval', array_column((array) ($p['artists'] ?? []), 'id'))]);
    $eq('Kein Künstler-Follow für alte Künstler', [], array_values(array_intersect($added, [$A, $B, $C])));
    $eq('Künstlerseiten-Index (_tix_artist_ref) ohne alte', [(string) $D], array_values(array_map('strval', get_post_meta($ev, TIX_Artists::EV_REF))));
    // ── stündliche Reparatur darf nichts zurückholen ──
    TIX_Artists::repair_links($ev);
    $eq('Reparatur-Lauf (Cron) holt nichts zurück', [$D], $ids($ev));

    // ── Altbestand wie Event 7: alte Künstler + Verknüpfungen hängen noch ──
    update_post_meta($ev, TIX_Artists::EV_META, array_merge(TIX_Artists::event_list($ev), [['artist_id' => $A, 'role' => 'support', 'tour' => null, 'by_organizer' => 0, 'at' => gmdate('c'), 'notified' => true], ['artist_id' => $B, 'role' => 'support', 'tour' => null, 'by_organizer' => 0, 'at' => gmdate('c'), 'notified' => true]]));
    add_post_meta($ev, TIX_Artists::EV_REF, (string) $A); add_post_meta($ev, TIX_Artists::EV_REF, (string) $B);
    update_post_meta($ev, TIX_Artists::EV_LINEMAP, TIX_Artists::line_map($ev) + [TIX_Artists::act_key("Alt Eins $sfx") => $A, TIX_Artists::act_key("Alt Zwei $sfx") => $B]);
    TIX_Artists::flush_cache();
    $eq('Altbestand sichtbar (vorher)', true, count((array) ($pub($ev)['lineup_acts'] ?? [])) > 2);
    $dr = TIX_Artists::prune_unlisted($ev, true);
    $eq('Aufräumen trocken: meldet 2 Künstler + 2 Verknüpfungen, ändert nichts', [2, 2, 3], [count($dr['artists_removed']), count($dr['links_removed']), count($ids($ev))]);
    $r = TIX_Artists::prune_unlisted($ev); TIX_Artists::after_prune($ev);
    $eq('Aufräumen: nur D bleibt, Index sauber, öffentlich zwei Acts', [[$D], [(string) $D], 2], [$ids($ev), array_values(array_map('strval', get_post_meta($ev, TIX_Artists::EV_REF))), count((array) ($pub($ev)['lineup_acts'] ?? []))]);
    TIX_Artists::repair_links($ev);
    $eq('… und der Reparatur-Lauf holt sie nicht zurück', [$D], $ids($ev));
    // ── Verknüpfung über Schlüssel bleibt, auch wenn der Künstler anders heißt ──
    $rest('POST', "/events/$ev", ['lineup_list' => [['name' => "Bühnenname X $sfx", 'artist_id' => $D]]]);
    $eq('Act mit anderem Namen, verknüpft: Künstler bleibt', [$D], $ids($ev));
    // ── Leeres Line-up ──
    $rest('POST', "/events/$ev", ['lineup_list' => []]);
    TIX_Artists::repair_links($ev);
    $eq('Leere Liste: keine Künstler, keine Verknüpfungen, Reparatur holt nichts', [[], []], [$ids($ev), TIX_Artists::line_map($ev)]);
    // ── Fließtext-Event: Aufräumen fasst nichts an ──
    $ev2 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "PR Fließtext $sfx", 'meta_input' => ['_tix_date_start' => wp_date('Y-m-d', time() + 30 * DAY_IN_SECONDS), '_tix_info_lineup' => '<p>' . str_repeat('Ein sehr langer Fließtext über den Abend ', 3) . '</p>', '_tix_lineup_order' => ['x']] + ($org ? ['_tix_organizer_id' => $org] : [])]); $posts[] = $ev2;
    TIX_Artists::apply($ev2, [['artist_id' => $A, 'role' => 'headliner', 'tour' => null, 'by_organizer' => 0, 'at' => gmdate('c'), 'notified' => true]]);
    $eq('Fließtext ohne Zeilen: Aufräumen lässt Künstler stehen', [[], [$A]], [TIX_Artists::prune_unlisted($ev2)['artists_removed'], $ids($ev2)]);
} finally {
    remove_action('tix_artist_event_added', $cb, 10);
    foreach ($posts as $p) wp_delete_post($p, true);
    if ($org) { if ($uorg !== '') update_user_meta($a[0]->ID, '_tix_organizer_id', $uorg); else delete_user_meta($a[0]->ID, '_tix_organizer_id'); }
    if ($bak === null) delete_option('tix_demo_instance'); else update_option('tix_demo_instance', $bak, false);
    if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
