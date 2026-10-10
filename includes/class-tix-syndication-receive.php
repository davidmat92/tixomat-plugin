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

    /** Über den Schlüssel erkannte Quelle der laufenden Anfrage (Partner-Verzeichnis) */
    private static $partner = null;

    /** Meta, die nie vom Sender übernommen wird (Herkunft, Zuordnung, Quell-IDs) */
    const PROTECTED_META = [
        '_tix_syndicated', '_tix_source_url', '_tix_source_site', '_tix_source_id', '_tix_source_checkout',
        '_tix_source_partner', '_tix_source_api', '_tix_partner_sales', '_tix_syndicated_image_url',
        '_tix_organizer_id', '_tix_location_id',
    ];

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
        self::$partner = null;
        if (!tix_get_settings('syndication_receive_enabled')) {
            return new WP_Error('disabled', 'Syndication-Empfang ist deaktiviert.', ['status' => 403]);
        }

        $key = (string) $request->get_header('X-Tix-Syndication-Key');

        // Eigener Schlüssel je Quelle (Partner-Verzeichnis): Quelle steht damit fest
        if (class_exists('TIX_Partners') && ($p = TIX_Partners::find_by_key($key))) {
            self::$partner = $p;
            return true;
        }

        // Übergangsweise: gemeinsamer Schlüssel (Quelle nennt sich selbst) – abschaltbar;
        // fehlt die Einstellung (ältere Installationen), bleibt er gültig
        $shared_on = tix_get_settings('syndication_shared_key_enabled');
        if ($shared_on !== null && empty($shared_on)) {
            return new WP_Error('unauthorized', 'Gemeinsamer Syndication-Key ist abgeschaltet – bitte Partner-Schlüssel verwenden.', ['status' => 401]);
        }
        $expected = (string) tix_get_settings('syndication_receive_key');
        if ($key === '' || $expected === '' || !hash_equals($expected, $key)) {
            return new WP_Error('unauthorized', 'Ungültiger Syndication-Key.', ['status' => 401]);
        }

        return true;
    }

    /** Darf die Quelle der laufenden Anfrage dieses geteilte Event ändern? */
    private static function owns($event_id, $data = []) {
        if (get_post_meta($event_id, '_tix_syndicated', true) !== '1') {
            return new WP_Error('not_syndicated', 'Dieses Event ist nicht syndiziert.', ['status' => 403]);
        }
        $owner = (string) get_post_meta($event_id, '_tix_source_partner', true);
        if (self::$partner) {
            if ($owner === self::$partner['id']) return true;
            // Altes Event (vor dem Partner-Verzeichnis) übernehmen, wenn der Name passt
            $site = sanitize_text_field($data['source_site'] ?? '');
            if ($owner === '' && $site !== '' && $site === (string) get_post_meta($event_id, '_tix_source_site', true)) return true;
        } elseif ($owner === '') {
            return true;
        }
        return new WP_Error('forbidden', 'Dieses Event gehört zu einer anderen Quelle.', ['status' => 403]);
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
        if (self::$partner) $source_site = self::$partner['name'];

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
        self::apply_source($event_id, $data);

        // _tix_* Meta-Felder übernehmen (ohne Herkunft und Quell-IDs)
        self::apply_meta($event_id, $data);

        // Kategorien zuweisen
        self::apply_categories($event_id, $data['categories'] ?? []);

        // Beitragsbild importieren
        self::import_featured_image($event_id, $data['featured_image'] ?? '');

        // Tages-Alarme: beim Veröffentlichen fehlten Datum und Ort noch
        if (class_exists('TIX_Day_Alerts') && get_post_status($event_id) === 'publish') TIX_Day_Alerts::notify_event($event_id);

        return rest_ensure_response([
            'event_id' => $event_id,
            'status'   => 'created',
            'partner'  => self::$partner ? self::$partner['id'] : null,
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
        $owns = self::owns($event_id, is_array($data) ? $data : []);
        if (is_wp_error($owns)) return $owns;

        return self::update_event($event_id, is_array($data) ? $data : []);
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

        // Nur syndizierte Events der eigenen Quelle löschen
        $owns = self::owns($event_id);
        if (is_wp_error($owns)) return $owns;

        wp_trash_post($event_id);

        return rest_ensure_response(['status' => 'deleted']);
    }

    // ──────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────

    /**
     * Bestehendes syndiziertes Event finden: bei bekannter Quelle über Partner + Quell-ID,
     * sonst (und für alte Events ohne Partner) über Name + Quell-ID.
     */
    private static function find_by_source($source_id, $source_site) {
        if (self::$partner) {
            $events = get_posts([
                'post_type'      => 'event',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => [
                    'relation' => 'AND',
                    ['key' => '_tix_source_id', 'value' => $source_id],
                    ['key' => '_tix_source_partner', 'value' => self::$partner['id']],
                    ['key' => '_tix_syndicated', 'value' => '1'],
                ],
            ]);
            if (!empty($events)) return $events[0];
        }
        if ($source_site === '') return null;
        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_tix_source_id', 'value' => $source_id],
                ['key' => '_tix_source_site', 'value' => $source_site],
                ['key' => '_tix_syndicated', 'value' => '1'],
                ['key' => '_tix_source_partner', 'compare' => 'NOT EXISTS'],
            ],
        ]);

        return !empty($events) ? $events[0] : null;
    }

    /**
     * Herkunft setzen: Partner, Quell-API, Häkchen „über die Plattform verkaufen“ und
     * geschützte Checkout-URL (nur auf dem Host der Quelle).
     */
    private static function apply_source($event_id, $data) {
        $source_url = (string) get_post_meta($event_id, '_tix_source_url', true);
        if (self::$partner) {
            update_post_meta($event_id, '_tix_source_partner', self::$partner['id']);
            update_post_meta($event_id, '_tix_source_site', self::$partner['name']);
            update_post_meta($event_id, '_tix_partner_sales', !empty($data['partner_sales']) ? '1' : '0');
            if (!empty($data['source_api'])) {
                update_post_meta($event_id, '_tix_source_api', esc_url_raw($data['source_api']));
            }
            $host = strtolower((string) wp_parse_url(self::$partner['api_base'], PHP_URL_HOST));
        } else {
            $host = strtolower((string) wp_parse_url($source_url, PHP_URL_HOST));
        }
        if (array_key_exists('source_checkout', (array) $data) || !metadata_exists('post', $event_id, '_tix_source_checkout')) {
            $checkout = esc_url_raw($data['source_checkout'] ?? '');
            if ($checkout === '' || strtolower((string) wp_parse_url($checkout, PHP_URL_HOST)) !== $host) {
                $checkout = strtolower((string) wp_parse_url($source_url, PHP_URL_HOST)) === $host ? $source_url : '';
            }
            update_post_meta($event_id, '_tix_source_checkout', $checkout);
        }
    }

    /**
     * Event updaten
     */
    private static function update_event($event_id, $data) {
        $was_published = get_post_status($event_id) === 'publish';
        $update = ['ID' => $event_id];
        if (isset($data['title']))   $update['post_title'] = sanitize_text_field($data['title']);
        if (isset($data['excerpt'])) $update['post_excerpt'] = sanitize_textarea_field($data['excerpt']);
        if (isset($data['status']))  $update['post_status'] = $data['status'] === 'publish' ? 'publish' : 'draft';

        wp_update_post($update);

        // Source-URL updaten (bei bekannter Quelle nur auf deren Host)
        if (isset($data['source_url'])) {
            $url  = esc_url_raw($data['source_url']);
            $host = self::$partner ? strtolower((string) wp_parse_url(self::$partner['api_base'], PHP_URL_HOST)) : '';
            if ($host === '' || strtolower((string) wp_parse_url($url, PHP_URL_HOST)) === $host) {
                update_post_meta($event_id, '_tix_source_url', $url);
            }
        }
        // Nur-Bestand-Update (stock_only) lässt Herkunft und Häkchen unverändert
        if (empty($data['stock_only'])) {
            self::apply_source($event_id, $data);
        }

        // _tix_* Meta-Felder übernehmen (ohne Herkunft und Quell-IDs)
        self::apply_meta($event_id, $data);

        // Kategorien
        if (isset($data['categories'])) {
            self::apply_categories($event_id, $data['categories']);
        }

        // Beitragsbild updaten
        if (!empty($data['featured_image'])) {
            self::import_featured_image($event_id, $data['featured_image']);
        }

        // Tages-Alarme: erst jetzt veröffentlicht → mit den neuen Metas benachrichtigen
        if (!$was_published && class_exists('TIX_Day_Alerts') && get_post_status($event_id) === 'publish') {
            TIX_Day_Alerts::notify_event($event_id);
        }

        return rest_ensure_response([
            'event_id' => $event_id,
            'status'   => 'updated',
            'partner'  => self::$partner ? self::$partner['id'] : null,
        ]);
    }

    /**
     * _tix_* Meta-Felder übernehmen. Herkunft, Syndication-Felder und Quell-IDs werden nie
     * überschrieben; Quell-IDs zeigen hier ins Leere bzw. auf fremde Datensätze:
     * _tix_organizer_id → zugeordneter Veranstalter des Partners (sonst leer),
     * _tix_location_id → leer (Ort bleibt als Text/Adresse), product_id in Kategorien → entfernt.
     */
    private static function apply_meta($event_id, $data) {
        if (!empty($data['meta']) && is_array($data['meta'])) {
            foreach ($data['meta'] as $key => $value) {
                $key = (string) $key;
                if (strpos($key, '_tix_') !== 0) continue;
                if (strpos($key, '_tix_syndicate') === 0) continue;
                if (in_array($key, self::PROTECTED_META, true)) continue;
                // Wiederholung macht die Quelle (Termine kommen einzeln); Serien-Metas tragen fremde IDs
                if (strpos($key, '_tix_recurrence') === 0) continue;
                // Ort-Koordinaten setzt nur das Feld `venue` bzw. das Geocoding hier (apply_venue)
                if (strpos($key, '_tix_venue_') === 0) continue;
                if ($key === '_tix_ticket_categories' && is_array($value)) {
                    foreach ($value as $i => $cat) {
                        if (is_array($cat)) unset($value[$i]['product_id']);
                    }
                }
                update_post_meta($event_id, $key, $value);
            }
        }

        $org = self::$partner ? intval(self::$partner['organizer_id']) : 0;
        if ($org > 0) {
            update_post_meta($event_id, '_tix_organizer_id', $org);
        } else {
            delete_post_meta($event_id, '_tix_organizer_id');
        }
        delete_post_meta($event_id, '_tix_location_id');

        // Nur-Bestand-Update: Ort bleibt unverändert
        if (empty($data['stock_only'])) self::apply_venue($event_id, $data);

        // Serien-Metas älterer Übertragungen entfernen; hier daraus angelegte Termine
        // ohne Verkäufe wegräumen (sonst stünde jeder Partner-Termin doppelt da)
        if (metadata_exists('post', $event_id, '_tix_recurrence') || metadata_exists('post', $event_id, '_tix_recurrence_parent')) {
            if (class_exists('TIX_Recurrence')) TIX_Recurrence::retire_future($event_id);
            foreach (['_tix_recurrence', '_tix_recurrence_until', '_tix_recurrence_count', '_tix_recurrence_skip',
                      '_tix_recurrence_parent', '_tix_recurrence_date', '_tix_recurrence_hash'] as $k) {
                delete_post_meta($event_id, $k);
            }
        }
    }

    /**
     * Ort-Koordinaten der Quelle übernehmen (Feld `venue`). Ältere Partner
     * schicken es nicht → bisherige Koordinaten bleiben. Fehlen danach Koordinaten,
     * ermittelt diese Seite sie im Hintergrund aus der Adresse (TIX_Venues).
     */
    private static function apply_venue($event_id, $data) {
        $v = (isset($data['venue']) && is_array($data['venue'])) ? $data['venue'] : null;
        if ($v !== null) {
            $city = sanitize_text_field((string) ($v['city'] ?? ''));
            $zip  = sanitize_text_field((string) ($v['zip'] ?? ''));
            if ($city !== '') update_post_meta($event_id, '_tix_venue_city', $city); else delete_post_meta($event_id, '_tix_venue_city');
            if ($zip !== '')  update_post_meta($event_id, '_tix_venue_zip', $zip);   else delete_post_meta($event_id, '_tix_venue_zip');
            $c = class_exists('TIX_Venues') ? TIX_Venues::clean_coords($v['lat'] ?? null, $v['lng'] ?? null) : null;
            if ($c) {
                update_post_meta($event_id, '_tix_venue_lat', (string) $c[0]);
                update_post_meta($event_id, '_tix_venue_lng', (string) $c[1]);
                update_post_meta($event_id, '_tix_venue_geo_auto', 'source');
                delete_post_meta($event_id, '_tix_venue_geo_query');
                delete_post_meta($event_id, '_tix_venue_geo_failed');
            } elseif (get_post_meta($event_id, '_tix_venue_geo_auto', true) === 'source') {
                // Quelle hat keine Koordinaten (mehr) → ihre alten nicht stehen lassen
                foreach (['_tix_venue_lat', '_tix_venue_lng', '_tix_venue_geo_auto'] as $k) delete_post_meta($event_id, $k);
            }
        }
        if (class_exists('TIX_Venues')) {
            list($lat) = TIX_Venues::event_coords($event_id);
            $auto = get_post_meta($event_id, '_tix_venue_geo_auto', true);
            // fehlend oder automatisch ermittelt (Adresse kann sich geändert haben)
            if ($lat === null || $auto === '1') TIX_Venues::queue_event_geocode($event_id);
        }
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
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
