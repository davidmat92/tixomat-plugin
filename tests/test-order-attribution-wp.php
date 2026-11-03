<?php
/** Integrationstest Statistik Stufe 1 – Herkunft der Bestellung (TIX_Order_Attribution). wp eval-file …/tests/test-order-attribution-wp.php */
if (!defined('ABSPATH') || !class_exists('TIX_Order_Attribution') || !class_exists('TIX_Order')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function oa_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
global $wpdb;
TIX_Order::create_tables();
TIX_Order_Attribution::maybe_create();
$ot = TIX_Order::table_name(); $at = TIX_Order_Attribution::table();
$mk_order = function ($id, $pm = 'mollie') use ($wpdb, $ot) {
    $wpdb->query($wpdb->prepare("DELETE FROM $ot WHERE id = %d", $id));
    $wpdb->insert($ot, ['id' => $id, 'order_number' => 'T' . $id, 'status' => 'completed', 'order_key' => 'k' . $id, 'total' => 10, 'payment_method' => $pm, 'billing_email' => "oa$id@example.test"]);
    return $id;
};
$posts = []; $orders = [];

// ── Umzug: Bestellung mit derselben ID wie ein Event-Post (Kollision) ──
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'OA Kollision']); $posts[] = $ev;
update_post_meta($ev, '_tix_date_start', '2030-01-01');
$orders[] = $mk_order($ev);
foreach (['_tix_campaign_source' => 'instagram', '_tix_campaign_medium' => 'social', '_tix_campaign_name' => 'sommer', '_tix_order_channel' => 'web', '_tix_meta_consent' => '1', '_tix_meta_fbp' => 'fb.1.2', '_tix_promoter_id' => 7, '_tix_promoter_commissions_calculated' => 1] as $k => $v) add_post_meta($ev, $k, $v);
// echte WooCommerce-Bestellung (shop_order) mit gleicher ID wie eine tix-Bestellung: bleibt unangetastet
$wc = wp_insert_post(['post_type' => 'shop_order', 'post_status' => 'publish', 'post_title' => 'OA WC']); $posts[] = $wc;
$orders[] = $mk_order($wc);
add_post_meta($wc, '_tix_campaign_source', 'google');
// Bestellung ohne Post (Normalfall), nur „keine Einwilligung“
$plain = 990000 + wp_rand(1, 9999); $orders[] = $mk_order($plain);
add_post_meta($plain, '_tix_meta_no_consent', '1');
add_post_meta($plain, '_tix_campaign_source', 'direct');

delete_option('tix_attr_migrated');
for ($i = 0; $i < 20 && TIX_Order_Attribution::migrate_batch(500) > 0; $i++);
$r = $wpdb->get_row($wpdb->prepare("SELECT * FROM $at WHERE order_id = %d", $ev), ARRAY_A);
oa_eq('Umzug: Quelle in der Tabelle', 'instagram', $r['source'] ?? null);
oa_eq('Umzug: Kampagne/Medium/Kanal', ['sommer', 'social', 'web'], [$r['campaign'] ?? null, $r['medium'] ?? null, $r['channel'] ?? null]);
oa_eq('Umzug: Einwilligung ja', '1', (string) ($r['consent_marketing'] ?? ''));
oa_eq('Umzug: Promoter + berechnet', ['7', '1'], [(string) ($r['promoter_id'] ?? ''), (string) ($r['promoter_done'] ?? '')]);
oa_eq('Umzug: Postmeta der Bestellung weg', '', (string) get_post_meta($ev, '_tix_campaign_source', true));
oa_eq('Umzug: Meta des Events bleibt', '2030-01-01', (string) get_post_meta($ev, '_tix_date_start', true));
oa_eq('Umzug: WooCommerce-Bestellung behält Postmeta', 'google', (string) get_post_meta($wc, '_tix_campaign_source', true));
oa_eq('Umzug: keine Zeile für WooCommerce-Post', null, $wpdb->get_var($wpdb->prepare("SELECT order_id FROM $at WHERE order_id = %d", $wc)));
oa_eq('Umzug: „keine Einwilligung“ = 0', '0', (string) $wpdb->get_var($wpdb->prepare("SELECT consent_marketing FROM $at WHERE order_id = %d", $plain)));
TIX_Order_Attribution::maybe_migrate();
oa_eq('Umzug: als fertig markiert', '1', get_option('tix_attr_migrated'));

