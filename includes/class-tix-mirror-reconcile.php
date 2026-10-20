<?php
/**
 * Tixomat – Datenspiegel: täglicher Abgleich mit Bericht
 *
 * Vergleicht je Tabelle in Paketen die IDs und eine kleine Prüfsumme über die wichtigsten
 * Felder (Status, Beträge, E-Mail, Check-in …) zwischen WordPress und Spiegel. Beide Seiten
 * rechnen md5 über dieselben, gleich formatierten Felder (WordPress hier, Supabase in
 * mirror_fingerprints). Fehlt etwas, weicht es ab oder ist es im Spiegel zu viel, kommt der
 * Datensatz in den Postausgang – der Sender schickt den aktuellen Stand bzw. löscht.
 * Danach: Bericht (Option tix_mirror_report + Tabelle sync_reports) und Verlauf kürzen.
 *
 * @since Datenspiegel Paket 2 (Version vergibt die Zentrale)
 */

if (!defined('ABSPATH')) exit;

class TIX_Mirror_Reconcile {

    const OPTION = 'tix_mirror_reconcile';
    const REPORT = 'tix_mirror_report';
    const CHUNK  = 1000;
    const HOUR   = 3;       // frühestens ab 3 Uhr Ortszeit

    /** Tabelle im Spiegel => Typ im Postausgang. Reihenfolge = Abgleich-Reihenfolge. */
    public static function tables() {
        return [
            'events'              => 'event',
            'customers'           => 'customer',
            'orders'              => 'order',
            'tickets'             => 'ticket',
            'organizers'          => 'organizer',
            'wallets'             => 'wallet',
            'wallet_transactions' => 'wallet_tx',
            'settlements'         => 'settlement',
            'settlement_items'    => 'settlement_item',
            'invoices'            => 'invoice',
            'audit_log'           => 'audit',
        ];
    }

    public static function get() {
        $s = get_option(self::OPTION, []);
        return wp_parse_args(is_array($s) ? $s : [], ['state' => 'idle', 'idx' => 0, 'after' => 0, 'started_at' => 0, 'finished_at' => 0, 'stats' => []]);
    }

    private static function put(array $s) { update_option(self::OPTION, $s, false); }

    public static function start() {
        self::put(['state' => 'running', 'idx' => 0, 'after' => 0, 'started_at' => time(), 'finished_at' => 0, 'stats' => []]);
        // Gutscheine haben Text-Schlüssel: täglich komplett senden und Überzähliges löschen
        // (der Spiegel-Trigger protokolliert nur echte Änderungen).
        if (class_exists('TIX_Mirror_Extra')) {
            delete_option(TIX_Mirror_Extra::COUPON_FP);
            $m = TIX_Mirror::unmuted_begin();
            TIX_Mirror::touch('coupons', 'all');
            TIX_Mirror::touch('invoice_counters', 'all');
            TIX_Mirror::flush_buffer();
            TIX_Mirror::unmuted_end($m);
        }
    }

    public static function report() {
        $r = get_option(self::REPORT, []);
        return is_array($r) ? $r : [];
    }

    /** Fällig? Einmal am Tag ab 3 Uhr, nur wenn die Erstbefüllung durch ist. */
    public static function due() {
        $s = self::get();
        if ($s['state'] === 'running') return true;
        if (class_exists('TIX_Mirror_Backfill') && TIX_Mirror_Backfill::get()['state'] !== 'done') return false;
        // Erst wenn alles gesendet ist: „fertig“ der Erstbefüllung heißt nur „alles im Postausgang“. Sonst meldet der
        // erste Abgleich direkt nach dem Einschalten lauter „fehlt“ (Fund 2026-10-16 auf allen drei Seiten).
        if (TIX_Mirror::pending_count() > 0) return false;
        $now = new DateTimeImmutable('now', wp_timezone());
        if ((int) $now->format('G') < self::HOUR) return false;
        $today = $now->setTime(self::HOUR, 0)->getTimestamp();
        return (int) $s['finished_at'] < $today && (int) $s['started_at'] < $today;
    }

