<?php
/**
 * P1 Event-Editor komplett (Expertenmodus, 1.38.803, Vertrag `uebergaben/vertrag-event-editor-extras.md`):
 *  B2 Galerie (+ Video), B5 Upsell, B7 Rest (Einbetten mit Domains, Express-Kasse, Warteliste) je Event
 *  B3 Event-Kategorien und Themen-Sammlungen verwalten (anlegen, umbenennen, sortieren, löschen)
 * Gleiche Metas wie die WP-Metabox (inkl. abgeleiteter Galerie-Metas für Breakdance/alte Leser).
 */
if (!defined('ABSPATH')) exit;

class TIX_Event_Editor_Extras {

    const NS = 'tixomat/v1';
    const GALLERY_MAX = 40;
    const UPSELL_MAX = 10;
    const DOMAINS_MAX = 20;
    const TAXES = ['categories' => 'event_category', 'collections' => 'tix_collection'];
    const META_ORDER = '_tix_order';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function routes() {
        $ev = [__CLASS__, 'perm_event'];
        register_rest_route(self::NS, '/events/(?P<id>\d+)/extras', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $ev],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $ev],
        ]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/gallery', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_gallery_upload'], 'permission_callback' => $ev]);
        register_rest_route(self::NS, '/studio/taxonomies/(?P<tax>categories|collections)', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_terms'], 'permission_callback' => ['TIX_REST_API', 'check_organizer']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_term_save'], 'permission_callback' => [__CLASS__, 'perm_terms']],
        ]);
        register_rest_route(self::NS, '/studio/taxonomies/(?P<tax>categories|collections)/order', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_term_order'], 'permission_callback' => [__CLASS__, 'perm_terms']]);
        register_rest_route(self::NS, '/studio/taxonomies/(?P<tax>categories|collections)/(?P<term>\d+)', [
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_term_save'], 'permission_callback' => [__CLASS__, 'perm_terms']],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_term_delete'], 'permission_callback' => [__CLASS__, 'perm_terms']],
        ]);
    }

    public static function perm_event(WP_REST_Request $req) {
        $c = TIX_REST_API::check_organizer($req);
        if ($c !== true) return $c;
        $id = absint($req['id']);
        if (get_post_type($id) !== 'event') return new WP_Error('tix_not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::user_can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        return true;
    }

    /** Kategorien: Admin bzw. Inhaber der Einzel-Seite; evendis nur Admins (gelten für alle). Sammlungen: nur Admins. */
    public static function perm_terms(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        $u = wp_get_current_user();
        if ($u->has_cap('manage_options') || $u->has_cap('manage_categories')) return true;
        if ((string) $req['tax'] === 'categories' && !self::multi() && in_array('tix_organizer', (array) $u->roles, true)) return true;
        return new WP_Error('rest_forbidden', self::multi() ? 'Kategorien und Sammlungen gelten für die ganze Plattform – nur Admins.' : 'Nur Inhaber bzw. Admins.', ['status' => 403]);
    }

    // ═══════════════ Je Event ═══════════════

    private static function img($id) {
        $u = wp_get_attachment_image_url($id, 'large') ?: wp_get_attachment_url($id);
        return $u ? ['id' => intval($id), 'url' => (string) $u, 'thumb' => (string) (wp_get_attachment_image_url($id, 'medium') ?: $u)] : null;
    }

    public static function payload($eid) {
        $gal = array_values(array_filter(array_map([__CLASS__, 'img'], array_map('intval', (array) get_post_meta($eid, '_tix_gallery', true)))));
        $up = array_values(array_filter(array_map('intval', (array) get_post_meta($eid, '_tix_upsell_events', true))));
        $dom = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) get_post_meta($eid, '_tix_embed_domains', true)))));
        return ['ok' => true, 'event_id' => $eid,
            'gallery' => $gal, 'gallery_max' => self::GALLERY_MAX,
            'video_url' => (string) get_post_meta($eid, '_tix_video_url', true) ?: null,
            'upsell' => ['disabled' => get_post_meta($eid, '_tix_upsell_disabled', true) === '1',
                'events' => array_map(function ($i) { return ['id' => $i, 'title' => html_entity_decode(get_the_title($i), ENT_QUOTES, 'UTF-8'), 'date' => (string) get_post_meta($i, '_tix_date_start', true) ?: null, 'status' => (string) get_post_status($i)]; }, $up),
                'max' => self::UPSELL_MAX],
            'embed' => ['enabled' => get_post_meta($eid, '_tix_embed_enabled', true) === '1', 'domains' => $dom, 'max' => self::DOMAINS_MAX],
            'express_checkout' => get_post_meta($eid, '_tix_express_checkout', true) === '1',
            'waitlist_enabled' => get_post_meta($eid, '_tix_waitlist_enabled', true) === '1'];
    }

    public static function rest_get(WP_REST_Request $req) {
        return rest_ensure_response(self::payload(absint($req['id'])));
    }

    private static function image_ok($id, $eid) {
        if (get_post_type($id) !== 'attachment' || strpos((string) get_post_mime_type($id), 'image/') !== 0) return false;
        if (!self::multi() || current_user_can('manage_options')) return true;
        return intval(get_post_field('post_parent', $id)) === intval($eid) || intval(get_post_field('post_author', $id)) === get_current_user_id()
            || in_array(intval($id), array_map('intval', (array) get_post_meta($eid, '_tix_gallery', true)), true);
    }

    private static function bad($field, $msg) {
        return new WP_Error('tix_invalid_field', $msg, ['status' => 400, 'field' => $field]);
    }

    /** Galerie wie die Metabox speichern (inkl. abgeleiteter Metas) */
    public static function store_gallery($eid, array $ids) {
        update_post_meta($eid, '_tix_gallery', $ids);
        update_post_meta($eid, '_tix_gallery_ids', implode(',', $ids));
        $full = []; $large = [];
        foreach ($ids as $gid) { if ($u = wp_get_attachment_image_url($gid, 'full')) $full[] = $u; if ($u = wp_get_attachment_image_url($gid, 'large')) $large[] = $u; }
        update_post_meta($eid, '_tix_gallery_urls', implode(',', $full));
        update_post_meta($eid, '_tix_gallery_urls_large', implode(',', $large));
        update_post_meta($eid, '_tix_gallery_count', count($ids));
    }

    /** Eingabe prüfen → [meta => wert] | WP_Error (reine Regeln bis auf Zugriffsprüfungen) */
    private static function clean($eid, array $b) {
        $out = [];
        if (array_key_exists('gallery', $b)) {
            if (!is_array($b['gallery'])) return self::bad('gallery', 'Galerie als Liste von Bild-IDs.');
            $ids = array_values(array_unique(array_filter(array_map(function ($g) { return intval(is_array($g) ? ($g['id'] ?? 0) : $g); }, $b['gallery']))));
            if (count($ids) > self::GALLERY_MAX) return self::bad('gallery', 'Höchstens ' . self::GALLERY_MAX . ' Bilder.');
            foreach ($ids as $i => $gid) if (!self::image_ok($gid, $eid)) return self::bad('gallery.' . $i, 'Dieses Bild ist nicht verfügbar.');
            $out['gallery'] = $ids;
        }
        if (array_key_exists('video_url', $b)) {
            $v = trim((string) ($b['video_url'] ?? ''));
            if ($v !== '' && (!preg_match('#^https://[^\s/]+\.[^\s]+#i', $v) || strlen($v) > 500)) return self::bad('video_url', 'Video-Link bitte mit https://.');
            $out['_tix_video_url'] = esc_url_raw($v);
        }
        if (array_key_exists('upsell', $b)) {
            $u = $b['upsell'];
            if (!is_array($u)) return self::bad('upsell', 'upsell als Objekt.');
            if (array_key_exists('disabled', $u)) $out['_tix_upsell_disabled'] = !empty($u['disabled']) ? '1' : '';
            if (array_key_exists('event_ids', $u)) {
                $ids = array_values(array_unique(array_filter(array_map('intval', (array) $u['event_ids']))));
                if (count($ids) > self::UPSELL_MAX) return self::bad('upsell.event_ids', 'Höchstens ' . self::UPSELL_MAX . ' Events.');
                foreach ($ids as $i => $x) {
                    if ($x === $eid || get_post_type($x) !== 'event' || !TIX_REST_API::user_can_access_event($x)) return self::bad('upsell.event_ids.' . $i, 'Dieses Event kann nicht angeboten werden.');
                }
                $out['_tix_upsell_events'] = $ids;
            }
        }
        if (array_key_exists('embed', $b)) {
            $e = $b['embed'];
            if (!is_array($e)) return self::bad('embed', 'embed als Objekt.');
            if (array_key_exists('enabled', $e)) $out['_tix_embed_enabled'] = !empty($e['enabled']) ? '1' : '0';
            if (array_key_exists('domains', $e)) {
                $doms = [];
                foreach ((array) $e['domains'] as $i => $d) {
                    $d = strtolower(trim(preg_replace('#^https?://#i', '', (string) $d), "/ \t"));
                    if ($d === '') continue;
                    if (!preg_match('/^(\*\.)?([a-z0-9-]+\.)+[a-z]{2,}$/', $d)) return self::bad('embed.domains.' . $i, 'Ungültige Domain „' . $d . '“ (z. B. meine-seite.de).');
                    $doms[] = $d;
                }
                $doms = array_values(array_unique($doms));
                if (count($doms) > self::DOMAINS_MAX) return self::bad('embed.domains', 'Höchstens ' . self::DOMAINS_MAX . ' Domains.');
                $out['_tix_embed_domains'] = implode(', ', $doms);
            }
        }
        if (array_key_exists('express_checkout', $b)) $out['_tix_express_checkout'] = !empty($b['express_checkout']) ? '1' : '0';
        if (array_key_exists('waitlist_enabled', $b)) $out['_tix_waitlist_enabled'] = !empty($b['waitlist_enabled']) ? '1' : '';
        return $out;
    }

    /** POST /events/{id}/extras {gallery?, video_url?, upsell?{disabled, event_ids}, embed?{enabled, domains}, express_checkout?, waitlist_enabled?} */
    public static function rest_save(WP_REST_Request $req) {
        $eid = absint($req['id']);
        $c = self::clean($eid, (array) $req->get_json_params());
        if (is_wp_error($c)) return $c;
        if (isset($c['gallery'])) { self::store_gallery($eid, $c['gallery']); unset($c['gallery']); }
        foreach ($c as $k => $v) update_post_meta($eid, $k, $v);
        clean_post_cache($eid);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        if (class_exists('TIX_Syndication_Push') && get_post_meta($eid, '_tix_syndicate', true) === '1') TIX_Syndication_Push::push_event($eid);
        return self::rest_get($req);
    }

    /** POST /events/{id}/gallery (multipart image) → Bild hinten anhängen, Antwort wie GET */
    public static function rest_gallery_upload(WP_REST_Request $req) {
        $eid = absint($req['id']);
        $ids = array_values(array_filter(array_map('intval', (array) get_post_meta($eid, '_tix_gallery', true))));
        if (count($ids) >= self::GALLERY_MAX) return new WP_Error('tix_gallery_full', 'Höchstens ' . self::GALLERY_MAX . ' Bilder.', ['status' => 409]);
        $files = $req->get_file_params();
        $file = $files['image'] ?? ($files['file'] ?? null);
        if (empty($file['tmp_name'])) return self::bad('image', 'Kein Bild hochgeladen.');
        if (!empty($file['error']) || intval($file['size'] ?? 0) > 15 * MB_IN_BYTES) return new WP_Error('too_large', 'Das Bild ist zu groß (höchstens 15 MB).', ['status' => 413, 'field' => 'image']);
        $info = @getimagesize($file['tmp_name']);
        $mime = is_array($info) ? (string) $info['mime'] : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return self::bad('image', 'Bitte ein JPG-, PNG- oder WebP-Bild wählen.');
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
        $aid = media_handle_sideload(['name' => 'galerie-' . $eid . '-' . time() . '.' . $ext, 'tmp_name' => $file['tmp_name'], 'type' => $mime, 'size' => $file['size'] ?? 0], $eid, 'Galerie');
        if (is_wp_error($aid)) return new WP_Error('upload_failed', $aid->get_error_message(), ['status' => 500]);
        $ids[] = intval($aid);
        self::store_gallery($eid, $ids);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return self::rest_get($req);
    }

    // ═══════════════ B3 Kategorien / Sammlungen ═══════════════

    private static function tax($req) {
        return self::TAXES[(string) $req['tax']] ?? '';
    }

    private static function term_out($t, $tax) {
        $o = ['id' => intval($t->term_id), 'name' => html_entity_decode((string) $t->name, ENT_QUOTES, 'UTF-8'), 'slug' => (string) $t->slug, 'count' => intval($t->count),
            'parent' => intval($t->parent) ?: null, 'order' => ($v = get_term_meta($t->term_id, self::META_ORDER, true)) === '' ? null : intval($v)];
        if ($tax === 'tix_collection') {
            $img = intval(get_term_meta($t->term_id, '_tix_image_id', true));
            $o += ['intro' => (string) get_term_meta($t->term_id, '_tix_intro', true), 'image' => $img ? self::img($img) : null,
                'visible_from' => (string) get_term_meta($t->term_id, '_tix_visible_from', true) ?: null, 'visible_to' => (string) get_term_meta($t->term_id, '_tix_visible_to', true) ?: null];
        }
        return $o;
    }

    public static function rest_terms(WP_REST_Request $req) {
        $tax = self::tax($req);
        if (!taxonomy_exists($tax)) return new WP_Error('tix_not_found', 'Diese Liste gibt es hier nicht.', ['status' => 404]);
        $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
        $out = array_map(function ($t) use ($tax) { return self::term_out($t, $tax); }, is_wp_error($terms) ? [] : $terms);
        usort($out, function ($a, $b) { $x = ($a['order'] ?? 9999) <=> ($b['order'] ?? 9999); return $x !== 0 ? $x : strcasecmp($a['name'], $b['name']); });
        return rest_ensure_response(['ok' => true, 'taxonomy' => (string) $req['tax'], 'can_edit' => self::perm_terms($req) === true, 'terms' => $out]);
    }

    /** POST …/{tax} (anlegen) bzw. …/{tax}/{term} (ändern): {name, slug?, parent?, intro?, image_id?, visible_from?, visible_to?} */
    public static function rest_term_save(WP_REST_Request $req) {
        $tax = self::tax($req);
        if (!taxonomy_exists($tax)) return new WP_Error('tix_not_found', 'Diese Liste gibt es hier nicht.', ['status' => 404]);
        $b = (array) $req->get_json_params();
        $tid = absint($req['term'] ?? 0);
        if ($tid && !get_term($tid, $tax)) return new WP_Error('tix_not_found', 'Eintrag nicht gefunden.', ['status' => 404]);
        $args = [];
        if (array_key_exists('name', $b) || !$tid) {
            $name = trim(sanitize_text_field((string) ($b['name'] ?? '')));
            if ($name === '' || mb_strlen($name) > 80) return self::bad('name', 'Name 1–80 Zeichen.');
            $args['name'] = $name;
        }
        if (array_key_exists('slug', $b)) { $sl = sanitize_title((string) $b['slug']); if ($sl === '') return self::bad('slug', 'Ungültige Kurzform.'); $args['slug'] = $sl; }
        if (array_key_exists('parent', $b)) { $p = absint($b['parent']); if ($p && (!get_term($p, $tax) || $p === $tid)) return self::bad('parent', 'Ungültige Oberkategorie.'); $args['parent'] = $p; }
        if ($tid) { $r = wp_update_term($tid, $tax, $args); }
        else { $r = wp_insert_term($args['name'], $tax, array_diff_key($args, ['name' => 1])); }
        if (is_wp_error($r)) return new WP_Error($r->get_error_code() === 'term_exists' ? 'tix_term_exists' : 'tix_term_failed', $r->get_error_code() === 'term_exists' ? 'Diesen Eintrag gibt es schon.' : $r->get_error_message(), ['status' => $r->get_error_code() === 'term_exists' ? 409 : 400, 'field' => 'name']);
        $tid = intval($r['term_id']);
        if ($tax === 'tix_collection') {
            if (array_key_exists('intro', $b)) update_term_meta($tid, '_tix_intro', wp_kses_post((string) $b['intro']));
            if (array_key_exists('image_id', $b)) { $img = absint($b['image_id']); if ($img && get_post_type($img) !== 'attachment') return self::bad('image_id', 'Bild nicht gefunden.'); update_term_meta($tid, '_tix_image_id', $img ?: ''); }
            foreach (['visible_from' => '_tix_visible_from', 'visible_to' => '_tix_visible_to'] as $k => $mk) {
                if (!array_key_exists($k, $b)) continue;
                $v = (string) ($b[$k] ?? '');
                if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return self::bad($k, 'Datum JJJJ-MM-TT.');
                update_term_meta($tid, $mk, $v);
            }
        }
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        $res = rest_ensure_response(['ok' => true, 'term' => self::term_out(get_term($tid, $tax), $tax)]);
        if (!absint($req['term'] ?? 0)) $res->set_status(201);
        return $res;
    }

    /** POST …/{tax}/order {ids: [..]} – Reihenfolge im Studio und in den Auswahllisten */
    public static function rest_term_order(WP_REST_Request $req) {
        $tax = self::tax($req);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $req->get_param('ids')))));
        if (!$ids) return self::bad('ids', 'Bitte die Reihenfolge als Liste von IDs.');
        foreach ($ids as $i => $id) { if (!get_term($id, $tax)) return self::bad('ids.' . $i, 'Eintrag nicht gefunden.'); }
        foreach ($ids as $i => $id) update_term_meta($id, self::META_ORDER, $i + 1);
        return self::rest_terms($req);
    }

    /** DELETE …/{tax}/{term}[?force=1] – mit Events nur mit force (Events behalten ihre übrigen Kategorien) */
    public static function rest_term_delete(WP_REST_Request $req) {
        $tax = self::tax($req);
        $t = get_term(absint($req['term']), $tax);
        if (!$t || is_wp_error($t)) return new WP_Error('tix_not_found', 'Eintrag nicht gefunden.', ['status' => 404]);
        if (intval($t->count) > 0 && !filter_var($req->get_param('force'), FILTER_VALIDATE_BOOLEAN)) return new WP_Error('tix_term_in_use', 'Diesem Eintrag sind noch ' . intval($t->count) . ' Event(s) zugeordnet.', ['status' => 409, 'count' => intval($t->count)]);
        $r = wp_delete_term($t->term_id, $tax);
        if (!$r || is_wp_error($r)) return new WP_Error('tix_term_failed', 'Löschen fehlgeschlagen.', ['status' => 500]);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return rest_ensure_response(['ok' => true, 'deleted' => intval($t->term_id)]);
    }
}
