<?php
/**
 * Plattformgebühr 2,9 % + 0,49 € (1.38.747, vorher kurz 0,30 € in 1.38.746): Vorgaben Katalog, Kanal-Gebühr, Preis-Wächter, Demo-Etikett, einmalige Umstellung.
 *   wp eval-file …/test-fee-rate-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Price_Watch') || !defined('TIX_Price_Watch::REF_PERCENT')) { echo "Tixomat ab 1.38.747 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function fr_pw() {
    $o = (array) get_option('tix_settings', []);
    return [floatval($o['price_watch_ref_percent'] ?? -1), floatval($o['price_watch_ref_fixed'] ?? -1)];
}
function fr_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
$bk_s = get_option('tix_settings', null);
$bk_c = get_option(TIX_Channel_Fee::OPTION, null);
$bk_m = get_option(TIX_Price_Watch::OPT_RATE_MIGRATED, null);
$bk_cat = get_option('tix_offer_catalog', null);
try {
    // Katalog (Vorgabe)
    delete_option('tix_offer_catalog');
    $it = TIX_Platform_Offers::catalog_item('tickets');
    fr_eq('Katalog „Tickets über evendis“: 2,9 % + 0,49 €, inkl. MwSt., Preis-Hinweis vorhanden', [2.9, 0.49, 'inkl. MwSt.', 'je verkauftem Ticket, inkl. MwSt. – Zahlungskosten enthalten, evendis trägt sie'], [floatval($it['fee_percent']), floatval($it['fee_fixed']), $it['tax_note'], $it['price_note']]);
    // Kanal-Gebühr (Vorgabe, Einzelsatz bleibt)
    update_option(TIX_Channel_Fee::OPTION, ['new_partners' => 0], false);
    $cs = TIX_Channel_Fee::settings();
    fr_eq('Kanal-Gebühr Vorgabe 2,9 % + 0,49 €', [2.9, 0.49], [$cs['percent'], $cs['fixed']]);
    update_option(TIX_Channel_Fee::OPTION, ['percent' => 2.5, 'fixed' => 0.59], false);
    $cs = TIX_Channel_Fee::settings();
    fr_eq('Kanal-Gebühr: gespeicherter Satz bleibt (nicht rückwirkend)', [2.5, 0.59], [$cs['percent'], $cs['fixed']]);
    // Demo-Etikett
    if (class_exists('TIX_Demo_Access')) fr_eq('Demo: Provisionskunde 2,9 % + 0,49 €', '2,9 % + 0,49 € je Ticket', TIX_Demo_Access::models_info()[0]['price']);
    // Preis-Wächter: Vorgabe ohne gespeicherten Wert
    $s = is_array($bk_s) ? $bk_s : [];
    unset($s['price_watch_ref_percent'], $s['price_watch_ref_fixed'], $s['price_watch_shop_monthly'], $s['price_watch_enabled']);
    update_option('tix_settings', $s);
    $ps = TIX_Price_Watch::settings();
    fr_eq('Preis-Wächter Vorgabe 2,9 % + 0,49 €', [2.9, 0.49], [$ps['ref_percent'], $ps['ref_fixed']]);
    // Umstellung: alter Satz → neu (tix_get_settings hat einen statischen Cache, daher direkt aus der Option lesen)
    $s['price_watch_ref_percent'] = 2.5; $s['price_watch_ref_fixed'] = 0.59;
    update_option('tix_settings', $s);
    delete_option(TIX_Price_Watch::OPT_RATE_MIGRATED);
    TIX_Price_Watch::migrate_rate();
    fr_eq('Umstellung: gespeichert 2,5/0,59 → 2,9/0,49, Merker gesetzt', [2.9, 0.49, true], array_merge(fr_pw(), [(bool) get_option(TIX_Price_Watch::OPT_RATE_MIGRATED)]));
    // eigener Wert bleibt
    $s['price_watch_ref_percent'] = 3.5; $s['price_watch_ref_fixed'] = 0.45;
    update_option('tix_settings', $s);
    delete_option(TIX_Price_Watch::OPT_RATE_MIGRATED);
    TIX_Price_Watch::migrate_rate();
    fr_eq('Umstellung: eigener Satz 3,5/0,45 bleibt', [3.5, 0.45], fr_pw());
    // Zwischenstand aus 1.38.746 (2,9/0,30) → 2,9/0,49
    $s['price_watch_ref_percent'] = 2.9; $s['price_watch_ref_fixed'] = 0.3;
    update_option('tix_settings', $s);
    delete_option(TIX_Price_Watch::OPT_RATE_MIGRATED);
    TIX_Price_Watch::migrate_rate();
    fr_eq('Umstellung: Zwischenstand 2,9/0,30 → 2,9/0,49', [2.9, 0.49], fr_pw());
    delete_option(TIX_Price_Watch::OPT_RATE_MIGRATED);
    $s['price_watch_ref_percent'] = 2.5; $s['price_watch_ref_fixed'] = 0.59;
    update_option('tix_settings', $s);
    TIX_Price_Watch::migrate_rate();
    // nur einmal
    $s['price_watch_ref_percent'] = 2.5; $s['price_watch_ref_fixed'] = 0.59;
    update_option('tix_settings', $s);
    TIX_Price_Watch::migrate_rate();
    $ps = ['ref_percent' => fr_pw()[0], 'ref_fixed' => fr_pw()[1]];
    fr_eq('Umstellung läuft nur einmal (danach bewusst gesetzter alter Satz bleibt)', [2.5, 0.59], [$ps['ref_percent'], $ps['ref_fixed']]);
} catch (Throwable $e) {
    $GLOBALS['t_fails']++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    if ($bk_s === null) delete_option('tix_settings'); else update_option('tix_settings', $bk_s);
    if ($bk_c === null) delete_option(TIX_Channel_Fee::OPTION); else update_option(TIX_Channel_Fee::OPTION, $bk_c, false);
    if ($bk_m === null) delete_option(TIX_Price_Watch::OPT_RATE_MIGRATED); else update_option(TIX_Price_Watch::OPT_RATE_MIGRATED, $bk_m, false);
    if ($bk_cat === null) delete_option('tix_offer_catalog'); else update_option('tix_offer_catalog', $bk_cat, false);
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
