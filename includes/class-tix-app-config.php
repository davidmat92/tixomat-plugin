<?php
if (!defined('ABSPATH')) exit;

/**
 * App-Inhalte ohne WordPress bearbeiten.
 *
 * Die App liest ihre Konfiguration (Kacheln, Seiten-Texte wie FAQ/Ü16/Kontakt,
 * Hashtag, Kontaktdaten) zuerst von `GET /app/config` und legt sie über ihre
 * gebündelten Defaults (`AppContent.mergeJson`). Diese Klasse speichert genau
 * diese JSON-Overrides in der Option `_tix_app_config` und lässt sie über
 * `POST /app/config` (Veranstalter-Auth) im App-Editor bearbeiten.
 *
 * Ist nichts gespeichert, liefert GET `{}` – dann greift wie bisher die
 * WordPress-Seite `app-config` bzw. es bleibt bei den Defaults.
 */
class TIX_App_Config {
    const NS = 'tixomat/v1';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function stored() {
        $c = get_option('_tix_app_config', []);
        return is_array($c) ? $c : [];
    }

    public static function rest_get(WP_REST_Request $req) {
        $c = self::stored();
        if (isset($c['nav']) && is_array($c['nav'])) unset($c['nav']['sos']);
        // SOS-Menüpunkt (TIX_App_Sos, 1.38.434): nur wenn eingeschaltet, sonst Antwort unverändert
        $sos = class_exists('TIX_App_Sos') ? TIX_App_Sos::nav_block() : null;
        if (empty($c)) {
            // Leer → App fällt auf die WordPress-Seite / Defaults zurück (mit SOS: nur `nav`, ohne `ok`)
            return rest_ensure_response($sos ? ['nav' => ['sos' => $sos]] : new stdClass());
        }
        if ($sos) {
            $c['nav'] = (isset($c['nav']) && is_array($c['nav']) ? $c['nav'] : []);
            $c['nav']['sos'] = $sos;
        }
        $c['ok'] = true;
        return rest_ensure_response($c);
    }