    /** Ein Stück Abgleich im Cron. */
    public static function step($seconds = 10) {
        if (!self::due()) return;
        $s = self::get();
        if ($s['state'] !== 'running') { self::start(); $s = self::get(); }
        if (TIX_Mirror::pending_count() >= 3000) return; // erst Rückstand abbauen
        $until = microtime(true) + $seconds;
        $tables = array_keys(self::tables());
        $muted = TIX_Mirror::unmuted_begin();
        try {
            while (microtime(true) < $until) {
                $table = $tables[(int) $s['idx']] ?? null;
                if ($table === null) { self::finish($s); return; }
                $r = self::compare_chunk($table, (int) $s['after']);
                if (is_wp_error($r)) {
                    $s['stats'][$table]['error'] = $r->get_error_message();
                    $s['idx']++; $s['after'] = 0;
                } else {
                    foreach (['wp', 'mirror', 'missing', 'extra', 'differ'] as $k) {
                        $s['stats'][$table][$k] = (int) ($s['stats'][$table][$k] ?? 0) + $r[$k];
                    }
                    if ($r['done']) { $s['idx']++; $s['after'] = 0; } else { $s['after'] = $r['last']; }
                }
                TIX_Mirror::flush_buffer();
                self::put($s);
            }
        } finally {
            TIX_Mirror::unmuted_end($muted);
        }
    }

    /**
     * Ein Paket vergleichen: WordPress-IDs > $after (bis zu CHUNK), Spiegel im selben ID-Bereich.
     * Beim letzten Paket gehört alles oberhalb der letzten WP-ID dazu (Überzähliges im Spiegel).
     */
    public static function compare_chunk($table, $after) {
        $wp = self::wp_fingerprints($table, $after, self::CHUNK);
        $done = count($wp) < self::CHUNK;
        $last = $wp ? max(array_keys($wp)) : $after;
        // Demo/Vorschau (Sandbox) erst NACH der Paketgrenze herausnehmen: gilt dann im Spiegel als „zu viel“ und wird gelöscht
        $wp = self::without_sandbox($table, $wp);
        $to = $done ? PHP_INT_MAX : $last;
        $res = TIX_Mirror_Client::rpc('mirror_fingerprints', ['p_site' => TIX_Mirror::site(), 'p_table' => $table, 'p_from' => $after, 'p_to' => $to]);
        if (!$res['ok']) return new WP_Error('rpc', $res['error']);
        $mirror = is_array($res['body']) ? $res['body'] : [];
        $entity = self::tables()[$table];
        $out = ['wp' => count($wp), 'mirror' => count($mirror), 'missing' => 0, 'extra' => 0, 'differ' => 0, 'done' => $done, 'last' => $last];
        foreach ($wp as $id => $h) {
            if (!isset($mirror[(string) $id])) { $out['missing']++; TIX_Mirror::touch($entity, $id); }
            elseif ($mirror[(string) $id] !== $h) { $out['differ']++; TIX_Mirror::touch($entity, $id); }
        }
        foreach ($mirror as $id => $h) {
            if (!isset($wp[(int) $id])) { $out['extra']++; TIX_Mirror::touch($entity, (int) $id); }
        }
        return $out;
    }

    private static function finish(array $s) {
        $s['state'] = 'idle';
        $s['finished_at'] = time();
        self::put($s);
        $diff = 0;
        foreach ($s['stats'] as $st) $diff += (int) ($st['missing'] ?? 0) + (int) ($st['extra'] ?? 0) + (int) ($st['differ'] ?? 0) + (empty($st['error']) ? 0 : 1);
        // Verwaiste Kinder entfernen (Gäste/Kategorien ohne Event, Positionen/Zahlungen ohne Bestellung).
        $or = TIX_Mirror_Client::rpc('mirror_prune_orphans', ['p_site' => TIX_Mirror::site()]);
        if ($or['ok'] && is_array($or['body'])) {
            $s['stats']['orphans'] = ['removed' => array_sum(array_map('intval', $or['body']))] + $or['body'];
            $diff += (int) $s['stats']['orphans']['removed'];
        } elseif (!$or['ok']) {
            $s['stats']['orphans'] = ['error' => $or['error']];
        }
        // Verlauf kürzen (Einstellung „Verlauf aufbewahren“, Standard 10 Jahre).
        $days = TIX_Mirror::log_days();
        $pr = TIX_Mirror_Client::rpc('mirror_prune_log', ['p_site' => TIX_Mirror::site(), 'p_days' => $days]);
        $report = [
            'at'          => time(),
            'duration'    => time() - (int) $s['started_at'],
            'differences' => $diff,
            'tables'      => $s['stats'],
            'pending'     => TIX_Mirror::pending_count(),
            'log_days'    => $days,
            'log_pruned'  => $pr['ok'] ? (int) $pr['body'] : null,
            'log_error'   => $pr['ok'] ? '' : $pr['error'],
        ];
        update_option(self::REPORT, $report, false);
        TIX_Mirror_Client::upsert('sync_reports', [[
            'site'        => TIX_Mirror::site(),
            'id'          => wp_date('Y-m-d'),
            'at'          => gmdate('c'),
            'differences' => $diff,
            'report'      => $report,
        ]]);
    }

