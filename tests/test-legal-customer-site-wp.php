<?php
/**
 * Rechtslinks von der Kunden-Seite (1.38.890): eigene URL > WP-Rechtstext > Kunden-Seite (nur wenn deren Host ≠ WordPress).
 * Einzel-Seite ohne eigene Kunden-Adresse: unverändert.   wp eval-file …/test-legal-customer-site-wp.php
 */
if (!defined('ABSPATH') || !method_exists('TIX_Studio_Legal', 'customer_site_url')) { echo "Tixomat ab 1.38.890 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$keep = ['tix_settings' => get_option('tix_settings', null), 'tix_customer_base_url' => get_option('tix_customer_base_url', null)];
$reset = function () { $r = new ReflectionClass('TIX_Studio_Legal'); foreach (['urls' => [], 'legal' => null] as $p => $v) if ($r->hasProperty($p)) { $x = $r->getProperty($p); $x->setAccessible(true); $x->setValue(null, $v); } };
$pages = [];
try {
    // ═══ Ohne eigene Kunden-Adresse (KK/Mallorca-Fall): nichts abgeleitet ═══
    delete_option('tix_customer_base_url'); $reset();
    $before = [TIX_Studio_Legal::checkout_link('terms'), TIX_Studio_Legal::checkout_link('privacy'), TIX_Studio_Legal::checkout_link('revocation')];
    $eq('ohne Kunden-Adresse: keine Ableitung', ['', ''], [TIX_Studio_Legal::customer_site_url('agb'), TIX_Studio_Legal::customer_site_url('impressum')]);
    $eq('ohne Kunden-Adresse: Quelle nie customer_site', false, in_array('customer_site', array_column($before, 1), true));
    update_option('tix_customer_base_url', home_url()); $reset(); // gleicher Host wie WordPress
    $eq('Kunden-Adresse = WordPress: keine Ableitung', '', TIX_Studio_Legal::customer_site_url('agb'));

    // ═══ Eigene Kunden-Seite (evendis-Fall) ═══
    update_option('tix_customer_base_url', 'https://kunden.example.test'); $reset();
    $s = (array) $keep['tix_settings']; foreach (['terms_url', 'privacy_url', 'revocation_url', 'imprint_url', 'impressum_url'] as $k) $s[$k] = '';
    update_option('tix_settings', $s);
    foreach (['agb', 'widerruf', 'datenschutz', 'impressum'] as $sl) { $p = get_page_by_path($sl); if ($p && $p->post_status === 'publish') { wp_update_post(['ID' => $p->ID, 'post_status' => 'draft']); $pages[] = $p->ID; } }
    $reset();
    $eq('Kunden-Seite: AGB/Datenschutz/Widerruf abgeleitet', [['https://kunden.example.test/agb/', 'customer_site'], ['https://kunden.example.test/datenschutz/', 'customer_site'], ['https://kunden.example.test/widerruf/', 'customer_site']],
        [TIX_Studio_Legal::checkout_link('terms'), TIX_Studio_Legal::checkout_link('privacy'), TIX_Studio_Legal::checkout_link('revocation')]);
    $eq('Ticket-Mail-Filter liefert die Kunden-Seite', 'https://kunden.example.test/widerruf/', (string) apply_filters('tix_checkout_revocation_url', ''));
    $lg = TIX_Site_Config::payload()['legal'] ?? [];
    $eq('site-config: Impressum, Datenschutz, AGB von der Kunden-Seite', ['https://kunden.example.test/impressum/', 'https://kunden.example.test/datenschutz/', 'https://kunden.example.test/agb/'], [$lg['imprint'] ?? null, $lg['privacy'] ?? null, $lg['terms'] ?? null]);
    $s['invoice_company_address'] = "Musterstr. 1\n12345 Musterstadt"; update_option('tix_settings', $s); $reset();
    $cl = TIX_Co_Legal::site();
    $eq('Co_Legal: Impressum + Datenschutz da' . ($multi ? ', Anschrift aus Rechnungsdaten → vollständig' : ''), [true, true, $multi], [!empty($cl['imprint_url']), !empty($cl['privacy_url']), $multi ? !empty($cl['complete']) : false]);

    // ═══ Vorrang ═══
    $s['terms_url'] = 'https://eigene.example.test/agb'; update_option('tix_settings', $s); $reset();
    $eq('eigene URL hat Vorrang', ['https://eigene.example.test/agb', 'own'], TIX_Studio_Legal::checkout_link('terms'));
    $pid = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Widerruf', 'post_name' => 'widerruf', 'post_content' => 'x']);
    $reset();
    $w = TIX_Studio_Legal::checkout_link('revocation');
    $eq('veröffentlichter WP-Rechtstext vor Kunden-Seite', 'page', $w[1]);
    wp_delete_post($pid, true);
    $r = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/studio/legal'));
    $eq('GET /studio/legal ohne Anmeldung nicht lesbar', true, in_array($r->get_status(), [401, 403], true));
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
    foreach ($pages as $p) wp_update_post(['ID' => $p, 'post_status' => 'publish']);
    $reset();
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
