<?php
/**
 * Ticket-Design im Studio (P4 Expertenmodus, B1, 1.38.800, Vertrag `uebergaben/vertrag-ticket-design.md`).
 *
 * Bisher nur im WP-Admin (Einstellungen → Ticket-Template/HTML-Ticket, Ticket-Vorlagen, Event-Metabox „Vorlage“). Jetzt per REST:
 *  - Seite/Veranstalter: PDF-Vorlage (Bild + platzierte Felder), Online-Ticket (Version, Farben, Schalter), Wallet-Farben
 *  - Vorlagen (tix_ticket_tpl): Liste, anlegen, ändern, löschen
 *  - je Event: Modus global | template | custom | none
 *  - Vorschau als Bild bzw. PDF (Muster- oder Event-Daten), Wallet-Vorschau als Angaben
 * Ablage wie bisher (tix_settings, Postmeta der Vorlagen/Events); evendis: PDF-Vorlage je Veranstalter in TIX_Object_Data.
 */
if (!defined('ABSPATH')) exit;

class TIX_Ticket_Design {

    const NS = 'tixomat/v1';
    const OBJ = 'tix_org_ticket_design';
    const MODES = ['global', 'template', 'custom', 'none'];
    /** Online-Ticket (HTML): Feld → [Typ, Werte] (Schlüssel in tix_settings) */
    const HTML = [
        'ht_version' => ['enum', ['v1', 'v2', 'v3', 'v4', 'v5', 'v6']],
        'ht_header_bg' => ['color'], 'ht_header_text' => ['color'], 'ht_body_bg' => ['color'], 'ht_text_color' => ['color'], 'ht_label_color' => ['color'],
        'ht_border_color' => ['color'], 'ht_btn_bg' => ['color'], 'ht_btn_text' => ['color'], 'ht_border_radius' => ['int', [0, 30]],
        'ht_logo_url' => ['url'], 'ht_footer_text' => ['text'],
        'ht_show_event_cover' => ['bool'], 'ht_show_countdown' => ['bool'], 'ht_show_verified_badge' => ['bool'], 'ht_show_agb_footer' => ['bool'],
        'ht_watermark_enabled' => ['bool'], 'ht_weather_enabled' => ['bool'], 'ht_action_save_image' => ['bool'], 'ht_action_wallets' => ['bool'],
        'ht_action_print' => ['bool'], 'ht_qr_bright_mode' => ['bool'],
    ];
    const WALLET = ['wallet_apple_bg_color' => '#0f172a', 'wallet_apple_fg_color' => '#ffffff', 'wallet_apple_label_color' => '#cbd5e1', 'wallet_google_bg_color' => '#0f172a'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    // ═══════════════ Reine Prüfung ═══════════════

    public static function clean_html_value(array $def, $v) {
        switch ($def[0]) {
            case 'bool': return [true, (bool) $v];
            case 'int': return is_numeric($v) && intval($v) >= $def[1][0] && intval($v) <= $def[1][1] ? [true, intval($v)] : [false, null];
            case 'enum': return in_array((string) $v, $def[1], true) ? [true, (string) $v] : [false, null];
            case 'color': return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string) $v) ? [true, strtolower((string) $v)] : [false, null];
            case 'url': $u = trim((string) $v); return $u === '' || preg_match('#^https?://#i', $u) ? [true, esc_url_raw($u)] : [false, null];
            case 'text': return mb_strlen((string) $v) <= 300 ? [true, sanitize_text_field((string) $v)] : [false, null];
        }
        return [false, null];
    }

    // ═══════════════ Lesen ═══════════════

    private static function settings() {
        return wp_parse_args((array) get_option('tix_settings', []), function_exists('tix_get_settings') ? (array) tix_get_settings() : []);
    }

    /** PDF-Vorlage der Seite bzw. des Veranstalters (roh, auch ohne Bild) */
    public static function pdf_config($org = 0) {
        if (self::multi() && $org > 0 && class_exists('TIX_Object_Data')) {
            $c = TIX_Object_Data::read(self::OBJ, $org, null);
            if (is_array($c) && isset($c['pdf'])) return TIX_Ticket_Template::sanitize_config($c['pdf']);
        }
        $j = self::settings()['ticket_template'] ?? '';
        $c = is_string($j) ? json_decode($j, true) : $j;
        return TIX_Ticket_Template::sanitize_config(is_array($c) ? $c : []);
    }

    /** Für TIX_Ticket_Template::get_effective_config: PDF-Vorlage des Veranstalters eines Events (evendis) oder null */
    public static function org_config_for_event($event_id) {
        if (!self::multi() || !class_exists('TIX_Object_Data')) return null;
        $org = intval(get_post_meta(intval($event_id), '_tix_organizer_id', true));
        if (!$org) return null;
        $c = TIX_Object_Data::read(self::OBJ, $org, null);
        if (!is_array($c) || !isset($c['pdf'])) return null;
        $cfg = TIX_Ticket_Template::sanitize_config($c['pdf']);
        return $cfg['template_image_id'] ? $cfg : null;
    }

    private static function img($id) {
        $id = intval($id);
        if (!$id) return null;
        $u = wp_get_attachment_url($id);
        return $u ? ['id' => $id, 'url' => (string) $u] : null;
    }

    private static function pdf_out(array $cfg) {
        return $cfg + ['template_image' => self::img($cfg['template_image_id']), 'logo_image' => self::img($cfg['fields']['logo']['image_id'] ?? 0)];
    }

    public static function html_values() {
        $s = self::settings(); $o = [];
        foreach (self::HTML as $k => $def) { [$ok, $v] = self::clean_html_value($def, $s[$k] ?? null); $o[$k] = $ok ? $v : null; }
        return $o;
    }

    public static function wallet_values() {
        $s = self::settings(); $o = [];
        foreach (self::WALLET as $k => $d) $o[$k] = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($s[$k] ?? '')) ? strtolower($s[$k]) : $d;
        return $o + ['apple_ready' => class_exists('TIX_Wallet') && method_exists('TIX_Wallet', 'is_apple_ready') && TIX_Wallet::is_apple_ready(),
            'enabled' => class_exists('TIX_Wallet') && TIX_Wallet::is_master_enabled()];
    }

    // ═══════════════ Routen ═══════════════

    public static function routes() {
        $read = ['TIX_REST_API', 'check_organizer'];
        $write = [__CLASS__, 'perm_write'];
        register_rest_route(self::NS, '/studio/ticket-design', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $read],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $write],
        ]);
        register_rest_route(self::NS, '/studio/ticket-design/image', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_image'], 'permission_callback' => [__CLASS__, 'perm_image']]);
        register_rest_route(self::NS, '/studio/ticket-design/preview', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_preview'], 'permission_callback' => $read]);
        register_rest_route(self::NS, '/studio/ticket-templates', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_tpl_list'], 'permission_callback' => $read],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_tpl_save'], 'permission_callback' => $write],
        ]);
        register_rest_route(self::NS, '/studio/ticket-templates/(?P<id>\d+)', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_tpl_get'], 'permission_callback' => $read],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_tpl_save'], 'permission_callback' => $write],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_tpl_delete'], 'permission_callback' => $write],
        ]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/ticket-design', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_event_get'], 'permission_callback' => [__CLASS__, 'perm_event']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_event_save'], 'permission_callback' => [__CLASS__, 'perm_event']],
        ]);
    }

    public static function perm_write(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        $u = wp_get_current_user();
        if ($u->has_cap('manage_options')) return true;
        if (!self::multi() && in_array('tix_organizer', (array) $u->roles, true)) return true;
        if (self::multi() && class_exists('TIX_App_Scope') && TIX_App_Scope::is_org_manager() && TIX_App_Scope::organizer_id_for_user()) return true;
        return new WP_Error('rest_forbidden', 'Das Ticket-Design ändern dürfen nur Inhaber bzw. Admins.', ['status' => 403]);
    }

    public static function perm_event(WP_REST_Request $req) {
        $c = TIX_REST_API::check_organizer($req);
        if ($c !== true) return $c;
        $id = absint($req['id']);
        if (get_post_type($id) !== 'event') return new WP_Error('tix_not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::user_can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        return true;
    }

    public static function perm_image(WP_REST_Request $req) {
        $eid = absint($req->get_param('event_id'));
        if ($eid) { $req->set_url_params(['id' => $eid]); return self::perm_event($req); }
        return self::perm_write($req);
    }

    private static function org_of(WP_REST_Request $req) {
        if (!self::multi()) return 0;
        if (current_user_can('manage_options')) return max(0, intval($req->get_param('organizer_id')));
        return class_exists('TIX_App_Scope') ? intval(TIX_App_Scope::organizer_id_for_user()) : 0;
    }

    private static function fields_meta() {
        $o = [];
        foreach (TIX_Ticket_Template::field_definitions() as $k => $d) $o[] = ['key' => $k, 'label' => $d['label'], 'type' => $d['type']];
        return $o;
    }

    private static function payload($org) {
        $platform = !self::multi() || !$org; // Online-Ticket und Wallet gelten für die ganze Seite
        return ['ok' => true, 'scope' => self::multi() && $org ? 'organizer' : 'site', 'organizer_id' => $org ?: null,
            'pdf' => self::pdf_out(self::pdf_config($org)),
            'html' => self::html_values(), 'html_editable' => $platform && current_user_can('manage_options') || (!self::multi()),
            'wallet' => self::wallet_values(), 'wallet_editable' => !self::multi() || current_user_can('manage_options'),
            'fields' => self::fields_meta(), 'gd' => function_exists('imagecreatetruecolor') && function_exists('imagettftext')];
    }

    public static function rest_get(WP_REST_Request $req) {
        $r = rest_ensure_response(self::payload(self::org_of($req)));
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    private static function image_ok($id) {
        $id = intval($id);
        if (!$id) return true;
        if (get_post_type($id) !== 'attachment' || strpos((string) get_post_mime_type($id), 'image/') !== 0) return false;
        return !self::multi() || current_user_can('manage_options') || intval(get_post_field('post_author', $id)) === get_current_user_id() || get_post_meta($id, '_tix_ticket_design_org', true) == (class_exists('TIX_App_Scope') ? TIX_App_Scope::organizer_id_for_user() : 0);
    }

    /** PDF-Konfiguration prüfen → sanitize_config | WP_Error */
    private static function clean_pdf($in) {
        if (!is_array($in)) return new WP_Error('tix_invalid_field', 'pdf als Objekt angeben.', ['status' => 400, 'field' => 'pdf']);
        if (isset($in['fields']) && is_array($in['fields'])) foreach (array_keys($in['fields']) as $k) {
            if (!isset(TIX_Ticket_Template::field_definitions()[$k])) return new WP_Error('tix_invalid_field', 'Unbekanntes Feld „' . $k . '“.', ['status' => 400, 'field' => 'pdf.fields.' . $k]);
        }
        $cfg = TIX_Ticket_Template::sanitize_config($in);
        if (!self::image_ok($cfg['template_image_id'])) return new WP_Error('tix_invalid_field', 'Dieses Bild ist nicht verfügbar.', ['status' => 400, 'field' => 'pdf.template_image_id']);
        if (!self::image_ok($cfg['fields']['logo']['image_id'] ?? 0)) return new WP_Error('tix_invalid_field', 'Dieses Logo ist nicht verfügbar.', ['status' => 400, 'field' => 'pdf.fields.logo.image_id']);
        return $cfg;
    }

    public static function rest_save(WP_REST_Request $req) {
        $org = self::org_of($req);
        $b = (array) $req->get_json_params();
        if (array_key_exists('pdf', $b)) {
            $cfg = self::clean_pdf($b['pdf']);
            if (is_wp_error($cfg)) return $cfg;
            if (self::multi() && $org) {
                $cur = (array) (TIX_Object_Data::read(self::OBJ, $org, []) ?: []);
                $cur['pdf'] = $cfg;
                TIX_Object_Data::write(self::OBJ, $org, $cur);
            } else {
                $s = (array) get_option('tix_settings', []);
                $s['ticket_template'] = wp_json_encode($cfg);
                update_option('tix_settings', $s);
            }
        }
        $site = !self::multi() || (current_user_can('manage_options') && !$org);
        foreach (['html' => self::HTML, 'wallet' => self::WALLET] as $area => $defs) {
            if (!array_key_exists($area, $b)) continue;
            if (!$site) return new WP_Error('rest_forbidden', 'Online-Ticket und Wallet gelten für die ganze Plattform – nur Admins.', ['status' => 403, 'field' => $area]);
            if (!is_array($b[$area])) return new WP_Error('tix_invalid_field', $area . ' als Objekt angeben.', ['status' => 400, 'field' => $area]);
            $s = (array) get_option('tix_settings', []);
            foreach ($b[$area] as $k => $v) {
                if (!isset($defs[$k])) return new WP_Error('tix_invalid_field', 'Unbekanntes Feld „' . $k . '“.', ['status' => 400, 'field' => $area . '.' . $k]);
                [$ok, $cv] = $area === 'html' ? self::clean_html_value($defs[$k], $v) : self::clean_html_value(['color'], $v);
                if (!$ok) return new WP_Error('tix_invalid_field', 'Ungültiger Wert für „' . $k . '“.', ['status' => 400, 'field' => $area . '.' . $k]);
                $s[$k] = is_bool($cv) ? ($cv ? 1 : 0) : $cv;
            }
            update_option('tix_settings', $s);
        }
        return self::rest_get($req);
    }

    /** POST /studio/ticket-design/image (multipart image, slot=template|logo, event_id?) → {image_id, url, width, height} */
    public static function rest_image(WP_REST_Request $req) {
        $files = $req->get_file_params();
        $file = $files['image'] ?? ($files['file'] ?? null);
        if (empty($file['tmp_name'])) return new WP_Error('no_file', 'Kein Bild hochgeladen.', ['status' => 400, 'field' => 'image']);
        if (!empty($file['error'])) return new WP_Error('upload_failed', 'Upload fehlgeschlagen (höchstens 10 MB).', ['status' => 400, 'field' => 'image']);
        if (intval($file['size'] ?? 0) > 10 * MB_IN_BYTES) return new WP_Error('too_large', 'Das Bild ist zu groß (höchstens 10 MB).', ['status' => 413, 'field' => 'image']);
        $info = @getimagesize($file['tmp_name']);
        $mime = is_array($info) ? (string) $info['mime'] : '';
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) return new WP_Error('bad_type', 'Bitte ein JPG- oder PNG-Bild wählen (das PDF-Ticket braucht eins davon).', ['status' => 400, 'field' => 'image']);
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $slot = (string) $req->get_param('slot') === 'logo' ? 'logo' : 'vorlage';
        $aid = media_handle_sideload(['name' => 'ticket-' . $slot . '-' . time() . ($mime === 'image/png' ? '.png' : '.jpg'), 'tmp_name' => $file['tmp_name'], 'type' => $mime, 'size' => $file['size'] ?? 0], absint($req->get_param('event_id')), 'Ticket-Design');
        if (is_wp_error($aid)) return new WP_Error('upload_failed', $aid->get_error_message(), ['status' => 500]);
        if (self::multi() && class_exists('TIX_App_Scope')) update_post_meta($aid, '_tix_ticket_design_org', intval(TIX_App_Scope::organizer_id_for_user()));
        return rest_ensure_response(['ok' => true, 'image_id' => intval($aid), 'url' => (string) wp_get_attachment_url($aid), 'width' => intval($info[0]), 'height' => intval($info[1])]);
    }

    // ═══════════════ Vorschau ═══════════════

    /**
     * POST /studio/ticket-design/preview {pdf? (ungespeicherte Konfiguration), template_id?, event_id?, format: jpg|pdf, width?}
     * → {image: data:image/jpeg… | pdf: data:application/pdf…, width, height, sample, wallet}
     */
    public static function rest_preview(WP_REST_Request $req) {
        if (!function_exists('imagecreatetruecolor')) return new WP_Error('tix_gd_missing', 'Auf diesem Server fehlt die Bildbearbeitung (GD).', ['status' => 501]);
        $b = (array) $req->get_json_params();
        $eid = absint($b['event_id'] ?? 0);
        if ($eid && (get_post_type($eid) !== 'event' || !TIX_REST_API::user_can_access_event($eid))) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        if (isset($b['pdf'])) { $cfg = self::clean_pdf($b['pdf']); if (is_wp_error($cfg)) return $cfg; }
        elseif (!empty($b['template_id'])) { $cfg = self::tpl_config(absint($b['template_id'])); if (!$cfg) return new WP_Error('tix_not_found', 'Vorlage nicht gefunden.', ['status' => 404]); }
        elseif ($eid) $cfg = TIX_Ticket_Template::get_effective_config($eid);
        else $cfg = self::pdf_config(self::org_of($req));
        if (!$cfg || empty($cfg['template_image_id'])) return new WP_Error('tix_no_template', 'Noch kein Vorlagenbild – bitte zuerst ein Bild hochladen.', ['status' => 409]);
        $img = TIX_Ticket_Template::render_ticket_image(0, $cfg, $eid ? self::event_sample($eid) : null);
        if (!$img) return new WP_Error('tix_render_failed', 'Die Vorschau konnte nicht erstellt werden.', ['status' => 500]);
        $format = ($b['format'] ?? 'jpg') === 'pdf' ? 'pdf' : 'jpg';
        $w = imagesx($img); $h = imagesy($img);
        if ($format === 'jpg') {
            $max = max(200, min(1600, intval($b['width'] ?? 900)));
            if ($w > $max) { $nh = intval($h * $max / $w); $t = imagecreatetruecolor($max, $nh); imagecopyresampled($t, $img, 0, 0, 0, 0, $max, $nh, $w, $h); imagedestroy($img); $img = $t; $w = $max; $h = $nh; }
        }
        ob_start(); imagejpeg($img, null, $format === 'pdf' ? 92 : 82); $jpeg = ob_get_clean();
        imagedestroy($img);
        $out = ['ok' => true, 'format' => $format, 'width' => $w, 'height' => $h, 'sample' => !$eid, 'wallet' => self::wallet_preview($eid)];
        if ($format === 'pdf') $out['pdf'] = 'data:application/pdf;base64,' . base64_encode((string) TIX_Ticket_Template::create_minimal_pdf($jpeg, $w, $h));
        else $out['image'] = 'data:image/jpeg;base64,' . base64_encode($jpeg);
        $r = rest_ensure_response($out);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /** Musterdaten mit den echten Angaben des Events (Name, Datum, Ort, erste Kategorie) */
    private static function event_sample($eid) {
        $d = TIX_Ticket_Template::preview_data();
        $d['event_name'] = html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8');
        $ds = (string) get_post_meta($eid, '_tix_date_start', true);
        if ($ds !== '' && ($ts = strtotime($ds))) $d['event_date'] = date_i18n('l, j. F Y', $ts);
        if (($t = (string) get_post_meta($eid, '_tix_time_start', true)) !== '') $d['event_time'] = $t . ' Uhr';
        if (($t = (string) get_post_meta($eid, '_tix_time_doors', true)) !== '') $d['event_doors'] = $t . ' Uhr';
        if (($l = (string) get_post_meta($eid, '_tix_location', true)) !== '') $d['event_location'] = $l;
        if (($a = (string) get_post_meta($eid, '_tix_address', true)) !== '') $d['event_address'] = $a;
        $cats = (array) get_post_meta($eid, '_tix_ticket_categories', true);
        $c = reset($cats);
        if (is_array($c)) { $d['cat_name'] = (string) ($c['name'] ?? $d['cat_name']); if (isset($c['price'])) $d['price'] = number_format(floatval($c['price']), 2, ',', '.') . ' €'; }
        $d['qr_code'] = 'GL-' . $eid . '-A7X3K9M2P5BN';
        return $d;
    }

    /** Wallet-Vorschau als Angaben (Farben, Titel, Felder) – die Pass-Datei selbst braucht ein echtes Ticket */
    private static function wallet_preview($eid) {
        $w = self::wallet_values();
        $d = $eid ? self::event_sample($eid) : TIX_Ticket_Template::preview_data();
        return ['enabled' => $w['enabled'], 'apple' => ['background' => $w['wallet_apple_bg_color'], 'foreground' => $w['wallet_apple_fg_color'], 'label' => $w['wallet_apple_label_color']],
            'google' => ['background' => $w['wallet_google_bg_color']],
            'title' => $d['event_name'], 'fields' => [['label' => 'Datum', 'value' => $d['event_date']], ['label' => 'Beginn', 'value' => $d['event_time']], ['label' => 'Ort', 'value' => $d['event_location']], ['label' => 'Ticket', 'value' => $d['cat_name']]],
            'barcode' => $d['qr_code']];
    }

    // ═══════════════ Vorlagen ═══════════════

    private static function tpl_allowed($id) {
        if (get_post_type($id) !== 'tix_ticket_tpl' || get_post_status($id) === 'trash') return false;
        if (!self::multi() || current_user_can('manage_options')) return true;
        $mine = class_exists('TIX_App_Scope') ? intval(TIX_App_Scope::organizer_id_for_user()) : 0;
        return $mine && intval(get_post_meta($id, '_tix_org_id', true)) === $mine;
    }

    private static function tpl_config($id) {
        if (!self::tpl_allowed($id)) return null;
        $j = get_post_meta($id, '_tix_template_config', true);
        $c = is_string($j) ? json_decode($j, true) : $j;
        return is_array($c) ? TIX_Ticket_Template::sanitize_config($c) : null;
    }

    private static function tpl_out($id) {
        $cfg = self::tpl_config($id) ?: TIX_Ticket_Template::default_config();
        global $wpdb;
        // genutzt = Events im Modus „Vorlage“ mit dieser Vorlage (die ID bleibt beim Umschalten stehen)
        $used = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT t.post_id) FROM $wpdb->postmeta t JOIN $wpdb->postmeta m ON m.post_id = t.post_id AND m.meta_key = '_tix_ticket_template_mode' AND m.meta_value = 'template'
            JOIN $wpdb->posts p ON p.ID = t.post_id AND p.post_status <> 'trash' WHERE t.meta_key = '_tix_ticket_template_id' AND t.meta_value = %s", (string) $id)));
        return ['id' => intval($id), 'title' => html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'), 'pdf' => self::pdf_out($cfg), 'used_by' => $used,
            'organizer_id' => intval(get_post_meta($id, '_tix_org_id', true)) ?: null];
    }

    public static function rest_tpl_list(WP_REST_Request $req) {
        $q = ['post_type' => 'tix_ticket_tpl', 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC'];
        $ids = array_values(array_filter(get_posts($q), [__CLASS__, 'tpl_allowed']));
        return rest_ensure_response(['ok' => true, 'templates' => array_map([__CLASS__, 'tpl_out'], $ids)]);
    }

    public static function rest_tpl_get(WP_REST_Request $req) {
        $id = absint($req['id']);
        if (!self::tpl_allowed($id)) return new WP_Error('tix_not_found', 'Vorlage nicht gefunden.', ['status' => 404]);
        return rest_ensure_response(['ok' => true, 'template' => self::tpl_out($id)]);
    }

    public static function rest_tpl_save(WP_REST_Request $req) {
        $id = absint($req['id'] ?? 0);
        if ($id && !self::tpl_allowed($id)) return new WP_Error('tix_not_found', 'Vorlage nicht gefunden.', ['status' => 404]);
        $b = (array) $req->get_json_params();
        $title = trim(sanitize_text_field((string) ($b['title'] ?? '')));
        if (!$id && $title === '') return new WP_Error('tix_invalid_field', 'Bitte einen Namen angeben.', ['status' => 400, 'field' => 'title']);
        $cfg = null;
        if (array_key_exists('pdf', $b)) { $cfg = self::clean_pdf($b['pdf']); if (is_wp_error($cfg)) return $cfg; }
        if (!$id) {
            $id = wp_insert_post(['post_type' => 'tix_ticket_tpl', 'post_status' => 'publish', 'post_title' => $title], true);
            if (is_wp_error($id)) return $id;
            if (self::multi() && class_exists('TIX_App_Scope')) update_post_meta($id, '_tix_org_id', current_user_can('manage_options') ? max(0, intval($req->get_param('organizer_id'))) : intval(TIX_App_Scope::organizer_id_for_user()));
        } elseif ($title !== '') wp_update_post(['ID' => $id, 'post_title' => $title]);
        if ($cfg) update_post_meta($id, '_tix_template_config', wp_slash(wp_json_encode($cfg)));
        $r = rest_ensure_response(['ok' => true, 'template' => self::tpl_out($id)]);
        if (!absint($req['id'] ?? 0)) $r->set_status(201);
        return $r;
    }

    public static function rest_tpl_delete(WP_REST_Request $req) {
        $id = absint($req['id']);
        if (!self::tpl_allowed($id)) return new WP_Error('tix_not_found', 'Vorlage nicht gefunden.', ['status' => 404]);
        $out = self::tpl_out($id);
        if ($out['used_by'] > 0) return new WP_Error('tix_template_in_use', 'Diese Vorlage nutzen noch ' . $out['used_by'] . ' Event(s).', ['status' => 409, 'used_by' => $out['used_by']]);
        wp_trash_post($id);
        return rest_ensure_response(['ok' => true, 'deleted' => $id]);
    }

    // ═══════════════ Je Event ═══════════════

    public static function rest_event_get(WP_REST_Request $req) {
        $eid = absint($req['id']);
        $mode = (string) get_post_meta($eid, '_tix_ticket_template_mode', true);
        if (!in_array($mode, self::MODES, true)) $mode = 'global';
        $tpl = intval(get_post_meta($eid, '_tix_ticket_template_id', true));
        $custom = TIX_Ticket_Template::get_event_config($eid);
        if (!$custom) { $j = get_post_meta($eid, '_tix_ticket_template', true); $c = is_string($j) ? json_decode($j, true) : $j; $custom = is_array($c) ? TIX_Ticket_Template::sanitize_config($c) : null; }
        $eff = TIX_Ticket_Template::get_effective_config($eid);
        return rest_ensure_response(['ok' => true, 'event_id' => $eid, 'mode' => $mode, 'template_id' => $tpl ?: null, 'custom' => $custom ? self::pdf_out($custom) : null,
            'effective' => $eff ? ['source' => $mode === 'none' ? 'none' : ($mode === 'template' && $tpl ? 'template' : ($mode === 'custom' ? 'custom' : (self::org_config_for_event($eid) ? 'organizer' : 'global'))), 'template_image' => self::img($eff['template_image_id'])] : null]);
    }

    public static function rest_event_save(WP_REST_Request $req) {
        $eid = absint($req['id']);
        $b = (array) $req->get_json_params();
        $mode = (string) ($b['mode'] ?? '');
        if ($mode !== '' && !in_array($mode, self::MODES, true)) return new WP_Error('tix_invalid_field', 'mode: global, template, custom oder none.', ['status' => 400, 'field' => 'mode']);
        if ($mode === 'template') {
            $tid = absint($b['template_id'] ?? 0);
            if (!$tid || !self::tpl_allowed($tid)) return new WP_Error('tix_invalid_field', 'Bitte eine Vorlage wählen.', ['status' => 400, 'field' => 'template_id']);
            update_post_meta($eid, '_tix_ticket_template_id', $tid);
        }
        if (array_key_exists('custom', $b)) {
            $cfg = self::clean_pdf($b['custom']);
            if (is_wp_error($cfg)) { $d = (array) $cfg->get_error_data(); $d['field'] = str_replace('pdf', 'custom', (string) ($d['field'] ?? 'custom')); return new WP_Error($cfg->get_error_code(), $cfg->get_error_message(), $d); }
            update_post_meta($eid, '_tix_ticket_template', wp_slash(wp_json_encode($cfg)));
        }
        if ($mode !== '') update_post_meta($eid, '_tix_ticket_template_mode', $mode === 'global' ? '' : $mode);
        if (class_exists('TIX_Syndication_Push') && get_post_meta($eid, '_tix_syndicate', true) === '1') TIX_Syndication_Push::push_event($eid);
        return self::rest_event_get($req);
    }
}
