<?php
/**
 * P6 Orte (1.38.804): Seiten-Angaben je Ort (Öffnungszeiten, Galerie, Kontakt, Texte), doppelte Orte zusammenlegen,
 * öffentliche Zusammenführung (Vorschläge, evendis-Admin).
 *   wp eval-file …/test-locations-studio-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Club') || !method_exists('TIX_Club', 'page_payload')) { echo "Tixomat ab 1.38.804 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$posts = [];
try {
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    wp_set_current_user($adm);
    [$st, $d] = $rest('POST', '/locations', ['title' => 'P6 Halle ' . wp_rand(100, 999), 'address' => 'Hauptstr. 5', 'zip' => '58332', 'city' => 'Schwelm',
        'page' => ['tagline' => 'Die Halle am Markt', 'hours' => ['fri' => [['open' => '20:00', 'close' => '03:00']]], 'hours_note' => 'Feiertage abweichend', 'phone' => '02336 123', 'website' => 'https://halle.example']]);
    $a = intval($d['location']['id'] ?? 0); if ($a) $posts[] = $a;
    $eq('Ort mit Seiten-Angaben anlegen', [201, 'Die Halle am Markt', 'Feiertage abweichend', '02336 123', true], [$st, $d['location']['page']['tagline'] ?? '', $d['location']['page']['hours_note'] ?? '', $d['location']['page']['phone'] ?? '', !empty($d['location']['page']['hours'])]);
    [$st, $d] = $rest('POST', "/locations/$a", ['page' => ['hours' => ['fri' => [['open' => '25:00', 'close' => '03:00']]]]]);
    $eq('Ungültige Uhrzeit → 400 mit page.-Feld', [400, true], [$st, strpos((string) ($d['data']['field'] ?? ''), 'page.hours') === 0]);
    [$st, $d] = $rest('GET', '/locations');
    $row = array_values(array_filter((array) ($d['locations'] ?? $d), function ($l) use ($a) { return is_array($l) && intval($l['id'] ?? 0) === $a; }))[0] ?? [];
    $eq('Liste ohne Seiten-Angaben (schlank)', null, $row['page'] ?? null);
    [$st, $d] = $rest('GET', '/locations', null, ['with' => 'page']);
    $row = array_values(array_filter((array) ($d['locations'] ?? $d), function ($l) use ($a) { return is_array($l) && intval($l['id'] ?? 0) === $a; }))[0] ?? [];
    $eq('Liste mit ?with=page', 'Die Halle am Markt', $row['page']['tagline'] ?? '');

    // Doppelten Ort zusammenlegen
    [, $d] = $rest('POST', '/locations', ['title' => 'P6 Halle doppelt ' . wp_rand(100, 999), 'address' => 'Hauptstraße 5', 'city' => 'Schwelm', 'accessibility' => ['items' => ['step_free'], 'note' => 'Rampe hinten']]);
    $b = intval($d['location']['id'] ?? 0); if ($b) $posts[] = $b;
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'P6 Abend', 'meta_input' => ['_tix_location_id' => $b, '_tix_location' => 'alt']]); $posts[] = $ev;
    [$st, $d] = $rest('POST', "/locations/$b/merge-into", ['target_id' => $a]);
    $eq('Zusammenlegen: Event hängt am Ziel, Ort im Papierkorb, Barrierefreiheit übernommen', [200, 1, $a, 'trash', ['step_free']],
        [$st, $d['events_moved'] ?? 0, intval(get_post_meta($ev, '_tix_location_id', true)), get_post_status($b), (array) ($d['into']['accessibility']['items'] ?? [])]);
    [$st, , $c] = $rest('POST', "/locations/$a/merge-into", ['target_id' => $a]);
    $eq('Ziel = Quelle → 400', 400, $st);

    // Öffentliche Zusammenführung (Admin)
    [$st, $d] = $rest('GET', '/studio/locations/merge');
    $eq('Vorschläge/Gruppen lesbar (Admin)', [200, true, true], [$st, is_array($d['suggestions'] ?? null), is_array($d['groups'] ?? null)]);
    [$st, , $c] = $rest('POST', '/studio/locations/merge', ['action' => 'kaputt']);
    $eq('Unbekannte Aktion → 400', 400, $st);
    $staff = wp_insert_user(['user_login' => 'p6s' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'user_email' => 'p6s' . wp_rand(1000, 9999) . '@example.test', 'role' => 'tix_staff']);
    wp_set_current_user($staff);
    $eq('Mitarbeiter: Zusammenführen verboten', true, in_array($rest('GET', '/studio/locations/merge')[0], [401, 403], true));
    wp_set_current_user($adm);
    require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($staff);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($posts as $p) wp_delete_post($p, true);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
