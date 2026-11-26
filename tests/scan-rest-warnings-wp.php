<?php
/**
 * Audit 26.11.: alle GET-Routen tixomat/v1 ohne Pfad-Parameter (plus {id}-Routen mit einer echten Event-ID) als Gast und als Admin
 * aufrufen und PHP-Warnungen/Notices/Deprecated aus dem Plugin sammeln. Auf kitchenklub.de (display_errors an) zerstören sie das JSON.
 *   wp eval-file …/scan-rest-warnings-wp.php
 */
if (!defined('ABSPATH')) return;
$hits = [];
set_error_handler(function ($no, $str, $file, $line) use (&$hits) {
    if (strpos($file, '/plugins/tixomat/') === false) return false;
    $k = basename($file) . ':' . $line . ' ' . $str;
    $hits[$k] = ($hits[$k] ?? 0) + 1;
    return true;
}, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED);
$eid = intval(get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids'])[0] ?? 0);
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 0;
$routes = rest_get_server()->get_routes('tixomat/v1');
$skip = '#(export|download|pdf|ics|csv|stream|_export|relay|cron|backup|confirm|unsubscribe|abmelden|redirect|/go\b|/r\b|oauth|callback|track)#i';
$n = 0;
add_filter('wp_redirect', function ($l) { throw new RuntimeException('redirect'); }, -9999); // Weiterleitungs-Routen nicht beenden lassen
foreach ([0, intval($admin)] as $uid) {
    wp_set_current_user($uid);
    foreach ($routes as $path => $handlers) {
        $p = $path;
        if (preg_match($skip, $p)) continue;
        if (preg_match('#\(\?P<id>\\\\d\+\)#', $p)) $p = preg_replace('#\(\?P<id>\\\\d\+\)#', (string) $eid, $p);
        if (strpos($p, '(?P<') !== false) continue;
        $get = false;
        foreach ($handlers as $h) if (!empty($h['methods']['GET'])) $get = true;
        if (!$get) continue;
        $n++;
        try { ob_start(); rest_do_request(new WP_REST_Request('GET', $p)); ob_end_clean(); }
        catch (\Throwable $e) { ob_end_clean(); if ($e->getMessage() === 'redirect') continue; $hits['FATAL ' . $p . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()] = 1; }
    }
}
restore_error_handler();
wp_set_current_user(0);
echo "$n Aufrufe, " . count($hits) . " Fundstellen\n";
foreach ($hits as $k => $c) echo "$c× $k\n";
