<?php
/**
 * TIX Syndication Push — Events an zentrale Plattform senden
 *
 * Selfhosted-Installationen können Events an Evendis (oder andere Tixomat-Instanzen)
 * pushen. Das Event wird dort nativ erstellt, Ticketkauf leitet zur Quellseite weiter.
 */
if (!defined('ABSPATH')) exit;

class TIX_Syndication_Push {

    public static function init() {
        // Push bei Event-Speichern (nach Sync, Prio 35)
        add_action('save_post_event', [__CLASS__, 'on_save'], 35, 2);
        // Push bei Löschung
        add_action('before_delete_post', [__CLASS__, 'on_delete']);
        // Push bei Status-Wechsel (publish → draft etc.)
        add_action('transition_post_status', [__CLASS__, 'on_status_change'], 10, 3);
        // Bestand nach Bestellung/Storno nachschicken (gebündelt, höchstens 1× pro Minute je Event)
        add_action('tix_native_order_created', [__CLASS__, 'queue_stock_for_order'], 50);
        add_action('tix_order_cancelled', [__CLASS__, 'queue_stock_for_order'], 50);
        add_action('tix_order_completed', [__CLASS__, 'queue_stock_for_order'], 50);
        add_action('tix_syndication_stock_push', [__CLASS__, 'push_stock']);
        // Nach der Kopplung mit einer Plattform: markierte, kommende Events verteilen
        add_action('tix_syndication_push_all', [__CLASS__, 'push_all']);
    }

