<?php
/**
 * Künstler: verknüpfte Acts auch zuordnen, versehentlich eigene Künstler an Kopien durch Spiegel ersetzen (1.38.755).
 * Fund: KK #1171 „Andreas Köhler (DJ/Moderation)“ verknüpft, nicht zugeordnet → fehlt im Schnappschuss; KMT: 12 Künstler aus dem
 * Line-up der Kopie als „eigen“ angelegt statt geteilt.
 *   Einzel-Seite: wp eval-file …/test-artists-assign-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Artists') || !method_exists('TIX_Artists', 'assign_linked')) { echo "Tixomat ab 1.38.755 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
if (TIX_Artists::multi()) { echo "Nur Einzel-Seite.\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function aa_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
$tmp = [];
$mk_artist = function ($name, array $extra = [], array $log = []) use (&$tmp) {
    $id = wp_insert_post(['post_type' => TIX_Artists::CPT, 'post_status' => 'publish', 'post_title' => $name, 'post_name' => sanitize_title($name . '-' . wp_rand(100, 999))]);
    update_post_meta($id, TIX_Artists::META, array_merge(['kind' => 'solo', 'genres' => [], 'short_bio' => '', 'bio' => '', 'images' => [], 'links' => [], 'ids' => ['spotify' => '', 'apple' => ''],
        'tours' => [], 'status' => 'public', 'verified' => false, 'managed_by' => ['type' => 'site', 'id' => 0]], $extra));
    update_post_meta($id, TIX_Artists::META_NORM, TIX_Artists::norm($name));
    if ($log) update_post_meta($id, TIX_Artists::META_LOG, $log);
    $tmp[] = $id; return $id;
};
add_filter('tix_shared_objects_sideload', function ($r, $url, $parent) use (&$tmp) {
    $att = wp_insert_post(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'aa-bild', 'post_mime_type' => 'image/png']);
    $tmp[] = $att; return $att;
}, 10, 3);
try {
    echo "-- (A) verknüpfte Acts zuordnen (Quelle)\n";
    $ak = $mk_artist('AA Andreas Köhler'); $mo = $mk_artist('AA Monchi');
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'AA Party', 'meta_input' => ['_tix_date_start' => '2027-09-01',
        '_tix_info_lineup' => "<p>AA Andreas Köhler (DJ/Moderation)</p>\n<p>AA Monchi</p>"]]);
    $tmp[] = $ev;
    TIX_Artists::apply($ev, [['artist_id' => $mo, 'role' => 'support', 'tour' => null, 'by_organizer' => 0, 'at' => gmdate('c'), 'notified' => true]]);
    // Fund KK: Verknüpfung gespeichert, Künstler nicht zugeordnet
    update_post_meta($ev, TIX_Artists::EV_LINEMAP, [TIX_Artists::act_key('AA Andreas Köhler') => $ak, TIX_Artists::act_key('AA Monchi') => $mo]);
    TIX_Artists::flush_cache();
    aa_eq('vorher: nur Monchi zugeordnet, Andreas fehlt im Schnappschuss', [[$mo], false], [array_map('intval', array_column(TIX_Artists::event_list($ev), 'artist_id')),
        in_array('AA Andreas Köhler', array_column(TIX_Artists::snapshot($ev), 'name'), true)]);
    $r = TIX_Artists::repair_links($ev, true);
    aa_eq('Reparatur trocken: 1 zuzuordnen, nichts geschrieben', [1, 1], [intval($r['assigned'] ?? 0), count(TIX_Artists::event_list($ev))]);
    $r = TIX_Artists::repair_links($ev);
    $roles = array_column(TIX_Artists::event_list($ev), 'role', 'artist_id');
    aa_eq('Reparatur: Andreas zugeordnet als Moderation (host), Monchi bleibt support', [1, 'host', 'support'], [intval($r['assigned'] ?? 0), $roles[$ak] ?? null, $roles[$mo] ?? null]);
    TIX_Artists::flush_cache();
    aa_eq('jetzt öffentlich und im Schnappschuss', [true, true], [TIX_Artists::is_public($ak), in_array('AA Andreas Köhler', array_column(TIX_Artists::snapshot($ev), 'name'), true)]);
    // Studio „Künstler verknüpfen“ ordnet zu
    $dj = $mk_artist('AA DJ Testo');
    update_post_meta($ev, '_tix_info_lineup', "<p>AA Andreas Köhler (DJ/Moderation)</p>\n<p>AA Monchi</p>\n<p>AA DJ Testo (DJ)</p>");
    TIX_Artists::flush_cache();
    TIX_Artists::link_line($ev, 'AA DJ Testo', $dj);
    $roles = array_column(TIX_Artists::event_list($ev), 'role', 'artist_id');
    aa_eq('link_line ordnet zu (Rolle dj aus „(DJ)“)', 'dj', $roles[$dj] ?? null);

    echo "-- (C) Schreibweise angleichen (Line-up-Text, Programm)\n";
    $ms = $mk_artist('AA Micha Schue'); $mp = $mk_artist('AA Maurice Die Partykanone'); $an = $mk_artist('AA Anna Klar');
    $ev2 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'AA Schreibweise', 'meta_input' => ['_tix_date_start' => '2027-09-05',
        '_tix_info_lineup' => "<p>AA Andreas Köhler (DJ/Moderation)</p>\n<p>AA Micha Schuh</p>\n<p>AA Maurice Die Partykanone</p>\n<p>AA Ganz Anders</p>",
        '_tix_timetable' => ['2027-09-05' => [['time' => '12:30', 'title' => 'AA Andreas Köhler (DJ/Moderation)'], ['time' => '15:30', 'title' => 'AA Maurice Die Partykanona'],
            ['time' => '16:30', 'title' => 'AA Micha Schue'], ['time' => '17:00', 'title' => 'AA Anna Klaar (live)']]]]]);
    $tmp[] = $ev2;
    update_post_meta($ev2, TIX_Artists::EV_LINEMAP, [TIX_Artists::act_key('AA Andreas Köhler') => $ak, TIX_Artists::act_key('AA Micha Schue') => $ms,
        TIX_Artists::act_key('AA Maurice Die Partykanone') => $mp, TIX_Artists::act_key('AA Anna Klar') => $an]);
    TIX_Artists::flush_cache();
    $r = TIX_Artists::align_spelling($ev2, true);
    $pairs = array_map(function ($x) { return $x['where'] . ': ' . $x['from'] . ' → ' . $x['to']; }, $r['rows']);
    sort($pairs);
    aa_eq('trocken: Line-up „Micha Schuh“, Programm „Partykanona“ und „Anna Klaar (live)“ – Zusatz bleibt, fremde Namen unberührt',
        ['lineup: AA Micha Schuh → AA Micha Schue', 'programm: AA Anna Klaar (live) → AA Anna Klar (live)', 'programm: AA Maurice Die Partykanona → AA Maurice Die Partykanone'], $pairs);
    aa_eq('trocken: nichts geschrieben', true, strpos((string) get_post_meta($ev2, '_tix_info_lineup', true), 'AA Micha Schuh') !== false);
    TIX_Artists::align_spelling($ev2);
    $tt = get_post_meta($ev2, '_tix_timetable', true)['2027-09-05'];
    aa_eq('angeglichen: Text und Programm, Andreas und „Ganz Anders“ unverändert', [true, true, 'AA Maurice Die Partykanone', 'AA Anna Klar (live)', 'AA Andreas Köhler (DJ/Moderation)'],
        [strpos((string) get_post_meta($ev2, '_tix_info_lineup', true), '<p>AA Micha Schue</p>') !== false, strpos((string) get_post_meta($ev2, '_tix_info_lineup', true), '<p>AA Ganz Anders</p>') !== false,
         $tt[1]['title'], $tt[3]['title'], $tt[0]['title']]);
    aa_eq('zweiter Lauf: nichts mehr zu tun', 0, count(TIX_Artists::align_spelling($ev2, true)['rows']));

    echo "-- (B) versehentlich eigene Künstler an Kopien → Spiegel\n";
    $cp = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'AA Kopie', 'meta_input' => ['_tix_date_start' => '2027-09-02',
        '_tix_syndicated' => '1', '_tix_source_url' => 'https://quelle.test/events/aa/']]);
    $tmp[] = $cp;
    $lg = [['at' => gmdate('c'), 'by' => 2, 'action' => 'assigned', 'event' => $cp], ['at' => gmdate('c'), 'by' => 2, 'action' => 'created', 'from' => 'lineup']];
    $acc = $mk_artist('AA Honk', [], $lg);                                       // versehentlich eigen
    $keep = $mk_artist('AA Marry', ['short_bio' => 'Eigener Text'], $lg);           // eigene Angaben → bleibt
    $self = $mk_artist('AA Rick');                                                 // selbst angelegt (kein Line-up-Import) → bleibt
    foreach ([$acc, $keep, $self] as $a) add_post_meta($cp, TIX_Artists::EV_REF, (string) $a);
    aa_eq('Erkennung: Honk versehentlich, Marry (Text) und Rick (selbst angelegt) nicht', [true, false, false],
        [TIX_Shared_Objects::accidental_own($acc), TIX_Shared_Objects::accidental_own($keep), TIX_Shared_Objects::accidental_own($self)]);
    $other = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'AA eigenes Event', 'meta_input' => ['_tix_date_start' => '2027-09-03']]);
    $tmp[] = $other;
    add_post_meta($other, TIX_Artists::EV_REF, (string) $acc);
    aa_eq('auch an eigenem Event zugeordnet → nicht versehentlich', false, TIX_Shared_Objects::accidental_own($acc));
    delete_post_meta($other, TIX_Artists::EV_REF, (string) $acc);
    $snap = [['source_id' => 501, 'name' => 'AA Honk', 'image' => 'https://quelle.test/honk.png'], ['source_id' => 502, 'name' => 'AA Marry', 'image' => 'https://quelle.test/marry.png'],
        ['source_id' => 503, 'name' => 'AA Rick', 'image' => 'https://quelle.test/rick.png']];
    $ids = TIX_Shared_Objects::sync_artists($cp, $snap);
    $honk_new = array_values(array_filter($ids, function ($i) { return get_the_title($i) === 'AA Honk'; }))[0] ?? 0;
    aa_eq('Honk: eigener Eintrag gelöscht, Spiegel mit Bild der Quelle', [null, true, true],
        [get_post($acc) ? 'noch da' : null, (bool) TIX_Shared_Objects::mirror($honk_new), !empty((TIX_Shared_Objects::mirror($honk_new) ?: [])['attachment'])]);
    aa_eq('Marry und Rick bleiben eigen und verknüpft', [true, true, false, false], [in_array($keep, $ids, true), in_array($self, $ids, true), (bool) TIX_Shared_Objects::mirror($keep), (bool) TIX_Shared_Objects::mirror($self)]);
    $tmp[] = $honk_new;
} catch (Throwable $e) {
    $GLOBALS['t_fails']++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach (array_unique($tmp) as $id) if ($id && get_post($id)) wp_delete_post($id, true);
    foreach (get_posts(['post_type' => TIX_Artists::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 's' => 'AA ']) as $id) if (strpos(get_the_title($id), 'AA ') === 0) wp_delete_post($id, true);
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
