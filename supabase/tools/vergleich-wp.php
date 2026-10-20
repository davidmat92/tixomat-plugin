<?php
/**
 * Datenspiegel: lesender Vergleich WordPress ↔ Spiegel für eine Seite (z. B. nach der Erstbefüllung).
 * Schreibt NICHTS – weder in WordPress (kein Postausgang) noch in Supabase.
 *
 *   wp eval-file wp-content/plugins/tixomat/supabase/tools/vergleich-wp.php
 *
 * 1. Anzahlen je Tabelle (WordPress gezählt wie die Erstbefüllung, Spiegel per count=exact)
 * 2. Stichproben: neueste Bestellungen, Tickets, Events, Geldbörsen Feld für Feld
 * 3. Datenschutz: Passwort-/PIN-Hashes, geheime Feldnamen, Klartext-IBAN in ALLEN Zeilen je Tabelle und im Verlauf
 */
if (!defined('ABSPATH') || !class_exists('TIX_Mirror')) { echo "WordPress mit Tixomat (Datenspiegel) nötig\n"; return; }
if (!TIX_Mirror_Client::configured()) { echo "Spiegel nicht eingerichtet (TIX_MIRROR_URL/TIX_MIRROR_KEY).\n"; return; }

global $wpdb;
$site = TIX_Mirror::site();
$problems = [];
$bf = TIX_Mirror_Backfill::get();
$pending = TIX_Mirror::pending_count();
$st = TIX_Mirror::state();
echo "Seite im Spiegel: {$site}\n";
echo "Erstbefüllung: " . TIX_Mirror_Backfill::status()['label'] . " · Rückstand im Postausgang: {$pending}"
    . " · letzter fehlerfreier Lauf: " . ($st['last_ok'] ? wp_date('d.m.Y H:i:s', (int) $st['last_ok']) : 'noch nie') . "\n";
if ($st['last_error'] !== '') echo "Letzter Fehler: {$st['last_error']}" . ((int) $st['last_ok'] > (int) $st['last_error_at'] ? ' (seitdem fehlerfrei)' : '') . "\n";
$settled = $bf['state'] === 'done' && $pending === 0;
if (!$settled) echo "Hinweis: Erstbefüllung läuft noch bzw. Rückstand > 0 – Abweichungen bei den Anzahlen sind dann erwartet.\n";