    // ═══════════════ Sandbox (Live-Demo) ═══════════════

    private static $any_sandbox = null;

    /** Gibt es auf dieser Seite überhaupt Sandbox-Veranstalter? (sonst kein Mehraufwand) */
    public static function any_sandbox() {
        if (self::$any_sandbox === null) {
            global $wpdb;
            self::$any_sandbox = false;
            foreach ((array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tix_organizer' AND post_status <> 'auto-draft'") as $oid) {
                if (TIX_Mirror_Map::is_sandbox_org((int) $oid)) { self::$any_sandbox = true; break; }
            }
        }
        return self::$any_sandbox;
    }

    public static function sandbox_reset() { self::$any_sandbox = null; }

    /** Prüfsummen ohne Datensätze von Sandbox-Veranstaltern (Demo Club, Vorschauen). */
    public static function without_sandbox($table, array $wp) {
        if (!$wp || !self::any_sandbox()) return $wp;
        global $wpdb;
        $ids = array_map('intval', array_keys($wp));
        $in = implode(',', $ids);
        $drop = [];
        $prev = $wpdb->suppress_errors(true);
        switch ($table) {
            case 'events':     foreach ($ids as $id) if (TIX_Mirror_Map::is_sandbox_event($id)) $drop[] = $id; break;
            case 'tickets':    foreach ($ids as $id) if (TIX_Mirror_Map::is_sandbox_event((int) get_post_meta($id, '_tix_ticket_event_id', true))) $drop[] = $id; break;
            case 'organizers': foreach ($ids as $id) if (TIX_Mirror_Map::is_sandbox_org($id)) $drop[] = $id; break;
            case 'orders':
                foreach ((array) $wpdb->get_results("SELECT DISTINCT order_id, event_id FROM {$wpdb->prefix}tix_order_items WHERE order_id IN ($in)", ARRAY_A) as $r) {
                    if (TIX_Mirror_Map::is_sandbox_event((int) $r['event_id'])) $drop[] = (int) $r['order_id'];
                }
                break;
            case 'wallets':
            case 'settlements':
            case 'settlement_items':
            case 'invoices':
                $t = ['wallets' => 'tix_wallets', 'settlements' => 'tix_settlements', 'settlement_items' => 'tix_settlement_items', 'invoices' => 'tix_invoices'][$table];
                foreach ((array) $wpdb->get_results("SELECT id, organizer_id FROM {$wpdb->prefix}{$t} WHERE id IN ($in)", ARRAY_A) as $r) {
                    if (TIX_Mirror_Map::is_sandbox_org((int) $r['organizer_id'])) $drop[] = (int) $r['id'];
                }
                break;
            case 'wallet_transactions':
                foreach ((array) $wpdb->get_results("SELECT x.id, w.organizer_id AS wo FROM {$wpdb->prefix}tix_wallet_tx x LEFT JOIN {$wpdb->prefix}tix_wallets w ON w.id = x.wallet_id WHERE x.id IN ($in)", ARRAY_A) as $r) {
                    if (TIX_Mirror_Map::is_sandbox_org((int) $r['wo'])) $drop[] = (int) $r['id'];
                }
                // ab Wallet-DB 3 trägt die Buchung selbst den Veranstalter (Plattform-Cashless)
                foreach ((array) $wpdb->get_results("SELECT id, organizer_id FROM {$wpdb->prefix}tix_wallet_tx WHERE id IN ($in) AND organizer_id <> 0", ARRAY_A) as $r) {
                    if (TIX_Mirror_Map::is_sandbox_org((int) $r['organizer_id'])) $drop[] = (int) $r['id'];
                }
                break;
            case 'audit_log':
                foreach ((array) $wpdb->get_results("SELECT id, scope FROM {$wpdb->prefix}tix_audit_log WHERE id IN ($in)", ARRAY_A) as $r) {
                    if (preg_match('/:(\d+)$/', (string) $r['scope'], $m) && TIX_Mirror_Map::is_sandbox_org((int) $m[1])) $drop[] = (int) $r['id'];
                }
                break;
        }
        $wpdb->suppress_errors($prev);
        foreach ($drop as $id) unset($wp[$id]);
        return $wp;
    }

    // ═══════════════ Prüfsummen auf WordPress-Seite ═══════════════
    // Format je Tabelle exakt wie mirror_fingerprints() in supabase/migrations.

    public static function fp(array $parts) {
        return md5(implode('|', array_map('strval', $parts)));
    }

    private static function money($v) { return number_format((float) $v, 2, '.', ''); }
    private static function bool($v) { return $v ? '1' : '0'; }

    /** @return array<int,string> id => md5 */
    public static function wp_fingerprints($table, $after, $limit) {
        global $wpdb;
        $out = [];
        switch ($table) {
            case 'events':
            case 'tickets':
                $type = $table === 'events' ? 'event' : 'tix_ticket';
                $posts = $wpdb->get_results($wpdb->prepare(
                    "SELECT ID, post_status, post_title, post_parent FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft' AND ID > %d ORDER BY ID LIMIT %d",
                    $type, $after, $limit
                ));
                if (!$posts) return [];
                update_meta_cache('post', wp_list_pluck($posts, 'ID'));
                foreach ($posts as $p) {
                    $id = (int) $p->ID;
                    if ($table === 'events') {
                        $cats = get_post_meta($id, '_tix_ticket_categories', true);
                        $n = is_array($cats) ? count(array_filter($cats, 'is_array')) : 0;
                        $out[$id] = self::fp([$p->post_status, wp_check_invalid_utf8($p->post_title, true), $n, count(TIX_Mirror_Map::guest_rows('', $id))]);
                    } else {
                        $oid = (int) (get_post_meta($id, '_tix_ticket_order_id', true) ?: $p->post_parent);
                        $ci = get_post_meta($id, '_tix_ticket_checked_in', true);
                        $out[$id] = self::fp([
                            $p->post_status,
                            (string) (get_post_meta($id, '_tix_ticket_status', true) ?: 'valid'),
                            self::bool(!empty($ci) && $ci !== '0' && $ci !== 'no' && $ci !== 'false'),
                            (string) get_post_meta($id, '_tix_ticket_owner_email', true),
                            $oid > 0 ? (string) $oid : '',
                        ]);
                    }
                }
                return $out;

            case 'customers':
                $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID LIMIT %d", $after, $limit)));
                if (!$ids) return [];
                cache_users($ids);
                foreach ($ids as $id) {
                    $u = get_userdata($id);
                    if (!$u) continue;
                    $out[$id] = self::fp([$u->user_email, $u->display_name, implode(',', array_values((array) $u->roles))]);
                }
                return $out;

            case 'orders':
                $prev = $wpdb->suppress_errors(true);
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, status, total, billing_email FROM {$wpdb->prefix}tix_orders WHERE id > %d ORDER BY id LIMIT %d", $after, $limit
                ), ARRAY_A);
                $wpdb->suppress_errors($prev);
                if (!$rows) return [];
                $names = array_map(function ($r) { return '_tix_refund_total_' . (int) $r['id']; }, $rows);
                $ref = [];
                foreach ($wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ('" . implode("','", array_map('esc_sql', $names)) . "')", ARRAY_A) as $o) {
                    $ref[(int) substr($o['option_name'], 18)] = maybe_unserialize($o['option_value']);
                }
                foreach ($rows as $r) {
                    $id = (int) $r['id'];
                    $rt = $ref[$id] ?? 0;
                    $out[$id] = self::fp([$r['status'], self::money($r['total']), $r['billing_email'], self::money(is_numeric($rt) ? $rt : 0)]);
                }
                return $out;

            case 'organizers':
                $posts = $wpdb->get_results($wpdb->prepare(
                    "SELECT ID, post_status, post_title FROM {$wpdb->posts} WHERE post_type = 'tix_organizer' AND post_status <> 'auto-draft' AND ID > %d ORDER BY ID LIMIT %d",
                    $after, $limit
                ));
                if (!$posts) return [];
                update_meta_cache('post', wp_list_pluck($posts, 'ID'));
                foreach ($posts as $p) {
                    $enc = (string) get_post_meta($p->ID, '_tix_payout_iban', true);
                    $plain = $enc !== '' && class_exists('TIX_Payout_Details') ? TIX_Payout_Details::decrypt($enc) : '';
                    $masked = $plain !== '' && class_exists('TIX_Payout_Details') ? TIX_Payout_Details::mask_iban($plain) : '';
                    $out[(int) $p->ID] = self::fp([$p->post_status, html_entity_decode($p->post_title, ENT_QUOTES, 'UTF-8'),
                        (string) (get_post_meta($p->ID, '_tix_org_approval', true) ?: 'approved'), $masked]);
                }
                return $out;

            case 'audit_log':
                $prev = $wpdb->suppress_errors(true);
                $rows = $wpdb->get_results($wpdb->prepare("SELECT id, action, subject_id FROM {$wpdb->prefix}tix_audit_log WHERE id > %d ORDER BY id LIMIT %d", $after, $limit), ARRAY_A);
                $wpdb->suppress_errors($prev);
                foreach ((array) $rows as $r) $out[(int) $r['id']] = self::fp([$r['action'], $r['subject_id']]);
                return $out;

            case 'invoices':
                $prev = $wpdb->suppress_errors(true);
                // export_status gibt es ab 1.38.490 (Rechnungs-DB 2); vorher zählt er als ''
                $has_export = (bool) $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}tix_invoices LIKE 'export_status'");
                $rows = $wpdb->get_results($wpdb->prepare("SELECT id, number, kind, gross, pdf_sha256" . ($has_export ? ', export_status' : '') . " FROM {$wpdb->prefix}tix_invoices WHERE id > %d ORDER BY id LIMIT %d", $after, $limit), ARRAY_A);
                $wpdb->suppress_errors($prev);
                foreach ((array) $rows as $r) $out[(int) $r['id']] = self::fp([$r['number'], $r['kind'], self::money($r['gross']), $r['pdf_sha256'], (string) ($r['export_status'] ?? '')]);
                return $out;

