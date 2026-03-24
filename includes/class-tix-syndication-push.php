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
            'categories'      => $categories,
            'meta'            => $all_meta,
        ];

        // Push oder Update?
        $remote_id = get_post_meta($post_id, '_tix_syndicate_remote_id', true);
        if ($remote_id) {
            $result = self::api_call('PATCH', '/syndicate/' . intval($remote_id), $payload);
        } else {
            $result = self::api_call('POST', '/syndicate', $payload);
        }

        if ($result && isset($result['event_id'])) {
            update_post_meta($post_id, '_tix_syndicate_remote_id', intval($result['event_id']));
            update_post_meta($post_id, '_tix_syndicate_status', 'synced');
            update_post_meta($post_id, '_tix_syndicate_last', current_time('mysql'));
        } else {
            $error = $result['message'] ?? 'Unbekannter Fehler';
            update_post_meta($post_id, '_tix_syndicate_status', 'error');
            update_post_meta($post_id, '_tix_syndicate_error', $error);
            error_log('[TIX Syndication] Push-Fehler für Event #' . $post_id . ': ' . $error);
        }
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

        return ['message' => $body['message'] ?? ('HTTP ' . $code)];
    }
}
