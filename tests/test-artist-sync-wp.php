<?php
/**
 * Künstlerdaten überall gleich (1.38.795): Kennungen im Schnappschuss, Profil-Link für Hörproben, Nachziehen nach Änderungen.
 *   wp eval-file …/test-artist-sync-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Artists') || !method_exists('TIX_Artists', 'propagate')) { echo "Tixomat ab 1.38.795 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); return [$res->get_status(), $res->get_data()]; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$p = []; $pushed = [];
$keep_synd = get_option('tix_settings');
add_filter('pre_http_request', function ($pre, $args, $url) use (&$pushed) {
    if ($pre !== false || strpos($url, '/syndicate') === false) return $pre;
    $pushed[] = json_decode((string) $args['body'], true);
    return ['headers' => [], 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null, 'body' => wp_json_encode(['ok' => true, 'id' => 4711])];
}, 10, 3);
try {
    $adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);
    $name = 'Sync Testkünstler ' . wp_rand(100, 999);
    $aid = wp_insert_post(['post_type' => 'tix_artist', 'post_status' => 'publish', 'post_title' => $name]); $p[] = $aid;
    $org = 0;
    if ($multi) { $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'Sync-Org']); $p[] = $org; }
    update_post_meta($aid, TIX_Artists::META, ['kind' => 'solo', 'status' => 'public', 'links' => [], 'ids' => [], 'managed_by' => $multi ? ['type' => 'organizer', 'id' => $org] : ['type' => 'site', 'id' => 0]]);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Sync-Event']); $p[] = $ev;
    update_post_meta($ev, '_tix_date_start', gmdate('Y-m-d', time() + 20 * DAY_IN_SECONDS));
    update_post_meta($ev, '_tix_info_lineup', $name);
    if ($multi) update_post_meta($ev, '_tix_organizer_id', $org);
    TIX_Artists::apply($ev, [['artist_id' => $aid, 'role' => 'headliner']]);
    wp_clear_scheduled_hook(TIX_Artists::PROPAGATE_HOOK, [$aid]);

    // 1) Profil ändern → Kennung, Nachziehen geplant
    [$st, $d] = $rest('POST', "/artists/$aid", ['links' => ['apple_music' => 'https://music.apple.com/de/artist/dj-d%C3%BCse/286426688']]);
    $eq('Profil: Apple-Link mit Umlaut-Kodierung gespeichert, ids.apple abgeleitet', [200, '286426688'], [$st, $d['artist']['ids']['apple'] ?? null]);
    $eq('Änderung plant das Nachziehen (Spiegel/Co-Kopien)', true, (bool) wp_next_scheduled(TIX_Artists::PROPAGATE_HOOK, [$aid]));

    // 2) Schnappschuss trägt Kennungen + Links
    $snap = TIX_Artists::snapshot($ev);
    $eq('Schnappschuss: ids.apple und links.apple_music', ['286426688', true], [$snap[0]['ids']['apple'] ?? null, strpos((string) ($snap[0]['links']['apple_music'] ?? ''), '286426688') !== false]);

    // 3) Hörproben-Zeilen: Profil-Link statt Namenssuche; manuell bleibt manuell
    $rows = TIX_Artists::rows_with_artist_links(TIX_Lineup_Links::for_event($ev), $ev);
    $eq('Line-up-Zeile (auto) nimmt den Apple-Link des Künstlerprofils', true, strpos((string) ($rows[0]['apple_music'] ?? ''), '286426688') !== false);
    $k = TIX_Lineup_Links::norm($name);
    update_post_meta($ev, TIX_Lineup_Links::META, [$k => ['source' => 'none']]);
    $rows = TIX_Artists::rows_with_artist_links(TIX_Lineup_Links::for_event($ev), $ev);
    $eq('„kein Link“ im Event bleibt ohne Link', '', (string) ($rows[0]['apple_music'] ?? ''));
    delete_post_meta($ev, TIX_Lineup_Links::META);

    // 4) Kopie: Schnappschuss mit Kennungen, Weitergabe und Hörproben-Link
    $cp = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Sync-Kopie']); $p[] = $cp;
    update_post_meta($cp, $multi ? '_tix_syndicated' : '_tix_platform_co', $multi ? '1' : '6453');
    update_post_meta($cp, '_tix_info_lineup', $name);
    TIX_Artists::store_snapshot($cp, $snap);
    $re = TIX_Artists::snapshot($cp);
    $eq('Kopie speichert Kennungen und gibt sie weiter (Spiegel → Kunden-Seite)', ['286426688', '286426688'], [get_post_meta($cp, TIX_Artists::EV_SNAP, true)[0]['ids']['apple'] ?? null, $re[0]['ids']['apple'] ?? null]);
    $rows = TIX_Artists::rows_with_artist_links(TIX_Lineup_Links::for_event($cp), $cp);
    $eq('Kopie: Hörproben-Zeile nimmt den Apple-Link aus dem Schnappschuss', true, strpos((string) ($rows[0]['apple_music'] ?? ''), '286426688') !== false);
    $bad = $snap; $bad[0]['ids'] = ['apple' => '12ab<script>', 'spotify' => 'x'];
    TIX_Artists::store_snapshot($cp, $bad);
    $sids = (array) (get_post_meta($cp, TIX_Artists::EV_SNAP, true)[0]['ids'] ?? []);
    $eq('ungültige Kennungen werden verworfen', [true, null, null], [array_key_exists('apple', $sids), $sids['apple'] ?? null, $sids['spotify'] ?? null]);

    // 5) Nachziehen: verteilte Events werden neu gesendet, mit Kennung
    update_post_meta($ev, '_tix_syndicate', '1');
    $s = (array) get_option('tix_settings', []); $s['syndication_api_url'] = 'https://plattform.test/wp-json/tixomat/v1'; $s['syndication_api_key'] = str_repeat('s', 32); $s['syndication_enabled'] = 1; update_option('tix_settings', $s);
    $pushed = [];
    $cnt = TIX_Artists::propagate($aid);
    $last = end($pushed) ?: [];
    if (class_exists('TIX_Syndication_Push') && TIX_Syndication_Push::is_configured()) {
        $eq('propagate: Event neu verteilt, Künstler mit ids.apple im Paket', [1, '286426688'], [$cnt, $last['artists'][0]['ids']['apple'] ?? null]);
    } else {
        $eq('propagate: Event gezählt (Verteilung hier nicht eingerichtet)', 1, $cnt);
    }
    $eq('propagate: Kopien werden übersprungen, vergangene Events nicht', 1, TIX_Artists::propagate($aid));

    // 6) Spiegel-Künstler (fremde Seite) stoßen nichts an
    if (class_exists('TIX_Shared_Objects')) {
        $mir = wp_insert_post(['post_type' => 'tix_artist', 'post_status' => 'publish', 'post_title' => 'Spiegel-Künstler']); $p[] = $mir;
        update_post_meta($mir, TIX_Shared_Objects::META . '_key', 'quelle.test|5');
        if (TIX_Shared_Objects::mirror($mir)) {
            TIX_Artists::queue_propagate($mir);
            $eq('Spiegel-Künstler: kein Nachziehen', false, (bool) wp_next_scheduled(TIX_Artists::PROPAGATE_HOOK, [$mir]));
        }
    }
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    remove_all_filters('pre_http_request');
    update_option('tix_settings', $keep_synd);
    foreach ($p as $id) { wp_clear_scheduled_hook(TIX_Artists::PROPAGATE_HOOK, [intval($id)]); wp_delete_post($id, true); }
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
