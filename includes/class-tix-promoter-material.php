<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Promoter_Material – Werbematerial für Promoter (Promoter-Ausbau Paket 2, 1.38.662).
 *
 * Texte je Event (WhatsApp, Instagram-Caption, Story, Telegram) erzeugt die KI einmal je Event (TIX_AI_Provider,
 * Bereich des Veranstalters) mit Platzhaltern {link} und {code}; gespeichert in der Event-Meta `_tix_promoter_texts`.
 * Jeder Promoter bekommt sie mit seinem Link bzw. Code eingesetzt. Ohne KI: feste Vorlagen.
 * Der Veranstalter kann die Texte ändern oder neu erzeugen und eigenes Material je Event hochladen
 * (Bilder, PDF, Video; Anhänge mit `_tix_pm_event`).
 *
 * Promoter (X-Tix-Promoter-Token):
 *   GET  /promoter/material?event_id=        Event, Links, Code, Marke, Texte, Uploads
 *   POST /promoter/material/texts {event_id}  KI-Texte erzeugen, wenn noch keine da sind (sonst Cache)
 *   GET  /promoter/material/image?event_id=   Event-Bild als Data-URL (für den Renderer im Studio, nur eigene Events)
 * Veranstalter (Inhaber/Team-Admin, nur eigene Events):
 *   GET|POST /organizer/events/{id}/promoter-material        Texte + Uploads | Datei hochladen
 *   DELETE   /organizer/events/{id}/promoter-material/{aid}  Upload löschen
 *   POST     /organizer/events/{id}/promoter-texts {texts?|refresh}  Texte ändern bzw. neu erzeugen
 */
class TIX_Promoter_Material {

