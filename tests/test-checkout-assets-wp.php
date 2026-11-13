<?php
/**
 * Bedingtes Laden der Kassen-Dateien (1.38.809, Audit evendis.de): checkout.css + native-checkout.js nur auf Kasse,
 * Danke-Seite, Event-Seiten und Seiten mit [tix_…]-Shortcode (Inhalt oder Breakdance); Kassen-Bausteine laden selbst nach.
 *   wp eval-file …/test-checkout-assets-wp.php
 */
if (!defined('ABSPATH') || !method_exists('TIX_Native_Checkout', 'needs_assets')) { echo "Tixomat ab 1.38.809 nötig\n"; return; }
if (function_exists('tix_has_wc') && tix_has_wc()) { echo "skip (WooCommerce aktiv)\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$posts = [];
$reset = function () {
    foreach (['want_assets', 'assets_done'] as $p) { $r = new ReflectionProperty('TIX_Native_Checkout', $p); $r->setAccessible(true); $r->setValue(null, false); }
    wp_dequeue_script('tix-native-checkout'); wp_dequeue_style('tix-checkout');
    unset($_GET['tix_thankyou']);
};
$visit = function ($q) use ($reset) {
    $reset();
    query_posts($q);
    TIX_Native_Checkout::enqueue_assets();
    $out = [wp_script_is('tix-native-checkout', 'enqueued'), wp_style_is('tix-checkout', 'enqueued')];
    wp_reset_query();
    return $out;
};
try {
    $plain = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Werbeseite', 'post_content' => '<p>Hallo</p>']); $posts[] = $plain;
    update_post_meta($plain, '_breakdance_data', wp_slash(wp_json_encode(['css' => "src:url('/wp-content/plugins/tixomat/assets/fonts/manrope/Manrope-400.woff2')"])));
    $sc = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Mit Ticketauswahl', 'post_content' => '[tix_ticket_selector id="1"]']); $posts[] = $sc;
    $bd = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Breakdance mit Shortcode', 'post_content' => '']); $posts[] = $bd;
    update_post_meta($bd, '_breakdance_data', wp_slash(wp_json_encode(['tree' => ['shortcode' => '[tix_checkout]']])));
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Asset-Event']); $posts[] = $ev;

    $eq('Werbeseite (Breakdance mit Plugin-Schrift, ohne Shortcode): keine Kassen-Dateien', [false, false], $visit(['page_id' => $plain]));
    $eq('Seite mit [tix_…]-Shortcode: Kassen-Dateien', [true, true], $visit(['page_id' => $sc]));
    $eq('Breakdance-Seite mit [tix_checkout]: Kassen-Dateien', [true, true], $visit(['page_id' => $bd]));
    $eq('Event-Seite: Kassen-Dateien', [true, true], $visit(['p' => $ev, 'post_type' => 'event']));
    $cp = intval(get_option('tix_checkout_page_id'));
    if ($cp && get_post_status($cp) === 'publish') $eq('Kassen-Seite: Kassen-Dateien', [true, true], $visit(['page_id' => $cp]));
    $reset(); $_GET['tix_thankyou'] = '1';
    TIX_Native_Checkout::enqueue_assets();
    $eq('Danke-Seite: Kassen-Dateien', true, wp_script_is('tix-native-checkout', 'enqueued'));
    // Sicherheitsnetz: Kasse irgendwo gerendert (nach wp_enqueue_scripts) → lädt selbst nach
    $reset(); query_posts(['page_id' => $plain]);
    TIX_Native_Checkout::enqueue_assets();
    $before = wp_script_is('tix-native-checkout', 'enqueued');
    if (!did_action('wp_enqueue_scripts')) do_action('wp_enqueue_scripts');
    do_shortcode('[tix_native_cart]');
    $eq('Warenkorb-Shortcode auf Werbeseite lädt selbst nach', [false, true], [$before, wp_script_is('tix-native-checkout', 'enqueued')]);
    wp_reset_query();
    // Filter für Sonderfälle
    add_filter('tix_needs_checkout_assets', '__return_true');
    $eq('Filter tix_needs_checkout_assets erzwingt', [true, true], $visit(['page_id' => $plain]));
    remove_filter('tix_needs_checkout_assets', '__return_true');
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    unset($_GET['tix_thankyou']);
    foreach ($posts as $p) wp_delete_post($p, true);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
