<?php
/**
 * R3 Kalender-Abo (1.38.811): GET /public/calendar.ics je Seite/Veranstalter/Künstler, RFC-5545-Form.
 *   wp eval-file …/test-calendar-feed-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Calendar_Feed')) { echo "Tixomat ab 1.38.811 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$posts = [];
try {
    $date = wp_date('Y-m-d', time() + 12 * DAY_IN_SECONDS);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Feed; Event, Nr 1', 'meta_input' => ['_tix_date_start' => $date, '_tix_time_start' => '21:00', '_tix_time_end' => '03:00', '_tix_date_end' => wp_date('Y-m-d', time() + 13 * DAY_IN_SECONDS), '_tix_location' => 'Halle']]); $posts[] = $ev;
    if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
    $req = new WP_REST_Request('GET', '/tixomat/v1/public/calendar.ics');
    $res = rest_do_request($req);
    $ics = (string) $res->get_data();
    $eq('200, text/calendar, VCALENDAR', [200, true, true], [$res->get_status(), stripos((string) ($res->get_headers()['Content-Type'] ?? ''), 'text/calendar') === 0, strpos($ics, "BEGIN:VCALENDAR\r\n") === 0]);
    $eq('Event drin, Sonderzeichen maskiert, UID stabil', [true, true], [strpos($ics, 'SUMMARY:Feed\; Event\, Nr 1') !== false, strpos($ics, "UID:event-$ev@") !== false]);
    $eq('Zeitangabe RFC 5545 (DTSTART;TZID=… bzw. DTSTART:…Z)', 1, preg_match('/^DTSTART(;TZID=[A-Za-z_\/+-]+:\d{8}T\d{6}|:\d{8}T\d{6}Z)\r?$/m', $ics));
    update_post_meta($ev, '_tix_status', 'cancelled');
    if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
    $ics2 = TIX_Calendar_Feed::build([$ev], 'Test');
    $eq('Abgesagt → STATUS:CANCELLED', true, strpos($ics2, 'STATUS:CANCELLED') !== false);
    $eq('Leerer Kalender bleibt gültig', true, (bool) preg_match('/BEGIN:VCALENDAR.*END:VCALENDAR/s', TIX_Calendar_Feed::build([], 'Leer')));
    if (class_exists('TIX_Artists') && method_exists('TIX_Artists', 'event_ids')) {
        $r = new WP_REST_Request('GET', '/tixomat/v1/public/calendar.ics'); $r->set_query_params(['artist' => 99999999]);
        $eq('Unbekannter Künstler → leerer Kalender', false, strpos((string) rest_do_request($r)->get_data(), 'BEGIN:VEVENT') !== false);
    }
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($posts as $p) wp_delete_post($p, true);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
