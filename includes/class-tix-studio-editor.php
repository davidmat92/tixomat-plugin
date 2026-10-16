<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Studio_Editor – evendis Studio Stufe 3: weitere Felder des Experten-Modus im Event-Editor und Orte (1.38.448).
 *
 * Event-Editor (additiv in GET /events/form, GET /events/{id}/edit → `event`, POST /events[/{id}]; nur mitgeschickte Schlüssel):
 *   timetable       {times_tba, stages[{name, color}], days{"JJJJ-MM-TT": [{time, end, stage, title, desc}]}} | null löscht
 *   low_stock       {mode: global|custom|manual|off, threshold, text}  (+ low_stock_global nur lesend)
 *   ticket_transfer bool  (+ ticket_transfer_available nur lesend: globaler Schalter)
 *   ticket_sponsor  {image, image_id, link} | null löscht;  POST /events/{id}/sponsor-image (multipart `image`) → {image_id, image}
 * Orte (CPT tix_location):
 *   GET /locations · POST /locations · POST /locations/{id} · DELETE /locations/{id} (nur ohne Events, sonst 409)
 *   Rechte: lesen Staff; ändern Admin bzw. Rolle Veranstalter. Mehr-Veranstalter: eigene Orte (selbst angelegt oder dem
 *   eigenen Veranstalter zugeordnet) änderbar, in der App gelistete Orte anderer nur lesend.
 */
class TIX_Studio_Editor {