    /** Alle veröffentlichten, kommenden Events mit Häkchen „Auf Plattform veröffentlichen“ senden. */
    public static function push_all() {
        if (!self::is_configured()) return;
        $ids = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_tix_syndicate', 'value' => '1'],
                ['key' => '_tix_date_start', 'value' => current_time('Y-m-d'), 'compare' => '>=', 'type' => 'DATE'],
            ],
        ]);
        foreach ($ids as $id) self::push_event(intval($id));
    }

    /** Darf die Plattform Tickets dieses Events verkaufen (Partner-API an + Häkchen je Event, Vorgabe an)? */
    public static function partner_sales_enabled($post_id) {
        return class_exists('TIX_Partners') && TIX_Partners::source_api_enabled()
            && get_post_meta($post_id, '_tix_partner_sales', true) !== '0';
    }

    /** Events einer Bestellung, die verteilt sind, zum Bestands-Update vormerken. */
    public static function queue_stock_for_order($order_id) {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT event_id FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d", intval($order_id)
        ));
        foreach ((array) $ids as $event_id) {
            $event_id = intval($event_id);
            if (!$event_id || !get_post_meta($event_id, '_tix_syndicate_remote_id', true)) continue;
            if (!wp_next_scheduled('tix_syndication_stock_push', [$event_id])) {
                wp_schedule_single_event(time() + 60, 'tix_syndication_stock_push', [$event_id]);
            }
        }
    }

    /** Leichtes Update: nur Kategorien (Bestand) und Verkaufsstatus. */
    public static function push_stock($event_id) {
        $event_id  = intval($event_id);
        $remote_id = intval(get_post_meta($event_id, '_tix_syndicate_remote_id', true));
        if (!$remote_id || !self::is_configured() || get_post_status($event_id) !== 'publish') return;
        if (get_post_meta($event_id, '_tix_syndicate', true) !== '1') return;
        wp_cache_delete($event_id, 'post_meta');
        $meta = [];
        foreach (['_tix_ticket_categories', '_tix_status', '_tix_tickets_enabled'] as $k) {
            if (metadata_exists('post', $event_id, $k)) $meta[$k] = get_post_meta($event_id, $k, true);
        }
        $result = self::api_call('PATCH', '/syndicate/' . $remote_id, ['stock_only' => 1, 'meta' => $meta]);
        if (!empty($result['http_code'])) {
            error_log('[TIX Syndication] Bestands-Update für Event #' . $event_id . ' fehlgeschlagen: ' . ($result['message'] ?? ''));
        }
    }

    /**
     * Ist Syndication global aktiviert + konfiguriert?
     */
    public static function is_configured() {
        return tix_get_settings('syndication_enabled')
            && tix_get_settings('syndication_api_url')
            && tix_get_settings('syndication_api_key');
    }

    /**
     * Event speichern → Push wenn Checkbox aktiv
     */
    public static function on_save($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if ($post->post_type !== 'event') return;
        if (!self::is_configured()) return;

        // Checkbox aus POST oder Meta
        $syndicate = isset($_POST['tix_syndicate_to_platform'])
            ? (bool) $_POST['tix_syndicate_to_platform']
            : (bool) get_post_meta($post_id, '_tix_syndicate', true);

        update_post_meta($post_id, '_tix_syndicate', $syndicate ? '1' : '0');
        // Häkchen „Tickets auch über die Plattform verkaufen“ (nur wenn im Formular vorhanden)
        if (isset($_POST['tix_partner_sales'])) {
            update_post_meta($post_id, '_tix_partner_sales', $_POST['tix_partner_sales'] ? '1' : '0');
        }

        if (!$syndicate) {
            // War vorher synced? → DELETE senden
            $remote_id = get_post_meta($post_id, '_tix_syndicate_remote_id', true);
            if ($remote_id) {
                self::api_call('DELETE', '/syndicate/' . intval($remote_id));
                delete_post_meta($post_id, '_tix_syndicate_remote_id');
                delete_post_meta($post_id, '_tix_syndicate_status');
            }
            return;
        }

        // Nur veröffentlichte Events pushen
        if ($post->post_status !== 'publish') return;

        self::push_event($post_id);
    }

    /**
     * Event löschen → DELETE an Plattform
     */
    public static function on_delete($post_id) {
        if (get_post_type($post_id) !== 'event') return;
        if (!self::is_configured()) return;

        $remote_id = get_post_meta($post_id, '_tix_syndicate_remote_id', true);
        if ($remote_id) {
            self::api_call('DELETE', '/syndicate/' . intval($remote_id));
        }
    }

    /**
     * Status-Wechsel → Update senden
     */
    public static function on_status_change($new_status, $old_status, $post) {
        if (!$post || $post->post_type !== 'event') return;
        if ($new_status === $old_status) return;
        if (!self::is_configured()) return;
        if (!get_post_meta($post->ID, '_tix_syndicate', true)) return;

        $remote_id = get_post_meta($post->ID, '_tix_syndicate_remote_id', true);
        if (!$remote_id) return;

        if ($new_status === 'trash' || $new_status === 'draft') {
            // Deaktivieren auf der Plattform
            self::api_call('PATCH', '/syndicate/' . intval($remote_id), [
                'status' => 'draft',
            ]);
        } elseif ($new_status === 'publish' && $old_status !== 'publish') {
            // Erneut pushen
            self::push_event($post->ID);
        }
    }

    /**
     * Komplettes Event an die Plattform senden
     */
    public static function push_event($post_id) {
        $post = get_post($post_id);
        if (!$post) return;

        // ALLE _tix_* Meta-Felder sammeln
        $all_meta = [];
        $raw = get_post_meta($post_id);
        foreach ($raw as $key => $values) {
            if (strpos($key, '_tix_') === 0) {
                // Syndication-Meta nicht mitsenden
                if (strpos($key, '_tix_syndicate') === 0) continue;
                if ($key === '_tix_partner_sales') continue;
                // Wiederholung bleibt hier: jeder Termin wird einzeln übertragen
                if (strpos($key, '_tix_recurrence') === 0) continue;
                $all_meta[$key] = maybe_unserialize($values[0]);
            }
        }

        // Kategorien
        $categories = wp_get_post_terms($post_id, 'event_category', ['fields' => 'names']);
        if (is_wp_error($categories)) $categories = [];

        $payload = [
            'source_id'       => $post_id,
            'source_url'      => get_permalink($post_id),
            'source_site'     => tix_get_settings('syndication_site_name') ?: get_bloginfo('name'),
            'source_checkout' => get_permalink($post_id),
            'title'           => $post->post_title,
            'excerpt'         => $post->post_excerpt,
            'status'          => $post->post_status,
            'featured_image'  => get_the_post_thumbnail_url($post_id, 'full') ?: '',
            // Partner-Verkauf: REST-Basis dieser Seite + Freigabe je Event
            'source_api'      => rest_url('tixomat/v1'),
            'partner_sales'   => self::partner_sales_enabled($post_id),
            'categories'      => $categories,
            'meta'            => $all_meta,
            // Ort mit Koordinaten: die Location bleibt hier, der Empfänger
            // kennt sonst nur Ort/Adresse als Text (Wetter, Umkreissuche brauchen lat/lng)
            'venue'           => self::venue_payload($post_id),
        ];

        // Push oder Update?
        $remote_id = get_post_meta($post_id, '_tix_syndicate_remote_id', true);
        if ($remote_id) {
            $result = self::api_call('PATCH', '/syndicate/' . intval($remote_id), $payload);
            // Remote-Event existiert nicht mehr (z.B. Plattform neu aufgesetzt) → neu anlegen.
            // Der Empfaenger dedupliziert per source_id + source_site, es entsteht kein Doppel.
            if (!empty($result['http_code']) && intval($result['http_code']) === 404) {
                delete_post_meta($post_id, '_tix_syndicate_remote_id');
                $result = self::api_call('POST', '/syndicate', $payload);
            }
        } else {
            $result = self::api_call('POST', '/syndicate', $payload);
        }

        if ($result && isset($result['event_id'])) {
            update_post_meta($post_id, '_tix_syndicate_remote_id', intval($result['event_id']));
            update_post_meta($post_id, '_tix_syndicate_status', 'synced');
            update_post_meta($post_id, '_tix_syndicate_last', current_time('mysql'));
            delete_post_meta($post_id, '_tix_syndicate_error');
        } else {
            $error = $result['message'] ?? 'Unbekannter Fehler';
            update_post_meta($post_id, '_tix_syndicate_status', 'error');
            update_post_meta($post_id, '_tix_syndicate_error', $error);
            error_log('[TIX Syndication] Push-Fehler für Event #' . $post_id . ': ' . $error);
        }
    }

    /** Ort des Events für den Empfänger; fehlen der Location Koordinaten, einmal ermitteln. */
    public static function venue_payload($post_id) {
        if (!class_exists('TIX_Public_Platform')) return null;
        $loc_id = intval(get_post_meta($post_id, '_tix_location_id', true));
        if ($loc_id && class_exists('TIX_Venues') && get_post_type($loc_id) === 'tix_location') {
            TIX_Venues::maybe_geocode($loc_id); // merkt Fehlschläge, fragt also nicht bei jedem Push
        }
        $v = TIX_Public_Platform::venue($post_id);
        $addr = trim((string) get_post_meta($post_id, '_tix_address', true));
        if ($addr === '' && $loc_id) $addr = trim((string) get_post_meta($loc_id, '_tix_loc_address', true));
        return [
            'name'    => (string) $v['name'],
            'address' => $addr,
            'city'    => (string) $v['city'],
            'zip'     => (string) $v['zip'],
            'lat'     => $v['lat'],
            'lng'     => $v['lng'],
        ];
    }

    /**
     * API-Call an die Plattform
     */
    private static function api_call($method, $endpoint, $data = []) {
        $base_url = rtrim(tix_get_settings('syndication_api_url'), '/');
        $api_key  = tix_get_settings('syndication_api_key');

        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Content-Type'            => 'application/json',
                'X-Tix-Syndication-Key'   => $api_key,
            ],
        ];

        if (!empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($base_url . $endpoint, $args);

        if (is_wp_error($response)) {
            error_log('[TIX Syndication] HTTP-Fehler: ' . $response->get_error_message());
            return ['message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300) {
            return $body ?: ['success' => true];
        }

        return ['message' => $body['message'] ?? ('HTTP ' . $code), 'http_code' => $code];
    }
}
