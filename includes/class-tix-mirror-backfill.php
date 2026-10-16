<?php
/**
 * Tixomat – Datenspiegel: Erstbefüllung
 *
 * Schickt den kompletten Bestand einer Seite in Paketen über den Postausgang
 * (Seite → Events → Kunden → Bestellungen → Tickets). Der Stand (Typ + letzte ID)
 * steht in der Option tix_mirror_backfill; Anhalten und Fortsetzen machen an derselben
 * Stelle weiter, ein abgebrochener Lauf ebenso. Läuft im Cron des Spiegels, nimmt nur
 * Neues auf, solange der Postausgang klein ist (Gegendruck), und startet von selbst,
 * sobald der Spiegel das erste Mal eingerichtet ist.
 *
 * @since Datenspiegel Paket 1 (Version vergibt die Zentrale)
 */

if (!defined('ABSPATH')) exit;

class TIX_Mirror_Backfill {

    const OPTION   = 'tix_mirror_backfill';
    const CHUNK    = 500;   // IDs je Schritt
    const MAX_OUT  = 3000;  // höchstens so viele offene Einträge, sonst warten
    // Neue Schritte nur hinten anhängen: Seiten mit fertiger Erstbefüllung holen dann nur die neuen nach.
    const STEPS    = ['site', 'event', 'customer', 'order', 'ticket', 'organizer', 'coupons', 'wallet', 'wallet_tx', 'settlement', 'settlement_item', 'invoice', 'invoice_counters'];

    public static function init() {
        // Nichts zu registrieren: step() läuft in TIX_Mirror::cron_run().
    }

    public static function get() {
        $s = get_option(self::OPTION, []);
        return wp_parse_args(is_array($s) ? $s : [], [
            'state' => 'idle', 'step' => 0, 'last_id' => 0, 'queued' => [], 'started_at' => 0, 'finished_at' => 0,
        ]);
    }

    private static function put(array $s) {
        update_option(self::OPTION, $s, false);
    }

    public static function start() {
        self::put(['state' => 'running', 'step' => 0, 'last_id' => 0, 'queued' => [], 'started_at' => time(), 'finished_at' => 0]);
    }

    public static function stop() {
        $s = self::get();
        if ($s['state'] === 'running') { $s['state'] = 'paused'; self::put($s); }
    }

    public static function resume() {
        $s = self::get();
        if ($s['state'] === 'paused') { $s['state'] = 'running'; self::put($s); }
    }

    public static function status() {
        $s = self::get();
        $q = [];
        foreach ((array) $s['queued'] as $k => $n) $q[] = $k . ' ' . (int) $n;
        $q = $q ? ' (' . implode(', ', $q) . ')' : '';
        switch ($s['state']) {
            case 'running':
                $step = self::STEPS[(int) $s['step']] ?? '';
                return ['state' => 'running', 'label' => 'läuft: ' . $step . ' ab ID ' . (int) $s['last_id'] . $q];
            case 'paused':
                return ['state' => 'paused', 'label' => 'angehalten bei ' . (self::STEPS[(int) $s['step']] ?? '') . ' ab ID ' . (int) $s['last_id'] . $q];
            case 'done':
                return ['state' => 'done', 'label' => 'fertig am ' . wp_date('d.m.Y H:i', (int) $s['finished_at']) . $q];
        }
        return ['state' => 'idle', 'label' => 'noch nicht gelaufen'];
    }

    /**
     * Ein Stück Erstbefüllung (im Cron, Spiegel ist stummgeschaltet → hier bewusst entstummen).
     */
    public static function step($seconds = 10) {
        $s = self::get();
        if ($s['state'] === 'idle' && TIX_Mirror::active()) { self::start(); $s = self::get(); } // erstmals eingerichtet
        if ($s['state'] === 'done' && (int) ($s['steps_total'] ?? 0) > 0 && (int) $s['steps_total'] < count(self::STEPS)) {
            // Update mit neuen Datenarten: nur die neuen Schritte nachholen.
            $s = array_merge($s, ['state' => 'running', 'step' => (int) $s['steps_total'], 'last_id' => 0]);
        }
        if ($s['state'] !== 'running') return;
        $until = microtime(true) + $seconds;
        $muted = TIX_Mirror::unmuted_begin();
        try {
            while (microtime(true) < $until) {
                if (TIX_Mirror::pending_count() >= self::MAX_OUT) break;
                if (!TIX_Mirror::capturing()) break; // Spiegel aus → Zeiger nicht weiterschieben
                $entity = self::STEPS[(int) $s['step']] ?? null;
                if ($entity === null) {
                    $s['state'] = 'done';
                    $s['finished_at'] = time();
                    $s['steps_total'] = count(self::STEPS);
                    break;
                }
                $ids = self::next_ids($entity, (int) $s['last_id']);
                if (!$ids) { $s['step']++; $s['last_id'] = 0; continue; }
                $single = in_array($entity, ['site', 'coupons', 'invoice_counters'], true);
                foreach ($ids as $id) TIX_Mirror::touch($entity, $entity === 'site' ? 'site' : ($single ? 'all' : (int) $id));
                if ($entity === 'coupons') delete_option(TIX_Mirror_Extra::COUPON_FP); // alles senden, Überzähliges löschen
                TIX_Mirror::flush_buffer();
                $s['last_id'] = $single ? PHP_INT_MAX : (int) end($ids);
                $s['queued'][$entity] = (int) ($s['queued'][$entity] ?? 0) + count($ids);
                self::put($s); // nach jedem Paket sichern → unterbrechbar
            }
        } finally {
            TIX_Mirror::unmuted_end($muted);
            self::put($s);
        }
    }

    private static function next_ids($entity, $after) {
        global $wpdb;
        switch ($entity) {
            case 'site':
            case 'coupons':
            case 'invoice_counters':
                return $after > 0 ? [] : [1];
            case 'organizer':
                return array_map('intval', $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tix_organizer' AND post_status <> 'auto-draft' AND ID > %d ORDER BY ID LIMIT %d",
                    $after, self::CHUNK
                )));
            case 'wallet':
            case 'wallet_tx':
            case 'settlement':
            case 'settlement_item':
            case 'invoice':
                $tables = ['wallet' => 'tix_wallets', 'wallet_tx' => 'tix_wallet_tx', 'settlement' => 'tix_settlements', 'settlement_item' => 'tix_settlement_items', 'invoice' => 'tix_invoices'];
                $prev = $wpdb->suppress_errors(true);
                $ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}{$tables[$entity]} WHERE id > %d ORDER BY id LIMIT %d", $after, self::CHUNK
                ));
                $wpdb->suppress_errors($prev);
                return array_map('intval', (array) $ids); // Tabelle fehlt (z. B. ohne Mehr-Veranstalter) → leer
            case 'event':
            case 'ticket':
                $type = $entity === 'event' ? 'event' : 'tix_ticket';
                return array_map('intval', $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft' AND ID > %d ORDER BY ID LIMIT %d",
                    $type, $after, self::CHUNK
                )));
            case 'customer':
                return array_map('intval', $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID LIMIT %d", $after, self::CHUNK
                )));
            case 'order':
                $prev = $wpdb->suppress_errors(true);
                $ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}tix_orders WHERE id > %d ORDER BY id LIMIT %d", $after, self::CHUNK
                ));
                $wpdb->suppress_errors($prev);
                return array_map('intval', (array) $ids);
        }
        return [];
    }
}