    const NS = 'tixomat/v1';
    const TIME_RE = '/^([01]?\d|2[0-3]):[0-5]\d$/';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    private static function bad($field, $msg, $status = 400) {
        return new WP_Error('tix_invalid_field', $msg, ['status' => $status, 'field' => $field]);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    // ═══════════════ Event-Editor ═══════════════

    /** Zusätzliche Felder für edit_payload ($id = 0: Vorlage). */
    public static function payload($id) {
        $id = intval($id);
        $m = function ($k) use ($id) { return $id ? (string) get_post_meta($id, $k, true) : ''; };
        // Programm
        $stages = $id ? get_post_meta($id, '_tix_stages', true) : [];
        $stages = is_array($stages) ? array_values(array_map(function ($s) { return ['name' => (string) ($s['name'] ?? ''), 'color' => (string) ($s['color'] ?? '')]; }, $stages)) : [];
        $tt = $id ? get_post_meta($id, '_tix_timetable', true) : [];
        $days = [];
        if (is_array($tt)) {
            ksort($tt);
            foreach ($tt as $date => $slots) {
                if (!is_array($slots)) continue;
                $days[(string) $date] = array_values(array_map(function ($s) {
                    return ['time' => (string) ($s['time'] ?? ''), 'end' => (string) ($s['end'] ?? ''), 'stage' => intval($s['stage'] ?? 0), 'title' => (string) ($s['title'] ?? ''), 'desc' => (string) ($s['desc'] ?? '')];
                }, $slots));
            }
        }
        // Sponsor
        $sid = $id ? intval(get_post_meta($id, '_tix_ticket_sponsor_image_id', true)) : 0;
        $simg = $sid ? (string) (wp_get_attachment_image_url($sid, 'large') ?: '') : $m('_tix_ticket_sponsor_image_url');
        $mode = $m('_tix_low_stock_mode') ?: 'global';
        return [
            'timetable'       => ['times_tba' => $m('_tix_timetable_times_tba') === '1', 'stages' => $stages, 'days' => (object) $days],
            'low_stock'       => ['mode' => $mode, 'threshold' => $m('_tix_low_stock_threshold') !== '' ? intval($m('_tix_low_stock_threshold')) : null, 'text' => $m('_tix_low_stock_text')],
            'low_stock_global' => function_exists('tix_get_settings') ? intval(tix_get_settings('low_stock_threshold') ?? 10) : 10,
            'ticket_transfer' => $m('_tix_ticket_transfer') === '1',
            'ticket_transfer_available' => function_exists('tix_get_settings') && (bool) tix_get_settings('ticket_transfer_enabled'),
            'ticket_sponsor'  => ['image' => $simg, 'image_id' => $sid, 'link' => $m('_tix_ticket_sponsor_link')],
        ];
    }

    /** Vorab prüfen (vor allen Änderungen). Liefert bereinigte Werte je mitgeschicktem Schlüssel oder WP_Error. */
    public static function check(array $body, $post_id = 0) {
        $out = [];
        // ── Programm ──
        if (array_key_exists('timetable', $body)) {
            $t = $body['timetable'];
            if ($t === null) { $out['timetable'] = null; }
            elseif (!is_array($t)) return self::bad('timetable', 'Ungültiges Programm.');
            else {
                $c = [];
                if (array_key_exists('times_tba', $t)) $c['times_tba'] = !empty($t['times_tba']);
                $stage_count = null;
                if (array_key_exists('stages', $t)) {
                    if (!is_array($t['stages'])) return self::bad('timetable.stages', 'Ungültige Bühnen.');
                    $st = [];
                    foreach (array_values($t['stages']) as $i => $s) {
                        $name = mb_substr(sanitize_text_field((string) ($s['name'] ?? '')), 0, 60);
                        if ($name === '') continue;
                        $color = sanitize_hex_color((string) ($s['color'] ?? '')) ?: (function_exists('tix_primary') ? tix_primary() : '#FF5500');
                        $st[] = ['name' => $name, 'color' => $color];
                    }
                    if (count($st) > 20) return self::bad('timetable.stages', 'Höchstens 20 Bühnen.');
                    $c['stages'] = $st;
                    $stage_count = count($st);
                }
                if (array_key_exists('days', $t)) {
                    if (!is_array($t['days'])) return self::bad('timetable.days', 'Ungültige Programm-Tage.');
                    if ($stage_count === null) { $cur = $post_id ? get_post_meta($post_id, '_tix_stages', true) : []; $stage_count = is_array($cur) ? count($cur) : 0; }
                    $days = [];
                    foreach ($t['days'] as $date => $slots) {
                        $date = (string) $date;
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return self::bad('timetable.days', 'Programm-Tag bitte als JJJJ-MM-TT.');
                        if (!is_array($slots)) return self::bad("timetable.days.$date", 'Ungültige Programmpunkte.');
                        $list = [];
                        foreach (array_values($slots) as $i => $s) {
                            if (!is_array($s)) continue;
                            $time = trim((string) ($s['time'] ?? '')); $end = trim((string) ($s['end'] ?? ''));
                            $title = mb_substr(sanitize_text_field((string) ($s['title'] ?? '')), 0, 200);
                            if ($time === '' && $title === '') continue;
                            if ($time !== '' && !preg_match(self::TIME_RE, $time)) return self::bad("timetable.days.$date.$i.time", 'Uhrzeit bitte als HH:MM.');
                            if ($end !== '' && !preg_match(self::TIME_RE, $end)) return self::bad("timetable.days.$date.$i.end", 'Uhrzeit bitte als HH:MM.');
                            $stage = max(0, intval($s['stage'] ?? 0));
                            if ($stage_count > 0 && $stage >= $stage_count) return self::bad("timetable.days.$date.$i.stage", 'Diese Bühne gibt es nicht.');
                            $list[] = ['time' => $time !== '' ? sprintf('%05s', $time) : '', 'end' => $end !== '' ? sprintf('%05s', $end) : '', 'stage' => $stage, 'title' => $title, 'desc' => mb_substr(sanitize_text_field((string) ($s['desc'] ?? '')), 0, 500)];
                            if (count($list) > 200) return self::bad("timetable.days.$date", 'Höchstens 200 Programmpunkte je Tag.');
                        }
                        usort($list, function ($a, $b) { return strcmp($a['time'], $b['time']); });
                        if ($list) $days[$date] = $list;
                    }
                    ksort($days);
                    $c['days'] = $days;
                }
                $out['timetable'] = $c;
            }
        }
        // ── Restbestand-Hinweis ──
        if (array_key_exists('low_stock', $body)) {
            $l = $body['low_stock'];
            if (!is_array($l)) return self::bad('low_stock', 'Ungültiger Restbestand-Hinweis.');
            $mode = sanitize_key((string) ($l['mode'] ?? 'global'));
            if (!in_array($mode, ['global', 'custom', 'manual', 'off'], true)) return self::bad('low_stock.mode', 'Modus: global, custom, manual oder off.');
            $c = ['mode' => $mode];
            if ($mode === 'custom') {
                $th = $l['threshold'] ?? 50;
                if (!is_numeric($th) || intval($th) < 1 || intval($th) > 9999) return self::bad('low_stock.threshold', 'Schwelle zwischen 1 und 9999.');
                $c['threshold'] = intval($th);
            }
            if ($mode === 'manual') {
                $text = sanitize_text_field((string) ($l['text'] ?? ''));
                if ($text === '' || mb_strlen($text) > 80) return self::bad('low_stock.text', 'Bitte einen Text mit höchstens 80 Zeichen angeben.');
                $c['text'] = $text;
            }
            $out['low_stock'] = $c;
        }
        if (array_key_exists('ticket_transfer', $body)) $out['ticket_transfer'] = !empty($body['ticket_transfer']);
        // ── Ticket-Sponsor ──
        if (array_key_exists('ticket_sponsor', $body)) {
            $s = $body['ticket_sponsor'];
            if ($s === null) { $out['ticket_sponsor'] = null; }
            elseif (!is_array($s)) return self::bad('ticket_sponsor', 'Ungültiger Sponsor.');
            else {
                $c = [];
                if (array_key_exists('image_id', $s)) {
                    $iid = intval($s['image_id']);
                    if ($iid && !self::image_allowed($iid, $post_id)) return self::bad('ticket_sponsor.image_id', 'Dieses Bild ist nicht verfügbar.');
                    $c['image_id'] = $iid;
                } elseif (array_key_exists('image', $s)) {
                    $u = trim((string) $s['image']);
                    if ($u !== '' && !preg_match('#^https://#i', $u)) return self::bad('ticket_sponsor.image', 'Bild-Adresse bitte mit https://.');
                    $c['image'] = esc_url_raw($u);
                }
                if (array_key_exists('link', $s)) {
                    $u = trim((string) $s['link']);
                    if ($u !== '' && !preg_match('#^https?://[^\s/]+\.[^\s]+#i', $u)) return self::bad('ticket_sponsor.link', 'Link bitte mit https://.');
                    $c['link'] = esc_url_raw($u);
                }
                $out['ticket_sponsor'] = $c;
            }
        }
        return $out;
    }

    /** Bild aus der Mediathek: Bild-Anhang, im Mehr-Veranstalter-Modus nur eigene bzw. am Event hängende. */
    private static function image_allowed($iid, $post_id) {
        if (get_post_type($iid) !== 'attachment' || strpos((string) get_post_mime_type($iid), 'image/') !== 0) return false;
        if (!self::multi() || current_user_can('manage_options')) return true;
        if (intval(get_post_field('post_parent', $iid)) === intval($post_id) && $post_id) return true;
        return intval(get_post_field('post_author', $iid)) === get_current_user_id();
    }

    /** Geprüfte Werte speichern. */
    public static function apply($post_id, array $c) {
        if (array_key_exists('timetable', $c)) {
            $t = $c['timetable'];
            if ($t === null) {
                foreach (['_tix_timetable', '_tix_stages', '_tix_timetable_times_tba'] as $k) delete_post_meta($post_id, $k);
            } else {
                if (array_key_exists('times_tba', $t)) update_post_meta($post_id, '_tix_timetable_times_tba', $t['times_tba'] ? '1' : '');
                if (array_key_exists('stages', $t)) update_post_meta($post_id, '_tix_stages', $t['stages']);
                if (array_key_exists('days', $t)) update_post_meta($post_id, '_tix_timetable', $t['days']);
            }
        }
        if (array_key_exists('low_stock', $c)) {
            $l = $c['low_stock'];
            update_post_meta($post_id, '_tix_low_stock_mode', $l['mode']);
            if (isset($l['threshold'])) update_post_meta($post_id, '_tix_low_stock_threshold', $l['threshold']);
            if (isset($l['text'])) update_post_meta($post_id, '_tix_low_stock_text', $l['text']);
        }
        if (array_key_exists('ticket_transfer', $c)) update_post_meta($post_id, '_tix_ticket_transfer', $c['ticket_transfer'] ? '1' : '0');
        if (array_key_exists('ticket_sponsor', $c)) {
            $s = $c['ticket_sponsor'];
            if ($s === null) {
                foreach (['_tix_ticket_sponsor_image_id', '_tix_ticket_sponsor_image_url', '_tix_ticket_sponsor_link'] as $k) delete_post_meta($post_id, $k);
            } else {
                if (array_key_exists('image_id', $s)) {
                    if ($s['image_id']) { update_post_meta($post_id, '_tix_ticket_sponsor_image_id', $s['image_id']); delete_post_meta($post_id, '_tix_ticket_sponsor_image_url'); }
                    else { delete_post_meta($post_id, '_tix_ticket_sponsor_image_id'); delete_post_meta($post_id, '_tix_ticket_sponsor_image_url'); }
                } elseif (array_key_exists('image', $s)) {
                    if ($s['image'] !== '') { update_post_meta($post_id, '_tix_ticket_sponsor_image_url', $s['image']); delete_post_meta($post_id, '_tix_ticket_sponsor_image_id'); }
                    else delete_post_meta($post_id, '_tix_ticket_sponsor_image_url');
                }
                if (array_key_exists('link', $s)) {
                    if ($s['link'] !== '') update_post_meta($post_id, '_tix_ticket_sponsor_link', $s['link']); else delete_post_meta($post_id, '_tix_ticket_sponsor_link');
                }
            }
        }
    }

    // ═══════════════ Routen ═══════════════

    public static function register_routes() {
        $staff = ['TIX_REST_API', 'check_organizer'];
        register_rest_route(self::NS, '/events/(?P<id>\d+)/sponsor-image', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_sponsor_image'], 'permission_callback' => $staff]);
        register_rest_route(self::NS, '/locations', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_locations'], 'permission_callback' => $staff],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_location_save'], 'permission_callback' => [__CLASS__, 'perm_location_write']],
        ]);
        register_rest_route(self::NS, '/locations/(?P<id>\d+)', [
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_location_save'], 'permission_callback' => [__CLASS__, 'perm_location_write']],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_location_delete'], 'permission_callback' => [__CLASS__, 'perm_location_write']],
        ]);
    }

    /** POST /events/{id}/sponsor-image (multipart `image`) → {image_id, image} */
    public static function rest_sponsor_image(WP_REST_Request $req) {
        $id = absint($req['id']);
        if (get_post_type($id) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::user_can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        $files = $req->get_file_params();
        $file = $files['image'] ?? ($files['file'] ?? null);
        if (empty($file['tmp_name'])) return new WP_Error('no_file', 'Kein Bild hochgeladen.', ['status' => 400, 'field' => 'image']);
        if (!empty($file['error'])) return new WP_Error('upload_failed', 'Upload fehlgeschlagen (höchstens 5 MB).', ['status' => in_array(intval($file['error']), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400, 'field' => 'image']);
        if (intval($file['size'] ?? 0) > 5 * MB_IN_BYTES) return new WP_Error('too_large', 'Das Bild ist zu groß (höchstens 5 MB).', ['status' => 413, 'field' => 'image']);
        $info = @getimagesize($file['tmp_name']);
        $mime = is_array($info) ? (string) $info['mime'] : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return new WP_Error('bad_type', 'Bitte ein JPG-, PNG- oder WebP-Bild wählen.', ['status' => 400, 'field' => 'image']);
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $aid = media_handle_sideload(['name' => 'sponsor-' . $id . '-' . time() . '.' . $ext, 'tmp_name' => $file['tmp_name'], 'type' => $mime, 'size' => $file['size'] ?? 0], $id, 'Ticket-Sponsor');
        if (is_wp_error($aid)) return new WP_Error('upload_failed', $aid->get_error_message(), ['status' => 500]);
        update_post_meta($id, '_tix_ticket_sponsor_image_id', $aid);
        delete_post_meta($id, '_tix_ticket_sponsor_image_url');
        return rest_ensure_response(['ok' => true, 'image_id' => intval($aid), 'image' => (string) (wp_get_attachment_image_url($aid, 'large') ?: '')]);
    }

    // ═══════════════ Orte ═══════════════

    /** Eigener Ort? (Admin bzw. Ein-Veranstalter: alle; Mehr-Veranstalter: selbst angelegt oder dem eigenen Veranstalter zugeordnet) */
    private static function location_own($lid) {
        if (current_user_can('manage_options') || !self::multi()) return true;
        if (intval(get_post_field('post_author', $lid)) === get_current_user_id()) return true;
        $oid = TIX_App_Scope::organizer_id_for_user();
        return $oid && intval(get_post_meta($lid, '_tix_loc_organizer_id', true)) === $oid;
    }

    private static function location_events($lid) {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_tix_location_id' AND pm.meta_value = %s AND p.post_type = 'event' AND p.post_status NOT IN ('trash', 'auto-draft')",
            (string) intval($lid)
        )));
    }

    public static function location_payload($lid) {
        $m = function ($k) use ($lid) { return (string) get_post_meta($lid, $k, true); };
        $lat = $m('_tix_loc_lat'); $lng = $m('_tix_loc_lng');
        $lat = is_numeric($lat) && floatval($lat) != 0 ? floatval($lat) : null;
        $lng = is_numeric($lng) && $lat !== null ? floatval($lng) : null;
        $addr = $m('_tix_loc_address');
        $zip = $m('_tix_loc_zip') ?: $m('_tix_loc_postcode');
        $q = $lat !== null ? $lat . ',' . $lng : implode(', ', array_filter([get_the_title($lid), $addr, trim($zip . ' ' . $m('_tix_loc_city'))]));
        return [
            'id'       => intval($lid),
            'title'    => html_entity_decode(get_the_title($lid), ENT_QUOTES, 'UTF-8'),
            'address'  => $addr,
            'zip'      => $zip,
            'city'     => $m('_tix_loc_city'),
            'country'  => $m('_tix_loc_country'),
            'lat'      => $lat,
            'lng'      => $lng,
            'maps_url' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q),
            'events'   => self::location_events($lid),
            'editable' => self::location_own($lid),
        ];
    }

    /** GET /locations */
    public static function rest_locations(WP_REST_Request $req) {
        $ids = get_posts(['post_type' => 'tix_location', 'post_status' => 'publish', 'posts_per_page' => 300, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids']);
        $out = [];
        foreach ($ids as $lid) {
            if (self::multi() && !current_user_can('manage_options') && !self::location_own($lid) && get_post_meta($lid, '_tix_loc_listed', true) !== '1') continue;
            $out[] = self::location_payload($lid);
        }
        return rest_ensure_response(['ok' => true, 'locations' => $out]);
    }

    public static function perm_location_write(WP_REST_Request $req) {
        $r = TIX_REST_API::check_organizer($req);
        if ($r !== true) return $r;
        $u = wp_get_current_user();
        if ($u->has_cap('manage_options') || in_array('tix_organizer', (array) $u->roles, true)) return true;
        return new WP_Error('rest_forbidden', 'Nur Admins und Veranstalter können Orte ändern.', ['status' => 403]);
    }

    /** POST /locations[/{id}] {title, address, zip, city, country, lat?, lng?} */
    public static function rest_location_save(WP_REST_Request $req) {
        $lid = absint($req['id'] ?? 0);
        if ($lid) {
            if (get_post_type($lid) !== 'tix_location') return new WP_Error('not_found', 'Ort nicht gefunden.', ['status' => 404]);
            if (!self::location_own($lid)) return new WP_Error('rest_forbidden', 'Diesen Ort kannst du nicht ändern.', ['status' => 403]);
        }
        $p = $req->get_json_params();
        $p = is_array($p) ? $p : $req->get_params();
        $f = ['address' => '_tix_loc_address', 'zip' => '_tix_loc_zip', 'city' => '_tix_loc_city', 'country' => '_tix_loc_country'];
        if (!$lid || array_key_exists('title', $p)) {
            $title = mb_substr(sanitize_text_field((string) ($p['title'] ?? '')), 0, 120);
            if ($title === '') return self::bad('title', 'Bitte einen Namen für den Ort angeben.');
        }
        foreach (['lat' => [-90, 90], 'lng' => [-180, 180]] as $k => $rng) {
            if (isset($p[$k]) && $p[$k] !== '' && $p[$k] !== null && (!is_numeric($p[$k]) || floatval($p[$k]) < $rng[0] || floatval($p[$k]) > $rng[1])) return self::bad($k, 'Ungültige Koordinate.');
        }
        if (!$lid) {
            $lid = wp_insert_post(['post_type' => 'tix_location', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => get_current_user_id()], true);
            if (is_wp_error($lid)) return new WP_Error('tix_save_failed', 'Ort konnte nicht angelegt werden.', ['status' => 500]);
            if (self::multi() && ($oid = TIX_App_Scope::organizer_id_for_user())) update_post_meta($lid, '_tix_loc_organizer_id', $oid);
            $created = true;
        } elseif (isset($title)) {
            wp_update_post(['ID' => $lid, 'post_title' => $title]);
        }
        foreach ($f as $k => $meta) {
            if (array_key_exists($k, $p)) update_post_meta($lid, $meta, mb_substr(sanitize_text_field((string) $p[$k]), 0, $k === 'address' ? 200 : 80));
        }
        if (isset($p['lat'], $p['lng']) && is_numeric($p['lat']) && is_numeric($p['lng'])) {
            update_post_meta($lid, '_tix_loc_lat', (string) floatval($p['lat']));
            update_post_meta($lid, '_tix_loc_lng', (string) floatval($p['lng']));
            update_post_meta($lid, '_tix_loc_geo_auto', '0');
        } elseif (array_intersect(array_keys($p), array_keys($f)) && class_exists('TIX_Venues')) {
            // Adresse geändert → Koordinaten wie bisher (Nominatim, nur automatische überschreiben)
            TIX_Venues::maybe_geocode($lid);
        }
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return new WP_REST_Response(['ok' => true, 'location' => self::location_payload($lid)], !empty($created) ? 201 : 200);
    }

    /** DELETE /locations/{id} – nur ohne Events */
    public static function rest_location_delete(WP_REST_Request $req) {
        $lid = absint($req['id']);
        if (get_post_type($lid) !== 'tix_location') return new WP_Error('not_found', 'Ort nicht gefunden.', ['status' => 404]);
        if (!self::location_own($lid)) return new WP_Error('rest_forbidden', 'Diesen Ort kannst du nicht löschen.', ['status' => 403]);
        $n = self::location_events($lid);
        if ($n > 0) return new WP_Error('tix_location_in_use', 'An diesem Ort hängen noch ' . $n . ' Event(s).', ['status' => 409, 'events' => $n]);
        wp_delete_post($lid, true);
        return rest_ensure_response(['ok' => true, 'deleted' => $lid]);
    }
}
