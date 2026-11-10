<?php
/** Bilder entfernen (1.38.741). wp eval-file …/test-image-remove-wp.php (Einzel-Seite und Plattform) */
if (!defined('ABSPATH') || !class_exists('TIX_Image_Remove')) { echo "Tixomat ab 1.38.741 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'DELETE') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = json_decode(wp_json_encode(rest_get_server()->response_to_data($res, false)), true); return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : '']; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$a = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user($a[0]->ID);
$posts = []; $users = [];
$img = function () use (&$posts) { $up = wp_upload_bits('irm-' . wp_generate_password(5, false) . '.png', null, (string) file_get_contents(ABSPATH . 'wp-admin/images/w-logo-blue.png')); $x = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'IRM', 'post_status' => 'inherit'], $up['file']); $posts[] = $x; return $x; };
try {
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'IRM Event', 'meta_input' => ['_tix_date_start' => wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS), '_tix_info_lineup' => '<p>IRM Act</p>']]); $posts[] = $ev;
    set_post_thumbnail($ev, $img());
    update_post_meta($ev, '_tix_charity_image', $img());
    update_post_meta($ev, '_tix_ticket_sponsor_image_id', $img());
    TIX_Lineup_Links::set_photo($ev, 'IRM Act', $img());
    $eq('Flyer entfernen', [200, false], (function ($r) use ($ev) { return [$r[0], has_post_thumbnail($ev)]; })($rest('DELETE', "/events/$ev/image")));
    $eq('Spenden-Bild entfernen', [200, ''], (function ($r) use ($ev) { return [$r[0], (string) get_post_meta($ev, '_tix_charity_image', true)]; })($rest('DELETE', "/events/$ev/charity-image")));
    $eq('Sponsor-Bild entfernen', [200, ''], (function ($r) use ($ev) { return [$r[0], (string) get_post_meta($ev, '_tix_ticket_sponsor_image_id', true)]; })($rest('DELETE', "/events/$ev/sponsor-image")));
    [$s] = $rest('DELETE', "/events/$ev/lineup-photo", ['name' => 'IRM Act']);
    $row = (TIX_Lineup_Links::for_event($ev) ?: [[]])[0];
    $eq('Act-Foto entfernen', [200, null], [$s, $row['picture'] ?? null]);
    $eq('Act-Foto ohne Namen 400', 400, $rest('DELETE', "/events/$ev/lineup-photo")[0]);
    // Upload-Route bleibt
    $eq('POST auf der Flyer-Route weiterhin erreichbar (kein 404)', true, $rest('POST', "/events/$ev/image")[0] !== 404);
    // Kopie eines geteilten Events: gesperrt
    $cp = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'IRM Kopie', 'meta_input' => $multi ? ['_tix_syndicated' => '1'] : ['_tix_platform_co' => 99]]); $posts[] = $cp;
    set_post_thumbnail($cp, $img());
    $eq('Kopie eines geteilten Events: 409, Bild bleibt', [409, true, true], (function ($r) use ($cp) { return [$r[0], in_array($r[2], ['tix_mirrored_readonly', 'tix_co_platform_event'], true), has_post_thumbnail($cp)]; })($rest('DELETE', "/events/$cp/image")));
    // Künstler
    $ar = wp_insert_post(['post_type' => 'tix_artist', 'post_status' => 'publish', 'post_title' => 'IRM Künstler']); $posts[] = $ar;
    $org = $multi ? wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'IRM Org']) : 0; if ($org) $posts[] = $org;
    update_post_meta($ar, TIX_Artists::META, ['status' => 'public', 'managed_by' => $org ? ['type' => 'organizer', 'id' => $org] : ['type' => 'site', 'id' => 0], 'links' => [], 'tours' => [], 'ids' => ['spotify' => '', 'apple' => ''], 'images' => ['portrait' => ['id' => $img(), 'credit' => 'x'], 'cover' => ['id' => $img(), 'credit' => 'y']]]);
    [$s, $d] = $rest('DELETE', "/artists/$ar/image", ['slot' => 'portrait']);
    $eq('Künstler: Porträt entfernen, Titelbild bleibt', [200, null, true], [$s, $d['artist']['images']['portrait'] ?? null, !empty($d['artist']['images']['cover'])]);
    $eq('Künstler: ungültiger slot 400', 400, $rest('DELETE', "/artists/$ar/image", ['slot' => 'x'])[0]);
    // Profilbild (Gast)
    $u = wp_insert_user(['user_login' => 'irm_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(20), 'user_email' => 'irm' . wp_rand(1000, 9999) . '@example.test']); $users[] = $u;
    update_user_meta($u, '_tix_avatar_url', 'https://x.test/a.png'); update_user_meta($u, '_tix_avatar_file', 'gibtsnicht.png');
    wp_set_current_user($u);
    $eq('Eigenes Profilbild entfernen', [200, ''], (function ($r) use ($u) { return [$r[0], (string) get_user_meta($u, '_tix_avatar_url', true)]; })($rest('DELETE', '/auth/profile/avatar')));
    wp_set_current_user(0);
    $eq('Profilbild ohne Anmeldung 401', 401, $rest('DELETE', '/auth/profile/avatar')[0]);
    wp_set_current_user($a[0]->ID);
    // Veranstalterseite (Plattform)
    if ($multi) {
        $ou = wp_insert_user(['user_login' => 'irmo_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(20), 'user_email' => 'irmo' . wp_rand(1000, 9999) . '@example.test', 'role' => 'tix_organizer']); $users[] = $ou;
        update_post_meta($org, '_tix_org_user_id', $ou); update_post_meta($org, '_tix_org_landing_hero_id', $img()); update_post_meta($org, '_tix_org_landing_logo_id', $img());
        wp_set_current_user($ou);
        [$s] = $rest('DELETE', '/organizer/profile/image', ['kind' => 'hero']);
        $eq('Veranstalterseite: Titelbild entfernen, Logo bleibt', [200, '', true], [$s, (string) get_post_meta($org, '_tix_org_landing_hero_id', true), (bool) get_post_meta($org, '_tix_org_landing_logo_id', true)]);
        $eq('Veranstalterseite: ungültiger kind 400', 400, $rest('DELETE', '/organizer/profile/image', ['kind' => 'x'])[0]);
        wp_set_current_user($a[0]->ID);
    }
    // Tipp
    if (class_exists('TIX_App_Tips')) {
        $tip = wp_insert_post(['post_type' => 'tix_app_tip', 'post_status' => 'publish', 'post_title' => 'IRM Tipp', 'meta_input' => ['_tix_tip_image' => $img()]]); $posts[] = $tip;
        $eq('Tipp-Bild entfernen', [200, ''], (function ($r) use ($tip) { return [$r[0], (string) get_post_meta($tip, '_tix_tip_image', true)]; })($rest('DELETE', "/app/tips/$tip/image", ['kind' => 'image'])));
    }
} finally {
    foreach (array_reverse($posts) as $p) if (get_post($p)) wp_delete_post($p, true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $x) wp_delete_user($x);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
