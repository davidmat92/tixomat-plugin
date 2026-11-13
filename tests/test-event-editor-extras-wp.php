<?php
/**
 * P1 Event-Editor komplett (1.38.803): Galerie, Video, Upsell, Einbetten, Express-Kasse, Warteliste; Kategorien/Sammlungen.
 *   wp eval-file …/test-event-editor-extras-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Event_Editor_Extras')) { echo "Tixomat ab 1.38.803 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$posts = []; $atts = []; $terms = []; $users = [];
$mkimg = function ($name) use (&$atts) {
    $up = wp_upload_dir(); $file = trailingslashit($up['path']) . $name . '-' . wp_rand(1000, 9999) . '.png';
    $im = imagecreatetruecolor(200, 120); imagepng($im, $file); imagedestroy($im);
    $id = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => $name, 'post_status' => 'inherit', 'post_author' => get_current_user_id()], $file);
    require_once ABSPATH . 'wp-admin/includes/image.php'; wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));
    $atts[] = $id; return $id;
};
try {
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    wp_set_current_user($adm);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'P1 Event']); $posts[] = $ev;
    $other = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'P1 Anderes', 'meta_input' => ['_tix_date_start' => '2027-02-01']]); $posts[] = $other;
    $g1 = $mkimg('p1-a'); $g2 = $mkimg('p1-b');
    [$st, $d] = $rest('GET', "/events/$ev/extras");
    $eq('GET: leer, Grenzen', [200, [], false, 40], [$st, $d['gallery'] ?? null, $d['embed']['enabled'] ?? null, $d['gallery_max'] ?? 0]);
    [$st, $d] = $rest('POST', "/events/$ev/extras", ['gallery' => [$g2, $g1], 'video_url' => 'https://www.youtube.com/watch?v=abc', 'upsell' => ['event_ids' => [$other]],
        'embed' => ['enabled' => true, 'domains' => ['https://meine-seite.de/', '*.partner.example']], 'express_checkout' => true, 'waitlist_enabled' => true]);
    $eq('Speichern: Reihenfolge, Video, Upsell, Einbetten, Express, Warteliste', [200, [$g2, $g1], 'https://www.youtube.com/watch?v=abc', 'P1 Anderes', ['meine-seite.de', '*.partner.example'], true, true],
        [$st, array_column((array) ($d['gallery'] ?? []), 'id'), $d['video_url'] ?? null, $d['upsell']['events'][0]['title'] ?? '', $d['embed']['domains'] ?? null, $d['express_checkout'] ?? null, $d['waitlist_enabled'] ?? null]);
    $eq('Abgeleitete Galerie-Metas wie die Metabox', [$g2 . ',' . $g1, '2'], [(string) get_post_meta($ev, '_tix_gallery_ids', true), (string) get_post_meta($ev, '_tix_gallery_count', true)]);
    [$st, $d] = $rest('POST', "/events/$ev/extras", ['upsell' => ['event_ids' => [$ev]]]);
    $eq('Upsell auf sich selbst → 400', [400, 'upsell.event_ids.0'], [$st, $d['data']['field'] ?? null]);
    [$st, $d] = $rest('POST', "/events/$ev/extras", ['embed' => ['domains' => ['kein domain']]]);
    $eq('Ungültige Domain → 400', 400, $st);
    [$st, $d] = $rest('POST', "/events/$ev/extras", ['video_url' => 'javascript:alert(1)']);
    $eq('Unsicherer Video-Link → 400', 400, $st);
    [$st, $d] = $rest('POST', "/events/$ev/extras", ['gallery' => [$g1]]);
    $eq('Bild entfernen', [$g1], array_column((array) ($d['gallery'] ?? []), 'id'));
    TIX_Public_Events::flush(); wp_cache_flush();
    [, $pe] = $rest('GET', "/public/events/$ev");
    $pe = $pe['event'] ?? $pe;
    $eq('Öffentlich: Galerie sichtbar', 1, count((array) ($pe['gallery_items'] ?? $pe['gallery'] ?? [])));

    // Kategorien
    [$st, $d] = $rest('POST', '/studio/taxonomies/categories', ['name' => 'P1 Techno ' . wp_rand(100, 999)]);
    $t1 = intval($d['term']['id'] ?? 0); if ($t1) $terms[] = [$t1, 'event_category'];
    [$st2, $d2] = $rest('POST', '/studio/taxonomies/categories', ['name' => 'P1 Schlager ' . wp_rand(100, 999)]);
    $t2 = intval($d2['term']['id'] ?? 0); if ($t2) $terms[] = [$t2, 'event_category'];
    $eq('Kategorien anlegen → 201', [201, 201], [$st, $st2]);
    [$st, , $c] = $rest('POST', '/studio/taxonomies/categories', ['name' => $d['term']['name'] ?? '']);
    $eq('Doppelt → 409 tix_term_exists', [409, 'tix_term_exists'], [$st, $c]);
    [$st, $d] = $rest('POST', '/studio/taxonomies/categories/order', ['ids' => [$t2, $t1]]);
    $pos = array_flip(array_column((array) ($d['terms'] ?? []), 'id'));
    $eq('Sortieren', true, isset($pos[$t2], $pos[$t1]) && $pos[$t2] < $pos[$t1]);
    [$st, $d] = $rest('POST', "/studio/taxonomies/categories/$t1", ['name' => 'P1 Techno neu']);
    $eq('Umbenennen', 'P1 Techno neu', $d['term']['name'] ?? '');
    wp_set_object_terms($ev, [$t1], 'event_category');
    [$st, , $c] = $rest('DELETE', "/studio/taxonomies/categories/$t1");
    $eq('Löschen mit Events → 409', [409, 'tix_term_in_use'], [$st, $c]);
    [$st] = $rest('DELETE', "/studio/taxonomies/categories/$t1", null, ['force' => 1]);
    $eq('Löschen mit force', 200, $st);
    if (taxonomy_exists('tix_collection')) {
        [$st, $d] = $rest('POST', '/studio/taxonomies/collections', ['name' => 'P1 Halloween ' . wp_rand(100, 999), 'visible_from' => '2026-10-01', 'visible_to' => '2026-11-01']);
        $tc = intval($d['term']['id'] ?? 0); if ($tc) $terms[] = [$tc, 'tix_collection'];
        $eq('Sammlung mit Zeitraum', [201, '2026-10-01'], [$st, $d['term']['visible_from'] ?? null]);
    }
    // Rechte
    $staff = wp_insert_user(['user_login' => 'p1s' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'user_email' => 'p1s' . wp_rand(1000, 9999) . '@example.test', 'role' => 'tix_staff']); $users[] = $staff;
    wp_set_current_user($staff);
    $eq('Mitarbeiter: Kategorien anlegen verboten', true, in_array($rest('POST', '/studio/taxonomies/categories', ['name' => 'Nope'])[0], [401, 403], true));
    wp_set_current_user($adm);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($terms as [$t, $tx]) wp_delete_term($t, $tx);
    foreach ($posts as $p) wp_delete_post($p, true);
    foreach ($atts as $a) wp_delete_attachment($a, true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $u) wp_delete_user($u);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
