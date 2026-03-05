<?php
/**
 * Tixomat – Airtable Sync
 *
 * Synchronisiert Ticketdaten aus der Custom-DB-Tabelle
 * per REST-API an eine Airtable-Base (Lookup + Create/Update).
 *
 * @since 1.27.0
 */

if (!defined('ABSPATH')) exit;

class TIX_Sync_Airtable {

    const API_BASE = 'https://api.airtable.com/v0';

    // ──────────────────────────────────────────
    // Aktiviert?
    // ──────────────────────────────────────────
    public static function is_enabled() {
        if (!class_exists('TIX_Settings')) return false;
        return (bool) TIX_Settings::get('airtable_enabled')
            && !empty(TIX_Settings::get('airtable_api_key'))
            && !empty(TIX_Settings::get('airtable_base_id'));
    }

    // ──────────────────────────────────────────
    // Einzelnes Ticket synchronisieren
    // ──────────────────────────────────────────
    public static function sync_ticket(array $data) {
        if (!self::is_enabled()) return false;

        $code = $data['ticket_code'] ?? '';
        if (empty($code)) return false;

        // Prüfen ob Record bereits existiert
        $existing = self::find_record_by_code($code);

        $fields = self::map_fields($data);

        if ($existing) {
            // Update
            $result = self::api_request('PATCH', self::endpoint() . '/' . $existing, [
                'fields' => $fields,
            ]);
        } else {
            // Create
            $result = self::api_request('POST', self::endpoint(), [
                'fields' => $fields,
            ]);
        }

        if ($result && !isset($result['error'])) {
            if (class_exists('TIX_Ticket_DB')) {
                TIX_Ticket_DB::mark_synced($code, 'airtable');
            }
            return true;
        }

        if (isset($result['error'])) {
            error_log('[Tixomat Airtable] Sync-Fehler: ' . ($result['error']['message'] ?? json_encode($result['error'])));
        }

        return false;
    }

    // ──────────────────────────────────────────
    // Batch-Sync (alle unsynced Records)
    // ──────────────────────────────────────────
    public static function sync_batch(int $limit = 50) {
        if (!self::is_enabled() || !class_exists('TIX_Ticket_DB')) return ['synced' => 0, 'failed' => 0];

        $rows   = TIX_Ticket_DB::get_unsynced('airtable', $limit);
        $synced = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $data = (array) $row;
            if (self::sync_ticket($data)) {
                $synced++;
            } else {
                $failed++;
            }
            // Rate-Limit: 5 req/sec → 250ms zwischen Requests
            // Da wir ggf. 2 Requests pro Ticket machen (lookup + upsert), 300ms
            usleep(300000);
        }

        return ['synced' => $synced, 'failed' => $failed, 'remaining' => max(0, TIX_Ticket_DB::count_unsynced('airtable'))];
    }

    // ──────────────────────────────────────────
    // Verbindungstest
    // ──────────────────────────────────────────
    public static function test_connection() {
        $key     = TIX_Settings::get('airtable_api_key');
        $base_id = TIX_Settings::get('airtable_base_id');

        if (empty($key) || empty($base_id)) {
            return ['success' => false, 'message' => 'API-Key und Base-ID erforderlich.'];
        }

        $result = self::api_request('GET', self::endpoint() . '?maxRecords=1');

        if ($result === null) {
            return ['success' => false, 'message' => 'Verbindung fehlgeschlagen.'];
        }

        if (isset($result['error'])) {
            return ['success' => false, 'message' => $result['error']['message'] ?? 'Unbekannter Fehler.'];
        }

        return ['success' => true, 'message' => 'Verbindung erfolgreich.'];
    }

    // ──────────────────────────────────────────
    // Record suchen per ticket_code
    // ──────────────────────────────────────────
    private static function find_record_by_code(string $code) {
        $formula = urlencode("{ticket_code}='" . addslashes($code) . "'");
        $url     = self::endpoint() . '?filterByFormula=' . $formula . '&maxRecords=1';

        $result = self::api_request('GET', $url);

        if ($result && !empty($result['records'][0]['id'])) {
            return $result['records'][0]['id'];
        }

        return null;
    }

    // ──────────────────────────────────────────
    // API-Endpoint
    // ──────────────────────────────────────────
    private static function endpoint() {
        $base_id = TIX_Settings::get('airtable_base_id');
        $table   = TIX_Settings::get('airtable_table') ?: 'Tickets';
        return self::API_BASE . '/' . rawurlencode($base_id) . '/' . rawurlencode($table);
    }

    // ──────────────────────────────────────────
    // API-Request Helper
    // ──────────────────────────────────────────
    private static function api_request(string $method, string $url, $body = null) {
        $key = TIX_Settings::get('airtable_api_key');
        if (empty($key)) return null;

        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            error_log('[Tixomat Airtable] ' . $response->get_error_message());
            return null;
        }

        $code  = wp_remote_retrieve_response_code($response);
        $json  = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300) {
            return $json ?: [];
        }

        // Fehler loggen
        error_log('[Tixomat Airtable] HTTP ' . $code . ': ' . wp_remote_retrieve_body($response));
        return $json ?: ['error' => ['message' => 'HTTP ' . $code]];
    }

    // ──────────────────────────────────────────
    // Feld-Mapping (DB → Airtable)
    // ──────────────────────────────────────────
    private static function map_fields(array $data) {
        // 1:1 Mapping — Airtable-Feldnamen = DB-Spalten
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
        ];

        $mapped = [];
        foreach ($fields as $f) {
            if (isset($data[$f])) {
                $mapped[$f] = $data[$f];
            }
        }

        // Typen casten
        if (isset($mapped['event_id']))     $mapped['event_id']     = (int) $mapped['event_id'];
        if (isset($mapped['order_id']))     $mapped['order_id']     = (int) $mapped['order_id'];
        if (isset($mapped['ticket_price'])) $mapped['ticket_price'] = (float) $mapped['ticket_price'];
        if (isset($mapped['checked_in']))   $mapped['checked_in']   = (bool)  $mapped['checked_in'];
        if (isset($mapped['newsletter_optin'])) $mapped['newsletter_optin'] = (bool) $mapped['newsletter_optin'];

        return $mapped;
    }
}
