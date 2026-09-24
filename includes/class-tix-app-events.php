<?php
/**
 * Tixomat – Event-Editor und Bestell-Details für die App (Veranstalter-Bereich).
 *
 * Routen (X-Tix-Token, Rolle Admin / Veranstalter / Mitarbeiter (App)):
 *   GET  /events/form                → Vorlagen für ein neues Event (Locations, Info-Sektionen)
 *   GET  /events/{id}/edit           → editierbare Daten eines Events
 *   POST /events                     → Event anlegen   (Body wie /events/{id})
 *   POST /events/{id}                → Event speichern (Titel, Kurztext, Termin, Ort, Status,
 *                                      Info-Texte, Tickets/Kategorien, Vorverkauf, Gästeliste)
 *   POST /events/{id}/image          → Flyer (Beitragsbild) hochladen, multipart `image`
 *   GET  /orders/{id}/detail         → Bestellung mit Positionen, Kunde, Tickets
 *   POST /orders/{id}/resend-email   → Bestellbestätigung mit Tickets erneut senden
 *
 * Kategorien werden nach Index gemischt: Felder, die die App nicht kennt
 * (Preisphasen, Gutschein-Flags, Bilder, Bundles …), bleiben erhalten.
 * Kategorien mit verkauften Tickets können nicht gelöscht werden (Ticket-Posts
 * referenzieren den Index), nur versteckt.
 */
if (!defined('ABSPATH')) exit;

class TIX_App_Events {

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        $ns   = 'tixomat/v1';
        $perm = ['TIX_REST_API', 'check_organizer'];

