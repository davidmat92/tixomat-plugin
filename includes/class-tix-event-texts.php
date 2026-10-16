<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Event_Texts – Info-Abschnitte des Events und Textfelder für den Editor (1.38.455).
 *
 * Info-Abschnitte (Website, API, Metabox): description, lineup, artist (Künstler-Info, `_tix_info_artist`), specials,
 * extra_info, accessibility (Barrierefreiheit aus TIX_Event_Facts). Reihenfolge und Sichtbarkeit: `_tix_info_sections`
 * = Liste der Keys in Reihenfolge, ausgeblendete fehlen; leer = feste Standard-Reihenfolge (alle sichtbar).
 * Ältere Daten in `_tix_info_sections` (Liste von {heading, body}) gelten als „leer“.
 *
 * Editor (/events/{id}/edit, POST /events[/{id}]): info_labels {key: label}, faq [{q, a}], video {url, type},
 * charity {enabled, name, desc, percent, image_id, image}, box_office_desc, info_order [keys] | null.
 *   POST /events/{id}/charity-image (multipart `image`) → {image_id, image}
 * Shortcode [tix_event_info id=""]: alle sichtbaren Abschnitte in Reihenfolge (z. B. für Breakdance).
 */
class TIX_Event_Texts {

    const META_ORDER = '_tix_info_sections';
    const ORDERABLE = ['description', 'lineup', 'artist', 'specials', 'extra_info', 'accessibility'];
    const DEFAULT_LABELS = ['description' => 'Beschreibung', 'lineup' => 'Line-Up', 'artist' => 'Künstler-Info', 'specials' => 'Specials',
        'extra_info' => 'Weitere Informationen', 'accessibility' => 'Barrierefreiheit'];
    const FAQ_MAX = 30;

    public static function init() {
        add_shortcode('tix_event_info', [__CLASS__, 'shortcode']);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('add_meta_boxes', [__CLASS__, 'meta_box']);
        add_action('save_post_event', [__CLASS__, 'save_box'], 15, 2);
    }

