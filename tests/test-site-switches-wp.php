<?php
/**
 * Funktionen der ganzen Seite (1.38.882, W2): /studio/features site, /studio/checkout-settings site, /studio/invoice-config order_tax,
 * /support/mode switches. Nur mitgeschickte Schlüssel, Grenzen, Rechte (evendis nur Admin).   wp eval-file …/test-site-switches-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Site_Switches')) { echo "Tixomat ab 1.38.882 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? null) : null, is_array($d) ? ($d['data']['field'] ?? null) : null]; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$keep = get_option('tix_settings');
$sub = 0;
$raw = function ($k) { $s = get_option('tix_settings', []); return $s[$k] ?? null; };
try {
    // ═══ Rein ═══
    $eq('clean: bool/int/text ok', [['ticket_transfer_enabled' => 1, 'upsell_count' => 4, 'upsell_heading' => 'Mehr'], null], TIX_Site_Switches::clean(['ticket_transfer_enabled' => true, 'upsell_count' => 4, 'upsell_heading' => 'Mehr'], 'features'));
    $eq('clean: Grenze upsell_count 7 → Feld', [null, 'upsell_count'], TIX_Site_Switches::clean(['upsell_count' => 7], 'features'));
    $eq('clean: Schlüssel aus anderem Bereich → abgelehnt', [null, 'tax_rate'], TIX_Site_Switches::clean(['tax_rate' => 7], 'features'));
    $eq('clean: Liste → Zeilen, doppelte raus', [['support_categories' => "Tickets\nRückgabe"], null], TIX_Site_Switches::clean(['support_categories' => ['Tickets', 'Rückgabe', 'Tickets']], 'support'));
    $eq('Themen: JSON und Zeilen lesen', [['slug' => 'a', 'label' => 'A']], TIX_Site_Switches::categories_from_raw('[{"slug":"a","label":"A"}]'));

    $adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);

    // ═══ V2 /studio/features ═══
    [$s, $d] = $rest('GET', '/studio/features');
    $eq('GET features: site mit 15 Schlüsseln, editierbar, Typ/Label/Gruppe', [200, 15, true, 'bool', 'verkauf'], [$s, count($d['site'] ?? []), $d['site_editable'] ?? null, $d['site']['ticket_transfer_enabled']['type'] ?? null, $d['site']['ticket_transfer_enabled']['group'] ?? null]);
    $before_feedback = $raw('feedback_enabled');
    [$s, $d] = $rest('POST', '/studio/features', ['site' => ['ticket_transfer_enabled' => true, 'upsell_count' => 5, 'abandoned_cart_subject' => 'Noch da?']]);
    $eq('POST nur site: gespeichert, andere unverändert', [200, 1, 5, 'Noch da?', $before_feedback], [$s, $raw('ticket_transfer_enabled'), $raw('upsell_count'), $raw('abandoned_cart_subject'), $raw('feedback_enabled')]);
    $eq('Antwort zeigt neuen Wert', true, $d['site']['ticket_transfer_enabled']['value'] ?? null);
    [$s, , $c, $fld] = $rest('POST', '/studio/features', ['site' => ['abandoned_cart_delay' => 2]]);
    $eq('Grenze → 400 mit Feld site.abandoned_cart_delay', [400, 'tix_invalid_field', 'site.abandoned_cart_delay'], [$s, $c, $fld]);
    [$s, , $c, $fld] = $rest('POST', '/studio/features', ['site' => ['gibts_nicht' => 1]]);
    $eq('unbekannt → 400', [400, 'site.gibts_nicht'], [$s, $fld]);
    $eq('nach Fehler: nichts halb geschrieben', 5, $raw('upsell_count'));

    // ═══ V2b /studio/checkout-settings ═══
    [$s, $d] = $rest('GET', '/studio/checkout-settings');
    $eq('GET checkout: site.low_stock_threshold', [200, 'int', 0, 999], [$s, $d['site']['low_stock_threshold']['type'] ?? null, $d['site']['low_stock_threshold']['min'] ?? null, $d['site']['low_stock_threshold']['max'] ?? null]);
    [$s] = $rest('POST', '/studio/checkout-settings', ['site' => ['low_stock_threshold' => 25]]);
    $eq('low_stock_threshold 25 gespeichert', [200, 25], [$s, $raw('low_stock_threshold')]);
    [$s, , , $fld] = $rest('POST', '/studio/checkout-settings', ['site' => ['low_stock_threshold' => 1000]]);
    $eq('1000 → 400 site.low_stock_threshold', [400, 'site.low_stock_threshold'], [$s, $fld]);
    [$s, $d] = $rest('POST', '/studio/checkout-settings', ['checkout' => ['company_field' => true]]);
    $eq('bestehende Felder weiter speicherbar', [200, true], [$s, $d['checkout']['company_field'] ?? null]);

    // ═══ V13 /studio/invoice-config ═══
    [$s, $d] = $rest('GET', '/studio/invoice-config');
    $eq('GET invoice-config: order_tax', [200, true], [$s, is_array($d['order_tax'] ?? null) && array_key_exists('inclusive', $d['order_tax'])]);
    [$s, $d] = $rest('POST', '/studio/invoice-config', ['order_tax' => ['enabled' => true, 'rate' => 7, 'inclusive' => true]]);
    $eq('order_tax gespeichert (tix_settings tax_*)', [200, 1, 7.0, 1], [$s, $raw('tax_enabled'), floatval($raw('tax_rate')), $raw('tax_inclusive')]);
    [$s, , , $fld] = $rest('POST', '/studio/invoice-config', ['order_tax' => ['rate' => 120]]);
    $eq('Satz 120 → 400 order_tax.rate', [400, 'order_tax.rate'], [$s, $fld]);

    // ═══ V15 /support/mode ═══
    [$s, $d] = $rest('GET', '/support/mode');
    $eq('GET support/mode: switches', [200, true], [$s, is_array($d['switches'] ?? null) && array_key_exists('categories', $d['switches'])]);
    [$s, $d] = $rest('POST', '/support/mode', ['switches' => ['enabled' => true, 'categories' => ['Tickets', 'Rückgabe']]]);
    $eq('switches ohne mode gespeichert', [200, 1, ['Tickets', 'Rückgabe']], [$s, $raw('support_enabled'), $d['switches']['categories'] ?? null]);
    [$s, , , $fld] = $rest('POST', '/support/mode', ['switches' => ['categories' => 'kein array']]);
    $eq('Themen kein Array → 400 switches.categories', [400, 'switches.categories'], [$s, $fld]);
    $audit = intval($wpdb->get_var("SELECT COUNT(*) FROM " . TIX_Customers::audit_table() . " WHERE action = 'site_settings' AND created >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)"));
    $eq('Änderungsprotokoll site_settings', true, $audit >= 4);

    // ═══ Rechte ═══
    $sub = wp_insert_user(['user_login' => 'ss_' . substr(md5(microtime()), 0, 6), 'user_pass' => wp_generate_password(), 'user_email' => 'ss_' . substr(md5(microtime()), 0, 6) . '@example.test', 'role' => 'subscriber']);
    wp_set_current_user($sub);
    [$s] = $rest('POST', '/studio/features', ['site' => ['upsell_count' => 2]]);
    $eq('Kunde → 401/403, nichts geschrieben', [true, 5], [in_array($s, [401, 403], true), $raw('upsell_count')]);
    wp_set_current_user($adm->ID);
    $eq('Recht: evendis nur Admin (rein)', !$multi || current_user_can('manage_options'), TIX_Site_Switches::can_write());
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    update_option('tix_settings', $keep);
    if ($sub) wp_delete_user($sub);
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
