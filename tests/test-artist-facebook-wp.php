<?php
/**
 * Künstler: Facebook-Link (links.facebook, ab 1.38.922) – Speichern, Host-Prüfung, öffentliche Route, sameAs.
 *   wp eval-file …/test-artist-facebook-wp.php   (Einzel- und Mehr-Veranstalter-Seite)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Artists') || !method_exists('TIX_Artists', 'is_facebook_url')) { echo "Tixomat ab 1.38.922 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$sfx = strtolower(wp_generate_password(5, false, false));
$tmp = [];
$admin = wp_insert_user(['user_login' => "fb_admin_$sfx", 'user_pass' => wp_generate_password(20), 'user_email' => "fb_admin_$sfx@example.test", 'role' => 'administrator']);
if (is_multisite()) grant_super_admin($admin);
wp_set_current_user($admin);
$post = function ($path, array $body) {
    $r = new WP_REST_Request('POST', '/tixomat/v1' . $path);
    $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body));
    return rest_do_request($r);
};

echo "── Host-Prüfung ──\n";
$ok = ['https://www.facebook.com/kuenstler', 'https://facebook.com/pages/x/123', 'https://m.facebook.com/kuenstler', 'https://fb.com/kuenstler', 'https://fb.me/kuenstler', 'https://www.facebook.com/profile.php?id=123'];
$no = ['https://evil.com/facebook.com', 'https://facebook.com.evil.com/x', 'https://notfacebook.com/x', 'https://www.facebook.com/', 'https://fb.me', 'https://instagram.com/x'];
$eq('gültige Facebook-Links', array_fill(0, count($ok), true), array_map(['TIX_Artists', 'is_facebook_url'], $ok));
$eq('fremde Hosts bzw. ohne Pfad', array_fill(0, count($no), false), array_map(['TIX_Artists', 'is_facebook_url'], $no));

echo "── Speichern ──\n";
$res = $post('/artists', ['name' => "FB Künstler $sfx", 'kind' => 'band', 'links' => ['facebook' => 'https://www.facebook.com/fbkuenstler' . $sfx, 'instagram' => 'https://instagram.com/x' . $sfx]]);
$id = intval($res->get_data()['artist']['id'] ?? ($res->get_data()['id'] ?? 0));
if ($id) $tmp[] = $id;
$eq('POST /artists mit facebook → angelegt', true, in_array($res->get_status(), [200, 201], true) && $id > 0);
$raw = (array) get_post_meta($id, TIX_Artists::META, true);
$eq('gespeichert', 'https://www.facebook.com/fbkuenstler' . $sfx, $raw['links']['facebook'] ?? null);
$res = $post("/artists/$id", ['links' => ['facebook' => 'https://example.com/fbkuenstler']]);
$eq('falscher Host → 400 links.facebook', [400, 'links.facebook'], [$res->get_status(), $res->get_data()['data']['field'] ?? ($res->get_data()['field'] ?? null)]);
$res = $post("/artists/$id", ['links' => ['facebook' => 'http://www.facebook.com/fbkuenstler']]);
$eq('http statt https → 400 links.facebook', [400, 'links.facebook'], [$res->get_status(), $res->get_data()['data']['field'] ?? ($res->get_data()['field'] ?? null)]);
$raw = (array) get_post_meta($id, TIX_Artists::META, true);
$eq('nach Fehlern unverändert', 'https://www.facebook.com/fbkuenstler' . $sfx, $raw['links']['facebook'] ?? null);
$res = $post("/artists/$id", ['links' => ['facebook' => 'https://fb.me/neu' . $sfx]]);
$eq('fb.me gespeichert, Instagram bleibt', [200, 'https://fb.me/neu' . $sfx, 'https://instagram.com/x' . $sfx], [$res->get_status(), ((array) get_post_meta($id, TIX_Artists::META, true))['links']['facebook'] ?? null, ((array) get_post_meta($id, TIX_Artists::META, true))['links']['instagram'] ?? null]);
$p = TIX_Artists::payload($id, true);
$eq('Verwalter-payload: links.facebook', 'https://fb.me/neu' . $sfx, $p['links']['facebook'] ?? 'fehlt');

echo "── Leer = null ──\n";
$res = $post("/artists/$id", ['links' => ['facebook' => '']]);
$p = TIX_Artists::payload($id);
$eq('leer → Schlüssel da, null', [200, true, null], [$res->get_status(), array_key_exists('facebook', $p['links']), $p['links']['facebook']]);
$keys = array_keys($p['links']);
$eq('Reihenfolge: facebook nach instagram', array_search('instagram', $keys) + 1, array_search('facebook', $keys));
$post("/artists/$id", ['links' => ['facebook' => 'https://www.facebook.com/fbkuenstler' . $sfx]]);

echo "── Öffentliche Route ──\n";
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "FB Event $sfx", 'meta_input' => ['_tix_date_start' => gmdate('Y-m-d', time() + 30 * DAY_IN_SECONDS), '_tix_time_start' => '20:00']]);
$tmp[] = $ev;
TIX_Artists::apply($ev, [['artist_id' => $id, 'role' => 'headliner', 'tour' => null, 'by_organizer' => 0, 'at' => gmdate('c'), 'notified' => true]]);
if (method_exists('TIX_Artists', 'flush_cache')) TIX_Artists::flush_cache();
wp_set_current_user(0);
$slug = get_post_field('post_name', $id);
$res = rest_do_request(new WP_REST_Request('GET', "/tixomat/v1/public/artists/$slug"));
if ($res->get_status() === 200) {
    $d = $res->get_data();
    $eq('GET /public/artists/{slug}: links.facebook', 'https://www.facebook.com/fbkuenstler' . $sfx, $d['artist']['links']['facebook'] ?? 'fehlt');
    $ld = (array) ($d['seo']['jsonld'] ?? []);
    $same = [];
    foreach ($ld as $node) if (!empty($node['sameAs'])) $same = $node['sameAs'];
    $eq('JSON-LD sameAs enthält Facebook', true, in_array('https://www.facebook.com/fbkuenstler' . $sfx, $same, true));
} else {
    echo "info  öffentliche Route hier " . $res->get_status() . " (" . ($res->get_data()['code'] ?? '') . ") – Einzel-Seite ohne Künstlerseiten bzw. Veranstalter nicht freigegeben\n";
    $p = TIX_Artists::payload($id);
    $eq('öffentlicher payload: links.facebook', 'https://www.facebook.com/fbkuenstler' . $sfx, $p['links']['facebook'] ?? 'fehlt');
}

echo "── Zusammenführen ──\n";
wp_set_current_user($admin);
$res2 = $post('/artists', ['name' => "FB Zweit $sfx", 'force' => true]);
$id2 = intval($res2->get_data()['artist']['id'] ?? ($res2->get_data()['id'] ?? 0));
if ($id2) {
    $tmp[] = $id2;
    $rm = $post("/platform/artists/$id/merge", ['into' => $id2]);
    if ($rm->get_status() === 200) {
        $eq('Zusammenführen übernimmt facebook ins leere Ziel', 'https://www.facebook.com/fbkuenstler' . $sfx, ((array) get_post_meta($id2, TIX_Artists::META, true))['links']['facebook'] ?? null);
    } else echo "info  merge hier " . $rm->get_status() . " (nur Plattform)\n";
}

wp_set_current_user(0);
foreach ($tmp as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($admin);
echo "\n" . ($n - $f) . "/$n ok" . ($f ? " – $f FEHLER" : '') . "\n";