        register_rest_route($ns, '/events/form', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'form'],
            'permission_callback' => $perm,
        ]);
        register_rest_route($ns, '/events/(?P<id>\d+)/edit', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'edit'],
            'permission_callback' => $perm,
        ]);
        register_rest_route($ns, '/events/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'update'],
            'permission_callback' => $perm,
        ]);
        register_rest_route($ns, '/events/(?P<id>\d+)/image', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'image'],
            'permission_callback' => $perm,
        ]);
        register_rest_route($ns, '/orders/(?P<id>\d+)/detail', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'order_detail'],
            'permission_callback' => $perm,
        ]);
        register_rest_route($ns, '/orders/(?P<id>\d+)/resend-email', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'order_resend'],
            'permission_callback' => $perm,
        ]);
    }

    // ──────────────────────────────────────────
    //  Vorlagen / editierbare Daten
    // ──────────────────────────────────────────

    private static function info_sections() {
        $defs = class_exists('TIX_Metabox') && method_exists('TIX_Metabox', 'info_sections')
            ? TIX_Metabox::info_sections()
            : [
                'description' => ['label' => 'Beschreibung', 'type' => 'textarea'],
                'lineup'      => ['label' => 'Line-Up', 'type' => 'textarea'],
                'specials'    => ['label' => 'Specials', 'type' => 'textarea'],
                'age_limit'   => ['label' => 'Altersbegrenzung', 'type' => 'number'],
                'extra_info'  => ['label' => 'Weitere Informationen', 'type' => 'textarea'],
                'dresscode'   => ['label' => 'Dresscode', 'type' => 'text', 'group' => 'ticket'],
                'entry_rules' => ['label' => 'Einlassregeln', 'type' => 'textarea', 'group' => 'ticket'],
                'ticket_notes'=> ['label' => 'Weitere Hinweise', 'type' => 'textarea', 'group' => 'ticket'],
            ];
        $out = [];
        foreach ($defs as $key => $def) {
            $out[] = [
                'key'   => $key,
                'label' => (string) ($def['label'] ?? ucfirst($key)),
                'type'  => (string) ($def['type'] ?? 'textarea'),
                'group' => (string) ($def['group'] ?? ''),
            ];
        }
        return $out;
    }

    private static function locations() {
        $posts = get_posts([
            'post_type'      => 'tix_location',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        $out = [];
        foreach ($posts as $p) {
            $out[] = [
                'id'      => $p->ID,
                'title'   => (string) $p->post_title,
                'address' => (string) get_post_meta($p->ID, '_tix_loc_address', true),
            ];
        }
        return $out;
    }

    /** HTML aus dem WP-Admin → Text für das Eingabefeld der App. */
    private static function html_to_text($html) {
        $html = (string) $html;
        if ($html === '') return '';
        $t = preg_replace('#<br\s*/?>#i', "\n", $html);
        $t = preg_replace('#</p>\s*#i', "\n\n", $t);
        $t = preg_replace('#<li[^>]*>#i', '• ', $t);
        $t = preg_replace('#</li>#i', "\n", $t);
        $t = wp_strip_all_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t]+\n/", "\n", $t);
        $t = preg_replace("/\n{3,}/", "\n\n", $t);
        return trim($t);
    }

    /** Enthält der Text mehr Markup als Absätze/Zeilenumbrüche (Links, Bilder, Listen …)? */
    private static function has_markup($html) {
        $stripped = preg_replace('#<(/?)(p|br|strong|b|em|i)(\s[^>]*)?>#i', '', (string) $html);
        return (bool) preg_match('/<[a-z][^>]*>/i', $stripped);
    }

    public static function form(WP_REST_Request $req) {
        return rest_ensure_response([
            'ok'            => true,
            'locations'     => self::locations(),
            'info_sections' => self::info_sections(),
            'event'         => self::edit_payload(0),
        ]);
    }

    public static function edit(WP_REST_Request $req) {
        $id   = absint($req['id']);
        $post = get_post($id);
        if (!$post || $post->post_type !== 'event') {
            return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        }
        if (!TIX_REST_API::user_can_access_event($id)) {
            return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        }
        return rest_ensure_response([
            'ok'            => true,
            'locations'     => self::locations(),
            'info_sections' => self::info_sections(),
            'event'         => self::edit_payload($id),
        ]);
    }

    /** Editierbare Daten; $id = 0 liefert die Vorlage für ein neues Event. */
    private static function edit_payload($id) {
        $m = fn($key, $default = '') => $id ? (string) (get_post_meta($id, $key, true) ?: $default) : $default;
        $post = $id ? get_post($id) : null;

        $info = [];
        $markup = [];
        foreach (self::info_sections() as $sec) {
            $raw = $id ? get_post_meta($id, '_tix_info_' . $sec['key'], true) : '';
            if ($sec['type'] === 'number') {
                $info[$sec['key']] = $raw === '' || $raw === null ? '' : (string) intval($raw);
            } else {
                $info[$sec['key']] = self::html_to_text($raw);
                if (self::has_markup($raw)) $markup[] = $sec['key'];
            }
        }

        $cats_raw = $id ? get_post_meta($id, '_tix_ticket_categories', true) : [];
        $counts   = $id ? TIX_REST_API::ticket_counts($id) : ['by_cat' => []];
        $cats = [];
        if (is_array($cats_raw)) {
            foreach ($cats_raw as $i => $c) {
                if (!is_array($c)) continue;
                $sold = intval($counts['by_cat'][intval($i)] ?? 0);
                $cats[] = [
                    'index'      => intval($i),
                    'name'       => (string) ($c['name'] ?? ''),
                    'price'      => floatval($c['price'] ?? 0),
                    'sale_price' => ($c['sale_price'] ?? '') === '' ? null : floatval($c['sale_price']),
                    'qty'        => intval($c['qty'] ?? ($c['quantity'] ?? 0)),
                    'desc'       => (string) ($c['desc'] ?? ($c['description'] ?? '')),
                    'hidden'     => !empty($c['hidden']),
                    'gift_card'  => !empty($c['gift_card']),
                    'sold'       => $sold,
                    'stock'      => (isset($c['stock']) && $c['stock'] !== '') ? intval($c['stock']) : -1,
                    'phases'     => is_array($c['phases'] ?? null) ? count($c['phases']) : 0,
                ];
            }
        }

        $thumb_id = $id ? (int) get_post_thumbnail_id($id) : 0;
        return [
            'id'                 => intval($id),
            'title'              => $post ? (string) $post->post_title : '',
            'excerpt'            => $post ? (string) $post->post_excerpt : '',
            'published'          => $post ? $post->post_status === 'publish' : false,
            'post_status'        => $post ? (string) $post->post_status : 'draft',
            'image'              => $thumb_id ? (wp_get_attachment_image_url($thumb_id, 'large') ?: '') : '',
            'image_id'           => $thumb_id,
            'date_start'         => $m('_tix_date_start'),
            'date_end'           => $m('_tix_date_end'),
            'time_start'         => $m('_tix_time_start'),
            'time_end'           => $m('_tix_time_end'),
            'time_doors'         => $m('_tix_time_doors'),
            'location_id'        => intval($m('_tix_location_id', '0')),
            'location'           => $m('_tix_location'),
            'address'            => $m('_tix_address'),
            'status'             => $m('_tix_event_status'),          // manueller Override, '' = automatisch
            'status_resolved'    => $m('_tix_status', 'available'),
            'tickets_enabled'    => $m('_tix_tickets_enabled', '0') === '1',
            'presale_active'     => $m('_tix_presale_active', '0') === '1',
            'presale_start'      => $m('_tix_presale_start'),
            'presale_end_mode'   => $m('_tix_presale_end_mode', 'manual'),
            'presale_end'        => $m('_tix_presale_end'),
            'presale_end_offset' => intval($m('_tix_presale_end_offset', '0')),
            'guest_list_enabled' => $m('_tix_guest_list_enabled', '0') === '1',
            'checkin_password'   => $m('_tix_checkin_password'),
            'info'               => $info,
            'info_markup'        => $markup,
            'categories'         => $cats,
        ];
    }

    // ──────────────────────────────────────────
    //  Anlegen / Speichern
    // ──────────────────────────────────────────

    public static function create(WP_REST_Request $req) {
        $body = $req->get_json_params();
        if (!is_array($body)) $body = [];
        $title = sanitize_text_field($body['title'] ?? '');
        if ($title === '') {
            return new WP_Error('missing_title', 'Bitte einen Titel eingeben.', ['status' => 400]);
        }
        $publish = !empty($body['published']);
        $post_id = wp_insert_post([
            'post_type'    => 'event',
            'post_title'   => $title,
            'post_excerpt' => sanitize_textarea_field($body['excerpt'] ?? ''),
            'post_status'  => $publish ? 'publish' : 'draft',
            'post_author'  => get_current_user_id(),
        ], true);
        if (is_wp_error($post_id)) return $post_id;

        // Veranstalter-Verknüpfung des Nutzers übernehmen (falls vorhanden)
        if (class_exists('TIX_Organizer_Dashboard') && method_exists('TIX_Organizer_Dashboard', 'get_organizer_by_user')) {
            $org = TIX_Organizer_Dashboard::get_organizer_by_user(get_current_user_id());
            if ($org) {
                update_post_meta($post_id, '_tix_organizer_id', $org->ID);
                update_post_meta($post_id, '_tix_organizer', get_the_title($org->ID));
            }
        }

        $err = self::apply($post_id, $body, true);
        if (is_wp_error($err)) {
            wp_delete_post($post_id, true);
            return $err;
        }
        return self::respond($post_id, 'Event angelegt.');
    }

    public static function update(WP_REST_Request $req) {
        $id   = absint($req['id']);
        $post = get_post($id);
        if (!$post || $post->post_type !== 'event') {
            return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        }
        if (!TIX_REST_API::user_can_access_event($id)) {
            return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        }
        $body = $req->get_json_params();
        if (!is_array($body)) $body = [];
        $err = self::apply($id, $body, false);
        if (is_wp_error($err)) return $err;
        return self::respond($id, 'Gespeichert.');
    }

    private static function respond($post_id, $message) {
        wp_cache_delete($post_id, 'post_meta');
        clean_post_cache($post_id);
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) {
            TIX_Public_Events::flush();
        }
        return rest_ensure_response([
            'ok'      => true,
            'message' => $message,
            'event'   => TIX_REST_API::event_payload(get_post($post_id), true),
            'edit'    => self::edit_payload($post_id),
        ]);
    }

    /**
     * Felder aus dem Body auf das Event anwenden. Nur mitgeschickte Felder werden
     * geändert (die App schickt immer das ganze Formular).
     */
    private static function apply($post_id, array $body, $is_new) {
        // ── Titel / Kurztext / Sichtbarkeit ──
        $post_args = ['ID' => $post_id];
        if (isset($body['title'])) {
            $title = sanitize_text_field($body['title']);
            if ($title === '') return new WP_Error('missing_title', 'Bitte einen Titel eingeben.', ['status' => 400]);
            $post_args['post_title'] = $title;
        }
        if (isset($body['excerpt']))   $post_args['post_excerpt'] = sanitize_textarea_field($body['excerpt']);
        if (isset($body['published'])) $post_args['post_status']  = !empty($body['published']) ? 'publish' : 'draft';

        // ── Termin ──
        $date_start = self::date($body['date_start'] ?? null);
        $date_end   = self::date($body['date_end'] ?? null);
        if (isset($body['date_start']) && $date_start === '' ) {
            return new WP_Error('missing_date', 'Bitte ein Startdatum wählen.', ['status' => 400]);
        }
        if ($date_start !== null) update_post_meta($post_id, '_tix_date_start', $date_start);
        if ($date_end !== null)   update_post_meta($post_id, '_tix_date_end', $date_end);
        foreach (['time_start', 'time_end', 'time_doors'] as $k) {
            if (isset($body[$k])) update_post_meta($post_id, '_tix_' . $k, self::time($body[$k]));
        }

        // ── Ort (Location-CPT) ──
        if (array_key_exists('location_id', $body)) {
            $loc_id = intval($body['location_id']);
            $loc = $loc_id ? get_post($loc_id) : null;
            if ($loc && $loc->post_type === 'tix_location') {
                update_post_meta($post_id, '_tix_location_id', $loc_id);
                update_post_meta($post_id, '_tix_location', get_the_title($loc_id));
                update_post_meta($post_id, '_tix_address', get_post_meta($loc_id, '_tix_loc_address', true));
            } else {
                update_post_meta($post_id, '_tix_location_id', 0);
                update_post_meta($post_id, '_tix_location', sanitize_text_field($body['location'] ?? ''));
                update_post_meta($post_id, '_tix_address', sanitize_text_field($body['address'] ?? ''));
            }
        }

        // ── Status (manueller Override) ──
        if (array_key_exists('status', $body)) {
            $status = sanitize_key((string) $body['status']);
            $allowed = ['', 'available', 'few_tickets', 'sold_out', 'cancelled', 'postponed'];
            if (!in_array($status, $allowed, true)) $status = '';
            update_post_meta($post_id, '_tix_event_status', $status);
        }

        // ── Info-Texte ──
        if (isset($body['info']) && is_array($body['info'])) {
            foreach (self::info_sections() as $sec) {
                $key = $sec['key'];
                if (!array_key_exists($key, $body['info'])) continue;
                $val = $body['info'][$key];
                if ($sec['type'] === 'number') {
                    $val = ($val === '' || $val === null) ? '' : intval($val);
                } elseif ($sec['type'] === 'text') {
                    $val = sanitize_text_field((string) $val);
                } else {
                    $val = wp_kses_post(trim((string) $val));
                }
                update_post_meta($post_id, '_tix_info_' . $key, $val);
            }
        }

        // ── Tickets / Vorverkauf ──
        if (array_key_exists('tickets_enabled', $body)) {
            update_post_meta($post_id, '_tix_tickets_enabled', !empty($body['tickets_enabled']) ? '1' : '0');
        }
        if (array_key_exists('presale_active', $body)) {
            update_post_meta($post_id, '_tix_presale_active', !empty($body['presale_active']) ? '1' : '0');
        }
        if (array_key_exists('presale_start', $body)) {
            update_post_meta($post_id, '_tix_presale_start', self::datetime($body['presale_start']));
        }
        if (array_key_exists('presale_end_mode', $body)) {
            $mode = in_array($body['presale_end_mode'], ['manual', 'fixed', 'before_event'], true) ? $body['presale_end_mode'] : 'manual';
            update_post_meta($post_id, '_tix_presale_end_mode', $mode);
            update_post_meta($post_id, '_tix_presale_end', $mode === 'fixed' ? self::datetime($body['presale_end'] ?? '') : '');
            update_post_meta($post_id, '_tix_presale_end_offset', $mode === 'before_event' ? max(0, intval($body['presale_end_offset'] ?? 0)) : 0);
        }

        // ── Gästeliste / Check-in-Passwort ──
        if (array_key_exists('guest_list_enabled', $body)) {
            update_post_meta($post_id, '_tix_guest_list_enabled', !empty($body['guest_list_enabled']) ? '1' : '0');
        }
        if (array_key_exists('checkin_password', $body)) {
            update_post_meta($post_id, '_tix_checkin_password', sanitize_text_field((string) $body['checkin_password']));
        }

        // ── Kategorien ──
        if (isset($body['categories']) && is_array($body['categories'])) {
            $err = self::apply_categories($post_id, $body['categories']);
            if (is_wp_error($err)) return $err;
        }

        // Titel/Kurztext/Status zuletzt: löst save_post_event aus (Breakdance-Meta etc.)
        if (count($post_args) > 1) {
            $res = wp_update_post($post_args, true);
            if (is_wp_error($res)) return $res;
        } elseif (!$is_new) {
            // Meta-Änderungen ohne Post-Update: Sync-Hook trotzdem anstoßen
            if (class_exists('TIX_Sync') && method_exists('TIX_Sync', 'sync')) {
                TIX_Sync::sync($post_id, get_post($post_id));
            }
        }

        self::resolve_status($post_id);
        return true;
    }

    /**
     * Kategorien nach Index mischen. Bekannte Felder der App überschreiben,
     * alles andere (Phasen, Gutschein-Flags, Bilder, Bundles …) bleibt.
     * Löschen nur, wenn die Kategorie und alle dahinter keine Tickets haben.
     */
    private static function apply_categories($post_id, array $incoming) {
        $old = get_post_meta($post_id, '_tix_ticket_categories', true);
        if (!is_array($old)) $old = [];
        $counts = TIX_REST_API::ticket_counts($post_id);
        $sold   = fn($i) => intval($counts['by_cat'][intval($i)] ?? 0);

        // Welche alten Indizes bleiben?
        $kept = [];
        foreach ($incoming as $in) {
            if (is_array($in) && isset($in['index']) && $in['index'] !== '' && $in['index'] !== null) {
                $kept[intval($in['index'])] = true;
            }
        }
        // Prüfung: gelöschte Kategorien dürfen keine Tickets haben, und kein
        // Index dahinter darf Tickets haben (Indizes würden verrutschen)
        $max_old = count($old) - 1;
        for ($i = 0; $i <= $max_old; $i++) {
            if (isset($kept[$i])) continue;
            for ($j = $i; $j <= $max_old; $j++) {
                if ($sold($j) > 0) {
                    $name = (string) ($old[$i]['name'] ?? ('#' . ($i + 1)));
                    return new WP_Error(
                        'category_in_use',
                        'Die Kategorie „' . $name . '“ kann nicht gelöscht werden, weil dahinter bereits Tickets verkauft wurden. Verstecke sie stattdessen.',
                        ['status' => 409]
                    );
                }
            }
        }
        // Reihenfolge der bestehenden Kategorien muss erhalten bleiben
        $last = -1;
        foreach ($incoming as $in) {
            if (!is_array($in) || !isset($in['index']) || $in['index'] === '' || $in['index'] === null) continue;
            $idx = intval($in['index']);
            if ($idx < $last) {
                return new WP_Error('category_order', 'Die Reihenfolge bestehender Kategorien kann nicht geändert werden.', ['status' => 409]);
            }
            $last = $idx;
        }

        $defaults = [
            'name' => '', 'price' => 0.0, 'sale_price' => '', 'qty' => 0, 'desc' => '', 'image_id' => 0,
            'online' => '1', 'offline_ticket' => '0', 'admin_only' => 0, 'hidden' => 0, 'no_fee' => 0,
            'gift_card' => 0, 'gift_free_amount' => 0, 'bundle_buy' => 0, 'bundle_pay' => 0, 'bundle_label' => '',
            'tc_event_id' => 0, 'product_id' => 0, 'sku' => '', 'group' => '', 'seatmap_id' => 0,
            'seatmap_section' => '', 'low_stock_mode' => 'inherit', 'phases' => [],
        ];

        $new = [];
        foreach ($incoming as $in) {
            if (!is_array($in)) continue;
            $name = sanitize_text_field((string) ($in['name'] ?? ''));
            if ($name === '') continue;
            $has_index = isset($in['index']) && $in['index'] !== '' && $in['index'] !== null && isset($old[intval($in['index'])]);
            $cat = $has_index ? $old[intval($in['index'])] : $defaults;
            if (!is_array($cat)) $cat = $defaults;
            $new_index = count($new);

            $cat['name']  = $name;
            $cat['price'] = max(0, round(floatval($in['price'] ?? 0), 2));
            $sale = $in['sale_price'] ?? '';
            $cat['sale_price'] = ($sale === '' || $sale === null) ? '' : max(0, round(floatval($sale), 2));
            $qty = max(0, intval($in['qty'] ?? 0));
            $cat['qty']    = $qty;
            $cat['desc']   = sanitize_text_field((string) ($in['desc'] ?? ''));
            $cat['hidden'] = !empty($in['hidden']) ? 1 : 0;

            // Restbestand für den nativen Checkout: Kontingent minus verkaufte
            $sold_here = $has_index ? $sold(intval($in['index'])) : 0;
            if ($qty > 0) {
                $cat['stock'] = max(0, $qty - $sold_here);
            } else {
                unset($cat['stock']); // unbegrenzt
            }
            $new[$new_index] = $cat;
        }

        update_post_meta($post_id, '_tix_ticket_categories', array_values($new));
        return true;
    }

    /** `_tix_status` wie TIX_Sync (läuft ohne WooCommerce sonst nicht). */
    private static function resolve_status($post_id) {
        $manual = (string) get_post_meta($post_id, '_tix_event_status', true);
        $labels = [
            'available'   => 'Verfügbar',
            'few_tickets' => 'Wenige Tickets',
            'sold_out'    => 'Ausverkauft',
            'cancelled'   => 'Abgesagt',
            'postponed'   => 'Verschoben',
            'past'        => 'Vergangen',
        ];
        $date_end   = (string) get_post_meta($post_id, '_tix_date_end', true);
        $date_start = (string) get_post_meta($post_id, '_tix_date_start', true);
        $time_end   = (string) get_post_meta($post_id, '_tix_time_end', true);
        $end_day    = $date_end ?: $date_start;
        $is_past    = false;
        if ($end_day) {
            $end_ts  = strtotime($end_day . ' ' . ($time_end ?: '23:59')) + 86400;
            $is_past = current_time('timestamp') >= $end_ts;
        }

        if ($manual !== '') {
            $status = $manual;
        } elseif ($is_past) {
            $status = 'past';
        } else {
            $cats = get_post_meta($post_id, '_tix_ticket_categories', true);
            $public = [];
            if (is_array($cats)) {
                foreach ($cats as $c) {
                    if (is_array($c) && empty($c['hidden']) && empty($c['gift_card'])) $public[] = $c;
                }
            }
            $all_sold_out = !empty($public);
            foreach ($public as $c) {
                if (!isset($c['stock']) || $c['stock'] === '' || intval($c['stock']) > 0) { $all_sold_out = false; break; }
            }
            $status = $all_sold_out ? 'sold_out' : 'available';
        }
        update_post_meta($post_id, '_tix_status', $status);
        update_post_meta($post_id, '_tix_status_label', $labels[$status] ?? '');
        update_post_meta($post_id, '_tix_is_past', $is_past ? '1' : '0');
        if ($date_start) {
            $ts = (string) (get_post_meta($post_id, '_tix_time_start', true) ?: '00:00');
            update_post_meta($post_id, '_tix_countdown_target', $date_start . 'T' . $ts);
        }
    }

    private static function date($v) {
        if ($v === null) return null;
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }

    private static function time($v) {
        $v = trim((string) $v);
        return preg_match('/^\d{1,2}:\d{2}$/', $v) ? str_pad($v, 5, '0', STR_PAD_LEFT) : '';
    }

    private static function datetime($v) {
        $v = trim((string) $v);
        if ($v === '') return '';
        $v = str_replace(' ', 'T', $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $v) ? substr($v, 0, 16) : '';
    }

    // ──────────────────────────────────────────
    //  Flyer
    // ──────────────────────────────────────────

    public static function image(WP_REST_Request $req) {
        $id   = absint($req['id']);
        $post = get_post($id);
        if (!$post || $post->post_type !== 'event') {
            return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        }
        if (!TIX_REST_API::user_can_access_event($id)) {
            return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        }
        $files = $req->get_file_params();
        $file  = $files['image'] ?? ($files['file'] ?? null);
        if (empty($file) || empty($file['tmp_name'])) {
            return new WP_Error('no_file', 'Kein Bild hochgeladen.', ['status' => 400]);
        }
        if (!empty($file['error'])) {
            return new WP_Error('upload_failed', 'Upload fehlgeschlagen.', ['status' => 400]);
        }
        if (intval($file['size'] ?? 0) > 15 * MB_IN_BYTES) {
            return new WP_Error('too_large', 'Das Bild ist zu groß (max. 15 MB).', ['status' => 400]);
        }
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'] ?? 'flyer.jpg');
        $mime  = $check['type'] ?: (string) ($file['type'] ?? '');
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return new WP_Error('bad_type', 'Bitte ein JPG-, PNG- oder WebP-Bild wählen.', ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $file_array = [
            'name'     => sanitize_file_name(($post->post_name ?: 'event-' . $id) . '-flyer-' . time() . '.' . $ext),
            'tmp_name' => $file['tmp_name'],
            'type'     => $mime,
            'size'     => $file['size'] ?? 0,
        ];
        $attachment_id = media_handle_sideload($file_array, $id, get_the_title($id));
        if (is_wp_error($attachment_id)) {
            return new WP_Error('upload_failed', $attachment_id->get_error_message(), ['status' => 500]);
        }
        set_post_thumbnail($id, $attachment_id);
        wp_cache_delete($id, 'post_meta');
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) {
            TIX_Public_Events::flush();
        }
        return rest_ensure_response([
            'ok'       => true,
            'image_id' => $attachment_id,
            'image'    => wp_get_attachment_image_url($attachment_id, 'large') ?: '',
            'event'    => TIX_REST_API::event_payload(get_post($id), true),
        ]);
    }

    // ──────────────────────────────────────────
    //  Bestellungen
    // ──────────────────────────────────────────

    private static function order_or_error($id) {
        $order = class_exists('TIX_Order') ? TIX_Order::get(absint($id)) : null;
        if (!$order) return new WP_Error('not_found', 'Bestellung nicht gefunden.', ['status' => 404]);
        // Zugriff über die Events der Positionen
        foreach ($order->get_items() as $item) {
            $eid = $item->get_event_id();
            if ($eid && !TIX_REST_API::user_can_access_event($eid)) {
                return new WP_Error('forbidden', 'Kein Zugriff auf diese Bestellung.', ['status' => 403]);
            }
        }
        return $order;
    }

    public static function order_detail(WP_REST_Request $req) {
        $order = self::order_or_error($req['id']);
        if (is_wp_error($order)) return $order;
        $id = $order->get_id();

        $items = [];
        foreach ($order->get_items() as $item) {
            $cat = trim((string) $item->get_cat_name());
            $items[] = [
                'name'     => $cat !== '' ? $cat : (string) $item->get_name(),
                'full_name'=> (string) $item->get_name(),
                'quantity' => $item->get_quantity(),
                'total'    => floatval($item->get_total()),
                'event_id' => $item->get_event_id(),
                'event'    => $item->get_event_id() ? get_the_title($item->get_event_id()) : '',
            ];
        }

        $tickets = [];
        $posts = get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_query'     => [['key' => '_tix_ticket_order_id', 'value' => (string) $id]],
        ]);
        foreach ($posts as $tp) {
            $status = (string) (get_post_meta($tp->ID, '_tix_ticket_status', true) ?: 'valid');
            $tickets[] = [
                'id'           => $tp->ID,
                'code'         => (string) get_post_meta($tp->ID, '_tix_ticket_code', true),
                'name'         => (string) get_post_meta($tp->ID, '_tix_ticket_owner_name', true),
                'email'        => (string) get_post_meta($tp->ID, '_tix_ticket_owner_email', true),
                'category'     => (string) (get_post_meta($tp->ID, '_tix_ticket_cat_name', true) ?: 'Ticket'),
                'status'       => $status,
                'checked_in'   => $status === 'used' || (string) get_post_meta($tp->ID, '_tix_ticket_checked_in', true) === '1',
                'checkin_time' => (string) get_post_meta($tp->ID, '_tix_ticket_checkin_time', true),
                'order_id'     => $id,
                'seat'         => (string) get_post_meta($tp->ID, '_tix_ticket_seat_id', true),
                'price'        => floatval(get_post_meta($tp->ID, '_tix_ticket_price', true)),
            ];
        }

        $coupon  = get_option('_tix_order_coupon_' . $id, []);
        $pos     = get_option('_tix_pos_order_' . $id, []);
        $source  = (string) get_option('_tix_order_source_' . $id, '');
        $created = $order->get_date_created();
        $pm      = (string) $order->get_payment_method();

        return rest_ensure_response([
            'ok'    => true,
            'order' => [
                'id'             => $id,
                'order_number'   => (string) $order->get_order_number(),
                'status'         => (string) $order->get_status(),
                'date'           => $created ? $created->format('Y-m-d H:i') : '',
                'payment'        => (string) ($order->get_payment_method_title() ?: $pm),
                'payment_method' => $pm,
                'is_pos'         => strpos($pm, 'pos_') === 0,
                'source'         => $source !== '' ? $source : (strpos($pm, 'pos_') === 0 ? 'pos' : 'web'),
                'staff'          => (string) (is_array($pos) ? ($pos['staff_name'] ?? '') : ''),
                'total'          => floatval($order->get_total()),
                'subtotal'       => method_exists($order, 'get_subtotal') ? floatval($order->get_subtotal()) : floatval($order->get_total()),
                'tax'            => floatval($order->get_total_tax()),
                'discount'       => floatval($order->get_total_discount()),
                'coupon'         => is_array($coupon) ? (string) ($coupon['code'] ?? '') : '',
                'customer'       => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'email'          => (string) $order->get_billing_email(),
                'phone'          => (string) $order->get_billing_phone(),
                'company'        => (string) $order->get_billing_company(),
                'address'        => trim(implode(', ', array_filter([
                    (string) $order->get_billing_address_1(),
                    trim((string) $order->get_billing_postcode() . ' ' . (string) $order->get_billing_city()),
                ]))),
                'items'          => $items,
                'tickets'        => $tickets,
            ],
        ]);
    }

    public static function order_resend(WP_REST_Request $req) {
        $order = self::order_or_error($req['id']);
        if (is_wp_error($order)) return $order;
        $id = $order->get_id();
        if (!class_exists('TIX_Emails') || !method_exists('TIX_Emails', 'send_native_completed')) {
            return new WP_Error('no_mail', 'E-Mail-Versand nicht verfügbar.', ['status' => 500]);
        }
        $body  = $req->get_json_params();
        $email = sanitize_email(is_array($body) ? (string) ($body['email'] ?? '') : '');
        if (!is_email($email)) $email = sanitize_email((string) $order->get_billing_email());
        if (!is_email($email)) {
            return new WP_Error('no_email', 'Keine E-Mail-Adresse hinterlegt.', ['status' => 400]);
        }
        if (strcasecmp($email, (string) $order->get_billing_email()) !== 0) {
            global $wpdb;
            $wpdb->update($wpdb->prefix . 'tix_orders', ['billing_email' => $email], ['id' => $id]);
        }
        if (!in_array($order->get_status(), ['completed', 'processing'], true)) {
            return new WP_Error('not_paid', 'Die Bestellung ist nicht bezahlt – es gibt keine Tickets zum Senden.', ['status' => 409]);
        }
        set_transient('_tix_skip_admin_email_' . $id, 1, 300);
        delete_post_meta($id, '_tix_completed_email_sent');
        TIX_Emails::send_native_completed($id);
        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
            TIX_Order_Admin::add_note($id, '🎟️ Bestellbestätigung erneut gesendet (App) an ' . $email, 'email');
        }
        return rest_ensure_response(['ok' => true, 'email' => $email, 'message' => 'E-Mail gesendet an ' . $email]);
    }
}
