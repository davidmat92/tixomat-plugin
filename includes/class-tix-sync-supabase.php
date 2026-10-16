<?php
/**
 * Tixomat – Supabase (abgelöst)
 *
 * Bis Datenspiegel Paket 1 schickte diese Klasse nur Tickets als flache Zeile in eine
 * Tabelle des ALTEN Supabase-Projekts. Sie ist durch den Datenspiegel (TIX_Mirror,
 * Tixomat → Datenspiegel) ersetzt und bleibt nur als Weiche für alte Aufrufer
 * (Admin-Knöpfe „Verbindung testen“ / „Alle jetzt synchronisieren“).
 * Das alte Projekt wird nicht mehr beschrieben.
 *
 * @since 1.27.0, abgelöst mit Datenspiegel Paket 1
 */

if (!defined('ABSPATH')) exit;

class TIX_Sync_Supabase {

    public static function init() {}

    /** Die alte Einzel-Ticket-Anbindung ist immer aus. */
    public static function is_enabled() {
        return false;
    }

    /** Altes Ticket-Format → Ticket im Datenspiegel vormerken. */
    public static function sync_ticket(array $data) {
        if (!class_exists('TIX_Mirror') || empty($data['ticket_code'])) return false;
        $id = (int) ($data['ticket_post_id'] ?? 0);
        if (!$id && class_exists('TIX_Tickets') && method_exists('TIX_Tickets', 'get_ticket_by_code')) {
            $p = TIX_Tickets::get_ticket_by_code($data['ticket_code']);
            $id = $p ? (int) $p->ID : 0;
        }
        if ($id) TIX_Mirror::touch('ticket', $id);
        return (bool) $id;
    }

    /** „Alle jetzt synchronisieren“ → eine Runde des Datenspiegels. */
    public static function sync_batch(int $limit = 50) {
        if (!class_exists('TIX_Mirror') || !TIX_Mirror::active()) return ['synced' => 0, 'failed' => 0, 'remaining' => 0];
        $r = TIX_Mirror::process_batch(max(1, $limit));
        return ['synced' => $r['sent'], 'failed' => $r['failed'], 'remaining' => TIX_Mirror::pending_count()];
    }

    public static function test_connection() {
        return class_exists('TIX_Mirror_Client') ? TIX_Mirror_Client::test() : ['success' => false, 'message' => 'Datenspiegel fehlt.'];
    }
}