    public static function register_routes() {
        register_rest_route('tixomat/v1', '/events/(?P<id>\d+)/charity-image', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_charity_image'], 'permission_callback' => ['TIX_REST_API', 'check_organizer']]);
    }

    private static function bad($field, $msg) {
        return new WP_Error('tix_invalid_field', $msg, ['status' => 400, 'field' => $field]);
    }

    // ═══════════════ Abschnitte ═══════════════

    /** Gespeicherte Reihenfolge (nur gültige, eindeutige Keys) oder null = Standard. */
    public static function stored_order($id) {
        $raw = get_post_meta($id, self::META_ORDER, true);
        if (!is_array($raw) || !$raw) return null;
        $out = [];
        foreach ($raw as $k) {
            if (!is_string($k) || !in_array($k, self::ORDERABLE, true)) return null; // alte Form {heading, body} → Standard
            if (!in_array($k, $out, true)) $out[] = $k;
        }
        return $out ?: null;
    }

    /** Sichtbare Abschnitte in Reihenfolge (Standard: alle). */
    public static function order($id) {
        return self::stored_order($id) ?? self::ORDERABLE;
    }

    public static function label($id, $key) {
        $l = (string) get_post_meta($id, '_tix_info_' . $key . '_label', true);
        if ($l !== '') return $l;
        if ($key !== 'accessibility' && class_exists('TIX_Metabox')) {
            $d = TIX_Metabox::info_sections();
            if (isset($d[$key]['label'])) return $d[$key]['label'];
        }
        return self::DEFAULT_LABELS[$key] ?? ucfirst($key);
    }

    /** HTML eines Abschnitts ('' = leer). */
    public static function html($id, $key) {
        if ($key === 'accessibility') {
            $a = class_exists('TIX_Event_Facts') ? TIX_Event_Facts::accessibility($id) : null;
            if (!$a) return '';
            $h = '';
            if ($a['items']) $h .= '<ul class="tix-accessibility">' . implode('', array_map(function ($i) { return '<li>' . esc_html($i['label']) . '</li>'; }, $a['items'])) . '</ul>';
            if ($a['note'] !== '') $h .= '<p>' . esc_html($a['note']) . '</p>';
            return $h;
        }
        $c = (string) get_post_meta($id, '_tix_info_' . $key, true);
        if (trim(wp_strip_all_tags($c)) === '') return '';
        if (strpos($c, '<p') === false) $c = wpautop($c);
        return wp_kses_post($c);
    }

    /** [{key, label, html}] – sichtbar, nicht leer, in Reihenfolge. */
    public static function sections($id) {
        $out = [];
        foreach (self::order($id) as $k) {
            $h = self::html($id, $k);
            if ($h !== '') $out[] = ['key' => $k, 'label' => self::label($id, $k), 'html' => $h];
        }
        return $out;
    }

    public static function is_visible($id, $key) {
        return in_array($key, self::order($id), true);
    }

    /** Alle Abschnitte als ein HTML (Shortcode, Breakdance-Feld `_tix_info_html`). */
    public static function render_html($id) {
        $h = '';
        foreach (self::sections($id) as $s) {
            $h .= '<div class="tix-info-block tix-info-' . esc_attr($s['key']) . '"><h3 class="tix-info-title">' . esc_html($s['label']) . '</h3><div class="tix-info-body">' . $s['html'] . '</div></div>';
        }
        return $h;
    }

    public static function shortcode($atts) {
        $atts = shortcode_atts(['id' => 0], $atts, 'tix_event_info');
        $id = intval($atts['id']) ?: get_the_ID();
        return $id && get_post_type($id) === 'event' ? self::render_html($id) : '';
    }

    // ═══════════════ Metabox: Reihenfolge & Sichtbarkeit ═══════════════

    public static function meta_box() {
        add_meta_box('tix_info_order', 'Info-Abschnitte: Reihenfolge & Sichtbarkeit', [__CLASS__, 'render_box'], 'event', 'side', 'default');
    }

    public static function render_box($post) {
        wp_nonce_field('tix_info_order', '_tix_info_order_nonce');
        $stored = self::stored_order($post->ID);
        $visible = $stored ?? self::ORDERABLE;
        $rows = array_merge($visible, array_values(array_diff(self::ORDERABLE, $visible)));
        echo '<p class="description">Reihenfolge auf der Event-Seite, in der App und im Studio. Ausgeblendete Abschnitte erscheinen nirgends. Leere Abschnitte bleiben ohnehin unsichtbar.</p><ul id="tix-info-order" style="margin:0;">';
        foreach ($rows as $k) {
            echo '<li style="display:flex;align-items:center;gap:6px;padding:4px 0;border-bottom:1px solid #eee;"><input type="hidden" name="tix_info_order[]" value="' . esc_attr($k) . '">'
                . '<label style="flex:1;"><input type="checkbox" name="tix_info_show[' . esc_attr($k) . ']" value="1"' . checked(in_array($k, $visible, true), true, false) . '> ' . esc_html(self::label($post->ID, $k)) . '</label>'
                . '<button type="button" class="button-link tix-io-up" aria-label="nach oben">▲</button><button type="button" class="button-link tix-io-down" aria-label="nach unten">▼</button></li>';
        }
        echo '</ul><p><label>Überschrift „Barrierefreiheit“<br><input type="text" name="tix_info_acc_label" class="widefat" maxlength="60" value="' . esc_attr((string) get_post_meta($post->ID, '_tix_info_accessibility_label', true)) . '" placeholder="Barrierefreiheit"></label></p>';
        echo '<p><label><input type="checkbox" name="tix_info_order_reset" value="1"> Standard-Reihenfolge</label></p>';
        echo "<script>document.getElementById('tix-info-order').addEventListener('click',function(e){var b=e.target;if(!b.classList.contains('tix-io-up')&&!b.classList.contains('tix-io-down'))return;var li=b.closest('li');if(b.classList.contains('tix-io-up')&&li.previousElementSibling)li.parentNode.insertBefore(li,li.previousElementSibling);if(b.classList.contains('tix-io-down')&&li.nextElementSibling)li.parentNode.insertBefore(li.nextElementSibling,li);});</script>";
    }

    public static function save_box($post_id, $post) {
        if (!isset($_POST['_tix_info_order_nonce']) || !wp_verify_nonce($_POST['_tix_info_order_nonce'], 'tix_info_order')) return;
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) return;
        $al = mb_substr(trim(sanitize_text_field(wp_unslash((string) ($_POST['tix_info_acc_label'] ?? '')))), 0, 60);
        if ($al === '' || $al === self::DEFAULT_LABELS['accessibility']) delete_post_meta($post_id, '_tix_info_accessibility_label'); else update_post_meta($post_id, '_tix_info_accessibility_label', $al);
        if (!empty($_POST['tix_info_order_reset'])) { delete_post_meta($post_id, self::META_ORDER); return; }
        $order = array_values(array_intersect(array_map('sanitize_key', (array) wp_unslash($_POST['tix_info_order'] ?? [])), self::ORDERABLE));
        $show = (array) ($_POST['tix_info_show'] ?? []);
        self::set_order($post_id, array_values(array_filter($order, function ($k) use ($show) { return !empty($show[$k]); })));
    }

