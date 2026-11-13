<?php
/**
 * Sponsoren-Logos (1.38.793): Liste/Grenzen, Schalter, Ausspielorte, Event-Modus, öffentliche Ausgabe, Klicks, Mail, Ticket, Schnappschuss, Sperre.
 *   wp eval-file …/test-sponsor-logos-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Sponsor_Logos')) { echo "Tixomat ab 1.38.793 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = [], $hd = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); foreach ($hd as $k => $v) $x->set_header($k, $v); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); return [$res->get_status(), $res->get_data(), $res->get_headers()]; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$posts = []; $atts = []; $users = [];
TIX_Sponsor_Logos::install();
$mkimg = function ($w, $h, $name) use (&$atts) {
    $up = wp_upload_dir(); $file = trailingslashit($up['path']) . $name . '-' . wp_rand(1000, 9999) . '.png';
    $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 60)); imagepng($im, $file); imagedestroy($im);
    $id = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => $name, 'post_status' => 'inherit'], $file);
    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));
    $atts[] = $id; return $id;
};
try {
    $adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);
    $org = 0;
    if ($multi) { $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'Sponsor-Test-Veranstalter']); $posts[] = $org; }
    $q = $multi ? ['organizer_id' => $org] : [];
    [$scope, $owner] = $multi ? ['organizer', $org] : ['site', 0];
    $keep_settings = TIX_Sponsor_Logos::settings($scope, $owner);
    $keep_rows = TIX_Sponsor_Logos::rows($scope, $owner);
    $wpdb->delete(TIX_Sponsor_Logos::t('logos'), ['scope' => $scope, 'owner_id' => $owner]);

    // reine Prüfung
    $v = TIX_Sponsor_Logos::validate(['main' => array_fill(0, 4, ['name' => 'X', 'logo' => ['url' => 'https://a.test/x.png']])]);
    $eq('Grenze: 4 Hauptsponsoren → tix_sponsor_limit', ['tix_sponsor_limit', 3], [$v['error'][0] ?? null, $v['error'][3]['max'] ?? null]);
    $v = TIX_Sponsor_Logos::validate(['partners' => array_fill(0, 21, ['name' => 'X', 'logo' => ['url' => 'https://a.test/x.png']])]);
    $eq('Grenze: 21 weitere → tix_sponsor_limit', 'tix_sponsor_limit', $v['error'][0] ?? null);
    $v = TIX_Sponsor_Logos::validate(['main' => [['name' => 'X', 'logo' => ['url' => 'https://a.test/x.png'], 'link' => 'http://unsicher.test']]]);
    $eq('Link nur https', 'main.0.link', $v['error'][1] ?? null);
    $v = TIX_Sponsor_Logos::validate(['main' => [['name' => 'X']]]);
    $eq('Logo Pflicht', 'main.0.logo', $v['error'][1] ?? null);
    $eq('Ausspielorte: Vorgabe', ['web_home' => true, 'web_event' => true, 'web_footer' => false, 'app_home' => true, 'app_event' => true, 'ticket' => false, 'mail' => false], TIX_Sponsor_Logos::clean_places(null));

    // Studio
    $l1 = $mkimg(400, 200, 'spon-a'); $l1d = $mkimg(400, 200, 'spon-a-dunkel'); $l2 = $mkimg(300, 100, 'spon-b'); $l3 = $mkimg(200, 200, 'spon-c');
    [$st, $d] = $rest('PUT', '/studio/sponsors', ['main' => [['name' => 'Brauerei', 'logo' => ['id' => $l1], 'logo_dark' => ['id' => $l1d], 'link' => 'https://brauerei.example']],
        'partners' => [['name' => 'Bäcker', 'logo' => ['id' => $l2]], ['name' => 'Alt', 'logo' => ['id' => $l3], 'active' => false]]], $q);
    $eq('PUT /studio/sponsors speichert, Vorgabe aus', [200, false, 1, 2, 'Brauerei', true], [$st, $d['enabled'] ?? null, count($d['main'] ?? []), count($d['partners'] ?? []), $d['main'][0]['name'] ?? null, !empty($d['main'][0]['logo_dark']['url'])]);
    $sid = intval($d['main'][0]['id'] ?? 0);
    $eq('aus: nirgends ausgespielt', null, TIX_Sponsor_Logos::owner_public($scope, $owner));
    [$st, $d] = $rest('PUT', '/studio/sponsors', ['enabled' => true, 'places' => ['mail' => true, 'ticket' => true]], $q);
    $eq('Schalter an + Orte: mail/ticket an, Rest Vorgabe; Liste unverändert', [200, true, true, true, true, 1], [$st, $d['enabled'] ?? null, $d['places']['mail'] ?? null, $d['places']['ticket'] ?? null, $d['places']['web_home'] ?? null, count($d['main'] ?? [])]);
    [$st, $d] = $rest('PUT', '/studio/sponsors', ['places' => ['kino' => true]], $q);
    $eq('unbekannter Ort: 400', [400, 'places.kino'], [$st, $d['data']['field'] ?? null]);
    $pub = TIX_Sponsor_Logos::owner_public($scope, $owner);
    $eq('öffentlich: nur aktive, Link = Weiterleitung, target = Original', [1, 1, true, 'https://brauerei.example', null], [count($pub['main']), count($pub['partners']), strpos((string) $pub['main'][0]['link'], '/public/sponsors/' . $sid . '/go') !== false, $pub['main'][0]['target'], $pub['partners'][0]['link']]);
    if (!$multi) {
        [, $sc] = $rest('GET', '/public/site-config');
        $eq('site-config: sponsors', ['Brauerei', true], [$sc['sponsors']['main'][0]['name'] ?? null, $sc['sponsors']['places']['web_home'] ?? null]);
        [, $ac] = $rest('GET', '/app/config');
        $eq('app/config: sponsors', 'Brauerei', $ac['sponsors']['main'][0]['name'] ?? null);
    } else {
        [, $sc] = $rest('GET', '/public/site-config');
        $eq('evendis: keine plattformweite Liste in site-config', null, is_array($sc) ? ($sc['sponsors'] ?? null) : null);
        [, $ac] = $rest('GET', '/app/config', null, ['organizer' => $org]);
        $eq('evendis app/config?organizer=: Liste des Veranstalters', 'Brauerei', $ac['sponsors']['main'][0]['name'] ?? null);
    }

    // Hauptsponsoren-Karte (1.38.796): Beschreibung + Knopf
    [$st, $d] = $rest('PUT', '/studio/sponsors', ['main' => [['id' => $sid, 'name' => 'Brauerei', 'logo' => ['id' => $l1], 'logo_dark' => ['id' => $l1d], 'link' => 'https://brauerei.example',
        'description' => "<b>Frisch</b> gezapft.\r\n\r\n\r\nSeit 1890   aus Schwelm.", 'button_label' => '']]], $q);
    $eq('Karte: Beschreibung ohne HTML, Umbrüche bereinigt, ID und Klicks bleiben', [200, "Frisch gezapft.\n\nSeit 1890 aus Schwelm.", '', $sid], [$st, $d['main'][0]['description'] ?? null, $d['main'][0]['button_label'] ?? null, $d['main'][0]['id'] ?? null]);
    $pub = TIX_Sponsor_Logos::owner_public($scope, $owner);
    $eq('öffentlich: description, Knopf-Vorgabe „Mehr erfahren“, weitere ohne', ["Frisch gezapft.\n\nSeit 1890 aus Schwelm.", 'Mehr erfahren', null, null],
        [$pub['main'][0]['description'] ?? 'fehlt', $pub['main'][0]['button_label'] ?? 'fehlt', array_key_exists('description', $pub['partners'][0]) ? $pub['partners'][0]['description'] : 'fehlt', $pub['partners'][0]['button_label'] ?? null]);
    [$st, $d] = $rest('PUT', '/studio/sponsors', ['main' => [['id' => $sid, 'name' => 'Brauerei', 'logo' => ['id' => $l1], 'link' => 'https://brauerei.example', 'description' => str_repeat('x', 301)]]], $q);
    $eq('Beschreibung über 300 Zeichen: 400 mit Feld', [400, 'main.0.description'], [$st, $d['data']['field'] ?? null]);
    [$st] = $rest('PUT', '/studio/sponsors', ['main' => [['id' => $sid, 'name' => 'Brauerei', 'logo' => ['id' => $l1], 'logo_dark' => ['id' => $l1d], 'link' => 'https://brauerei.example', 'description' => 'Frisch gezapft.', 'button_label' => 'Zum Biergarten']]], $q);
    $pub = TIX_Sponsor_Logos::owner_public($scope, $owner);
    $eq('eigener Knopf-Text; Knopf führt über den Klick-Link', [200, 'Zum Biergarten', true], [$st, $pub['main'][0]['button_label'] ?? null, strpos((string) $pub['main'][0]['link'], '/go') !== false]);
    TIX_Sponsor_Logos::store_snapshot(999999991, $pub);
    $sn = TIX_Sponsor_Logos::settings('event', 999999991)['pub'] ?? [];
    $wpdb->delete(TIX_Sponsor_Logos::t('settings'), ['scope' => 'event', 'owner_id' => 999999991]);
    $eq('Schnappschuss behält Beschreibung und Knopf', ['Frisch gezapft.', 'Zum Biergarten'], [$sn['main'][0]['description'] ?? null, $sn['main'][0]['button_label'] ?? null]);
    [$st, $d] = $rest('PUT', '/studio/sponsors', ['partners' => [['name' => 'Bäcker', 'logo' => ['id' => $l2], 'description' => 'wird ignoriert'], ['name' => 'Alt', 'logo' => ['id' => $l3], 'active' => false]]], $q);
    $eq('weitere Sponsoren: keine Beschreibung', null, $d['partners'][0]['description'] ?? null);

    // Event-Modus
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Sponsor-Event']); $posts[] = $ev;
    if ($multi) update_post_meta($ev, '_tix_organizer_id', $org);
    $eq('Event erbt', 'Brauerei', TIX_Sponsor_Logos::event_public($ev)['main'][0]['name'] ?? null);
    [$st, $d] = $rest('PUT', "/events/$ev/sponsors", ['mode' => 'own', 'main' => [['name' => 'Festival-Partner', 'logo' => ['id' => $l2]]], 'partners' => []]);
    $eq('Event eigene (ersetzt)', [200, 'own', 'Festival-Partner', 0], [$st, $d['mode'] ?? null, $d['effective']['main'][0]['name'] ?? null, count($d['effective']['partners'] ?? [1])]);
    TIX_Public_Events::flush(); wp_cache_flush();
    [, $pe] = $rest('GET', "/public/events/$ev");
    $pe = $pe['event'] ?? $pe;
    $eq('/public/events/{id}: sponsors', 'Festival-Partner', $pe['sponsors']['main'][0]['name'] ?? null);
    $rest('PUT', "/events/$ev/sponsors", ['mode' => 'none']);
    $eq('Event keine', null, TIX_Sponsor_Logos::event_public($ev));
    $rest('PUT', "/events/$ev/sponsors", ['mode' => 'inherit']);

    // Klicks
    $x = new WP_REST_Request('GET', "/tixomat/v1/public/sponsors/$sid/go"); $x->set_query_params(['src' => 'app']); $x->set_header('user_agent', 'Mozilla/5.0 (iPhone)');
    $r = rest_do_request($x);
    $x2 = new WP_REST_Request('GET', "/tixomat/v1/public/sponsors/$sid/go"); $x2->set_header('user_agent', 'Googlebot/2.1');
    rest_do_request($x2);
    [, $d] = $rest('GET', '/studio/sponsors', null, $q);
    $eq('Klick: 302 auf das Ziel, gezählt (Bot nicht), im Studio sichtbar', [302, 'https://brauerei.example', 1, 1], [$r->get_status(), $r->get_headers()['Location'] ?? null, $d['main'][0]['clicks']['total'] ?? null, ((array) ($d['main'][0]['clicks']['by_source'] ?? []))['app'] ?? null]);
    $eq('Klick ohne Link: 404', 404, $rest('GET', '/public/sponsors/999999999/go')[0]);

    // Mail
    $h = TIX_Sponsor_Logos::mail_html('', ['kind' => 'customer', 'event_id' => $ev]);
    $eq('Mail: Leiste mit Logo + Klick-Link src=mail', [true, true], [strpos($h, 'Brauerei') !== false, strpos($h, 'src=mail') !== false]);
    $eq('Mail: Admin-Mails ohne', '', TIX_Sponsor_Logos::mail_html('', ['kind' => 'admin', 'event_id' => $ev]));
    $full = TIX_Mail_Layout::render(['title' => 'T', 'kind' => 'customer', 'event_id' => $ev, 'blocks' => []]);
    $eq('Mail-Layout bindet die Leiste ein', true, strpos($full, 'Unser Hauptsponsor') !== false);

    // Ticket
    $gd = imagecreatetruecolor(600, 300);
    $g2 = TIX_Sponsor_Logos::ticket_gd($gd, $ev);
    $eq('Ticket/PDF: Leiste unten (höher als vorher)', true, imagesy($g2) > 300);
    imagedestroy($g2);
    $rest('PUT', '/studio/sponsors', ['places' => ['ticket' => false, 'mail' => false]], $q);
    $gd = imagecreatetruecolor(600, 300); $g3 = TIX_Sponsor_Logos::ticket_gd($gd, $ev);
    $eq('Ort aus: Ticket und Mail ohne', [300, ''], [imagesy($g3), TIX_Sponsor_Logos::mail_html('', ['kind' => 'customer', 'event_id' => $ev])]);
    imagedestroy($g3);

    // Schnappschuss (Spiegel/Kopie)
    $mir = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Sponsor-Spiegel']); $posts[] = $mir;
    update_post_meta($mir, $multi ? '_tix_syndicated' : '_tix_platform_co', $multi ? '1' : '6453');
    $eq('Spiegel ohne Schnappschuss: nichts', null, TIX_Sponsor_Logos::event_public($mir));
    TIX_Sponsor_Logos::store_snapshot($mir, ['places' => ['web_event' => true], 'main' => [['name' => 'Quelle-Sponsor', 'logo' => 'https://quelle.test/l.png', 'link' => 'https://quelle.test/wp-json/tixomat/v1/public/sponsors/5/go', 'target' => 'https://ziel.test']], 'partners' => [['name' => 'ohne Logo']]]);
    $sn = TIX_Sponsor_Logos::event_public($mir);
    $eq('Schnappschuss: Liste der Quelle, Link zählt bei der Quelle, ungültige verworfen', ['Quelle-Sponsor', 'https://quelle.test/wp-json/tixomat/v1/public/sponsors/5/go', 0], [$sn['main'][0]['name'] ?? null, $sn['main'][0]['link'] ?? null, count($sn['partners'] ?? [1])]);

    // Bearbeitungssperre gilt für Event-Sponsoren
    if (class_exists('TIX_Edit_Lock')) {
        $B = wp_insert_user(['user_login' => 'spb' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(20), 'user_email' => 'spb' . wp_rand(1000, 9999) . '@example.test', 'role' => 'administrator', 'display_name' => 'Bernd']); $users[] = $B;
        [, $lk] = $rest('POST', "/events/$ev/lock", []);
        wp_set_current_user($B);
        [$st, $d] = $rest('PUT', "/events/$ev/sponsors", ['mode' => 'none']);
        $eq('Event-Sponsoren: fremde Bearbeitungssperre → 409', [409, 'tix_event_locked'], [$st, $d['code'] ?? null]);
        wp_set_current_user($adm->ID);
        $rest('DELETE', "/events/$ev/lock", ['token' => $lk['token'] ?? '']);
    }

    // Rechte: Team ohne Inhaber-Recht darf nicht ändern
    $tm = wp_insert_user(['user_login' => 'spt' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(20), 'user_email' => 'spt' . wp_rand(1000, 9999) . '@example.test', 'role' => 'tix_staff']); $users[] = $tm;
    wp_set_current_user($tm);
    $eq('Mitarbeiter: Ändern verboten', true, in_array($rest('PUT', '/studio/sponsors', ['enabled' => false], $q)[0], [401, 403], true));
    wp_set_current_user($adm->ID);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    if (isset($scope)) {
        $wpdb->delete(TIX_Sponsor_Logos::t('logos'), ['scope' => $scope, 'owner_id' => $owner]);
        foreach ((array) ($keep_rows ?? []) as $r) $wpdb->insert(TIX_Sponsor_Logos::t('logos'), $r);
        if (!empty($keep_settings)) TIX_Sponsor_Logos::put_settings($scope, $owner, $keep_settings); else $wpdb->delete(TIX_Sponsor_Logos::t('settings'), ['scope' => $scope, 'owner_id' => $owner]);
    }
    foreach ($posts as $p) { $wpdb->delete(TIX_Sponsor_Logos::t('settings'), ['scope' => 'event', 'owner_id' => $p]); $wpdb->delete(TIX_Sponsor_Logos::t('logos'), ['scope' => 'event', 'owner_id' => $p]); if (class_exists('TIX_Edit_Lock')) $wpdb->delete(TIX_Edit_Lock::table(), ['event_id' => $p]); wp_delete_post($p, true); }
    foreach ($atts as $a) wp_delete_attachment($a, true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $u) wp_delete_user($u);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
