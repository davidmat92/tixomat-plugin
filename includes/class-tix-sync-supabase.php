<?php
/**
 * Tixomat – Supabase Sync
 *
 * Synchronisiert Ticketdaten aus der Custom-DB-Tabelle
 * per REST-API an eine Supabase-Tabelle (Upsert).
 *
 * @since 1.27.0
 */

if (!defined('ABSPATH')) exit;

class TIX_Sync_Supabase {

    // ──────────────────────────────────────────
    // Aktiviert?
    // ──────────────────────────────────────────
    public static function is_enabled() {
        if (!class_exists('TIX_Settings')) return false;
        return (bool) TIX_Settings::get('supabase_enabled')
            && !empty(TIX_Settings::get('supabase_url'))
            && !empty(TIX_Settings::get('supabase_api_key'));
    }

    // ──────────────────────────────────────────
    // Einzelnes Ticket synchronisieren (Upsert)
    // ──────────────────────────────────────────
    public static function sync_ticket(array $data) {
        if (!self::is_enabled()) return false;

        $url   = rtrim(TIX_Settings::get('supabase_url'), '/');
        $key   = TIX_Settings::get('supabase_api_key');
        $table = TIX_Settings::get('supabase_table') ?: 'tickets';

        $endpoint = $url . '/rest/v1/' . rawurlencode($table);

        // Daten für Supabase aufbereiten
        $payload = self::map_fields($data);

        $response = wp_remote_post($endpoint, [
            'timeout' => 15,
            'headers' => [
                'apikey'        => $key,
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'resolution=merge-duplicates',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            error_log('[Tixomat Supabase] Sync-Fehler: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            // Sync-Flag setzen
            if (!empty($data['ticket_code']) && class_exists('TIX_Ticket_DB')) {
                TIX_Ticket_DB::mark_synced($data['ticket_code'], 'supabase');
            }
            return true;
        }

        error_log('[Tixomat Supabase] HTTP ' . $code . ': ' . wp_remote_retrieve_body($response));
        return false;
    }

    // ──────────────────────────────────────────
    // Batch-Sync (alle unsynced Records)
    // ──────────────────────────────────────────
    public static function sync_batch(int $limit = 50) {
        if (!self::is_enabled() || !class_exists('TIX_Ticket_DB')) return ['synced' => 0, 'failed' => 0];

        $rows   = TIX_Ticket_DB::get_unsynced('supabase', $limit);
        $synced = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $data = (array) $row;
            if (self::sync_ticket($data)) {
                $synced++;
            } else {
                $failed++;
            }
            // Rate-Limit: max ~2 req/sec
            usleep(500000);
        }

        return ['synced' => $synced, 'failed' => $failed, 'remaining' => max(0, TIX_Ticket_DB::count_unsynced('supabase'))];
    }

    // ──────────────────────────────────────────
    // Verbindungstest
    // ──────────────────────────────────────────
    public static function test_connection() {
        $url = rtrim(TIX_Settings::get('supabase_url'), '/');
        $key = TIX_Settings::get('supabase_api_key');

        if (empty($url) || empty($key)) {
            return ['success' => false, 'message' => 'URL und API-Key erforderlich.'];
        }

        $table    = TIX_Settings::get('supabase_table') ?: 'tickets';
        $endpoint = $url . '/rest/v1/' . rawurlencode($table) . '?limit=1';

        $response = wp_remote_get($endpoint, [
            'timeout' => 10,
            'headers' => [
                'apikey'        => $key,
                'Authorization' => 'Bearer ' . $key,
            ],
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'message' => 'Verbindung erfolgreich.'];
        }

        $body = wp_remote_retrieve_body($response);
        $msg  = 'HTTP ' . $code;
        $json = json_decode($body, true);
        if (!empty($json['message'])) $msg .= ': ' . $json['message'];

        return ['success' => false, 'message' => $msg];
    }

    // ──────────────────────────────────────────
    // Feld-Mapping (DB → Supabase)
    // ──────────────────────────────────────────
    private static function map_fields(array $data) {
        // 1:1 Mapping — Spalten-Namen bleiben gleich
        $fields = [
            'ticket_code',
            'event_id',
            'event_name',
            'order_id',
            'category_name',
            'buyer_name',
            'buyer_email',
            'buyer_phone',
            'buyer_company',
            'buyer_city',
            'buyer_zip',
            'buyer_country',
            'seat_id',
            'ticket_status',
            'ticket_price',
            'checked_in',
            'checkin_time',
            'newsletter_optin',
            'created_at',
            'updated_at',
        ];

        $mapped = [];
        foreach ($fields as $f) {
            if (isset($data[$f])) {
                $mapped[$f] = $data[$f];
            }
        }

        // Numerische Werte casten
        if (isset($mapped['event_id']))        $mapped['event_id']        = (int)   $mapped['event_id'];
        if (isset($mapped['order_id']))        $mapped['order_id']        = (int)   $mapped['order_id'];
        if (isset($mapped['ticket_price']))    $mapped['ticket_price']    = (float) $mapped['ticket_price'];
        if (isset($mapped['checked_in']))      $mapped['checked_in']      = (bool)  $mapped['checked_in'];
        if (isset($mapped['newsletter_optin']))$mapped['newsletter_optin']= (bool)  $mapped['newsletter_optin'];

        return $mapped;
    }
}