    /** Reihenfolge speichern; Standard (alle in fester Reihenfolge) bzw. leer → Meta löschen. */
    public static function set_order($post_id, $keys) {
        if (!is_array($keys) || !$keys || $keys === self::ORDERABLE) { delete_post_meta($post_id, self::META_ORDER); return; }
        update_post_meta($post_id, self::META_ORDER, array_values($keys));
    }

    // ═══════════════ Editor (X1–X5) ═══════════════

    private static function label_keys() {
        $k = class_exists('TIX_Metabox') ? array_keys(TIX_Metabox::info_sections()) : [];
        $k[] = 'accessibility';
        return array_values(array_unique($k));
    }

    public static function video($id) {
        $url = (string) get_post_meta($id, '_tix_video_url', true);
        if ($url === '') return null;
        return ['url' => $url, 'type' => (string) (get_post_meta($id, '_tix_video_type', true) ?: self::video_type($url))];
    }

    private static function video_type($url) {
        if (preg_match('~youtube\.com|youtu\.be~i', $url)) return 'youtube';
        if (preg_match('~vimeo\.com~i', $url)) return 'vimeo';
        return 'external';
    }

    public static function charity($id) {
        $img = intval(get_post_meta($id, '_tix_charity_image', true));
        return [
            'enabled' => get_post_meta($id, '_tix_charity_enabled', true) === '1',
            'name' => (string) get_post_meta($id, '_tix_charity_name', true),
            'desc' => (string) get_post_meta($id, '_tix_charity_desc', true),
            'percent' => intval(get_post_meta($id, '_tix_charity_percent', true)),
            'image_id' => $img ?: null,
            'image' => $img ? ((string) wp_get_attachment_image_url($img, 'medium') ?: null) : null,
            // Spendenaktionen sind seitenweit eingeschaltet (Einstellung charity_enabled)? Sonst zeigt die Website nichts
            'site_enabled' => function_exists('tix_get_settings') && !empty(tix_get_settings('charity_enabled')),
        ];
    }

    public static function editor_payload($id) {
        $labels = [];
        foreach (self::label_keys() as $k) $labels[$k] = $id ? (string) get_post_meta($id, '_tix_info_' . $k . '_label', true) : '';
        $faq = [];
        foreach ((array) ($id ? get_post_meta($id, '_tix_faq', true) : []) as $f) {
            if (!is_array($f) || trim((string) ($f['q'] ?? '')) === '') continue;
            $faq[] = ['q' => (string) $f['q'], 'a' => (string) ($f['a'] ?? '')];
        }
        $stored = $id ? self::stored_order($id) : null;
        return [
            'info_labels' => $labels,
            'faq' => $faq,
            'video' => $id ? self::video($id) : null,
            'charity' => $id ? self::charity($id) : self::charity(0),
            'box_office_desc' => $id ? (string) get_post_meta($id, '_tix_box_office_desc', true) : '',
            'info_order' => [
                'sections' => $stored ?? self::ORDERABLE,
                'custom' => $stored !== null,
                'default' => self::ORDERABLE,
                'available' => array_map(function ($k) use ($id) { return ['key' => $k, 'label' => $id ? self::label($id, $k) : self::DEFAULT_LABELS[$k]]; }, self::ORDERABLE),
            ],
        ];
    }