    const NS = 'tixomat/v1';
    const META_TEXTS = '_tix_promoter_texts';
    const META_UPLOAD = '_tix_pm_event';
    const KINDS = ['whatsapp' => 'WhatsApp', 'instagram' => 'Instagram-Caption', 'story' => 'Story-Text', 'telegram' => 'Telegram'];
    const MAX_BYTES = 26214400; // 25 MB
    const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf', 'video/mp4' => 'mp4'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        $pp = ['TIX_Promoter_Portal', 'perm'];
        $org = [__CLASS__, 'perm_org'];
        register_rest_route(self::NS, '/promoter/material', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $pp]);
        register_rest_route(self::NS, '/promoter/material/texts', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_texts'], 'permission_callback' => $pp]);
        register_rest_route(self::NS, '/promoter/material/image', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_image'], 'permission_callback' => $pp]);
        register_rest_route(self::NS, '/organizer/events/(?P<id>\d+)/promoter-material', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_org_get'], 'permission_callback' => $org],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_org_upload'], 'permission_callback' => $org],
        ]);
        register_rest_route(self::NS, '/organizer/events/(?P<id>\d+)/promoter-material/(?P<aid>\d+)', ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_org_delete'], 'permission_callback' => $org]);
        register_rest_route(self::NS, '/organizer/events/(?P<id>\d+)/promoter-texts', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_org_texts'], 'permission_callback' => $org]);
    }

    private static function bad($field, $msg, $code = 'tix_invalid_field', $status = 400) { return new WP_Error($code, $msg, ['status' => $status, 'field' => $field]); }

    public static function perm_org(WP_REST_Request $req) {
        $p = class_exists('TIX_Ad_Accounts') ? TIX_Ad_Accounts::perm($req) : current_user_can('manage_options');
        if ($p !== true) return $p;
        $eid = intval($req['id']);
        if (get_post_type($eid) !== 'event' || !in_array($eid, array_map('intval', TIX_Studio_Stats::scope_ids()), true)) return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        return true;
    }

    /** Darf der angemeldete Promoter für das Event werben? → event_id | WP_Error */
    private static function promoter_event(WP_REST_Request $req) {
        $p = TIX_Promoter_Portal::current();
        $eid = absint($req->get_param('event_id'));
        if (!$p || $p->status !== 'active') return new WP_Error('tix_promoter_pending', 'Dein Zugang wartet noch auf die Freigabe.', ['status' => 403]);
        if (!$eid || get_post_type($eid) !== 'event' || get_post_status($eid) !== 'publish' || TIX_Promoter::excluded_event($eid)) return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        $scope = TIX_Promoter::scope_event_ids($p);
        if ($scope !== null && !in_array($eid, $scope, true)) return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        return $eid;
    }

    private static function org_of_event($eid) {
        return (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) ? intval(get_post_meta($eid, '_tix_organizer_id', true)) : 0;
    }

    private static function event_url($eid) {
        $u = (string) get_permalink($eid);
        return class_exists('TIX_Customer_URL') ? TIX_Customer_URL::to($u) : $u;
    }

    private static function facts($eid) {
        $f = class_exists('TIX_Public_Events') ? (array) TIX_Public_Events::payload($eid, true) : [];
        return [
            'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'),
            'date' => (string) get_post_meta($eid, '_tix_date_start', true), 'time' => (string) get_post_meta($eid, '_tix_time_start', true),
            'location' => (string) get_post_meta($eid, '_tix_location', true),
            'teaser' => wp_strip_all_tags((string) ($f['excerpt'] ?? $f['short_description'] ?? get_post_field('post_excerpt', $eid))),
            'lineup' => mb_substr(wp_strip_all_tags((string) get_post_meta($eid, '_tix_info_lineup', true)), 0, 400),
            'price_from' => $f['price_from'] ?? null, 'age' => (string) ($f['age_label'] ?? ''),
        ];
    }

    // ═══════════════ Texte ═══════════════

    /** Feste Vorlagen (ohne KI) mit Platzhaltern */
    public static function templates($eid) {
        $f = self::facts($eid);
        $when = $f['date'] ? wp_date('l, j. F', strtotime($f['date'])) . ($f['time'] ? ' ab ' . $f['time'] . ' Uhr' : '') : '';
        $where = $f['location'] !== '' ? ' · ' . $f['location'] : '';
        return [
            'whatsapp' => "Hey! 🎉 {$f['title']} – {$when}{$where}. Ich bin dabei, kommst du mit? Tickets hier: {link}",
            'instagram' => "{$f['title']} 🔥\n{$when}{$where}\n\nTickets über den Link in meiner Bio oder hier: {link}\n\n#party #wochenende",
            'story' => "{$f['title']}\n{$when}\nTickets: Link antippen 👆",
            'telegram' => "{$f['title']} – {$when}{$where}\nTickets: {link}",
        ];
    }

    /** Gespeicherte Texte {texts{…}, source ai|edited, at} oder null */
    public static function stored($eid) {
        $v = get_post_meta(intval($eid), self::META_TEXTS, true);
        return (is_array($v) && !empty($v['texts']) && is_array($v['texts'])) ? $v : null;
    }

    private static function clean_texts(array $in) {
        $out = [];
        foreach (array_keys(self::KINDS) as $k) {
            $t = trim(sanitize_textarea_field((string) ($in[$k] ?? '')));
            if ($t === '') continue;
            $t = mb_substr($t, 0, $k === 'instagram' ? 2000 : 900);
            if ($k !== 'story' && strpos($t, '{link}') === false) $t .= ' {link}';
            $out[$k] = $t;
        }
        return $out;
    }

    /** Platzhalter einsetzen */
    public static function fill(array $texts, $link, $code, $coupon = '') {
        $out = [];
        foreach ($texts as $k => $t) $out[$k] = str_replace(['{link}', '{code}', '{rabatt_code}'], [$link, $coupon !== '' ? $coupon : $code, $coupon], (string) $t);
        return $out;
    }

    /** KI-Texte erzeugen und speichern → array | WP_Error */
    public static function generate($eid) {
        if (!class_exists('TIX_AI_Provider')) return new WP_Error('ai_unavailable', 'Die KI ist hier nicht eingerichtet.', ['status' => 503]);
        $org = self::org_of_event($eid);
        if (!TIX_AI_Provider::ready($org)) return new WP_Error('ai_unavailable', 'Die KI ist hier nicht eingerichtet (kein API-Schlüssel).', ['status' => 503]);
        $lock = 'tix_pm_gen_' . intval($eid);
        if (get_transient($lock)) return new WP_Error('tix_busy', 'Die Texte werden gerade erstellt – bitte gleich noch einmal.', ['status' => 409]);
        set_transient($lock, 1, 90);
        $f = self::facts($eid);
        $site = class_exists('TIX_Mail_Layout') ? (string) (TIX_Mail_Layout::brand()['name'] ?? '') : get_bloginfo('name');
        $tool = ['name' => 'werbetexte', 'description' => 'Werbetexte für Promoter, die Freunden und Followern ein Event empfehlen.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'whatsapp' => ['type' => 'string', 'description' => 'Persönliche Nachricht an Freunde, 1–3 Sätze, mit {link}'],
                'instagram' => ['type' => 'string', 'description' => 'Caption mit Zeilenumbrüchen, 2–5 Hashtags, mit {link}'],
                'story' => ['type' => 'string', 'description' => 'Sehr kurzer Story-Text, höchstens 2 Zeilen, ohne Link'],
                'telegram' => ['type' => 'string', 'description' => 'Sachliche Gruppen-Nachricht mit Datum, Ort und {link}'],
            ], 'required' => array_keys(self::KINDS)]];
        $prompt = "Schreibe Werbetexte auf Deutsch (du-Form, locker, ehrlich, keine Übertreibungen, keine erfundenen Fakten, kein Wort „Ballermann“) für dieses Event von {$site}. "
            . "Setze genau den Platzhalter {link} für den Ticket-Link ein (in der Story keinen Link). Wenn ein Rabatt erwähnt werden soll, nutze {code}, sonst nicht.\n"
            . wp_json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payload = ['max_tokens' => 1200, 'tools' => [$tool], 'tool_choice' => ['type' => 'tool', 'name' => 'werbetexte'], 'messages' => [['role' => 'user', 'content' => $prompt]]];
        $res = TIX_AI_Provider::with_org($org, function () use ($payload) { return TIX_AI_Provider::send($payload, 45); });
        delete_transient($lock);
        if (is_wp_error($res)) return $res;
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if (intval(wp_remote_retrieve_response_code($res)) !== 200 || !is_array($body)) return new WP_Error('ai_failed', 'Die KI hat gerade nicht geantwortet. Bitte später noch einmal.', ['status' => 502]);
        $in = null;
        foreach ((array) ($body['content'] ?? []) as $c) if (($c['type'] ?? '') === 'tool_use') $in = (array) $c['input'];
        $texts = $in ? self::clean_texts($in) : [];
        if (count($texts) < 3) return new WP_Error('ai_failed', 'Die KI hat keine brauchbaren Texte geliefert.', ['status' => 502]);
        foreach (self::templates($eid) as $k => $t) if (!isset($texts[$k])) $texts[$k] = $t;
        // Geschützter Begriff (User-Regel): solche Texte durch die Vorlage ersetzen
        foreach ($texts as $k => $t) if (preg_match('/ballermann/iu', $t)) $texts[$k] = self::templates($eid)[$k];
        $v = ['texts' => $texts, 'source' => 'ai', 'at' => gmdate('c')];
        update_post_meta($eid, self::META_TEXTS, wp_slash($v));
        return $v;
    }

    // ═══════════════ Uploads ═══════════════

    public static function uploads($eid) {
        $out = [];
        foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 50, 'meta_key' => self::META_UPLOAD, 'meta_value' => intval($eid), 'orderby' => 'date', 'order' => 'DESC']) as $a) {
            $m = wp_get_attachment_metadata($a->ID) ?: [];
            $f = get_attached_file($a->ID);
            $out[] = ['id' => intval($a->ID), 'name' => (string) $a->post_title, 'mime' => (string) $a->post_mime_type, 'url' => (string) wp_get_attachment_url($a->ID),
                'thumb' => (string) (wp_get_attachment_image_url($a->ID, 'medium') ?: ''), 'width' => intval($m['width'] ?? 0), 'height' => intval($m['height'] ?? 0),
                'size' => $f && file_exists($f) ? intval(filesize($f)) : 0, 'created' => mysql_to_rfc3339($a->post_date_gmt)];
        }
        return $out;
    }

    // ═══════════════ Promoter ═══════════════

    public static function rest_get(WP_REST_Request $req) {
        $eid = self::promoter_event($req);
        if (is_wp_error($eid)) return $eid;
        $p = TIX_Promoter_Portal::current();
        $a = TIX_Promoter_DB::get_assignment_by_promoter_event(intval($p->id), $eid, true);
        $l = TIX_Promoter_REST::links((string) $p->promoter_code, self::event_url($eid), TIX_Promoter_REST::link_coupon($a), $eid);
        $st = self::stored($eid);
        $texts = $st ? $st['texts'] : self::templates($eid);
        $b = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand() : [];
        $f = self::facts($eid);
        return rest_ensure_response(['ok' => true,
            'event' => ['id' => $eid, 'title' => $f['title'], 'date' => $f['date'], 'time' => $f['time'], 'location' => $f['location'], 'url' => self::event_url($eid),
                'image' => (string) (get_the_post_thumbnail_url($eid, 'large') ?: '')],
            'code' => (string) $p->promoter_code, 'coupon' => $l['coupon'], 'links' => $l,
            'brand' => ['name' => (string) ($b['name'] ?? get_bloginfo('name')), 'logo' => (string) ($b['logo'] ?? ''), 'accent' => (string) ($b['accent'] ?? '#FC2F55')],
            'texts' => self::fill($texts, $l['link'], (string) $p->promoter_code, (string) ($l['coupon'] ?? '')),
            'texts_source' => $st ? (string) $st['source'] : 'template',
            'texts_ai_available' => !$st && class_exists('TIX_AI_Provider') && TIX_AI_Provider::ready(self::org_of_event($eid)),
            'share' => ['whatsapp' => 'https://wa.me/?text=' . rawurlencode(self::fill(['t' => $texts['whatsapp'] ?? ''], $l['link'], (string) $p->promoter_code, (string) ($l['coupon'] ?? ''))['t']),
                'telegram' => 'https://t.me/share/url?url=' . rawurlencode($l['link']) . '&text=' . rawurlencode(self::fill(['t' => str_replace('{link}', '', $texts['telegram'] ?? '')], '', (string) $p->promoter_code, (string) ($l['coupon'] ?? ''))['t'])],
            'uploads' => self::uploads($eid),
        ]);
    }

    /** POST /promoter/material/texts {event_id} – einmal je Event (danach Cache für alle Promoter) */
    public static function rest_texts(WP_REST_Request $req) {
        $eid = self::promoter_event($req);
        if (is_wp_error($eid)) return $eid;
        if (!self::stored($eid)) {
            $p = TIX_Promoter_Portal::current();
            if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('pm_texts', 10, DAY_IN_SECONDS, 'p' . intval($p->id))) return new WP_Error('tix_rate_limit', 'Heute wurden schon genug Texte erstellt.', ['status' => 429]);
            $g = self::generate($eid);
            if (is_wp_error($g)) return $g;
        }
        return self::rest_get($req);
    }

    /** GET /promoter/material/image?event_id=&size=large|medium_large → {data_url, mime, width, height} */
    public static function rest_image(WP_REST_Request $req) {
        $eid = self::promoter_event($req);
        if (is_wp_error($eid)) return $eid;
        $tid = intval(get_post_thumbnail_id($eid));
        if (!$tid) return new WP_Error('not_found', 'Dieses Event hat kein Bild.', ['status' => 404]);
        $size = in_array($req->get_param('size'), ['medium_large', 'large', 'full'], true) ? (string) $req->get_param('size') : 'large';
        $src = wp_get_attachment_image_src($tid, $size);
        $file = get_attached_file($tid);
        if ($src && $size !== 'full' && !empty($src[0])) {
            $meta = wp_get_attachment_metadata($tid);
            if (!empty($meta['sizes'][$size]['file'])) $file = trailingslashit(dirname($file)) . $meta['sizes'][$size]['file'];
        }
        if (!$file || !is_readable($file) || filesize($file) > 6291456) return new WP_Error('not_found', 'Bild nicht verfügbar.', ['status' => 404]);
        $mime = (string) (wp_check_filetype($file)['type'] ?: 'image/jpeg');
        $sz = @getimagesize($file);
        return rest_ensure_response(['ok' => true, 'mime' => $mime, 'width' => intval($sz[0] ?? 0), 'height' => intval($sz[1] ?? 0),
            'data_url' => 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($file))]);
    }

    // ═══════════════ Veranstalter ═══════════════

    public static function rest_org_get(WP_REST_Request $req) {
        $eid = intval($req['id']);
        $st = self::stored($eid);
        return rest_ensure_response(['ok' => true, 'event_id' => $eid, 'texts' => $st ? $st['texts'] : self::templates($eid), 'texts_source' => $st ? (string) $st['source'] : 'template',
            'texts_at' => $st ? (string) $st['at'] : null, 'ai_available' => class_exists('TIX_AI_Provider') && TIX_AI_Provider::ready(self::org_of_event($eid)),
            'placeholders' => ['{link}' => 'persönlicher Ticket-Link', '{code}' => 'Rabatt- bzw. Promoter-Code'], 'uploads' => self::uploads($eid)]);
    }

    /** POST /organizer/events/{id}/promoter-texts {texts{whatsapp, instagram, story, telegram}} | {refresh: true} | {reset: true} */
    public static function rest_org_texts(WP_REST_Request $req) {
        $eid = intval($req['id']);
        if (filter_var($req->get_param('reset'), FILTER_VALIDATE_BOOLEAN)) {
            delete_post_meta($eid, self::META_TEXTS);
        } elseif (filter_var($req->get_param('refresh'), FILTER_VALIDATE_BOOLEAN)) {
            if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('pm_texts_org', 10, DAY_IN_SECONDS, 'e' . $eid)) return new WP_Error('tix_rate_limit', 'Für dieses Event wurden heute schon oft Texte erstellt.', ['status' => 429]);
            $g = self::generate($eid);
            if (is_wp_error($g)) return $g;
        } else {
            $t = self::clean_texts((array) $req->get_param('texts'));
            if (!$t) return self::bad('texts', 'Bitte mindestens einen Text eingeben.');
            $cur = self::stored($eid);
            $all = array_merge($cur ? $cur['texts'] : self::templates($eid), $t);
            update_post_meta($eid, self::META_TEXTS, wp_slash(['texts' => $all, 'source' => 'edited', 'at' => gmdate('c')]));
        }
        return self::rest_org_get($req);
    }

    /** POST /organizer/events/{id}/promoter-material – Datei (multipart `file`) oder `data` (Data-URL/Base64) + `name` */
    public static function rest_org_upload(WP_REST_Request $req) {
        $eid = intval($req['id']);
        if (count(self::uploads($eid)) >= 50) return self::bad('file', 'Höchstens 50 Dateien je Event.', 'tix_limit', 409);
        $files = $req->get_file_params();
        $bytes = null; $name = sanitize_file_name((string) ($req->get_param('name') ?: 'material'));
        if (!empty($files['file']['tmp_name']) && is_uploaded_file($files['file']['tmp_name'])) {
            $bytes = file_get_contents($files['file']['tmp_name']);
            $name = sanitize_file_name((string) ($files['file']['name'] ?? $name));
        } elseif (($d = (string) $req->get_param('data')) !== '') {
            if (preg_match('#^data:[a-z/+.-]+;base64,(.+)$#is', $d, $m)) $d = $m[1];
            $bytes = base64_decode(preg_replace('/\s+/', '', $d), true);
        }
        if (!$bytes) return self::bad('file', 'Bitte eine Datei wählen.');
        if (strlen($bytes) > self::MAX_BYTES) return self::bad('file', 'Die Datei ist zu groß (höchstens 25 MB).', 'tix_file', 413);
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $fi->buffer($bytes);
        if (!isset(self::MIMES[$mime])) return self::bad('file', 'Erlaubt sind Bilder (JPG, PNG, WebP, GIF), PDF und MP4.', 'tix_file', 415);
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $name) ?: 'material';
        $up = wp_upload_bits($base . '.' . self::MIMES[$mime], null, $bytes);
        if (!empty($up['error'])) return new WP_Error('tix_file', 'Die Datei konnte nicht gespeichert werden.', ['status' => 500]);
        $aid = wp_insert_attachment(['post_mime_type' => $mime, 'post_title' => mb_substr($base, 0, 120), 'post_status' => 'inherit', 'post_author' => get_current_user_id()], $up['file']);
        if (!$aid || is_wp_error($aid)) return new WP_Error('tix_file', 'Die Datei konnte nicht gespeichert werden.', ['status' => 500]);
        if (strpos($mime, 'image/') === 0) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $up['file']));
        }
        update_post_meta($aid, self::META_UPLOAD, $eid);
        $res = self::rest_org_get($req);
        $res->set_status(201);
        return $res;
    }

    public static function rest_org_delete(WP_REST_Request $req) {
        $eid = intval($req['id']); $aid = intval($req['aid']);
        if (get_post_type($aid) !== 'attachment' || intval(get_post_meta($aid, self::META_UPLOAD, true)) !== $eid) return new WP_Error('not_found', 'Datei nicht gefunden.', ['status' => 404]);
        wp_delete_attachment($aid, true);
        return self::rest_org_get($req);
    }
}

TIX_Promoter_Material::init();
