<?php
/**
 * W6b (1.38.888): Partner (P4), Hervorheben (P6), Städte-Texte (P7), Wochenmail/Folgen per E-Mail (P8), Sperren (P10).
 *   wp eval-file …/test-w6b-platform-content-wp.php   (Einzel-Seite: Routen 404)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Platform_Content')) { echo "Tixomat ab 1.38.888 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $req = new WP_REST_Request($m, '/tixomat/v1' . $r); foreach ($q as $k => $v) $req->set_param($k, $v); if ($b !== null) { $req->set_header('content-type', 'application/json'); $req->set_body(wp_json_encode($b)); } $res = rest_do_request($req); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? null) : null, is_array($d) ? ($d['data']['field'] ?? null) : null]; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);
if (!$multi) {
    [$s] = $rest('GET', '/platform/partners');
    $eq('Einzel-Seite: Plattform-Routen 404', 404, $s);
    echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
    return;
}
$mails = [];
$cap = function ($atts) use (&$mails) { $mails[] = (string) (is_array($atts['to']) ? implode(',', $atts['to']) : $atts['to']); return $atts; };
add_filter('wp_mail', $cap, 1); add_filter('pre_wp_mail', '__return_true', 99);
$keep = [];
foreach (['tix_partners', 'tix_city_texts', 'tix_weekly_mail', 'tix_mail_follow', 'tix_blocked_ips', 'tix_blocked_customers'] as $o) $keep[$o] = get_option($o, null);
$ev = 0; $sub = 0; $org = 0;
try {
    // ═══ P4 Partner ═══
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'W6b Partner-Veranstalter']);
    $all = (array) get_option('tix_partners', []);
    $all['w6b-test'] = ['id' => 'w6b-test', 'name' => 'W6b Quelle', 'api_base' => 'https://quelle.test/wp-json/tixomat/v1', 'key_in' => 'tix_pin_' . str_repeat('a', 30), 'key_out' => 'tix_pout_' . str_repeat('b', 30),
        'organizer_id' => 0, 'sales_enabled' => 0, 'pending' => 1, 'terms_url' => '', 'created' => current_time('mysql'), 'auto' => 1];
    update_option('tix_partners', $all, false);
    [$s, $d] = $rest('GET', '/platform/partners');
    $row = array_values(array_filter($d['partners'] ?? [], function ($p) { return $p['id'] === 'w6b-test'; }))[0] ?? [];
    $eq('Partner gelistet: wartet, Schlüssel gesetzt, aber nicht ausgegeben', [200, true, false, true, false], [$s, $row['pending'] ?? null, $row['sales_enabled'] ?? null, $row['keys_set'] ?? null, strpos(wp_json_encode($d), str_repeat('a', 30)) !== false]);
    [$s, $d] = $rest('POST', '/platform/partners/w6b-test', ['name' => 'W6b Quelle neu', 'organizer_id' => $org, 'terms_url' => 'https://quelle.test/agb', 'channel_fee' => true, 'channel_fee_percent' => '1,5']);
    $eq('Partner bearbeitet', [200, 'W6b Quelle neu', $org, 'https://quelle.test/agb', true, 1.5], [$s, $d['partner']['name'] ?? null, $d['partner']['organizer']['id'] ?? null, $d['partner']['terms_url'] ?? null, $d['partner']['channel_fee'] ?? null, $d['partner']['channel_fee_percent'] ?? null]);
    [$s, , , $fld] = $rest('POST', '/platform/partners/w6b-test', ['channel_fee_percent' => 30]);
    $eq('Kanal-Gebühr 30 % → 400', [400, 'channel_fee_percent'], [$s, $fld]);
    [$s, , , $fld] = $rest('POST', '/platform/partners/w6b-test', ['terms_url' => 'http://unsicher.test']);
    $eq('AGB ohne https → 400', [400, 'terms_url'], [$s, $fld]);
    [$s, $d] = $rest('POST', '/platform/partners/w6b-test/approve');
    $eq('Freigeben: Verkauf an, nicht mehr wartend', [200, true, false], [$s, $d['partner']['sales_enabled'] ?? null, $d['partner']['pending'] ?? null]);
    [$s] = $rest('POST', '/platform/partners/gibts-nicht', ['name' => 'x']);
    $eq('unbekannter Partner → 404', 404, $s);
    [$s] = $rest('DELETE', '/platform/partners/w6b-test');
    $eq('Partner entfernt', [200, null], [$s, TIX_Partners::get('w6b-test')]);

    // ═══ P6 Hervorheben ═══
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'W6b Event']);
    update_post_meta($ev, '_tix_date_start', wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS));
    $until = wp_date('Y-m-d', time() + 5 * DAY_IN_SECONDS);
    [$s, $d] = $rest('POST', "/platform/events/$ev/promote", ['enabled' => true, 'priority' => 50, 'until' => $until]);
    $eq('hervorgehoben bis Datum mit Rang', [200, true, 50, $until, true], [$s, $d['event']['promoted'] ?? null, $d['event']['priority'] ?? null, $d['event']['until'] ?? null, $d['event']['active'] ?? null]);
    [, $d] = $rest('GET', '/platform/promoted');
    $eq('in der Liste', true, in_array($ev, array_column($d['events'] ?? [], 'id'), true));
    [$s, , , $fld] = $rest('POST', "/platform/events/$ev/promote", ['enabled' => true, 'until' => '2020-01-01']);
    $eq('Datum in der Vergangenheit → 400 until', [400, 'until'], [$s, $fld]);
    [$s, $d] = $rest('POST', "/platform/events/$ev/promote", ['enabled' => false]);
    $eq('Hervorhebung aus', [200, false], [$s, $d['event']['promoted'] ?? null]);

    // ═══ P7 Städte-Texte ═══
    [$s, $d] = $rest('POST', '/platform/city-texts', ['name' => 'Düsseldorf', 'intro' => '<p>Rhein <strong>und</strong> Altstadt</p><script>x</script>']);
    $c = array_values(array_filter($d['cities'] ?? [], function ($x) { return $x['slug'] === 'duesseldorf'; }))[0] ?? [];
    $eq('Stadt angelegt, Slug duesseldorf, Skript entfernt', [200, 'Düsseldorf', true, false], [$s, $c['name'] ?? null, strpos($c['intro'] ?? '', '<strong>') !== false, strpos($c['intro'] ?? '', '<script') !== false]);
    [$s, , , $fld] = $rest('POST', '/platform/city-texts', ['name' => 'Leer']);
    $eq('ohne Text und Bild → 400 intro', [400, 'intro'], [$s, $fld]);
    // Bild hochladen (1.38.891) und mit dem Text speichern
    $tmp = wp_tempnam('stadt.png'); $im = imagecreatetruecolor(4, 4); imagepng($im, $tmp); imagedestroy($im);
    $ureq = new WP_REST_Request('POST', '/tixomat/v1/platform/city-texts/image');
    $ureq->set_file_params(['image' => ['name' => 'stadt.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)]]);
    $ureq->set_param('alt', 'Rheinufer');
    $ures = rest_do_request($ureq); $ud = $ures->get_data(); $img_id = intval($ud['image']['id'] ?? 0);
    $eq('Städte-Bild hochgeladen (id, url, alt)', [200, true, true, 'Rheinufer'], [$ures->get_status(), $img_id > 0, !empty($ud['image']['url']), $ud['image']['alt'] ?? null]);
    [$s, $d] = $rest('POST', '/platform/city-texts', ['name' => 'Düsseldorf', 'intro' => '<p>Rhein</p>', 'image_id' => $img_id]);
    $c = array_values(array_filter($d['cities'] ?? [], function ($x) { return $x['slug'] === 'duesseldorf'; }))[0] ?? [];
    $eq('Bild am Städte-Text', [200, $img_id], [$s, $c['image']['id'] ?? null]);
    $nreq = new WP_REST_Request('POST', '/tixomat/v1/platform/city-texts/image');
    $eq('ohne Bild → 400', 400, rest_do_request($nreq)->get_status());
    if ($img_id) wp_delete_attachment($img_id, true);
    [$s, $d] = $rest('DELETE', '/platform/city-texts/duesseldorf');
    $eq('Stadt entfernt', [200, false], [$s, in_array('duesseldorf', array_column($d['cities'] ?? [], 'slug'), true)]);

    // ═══ P8 ═══
    [$s, $d] = $rest('POST', '/platform/weekly-mail', ['enabled' => true, 'day' => 5, 'hour' => 8]);
    $eq('Wochenmail gespeichert (+ Zähler)', [200, true, 5, 8, true], [$s, $d['enabled'] ?? null, $d['day'] ?? null, $d['hour'] ?? null, is_array($d['counts'] ?? null)]);
    [$s, , , $fld] = $rest('POST', '/platform/weekly-mail', ['hour' => 24]);
    $eq('Stunde 24 → 400', [400, 'hour'], [$s, $fld]);
    $mails = [];
    [$s, $d] = $rest('POST', '/platform/weekly-mail/test', ['city' => 'Köln']);
    $eq('Test-Mail an die eigene Adresse', [200, $adm->user_email, true], [$s, $d['email'] ?? null, in_array($adm->user_email, $mails, true)]);
    [$s, $d] = $rest('POST', '/platform/mail-follow', ['enabled' => true]);
    $eq('Folgen per E-Mail an (+ Anzahl)', [200, true, true], [$s, $d['enabled'] ?? null, is_int($d['active'] ?? null)]);

    // ═══ P10 Sperren ═══
    $eq('clean_ips rein', [['1.2.3.4', '84.143.'], null], TIX_Platform_Content::clean_ips(['1.2.3.4', ' ', '84.143.', '1.2.3.4']));
    $eq('clean_ips ungültig → Index', [null, 1], TIX_Platform_Content::clean_ips(['1.2.3.4', 'kaputt']));
    [$s, $d] = $rest('POST', '/platform/blocks', ['ips' => ['203.0.113.7', '198.51.100.'], 'customers' => ['Spam@Example.test', 'Max Mustermann']]);
    $eq('Sperren gespeichert', [200, ['203.0.113.7', '198.51.100.'], ['spam@example.test', 'Max Mustermann']], [$s, $d['ips'] ?? null, $d['customers'] ?? null]);
    [$s, , , $fld] = $rest('POST', '/platform/blocks', ['customers' => ['kaputt@']]);
    $eq('ungültige Mail → 400 customers.0', [400, 'customers.0'], [$s, $fld]);

    // ═══ Rechte ═══
    $sub = wp_insert_user(['user_login' => 'w6b_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'user_email' => 'w6b_' . wp_rand(1000, 9999) . '@example.test', 'role' => 'tix_organizer']);
    wp_set_current_user($sub);
    [$s1] = $rest('GET', '/platform/partners'); [$s2] = $rest('POST', '/platform/blocks', ['ips' => []]); [$s3] = $rest('POST', "/platform/events/$ev/promote", ['enabled' => true]);
    $eq('Veranstalter → 403', [403, 403, 403], [$s1, $s2, $s3]);
    wp_set_current_user($adm->ID);
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    remove_filter('wp_mail', $cap, 1); remove_filter('pre_wp_mail', '__return_true', 99);
    foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
    if ($ev) wp_delete_post($ev, true);
    if ($org) wp_delete_post($org, true);
    if ($sub) wp_delete_user($sub);
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