    /** Prüfen vor dem Speichern: bereinigte Werte je mitgeschicktem Schlüssel oder WP_Error. */
    public static function check(array $b, $post_id = 0) {
        $out = [];
        if (array_key_exists('info_labels', $b)) {
            if (!is_array($b['info_labels'])) return self::bad('info_labels', 'Ungültige Überschriften.');
            $l = [];
            foreach ($b['info_labels'] as $k => $v) {
                $k = (string) $k;
                if (!in_array($k, self::label_keys(), true)) return self::bad('info_labels.' . $k, 'Unbekannter Abschnitt.');
                $v = trim(sanitize_text_field((string) $v));
                if (mb_strlen($v) > 60) return self::bad('info_labels.' . $k, 'Höchstens 60 Zeichen.');
                $l[$k] = $v;
            }
            $out['info_labels'] = $l;
        }
        if (array_key_exists('faq', $b)) {
            if ($b['faq'] !== null && !is_array($b['faq'])) return self::bad('faq', 'Ungültige FAQ.');
            $f = [];
            foreach ((array) $b['faq'] as $i => $row) {
                if (!is_array($row)) return self::bad('faq[' . $i . ']', 'Ungültiger Eintrag.');
                $q = trim(sanitize_text_field((string) ($row['q'] ?? '')));
                $a = trim(wp_kses_post((string) ($row['a'] ?? '')));
                if ($q === '') continue; // leere Fragen fallen weg
                if (mb_strlen($q) > 300) return self::bad('faq[' . $i . '].q', 'Höchstens 300 Zeichen.');
                $f[] = ['q' => $q, 'a' => $a];
            }
            if (count($f) > self::FAQ_MAX) return self::bad('faq', 'Höchstens ' . self::FAQ_MAX . ' Fragen.');
            $out['faq'] = $f;
        }
        if (array_key_exists('video', $b)) {
            $v = $b['video'];
            $url = is_array($v) ? trim((string) ($v['url'] ?? '')) : ($v === null ? '' : trim((string) $v));
            if ($url !== '' && !preg_match('~^https?://~i', $url)) return self::bad('video.url', 'Bitte einen Link mit https:// angeben.');
            $out['video'] = $url === '' ? null : esc_url_raw($url);
            if ($url !== '' && $out['video'] === '') return self::bad('video.url', 'Ungültiger Link.');
        }
        if (array_key_exists('charity', $b)) {
            $c = $b['charity'];
            if (!is_array($c)) return self::bad('charity', 'Ungültige Spendenaktion.');
            $o = [];
            if (array_key_exists('enabled', $c)) $o['enabled'] = filter_var($c['enabled'], FILTER_VALIDATE_BOOLEAN);
            if (array_key_exists('name', $c)) { $o['name'] = mb_substr(trim(sanitize_text_field((string) $c['name'])), 0, 120); }
            if (array_key_exists('desc', $c)) { $o['desc'] = mb_substr(trim(sanitize_textarea_field((string) $c['desc'])), 0, 1000); }
            if (array_key_exists('percent', $c)) {
                if (!is_numeric($c['percent']) || intval($c['percent']) < 0 || intval($c['percent']) > 100) return self::bad('charity.percent', 'Anteil zwischen 0 und 100 %.');
                $o['percent'] = intval($c['percent']);
            }
            if (array_key_exists('image_id', $c)) {
                $img = intval($c['image_id']);
                if ($img && (get_post_type($img) !== 'attachment' || !wp_attachment_is_image($img))) return self::bad('charity.image_id', 'Bild nicht gefunden.');
                $o['image_id'] = $img;
            }
            $after = array_merge($post_id ? self::charity($post_id) : self::charity(0), $o);
            if ($after['enabled'] && trim((string) $after['name']) === '') return self::bad('charity.name', 'Bitte angeben, wofür gespendet wird.');
            if ($after['enabled'] && intval($after['percent']) <= 0) return self::bad('charity.percent', 'Bitte einen Anteil über 0 % angeben.');
            $out['charity'] = $o;
        }
        if (array_key_exists('box_office_desc', $b)) {
            $d = trim(sanitize_text_field((string) $b['box_office_desc']));
            if (mb_strlen($d) > 300) return self::bad('box_office_desc', 'Höchstens 300 Zeichen.');
            $out['box_office_desc'] = $d;
        }
        if (array_key_exists('info_order', $b)) {
            $o = $b['info_order'];
            if (is_array($o) && isset($o['sections'])) $o = $o['sections'];
            if ($o !== null && !is_array($o)) return self::bad('info_order', 'Bitte eine Liste der Abschnitte angeben.');
            $keys = [];
            foreach ((array) $o as $i => $k) {
                if (!is_string($k) || !in_array($k, self::ORDERABLE, true)) return self::bad('info_order[' . $i . ']', 'Unbekannter Abschnitt.');
                if (!in_array($k, $keys, true)) $keys[] = $k;
            }
            $out['info_order'] = $keys;
        }
        return $out;
    }

