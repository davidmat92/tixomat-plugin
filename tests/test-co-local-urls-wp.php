<?php
/**
 * Co-Kopien auf Kunden-Seiten: Event-Links immer zur eigenen Kopie (1.38.784, Fund KMT: „Auge“/share_url führten auf kitchenklub.de).
 *   wp eval-file …/test-co-local-urls-wp.php
 */
if (!defined('ABSPATH') || !method_exists('TIX_Site_Move', 'local_urls_back')) { echo "Tixomat ab 1.38.784 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$id = 0;
try {
    $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Lokale Kopie', 'post_name' => 'lokale-kopie-' . wp_rand(100, 999)]);
    update_post_meta($id, '_tix_platform_co', '6453'); update_post_meta($id, '_tix_platform_co_url', 'https://evendis.de/events/x/');
    $local = TIX_Site_Move::local_event_url($id);
    $resp = ['event' => ['id' => 6453, 'title' => 'X', 'share_url' => 'https://kitchenklub.de/events/x/?utm_source=veranstalter-link', 'url' => 'https://kitchenklub.de/events/x/',
        'image' => 'https://kitchenklub.de/wp-content/uploads/a.jpg', 'terms_url' => 'https://kitchenklub.de/agb/',
        'lineup_acts' => [['name' => 'Honk', 'artist_source_url' => 'https://kitchenklub.de/kuenstler/honk/', 'url' => 'https://kitchenklub.de/kuenstler/honk/']]],
        'events' => [['id' => 6453, 'share_url' => 'https://kitchenklub.de/events/x/']]];
    $out = TIX_Site_Move::local_urls_back($resp, $id);
    $eq('share_url und url des Events → eigene Kopie', [add_query_arg(['utm_source' => 'veranstalter-link'], $local), $local, add_query_arg(['utm_source' => 'veranstalter-link'], $local)], [$out['event']['share_url'], $out['event']['url'], $out['events'][0]['share_url']]);
    $eq('Bilder, AGB, Künstlerseiten bleiben', ['https://kitchenklub.de/wp-content/uploads/a.jpg', 'https://kitchenklub.de/agb/', 'https://kitchenklub.de/kuenstler/honk/', 'https://kitchenklub.de/kuenstler/honk/'],
        [$out['event']['image'], $out['event']['terms_url'], $out['event']['lineup_acts'][0]['artist_source_url'], $out['event']['lineup_acts'][0]['url']]);
    $eq('eigene Adressen unverändert', $local, TIX_Site_Move::local_urls_back(['url' => $local], $id)['url']);
    $eq('Entwurf: kein share_url', '', (function () use ($id) { wp_update_post(['ID' => $id, 'post_status' => 'draft']); $r = TIX_Site_Move::local_event_url($id, true); wp_update_post(['ID' => $id, 'post_status' => 'publish']); return $r; })());
    if (!(class_exists('TIX_App_Scope') && TIX_App_Scope::multi())) {
        $adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);
        $d = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/platform-co/events'))->get_data();
        $it = array_values(array_filter((array) ($d['items'] ?? []), function ($x) use ($id) { return intval($x['id']) === $id; }))[0] ?? [];
        $eq('Liste /platform-co/events: url eigene Kopie, platform_url evendis, share_url mit Herkunft', [$local, 'https://evendis.de/events/x/', true], [$it['url'] ?? null, $it['platform_url'] ?? null, strpos((string) ($it['share_url'] ?? ''), 'utm_source=veranstalter-link') !== false]);
        wp_set_current_user(0);
    }
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    if ($id) wp_delete_post($id, true);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
