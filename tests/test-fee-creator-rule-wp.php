<?php
/**
 * Servicegebühr bei Co-Events (1.38.759/760, User-Regeln): nimmt der Ersteller das Geld ein, gilt überall SEINE Gebührenregel.
 *   Plattform:    wp eval-file …/test-fee-creator-rule-wp.php   (creator_fee_rule: Spiegel → Regel der Quelle, eigenes Event → Plattform, Co nimmt ein → null)
 *   Einzel-Seite: wp eval-file …/test-fee-creator-rule-wp.php   (Kopie rechnet mit der Regel des Erstellers statt eigener)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Fees') || !method_exists('TIX_Fees', 'creator_fee_rule')) { echo "Tixomat ab 1.38.760 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function cr_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
$tmp = [];
$cats = [['name' => 'VVK', 'price' => 20, 'qty' => 100, 'stock' => 100]];
$kk_rule = ['fixed' => 1.5, 'percent' => 0, 'mode' => 'customer', 'label' => 'Vorverkaufsgebühr', 'rounding' => 'none', 'gateway' => ['mode' => 'organizer'], 'channel_mode' => 'customer'];
try {
    if (TIX_Fees::multi()) {
        echo "== Plattform\n";
        $mir = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'CR Spiegel', 'meta_input' => ['_tix_date_start' => '2027-10-01',
            '_tix_syndicated' => '1', '_tix_source_url' => 'https://kk.test/e/', '_tix_source_fee' => $kk_rule, '_tix_ticket_categories' => $cats]]);
        $tmp[] = $mir;
        cr_eq('Spiegel, Ersteller nimmt ein: Regel der Quelle (1,50 €, Vorverkaufsgebühr)', [1.5, 'customer', 'Vorverkaufsgebühr'], array_values(array_intersect_key(TIX_Fees::creator_fee_rule($mir) ?: [], ['fixed' => 1, 'mode' => 1, 'label' => 1])));
        $own = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'CR eigenes', 'meta_input' => ['_tix_date_start' => '2027-10-02', '_tix_ticket_categories' => $cats]]);
        $tmp[] = $own;
        $r = TIX_Fees::creator_fee_rule($own);
        cr_eq('eigenes Plattform-Event: Regel der Plattform (Satz, Modus, Bezeichnung vorhanden)', true, is_array($r) && isset($r['fixed'], $r['percent'], $r['mode'], $r['label']));
        cr_eq('Spiegel ohne Regel der Quelle: keine Gebühr', [0.0, 'organizer'], array_values(array_intersect_key(TIX_Fees::creator_fee_rule((function () use (&$tmp, $cats) {
            $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'CR alt', 'meta_input' => ['_tix_date_start' => '2027-10-03', '_tix_syndicated' => '1', '_tix_ticket_categories' => $cats]]);
            $tmp[] = $id; return $id; })()) ?: [], ['fixed' => 1, 'mode' => 1])));
        // Co nimmt ein → null
        if (class_exists('TIX_Co_Organizer')) {
            $oc = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'CR Co']); $tmp[] = $oc;
            TIX_Co_Organizer::set($own, $oc, true, 0);
            update_post_meta($own, TIX_Co_Money::META_OWNER, 'co');
            cr_eq('Co nimmt ein: trotzdem Regel des Erstellers (1.38.760)', true, is_array(TIX_Fees::creator_fee_rule($own)));
            update_post_meta($mir, '_tix_co_remote_oid', $oc); update_post_meta($mir, '_tix_co_remote_access', '1'); update_post_meta($mir, TIX_Co_Money::META_OWNER, 'co');
            cr_eq('Spiegel, Co nimmt ein: Regel der Quelle (1,50 €)', 1.5, floatval((TIX_Fees::creator_fee_rule($mir) ?: [])['fixed'] ?? 0));
            if (TIX_Co_Seller::sells_here($mir)) {
                $cfg = TIX_Fees::display_config($mir);
                cr_eq('Fall B (Plattform verkauft selbst): Kasse/Anzeige mit Regel der Quelle', [1.5, 'Vorverkaufsgebühr'], [$cfg['fee_fixed'], $cfg['fee_label']]);
                $f = TIX_Fees::calc_order_fees([['price' => 20, 'qty' => 2, 'event_id' => $mir, 'cat_index' => 0]]);
                cr_eq('Fall B Kasse: 2 × 1,50 € = 3,00 €', 3.0, floatval($f['customer_fee_line']));
            }
        }
    } else {
        echo "== Einzel-Seite (Co-Seite, z. B. KMT)\n";
        $bk = get_option('tix_settings', null);
        $s = is_array($bk) ? $bk : [];
        foreach (['fee_fixed' => 3.0, 'fee_percent' => 0, 'fee_mode' => 'customer', 'fee_label' => 'Servicegebühr', 'gateway_fee_mode' => 'organizer'] as $k => $v) $s[$k] = $v;
        update_option('tix_settings', $s);
        $cp = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'CR Kopie', 'meta_input' => ['_tix_date_start' => '2027-10-04', '_tix_platform_co' => 4711,
            '_tix_platform_co_sale' => '1', '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1', '_tix_ticket_categories' => $cats, '_tix_source_fee' => $kk_rule]]);
        $tmp[] = $cp;
        $cfg = TIX_Fees::display_config($cp);
        cr_eq('Kopie: Regel des Erstellers (1,50 € Vorverkaufsgebühr) statt eigener', [1.5, 'Vorverkaufsgebühr'], [$cfg['fee_fixed'], $cfg['fee_label']]);
        $pe = TIX_Public_Events::payload($cp, true);
        cr_eq('Kopie öffentlich: price_total 21,50, service_fee.label', [21.5, 'Vorverkaufsgebühr'], [$pe['categories'][0]['price_total'] ?? null, $pe['service_fee']['label'] ?? null]);
        TIX_Sync::apply_fee_display($cp, true);
        cr_eq('Kopie Preis-Felder: Endpreis 21,50', 21.5, floatval(get_post_meta($cp, '_tix_price_min_total', true)));
        delete_post_meta($cp, '_tix_source_fee');
        cr_eq('ältere Plattform ohne Regel: eigene Regel (unverändert wie bisher)', TIX_Fees::get_fee_config()['fee_mode'], TIX_Fees::display_config($cp)['fee_mode']);
        // Co-Seite verkauft selbst (Co nimmt ein): trotzdem Regel des Erstellers, auch in der Kasse (1.38.760)
        update_post_meta($cp, '_tix_source_fee', $kk_rule);
        update_post_meta($cp, '_tix_platform_co_sells', '1'); update_post_meta($cp, '_tix_platform_co_sale', '0');
        $f = TIX_Fees::calc_order_fees([['price' => 20, 'qty' => 2, 'event_id' => $cp, 'cat_index' => 0]]);
        cr_eq('Kopie verkauft selbst: Kasse 2 × 1,50 € (Regel des Erstellers) statt 2 × 3 €', [3.0, 'Vorverkaufsgebühr'], [floatval($f['customer_fee_line']), $f['fee_label']]);
        echo "-- Kanal-Gebühr trägt die Quelle\n";
        $o = 990000 + wp_rand(1, 9999);
        update_option('_tix_order_fees_' . $o, ['customer_fee_line' => 3.0, 'lines' => [['event_id' => $own_ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'CR Quelle', 'meta_input' => ['_tix_ticket_categories' => $cats + [1 => ['name' => 'Frei', 'price' => 0]]]]), 'cat_index' => 0, 'price' => 20, 'qty' => 2, 'fee' => 6.0],
            ['event_id' => $own_ev, 'cat_index' => 1, 'price' => 0, 'qty' => 3, 'fee' => 0]]], false);
        $tmp[] = $own_ev;
        $rq = new WP_REST_Request('POST', '/tixomat/v1/partner/quote'); $rq->set_param('channel_fee', ['percent' => 2.9, 'fixed' => 0.49, 'label' => 'Servicegebühr']);
        TIX_Channel_Fee::begin($rq);
        $c1 = TIX_Fees::get_fee_config(); 
        TIX_Channel_Fee::record($o);
        TIX_Channel_Fee::end();
        $c0 = TIX_Fees::get_fee_config();
        $rec = get_option('_tix_order_channel_fee_' . $o);
        cr_eq('während der Vermittlung: Käufer-Regel unverändert (keine Kanal-Gebühr für den Käufer)', [$c0['fee_fixed'], $c0['fee_mode'], $c0['fee_label']], [$c1['fee_fixed'], $c1['fee_mode'], $c1['fee_label']]);
        cr_eq('Kanal-Gebühr: 2 × (20 € × 2,9 % + 0,49 €) = 2,14 € zu Lasten der Quelle, Freitickets ohne', [2.14, 0.0, 2.14, 'organizer'], [$rec['fee'] ?? null, $rec['fee_customer'] ?? null, $rec['fee_organizer'] ?? null, $rec['mode'] ?? null]);
        delete_option('_tix_order_fees_' . $o); delete_option('_tix_order_channel_fee_' . $o);
        $own = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'CR eigenes KMT', 'meta_input' => ['_tix_date_start' => '2027-10-05', '_tix_ticket_categories' => $cats]]);
        $tmp[] = $own;
        cr_eq('eigenes Event der Co-Seite: eigene Regel', [TIX_Fees::get_fee_config()['fee_fixed'], TIX_Fees::get_fee_config()['fee_label']], [TIX_Fees::display_config($own)['fee_fixed'], TIX_Fees::display_config($own)['fee_label']]);
        if ($bk === null) delete_option('tix_settings'); else update_option('tix_settings', $bk);
    }
} catch (Throwable $e) {
    $GLOBALS['t_fails']++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($tmp as $id) wp_delete_post($id, true);
    wp_clear_scheduled_hook('tix_refresh_price_meta');
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