    public static function apply($post_id, array $c) {
        if (isset($c['info_labels'])) {
            foreach ($c['info_labels'] as $k => $v) {
                $def = $k === 'accessibility' ? self::DEFAULT_LABELS['accessibility'] : (TIX_Metabox::info_sections()[$k]['label'] ?? '');
                if ($v === '' || $v === $def) delete_post_meta($post_id, '_tix_info_' . $k . '_label'); else update_post_meta($post_id, '_tix_info_' . $k . '_label', $v);
            }
        }
        if (array_key_exists('faq', $c)) update_post_meta($post_id, '_tix_faq', $c['faq']);
        if (array_key_exists('video', $c)) {
            if ($c['video'] === null) {
                update_post_meta($post_id, '_tix_video_url', '');
                update_post_meta($post_id, '_tix_video_id', 0);
                delete_post_meta($post_id, '_tix_video_embed');
                delete_post_meta($post_id, '_tix_video_type');
            } else {
                $url = $c['video'];
                $type = self::video_type($url);
                update_post_meta($post_id, '_tix_video_url', $url);
                update_post_meta($post_id, '_tix_video_id', 0);
                update_post_meta($post_id, '_tix_video_type', $type);
                // Einbettung für Breakdance ohne Abruf bei YouTube/Vimeo (YouTube ohne Cookies)
                $embed = '';
                if ($type === 'youtube' && preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/|live/))([A-Za-z0-9_-]{6,})~', $url, $m)) $embed = 'https://www.youtube-nocookie.com/embed/' . $m[1];
                elseif ($type === 'vimeo' && preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) $embed = 'https://player.vimeo.com/video/' . $m[1];
                if ($embed !== '') update_post_meta($post_id, '_tix_video_embed', '<iframe src="' . esc_url($embed) . '" width="560" height="315" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen loading="lazy"></iframe>');
                else delete_post_meta($post_id, '_tix_video_embed');
            }
        }
        if (isset($c['charity'])) {
            $o = $c['charity'];
            if (array_key_exists('enabled', $o)) update_post_meta($post_id, '_tix_charity_enabled', $o['enabled'] ? '1' : '0');
            if (array_key_exists('name', $o)) update_post_meta($post_id, '_tix_charity_name', $o['name']);
            if (array_key_exists('desc', $o)) update_post_meta($post_id, '_tix_charity_desc', $o['desc']);
            if (array_key_exists('percent', $o)) update_post_meta($post_id, '_tix_charity_percent', $o['percent']);
            if (array_key_exists('image_id', $o)) update_post_meta($post_id, '_tix_charity_image', $o['image_id']);
        }
        if (array_key_exists('box_office_desc', $c)) update_post_meta($post_id, '_tix_box_office_desc', $c['box_office_desc']);
        if (array_key_exists('info_order', $c)) self::set_order($post_id, $c['info_order']);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
    }

    /** POST /events/{id}/charity-image (multipart `image`) → {image_id, image} */
    public static function rest_charity_image(WP_REST_Request $req) {
        $id = absint($req['id']);
        if (get_post_type($id) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::user_can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        $files = $req->get_file_params();
        $file = $files['image'] ?? ($files['file'] ?? null);
        if (intval($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$files && !$req->get_body_params()) return new WP_Error('too_large', 'Das Bild ist zu groß (höchstens 5 MB).', ['status' => 413, 'field' => 'image']);
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
        $aid = media_handle_sideload(['name' => 'spende-' . $id . '-' . time() . '.' . $ext, 'tmp_name' => $file['tmp_name'], 'type' => $mime, 'size' => $file['size'] ?? 0], $id, 'Spendenaktion');
        if (is_wp_error($aid)) return new WP_Error('upload_failed', $aid->get_error_message(), ['status' => 500]);
        update_post_meta($id, '_tix_charity_image', intval($aid));
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return rest_ensure_response(['ok' => true, 'image_id' => intval($aid), 'image' => (string) (wp_get_attachment_image_url($aid, 'medium') ?: '')]);
    }
}
