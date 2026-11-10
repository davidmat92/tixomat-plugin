<?php
/** Kunden-Seiten-Profil auf evendis (1.38.726, Fund KMT #9510): öffentlich mit Co-Events, nur lesend. plat eval-file …/test-site-profile-public-wp.php */
if (!defined('ABSPATH') || !class_exists('TIX_Co_Sites')) { echo "Tixomat nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
if (!(class_exists('TIX_App_Scope') && TIX_App_Scope::multi())) { echo "Nur auf der Plattform\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = rest_get_server()->response_to_data($res, false); return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : '']; };
$flush = function () { if (class_exists('TIX_App_Scope')) TIX_App_Scope::flush_public_cache(); if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush(); wp_cache_flush(); };
$sfx = wp_rand(1000, 9999);
$posts = []; $users = [];
try {
    $u = wp_insert_user(['user_login' => "spp_$sfx", 'user_pass' => wp_generate_password(20), 'user_email' => "spp_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $u;
    $site = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => "SPPTEST Schwelm $sfx", 'meta_input' => [TIX_Co_Organizer::ORG_KIND => TIX_Co_Sites::KIND, '_tix_org_user_id' => $u, '_tix_org_city' => 'Schwelm']]); $posts[] = $site;
    $host = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => "SPPTEST KK $sfx"]); $posts[] = $host;
    $date = wp_date('Y-m-d', time() + 20 * DAY_IN_SECONDS);
    $flush();
    $eq('Kunden-Seiten-Profil ohne Event: ausgeblendet', [true, 404], [TIX_Co_Sites::public_hidden($site), $rest('GET', "/public/organizers/$site")[0]]);
    // Co bei einem lokalen Event eines anderen Veranstalters
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "SPPTEST Party $sfx", 'meta_input' => ['_tix_organizer_id' => $host, '_tix_date_start' => $date, '_tix_time_start' => '20:00']]); $posts[] = $ev;
    TIX_Co_Organizer::set($ev, $site, true);
    $flush();
    $eq('Co bei veröffentlichtem Event: sichtbar', false, TIX_Co_Sites::public_hidden($site));
    [$s, $d] = $rest('GET', "/public/organizers/$site");
    $evs = (array) ($d['organizer']['events'] ?? []);
    $eq('Detail 200, Co-Event in „kommende Events“ mit organizer_role co', [200, true, 'co', 1], [$s, in_array($ev, array_map('intval', array_column($evs, 'id')), true), $evs[0]['organizer_role'] ?? '', intval($d['organizer']['upcoming_count'] ?? 0)]);
    [$s, $d] = $rest('GET', '/public/organizers', ['q' => "spptest schwelm $sfx"]);
    $eq('Suche findet das Profil', [200, [$site]], [$s, array_map('intval', array_column((array) ($d['organizers'] ?? []), 'id'))]);
    [, $d] = $rest('GET', "/public/organizers/$host");
    $eq('Veranstalter des Events: organizer_role own', 'own', ($d['organizer']['events'][0]['organizer_role'] ?? ''));
    // Entwurf zählt nicht
    wp_update_post(['ID' => $ev, 'post_status' => 'draft']); $flush();
    $eq('Event nur Entwurf: wieder ausgeblendet', true, TIX_Co_Sites::public_hidden($site));
    // Co auf dem Spiegel einer Lizenzseite (Teil B)
    $mir = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "SPPTEST Spiegel $sfx", 'meta_input' => ['_tix_organizer_id' => $host, '_tix_date_start' => $date, '_tix_syndicated' => '1', '_tix_source_id' => 1171]]); $posts[] = $mir;
    TIX_Co_Remote::mirror_save($mir, [['oid' => $site, 'access' => true, 'token' => str_repeat('t', 48)]]);
    $flush();
    [$s, $d] = $rest('GET', "/public/organizers/$site");
    $eq('Co auf Spiegel (KK-Event): sichtbar, Event dabei', [false, 200, true], [TIX_Co_Sites::public_hidden($site), $s, in_array($mir, array_map('intval', array_column((array) ($d['organizer']['events'] ?? []), 'id')), true)]);
    // Nur lesend auf evendis
    wp_set_current_user($u);
    [$s, $d] = $rest('GET', '/organizer/profile');
    $eq('evendis-Studio: managed_by_site', [200, true], [$s, $d['managed_by_site'] ?? null]);
    $eq('Speichern auf evendis gesperrt', [409, 'tix_org_site_managed'], (function ($r) { return [$r[0], $r[2]]; })($rest('POST', '/organizer/profile', ['tagline' => 'x'])));
    $eq('Bild auf evendis gesperrt', 409, $rest('POST', '/organizer/profile/image', ['kind' => 'logo'])[0]);
    wp_set_current_user(0);
    $r = TIX_App_Scope::save_profile($site, ['tagline' => "Motto $sfx"]);
    $eq('Brücke der Kunden-Seite (save_profile) schreibt weiter', [true, "Motto $sfx", false], [$r, (string) get_post_meta($site, '_tix_org_landing_tagline', true), array_key_exists('managed_by_site', TIX_App_Scope::profile_for($site))]);
} finally {
    foreach (array_reverse($posts) as $p) wp_delete_post($p, true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $x) wp_delete_user($x);
    $flush();
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
