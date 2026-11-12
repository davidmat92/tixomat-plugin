<?php
/**
 * Co-Kopien auf Kunden-Seiten zeitnah abgleichen (1.38.772): Änderung am Event (Verteilung/Studio) plant je Kunden-Seite
 * einen Abgleich in ~20 s, je Seite höchstens einen wartenden. Nur Plattform.   wp eval-file …/test-co-site-sync-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Co_Sites') || !method_exists('TIX_Co_Sites', 'sync_sites_of_event')) { echo "Tixomat ab 1.38.772 nötig\n"; return; }
if (!(class_exists('TIX_App_Scope') && TIX_App_Scope::multi())) { echo "Nur Plattform.\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$p = [];
try {
    $site = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'CSS Kunden-Seite']); $p[] = $site;
    update_post_meta($site, TIX_Co_Organizer::ORG_KIND, TIX_Co_Sites::KIND);
    $plain = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'CSS Veranstalter']); $p[] = $plain;
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'CSS Event']); $p[] = $ev;
    wp_clear_scheduled_hook(TIX_Co_Sites::SYNC_HOOK, [$site]); wp_clear_scheduled_hook(TIX_Co_Sites::SYNC_HOOK, [$plain]);
    $eq('ohne Co: nichts geplant', 0, TIX_Co_Sites::sync_sites_of_event($ev));
    update_post_meta($ev, '_tix_co_mirror', [['oid' => $site, 'access' => true, 'token' => 'x'], ['oid' => $plain, 'access' => false, 'token' => 'y']]);
    $eq('Spiegel mit Kunden-Seite + normalem Veranstalter: nur die Seite', [1, true, false], [TIX_Co_Sites::sync_sites_of_event($ev), (bool) wp_next_scheduled(TIX_Co_Sites::SYNC_HOOK, [$site]), (bool) wp_next_scheduled(TIX_Co_Sites::SYNC_HOOK, [$plain])]);
    $t1 = wp_next_scheduled(TIX_Co_Sites::SYNC_HOOK, [$site]);
    $eq('in ~20 s', true, $t1 >= time() + 5 && $t1 <= time() + 25);
    TIX_Co_Sites::sync_sites_of_event($ev);
    $cnt = 0; foreach (_get_cron_array() as $h) foreach ((array) ($h[TIX_Co_Sites::SYNC_HOOK] ?? []) as $x) if (($x['args'] ?? []) === [$site]) $cnt++;
    $eq('zweite Änderung: weiterhin genau ein wartender Abgleich', 1, $cnt);
    wp_clear_scheduled_hook(TIX_Co_Sites::SYNC_HOOK, [$site]);
    wp_update_post(['ID' => $ev, 'post_title' => 'CSS Event neu']);
    $eq('Speichern im Studio/WP (save_post_event) plant ebenfalls', true, (bool) wp_next_scheduled(TIX_Co_Sites::SYNC_HOOK, [$site]));
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($p as $id) { wp_clear_scheduled_hook(TIX_Co_Sites::SYNC_HOOK, [$id]); wp_delete_post($id, true); }
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