    /** Editor: aktuelle Overrides laden (auch wenn leer). */
    public static function rest_admin_get(WP_REST_Request $req) {
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && TIX_App_Scope::scoped()) {
            return TIX_App_Scope::deny('Die App-Inhalte pflegt der Plattform-Betreiber.');
        }
        return rest_ensure_response(['config' => (object) self::stored(), 'defaults' => self::defaults()] + (class_exists('TIX_App_Sos') ? ['nav_sos' => TIX_App_Sos::settings()] : []));
    }

    /**
     * Standard-Inhalte, wenn nichts gespeichert ist (1.38.455): aus der WordPress-Seite `app-config` (JSON im Inhalt, wie die App sie liest).
     * {source: page|none, tiles: [...], faq: [{q, a}], pages: {slug: {...}}}. Die in der App gebündelten Standards kennt nur die App.
     */
    public static function defaults() {
        $out = ['source' => 'none', 'tiles' => [], 'faq' => [], 'pages' => new stdClass()];
        $p = get_page_by_path('app-config');
        if (!$p || $p->post_status !== 'publish') return $out;
        $raw = html_entity_decode(wp_strip_all_tags((string) $p->post_content), ENT_QUOTES, 'UTF-8');
        $raw = strtr($raw, ["\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{2033}" => '"']);
        $a = strpos($raw, '{'); $b = strrpos($raw, '}');
        $j = ($a !== false && $b > $a) ? json_decode(substr($raw, $a, $b - $a + 1), true) : null;
        if (!is_array($j)) return $out;
        $home = isset($j['home']) && is_array($j['home']) ? $j['home'] : [];
        $tiles = $home['tiles'] ?? ($j['tiles'] ?? []);
        $pages = isset($j['pages']) && is_array($j['pages']) ? $j['pages'] : [];
        $faq = [];
        foreach ((array) ($pages['faq']['faq'] ?? ($j['faq'] ?? [])) as $f) {
            if (!is_array($f)) continue;
            $q = (string) ($f['q'] ?? ($f['question'] ?? ($f['title'] ?? '')));
            if ($q !== '') $faq[] = ['q' => $q, 'a' => (string) ($f['a'] ?? ($f['answer'] ?? ($f['html'] ?? ($f['content'] ?? ''))))];
        }
        return ['source' => 'page', 'tiles' => array_values(array_filter((array) $tiles, 'is_array')), 'faq' => $faq, 'pages' => $pages ? $pages : new stdClass()];
    }

    public static function rest_save(WP_REST_Request $req) {
        // Mehr-Veranstalter-Modus: App-Inhalte gelten für die ganze Plattform → nur Admins
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && TIX_App_Scope::scoped()) {
            return TIX_App_Scope::deny('Die App-Inhalte pflegt der Plattform-Betreiber. Deine Veranstalter-Seite bearbeitest du unter „Veranstalter-Seite“.');
        }
        $b = $req->get_json_params();
        if (!is_array($b)) {
            return new WP_Error('bad_data', 'Ungültige Daten.', ['status' => 400]);
        }
        unset($b['ok']);
        // „Jetzt los“-Bereich der Startseite (1.38.486, Vertrag KK-App): home.now = {show, style section|badge, hours 1–12}
        if (isset($b['home']['now'])) {
            $now = $b['home']['now'];
            if (!is_array($now)) return new WP_Error('bad_data', 'home.now: Objekt erwartet.', ['status' => 400, 'field' => 'home.now']);
            $style = (string) ($now['style'] ?? 'section');
            if (!in_array($style, ['section', 'badge'], true)) return new WP_Error('bad_data', 'home.now.style: section oder badge.', ['status' => 400, 'field' => 'home.now.style']);
            $hours = $now['hours'] ?? 3;
            if (!is_numeric($hours) || intval($hours) != $hours || intval($hours) < 1 || intval($hours) > 12) return new WP_Error('bad_data', 'home.now.hours: ganze Zahl von 1 bis 12.', ['status' => 400, 'field' => 'home.now.hours']);
            $b['home']['now'] = ['show' => filter_var($now['show'] ?? false, FILTER_VALIDATE_BOOLEAN), 'style' => $style, 'hours' => intval($hours)];
        }
        // SOS-Menüpunkt liegt in eigener Option (bleibt unverändert, wenn `nav.sos` fehlt)
        if (isset($b['nav']['sos']) && is_array($b['nav']['sos']) && class_exists('TIX_App_Sos')) {
            $sos = $b['nav']['sos'];
            unset($sos['icon'], $sos['live']);
            TIX_App_Sos::save($sos);
        }
        if (isset($b['nav']) && is_array($b['nav'])) {
            unset($b['nav']['sos']);
            if (!$b['nav']) unset($b['nav']);
        }
        update_option('_tix_app_config', $b, false);
        return rest_ensure_response(['ok' => true]);
    }

    public static function register_routes() {
        $organizer = ['TIX_REST_API', 'check_organizer'];
        register_rest_route(self::NS, '/app/config', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/app/config', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'],
            'permission_callback' => $organizer,
        ]);
        register_rest_route(self::NS, '/app/config/edit', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_admin_get'],
            'permission_callback' => $organizer,
        ]);
        // Bild für App-Inhalte hochladen (1.38.465, z. B. Banner): multipart `file`, `kind` = banner | image → {ok, url, id, kind, width, height}
        register_rest_route(self::NS, '/app/config/image', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_image'],
            'permission_callback' => $organizer,
        ]);
    }

    public static function rest_image(WP_REST_Request $req) {
        $kind = sanitize_key((string) ($req->get_param('kind') ?: 'image'));
        if (!in_array($kind, ['banner', 'image'], true)) return new WP_Error('tix_invalid_field', 'kind: banner oder image.', ['status' => 400, 'field' => 'kind']);
        $files = $req->get_file_params();
        $f = $files['file'] ?? ($files['image'] ?? null);
        if (intval($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$files && !$req->get_body_params()) return new WP_Error('tix_too_large', 'Das Bild ist zu groß (höchstens 5 MB).', ['status' => 413, 'field' => 'file']);
        if (!empty($f['error']) && intval($f['error']) !== UPLOAD_ERR_NO_FILE) return new WP_Error('upload_failed', 'Upload fehlgeschlagen (höchstens 5 MB).', ['status' => in_array(intval($f['error']), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400, 'field' => 'file']);
        if (empty($f['tmp_name'])) return new WP_Error('no_file', 'Kein Bild übermittelt.', ['status' => 400, 'field' => 'file']);
        if (intval($f['size'] ?? 0) > 5 * MB_IN_BYTES) return new WP_Error('tix_too_large', 'Das Bild ist zu groß (höchstens 5 MB).', ['status' => 413, 'field' => 'file']);
        $info = @getimagesize($f['tmp_name']);
        $mime = is_array($info) ? (string) $info['mime'] : '';
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? '';
        if ($ext === '') return new WP_Error('bad_type', 'Bitte ein JPG-, PNG-, WebP- oder GIF-Bild wählen.', ['status' => 400, 'field' => 'file']);
        if ($info[0] > 8000 || $info[1] > 8000) return new WP_Error('bad_size', 'Das Bild darf höchstens 8000 Pixel groß sein.', ['status' => 400, 'field' => 'file']);
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $aid = media_handle_sideload(['name' => 'app-' . $kind . '-' . time() . '.' . $ext, 'tmp_name' => $f['tmp_name'], 'type' => $mime, 'size' => $f['size'] ?? 0], 0, 'App ' . ($kind === 'banner' ? 'Banner' : 'Bild'));
        if (is_wp_error($aid)) return new WP_Error('upload_failed', $aid->get_error_message(), ['status' => 500]);
        update_post_meta($aid, '_tix_app_image_kind', $kind);
        $url = (string) (wp_get_attachment_image_url($aid, $kind === 'banner' ? 'large' : 'large') ?: wp_get_attachment_url($aid));
        return rest_ensure_response(['ok' => true, 'url' => $url, 'id' => intval($aid), 'kind' => $kind, 'width' => intval($info[0]), 'height' => intval($info[1])]);
    }
}
