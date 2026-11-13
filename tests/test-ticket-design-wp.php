<?php
/**
 * Ticket-Design im Studio (P4 Expertenmodus, 1.38.800): PDF-Vorlage, Online-Ticket, Wallet, Vorlagen, je Event, Vorschau.
 *   wp eval-file …/test-ticket-design-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Ticket_Design')) { echo "Tixomat ab 1.38.800 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$keep = get_option('tix_settings');
$posts = []; $atts = []; $users = [];
try {
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    wp_set_current_user($adm);
    $org = 0; $q = [];
    if ($multi) { $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'P4 Veranstalter']); $posts[] = $org; $q = ['organizer_id' => $org]; }
    // Vorlagenbild
    $up = wp_upload_dir(); $file = trailingslashit($up['path']) . 'p4-vorlage-' . wp_rand(1000, 9999) . '.png';
    $im = imagecreatetruecolor(600, 900); imagefill($im, 0, 0, imagecolorallocate($im, 30, 30, 60)); imagepng($im, $file); imagedestroy($im);
    $tpl_img = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'P4 Vorlage', 'post_status' => 'inherit', 'post_author' => $adm], $file); $atts[] = $tpl_img;

    [$st, $d] = $rest('GET', '/studio/ticket-design', null, $q);
    $eq('GET: Felder, GD, Bereiche', [200, 17, true, true], [$st, count($d['fields'] ?? []), !empty($d['gd']), isset($d['pdf'], $d['html'], $d['wallet'])]);
    $pdf = ['template_image_id' => $tpl_img, 'canvas_width' => 600, 'canvas_height' => 900, 'fields' => ['event_name' => ['enabled' => true, 'x' => 40, 'y' => 60, 'width' => 520, 'height' => 60, 'font_size' => 36, 'color' => '#ffffff'],
        'qr_code' => ['enabled' => true, 'x' => 200, 'y' => 500, 'width' => 200, 'height' => 200], 'seat' => ['enabled' => true, 'x' => 40, 'y' => 200, 'width' => 300, 'height' => 40]]];
    [$st, $d] = $rest('POST', '/studio/ticket-design', ['pdf' => $pdf], $q);
    $eq('PDF-Vorlage gespeichert (Bild, Felder an)', [200, $tpl_img, true, true, false], [$st, $d['pdf']['template_image_id'] ?? 0, $d['pdf']['fields']['event_name']['enabled'] ?? null, !empty($d['pdf']['template_image']['url']), $d['pdf']['fields']['barcode']['enabled'] ?? null]);
    [$st, $d] = $rest('POST', '/studio/ticket-design', ['pdf' => ['fields' => ['kino' => ['enabled' => true]]]], $q);
    $eq('Unbekanntes Feld → 400', [400, 'pdf.fields.kino'], [$st, $d['data']['field'] ?? null]);
    [$st, $d] = $rest('POST', '/studio/ticket-design', ['html' => ['ht_version' => 'v3', 'ht_header_bg' => '#112233', 'ht_show_countdown' => true], 'wallet' => ['wallet_apple_bg_color' => '#101010']]);
    $eq('Online-Ticket und Wallet (Seite/Admin)', [200, 'v3', '#112233', true, '#101010'], [$st, $d['html']['ht_version'] ?? null, $d['html']['ht_header_bg'] ?? null, $d['html']['ht_show_countdown'] ?? null, $d['wallet']['wallet_apple_bg_color'] ?? null]);
    [$st, $d] = $rest('POST', '/studio/ticket-design', ['html' => ['ht_header_bg' => 'rot']]);
    $eq('Ungültige Farbe → 400', [400, 'html.ht_header_bg'], [$st, $d['data']['field'] ?? null]);

    // Vorschau
    [$st, $d] = $rest('POST', '/studio/ticket-design/preview', ['format' => 'jpg', 'width' => 400] + ($multi ? ['organizer_id' => $org] : []), $q);
    $eq('Vorschau JPG aus der gespeicherten Vorlage (Musterdaten), Breite begrenzt', [200, true, 400, true], [$st, strpos((string) ($d['image'] ?? ''), 'data:image/jpeg;base64,') === 0, $d['width'] ?? 0, $d['sample'] ?? null]);
    [$st, $d] = $rest('POST', '/studio/ticket-design/preview', ['pdf' => $pdf, 'format' => 'pdf']);
    $raw = base64_decode(substr((string) ($d['pdf'] ?? ''), strlen('data:application/pdf;base64,')));
    $eq('Vorschau PDF aus ungespeicherter Konfiguration', [200, '%PDF'], [$st, substr((string) $raw, 0, 4)]);
    [$st, , $c] = $rest('POST', '/studio/ticket-design/preview', ['pdf' => ['template_image_id' => 0]]);
    $eq('Ohne Bild → 409 tix_no_template', [409, 'tix_no_template'], [$st, $c]);

    // Vorlagen + Event
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'P4 Konzert', 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => '2027-05-01', '_tix_time_start' => '20:00', '_tix_location' => 'Halle 1']]); $posts[] = $ev;
    [$st, $d] = $rest('GET', "/events/$ev/ticket-design");
    $eq('Event: Modus global, wirksam = Seite bzw. Veranstalter', [200, 'global', $multi ? 'organizer' : 'global'], [$st, $d['mode'] ?? '', $d['effective']['source'] ?? '']);
    [$st, $d] = $rest('POST', '/studio/ticket-templates', ['title' => 'P4 Festival', 'pdf' => $pdf] + ($multi ? [] : []), $q);
    $tid = intval($d['template']['id'] ?? 0); if ($tid) $posts[] = $tid;
    $eq('Vorlage anlegen → 201', [201, 'P4 Festival', $tpl_img], [$st, $d['template']['title'] ?? '', $d['template']['pdf']['template_image_id'] ?? 0]);
    [$st, $d] = $rest('GET', '/studio/ticket-templates');
    $eq('Liste enthält die Vorlage', true, in_array($tid, array_column((array) ($d['templates'] ?? []), 'id'), true));
    [$st, $d] = $rest('POST', "/events/$ev/ticket-design", ['mode' => 'template', 'template_id' => $tid]);
    $eq('Event nutzt die Vorlage', [200, 'template', 'template'], [$st, $d['mode'] ?? '', $d['effective']['source'] ?? '']);
    [$st, $d] = $rest('POST', '/studio/ticket-design/preview', ['event_id' => $ev, 'format' => 'jpg']);
    $eq('Vorschau mit Event-Daten', [200, false, 'P4 Konzert'], [$st, $d['sample'] ?? null, $d['wallet']['title'] ?? '']);
    [$st, , $c] = $rest('DELETE', "/studio/ticket-templates/$tid");
    $eq('Genutzte Vorlage löschen → 409', [409, 'tix_template_in_use'], [$st, $c]);
    $custom = $pdf; $custom['fields']['event_name']['color'] = '#ff0000';
    [$st, $d] = $rest('POST', "/events/$ev/ticket-design", ['mode' => 'custom', 'custom' => $custom]);
    $eq('Eigene Vorlage je Event', [200, 'custom', '#ff0000'], [$st, $d['effective']['source'] ?? '', $d['custom']['fields']['event_name']['color'] ?? '']);
    [$st, $d] = $rest('POST', "/events/$ev/ticket-design", ['mode' => 'none']);
    $eq('Kein PDF-Ticket', [200, 'none', null], [$st, $d['mode'] ?? '', $d['effective']]);
    [$st] = $rest('DELETE', "/studio/ticket-templates/$tid");
    $eq('Ungenutzte Vorlage löschen', 200, $st);
    [$st, , $c] = $rest('POST', "/events/$ev/ticket-design", ['mode' => 'kino']);
    $eq('Unbekannter Modus → 400', 400, $st);

    // Rechte
    $staff = wp_insert_user(['user_login' => 'p4s' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'user_email' => 'p4s' . wp_rand(1000, 9999) . '@example.test', 'role' => 'tix_staff']); $users[] = $staff;
    wp_set_current_user($staff);
    $eq('Mitarbeiter: ändern verboten', true, in_array($rest('POST', '/studio/ticket-design', ['pdf' => $pdf], $q)[0], [401, 403], true));
    wp_set_current_user($adm);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    update_option('tix_settings', $keep);
    foreach (array_reverse($posts) as $p) { if (get_post_type($p) === 'tix_organizer' && class_exists('TIX_Object_Data')) TIX_Object_Data::remove(TIX_Ticket_Design::OBJ, $p); wp_delete_post($p, true); }
    foreach ($atts as $a) wp_delete_attachment($a, true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $u) wp_delete_user($u);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
