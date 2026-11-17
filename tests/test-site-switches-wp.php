<?php
/**
 * Funktionen der ganzen Seite (W2, 1.38.882/883, Form laut Studio-Vertrag): /studio/checkout-settings features + low_stock_threshold,
 * /studio/invoice-config tax {enabled, inclusive} (Satz = tax_rate, eine Quelle), /support/mode switches.
 *   wp eval-file …/test-site-switches-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Site_Switches') || !method_exists('TIX_Site_Switches', 'features_payload')) { echo "Tixomat ab 1.38.883 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? null) : null, is_array($d) ? ($d['data']['field'] ?? null) : null]; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$keep = get_option('tix_settings');
$keep_inv = get_option('tix_invoice_config', null);
$sub = 0;
$raw = function ($k) { $s = get_option('tix_settings', []); return $s[$k] ?? null; };
try {
    // ═══ Rein ═══
    $eq('clean: bool/int/text ok', [['ticket_transfer_enabled' => 1, 'upsell_count' => 4, 'upsell_heading' => 'Mehr'], null], TIX_Site_Switches::clean(['ticket_transfer_enabled' => true, 'upsell_count' => 4, 'upsell_heading' => 'Mehr'], 'checkout'));
    $eq('clean: Grenze upsell_count 7 → Feld', [null, 'upsell_count'], TIX_Site_Switches::clean(['upsell_count' => 7], 'checkout'));
    $eq('clean: Liste → Zeilen, doppelte raus', [['support_categories' => "Tickets\nRückgabe"], null], TIX_Site_Switches::clean(['support_categories' => ['Tickets', 'Rückgabe', 'Tickets']], 'support'));
    $eq('Barcode nicht mehr dabei (ohne Wirkung)', false, isset(TIX_Site_Switches::DEFS['barcode_enabled']));

    $adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);

    // ═══ V2/V2b /studio/checkout-settings ═══
    [$s, $d] = $rest('GET', '/studio/checkout-settings');
    $eq('GET: features mit 10 Funktionen, can_edit, hint, low_stock_threshold', [200, 10, true, true, true],
        [$s, count($d['features'] ?? []), $d['features']['ticket_transfer']['can_edit'] ?? null, isset($d['features']['feedback']['hint']), is_int($d['low_stock_threshold'] ?? null)]);
    $eq('upsell und abandoned_cart mit Zusatzfeldern', [true, true, true], [isset($d['features']['upsell']['count'], $d['features']['upsell']['heading']), isset($d['features']['abandoned_cart']['delay_minutes'], $d['features']['abandoned_cart']['delay_hours']), isset($d['features']['abandoned_cart']['subject'])]);
    $eq('/studio/features ohne site (nur eine Stelle)', false, array_key_exists('site', $rest('GET', '/studio/features')[1]));
    $fb = $raw('feedback_enabled');
    [$s, $d] = $rest('POST', '/studio/checkout-settings', ['features' => ['ticket_transfer' => ['enabled' => true], 'upsell' => ['count' => 5, 'heading' => 'Auch gut'], 'abandoned_cart' => ['delay_hours' => 2, 'subject' => 'Noch da?']]]);
    $eq('POST features: gespeichert, Stunden → Minuten, anderes unverändert', [200, 1, 5, 'Auch gut', 120, 'Noch da?', $fb],
        [$s, $raw('ticket_transfer_enabled'), $raw('upsell_count'), $raw('upsell_heading'), $raw('abandoned_cart_delay'), $raw('abandoned_cart_subject'), $raw('feedback_enabled')]);
    $eq('Antwort zeigt neue Werte', [true, 2.0], [$d['features']['ticket_transfer']['enabled'] ?? null, $d['features']['abandoned_cart']['delay_hours'] ?? null]);
    [$s, , $c, $fld] = $rest('POST', '/studio/checkout-settings', ['features' => ['upsell' => ['count' => 9]]]);
    $eq('Grenze → 400 features.upsell.count', [400, 'tix_invalid_field', 'features.upsell.count'], [$s, $c, $fld]);
    [$s, , , $fld] = $rest('POST', '/studio/checkout-settings', ['features' => ['abandoned_cart' => ['delay_hours' => 48]]]);
    $eq('delay_hours 48 → 400', [400, 'features.abandoned_cart.delay_hours'], [$s, $fld]);
    [$s, , , $fld] = $rest('POST', '/studio/checkout-settings', ['features' => ['barcode' => ['enabled' => true]]]);
    $eq('unbekannte Funktion → 400', [400, 'features.barcode'], [$s, $fld]);
    [$s] = $rest('POST', '/studio/checkout-settings', ['features' => ['waitlist' => ['enabled' => false, 'can_edit' => true, 'hint' => 'x']]]);
    $eq('nur lesende Felder werden übergangen', [200, 0], [$s, $raw('waitlist_enabled')]);
    $eq('nach Fehlern: nichts halb geschrieben', 5, $raw('upsell_count'));
    [$s, $d] = $rest('POST', '/studio/checkout-settings', ['low_stock_threshold' => 25, 'checkout' => ['company_field' => true]]);
    $eq('low_stock_threshold + bestehende Felder zusammen', [200, 25, 25, true], [$s, $raw('low_stock_threshold'), $d['low_stock_threshold'] ?? null, $d['checkout']['company_field'] ?? null]);
    [$s, , , $fld] = $rest('POST', '/studio/checkout-settings', ['low_stock_threshold' => 1000]);
    $eq('1000 → 400 low_stock_threshold', [400, 'low_stock_threshold'], [$s, $fld]);

    // ═══ V13 /studio/invoice-config ═══
    [$s, $d] = $rest('GET', '/studio/invoice-config');
    $eq('GET: tax {enabled, inclusive, rate}', [200, true], [$s, is_array($d['tax'] ?? null) && array_key_exists('rate', $d['tax']) && array_key_exists('inclusive', $d['tax'])]);
    [$s, $d] = $rest('POST', '/studio/invoice-config', ['tax' => ['enabled' => true, 'inclusive' => true]]);
    $eq('tax gespeichert', [200, 1, 1], [$s, $raw('tax_enabled'), $raw('tax_inclusive')]);
    [$s, , , $fld] = $rest('POST', '/studio/invoice-config', ['tax' => ['rate' => 7], 'tax_rate' => 7]);
    $eq('Satz doppelt angegeben → 400 tax.rate', [400, 'tax.rate'], [$s, $fld]);
    [$s, , , $fld] = $rest('POST', '/studio/invoice-config', ['tax' => ['rate' => 40]]);
    $eq('Satz 40 → 400 tax.rate', [400, 'tax.rate'], [$s, $fld]);
    [$s, $d] = $rest('POST', '/studio/invoice-config', ['tax' => ['rate' => 7]]);
    $eq('tax.rate 7: Bestellung 7' . ($multi ? '' : ', Rechnungssteller „site“ auch 7'), [200, 7.0, 7.0, $multi ? true : 7.0],
        [$s, floatval($raw('tax_rate')), floatval($d['tax']['rate'] ?? 0), $multi ? true : floatval($d['tax_rate'] ?? 0)]);
    if (!$multi) {
        [$s, $d] = $rest('POST', '/studio/invoice-config', ['tax_rate' => 19]);
        $eq('tax_rate 19 (Seite): Bestellung zieht mit', [200, 19.0, 19.0], [$s, floatval($raw('tax_rate')), floatval($d['tax']['rate'] ?? 0)]);
    }

    // ═══ V15 /support/mode ═══
    [$s, $d] = $rest('GET', '/support/mode');
    $eq('GET support/mode: switches + categories_custom', [200, true, true], [$s, is_array($d['switches'] ?? null) && array_key_exists('chat_enabled', $d['switches']), array_key_exists('categories_custom', $d)]);
    [$s, $d] = $rest('POST', '/support/mode', ['switches' => ['enabled' => true, 'categories' => ['Tickets', 'Rückgabe']]]);
    $eq('switches ohne mode gespeichert', [200, 1, ['Tickets', 'Rückgabe']], [$s, $raw('support_enabled'), $d['switches']['categories'] ?? null]);
    [$s, , , $fld] = $rest('POST', '/support/mode', ['switches' => ['categories' => array_fill(0, 13, 'x')]]);
    $eq('13 Themen → 400 switches.categories', [400, 'switches.categories'], [$s, $fld]);
    $audit = intval($wpdb->get_var("SELECT COUNT(*) FROM " . TIX_Customers::audit_table() . " WHERE action = 'site_settings' AND created >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)"));
    $eq('Änderungsprotokoll site_settings', true, $audit >= 4);

    // ═══ Rechte ═══
    $sfx = substr(md5(microtime()), 0, 6);
    $sub = wp_insert_user(['user_login' => 'ss_' . $sfx, 'user_pass' => wp_generate_password(), 'user_email' => 'ss_' . $sfx . '@example.test', 'role' => 'subscriber']);
    wp_set_current_user($sub);
    [$s] = $rest('POST', '/studio/checkout-settings', ['features' => ['upsell' => ['count' => 2]]]);
    $eq('Kunde → 401/403, nichts geschrieben', [true, 5], [in_array($s, [401, 403], true), $raw('upsell_count')]);
    wp_set_current_user($adm->ID);
    $eq('Recht: evendis nur Admin (rein)', !$multi || current_user_can('manage_options'), TIX_Site_Switches::can_write());
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    update_option('tix_settings', $keep);
    if ($keep_inv === null) delete_option('tix_invoice_config'); else update_option('tix_invoice_config', $keep_inv, false);
    if ($sub) wp_delete_user($sub);
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
