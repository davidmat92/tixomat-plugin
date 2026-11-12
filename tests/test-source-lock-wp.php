<?php
/**
 * Geteilte Events überall änderbar – Durchgriff an den Ersteller (1.38.788, ersetzt die Sperre 1.38.787).
 * HTTP zur Quelle hier simuliert (pre_http_request); echter Weg über zwei Seiten: tests/test-co-remote-e2e.php.
 *   wp eval-file …/test-source-lock-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Source_Lock')) { echo "Tixomat ab 1.38.788 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); return [$res->get_status(), $res->get_data(), $res->get_headers()]; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$p = []; $calls = [];
$keep = ['tix_settings' => get_option('tix_settings')];
add_filter('pre_http_request', function ($pre, $args, $url) use (&$calls) {
    if ($pre !== false) return $pre;
    if (strpos($url, '/partner/source/') === false && strpos($url, '/partner/co-events/') === false) return $pre;
    $b = json_decode((string) $args['body'], true);
    $calls[] = ['url' => $url, 'body' => $b, 'headers' => $args['headers']];
    $sid = intval(preg_replace('#.*/(?:source|co-events)/(\d+).*#', '$1', $url));
    return ['headers' => [], 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null,
        'body' => wp_json_encode(['ok' => true, 'status' => 200, 'data' => ['ok' => true, 'event' => ['id' => $sid, 'url' => 'https://quelle.test/events/x/']]])];
}, 10, 3);
try {
    $adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);
    $own = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SL Eigen']); $p[] = $own;
    [$st] = $rest('POST', "/events/$own", ['timetable' => ['hidden' => true]]);
    $eq('eigenes Event: lokal wie bisher', [200, '1', 0], [$st, get_post_meta($own, '_tix_timetable_hidden', true), count($calls)]);
    if ($multi) {
        echo "== evendis-Spiegel\n";
        $partner = 'sltest' . wp_rand(100, 999);
        $keep['tix_partners'] = get_option(TIX_Partners::OPTION, []);
        $ps = (array) $keep['tix_partners'];
        $ps[$partner] = ['name' => 'Quelle', 'api_base' => 'https://quelle.test/wp-json/tixomat/v1', 'key_out' => str_repeat('k', 32), 'key_in' => str_repeat('i', 32), 'organizer_id' => 0];
        update_option(TIX_Partners::OPTION, $ps);
        $mir = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SL Spiegel']); $p[] = $mir;
        foreach (['_tix_syndicated' => '1', '_tix_source_site' => 'KitchenKlub', '_tix_source_id' => 1171, '_tix_source_partner' => $partner, '_tix_timetable_hidden' => '0'] as $k => $v) update_post_meta($mir, $k, $v);
        [$st, $d, $hd] = $rest('POST', "/events/$mir", ['timetable' => ['hidden' => true]]);
        $c = end($calls);
        $eq('Admin: Änderung geht an die Quelle (Betreiber-Durchgriff), Pfad mit Quell-ID, Person im actor', [200, 'operator', true, '/events/1171', true, $adm->display_name],
            [$st, $hd['X-Tix-Forwarded'] ?? null, strpos((string) ($c['url'] ?? ''), '/partner/source/1171') !== false, $c['body']['path'] ?? null, ($c['body']['body']['timetable']['hidden'] ?? null) === true, $c['body']['actor']['name'] ?? null]);
        $eq('Antwort: Event-ID zurück auf den Spiegel; lokal nichts geschrieben (kommt per Verteilung)', [$mir, '0'], [intval($d['event']['id'] ?? 0), get_post_meta($mir, '_tix_timetable_hidden', true)]);
        $eq('plattform-eigene Sparte bleibt lokal (kein Durchgriff)', [true, count($calls)], [$rest('POST', "/events/$mir", ['event_category' => null])[0] !== 409, count($calls)]);
        $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'SL Fremd']); $p[] = $org;
        $om = wp_insert_user(['user_login' => 'slorg' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(20), 'user_email' => 'slorg' . wp_rand(1000, 9999) . '@example.test', 'role' => 'tix_organizer']);
        update_user_meta($om, TIX_App_Scope::META_TEAM_ORG, $org); update_user_meta($om, '_tix_team_role', 'admin');
        wp_set_current_user($om);
        $st = $rest('POST', "/events/$mir", ['title' => 'X'])[0];
        $eq('Veranstalter ohne Co-Zugriff: kein Durchgriff (403)', true, in_array($st, [403, 401], true));
        wp_set_current_user($adm->ID);
        require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($om);
        [, $d] = $rest('GET', "/events/$mir/edit"); $ed = $d['event'] ?? $d;
        $eq('Editor: source_owned editable via operator', ['KitchenKlub', true, 'operator'], [$ed['source_owned']['site'] ?? null, $ed['source_owned']['editable'] ?? null, $ed['source_owned']['via'] ?? null]);
    } else {
        echo "== Kopie auf Kunden-Seite\n";
        $s = (array) get_option('tix_settings', []); $s['syndication_api_url'] = 'https://evendis.test/wp-json/tixomat/v1'; $s['syndication_api_key'] = str_repeat('s', 32); $s['syndication_enabled'] = 1; update_option('tix_settings', $s);
        $cp = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SL Kopie']); $p[] = $cp;
        foreach (['_tix_platform_co' => '6453', '_tix_platform_co_source' => 'kitchenklub.de', '_tix_platform_co_access' => '1'] as $k => $v) update_post_meta($cp, $k, $v);
        $before = count($calls);
        [$st, $d, $hd] = $rest('POST', "/events/$cp", ['timetable' => ['hidden' => true]]);
        $c = end($calls);
        if (TIX_Syndication_Push::is_configured()) {
            $eq('Kopie mit Zugriff: an die Plattform (/partner/co-events/{pid}/call), Pfad mit Plattform-ID, ID und Link zurück auf die Kopie', [200, 'platform', true, '/events/6453', $cp, true],
                [$st, $hd['X-Tix-Forwarded'] ?? null, strpos((string) ($c['url'] ?? ''), '/partner/co-events/6453/call') !== false, $c['body']['path'] ?? null, intval($d['event']['id'] ?? 0), strpos((string) ($d['event']['url'] ?? ''), (string) wp_parse_url(home_url(), PHP_URL_HOST)) !== false]);
        } else {
            $eq('Kopie: ohne Plattform-Verbindung 409', 409, $st);
        }
        update_post_meta($cp, '_tix_platform_co_access', '0');
        [$st, $d] = $rest('POST', "/events/$cp", ['title' => 'X']);
        $eq('Kopie ohne Zugriff: 403 tix_co_no_access, kein Aufruf', [403, 'tix_co_no_access'], [$st, $d['code'] ?? null]);
        [, $d] = $rest('GET', "/events/$cp/edit"); $ed = $d['event'] ?? $d;
        $eq('Editor: source_owned nicht änderbar mit Grund', [false, true], [$ed['source_owned']['editable'] ?? null, (string) ($ed['source_owned']['blocked_reason'] ?? '') !== '']);
    }
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    remove_all_filters('pre_http_request');
    foreach ($keep as $k => $v) update_option($k, $v);
    foreach ($p as $id) wp_delete_post($id, true);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
