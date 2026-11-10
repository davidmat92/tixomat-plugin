<?php
/** Homepage neu laden nach Änderungen (1.38.732). wp eval-file …/test-web-revalidate-site-wp.php (Einzel-Seite und Plattform) */
if (!defined('ABSPATH') || !class_exists('TIX_Web_Revalidate') || !method_exists('TIX_Web_Revalidate', 'touch_site')) { echo "Tixomat ab 1.38.732 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = json_decode(wp_json_encode(rest_get_server()->response_to_data($res, false)), true); return [$res->get_status(), is_array($d) ? $d : []]; };
$GLOBALS['rv'] = [];
add_filter('pre_http_request', function ($pre, $a, $url) {
    if (strpos($url, '/api/revalidate') === false) return $pre;
    $GLOBALS['rv'][] = ['url' => $url, 'headers' => $a['headers'] ?? [], 'body' => json_decode((string) ($a['body'] ?? ''), true), 'blocking' => $a['blocking'] ?? true];
    return ['headers' => [], 'body' => '{"ok":true}', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
}, 10, 3);
$go = function () { $GLOBALS['rv'] = []; TIX_Web_Revalidate::flush(); return $GLOBALS['rv']; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$keep = ['tix_web_revalidate_secret' => get_option('tix_web_revalidate_secret', null), 'tix_web_revalidate_url' => get_option('tix_web_revalidate_url', null), 'tix_studio_brand' => get_option('tix_studio_brand', null)];
$a = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user($a[0]->ID);
$posts = [];
try {
    delete_option('tix_web_revalidate_secret'); delete_option('tix_web_revalidate_url');
    $go();
    if (!$multi) {
        $host = TIX_Web_Revalidate::site_host();
        $b = (array) get_option('tix_studio_brand', []); $b['accent'] = '#12' . substr((string) wp_rand(1000, 9999), 0, 4); update_option('tix_studio_brand', $b, false);
        $c = $go();
        $eq('Marke geändert, ohne Schlüssel: ein Aufruf an https://<host>/api/revalidate/ mit domains', [1, 'https://' . $host . '/api/revalidate/', [$host], false, false],
            [count($c), $c[0]['url'] ?? '', $c[0]['body']['domains'] ?? [], isset($c[0]['headers']['x-revalidate-secret']), $c[0]['blocking'] ?? true]);
        update_option('tix_web_revalidate_secret', 'geheim-123', false);
        TIX_Web_Revalidate::touch_site();
        $c = $go();
        $eq('Mit Schlüssel: Kopfzeile mit', 'geheim-123', $c[0]['headers']['x-revalidate-secret'] ?? '');
        delete_option('tix_web_revalidate_secret');
        update_option('tix_web_revalidate_url', 'https://neu.beispiel.test/api/revalidate/', false);
        TIX_Web_Revalidate::touch_site();
        $eq('Alte Anweisung neu.<domain> überholt → eigener Host', 'https://' . $host . '/api/revalidate/', ($go()[0]['url'] ?? ''));
        delete_option('tix_web_revalidate_url');
        $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'RVTEST Party', 'meta_input' => ['_tix_date_start' => wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS)]]); $posts[] = $ev;
        $c = $go();
        $eq('Event angelegt: genau ein Aufruf für die Homepage', [1, [$host]], [count($c), $c[0]['body']['domains'] ?? []]);
        $go();
        $pkg = get_option(TIX_Website::OPT_ENABLED, null); update_option(TIX_Website::OPT_ENABLED, '1', false); $go();
        [$s, $g] = $rest('GET', '/studio/website');
        $rest('POST', '/studio/website', ['updated_at' => (string) $g['updated_at'], 'website' => ['template' => 'klar']]);
        $eq('Website-Entwurf speichern: kein Aufruf', 0, count($go()));
        [, $g] = $rest('GET', '/studio/website');
        $rest('POST', '/studio/website', ['updated_at' => (string) $g['updated_at'], 'publish' => true]);
        $c = $go();
        $eq('Website veröffentlichen: Aufruf', [true, true], [count($c) === 1, in_array($host, (array) ($c[0]['body']['domains'] ?? []), true)]);
        [, $g] = $rest('GET', '/studio/website');
        $rest('POST', '/studio/website', ['updated_at' => (string) $g['updated_at'], 'website' => (array) ($g['website'] ?: []), 'publish' => true]);
        if ($pkg === null) delete_option(TIX_Website::OPT_ENABLED); else update_option(TIX_Website::OPT_ENABLED, $pkg, false);
        $go();
        [$s] = $rest('POST', '/studio/config', ['font' => '']);
        $eq('POST /studio/config: Aufruf', [200, 1], [$s, count($go())]);
        $eq('nichts geändert: kein Aufruf', 0, count($go()));
    } else {
        $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'RVTEST Org']); $posts[] = $org;
        $eq('Plattform ohne Schlüssel: kein Aufruf (wie bisher)', 0, count($go()));
        update_option('tix_web_revalidate_secret', 'geheim-123', false);
        update_option('tix_web_revalidate_url', 'https://plat.test/api/revalidate/', false);
        TIX_Web_Revalidate::touch_org($org);
        $c = $go();
        $eq('Plattform mit Schlüssel: Veranstalter-Auftrag wie bisher', [true, 'geheim-123'], [isset($c[0]['body']['organizer']), $c[0]['headers']['x-revalidate-secret'] ?? '']);
        TIX_Web_Revalidate::touch_site();
        $eq('touch_site auf der Plattform wirkungslos', 0, count($go()));
    }
} finally {
    foreach ($posts as $p) wp_delete_post($p, true);
    foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
    TIX_Web_Revalidate::flush();
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