// ── Lesen/Schreiben im alten Format ──
oa_eq('meta: Quelle', 'instagram', TIX_Order_Attribution::meta($ev, '_tix_campaign_source'));
oa_eq('meta: Einwilligung ja / nein', ['1', ''], [TIX_Order_Attribution::meta($ev, '_tix_meta_consent'), TIX_Order_Attribution::meta($ev, '_tix_meta_no_consent')]);
oa_eq('meta: ohne Einwilligung', ['', '1'], [TIX_Order_Attribution::meta($plain, '_tix_meta_consent'), TIX_Order_Attribution::meta($plain, '_tix_meta_no_consent')]);
oa_eq('meta: WooCommerce liest weiter Postmeta', 'google', TIX_Order_Attribution::meta($wc, '_tix_campaign_source'));
TIX_Order_Attribution::set_meta($plain, '_tix_meta_consent', '1');
TIX_Order_Attribution::set_meta($plain, '_tix_meta_no_consent', null);
oa_eq('set_meta: Einwilligung nachträglich, „nein“ gelöscht', ['1', ''], [TIX_Order_Attribution::meta($plain, '_tix_meta_consent'), TIX_Order_Attribution::meta($plain, '_tix_meta_no_consent')]);
TIX_Order_Attribution::set_meta($plain, '_tix_campaign_name', str_repeat('x', 300));
oa_eq('set_meta: auf Spaltenlänge gekürzt', 100, strlen(TIX_Order_Attribution::meta($plain, '_tix_campaign_name')));
oa_eq('set_meta: kein Postmeta mehr', '', (string) get_post_meta($plain, '_tix_campaign_name', true));

// ── Konto löschen: Kennungen weg, Herkunft bleibt ──
TIX_Order_Attribution::set($ev, ['click_ids' => ['gclid' => 'abc'], 'client_ip' => '1.2.3.4']);
TIX_Order_Attribution::forget($ev);
$r = TIX_Order_Attribution::get($ev);
oa_eq('forget: fbp/IP/Klick-IDs/Einwilligung weg', ['', '', null, null], [$r['fbp'], $r['client_ip'], $r['click_ids'], $r['consent_marketing']]);
oa_eq('forget: Quelle bleibt', 'instagram', $r['source']);

// ── Kanal und Spiegel ──
TIX_Order_Attribution::set($plain, ['source' => 'veranstalter-link', 'channel' => '']);
$ch = TIX_Studio_Stats::channels([$ev, $plain]);
oa_eq('Kanal: Website / Dein Link', ['web', 'link'], [$ch[$ev] ?? null, $ch[$plain] ?? null]);
TIX_Order_Attribution::set($ev, ['link_code' => 'abc123', 'referrer' => 'https://www.instagram.com/p/x']);
$m = TIX_Order_Attribution::mirror_data($ev);
oa_eq('Spiegel: Quelle, Link-Code, Referrer-Host', ['instagram', 'abc123', 'www.instagram.com'], [$m['source'] ?? null, $m['link_code'] ?? null, $m['referrer_host'] ?? null]);
oa_eq('Spiegel: keine IP/fbp', [false, false], [isset($m['client_ip']), isset($m['fbp'])]);

// ── Kasse: Kampagnen-Code und Klick-IDs nur mit Einwilligung ──
$ref = new ReflectionMethod('TIX_App_Checkout', 'apply_attribution'); $ref->setAccessible(true);
$o1 = 980000 + wp_rand(1, 9999); $orders[] = $mk_order($o1);
$ref->invoke(null, $o1, ['utm_source' => 'TikTok', 'utm_campaign' => 'Herbst', 'c' => 'k7Xy', 'click_ids' => ['ttclid' => 'tt1']], []);
$r = TIX_Order_Attribution::get($o1);
oa_eq('Kasse: Quelle/Kampagne/Code', ['tiktok', 'Herbst', 'k7Xy'], [$r['source'], $r['campaign'], $r['link_code']]);
oa_eq('Kasse: ohne Einwilligung keine Klick-ID', null, $r['click_ids']);
$o2 = 970000 + wp_rand(1, 9999); $orders[] = $mk_order($o2);
$ref->invoke(null, $o2, ['utm_source' => 'google', 'consent_marketing' => true, 'click_ids' => ['gclid' => 'g1', 'evil' => 'x', 'fbclid' => 'bad value!']], []);
oa_eq('Kasse: mit Einwilligung nur gültige Klick-IDs', ['gclid' => 'g1'], json_decode((string) TIX_Order_Attribution::get($o2)['click_ids'], true));
oa_eq('Kasse: Einwilligung gemerkt', '1', TIX_Order_Attribution::meta($o2, '_tix_meta_consent'));
oa_eq('Kasse: kein Postmeta an der Bestell-ID', '', (string) get_post_meta($o2, '_tix_campaign_source', true));

// Aufräumen
foreach ($orders as $o) { $wpdb->query($wpdb->prepare("DELETE FROM $ot WHERE id = %d", $o)); $wpdb->query($wpdb->prepare("DELETE FROM $at WHERE order_id = %d", $o)); }
foreach ($posts as $p) wp_delete_post($p, true);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
