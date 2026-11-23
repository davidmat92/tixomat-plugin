<?php
/**
 * Act-Enthüllung mit automatischem Platzhalter (1.38.907, Auftrag uebergaben/auftrag-act-enthuellung.md).
 * Beweist: vor dem Zeitpunkt verlassen Name, Foto, Künstler den Server nicht (API, Meta, Optionen/Transients, Spiegel, Verteilung).
 *   wp eval-file …/test-lineup-reveal-wp.php   (Einzel-Seite und Plattform)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Lineup_Reveal')) { echo "Tixomat ab 1.38.907 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = json_decode(wp_json_encode(rest_get_server()->response_to_data($res, false)), true); return [$res->get_status(), is_array($d) ? $d : [], wp_json_encode($d)]; };
$R = 'TIX_Lineup_Reveal';
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$bak = ['tix_demo_instance' => get_option('tix_demo_instance', null)];
update_option('tix_demo_instance', '0', false);
$adm = get_users(['role' => 'administrator', 'number' => 1]); $admin = $adm[0]->ID; wp_set_current_user($admin);
$posts = []; $users = [];
$sfx = 'rv' . wp_rand(1000, 9999);
$SECRET = "Geheimer Stargast $sfx";
$tz = new DateTimeZone('Europe/Berlin');
$ts = function ($s) use ($tz) { return (new DateTimeImmutable($s, $tz))->getTimestamp(); };

// ═══ Reiner Kern ═══
$eq('Platzhalter-Text: Datum, Uhrzeit voll/halb, Hinweis', ['Neuer Act am 1. Dezember', 'Neuer Act am 1. Dezember um 18 Uhr', 'Neuer Act am 24. Dezember um 18:30 Uhr', 'Wird am 01.12. um 18 Uhr enthüllt'],
    [$R::date_label($ts('2026-12-01 00:00')), $R::date_label($ts('2026-12-01 18:00')), $R::date_label($ts('2026-12-24 18:30')), $R::hint($ts('2026-12-01 18:00'))]);
$R::$now = $ts('2026-11-23 12:00');
$eq('Label: eigener Text schlägt Datum; fällig ohne Namen → „Bald enthüllt“', ['Überraschungsgast', 'Bald enthüllt', 'Neuer Act am 1. Dezember um 18 Uhr'],
    [$R::label(['placeholder_text' => 'Überraschungsgast', 'reveal_at' => '2026-12-01T18:00']), $R::label(['reveal_at' => '2026-11-01T18:00']), $R::label(['reveal_at' => '2026-12-01T18:00'])]);
$eq('Zeitpunkt lesen: ISO mit Offset, „Y-m-d H:i“ Berlin, Datum allein, Unsinn', [$ts('2026-12-01 18:00'), $ts('2026-12-01 18:00'), $ts('2026-12-01 00:00'), null],
    [$R::parse_at('2026-12-01T17:00:00Z'), $R::parse_at('2026-12-01 18:00'), $R::parse_at('2026-12-01'), $R::parse_at('morgen')]);
$eq('Schlüssel bleibt Schlüssel in der Reihenfolge', $R::key('ab12cd34ef'), TIX_Lineup_Links::act_key_of($R::key('ab12cd34ef')));
$R::$now = null;

try {
    // ═══ Aufbau: Event mit zwei Acts, Veranstalter, Follower, Künstler ═══
    $org = $multi ? wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => "RV Veranstalter $sfx"]) : 0; if ($org) $posts[] = $org;
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "RV Party $sfx", 'post_name' => "rv-party-$sfx",
        'meta_input' => ['_tix_date_start' => wp_date('Y-m-d', time() + 40 * DAY_IN_SECONDS), '_tix_info_lineup' => "<p>Act Eins $sfx</p>\n<p>Act Zwei $sfx</p>"] + ($org ? ['_tix_organizer_id' => $org] : [])]);
    $posts[] = $ev;
    $art = 0;
    if (class_exists('TIX_Artists') && post_type_exists('tix_artist')) {
        $art = wp_insert_post(['post_type' => 'tix_artist', 'post_status' => 'publish', 'post_title' => $SECRET, 'post_name' => "geheimer-stargast-$sfx"]); $posts[] = $art;
        update_post_meta($art, TIX_Artists::META, ['status' => 'public', 'managed_by' => $org ? ['type' => 'organizer', 'id' => $org] : ['type' => 'site', 'id' => 0], 'ids' => ['spotify' => '', 'apple' => ''], 'links' => [], 'tours' => [], 'images' => []]);
        update_post_meta($art, TIX_Artists::META_NORM, TIX_Artists::norm($SECRET));
        TIX_Artists::flush_cache();
    }
    $fol = wp_insert_user(['user_login' => "rvfan$sfx", 'user_email' => "rvfan$sfx@example.org", 'user_pass' => wp_generate_password()]); $users[] = $fol;
    $fmeta = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::META_FOLLOWING : '_tix_app_following';
    if ($org) update_user_meta($fol, $fmeta, [$org]);
    $added = 0; $cb = function () use (&$added) { $added++; }; add_action('tix_artist_event_added', $cb);

    // ═══ Studio speichert die Liste mit einem geheimen Act (Headliner, Mitteilung, Künstler) ═══
    $at = (new DateTimeImmutable('+2 days', $tz))->setTime(18, 0);
    [$s, $d] = $rest('POST', "/events/$ev", ['lineup_list' => [['name' => "Act Eins $sfx"], ['name' => $SECRET, 'reveal_at' => $at->format('c'), 'notify' => true, 'headliner' => true, 'countdown' => true] + ($art ? ['artist_id' => $art] : []), ['name' => "Act Zwei $sfx"]]]);
    $ed = ($d['event'] ?? $d);
    $li = array_values(array_filter((array) ($ed['lineup_list'] ?? []), function ($x) { return !empty($x['placeholder']); }));
    $eq('POST lineup_list: 200; Editor sieht den echten Namen, reveal_id, reveal_at, notify, Platz 2', [200, 1, $SECRET, true, true, 2],
        [$s, count($li), $li[0]['name'] ?? '', !empty($li[0]['reveal_id']), !empty($li[0]['notify']), intval($li[0]['position'] ?? 0)]);
    $rid = (string) ($li[0]['reveal_id'] ?? '');

    // ═══ Geheimhaltung vor dem Zeitpunkt ═══
    wp_set_current_user(0);
    [$s1, $d1, $j1] = $rest('GET', "/public/events/$ev"); $d1 = $d1['event'] ?? $d1;
    [$s2, , $j2] = $rest('GET', '/public/events', ['per_page' => 100]);
    [, , $j3] = $rest('GET', "/public/events/$ev/artists");
    $ph = array_values(array_filter((array) ($d1['lineup_acts'] ?? []), function ($a) { return !empty($a['placeholder']); }));
    $eq('Öffentliches Detail: Name nirgends (auch nicht Künstler, lineup, lineup_links, artists, seo)', [200, false, false], [$s1, stripos($j1, 'Geheimer Stargast') !== false, stripos($j1, "geheimer-stargast-$sfx") !== false]);
    $eq('Liste und /artists: Name nirgends', [false, false], [stripos((string) $j2, 'Geheimer Stargast') !== false, stripos((string) $j3, 'Geheimer Stargast') !== false]);
    $eq('Platzhalter an seinem Platz: Headliner (öffentlich vorne), Text mit Datum, reveal_at, Countdown, ohne Bild/Künstler', [1, true, 'Neuer Act am ' . intval($at->format('j')) . '. ' . $R::MONTHS[intval($at->format('n'))] . ' um 18 Uhr', $at->format('c'), true, null, null, 1],
        [count($ph), !empty($ph[0]['headliner']), $ph[0]['name'] ?? '', $ph[0]['reveal_at'] ?? '', !empty($ph[0]['countdown']), array_key_exists('image', $ph[0] ?? []) ? $ph[0]['image'] : 'x', array_key_exists('artist_id', $ph[0] ?? []) ? $ph[0]['artist_id'] : 'x', intval($ph[0]['position'] ?? 0)]);
    $eq('Reihenfolge der übrigen bleibt', ["Act Eins $sfx", "Act Zwei $sfx"], array_values(array_map(function ($a) { return $a['name']; }, array_filter((array) $d1['lineup_acts'], function ($a) { return empty($a['placeholder']); }))));
    $keys = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_value LIKE %s", $ev, '%' . $wpdb->esc_like('Geheimer Stargast') . '%'));
    $eq('Speicher: Name steht am Event NUR in der privaten Meta (nicht in Line-up-Text, Künstlern, Programm, Schnappschüssen)', [$R::META], $keys);
    $eq('Speicher: kein Transient/keine Option mit dem Namen', 0, intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like($SECRET) . '%'))));
    $eq('Künstler noch nicht zugeordnet, kein Künstler-Follow ausgelöst', [false, 0], [$art ? in_array($art, array_map('intval', array_column(TIX_Artists::event_list($ev), 'artist_id')), true) : false, $added]);
    $eq('Spiegel/Export: private Meta gilt als sensibel', true, !class_exists('TIX_Mirror_Map') || TIX_Mirror_Map::is_sensitive($R::META));
    // Verteilung: Nutzlast abfangen
    $cap = []; $hk = function ($pre, $args, $url) use (&$cap) { if (isset($args['body'])) $cap[] = is_string($args['body']) ? $args['body'] : wp_json_encode($args['body']); return ['headers' => [], 'body' => '{"ok":true}', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null]; };
    add_filter('pre_http_request', $hk, PHP_INT_MAX, 3);
    if (class_exists('TIX_Syndication_Push')) TIX_Syndication_Push::push_event($ev);
    remove_filter('pre_http_request', $hk, PHP_INT_MAX);
    $all = implode("\n", $cap);
    $eq('Verteilung an Partner: Name nicht in der Nutzlast' . ($cap ? '' : ' (nicht eingerichtet: Quelltext-Prüfung)'), false,
        $cap ? stripos($all, 'Geheimer Stargast') !== false : strpos((string) file_get_contents(TIXOMAT_PATH . 'includes/class-tix-syndication-push.php'), "'_tix_lineup_private'") === false);
    $acts_pub = wp_json_encode(TIX_Lineup_Links::acts($ev));
    $eq('Schnappschuss für Kopien (acts öffentlich): nur Platzhalter', [false, true], [stripos($acts_pub, 'Geheimer Stargast') !== false, strpos($acts_pub, '"placeholder":true') !== false]);
    [$sx] = $rest('GET', "/events/$ev/lineup-reveals");
    $eq('Studio-Route ohne Anmeldung → 401', 401, $sx);
    wp_set_current_user($admin);

    // ═══ Cron vor dem Zeitpunkt: nichts ═══
    $R::cron();
    $eq('Cron vor dem Zeitpunkt: bleibt privat', [1, false], [count($R::slots($ev)), stripos((string) get_post_meta($ev, '_tix_info_lineup', true), 'Geheimer Stargast') !== false]);

    // ═══ Alter Client speichert die Liste ohne den geheimen Act: Slot bleibt mit Platz ═══
    $rest('POST', "/events/$ev", ['lineup_list' => [['name' => "Act Eins $sfx"], ['name' => "Act Zwei $sfx"]]]);
    $ord = array_values((array) get_post_meta($ev, TIX_Lineup_Links::META_ORDER, true));
    $eq('Alter Client: Slot bleibt, Platz 2 in der Reihenfolge', [1, 1], [count($R::slots($ev)), array_search($R::key($rid), $ord, true)]);

    // ═══ Act ohne Namen, schon fällig ═══
    [$s, $d] = $rest('POST', "/events/$ev/lineup-reveals", ['reveal_at' => wp_date('c', time() - 3600), 'placeholder_text' => '']);
    $nn = (string) ($d['reveal']['id'] ?? '');
    $eq('Act ohne Namen angelegt, fällig → bleibt Platzhalter „Bald enthüllt“', [200, true, 'Bald enthüllt', false], [$s, !empty($d['reveal']['waiting_for_name']), $d['reveal']['label'] ?? '', !empty($d['revealed'])]);
    $R::cron();
    wp_set_current_user(0);
    [, $dp] = $rest('GET', "/public/events/$ev"); $dp = $dp['event'] ?? $dp;
    wp_set_current_user($admin);
    $eq('Öffentlich: zwei Platzhalter, nie ein leerer Act', [2, true], [count(array_filter((array) $dp['lineup_acts'], function ($a) { return !empty($a['placeholder']); })), !in_array('', array_map(function ($a) { return trim((string) $a['name']); }, (array) $dp['lineup_acts']), true)]);
    [$s] = $rest('POST', "/events/$ev/lineup-reveals/$nn/reveal");
    $eq('Jetzt enthüllen ohne Namen → 409', 409, $s);
    [$s, $d] = $rest('POST', "/events/$ev/lineup-reveals", ['name' => 'x', 'reveal_at' => 'morgen']);
    $eq('Ungültiger Zeitpunkt → 400 reveal.reveal_at', [400, 'reveal.reveal_at'], [$s, $d['data']['field'] ?? '']);
    // Programm-Slot mit reveal_id
    update_post_meta($ev, '_tix_timetable', [wp_date('Y-m-d', time() + 40 * DAY_IN_SECONDS) => [['time' => '22:00', 'title' => 'Überraschung', 'reveal_id' => $nn]]]);
    [$s, $d] = $rest('POST', "/events/$ev/lineup-reveals/$nn", ['name' => "Spät Benannt $sfx"]);
    $tt = get_post_meta($ev, '_tix_timetable', true); $tt = array_values((array) $tt)[0][0] ?? [];
    $eq('Name nachgetragen (fällig) → sofort enthüllt: Line-up-Text, Programm-Titel, Slot weg', [200, true, true, "Spät Benannt $sfx", false, 1],
        [$s, !empty($d['revealed']), stripos((string) get_post_meta($ev, '_tix_info_lineup', true), "Spät Benannt $sfx") !== false, $tt['title'] ?? '', isset($tt['reveal_id']), count($R::slots($ev))]);

    // ═══ Zum Zeitpunkt: Cron enthüllt ═══
    $R::$now = $at->getTimestamp() + 60;
    $R::cron();
    $R::$now = null;
    wp_set_current_user(0);
    [, $d2, $jj] = $rest('GET', "/public/events/$ev"); $d2 = $d2['event'] ?? $d2;
    wp_set_current_user($admin);
    $act = null; foreach ((array) $d2['lineup_acts'] as $a) if (($a['name'] ?? '') === $SECRET) $act = $a;
    $ord = array_values((array) get_post_meta($ev, TIX_Lineup_Links::META_ORDER, true));
    $eq('Nach dem Zeitpunkt: Act öffentlich, Headliner, kein Platzhalter mehr, Slot weg, Reihenfolge-Schlüssel ersetzt', [true, true, 0, 0, false, true],
        [$act !== null, !empty($act['headliner']), count(array_filter((array) $d2['lineup_acts'], function ($a) { return !empty($a['placeholder']); })), count($R::slots($ev)),
         in_array($R::key($rid), $ord, true), in_array(TIX_Lineup_Links::act_key_of($SECRET), $ord, true)]);
    if ($art) $eq('Künstler jetzt zugeordnet und verknüpft, Künstler-Follow ausgelöst (nur evendis)', [true, $art, $multi], [in_array($art, array_map('intval', array_column(TIX_Artists::event_list($ev), 'artist_id')), true), intval($act['artist_id'] ?? 0), $added >= 1]);
    $items = (array) get_user_meta($fol, TIX_Notifications::META_USER, true);
    $hit = array_values(array_filter($items, function ($i) use ($SECRET) { return ($i['body'] ?? '') === 'Neu im Line-up: ' . $SECRET; }));
    $eq('Mitteilung „Neu im Line-up: …“ an Follower des Veranstalters' . ($org ? '' : ' (Einzel-Seite ohne Veranstalter: keine)'), $org ? [1, 'event:' . $ev] : [0, null], [count($hit), $hit[0]['action'] ?? null]);
    $R::cron();
    $eq('Zweiter Cron-Lauf: nichts doppelt', $org ? 1 : 0, count(array_filter((array) get_user_meta($fol, TIX_Notifications::META_USER, true), function ($i) use ($SECRET) { return ($i['body'] ?? '') === 'Neu im Line-up: ' . $SECRET; })));

    // ═══ 1.38.914: ohne Datum („Kommt noch“) – nie automatisch ═══
    $OPEN = "Offener Geheimgast $sfx";
    $kx = function ($arr, $k) { return array_key_exists($k, (array) $arr) ? $arr[$k] : 'fehlt'; };
    $eq('Kern: ohne Datum offen, nie fällig, Text „Kommt noch“ bzw. eigener Text', [true, false, 'Kommt noch', 'Überraschungsgast'],
        [$R::is_open(['reveal_at' => '']), $R::due(['reveal_at' => ''], PHP_INT_MAX), $R::label(['reveal_at' => '']), $R::label(['reveal_at' => '', 'placeholder_text' => 'Überraschungsgast'])]);
    [$s, $d] = $rest('POST', "/events/$ev/lineup-reveals", ['name' => $OPEN, 'countdown' => true, 'notify' => true]);
    $orid = (string) ($d['reveal']['id'] ?? '');
    $eq('Anlegen ohne reveal_at → 200, open, reveal_at null, „Kommt noch“, kein Hinweis', [200, true, null, 'Kommt noch', null, false],
        [$s, !empty($d['reveal']['open']), $kx($d['reveal'] ?? [], 'reveal_at'), $d['reveal']['label'] ?? '', $kx($d['reveal'] ?? [], 'hint'), !empty($d['revealed'])]);
    $R::$now = time() + 5 * YEAR_IN_SECONDS; $R::cron(); $R::$now = null;
    wp_set_current_user(0);
    [, $dq, $jq] = $rest('GET', "/public/events/$ev"); $dq = $dq['event'] ?? $dq;
    wp_set_current_user($admin);
    $po = array_values(array_filter((array) $dq['lineup_acts'], function ($a) { return !empty($a['reveal_open']); }));
    $eq('Cron (auch Jahre später): nicht enthüllt; öffentlich „Kommt noch“ ohne reveal_at, Hinweis, Countdown; Name nirgends', [1, 'Kommt noch', null, null, false, false],
        [count($po), $po[0]['name'] ?? '', array_key_exists('reveal_at', $po[0] ?? []) ? $po[0]['reveal_at'] : 'x', array_key_exists('reveal_hint', $po[0] ?? []) ? $po[0]['reveal_hint'] : 'x', !empty($po[0]['countdown']), stripos($jq, 'Offener Geheimgast') !== false]);
    [$s, $d] = $rest('POST', "/events/$ev/lineup-reveals/$orid", ['placeholder_text' => 'Überraschungsgast']);
    $eq('Eigener Text statt „Kommt noch“', 'Überraschungsgast', $d['reveal']['label'] ?? '');
    $later = (new DateTimeImmutable('+5 days', $tz))->setTime(20, 0);
    [$s, $d] = $rest('POST', "/events/$ev/lineup-reveals/$orid", ['reveal_at' => $later->format('c'), 'placeholder_text' => '']);
    $eq('Datum später setzen: nicht mehr offen, Datumstext', [false, $later->format('c'), true], [!empty($d['reveal']['open']), $d['reveal']['reveal_at'] ?? '', strpos((string) ($d['reveal']['label'] ?? ''), 'Neuer Act am') === 0]);
    [$s, $d] = $rest('POST', "/events/$ev/lineup-reveals/$orid", ['reveal_open' => true]);
    $eq('reveal_open: true → wieder ohne Datum', [true, null], [!empty($d['reveal']['open']), $kx($d['reveal'] ?? [], 'reveal_at')]);
    [$s, $d] = $rest('POST', "/events/$ev/lineup-reveals/$orid/reveal");
    $eq('Von Hand enthüllen (…/reveal): Name im Line-up, Slot weg', [200, true, 0], [$s, stripos((string) get_post_meta($ev, '_tix_info_lineup', true), $OPEN) !== false, count($R::slots($ev))]);
    // über die Liste: anlegen ohne Datum, dann „Sofort zeigen“
    $base = array_map(function ($x) { return ['name' => $x['name']]; }, array_values(array_filter((array) (($rest('GET', "/events/$ev/edit")[1]['event'] ?? [])['lineup_list'] ?? []), function ($x) { return empty($x['placeholder']); })));
    [$s, $d] = $rest('POST', "/events/$ev", ['lineup_list' => array_merge($base, [['name' => "Liste Offen $sfx", 'reveal_open' => true]])]);
    $lo = array_values(array_filter((array) (($d['event'] ?? $d)['lineup_list'] ?? []), function ($x) { return !empty($x['placeholder']); }));
    $eq('lineup_list mit reveal_open: Slot ohne Datum, reveal_open true im Editor', [200, 1, true, null], [$s, count($lo), !empty($lo[0]['reveal_open']), $kx($lo[0] ?? [], 'reveal_at')]);
    [$s] = $rest('POST', "/events/$ev", ['lineup_list' => array_merge($base, [['name' => "Liste Offen $sfx", 'reveal_id' => $lo[0]['reveal_id'] ?? '', 'reveal_at' => null]])]);
    $eq('„Sofort zeigen“ (reveal_id + reveal_at null): normaler Act', [200, 0, true], [$s, count($R::slots($ev)), stripos((string) get_post_meta($ev, '_tix_info_lineup', true), "Liste Offen $sfx") !== false]);

    // ═══ Abgleich und Löschen ═══
    $rest('POST', "/events/$ev/lineup-reveals", ['reveal_at' => wp_date('c', time() + 9 * DAY_IN_SECONDS), 'name' => "Weg Damit $sfx"]);
    $rest('POST', "/events/$ev/lineup-reveals", ['reveal_at' => wp_date('c', time() + 9 * DAY_IN_SECONDS), 'name' => "Auch Weg $sfx"]);
    $sl = $R::slots($ev);
    [$s] = $rest('DELETE', "/events/$ev/lineup-reveals/" . $sl[0]['id']);
    $eq('DELETE: Slot weg, Schlüssel aus der Reihenfolge', [200, 1, false], [$s, count($R::slots($ev)), in_array($R::key($sl[0]['id']), array_values((array) get_post_meta($ev, TIX_Lineup_Links::META_ORDER, true)), true)]);
    $rest('POST', "/events/$ev", ['lineup_list' => [['name' => "Act Eins $sfx"]], 'lineup_reveals_sync' => true]);
    $eq('lineup_reveals_sync: nicht genannte Slots werden gelöscht', 0, count($R::slots($ev)));
    $eq('Gelöschte geheime Namen bleiben nirgends öffentlich', false, stripos((string) get_post_meta($ev, '_tix_info_lineup', true), 'Weg') !== false);
} finally {
    remove_action('tix_artist_event_added', $cb ?? '__return_null');
    $R::$now = null;
    wp_set_current_user(0);
    foreach ($posts as $p) wp_delete_post($p, true);
    if ($users) { require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ($users as $u) wp_delete_user($u); }
    foreach ($bak as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
    if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
