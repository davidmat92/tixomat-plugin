<?php
/**
 * Entfernt ALLE Zeilen der Test-Kennung (*.local) aus dem Spiegel, inkl. Verlauf und Berichte.
 * Nur für Testläufe gegen das echte Projekt; verweigert jede andere Kennung.
 *
 *   MIRROR_TEST_REMOTE=1 wp eval-file wp-content/plugins/tixomat/tests/mirror-test-site-cleanup.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Mirror')) return;
$site = TIX_Mirror::site();
if (getenv('MIRROR_TEST_REMOTE') !== '1' || !preg_match('/\.local$/', $site)) { echo "Nur für Test-Kennungen *.local mit MIRROR_TEST_REMOTE=1\n"; return; }
$tables = ['checkins', 'refunds', 'payments', 'order_items', 'orders', 'tickets', 'ticket_categories', 'guests', 'events', 'loyalty', 'customers',
           'coupons', 'wallet_transactions', 'wallets', 'settlement_items', 'settlements', 'invoices', 'invoice_counters', 'audit_log', 'organizers', 'sync_reports', 'change_log', 'sites'];
foreach ($tables as $t) {
    $r = TIX_Mirror_Client::delete($t, $t === 'sites' ? ['site' => 'eq.' . $site] : ['site' => 'eq.' . $site]);
    echo str_pad($t, 22) . ($r['ok'] ? 'geleert' : 'Fehler: ' . $r['error']) . "\n";
}
// change_log bekommt durch die Löschungen neue delete-Einträge → zum Schluss noch einmal
TIX_Mirror_Client::delete('change_log', ['site' => 'eq.' . $site]);
$left = TIX_Mirror_Client::select('change_log', ['site' => 'eq.' . $site, 'limit' => '1'], 'id');
echo 'Rest im Verlauf: ' . count((array) ($left['body'] ?? [])) . "\n";