            case 'wallets':
            case 'wallet_transactions':
            case 'settlements':
            case 'settlement_items':
                $map = [
                    'wallets'             => ['tix_wallets', 'id, user_id, status, balance_paid, balance_bonus'],
                    'wallet_transactions' => ['tix_wallet_tx', 'id, wallet_id, type, amount_paid, amount_bonus'],
                    'settlements'         => ['tix_settlements', 'id, status, number, payout_amount'],
                    'settlement_items'    => ['tix_settlement_items', 'id, settlement_id, type, amount'],
                ][$table];
                $prev = $wpdb->suppress_errors(true);
                $rows = $wpdb->get_results($wpdb->prepare("SELECT {$map[1]} FROM {$wpdb->prefix}{$map[0]} WHERE id > %d ORDER BY id LIMIT %d", $after, $limit), ARRAY_A);
                $wpdb->suppress_errors($prev);
                foreach ((array) $rows as $r) {
                    $id = (int) $r['id'];
                    switch ($table) {
                        case 'wallets':             $out[$id] = self::fp([(int) $r['user_id'], $r['status'], (int) $r['balance_paid'], (int) $r['balance_bonus']]); break;
                        case 'wallet_transactions': $out[$id] = self::fp([(int) $r['wallet_id'], $r['type'], (int) $r['amount_paid'], (int) $r['amount_bonus']]); break;
                        case 'settlements':         $out[$id] = self::fp([$r['status'], $r['number'], $r['payout_amount'] === null || $r['payout_amount'] === '' ? '' : self::money($r['payout_amount'])]); break;
                        case 'settlement_items':    $out[$id] = self::fp([(int) $r['settlement_id'], $r['type'], $r['amount'] === null || $r['amount'] === '' ? '' : self::money($r['amount'])]); break;
                    }
                }
                return $out;
        }
        return [];
    }
}