// ── 1. Anzahlen ──
$q = function ($sql) use ($wpdb) {
    $prev = $wpdb->suppress_errors(true);
    $v = $wpdb->get_var($sql);
    $err = $wpdb->last_error;
    $wpdb->suppress_errors($prev);
    return $err !== '' ? null : (int) $v;
};
$posts = function ($type) use ($q, $wpdb) {
    return $q($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft'", $type));
};
// Demo/Vorschau (Sandbox-Veranstalter) gehören nie in den Spiegel → hier nicht mitzählen
$sbo = []; $sbe = [];
if (class_exists('TIX_Mirror_Reconcile') && method_exists('TIX_Mirror_Reconcile', 'any_sandbox') && TIX_Mirror_Reconcile::any_sandbox()) {
    foreach ((array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tix_organizer' AND post_status <> 'auto-draft'") as $o) if (TIX_Mirror_Map::is_sandbox_org((int) $o)) $sbo[] = (int) $o;
}
$event_ids = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'event' AND post_status <> 'auto-draft'"));
if ($sbo) {
    $sbe = array_values(array_filter($event_ids, function ($e) { return TIX_Mirror_Map::is_sandbox_event($e); }));
    $event_ids = array_values(array_diff($event_ids, $sbe));
    echo "Sandbox (Demo/Vorschau, nie im Spiegel): " . count($sbo) . " Veranstalter, " . count($sbe) . " Events – nicht mitgezählt\n";
}
// Platzhalter -1 (kommt nie vor): ohne Sandbox filtert NOT IN nichts weg – auch nicht Veranstalter 0 (= Seite)
$in_e = $sbe ? implode(',', array_map('intval', $sbe)) : '-1';
$in_o = $sbo ? implode(',', array_map('intval', $sbo)) : '-1';
$sb_orders = $sbe ? "SELECT DISTINCT order_id FROM {$wpdb->prefix}tix_order_items WHERE event_id IN ($in_e) UNION SELECT id FROM {$wpdb->prefix}tix_orders WHERE payment_method = 'demo'" : "SELECT id FROM {$wpdb->prefix}tix_orders WHERE payment_method = 'demo'";
$cats = 0; $guests = 0;
foreach ($event_ids as $eid) {
    $c = get_post_meta($eid, '_tix_ticket_categories', true);
    $cats += is_array($c) ? count(array_filter($c, 'is_array')) : 0;
    $guests += count(TIX_Mirror_Map::guest_rows('', $eid));
}
$coupons = get_option('tix_coupons', []);
$coupons = is_array($coupons) ? count(array_filter($coupons, function ($c, $code) use ($sbo) {
    if (!is_array($c) || (string) $code === '') return false;
    if (!empty($c['demo']) || ($sbo && (TIX_Mirror_Map::is_sandbox_org($c['organizer_id'] ?? ($c['gift_organizer_id'] ?? 0)) || (!empty($c['order_id']) && TIX_Mirror_Map::is_sandbox_order((int) $c['order_id']))))) return false;
    return true;
}, ARRAY_FILTER_USE_BOTH)) : 0;
$orders = $q("SELECT COUNT(*) FROM {$wpdb->prefix}tix_orders WHERE id NOT IN ($sb_orders)");
$wp = [
    'sites'               => 1,
    'events'              => count($event_ids),
    'ticket_categories'   => $cats,
    'guests'              => $guests,
    'customers'           => $q("SELECT COUNT(*) FROM {$wpdb->users}"),
    'loyalty'             => $q("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key REGEXP '^_tix_loyalty_points(_o[0-9]+)?$'" . ($sbo ? " AND meta_key NOT IN ('" . implode("','", array_map(function ($o) { return '_tix_loyalty_points_o' . (int) $o; }, $sbo)) . "')" : '')),
    'orders'              => $orders,
    'order_items'         => $q("SELECT COUNT(*) FROM {$wpdb->prefix}tix_order_items WHERE order_id NOT IN ($sb_orders)"),
    'payments'            => $orders,
    'tickets'             => $sbe ? $q("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'tix_ticket' AND p.post_status <> 'auto-draft' AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_tix_ticket_event_id' AND m.meta_value IN ($in_e))") : $posts('tix_ticket'),
    'organizers'          => $posts('tix_organizer') === null ? null : $posts('tix_organizer') - count($sbo),
    'coupons'             => $coupons,
    'wallets'             => $q("SELECT COUNT(*) FROM {$wpdb->prefix}tix_wallets WHERE organizer_id NOT IN ($in_o)"),
    'wallet_transactions' => $q("SELECT COUNT(*) FROM {$wpdb->prefix}tix_wallet_tx x JOIN {$wpdb->prefix}tix_wallets w ON w.id = x.wallet_id WHERE w.organizer_id NOT IN ($in_o)" . ($sbo ? " AND x.organizer_id NOT IN ($in_o)" : '')),
    'settlements'         => $q("SELECT COUNT(*) FROM {$wpdb->prefix}tix_settlements WHERE organizer_id NOT IN ($in_o)"),
    'settlement_items'    => $q("SELECT COUNT(*) FROM {$wpdb->prefix}tix_settlement_items WHERE organizer_id NOT IN ($in_o)"),
    'invoices'            => $q("SELECT COUNT(*) FROM {$wpdb->prefix}tix_invoices WHERE organizer_id NOT IN ($in_o) AND order_id NOT IN ($sb_orders)"),
    'invoice_counters'    => $q("SELECT COUNT(*) FROM {$wpdb->prefix}tix_invoice_counters"),
    'audit_log'           => $q("SELECT COUNT(*) FROM {$wpdb->prefix}tix_audit_log" . ($sbo ? " WHERE scope NOT REGEXP ':(" . implode('|', array_map('intval', $sbo)) . ")$'" : '')),
    'checkins'            => null, // nur im Spiegel (Verlauf ab Einrichtung)
    'refunds'             => null, // nur im Spiegel (Verlauf ab Einrichtung)
];
echo "\n1. Anzahlen\n";
printf("   %-21s %10s %10s  %s\n", 'Tabelle', 'WordPress', 'Spiegel', '');
foreach ($wp as $table => $n) {
    $m = TIX_Mirror_Client::count($table, ['site' => 'eq.' . $site]);
    $mark = '';
    if ($m === null) { $mark = 'FEHLER beim Lesen'; $problems[] = "Zählen {$table}"; }
    elseif ($n === null) { $mark = $table === 'checkins' || $table === 'refunds' ? 'nur Spiegel (Verlauf)' : 'Tabelle fehlt in WP'; }
    elseif ($n === $m) { $mark = 'gleich'; }
    else { $mark = 'ABWEICHUNG ' . ($m - $n > 0 ? '+' : '') . ($m - $n); $problems[] = "Anzahl {$table}: WP {$n}, Spiegel {$m}"; }
    printf("   %-21s %10s %10s  %s\n", $table, $n === null ? '–' : $n, $m === null ? '?' : $m, $mark);
}

// ── 2. Stichproben ──
echo "\n2. Stichproben (neueste)\n";
$cmp = function ($label, array $want, $row) use (&$problems) {
    if (!$row) { echo "   {$label}: FEHLT im Spiegel\n"; $problems[] = "{$label} fehlt"; return; }
    $diff = [];
    foreach ($want as $k => $v) {
        $have = $row[$k] ?? null;
        $same = is_float($v) || is_int($v) ? abs((float) $have - (float) $v) < 0.005 : (string) $have === (string) $v;
        if (!$same) $diff[] = "{$k}: WP " . var_export($v, true) . " / Spiegel " . var_export($have, true);
    }
    echo "   {$label}: " . ($diff ? 'ABWEICHUNG – ' . implode('; ', $diff) : 'gleich') . "\n";
    if ($diff) $problems[] = $label;
};
$one = function ($table, $id) use ($site) {
    $r = TIX_Mirror_Client::select($table, ['site' => 'eq.' . $site, 'id' => 'eq.' . $id]);
    return $r['ok'] ? ($r['body'][0] ?? null) : null;
};
$cnt = function ($table, $col, $id) use ($site) { return TIX_Mirror_Client::count($table, ['site' => 'eq.' . $site, $col => 'eq.' . $id]); };

foreach ((array) $wpdb->get_results("SELECT id, status, total, billing_email FROM {$wpdb->prefix}tix_orders WHERE id NOT IN ($sb_orders) ORDER BY id DESC LIMIT 5", ARRAY_A) as $o) {
    $oid = (int) $o['id'];
    $items = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d", $oid));
    $tix = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'tix_ticket' WHERE m.meta_key = '_tix_ticket_order_id' AND m.meta_value = %s", (string) $oid));
    $row = $one('orders', $oid);
    if ($row) { $row['_items'] = $cnt('order_items', 'order_id', $oid); $row['_tickets'] = $cnt('tickets', 'order_id', $oid); }
    $cmp("Bestellung #{$oid}", ['status' => $o['status'], 'total' => (float) $o['total'], 'billing_email' => $o['billing_email'], '_items' => $items, '_tickets' => $tix], $row);
}
// neueste Tickets ohne Sandbox (direkt in SQL, damit viele Demo-Tickets die Stichprobe nicht leeren)
foreach ((array) $wpdb->get_col("SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'tix_ticket' AND p.post_status <> 'auto-draft'"
    . ($sbe ? " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_tix_ticket_event_id' AND m.meta_value IN ($in_e))" : '')
    . " ORDER BY p.ID DESC LIMIT 5") as $tid) {
    $ci = get_post_meta($tid, '_tix_ticket_checked_in', true);
    $cmp("Ticket #{$tid}", [
        'code'        => (string) get_post_meta($tid, '_tix_ticket_code', true),
        'status'      => (string) (get_post_meta($tid, '_tix_ticket_status', true) ?: 'valid'),
        'post_status' => get_post_status($tid),
        'checked_in'  => (!empty($ci) && $ci !== '0' && $ci !== 'no' && $ci !== 'false') ? '1' : '',
    ], ($r = $one('tickets', $tid)) ? array_merge($r, ['checked_in' => $r['checked_in'] ? '1' : '']) : null);
}
foreach (array_slice(array_reverse($event_ids), 0, 3) as $eid) {
    $c = get_post_meta($eid, '_tix_ticket_categories', true);
    $row = $one('events', $eid);
    if ($row) { $row['_cats'] = $cnt('ticket_categories', 'event_id', $eid); $row['_guests'] = $cnt('guests', 'event_id', $eid); }
    $cmp("Event #{$eid}", ['title' => get_post_field('post_title', $eid), 'status' => get_post_status($eid),
        '_cats' => is_array($c) ? count(array_filter($c, 'is_array')) : 0, '_guests' => count(TIX_Mirror_Map::guest_rows('', $eid))], $row);
}
if ($wp['wallets']) {
    foreach ((array) $wpdb->get_results("SELECT id, user_id, balance_paid, balance_bonus, status FROM {$wpdb->prefix}tix_wallets ORDER BY updated DESC LIMIT 3", ARRAY_A) as $w) {
        $cmp("Geldbörse #{$w['id']}", ['user_id' => (int) $w['user_id'], 'balance_paid' => (int) $w['balance_paid'], 'balance_bonus' => (int) $w['balance_bonus'], 'status' => $w['status']], $one('wallets', (int) $w['id']));
    }
}

// ── 3. Datenschutz: ALLE Zeilen je Tabelle, auch der Verlauf (change_log) ──
echo "\n3. Datenschutz (alle Zeilen, inkl. Verlauf)\n";
// Feldnamen wie in TIX_Mirror_Map::DENY; Spalten, die bewusst so heißen, sind ausgenommen.
$key_re  = '/"([^"]*(pass|token|secret|nonce|code_hash|public_key|pushover|_pin|pin_|hash|_key|wfls_|2fa|totp|recovery|application_password|credential|session)[^"]*)"\s*:/i';
$key_ok  = ['idempotency_key', 'issuer_key']; // Spalten, keine Geheimnisse
$hash_re = '/"\$(P|H|wp|2y|2a|2b|argon2id?|scrypt|[156])\$/';
$iban_re = '/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]){11,30}\b/';
foreach (['customers', 'organizers', 'orders', 'order_items', 'payments', 'tickets', 'events', 'guests', 'loyalty', 'coupons',
          'wallets', 'wallet_transactions', 'settlements', 'settlement_items', 'invoices', 'audit_log', 'checkins', 'refunds', 'change_log'] as $table) {
    $hits = []; $rows = 0; $err = '';
    for ($offset = 0; ; $offset += 1000) {
        $r = TIX_Mirror_Client::select($table, ['site' => 'eq.' . $site, 'order' => $table === 'change_log' ? 'id.asc' : 'id.asc', 'limit' => '1000', 'offset' => (string) $offset]);
        if (!$r['ok']) { $err = $r['error']; break; }
        $chunk = (array) $r['body'];
        foreach ($chunk as $row) {
            // Verschlüsselte Werte (m1:/v1:) und Maskierungen sind erlaubt
            $json = preg_replace('/"(iban_enc|iban_masked)"\s*:\s*"[^"]*"/', '', wp_json_encode($row));
            if (preg_match_all($key_re, $json, $m)) {
                foreach ($m[1] as $k) if (!in_array($k, $key_ok, true)) { $hits['Feld ' . $k] = ($hits['Feld ' . $k] ?? 0) + 1; }
            }
            if (preg_match($hash_re, $json)) $hits['Hash-Wert'] = ($hits['Hash-Wert'] ?? 0) + 1;
            if (preg_match($iban_re, $json)) $hits['Klartext-IBAN'] = ($hits['Klartext-IBAN'] ?? 0) + 1;
        }
        $rows += count($chunk);
        if (count($chunk) < 1000) break;
    }
    if ($err !== '') { echo "   {$table}: FEHLER beim Lesen\n"; $problems[] = "Lesen {$table}"; continue; }
    echo "   {$table}: {$rows} Zeilen · " . ($hits ? 'TREFFER ' . implode(', ', array_map(function ($k, $v) { return "{$k} {$v}×"; }, array_keys($hits), $hits)) : 'sauber') . "\n";
    foreach ($hits as $k => $v) $problems[] = "{$table}: {$k}";
}

// Geschenkgutscheine (1.38.496): Käufer gelöscht, Gutschein trägt aber noch eine echte E-Mail (Stand WordPress = Spiegel)
$gc = get_option('tix_coupons', []);
$left = 0;
foreach (is_array($gc) ? $gc : [] as $c) {
    if (!is_array($c) || empty($c['order_id'])) continue;
    $be = (string) $wpdb->get_var($wpdb->prepare("SELECT billing_email FROM {$wpdb->prefix}tix_orders WHERE id = %d", (int) $c['order_id']));
    if (!TIX_Mirror_Map::is_anonymized($be)) continue;
    $j = preg_replace('/[^" ]*@removed\.invalid/i', '', wp_json_encode($c));
    if (preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $j)) $left++;
}
echo "   Gutscheine mit Empfänger-Adresse trotz gelöschtem Käufer: " . ($left ? "{$left} – TREFFER" : 'keine') . "\n";
if ($left) $problems[] = "Gutscheine: {$left} mit Empfänger-Adresse trotz gelöschtem Käufer";

echo "\nErgebnis: " . ($problems ? count($problems) . " Auffälligkeit(en)\n - " . implode("\n - ", $problems) . "\n" : "alles gleich und sauber\n");
