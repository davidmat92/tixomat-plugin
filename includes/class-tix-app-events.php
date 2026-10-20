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
 *   (1.38.441, evendis Studio Stufe 2)
 *   POST /events/{id}/duplicate      → Kopie als Entwurf {title?, date_start?, date_end?}
 *   POST /events/import-url          → Eventseite einlesen {url} → {fields} (TIX_AI_Writer::import_url)
 *   POST /orders/{id}/refund         → erstatten {amount?, cancel_tickets?, reason?} (Inhaber/Team-Admin)
 *   POST /orders/{id}/cancel         → unbezahlte Bestellung stornieren
 *   Kategorien: `bundle {buy, pay, label}`; Event: `group_discount {enabled, tiers[{min_qty, percent}], combine_*}`
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
        register_rest_route($ns, '/events/(?P<id>\d+)/duplicate', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'duplicate'],
            'permission_callback' => $perm,
        ]);
        register_rest_route($ns, '/events/import-url', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'import_url'],
            'permission_callback' => $perm,
        ]);
        register_rest_route($ns, '/orders/(?P<id>\d+)/refund', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'order_refund'],
            'permission_callback' => [__CLASS__, 'perm_money'],
        ]);
        register_rest_route($ns, '/orders/(?P<id>\d+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'order_cancel'],
            'permission_callback' => [__CLASS__, 'perm_money'],
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

    private static function locations($current = 0) {
        // Mehr-Veranstalter-Modus: nur eigene Orte (1.38.475), der am Event gesetzte Ort bleibt sichtbar
        if (class_exists('TIX_Locations')) {
            $ids = TIX_Locations::visible_ids(300);
            if ($current && !in_array(intval($current), $ids, true) && get_post_type($current) === 'tix_location') $ids[] = intval($current);
            $posts = array_filter(array_map('get_post', $ids));
        } else {
            $posts = get_posts(['post_type' => 'tix_location', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC']);
        }
        $out = [];
        foreach ($posts as $p) {
            $out[] = [
                'id'      => $p->ID,
                'title'   => (string) $p->post_title,
                'address' => (string) get_post_meta($p->ID, '_tix_loc_address', true),
                'city'    => (string) get_post_meta($p->ID, '_tix_loc_city', true),
            ];
        }
        return $out;
    }

    /** Sparten (Taxonomie `event_category`) für die Auswahl im Editor. */
    private static function event_categories() {
        $terms = get_terms(['taxonomy' => 'event_category', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
        $out = [];
        foreach (is_wp_error($terms) ? [] : $terms as $t) {
            $out[] = ['id' => intval($t->term_id), 'slug' => (string) $t->slug, 'name' => html_entity_decode((string) $t->name, ENT_QUOTES, 'UTF-8')];
        }
        return $out;
    }

    /** Sparte des Events als Slug ('' = keine). */
    private static function event_category_slug($id) {
        $terms = $id ? get_the_terms($id, 'event_category') : [];
        return (is_array($terms) && $terms) ? (string) $terms[0]->slug : '';
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
            'ok'               => true,
            'locations'        => self::locations(),
            'event_categories' => self::event_categories(),
            'info_sections'    => self::info_sections(),
            'event'            => self::edit_payload(0),
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
            'ok'               => true,
            'locations'        => self::locations(intval(get_post_meta($id, '_tix_location_id', true))),
            'event_categories' => self::event_categories(),
            'info_sections'    => self::info_sections(),
            'event'            => self::edit_payload($id),
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
                    'tax_rate'   => class_exists('TIX_Invoices') ? TIX_Invoices::clean_rate($c['tax_rate'] ?? null) : null,
                    'sold'       => $sold,
                    'stock'      => (isset($c['stock']) && $c['stock'] !== '') ? intval($c['stock']) : -1,
                    'phases'     => is_array($c['phases'] ?? null) ? count($c['phases']) : 0,
                    'phase_list' => self::phase_list($c['phases'] ?? []),
                    // Paket „kaufe X, zahle Y“ oder null
                    'bundle'     => self::bundle_of($c),
                ];
            }
        }

        $thumb_id = $id ? (int) get_post_thumbnail_id($id) : 0;
        $guard = ($id && class_exists('TIX_Content_Guard') && method_exists('TIX_Content_Guard', 'guard_info')) ? TIX_Content_Guard::guard_info($id) : null;
        return [
            'guard'              => $guard, // KI-Schutz (1.38.505)
            'demo'               => $id && class_exists('TIX_Sales') && TIX_Sales::is_demo_organizer(intval(get_post_meta($id, '_tix_organizer_id', true))), // 1.38.508
            'id'                 => intval($id),
            'title'              => $post ? (string) $post->post_title : '',
            'excerpt'            => $post ? (string) $post->post_excerpt : '',
            'published'          => $post ? $post->post_status === 'publish' : false,
            'post_status'        => $post ? (string) $post->post_status : 'draft',
            // Veröffentlichen gewünscht, wartet auf die Freigabe des Veranstalters (TIX_Org_Approval)
            'publish_held'       => $post ? get_post_meta($post->ID, '_tix_publish_held', true) !== '' : false,
            'image'              => $thumb_id ? (wp_get_attachment_image_url($thumb_id, 'large') ?: '') : '',
            // 1.38.456: Original und srcset (Retina auf breiten Bildschirmen)
            'image_full'         => $thumb_id ? (string) (wp_get_attachment_image_url($thumb_id, 'full') ?: '') : '',
            'srcset'             => $thumb_id ? (string) (wp_get_attachment_image_srcset($thumb_id, 'full') ?: '') : '',
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
            // Ticket-Art (1.38.436): online | free | box_office | external | '' – dazu Preis bzw. externer Shop
            'ticket_mode'        => $id && class_exists('TIX_Register_Event') ? (TIX_Register_Event::ticket_mode_of($id) ?: '') : 'online',
            'box_office_price'   => $m('_tix_box_office_price') !== '' ? round(floatval($m('_tix_box_office_price')), 2) : null,
            'external_shop'      => ['url' => $m('_tix_extshop_url'), 'price_from' => $m('_tix_extshop_price') !== '' ? round(floatval($m('_tix_extshop_price')), 2) : null, 'text' => $m('_tix_extshop_text') ?: 'Tickets kaufen'],
            'presale_active'     => $m('_tix_presale_active', '0') === '1',
            // Steuersatz des Events (1.38.485, Rechnungen; null = Vorgabe des Ausstellers), je Kategorie `tax_rate`
            'tax_rate'           => class_exists('TIX_Invoices') ? TIX_Invoices::clean_rate($m('_tix_tax_rate')) : null,
            'presale_start'      => $m('_tix_presale_start'),
            'presale_end_mode'   => $m('_tix_presale_end_mode', 'manual'),
            'presale_end'        => $m('_tix_presale_end'),
            'presale_end_offset' => intval($m('_tix_presale_end_offset', '0')),
            'guest_list_enabled' => $m('_tix_guest_list_enabled', '0') === '1',
            'checkin_password'   => $m('_tix_checkin_password'),
            'info'               => $info,
            'info_markup'        => $markup,
            'categories'         => $cats,
            // Mengenrabatt (Staffeln „ab X Tickets Y %“)
            'group_discount'     => self::group_discount_of($id),
            // Sparte (Slug aus `event_categories`, '' = keine)
            'event_category'     => self::event_category_slug($id),
            // Awareness: inherit (Vorgabe Veranstalter/Seite) | custom (eigene Angaben) | off; `awareness` = eigene Angaben,
            // `awareness_effective` = was Gäste sehen (null = nichts)
            'awareness_mode'      => class_exists('TIX_Awareness') ? TIX_Awareness::event_mode($id) : 'inherit',
            'awareness'           => class_exists('TIX_Awareness') ? (function ($a) { unset($a['enabled']); return $a; })(TIX_Awareness::sanitize(get_post_meta($id, TIX_Awareness::META, true))) : null,
            'awareness_effective' => class_exists('TIX_Awareness') ? TIX_Awareness::effective($id) : null,
            // Muttizettel-Hinweis (gilt nur mit Modul „muttizettel“ des Veranstalters)
            'muttizettel_note'    => (string) get_post_meta($id, '_tix_muttizettel_note', true),
            // „Musik hören“: Künstler-Links wie im Event-Detail (source auto|manual|none) + auto = automatische Treffer
            'lineup_links'        => class_exists('TIX_Lineup_Links') ? TIX_Lineup_Links::for_event($id, true) : [],
        ] + (class_exists('TIX_Studio_Editor') ? TIX_Studio_Editor::payload($id) : []) + ((class_exists('TIX_Contract') && TIX_Contract::multi()) ? [
            // Hinweis beim ersten Online-Verkauf (evendis): {version, title, url, text} oder null – ohne Häkchen
            'contract_notice'     => TIX_Contract::first_sale_notice($id),
        ] : []) + (class_exists('TIX_Event_Facts') ? TIX_Event_Facts::editor_payload($id) : [])
          // 1.38.455: info_labels, faq, video, charity, box_office_desc, info_order
          + (class_exists('TIX_Event_Texts') ? TIX_Event_Texts::editor_payload($id) : []);
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
        // Mehr-Veranstalter-Modus: Event gehört immer dem eigenen Veranstalter
        // (Admins dürfen `organizer_id` mitgeben)
        $multi_org = 0;
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            $multi_org = TIX_App_Scope::organizer_id_for_user();
            if (TIX_App_Scope::is_admin() && !empty($body['organizer_id'])) {
                $cand = get_post(intval($body['organizer_id']));
                if ($cand && $cand->post_type === 'tix_organizer') $multi_org = intval($cand->ID);
            }
            if (!$multi_org && !TIX_App_Scope::is_admin()) {
                return new WP_Error('no_organizer', 'Dieses Konto ist keinem Veranstalter zugeordnet.', ['status' => 403]);
            }
        }
        $post_id = wp_insert_post([
            'post_type'    => 'event',
            'post_title'   => $title,
            'post_excerpt' => sanitize_textarea_field($body['excerpt'] ?? ''),
            'post_status'  => $publish ? 'publish' : 'draft',
            'post_author'  => get_current_user_id(),
        ], true);
        if (is_wp_error($post_id)) return $post_id;

        // Veranstalter-Verknüpfung des Nutzers übernehmen (falls vorhanden)
        if ($multi_org) {
            update_post_meta($post_id, '_tix_organizer_id', $multi_org);
            update_post_meta($post_id, '_tix_organizer', get_the_title($multi_org));
        } elseif (!(class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) && class_exists('TIX_Organizer_Dashboard') && method_exists('TIX_Organizer_Dashboard', 'get_organizer_by_user')) {
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
        // Tages-Alarme: beim Anlegen als „veröffentlicht“ fehlten Datum und Ort noch
        if (class_exists('TIX_Day_Alerts') && get_post_status($post_id) === 'publish') TIX_Day_Alerts::notify_event($post_id);
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
        $before = $post->post_status;
        $err = self::apply($id, $body, false);
        if (is_wp_error($err)) return $err;
        $res = self::respond($id, 'Gespeichert.');
        // Offline genommen trotz verkaufter Tickets: Hinweis (Tickets bleiben gültig, Einlass funktioniert, kein Verkauf mehr)
        clean_post_cache($id);
        $now = get_post_status($id);
        if (in_array($before, ['publish', 'future', 'pending'], true) && $now === 'draft' && class_exists('TIX_REST_API')) {
            $sold = intval(TIX_REST_API::ticket_counts($id)['total'] ?? 0);
            if ($sold > 0) {
                $d = $res->get_data();
                $d['warning'] = ['code' => 'tix_draft_with_sales', 'sold' => $sold,
                    'message' => sprintf('Das Event ist jetzt ein Entwurf: nicht mehr öffentlich und nicht mehr buchbar. Die %d verkauften Tickets bleiben gültig (Einlass funktioniert), Käufer werden nicht benachrichtigt.', $sold)];
                $res->set_data($d);
            }
        }
        return $res;
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
        // Pakete und Mengenrabatt vorab prüfen (vor allen Änderungen)
        $pricing = self::check_pricing($body);
        if (is_wp_error($pricing)) return $pricing;
        // Studio Stufe 3 (Programm, Restbestand, Übertragung, Sponsor …) vorab prüfen
        $studio = class_exists('TIX_Studio_Editor') ? TIX_Studio_Editor::check($body, $is_new ? 0 : $post_id) : [];
        $texts = class_exists('TIX_Event_Texts') ? TIX_Event_Texts::check($body, $is_new ? 0 : $post_id) : [];
        if (is_wp_error($texts)) return $texts;
        if (is_wp_error($studio)) return $studio;
        // Eventbild per image_id (1.38.505, z. B. Flyer aus POST /ai/extract): eigenes Bild bzw. vorläufiger Flyer, null/0 entfernt
        $thumb = self::check_image_id($body);
        if (is_wp_error($thumb)) return $thumb;

        // Steuersatz (1.38.485): Event und Kategorien 0–30 oder leer/null = erben – vor allen Änderungen prüfen
        $bad_rate = function ($v) { return $v !== null && $v !== '' && (!is_numeric($v) || floatval($v) < 0 || floatval($v) > 30); };
        if (array_key_exists('tax_rate', $body) && $bad_rate($body['tax_rate'])) {
            return new WP_Error('tix_invalid', 'Steuersatz: Zahl von 0 bis 30 oder leer.', ['status' => 400, 'field' => 'tax_rate']);
        }
        foreach ((is_array($body['categories'] ?? null) ? $body['categories'] : []) as $ci => $cin) {
            if (is_array($cin) && array_key_exists('tax_rate', $cin) && $bad_rate($cin['tax_rate'])) {
                return new WP_Error('tix_invalid', 'Steuersatz der Kategorie: Zahl von 0 bis 30 oder leer.', ['status' => 400, 'field' => 'categories.' . $ci . '.tax_rate']);
            }
        }

        // Fremden Ort nicht übernehmen (1.38.475): nur eigene Orte bzw. der schon gesetzte; neue Orte über POST /locations
        if (array_key_exists('location_id', $body) && class_exists('TIX_Locations')) {
            $lid_in = intval($body['location_id']);
            if ($lid_in && get_post_type($lid_in) === 'tix_location' && !TIX_Locations::is_own($lid_in)
                && ($is_new || $lid_in !== intval(get_post_meta($post_id, '_tix_location_id', true)))) {
                return new WP_Error('tix_location_foreign', 'Diesen Ort kannst du nicht verwenden. Lege ihn über die Ortssuche als eigenen Ort an.', ['status' => 403, 'field' => 'location_id']);
            }
        }

        // Sparte zuerst prüfen (Slug oder ID; '' entfernt sie; `category` als Alias) – vor allen Änderungen
        $cat_term = null;
        $cat_in = array_key_exists('event_category', $body) ? $body['event_category'] : (array_key_exists('category', $body) && !is_array($body['category']) ? $body['category'] : null);
        if (is_array($cat_in)) $cat_in = $cat_in['slug'] ?? ($cat_in['id'] ?? '');
        if ($cat_in !== null) {
            $cat_in = trim((string) $cat_in);
            $cat_term = 0;
            if ($cat_in !== '') {
                $term = ctype_digit($cat_in) ? get_term(intval($cat_in), 'event_category') : get_term_by('slug', sanitize_title($cat_in), 'event_category');
                if (!$term || is_wp_error($term)) {
                    return new WP_Error('tix_invalid_field', 'Diese Sparte gibt es nicht.', ['status' => 400, 'field' => 'event_category']);
                }
                $cat_term = intval($term->term_id);
            }
        }

        // ── Titel / Kurztext / Sichtbarkeit ──
        $post_args = ['ID' => $post_id];
        if (isset($body['title'])) {
            $title = sanitize_text_field($body['title']);
            if ($title === '') return new WP_Error('missing_title', 'Bitte einen Titel eingeben.', ['status' => 400]);
            $post_args['post_title'] = $title;
        }
        if (isset($body['excerpt']))   $post_args['post_excerpt'] = sanitize_textarea_field($body['excerpt']);
        if (isset($body['published'])) {
            // Zurückgehaltenes Event (Veranstalter wartet auf Freigabe, TIX_Org_Approval): die App zeigt
            // es als nicht veröffentlicht – `published=false` lässt den Veröffentlichungswunsch stehen.
            $held = !$is_new && empty($body['published']) && get_post_status($post_id) === 'pending'
                && get_post_meta($post_id, '_tix_publish_held', true) !== '';
            if (!$held) $post_args['post_status'] = !empty($body['published']) ? 'publish' : 'draft';
        }
        // Ausdrücklich auf Entwurf (1.38.457, Studio): auch ein zurückgehaltenes Event – der Veröffentlichungswunsch entfällt
        if (!$is_new && !empty($body['draft']) && filter_var($body['draft'], FILTER_VALIDATE_BOOLEAN)) {
            $post_args['post_status'] = 'draft';
            delete_post_meta($post_id, '_tix_publish_held');
        }

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
        // Ticket-Art (1.38.436): online | free | box_office (box_office_price) | external (external_shop {url, price_from})
        if (array_key_exists('ticket_mode', $body) && $body['ticket_mode'] !== '' && $body['ticket_mode'] !== null && class_exists('TIX_Register_Event')) {
            $mode = sanitize_key((string) $body['ticket_mode']);
            $ext = is_array($body['external_shop'] ?? null) ? $body['external_shop'] : [];
            $price = $mode === 'external' ? ($ext['price_from'] ?? '') : ($body['box_office_price'] ?? '');
            $r = TIX_Register_Event::apply_ticket_mode($post_id, $mode, $price === null ? '' : (string) $price, (string) ($ext['url'] ?? ''));
            if (is_wp_error($r)) return $r;
        }
        if (array_key_exists('tax_rate', $body) && class_exists('TIX_Invoices')) {
            $tr = TIX_Invoices::clean_rate($body['tax_rate']);
            if ($tr === null) delete_post_meta($post_id, '_tix_tax_rate'); else update_post_meta($post_id, '_tix_tax_rate', (string) $tr);
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

        if (array_key_exists('lineup_links', $body) && class_exists('TIX_Lineup_Links')) {
            $ll = TIX_Lineup_Links::apply($post_id, $body['lineup_links']);
            if (is_wp_error($ll)) return $ll;
        }
        if (isset($body['muttizettel_note']) && class_exists('TIX_Muttizettel')) TIX_Muttizettel::set_note($post_id, (string) $body['muttizettel_note']);
        if (class_exists('TIX_Event_Facts') && (isset($body['accessibility_mode']) || isset($body['accessibility']) || array_key_exists('refund_policy', $body))) {
            $fr = TIX_Event_Facts::apply_editor($post_id, $body);
            if (is_wp_error($fr)) return $fr;
        }
        // ── Awareness (awareness_mode inherit|custom|off, awareness = eigene Angaben) ──
        if (class_exists('TIX_Awareness') && (isset($body['awareness_mode']) || isset($body['awareness']))) {
            if (isset($body['awareness_mode'])) {
                $am = sanitize_key((string) $body['awareness_mode']);
                if (!in_array($am, ['inherit', 'custom', 'off'], true)) return new WP_Error('tix_invalid_field', 'Ungültige Awareness-Einstellung.', ['status' => 400, 'field' => 'awareness_mode']);
                if ($am === 'inherit') delete_post_meta($post_id, TIX_Awareness::META_MODE); else update_post_meta($post_id, TIX_Awareness::META_MODE, $am);
            }
            if (isset($body['awareness']) && is_array($body['awareness'])) {
                $cur = TIX_Awareness::sanitize(get_post_meta($post_id, TIX_Awareness::META, true));
                $a = TIX_Awareness::sanitize(array_merge($cur, $body['awareness']));
                $a['enabled'] = true;
                update_post_meta($post_id, TIX_Awareness::META, $a);
            }
            TIX_Awareness::flush();
        }
        // ── Studio Stufe 3 (vorab geprüft) ──
        if ($studio) TIX_Studio_Editor::apply($post_id, $studio);
        if ($texts) TIX_Event_Texts::apply($post_id, $texts);

        // ── Mengenrabatt (vorab geprüft; null = aus) ──
        if (array_key_exists('group_discount', $pricing)) {
            if ($pricing['group_discount'] === null) delete_post_meta($post_id, '_tix_group_discount');
            else update_post_meta($post_id, '_tix_group_discount', $pricing['group_discount']);
        }

        // ── Sparte (vorab geprüft) ──
        if ($cat_term !== null) wp_set_object_terms($post_id, $cat_term ? [$cat_term] : [], 'event_category');

        // Titel/Kurztext/Status zuletzt: löst save_post_event aus (Breakdance-Meta etc.)
        $post_updated = count($post_args) > 1;
        if (count($post_args) > 1) {
            $res = wp_update_post($post_args, true);
            if (is_wp_error($res)) return $res;
        } elseif (!$is_new) {
            // Meta-Änderungen ohne Post-Update: Sync-Hook trotzdem anstoßen
            if (class_exists('TIX_Sync') && method_exists('TIX_Sync', 'sync')) {
                TIX_Sync::sync($post_id, get_post($post_id));
            }
        }

        // Verteilung anstoßen, falls kein save_post lief (Studio Stufe 3)
        if ($studio) TIX_Studio_Editor::after_save($post_id, $studio, $post_updated);

        if ($thumb !== null) {
            if ($thumb === 0) delete_post_thumbnail($post_id);
            else {
                set_post_thumbnail($post_id, $thumb);
                // vorläufiger Flyer wird fester Teil des Events (kein Aufräumen mehr)
                delete_post_meta($thumb, '_tix_register_tmp');
                if (!get_post_field('post_parent', $thumb)) wp_update_post(['ID' => $thumb, 'post_parent' => $post_id]);
            }
        }

        self::resolve_status($post_id);
        return true;
    }

    /** image_id prüfen: null = nicht gesendet, 0 = entfernen, sonst Bild-Anhang des Aufrufers (Admin: jedes Bild). → int|null|WP_Error */
    private static function check_image_id(array $body) {
        if (!array_key_exists('image_id', $body)) return null;
        $id = intval($body['image_id']);
        if ($id <= 0) return 0;
        if (get_post_type($id) !== 'attachment' || strpos((string) get_post_mime_type($id), 'image/') !== 0) {
            return new WP_Error('tix_invalid_field', 'Bild nicht gefunden.', ['status' => 400, 'field' => 'image_id']);
        }
        if (!current_user_can('manage_options') && intval(get_post_field('post_author', $id)) !== get_current_user_id()) {
            return new WP_Error('tix_invalid_field', 'Dieses Bild gehört nicht zu deinem Konto.', ['status' => 403, 'field' => 'image_id']);
        }
        return $id;
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
            // Preisphasen nur, wenn die App sie mitschickt (ältere Apps lassen sie unverändert)
            if (array_key_exists('phase_list', $in) && is_array($in['phase_list'])) {
                $cat['phases'] = self::clean_phases($in['phase_list']);
            }
            // Steuersatz nur, wenn mitgeschickt (null/'' = erben)
            if (array_key_exists('tax_rate', $in) && class_exists('TIX_Invoices')) {
                $tr = TIX_Invoices::clean_rate($in['tax_rate']);
                if ($tr === null) unset($cat['tax_rate']); else $cat['tax_rate'] = $tr;
            }
            // Paket nur, wenn mitgeschickt (null = kein Paket; geprüft in check_pricing)
            if (array_key_exists('bundle', $in)) {
                $b = is_array($in['bundle']) ? $in['bundle'] : null;
                $cat['bundle_buy']   = $b ? intval($b['buy']) : 0;
                $cat['bundle_pay']   = $b ? intval($b['pay']) : 0;
                $cat['bundle_label'] = $b ? mb_substr(sanitize_text_field((string) ($b['label'] ?? '')), 0, 60) : '';
            }

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

    // ── Pakete und Mengenrabatt (1.38.441) ──

    /** Paket einer Kategorie {buy, pay, label} oder null. */
    private static function bundle_of(array $c) {
        $buy = intval($c['bundle_buy'] ?? 0);
        $pay = intval($c['bundle_pay'] ?? 0);
        if ($buy < 2 || $pay < 1 || $pay >= $buy) return null;
        return ['buy' => $buy, 'pay' => $pay, 'label' => (string) ($c['bundle_label'] ?? '')];
    }

    /** Mengenrabatt des Events (immer ein Objekt; enabled false = aus). */
    private static function group_discount_of($id) {
        $gd = $id ? get_post_meta($id, '_tix_group_discount', true) : [];
        $gd = is_array($gd) ? $gd : [];
        $tiers = [];
        foreach ((array) ($gd['tiers'] ?? []) as $t) {
            if (!is_array($t)) continue;
            $min = intval($t['min_qty'] ?? 0); $pct = floatval($t['percent'] ?? 0);
            if ($min >= 2 && $pct > 0 && $pct < 100) $tiers[] = ['min_qty' => $min, 'percent' => $pct];
        }
        usort($tiers, function ($a, $b) { return $a['min_qty'] - $b['min_qty']; });
        return [
            'enabled'        => !empty($gd['enabled']) && (bool) $tiers,
            'tiers'          => $tiers,
            'combine_bundle' => !empty($gd['combine_bundle']),
            'combine_combo'  => !empty($gd['combine_combo']),
            'combine_phase'  => !empty($gd['combine_phase']),
        ];
    }

    /**
     * Pakete (`categories[].bundle`) und `group_discount` prüfen, bevor etwas gespeichert wird.
     * Liefert ['group_discount' => Meta|null] (nur wenn mitgeschickt) oder WP_Error 400 tix_invalid_field.
     */
    private static function check_pricing(array $body) {
        $bad = function ($field, $msg) { return new WP_Error('tix_invalid_field', $msg, ['status' => 400, 'field' => $field]); };
        if (isset($body['categories']) && is_array($body['categories'])) {
            foreach (array_values($body['categories']) as $i => $c) {
                if (!is_array($c) || !array_key_exists('bundle', $c) || $c['bundle'] === null || $c['bundle'] === false) continue;
                $b = $c['bundle'];
                if (!is_array($b)) return $bad("categories[$i].bundle", 'Ungültiges Paket.');
                $buy = filter_var($b['buy'] ?? null, FILTER_VALIDATE_INT); $pay = filter_var($b['pay'] ?? null, FILTER_VALIDATE_INT);
                if ($buy === false || $pay === false || $buy < 2 || $buy > 100 || $pay < 1 || $pay >= $buy) {
                    return $bad("categories[$i].bundle", 'Paket: „kaufe“ mindestens 2, „zahle“ mindestens 1 und weniger als „kaufe“.');
                }
            }
        }
        $out = [];
        if (array_key_exists('group_discount', $body)) {
            $gd = $body['group_discount'];
            if ($gd === null || $gd === false) { $out['group_discount'] = null; return $out; }
            if (!is_array($gd)) return $bad('group_discount', 'Ungültiger Mengenrabatt.');
            $tiers = []; $seen = [];
            foreach (array_values((array) ($gd['tiers'] ?? [])) as $i => $t) {
                $min = filter_var($t['min_qty'] ?? null, FILTER_VALIDATE_INT);
                $pct = is_numeric($t['percent'] ?? null) ? round(floatval($t['percent']), 2) : null;
                if ($min === false || $min < 2 || $min > 1000) return $bad("group_discount.tiers[$i].min_qty", 'Staffel: „ab“ mindestens 2 Tickets.');
                if ($pct === null || $pct < 1 || $pct > 99) return $bad("group_discount.tiers[$i].percent", 'Staffel: Rabatt zwischen 1 und 99 %.');
                if (isset($seen[$min])) return $bad("group_discount.tiers[$i].min_qty", 'Jede Staffel braucht eine andere Ticketzahl.');
                $seen[$min] = true;
                $tiers[] = ['min_qty' => $min, 'percent' => $pct];
            }
            if (count($tiers) > 10) return $bad('group_discount.tiers', 'Höchstens 10 Staffeln.');
            $enabled = !empty($gd['enabled']);
            if ($enabled && !$tiers) return $bad('group_discount.tiers', 'Bitte mindestens eine Staffel angeben.');
            usort($tiers, function ($a, $b) { return $a['min_qty'] - $b['min_qty']; });
            $out['group_discount'] = [
                'enabled'        => $enabled ? 1 : 0,
                'tiers'          => $tiers,
                'combine_bundle' => !empty($gd['combine_bundle']) ? 1 : 0,
                'combine_combo'  => !empty($gd['combine_combo']) ? 1 : 0,
                'combine_phase'  => !empty($gd['combine_phase']) ? 1 : 0,
            ];
        }
        return $out;
    }

    // ── Duplizieren, Import per URL (1.38.441) ──

    /** POST /events/{id}/duplicate {title?, date_start?, date_end?} → {ok, event, edit} */
    public static function duplicate(WP_REST_Request $req) {
        $id = absint($req['id']);
        if (get_post_type($id) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::user_can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        if (!class_exists('TIX_Columns')) require_once TIXOMAT_PATH . 'includes/class-tix-columns.php';
        $body = $req->get_json_params();
        $body = is_array($body) ? $body : $req->get_params();
        foreach (['date_start', 'date_end'] as $k) {
            if (!empty($body[$k]) && !self::date($body[$k])) return new WP_Error('tix_invalid_field', 'Datum bitte als JJJJ-MM-TT.', ['status' => 400, 'field' => $k]);
        }
        $new = TIX_Columns::duplicate_event($id, [
            'title'      => (string) ($body['title'] ?? ''),
            'date_start' => (string) ($body['date_start'] ?? ''),
            'date_end'   => (string) ($body['date_end'] ?? ''),
        ]);
        if (is_wp_error($new)) return $new;
        self::resolve_status($new);
        $post = get_post($new);
        return rest_ensure_response([
            'ok'    => true,
            'event' => TIX_REST_API::event_payload($post),
            'edit'  => self::edit_payload($new),
        ]);
    }

    /** POST /events/import-url {url} → {ok, source, fields} */
    public static function import_url(WP_REST_Request $req) {
        $url = esc_url_raw(trim((string) $req->get_param('url')));
        if ($url === '' || !preg_match('#^https?://#i', $url)) return new WP_Error('url_invalid', 'Bitte eine vollständige Adresse (https://…) angeben.', ['status' => 400, 'field' => 'url']);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('import_url', 10, 300, 'u' . get_current_user_id())) {
            return new WP_Error('tix_rate_limit', 'Zu viele Anfragen. Bitte warte ein paar Minuten.', ['status' => 429]);
        }
        if (!class_exists('TIX_AI_Writer') || !method_exists('TIX_AI_Writer', 'import_url')) return new WP_Error('ai_unavailable', 'Der Import ist hier nicht verfügbar.', ['status' => 503]);
        $f = TIX_AI_Writer::import_url($url);
        if (is_wp_error($f)) {
            $code = $f->get_error_code();
            $map = ['url_invalid' => 400, 'url_unreadable' => 422, 'url_blocked' => 422, 'ai_unavailable' => 503];
            $code = isset($map[$code]) ? $code : 'url_unreadable';
            $d = (array) $f->get_error_data();
            // Gesperrte Seite (1.38.507): read_mode blocked + notice (Studio fällt auf Screenshot/Text zurück)
            $extra = $code === 'url_blocked' ? ['read_mode' => 'blocked', 'notice' => (string) ($d['notice'] ?? TIX_Event_Import::BLOCKED_NOTICE), 'reason' => (string) ($d['reason'] ?? '')] : [];
            return new WP_Error($code, $f->get_error_message(), ['status' => $map[$code], 'field' => 'url'] + $extra);
        }
        $source = (string) ($f['_source'] ?? 'ai');
        // Wie gelesen (1.38.502) als eigene Felder statt in fields (1.38.505)
        $read = ['read_mode' => (string) ($f['_read_mode'] ?? 'direct'), 'notice' => $f['_notice'] ?? null, 'pages_read' => intval($f['_pages_read'] ?? 1)];
        unset($f['_source'], $f['_read_mode'], $f['_notice'], $f['_pages_read']);
        // Sparte als Slug (wie im Event-Editor)
        $ids = array_map('intval', (array) ($f['category_ids'] ?? []));
        $t = $ids ? get_term($ids[0], 'event_category') : null;
        $f['event_category'] = ($t && !is_wp_error($t)) ? (string) $t->slug : '';
        return rest_ensure_response(['ok' => true, 'source' => $source, 'fields' => $f] + $read);
    }

    // ── Erstatten, Stornieren (1.38.441) ──

    /** Geld zurück: Admin; Mehr-Veranstalter-Modus Inhaber/Team-Admin; sonst Rolle Veranstalter (Team-Admin). */
    public static function perm_money(WP_REST_Request $req) {
        $r = TIX_REST_API::check_organizer($req);
        if ($r !== true) return $r;
        $u = wp_get_current_user();
        if ($u->has_cap('manage_options')) return true;
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            return TIX_App_Scope::is_org_manager() ? true : new WP_Error('rest_forbidden', 'Nur der Veranstalter selbst (Inhaber oder Team-Admin) kann erstatten oder stornieren.', ['status' => 403]);
        }
        return in_array('tix_organizer', (array) $u->roles, true) ? true : new WP_Error('rest_forbidden', 'Nur Admins und Team-Admins können erstatten oder stornieren.', ['status' => 403]);
    }

    private static function is_pos_order($order_id) {
        global $wpdb;
        $pm = (string) $wpdb->get_var($wpdb->prepare("SELECT payment_method FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id));
        return strpos($pm, 'pos_') === 0 || get_option('_tix_order_source_' . $order_id) === 'pos';
    }

    private static function order_state($order_id) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT status, total FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id));
        return ['status' => (string) ($r->status ?? ''), 'total' => round(floatval($r->total ?? 0), 2),
                'refunded_total' => class_exists('TIX_Order_Admin') ? TIX_Order_Admin::refunded_total($order_id) : 0.0];
    }

    /** POST /orders/{id}/refund {amount?, cancel_tickets? (Vorgabe: true ohne amount, false mit amount), reason?} */
    public static function order_refund(WP_REST_Request $req) {
        $order = self::order_or_error($req['id']);
        if (is_wp_error($order)) return $order;
        $id = intval($order->get_id());
        if (self::is_pos_order($id)) return new WP_Error('pos_order', 'Kassen-Bestellungen bitte an der Kasse stornieren.', ['status' => 409]);
        $amount = $req->get_param('amount');
        if ($amount !== null && $amount !== '' && !is_numeric(str_replace(',', '.', (string) $amount))) return new WP_Error('amount_invalid', 'Ungültiger Erstattungsbetrag.', ['status' => 400, 'field' => 'amount']);
        $amount = ($amount === null || $amount === '') ? null : floatval(str_replace(',', '.', (string) $amount));
        // Ohne Angabe: Tickets nur bei voller Erstattung stornieren (Teilbetrag lässt sie gültig)
        $cancel = $req->get_param('cancel_tickets') === null ? $amount === null : filter_var($req->get_param('cancel_tickets'), FILTER_VALIDATE_BOOLEAN);
        $r = TIX_Order_Admin::process_refund($id, $amount, $cancel, mb_substr(sanitize_textarea_field((string) $req->get_param('reason')), 0, 500), 'studio');
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(['ok' => true, 'refunded' => $r['amount'], 'full' => $r['full'], 'tickets_cancelled' => $r['tickets_cancelled'], 'order' => self::order_state($id)]);
    }

    /** POST /orders/{id}/cancel – nur unbezahlte Bestellungen (offen/wartend). */
    public static function order_cancel(WP_REST_Request $req) {
        $order = self::order_or_error($req['id']);
        if (is_wp_error($order)) return $order;
        $id = intval($order->get_id());
        $st = self::order_state($id)['status'];
        if (in_array($st, ['completed', 'processing'], true)) return new WP_Error('already_paid', 'Die Bestellung ist bezahlt – bitte erstatten.', ['status' => 409]);
        if (in_array($st, ['cancelled', 'refunded', 'failed'], true)) return new WP_Error('already_cancelled', 'Die Bestellung ist bereits storniert.', ['status' => 409]);
        TIX_Native_Checkout::update_order_status($id, 'cancelled', 'admin');
        if (class_exists('TIX_Order_Admin')) TIX_Order_Admin::add_note($id, 'Storniert (studio, ' . wp_get_current_user()->display_name . ')', 'status_change');
        return rest_ensure_response(['ok' => true, 'order' => self::order_state($id)]);
    }

    /** Preisphasen für die App: [{name, price, until (Y-m-d)}], nach Datum sortiert. */
    private static function phase_list($phases) {
        if (!is_array($phases)) return [];
        $out = [];
        foreach ($phases as $ph) {
            if (!is_array($ph)) continue;
            $out[] = [
                'name'  => (string) ($ph['name'] ?? ''),
                'price' => floatval($ph['price'] ?? 0),
                'until' => (string) ($ph['until'] ?? ''),
            ];
        }
        return $out;
    }

    /** Preisphasen säubern wie die Metabox (leere Zeilen raus, nach Datum sortieren). */
    private static function clean_phases(array $raw) {
        $phases = [];
        foreach ($raw as $ph) {
            if (!is_array($ph)) continue;
            $name  = sanitize_text_field((string) ($ph['name'] ?? ''));
            $price = $ph['price'] ?? '';
            $until = sanitize_text_field((string) ($ph['until'] ?? ''));
            if ($until !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) $until = '';
            if ($name === '' && ($price === '' || $price === null) && $until === '') continue;
            $phases[] = [
                'name'  => $name,
                'price' => ($price !== '' && $price !== null) ? max(0, round(floatval($price), 2)) : 0,
                'until' => $until,
            ];
        }
        usort($phases, fn($a, $b) => strcmp($a['until'], $b['until']));
        return $phases;
    }

    /** `_tix_status` wie TIX_Sync (läuft ohne WooCommerce sonst nicht). */
    public static function resolve_status($post_id) {
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
        } elseif (class_exists('TIX_Sync') && !TIX_Sync::online_sale($post_id)) {
            $status = 'available';   // ohne Online-Verkauf nie „ausverkauft“ aus Beständen (1.38.462)
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
        // Mehr-Veranstalter-Modus: alle Events der Bestellung müssen eigene sein
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && !TIX_App_Scope::order_allowed($order)) {
            return new WP_Error('forbidden', 'Kein Zugriff auf diese Bestellung.', ['status' => 403]);
        }
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
                // Rechnungen der Bestellung (1.38.487): Liste, senden über POST /invoices/{id}/send, nachholen über POST /orders/{id}/invoices/issue
                'invoices'         => class_exists('TIX_Invoices') ? TIX_Invoices::order_summary($id) : [],
                'invoices_enabled' => class_exists('TIX_Invoices') && TIX_Invoices::enabled(),
                'invoice_pending'  => class_exists('TIX_Invoices') && in_array(intval($id), TIX_Invoices::pending_orders(), true),
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
