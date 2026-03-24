<?php
/**
 * TIX Syndication Receive — Events von Selfhosted-Installationen empfangen
 *
 * REST-Endpoints zum Erstellen/Updaten/Löschen von syndizierten Events.
 * Syndizierte Events werden als normale Events erstellt, aber bei Ticketkauf
 * zur Quellseite weitergeleitet.
 */
if (!defined('ABSPATH')) exit;

class TIX_Syndication_Receive {

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    /**
     * REST-Routes registrieren
     */
    public static function register_routes() {
        register_rest_route('tixomat/v1', '/syndicate', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_create'],
            'permission_callback' => [__CLASS__, 'check_auth'],
        ]);

        register_rest_route('tixomat/v1', '/syndicate/(?P<id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [__CLASS__, 'handle_update'],
                'permission_callback' => [__CLASS__, 'check_auth'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [__CLASS__, 'handle_delete'],
                'permission_callback' => [__CLASS__, 'check_auth'],
            ],
        ]);
    }

    /**
     * Auth: X-Tix-Syndication-Key Header prüfen
     */
    public static function check_auth($request) {
        if (!tix_get_settings('syndication_receive_enabled')) {
            return new WP_Error('disabled', 'Syndication-Empfang ist deaktiviert.', ['status' => 403]);
        }

        $key = $request->get_header('X-Tix-Syndication-Key');
        $expected = tix_get_settings('syndication_receive_key');

        if (!$key || !$expected || !hash_equals($expected, $key)) {
            return new WP_Error('unauthorized', 'Ungültiger Syndication-Key.', ['status' => 401]);
        }

        return true;
    }

    /**
     * POST /syndicate — Neues Event erstellen oder bestehendes updaten
     */
    public static function handle_create($request) {
        $data = $request->get_json_params();

        $source_id   = intval($data['source_id'] ?? 0);
        $source_url  = esc_url_raw($data['source_url'] ?? '');
        $source_site = sanitize_text_field($data['source_site'] ?? '');
        $title       = sanitize_text_field($data['title'] ?? '');

        if (!$source_id || !$title) {
            return new WP_Error('invalid', 'source_id und title sind erforderlich.', ['status' => 400]);
        }

        // Existiert bereits ein Event für diese source_id?
        $existing = self::find_by_source($source_id, $source_site);
        if ($existing) {
            // Update statt Create
            return self::update_event($existing, $data);
        }

        // Neues Event erstellen
        $event_id = wp_insert_post([
            'post_type'    => 'event',
            'post_title'   => $title,
            'post_excerpt' => sanitize_textarea_field($data['excerpt'] ?? ''),
            'post_status'  => ($data['status'] ?? 'publish') === 'publish' ? 'publish' : 'draft',
            'post_author'  => 1, // Admin
        ]);

        if (is_wp_error($event_id)) {
            return new WP_Error('create_failed', $event_id->get_error_message(), ['status' => 500]);
        }

        // Syndication-Meta setzen
        update_post_meta($event_id, '_tix_syndicated', '1');
        update_post_meta($event_id, '_tix_source_url', $source_url);
        update_post_meta($event_id, '_tix_source_site', $source_site);
        update_post_meta($event_id, '_tix_source_id', $source_id);
        update_post_meta($event_id, '_tix_source_checkout', esc_url_raw($data['source_checkout'] ?? $source_url));

        // Alle _tix_* Meta-Felder setzen (1:1 vom Sender)
        self::apply_meta($event_id, $data);

        // Kategorien zuweisen
        self::apply_categories($event_id, $data['categories'] ?? []);

        // Beitragsbild importieren
        self::import_featured_image($event_id, $data['featured_image'] ?? '');

        return rest_ensure_response([
            'event_id' => $event_id,
            'status'   => 'created',
        ]);
    }

    /**
     * PATCH /syndicate/{id} — Event updaten
     */
    public static function handle_update($request) {
        $event_id = intval($request['id']);
        $data = $request->get_json_params();

        $post = get_post($event_id);
        if (!$post || $post->post_type !== 'event') {
            return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        }

        return self::update_event($event_id, $data);
    }

    /**
     * DELETE /syndicate/{id} — Event entfernen
     */
    public static function handle_delete($request) {
        $event_id = intval($request['id']);

        $post = get_post($event_id);
        if (!$post || $post->post_type !== 'event') {
            return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        }

        // Nur syndizierte Events löschen
        if (!get_post_meta($event_id, '_tix_syndicated', true)) {
            return new WP_Error('not_syndicated', 'Dieses Event ist nicht syndiziert.', ['status' => 403]);
        }

        wp_trash_post($event_id);

        return rest_ensure_response(['status' => 'deleted']);
    }

    // ──────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────

    /**
     * Bestehendes syndiziertes Event finden
     */
    private static function find_by_source($source_id, $source_site) {
        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_tix_source_id', 'value' => $source_id],
                ['key' => '_tix_source_site', 'value' => $source_site],
                ['key' => '_tix_syndicated', 'value' => '1'],
            ],
        ]);

        return !empty($events) ? $events[0]->ID : null;
    }

    /**
     * Event updaten
     */
    private static function update_event($event_id, $data) {
        $update = ['ID' => $event_id];
        if (isset($data['title']))   $update['post_title'] = sanitize_text_field($data['title']);
        if (isset($data['excerpt'])) $update['post_excerpt'] = sanitize_textarea_field($data['excerpt']);
        if (isset($data['status']))  $update['post_status'] = $data['status'] === 'publish' ? 'publish' : 'draft';

        wp_update_post($update);

        // Source-URL updaten
        if (isset($data['source_url'])) {
            update_post_meta($event_id, '_tix_source_url', esc_url_raw($data['source_url']));
        }
        if (isset($data['source_checkout'])) {
            update_post_meta($event_id, '_tix_source_checkout', esc_url_raw($data['source_checkout']));
        }

        // Meta-Felder 1:1 übernehmen
        self::apply_meta($event_id, $data);

        // Kategorien
        if (isset($data['categories'])) {
            self::apply_categories($event_id, $data['categories']);
        }

        // Beitragsbild updaten
        if (!empty($data['featured_image'])) {
            self::import_featured_image($event_id, $data['featured_image']);
        }

        return rest_ensure_response([
            'event_id' => $event_id,
            'status'   => 'updated',
        ]);
    }

    /**
     * Alle _tix_* Meta-Felder setzen
     */
    private static function apply_meta($event_id, $data) {
        if (empty($data['meta']) || !is_array($data['meta'])) return;

        foreach ($data['meta'] as $key => $value) {
            if (strpos($key, '_tix_') !== 0) continue;
            // Syndication-Keys nicht überschreiben
            if (strpos($key, '_tix_syndicate') === 0) continue;
            if ($key === '_tix_source_url' || $key === '_tix_source_site' || $key === '_tix_source_id') continue;

            update_post_meta($event_id, $key, $value);
        }
    }

    /**
     * Kategorien zuweisen (erstellen wenn nötig)
     */
    private static function apply_categories($event_id, $categories) {
        if (empty($categories) || !is_array($categories)) return;

        $term_ids = [];
        foreach ($categories as $name) {
            $term = get_term_by('name', $name, 'event_category');
            if ($term) {
                $term_ids[] = $term->term_id;
            } else {
                $new = wp_insert_term($name, 'event_category');
                if (!is_wp_error($new)) {
                    $term_ids[] = $new['term_id'];
                }
            }
        }

        if (!empty($term_ids)) {
            wp_set_object_terms($event_id, $term_ids, 'event_category');
        }
    }

    /**
     * Beitragsbild von URL importieren
     */
    private static function import_featured_image($event_id, $image_url) {
        if (empty($image_url)) return;

        // Nur importieren wenn sich das Bild geändert hat
        $current_url = get_post_meta($event_id, '_tix_syndicated_image_url', true);
        if ($current_url === $image_url) return;

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_sideload_image($image_url, $event_id, '', 'id');
        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($event_id, $attachment_id);
            update_post_meta($event_id, '_tix_syndicated_image_url', $image_url);
        }
    }
}
