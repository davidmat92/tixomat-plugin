<?php
/**
 * Orte mit Öffnungszeiten („Heute geöffnet“) und Koordinaten (1.38.367).
 *
 *   GET /public/venues/open?date=YYYY-MM-DD[&city=][&near=LAT,LNG&radius=KM]
 *
 * Location-Metabox „App: Ort & Öffnungszeiten“: `_tix_loc_listed` (in der App als
 * Ort mit Öffnungszeiten zeigen), `_tix_loc_hours` (JSON je Wochentag
 * {"mon":[{"open":"22:00","close":"05:00"}],…}; close ≤ open = nach Mitternacht),
 * `_tix_loc_tagline` (Kurzzeile, sonst Kurzbeschreibung), `_tix_loc_organizer_id`,
 * `_tix_loc_lat`/`_tix_loc_lng`.
 *
 * Koordinaten: Beim Speichern einer Location ohne lat/lng, aber mit Adresse fragt
 * das Plugin einmal Nominatim (OpenStreetMap) ab. Nachtragen: `wp tixomat geocode-locations`.
 */
if (!defined('ABSPATH')) exit;

class TIX_Venues {

    const NS   = 'tixomat/v1';
    const TTL  = 60;
    const DAYS = ['mon' => 'Montag', 'tue' => 'Dienstag', 'wed' => 'Mittwoch', 'thu' => 'Donnerstag', 'fri' => 'Freitag', 'sat' => 'Samstag', 'sun' => 'Sonntag'];
    const NOMINATIM = 'https://nominatim.openstreetmap.org/search';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('add_meta_boxes_tix_location', [__CLASS__, 'add_meta_box']);
        add_action('save_post_tix_location', [__CLASS__, 'save'], 20, 2);
        add_action('save_post_tix_location', [__CLASS__, 'on_save_geocode'], 30, 2);
        if (class_exists('TIX_Public_Events')) add_action('save_post_tix_location', ['TIX_Public_Events', 'flush'], 40);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('tixomat geocode-locations', [__CLASS__, 'cli_geocode']);
        }
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/public/venues/open', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_open'],
            'permission_callback' => '__return_true',
        ]);
    }

    // ──────────────────────────────────────────
    //  Öffnungszeiten
    // ──────────────────────────────────────────

    private static function valid_time($t) {
        return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
    }

    /** Öffnungszeiten eines Orts: [day => [{open, close}]] (nur gültige Zeitfenster). */
    public static function hours($loc_id) {
        $raw = get_post_meta($loc_id, '_tix_loc_hours', true);
        if (is_string($raw) && $raw !== '') $raw = json_decode($raw, true);
        return self::clean_hours(is_array($raw) ? $raw : []);
    }

    public static function clean_hours(array $raw) {
        $out = [];
        foreach (array_keys(self::DAYS) as $d) {
            $slots = [];
            foreach ((array) ($raw[$d] ?? []) as $s) {
                if (!is_array($s)) continue;
                $o = (string) ($s['open'] ?? '');
                $c = (string) ($s['close'] ?? '');
                if (!self::valid_time($o) || !self::valid_time($c)) continue;
                $slots[] = ['open' => $o, 'close' => $c];
            }
            usort($slots, function ($a, $b) { return strcmp($a['open'], $b['open']); });
            if ($slots) $out[$d] = $slots;
        }
        return $out;
    }

    /** Wochentag-Schlüssel (mon…sun) eines Datums. */
    public static function weekday_key($date) {
        $keys = array_keys(self::DAYS);
        return $keys[intval(gmdate('N', strtotime($date . ' 12:00:00 UTC'))) - 1];
    }

    private static function city_of($loc_id) {
        $city = trim((string) get_post_meta($loc_id, '_tix_loc_city', true));
        if ($city === '' && class_exists('TIX_Public_Platform')) {
            list($city) = TIX_Public_Platform::parse_city((string) get_post_meta($loc_id, '_tix_loc_address', true));
        }
        return $city;
    }

    private static function coords($loc_id) {
        $la = get_post_meta($loc_id, '_tix_loc_lat', true);
        $ln = get_post_meta($loc_id, '_tix_loc_lng', true);
        if ($la === '' || $ln === '' || !is_numeric($la) || !is_numeric($ln) || floatval($la) == 0) return [null, null];
        return [floatval($la), floatval($ln)];
    }

    private static function image($loc_id) {
        $img = intval(get_post_meta($loc_id, '_tix_loc_image_id', true));
        $url = $img ? wp_get_attachment_image_url($img, 'large') : '';
        if (!$url) $url = get_the_post_thumbnail_url($loc_id, 'large') ?: '';
        return (string) $url;
    }

    // ──────────────────────────────────────────
    //  REST
    // ──────────────────────────────────────────

    /** GET /public/venues/open */
    public static function rest_open(WP_REST_Request $req) {
        $date = (string) $req->get_param('date');
        if ($date === '') $date = current_time('Y-m-d');
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
            return new WP_Error('tix_date', 'Bitte date als JJJJ-MM-TT angeben.', ['status' => 400]);
        }
        $city = sanitize_text_field((string) $req->get_param('city'));
        $near = null;
        $radius = null;
        if ((string) $req->get_param('near') !== '') {
            $near = TIX_Public_Events::parse_near($req->get_param('near'));
            if (!$near) return new WP_Error('tix_near', 'Bitte near als „Breite,Länge“ angeben, z. B. 50.94,6.96.', ['status' => 400]);
            $radius = TIX_Public_Events::parse_radius($req->get_param('radius'));
        }
        $key = 'tix_pub_events_venues_' . md5(wp_json_encode([$date, $city, $near, $radius]));
        $cached = get_transient($key);
        if (is_array($cached)) return rest_ensure_response($cached);

        $wd = self::weekday_key($date);
        $ids = get_posts([
            'post_type'      => 'tix_location',
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'fields'         => 'ids',
            'meta_key'       => '_tix_loc_listed',
            'meta_value'     => '1',
        ]);
        $list = [];
        foreach ($ids as $lid) {
            $slots = self::hours($lid)[$wd] ?? [];
            if (!$slots) continue;
            $vcity = self::city_of($lid);
            if ($city !== '' && strcasecmp($vcity, $city) !== 0) continue;
            list($lat, $lng) = self::coords($lid);
            $dist = null;
            if ($near) {
                if ($lat === null) continue;
                $dist = TIX_Public_Events::distance_km($near[0], $near[1], $lat, $lng);
                if ($dist > $radius) continue;
            }
            $tagline = trim((string) get_post_meta($lid, '_tix_loc_tagline', true));
            if ($tagline === '') $tagline = trim((string) get_post_meta($lid, '_tix_loc_short_desc', true));
            $oid = intval(get_post_meta($lid, '_tix_loc_organizer_id', true));
            $v = [
                'id'             => intval($lid),
                'name'           => (string) get_the_title($lid),
                'city'           => $vcity,
                'zip'            => (string) get_post_meta($lid, '_tix_loc_zip', true),
                'address'        => (string) get_post_meta($lid, '_tix_loc_address', true),
                'image'          => self::image($lid),
                'lat'            => $lat,
                'lng'            => $lng,
                'opens'          => $slots[0]['open'],
                'closes'         => $slots[0]['close'],
                'overnight'      => strcmp($slots[0]['close'], $slots[0]['open']) <= 0,
                'hours'          => $slots,
                'tagline'        => $tagline,
                'organizer_info' => ($oid && class_exists('TIX_Public_Platform')) ? TIX_Public_Platform::organizer_ref($oid) : null,
            ];
            if ($dist !== null) $v['distance_km'] = round($dist, 1);
            $list[] = $v;
        }
        // Mit Standort nach Entfernung, sonst nach Öffnungszeit und Name
        usort($list, function ($a, $b) use ($near) {
            if ($near) return $a['distance_km'] <=> $b['distance_km'];
            $d = strcmp($a['opens'], $b['opens']);
            return $d !== 0 ? $d : strcasecmp($a['name'], $b['name']);
        });
        $resp = ['ok' => true, 'date' => $date, 'weekday' => $wd, 'venues' => $list];
        set_transient($key, $resp, self::TTL);
        return rest_ensure_response($resp);
    }

    // ──────────────────────────────────────────
    //  Metabox
    // ──────────────────────────────────────────

    public static function add_meta_box() {
        add_meta_box('tix_venue_app', 'App: Ort &amp; &Ouml;ffnungszeiten', [__CLASS__, 'render_meta_box'], 'tix_location', 'normal', 'default');
    }

    public static function render_meta_box($post) {
        wp_nonce_field('tix_save_venue_app', 'tix_venue_nonce');
        $listed  = get_post_meta($post->ID, '_tix_loc_listed', true) === '1';
        $tagline = (string) get_post_meta($post->ID, '_tix_loc_tagline', true);
        $org     = intval(get_post_meta($post->ID, '_tix_loc_organizer_id', true));
        $lat     = (string) get_post_meta($post->ID, '_tix_loc_lat', true);
        $lng     = (string) get_post_meta($post->ID, '_tix_loc_lng', true);
        $auto    = get_post_meta($post->ID, '_tix_loc_geo_auto', true) === '1';
        $hours   = self::hours($post->ID);
        $orgs    = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 300, 'orderby' => 'title', 'order' => 'ASC']);
        ?>
        <p><label><input type="checkbox" name="tix_loc_listed" value="1" <?php checked($listed); ?>> <strong>In der App als Ort mit &Ouml;ffnungszeiten zeigen</strong></label><br>
            <span class="description">Erscheint in der App unter &bdquo;Heute ge&ouml;ffnet&ldquo; an Tagen mit &Ouml;ffnungszeiten.</span></p>
        <p><label>Kurzzeile f&uuml;r die App (optional, sonst Kurzbeschreibung)<br>
            <input type="text" name="tix_loc_tagline" value="<?php echo esc_attr($tagline); ?>" maxlength="120" style="width:100%;" placeholder="z. B. Techno &amp; House im Keller"></label></p>
        <?php if ($orgs): ?>
        <p><label>Veranstalter (optional)<br>
            <select name="tix_loc_organizer_id">
                <option value="0">&ndash; keiner &ndash;</option>
                <?php foreach ($orgs as $o): ?>
                    <option value="<?php echo intval($o->ID); ?>" <?php selected($org, $o->ID); ?>><?php echo esc_html($o->post_title); ?></option>
                <?php endforeach; ?>
            </select></label></p>
        <?php endif; ?>
        <table class="widefat striped" style="max-width:520px;">
            <thead><tr><th>Tag</th><th>Ge&ouml;ffnet</th><th>von</th><th>bis</th></tr></thead>
            <tbody>
            <?php foreach (self::DAYS as $k => $label):
                $s = $hours[$k][0] ?? null; ?>
                <tr>
                    <td><?php echo esc_html($label); ?></td>
                    <td><input type="checkbox" name="tix_loc_hours[<?php echo $k; ?>][on]" value="1" <?php checked((bool) $s); ?>></td>
                    <td><input type="time" name="tix_loc_hours[<?php echo $k; ?>][open]" value="<?php echo esc_attr($s['open'] ?? ''); ?>"></td>
                    <td><input type="time" name="tix_loc_hours[<?php echo $k; ?>][close]" value="<?php echo esc_attr($s['close'] ?? ''); ?>"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">&bdquo;bis&ldquo; fr&uuml;her als &bdquo;von&ldquo; = nach Mitternacht (z. B. 22:00&ndash;05:00).</p>
        <p><label>Breite <input type="text" name="tix_loc_lat" value="<?php echo esc_attr($lat); ?>" style="width:120px;"></label>
            <label style="margin-left:12px;">L&auml;nge <input type="text" name="tix_loc_lng" value="<?php echo esc_attr($lng); ?>" style="width:120px;"></label><br>
            <span class="description"><?php echo $auto ? 'Automatisch aus der Adresse ermittelt (OpenStreetMap). ' : ''; ?>Leer lassen = beim Speichern aus der Adresse ermitteln.</span></p>
        <?php
    }

    public static function save($post_id, $post) {
        if (!isset($_POST['tix_venue_nonce']) || !wp_verify_nonce($_POST['tix_venue_nonce'], 'tix_save_venue_app')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, '_tix_loc_listed', !empty($_POST['tix_loc_listed']) ? '1' : '0');
        update_post_meta($post_id, '_tix_loc_tagline', mb_substr(sanitize_text_field(wp_unslash($_POST['tix_loc_tagline'] ?? '')), 0, 120));
        if (isset($_POST['tix_loc_organizer_id'])) update_post_meta($post_id, '_tix_loc_organizer_id', absint($_POST['tix_loc_organizer_id']));

        $raw = isset($_POST['tix_loc_hours']) && is_array($_POST['tix_loc_hours']) ? wp_unslash($_POST['tix_loc_hours']) : [];
        $hours = [];
        foreach (array_keys(self::DAYS) as $d) {
            $r = $raw[$d] ?? [];
            if (empty($r['on'])) continue;
            $hours[$d] = [['open' => sanitize_text_field($r['open'] ?? ''), 'close' => sanitize_text_field($r['close'] ?? '')]];
        }
        update_post_meta($post_id, '_tix_loc_hours', wp_slash(wp_json_encode((object) self::clean_hours($hours))));

        // Koordinaten: von Hand geändert → manuell; geleert → neu ermitteln
        $lat = trim(str_replace(',', '.', sanitize_text_field(wp_unslash($_POST['tix_loc_lat'] ?? ''))));
        $lng = trim(str_replace(',', '.', sanitize_text_field(wp_unslash($_POST['tix_loc_lng'] ?? ''))));
        $old_lat = (string) get_post_meta($post_id, '_tix_loc_lat', true);
        $old_lng = (string) get_post_meta($post_id, '_tix_loc_lng', true);
        if ($lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng)) {
            delete_post_meta($post_id, '_tix_loc_lat');
            delete_post_meta($post_id, '_tix_loc_lng');
            delete_post_meta($post_id, '_tix_loc_geo_auto');
            delete_post_meta($post_id, '_tix_loc_geo_failed');
        } elseif ($lat !== $old_lat || $lng !== $old_lng) {
            update_post_meta($post_id, '_tix_loc_lat', $lat);
            update_post_meta($post_id, '_tix_loc_lng', $lng);
            update_post_meta($post_id, '_tix_loc_geo_auto', '0');
        }
    }

    // ──────────────────────────────────────────
    //  Koordinaten (Nominatim)
    // ──────────────────────────────────────────

    /** Suchtext aus der Adresse der Location ('' = keine Straße hinterlegt). */
    public static function geo_query($loc_id) {
        $street  = trim((string) get_post_meta($loc_id, '_tix_loc_address', true));
        $zip     = trim((string) (get_post_meta($loc_id, '_tix_loc_zip', true) ?: get_post_meta($loc_id, '_tix_loc_postcode', true)));
        $city    = trim((string) get_post_meta($loc_id, '_tix_loc_city', true));
        $country = trim((string) get_post_meta($loc_id, '_tix_loc_country', true));
        if ($street === '') return ''; // nur mit Straße (sonst nur Stadtmitte)
        return implode(', ', array_filter([$street, trim($zip . ' ' . $city), $country]));
    }

    /** Eine Nominatim-Abfrage: [lat, lng] oder null (Fehler werden ignoriert). */
    public static function geocode($query) {
        $url = self::NOMINATIM . '?' . http_build_query(['format' => 'json', 'limit' => 1, 'q' => $query], '', '&', PHP_QUERY_RFC3986);
        $res = wp_remote_get($url, [
            'timeout'    => 5,
            'user-agent' => 'Tixomat/' . (defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : '1') . ' (' . home_url() . ')',
            'headers'    => ['Accept-Language' => 'de'],
        ]);
        if (is_wp_error($res) || intval(wp_remote_retrieve_response_code($res)) !== 200) return null;
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) return null;
        if (!is_numeric($data[0]['lat']) || !is_numeric($data[0]['lon'])) return null;
        return [round(floatval($data[0]['lat']), 7), round(floatval($data[0]['lon']), 7)];
    }

    /**
     * Koordinaten ergänzen, wenn sie fehlen (oder automatisch ermittelte nach Adressänderung).
     * $force = auch nach einem früheren Fehlschlag erneut fragen. Rückgabe: 'ok'|'failed'|'skip'.
     */
    public static function maybe_geocode($loc_id, $force = false) {
        $query = self::geo_query($loc_id);
        if ($query === '') return 'skip';
        list($lat) = self::coords($loc_id);
        $auto = get_post_meta($loc_id, '_tix_loc_geo_auto', true) === '1';
        if ($lat !== null) {
            if (!$auto || get_post_meta($loc_id, '_tix_loc_geo_query', true) === $query) return 'skip';
        }
        if (!$force && get_post_meta($loc_id, '_tix_loc_geo_failed', true) === $query) return 'skip';
        $c = self::geocode($query);
        if (!$c) {
            update_post_meta($loc_id, '_tix_loc_geo_failed', $query);
            return 'failed';
        }
        update_post_meta($loc_id, '_tix_loc_lat', (string) $c[0]);
        update_post_meta($loc_id, '_tix_loc_lng', (string) $c[1]);
        update_post_meta($loc_id, '_tix_loc_geo_auto', '1');
        update_post_meta($loc_id, '_tix_loc_geo_query', $query);
        delete_post_meta($loc_id, '_tix_loc_geo_failed');
        return 'ok';
    }

    public static function on_save_geocode($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!$post || in_array($post->post_status, ['trash', 'auto-draft'], true)) return;
        self::maybe_geocode($post_id);
    }

    /**
     * Koordinaten für Locations ohne lat/lng nachtragen (1 Anfrage pro Sekunde).
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Nur auflisten, nichts abfragen.
     *
     * [--sleep=<sekunden>]
     * : Pause zwischen den Anfragen (Standard 1).
     *
     * ## EXAMPLES
     *
     *     wp tixomat geocode-locations
     */
    public static function cli_geocode($args, $assoc) {
        $dry   = !empty($assoc['dry-run']);
        $sleep = isset($assoc['sleep']) ? max(0, floatval($assoc['sleep'])) : 1.0;
        $ids = get_posts(['post_type' => 'tix_location', 'post_status' => ['publish', 'draft', 'private'], 'posts_per_page' => -1, 'fields' => 'ids']);
        $done = 0; $failed = 0; $todo = 0; $first = true;
        foreach ($ids as $lid) {
            list($lat) = self::coords($lid);
            if ($lat !== null) continue;
            $q = self::geo_query($lid);
            if ($q === '') { WP_CLI::log("#{$lid} " . get_the_title($lid) . ': keine Adresse – übersprungen'); continue; }
            $todo++;
            if ($dry) { WP_CLI::log("#{$lid} " . get_the_title($lid) . ": {$q}"); continue; }
            if (!$first && $sleep > 0) usleep(intval($sleep * 1000000));
            $first = false;
            $r = self::maybe_geocode($lid, true);
            if ($r === 'ok') {
                $done++;
                WP_CLI::log("#{$lid} " . get_the_title($lid) . ': ' . get_post_meta($lid, '_tix_loc_lat', true) . ', ' . get_post_meta($lid, '_tix_loc_lng', true));
            } else {
                $failed++;
                WP_CLI::warning("#{$lid} " . get_the_title($lid) . ": nicht gefunden ({$q})");
            }
        }
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        if ($dry) WP_CLI::success("{$todo} Locations ohne Koordinaten.");
        else WP_CLI::success("{$done} ergänzt, {$failed} nicht gefunden.");
    }
}
