<?php
/**
 * Integrationstest Selbstheilung Bestell-Tabellen (1.38.695): TIX_Order::maybe_install auf init legt
 * tix_orders/_items/_notes an, auch ohne admin_init-Migration (Seiten per WP-CLI/REST eingerichtet).
 * Benennt die vorhandenen Tabellen vorübergehend um und stellt sie danach wieder her.
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-order-tables-heal-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Order')) { echo "WordPress mit Tixomat nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }

$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function oh_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
global $wpdb;
$tables = [TIX_Order::table_name(), TIX_Order::items_table_name(), TIX_Order::notes_table_name()];
$exists = function ($t) use ($wpdb) { return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t; };
$keep_opt = get_option(TIX_Order::DB_OPTION, null);
$keep_ver = get_option('tix_db_version', null);
$moved = [];
try {
    oh_eq('init-Hook registriert', 4, has_action('init', ['TIX_Order', 'maybe_install']));
    foreach ($tables as $t) if ($exists($t)) { $wpdb->query("RENAME TABLE `$t` TO `{$t}_ohbak`"); $moved[] = $t; }
    delete_option(TIX_Order::DB_OPTION);
    delete_option('tix_db_version'); // Lage wie bei XXL
    oh_eq('Tabellen weg', false, TIX_Order::tables_exist());

    TIX_Order::maybe_install();
    oh_eq('nach maybe_install: alle drei da', [true, true, true], array_map($exists, $tables));
    oh_eq('DB-Option gesetzt', TIX_Order::DB_VER, get_option(TIX_Order::DB_OPTION));
    oh_eq('tix_db_version unberührt', false, get_option('tix_db_version'));

    // Option gesetzt → kein weiterer Abruf; Tabelle fehlt trotzdem → erst nach Zurücksetzen der Option
    $wpdb->query("DROP TABLE `{$tables[2]}`");
    TIX_Order::maybe_install();
    oh_eq('Option gesetzt: keine Prüfung', false, $exists($tables[2]));
    delete_option(TIX_Order::DB_OPTION);
    TIX_Order::maybe_install();
    oh_eq('Option zurückgesetzt: Notizen-Tabelle wieder da', true, $exists($tables[2]));

    // Option fehlt, Tabellen da → nur Option setzen
    delete_option(TIX_Order::DB_OPTION);
    TIX_Order::maybe_install();
    oh_eq('vorhandene Tabellen: Option nachgetragen', TIX_Order::DB_VER, get_option(TIX_Order::DB_OPTION));

    // Bestellung anlegen geht
    $wpdb->insert($tables[0], ['order_number' => 'OH-' . wp_rand(1000, 9999), 'status' => 'pending', 'billing_email' => 'oh@example.test']);
    oh_eq('Bestellung schreibbar', true, $wpdb->insert_id > 0);
} finally {
    foreach ($moved as $t) { $wpdb->query("DROP TABLE IF EXISTS `$t`"); $wpdb->query("RENAME TABLE `{$t}_ohbak` TO `$t`"); }
    if ($keep_opt === null) delete_option(TIX_Order::DB_OPTION); else update_option(TIX_Order::DB_OPTION, $keep_opt, true);
    if ($keep_ver === null) delete_option('tix_db_version'); else update_option('tix_db_version', $keep_ver);
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
