<?php
/**
 * M2 Verkaufsprognose (1.38.811): reine Rechnung (Vergleich, einfache Hochrechnung, Kapazität, Ausverkauft-Tag), Route.
 *   wp eval-file …/test-forecast-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Forecast')) { echo "Tixomat ab 1.38.811 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$near = function ($l, $e, $a, $tol) use (&$f, &$n) { $n++; if (abs($e - $a) <= $tol) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet ≈$e, ist $a\n"; };
$rest = function ($m, $r, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$N = 60;
// Kurve: 10 % vorab, dann 1 je Tag … steigend zum Event hin (gesamt skaliert)
$curve = function ($scale) use ($N) { $t = []; for ($i = 0; $i < $N; $i++) $t[] = (int) round($scale * (1 + $i / 10)); return ['before' => (int) round($scale * 10), 'tickets' => $t]; };
$comp = [$curve(2), $curve(2), $curve(2), $curve(2)];
$full = $curve(1);
// Ziel-Event: bis 20 Tage vor dem Event wie $full, danach noch nichts
$target = $full; for ($i = $N - 20; $i < $N; $i++) $target['tickets'][$i] = 0;
$idx = $N - 1 - 20;
$p = TIX_Forecast::project($target, 20, $comp, 0);
$eq('Vergleich: Methode, genug Daten', ['comparison', false, 4], [$p['method'], $p['low_data'], $p['comparisons_used']]);
$near('Vergleich: Hochrechnung = Ist-Gesamt der gleich verlaufenden Kurve', TIX_Forecast::total($full), $p['projected'], TIX_Forecast::total($full) * 0.01);
$p2 = TIX_Forecast::project($target, 20, array_slice($comp, 0, 2), 0);
$eq('Unter 3 Vergleichen → einfache Hochrechnung, wenig Daten', ['linear', true], [$p2['method'], $p2['low_data']]);
$p2 = TIX_Forecast::project($target, 20, [], 0);
$eq('Ohne Vergleich → einfache Hochrechnung', ['linear', true], [$p2['method'], $p2['low_data']]);
$v7 = (TIX_Forecast::cum($target, $idx) - TIX_Forecast::cum($target, $idx - 7)) / 7;
$near('Einfach: Tempo der letzten 7 Tage × 20 Tage', TIX_Forecast::cum($target, $idx) + $v7 * 20, $p2['projected'], 0.01);
$cap = (int) round(TIX_Forecast::cum($target, $idx) + 0.5 * (TIX_Forecast::total($full) - TIX_Forecast::cum($target, $idx)));
$p3 = TIX_Forecast::project($target, 20, $comp, $cap);
$day = TIX_Forecast::sellout_day($p3['path'], $cap);
$eq('Kapazität begrenzt Prognose, Ausverkauft-Tag vor dem Event', [$cap, true], [(int) round($p3['projected']), $day !== null && $day > 0 && $day < 20]);
$eq('Event heute → keine Prognose', 'none', TIX_Forecast::project($target, 0, $comp, 0)['method']);
$eq('Quantil (Median)', 2.5, TIX_Forecast::quantile([1, 2, 3, 4], 0.5));

// Route
$posts = [];
try {
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    wp_set_current_user($adm);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Prognose-Event', 'meta_input' => ['_tix_date_start' => wp_date('Y-m-d', time() + 30 * DAY_IN_SECONDS),
        '_tix_ticket_categories' => [['name' => 'Normal', 'price' => 10, 'qty' => 500, 'phases' => [['name' => 'Early Bird', 'price' => 8, 'until' => wp_date('Y-m-d', time() + 3 * DAY_IN_SECONDS)]]]]]]); $posts[] = $ev;
    [$st, $d] = $rest('GET', '/organizer/stats/forecast', ['event' => $ev, 'goal' => 300]);
    $eq('Route: 200, Tage bis Event, Ziel, Hinweise als Liste', [200, 30, 300, true], [$st, $d['days_left'] ?? null, $d['goal'] ?? null, is_array($d['hints'] ?? null)]);
    $codes = array_column((array) ($d['hints'] ?? []), 'code');
    $eq('Ziel verfehlt → Kampagne, Preisphase endet bald', [true, true, true], [in_array('goal_miss', $codes, true), in_array('start_campaign', $codes, true), in_array('extend_price_phase', $codes, true)]);
    [$st] = $rest('GET', '/organizer/stats/forecast', ['event' => $ev, 'goal' => 0]);
    $eq('Ungültiges Ziel → 400', 400, $st);
    [$st] = $rest('GET', '/organizer/stats/forecast', ['event' => 99999999]);
    $eq('Unbekanntes Event → 404', 404, $st);
    $staff = intval(get_users(['role' => 'tix_staff', 'number' => 1, 'fields' => 'ID'])[0] ?? 0);
    wp_set_current_user($staff ?: 0);
    $eq('Mitarbeiter/anonym → verboten', true, in_array($rest('GET', '/organizer/stats/forecast', ['event' => $ev])[0], [401, 403], true));
    wp_set_current_user($adm);
    $b = TIX_Forecast::backtest(array_map('intval', get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => 300, 'fields' => 'ids'])), 7);
    $eq('Rückrechnung läuft (Liste + Mittel)', true, is_array($b['rows']) && array_key_exists('mean_abs_error', $b));
    echo "info  Rückrechnung Testdaten: n={$b['n']} (Vergleich {$b['n_comparison']}), Abweichung " . ($b['mean_abs_error'] === null ? '–' : round($b['mean_abs_error'] * 100, 1) . ' %') . "\n";
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($posts as $p) wp_delete_post($p, true);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
