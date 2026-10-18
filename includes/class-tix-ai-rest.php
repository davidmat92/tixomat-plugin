<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_AI_REST – KI-Funktionen für Veranstalter im Studio (1.38.505, User: „alle KI-Funktionen für Veranstalter auch ins Studio“).
 * Nutzt die bestehenden Kerne (TIX_AI_Writer, TIX_Meta_Ads, TIX_Content_Guard) – nichts doppelt.
 *
 *  A POST /ai/text {event_id?, field: excerpt|description|lineup|specials|extra_info, title?, context?} → {content, model}
 *  B POST /ai/extract {mode: image|text, image (Multipart/Base64) | text} → {fields, image {id, url}|null, missing[], model}
 *  C GET  /ai/duplicates?title&date_start&location&exclude → {duplicates [{id, title, date, status, url, reason}]}
 *  D POST /ai/social {event_id, platform: instagram|instagram_story|story|facebook|whatsapp|newsletter|twitter|x} → {variants[], model}
 *  F POST /events/{id}/guard {approve: true} (Admin) → {ok, guard, status}
 *  G GET/POST /studio/ai → {model, models [{id, label}], default, guard_enabled, provider_ready}; Schlüssel nie
 * Rechte A–D: TIX_REST_API::check_organizer, mit event_id nur zugängliche Events. Limit 20/min je Nutzer.
 * Fehler: 503 ai_unavailable, 429 tix_rate_limit, 400 tix_invalid_field (B auch 422 url_unreadable).
 */
class TIX_AI_REST {

    const NS = 'tixomat/v1';
    const MAX_IMAGE = 15728640; // 15 MB
    const API_IMAGE = 4718592;  // ~4,5 MB nach dem Verkleinern (Grenze der KI-Schnittstelle)

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        $org = [__CLASS__, 'perm'];
        register_rest_route(self::NS, '/ai/text', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_text'], 'permission_callback' => $org]);
        register_rest_route(self::NS, '/ai/extract', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_extract'], 'permission_callback' => $org]);
        register_rest_route(self::NS, '/ai/duplicates', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_duplicates'], 'permission_callback' => $org]);
        register_rest_route(self::NS, '/ai/social', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_social'], 'permission_callback' => $org]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/guard', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_guard'], 'permission_callback' => [__CLASS__, 'perm_admin']]);
        register_rest_route(self::NS, '/studio/ai', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_settings'], 'permission_callback' => [__CLASS__, 'perm_settings_read']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_settings_save'], 'permission_callback' => [__CLASS__, 'perm_admin']],
        ]);
    }

    // ── Rechte ──

    public static function perm(WP_REST_Request $req) {
        return class_exists('TIX_REST_API') ? TIX_REST_API::check_organizer($req) : current_user_can('manage_options');
    }

    public static function perm_admin() {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]);
        return current_user_can('manage_options') ? true : new WP_Error('rest_forbidden', 'Nur für Admins.', ['status' => 403]);
    }

    /** Lesen: Admin; Einzel-Seite auch Inhaber (Rolle Veranstalter). */
    public static function perm_settings_read() {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]);
        if (current_user_can('manage_options')) return true;
        $multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
        return (!$multi && in_array('tix_organizer', (array) wp_get_current_user()->roles, true)) ? true : new WP_Error('rest_forbidden', 'Nur für Admins.', ['status' => 403]);
    }

    private static function limited() {
        return class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('ai_rest', 20, 60, 'u' . get_current_user_id());
    }

    private static function rate_error() {
        return new WP_Error('tix_rate_limit', 'Zu viele KI-Anfragen – bitte kurz warten.', ['status' => 429]);
    }

    private static function ready_error() {
        if (!class_exists('TIX_AI_Writer') || !TIX_AI_Writer::provider_ready()) return new WP_Error('ai_unavailable', 'Die KI ist hier nicht eingerichtet (kein API-Schlüssel).', ['status' => 503]);
        return null;
    }

    private static function event_error($event_id) {
        if (get_post_type($event_id) !== 'event') return new WP_Error('tix_invalid_field', 'Event nicht gefunden.', ['status' => 404, 'field' => 'event_id']);
        if (class_exists('TIX_REST_API') && !TIX_REST_API::can_access_event($event_id)) return new WP_Error('rest_forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        return null;
    }

    private static function out(array $d) {
        $r = new WP_REST_Response(['ok' => true] + $d);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /** Fehler der Kerne (WP_Error mit Status) einheitlich: unbekannte Codes → ai_unavailable 503. */
    private static function map_error(WP_Error $e) {
        $d = (array) $e->get_error_data();
        $code = $e->get_error_code();
        if (!in_array($code, ['tix_invalid_field', 'ai_unavailable', 'url_unreadable', 'url_invalid', 'tix_rate_limit'], true)) $code = 'ai_unavailable';
        $status = intval($d['status'] ?? 0) ?: ['tix_invalid_field' => 400, 'url_invalid' => 400, 'url_unreadable' => 422, 'tix_rate_limit' => 429][$code] ?? 503;
        return new WP_Error($code, $e->get_error_message(), array_merge($d, ['status' => $status]));
    }

    // ── A: Texte ──

    public static function rest_text(WP_REST_Request $req) {
        $field = sanitize_key((string) $req->get_param('field'));
        if (!in_array($field, ['excerpt', 'description', 'lineup', 'specials', 'extra_info'], true)) return new WP_Error('tix_invalid_field', 'Feld: excerpt, description, lineup, specials oder extra_info.', ['status' => 400, 'field' => 'field']);
        $event_id = absint($req->get_param('event_id'));
        if ($event_id && ($e = self::event_error($event_id))) return $e;
        if ($e = self::ready_error()) return $e;
        if (self::limited()) return self::rate_error();
        $title = sanitize_text_field((string) ($req->get_param('title') ?? ($event_id ? get_the_title($event_id) : '')));
        $context = sanitize_textarea_field((string) ($req->get_param('context') ?? ''));
        if ($field === 'excerpt') {
            $in = ['post_id' => $event_id, 'title' => $title, 'context' => $context];
            foreach (['description', 'lineup', 'specials', 'extra_info', 'date_start', 'time_start', 'location_id'] as $k) if ($req->get_param($k) !== null) $in[$k] = (string) $req->get_param($k);
            $r = TIX_AI_Writer::excerpt_for($in);
        } else {
            // Ohne eigenen Kontext: Angaben des Events (Datum, Ort, andere Info-Felder)
            if ($context === '' && $event_id) $context = self::event_context($event_id, $field);
            $r = TIX_AI_Writer::field_for($field, $title, $context);
        }
        if (is_wp_error($r)) return self::map_error($r);
        return self::out(['content' => (string) $r['content'], 'field' => $field, 'model' => TIX_AI_Writer::model()]);
    }

    private static function event_context($event_id, $skip) {
        $p = [];
        $d = (string) get_post_meta($event_id, '_tix_date_start', true);
        if ($d) $p[] = 'Datum: ' . date_i18n('l, d. F Y', strtotime($d));
        if ($t = (string) get_post_meta($event_id, '_tix_time_start', true)) $p[] = "Beginn: {$t} Uhr";
        if ($l = (string) get_post_meta($event_id, '_tix_location', true)) $p[] = "Ort: {$l}";
        foreach (['description' => 'Beschreibung', 'lineup' => 'Line-Up', 'specials' => 'Specials', 'extra_info' => 'Weitere Infos'] as $k => $lab) {
            if ($k === $skip) continue;
            $v = trim(wp_strip_all_tags((string) get_post_meta($event_id, '_tix_info_' . $k, true)));
            if ($v !== '') $p[] = "{$lab}: " . mb_substr($v, 0, 800);
        }
        return implode("\n", $p);
    }

    // ── B: Ausfüllen aus Flyer oder Text ──

    /** Bild aus Multipart (`image`) oder Base64/Data-URL lesen, ≤ 15 MB, JPG/PNG/WebP/GIF. → [bytes, mime] | WP_Error */
    private static function read_image(WP_REST_Request $req) {
        $files = $req->get_file_params();
        $bytes = '';
        if (!empty($files['image']['tmp_name']) && is_uploaded_file($files['image']['tmp_name'])) {
            if (intval($files['image']['size']) > self::MAX_IMAGE) return new WP_Error('tix_invalid_field', 'Das Bild ist zu groß (höchstens 15 MB).', ['status' => 400, 'field' => 'image']);
            $bytes = (string) file_get_contents($files['image']['tmp_name']);
        } else {
            $raw = (string) $req->get_param('image');
            if (preg_match('#^data:image/[a-z+.-]+;base64,#i', $raw)) $raw = substr($raw, strpos($raw, ',') + 1);
            if (strlen($raw) > self::MAX_IMAGE * 1.4) return new WP_Error('tix_invalid_field', 'Das Bild ist zu groß (höchstens 15 MB).', ['status' => 400, 'field' => 'image']);
            $bytes = $raw !== '' ? (string) base64_decode($raw, true) : '';
        }
        if ($bytes === '') return new WP_Error('tix_invalid_field', 'Bitte lade einen Flyer hoch (JPG, PNG oder WebP).', ['status' => 400, 'field' => 'image']);
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return new WP_Error('tix_invalid_field', 'Bitte ein Bild als JPG, PNG, WebP oder GIF.', ['status' => 400, 'field' => 'image']);
        return [$bytes, $mime];
    }

    /** Für die KI verkleinern (längste Seite 2400 px, JPEG), wenn das Bild zu groß ist. → [bytes, mime] */
    private static function shrink_for_api($bytes, $mime) {
        if (strlen($bytes) <= self::API_IMAGE) return [$bytes, $mime];
        // Endung passend zum Format, sonst erkennt Imagick das Bild nicht
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? 'jpg';
        $tmp = wp_tempnam('tix-ai-flyer');
        @unlink($tmp);
        $tmp .= '.' . $ext;
        file_put_contents($tmp, $bytes);
        $out = [$bytes, $mime];
        // Stufen, bis das Bild unter die Grenze der KI-Schnittstelle passt
        foreach ([[2400, 82], [1800, 78], [1400, 72]] as $step) {
            $ed = wp_get_image_editor($tmp);
            if (is_wp_error($ed)) break;
            $ed->resize($step[0], $step[0], false);
            $ed->set_quality($step[1]);
            $saved = $ed->save(preg_replace('/\.[a-z]+$/', '', $tmp) . '-ki.jpg', 'image/jpeg');
            if (is_wp_error($saved) || empty($saved['path']) || !is_file($saved['path'])) break;
            $out = [(string) file_get_contents($saved['path']), 'image/jpeg'];
            @unlink($saved['path']);
            if (strlen($out[0]) <= self::API_IMAGE) break;
        }
        @unlink($tmp);
        return $out;
    }

    public static function rest_extract(WP_REST_Request $req) {
        $mode = sanitize_key((string) $req->get_param('mode'));
        if (!in_array($mode, ['image', 'text'], true)) return new WP_Error('tix_invalid_field', 'mode: image oder text.', ['status' => 400, 'field' => 'mode']);
        $img = null;
        if ($mode === 'text') {
            $text = trim(sanitize_textarea_field((string) $req->get_param('text')));
            if (mb_strlen($text) < 10) return new WP_Error('tix_invalid_field', 'Beschreibe dein Event in ein paar Worten (Titel, Datum, Ort).', ['status' => 400, 'field' => 'text']);
            if (mb_strlen($text) > 4000) return new WP_Error('tix_invalid_field', 'Bitte höchstens 4000 Zeichen.', ['status' => 400, 'field' => 'text']);
        } else {
            $img = self::read_image($req);
            if (is_wp_error($img)) return $img;
        }
        if ($e = self::ready_error()) return $e;
        if (self::limited()) return self::rate_error();
        $image = null;
        if ($mode === 'text') {
            $f = TIX_AI_Writer::extract_text($text);
        } else {
            list($b, $m) = self::shrink_for_api($img[0], $img[1]);
            if (strlen($b) > self::API_IMAGE * 1.1) return new WP_Error('tix_invalid_field', 'Das Bild ist zu groß für die Analyse. Bitte ein kleineres Bild wählen.', ['status' => 400, 'field' => 'image']);
            $f = TIX_AI_Writer::extract_image(base64_encode($b), $m);
            // Flyer als vorläufiger Anhang (wie bei der Registrierung; unbenutzt räumt der Tageslauf auf)
            if (!is_wp_error($f) && class_exists('TIX_Register_API') && method_exists('TIX_Register_API', 'store_tmp_image')) {
                $aid = TIX_Register_API::store_tmp_image($img[0], $img[1]);
                // dem Aufrufer zuordnen, damit nur er es per image_id ans Event hängen kann
                if ($aid) wp_update_post(['ID' => $aid, 'post_author' => get_current_user_id()]);
                if ($aid) $image = ['id' => $aid, 'url' => (string) (wp_get_attachment_image_url($aid, 'large') ?: wp_get_attachment_url($aid))];
            }
        }
        if (is_wp_error($f)) return self::map_error($f);
        unset($f['_source'], $f['_read_mode'], $f['_notice'], $f['_pages_read'], $f['suggested_image_id'], $f['suggested_image_url']);
        $ids = array_map('intval', (array) ($f['category_ids'] ?? []));
        $t = $ids ? get_term($ids[0], 'event_category') : null;
        $f['event_category'] = ($t && !is_wp_error($t)) ? (string) $t->slug : '';
        $missing = [];
        foreach (['title', 'date_start', 'time_start', 'location'] as $k) if (trim((string) ($f[$k] ?? '')) === '') $missing[] = $k;
        return self::out(['mode' => $mode, 'fields' => $f, 'image' => $image, 'missing' => $missing, 'model' => TIX_AI_Writer::model()]);
    }

    // ── C: Dubletten ──

    public static function rest_duplicates(WP_REST_Request $req) {
        $title = sanitize_text_field((string) $req->get_param('title'));
        if (trim($title) === '') return self::out(['duplicates' => []]);
        $query = [];
        // Mehr-Veranstalter-Modus: nur Events des eigenen Veranstalters (Admin: alle)
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::scoped()) {
            $oid = intval(TIX_App_Scope::organizer_id_for_user());
            if (!$oid) return self::out(['duplicates' => []]);
            $query['meta_query'] = [TIX_App_Scope::event_meta_clause($oid)];
        }
        $rows = TIX_AI_Writer::find_duplicates($title, sanitize_text_field((string) $req->get_param('date_start')), sanitize_text_field((string) $req->get_param('location')), absint($req->get_param('exclude')), $query);
        foreach ($rows as &$r) $r['url'] = (string) get_permalink($r['id']);
        unset($r);
        return self::out(['duplicates' => $rows]);
    }

    // ── D: Social-Texte ──

    public static function rest_social(WP_REST_Request $req) {
        $event_id = absint($req->get_param('event_id'));
        if (!$event_id) return new WP_Error('tix_invalid_field', 'event_id fehlt.', ['status' => 400, 'field' => 'event_id']);
        if ($e = self::event_error($event_id)) return $e;
        $platform = sanitize_key((string) $req->get_param('platform'));
        $known = ['instagram', 'instagram_story', 'story', 'facebook', 'whatsapp', 'newsletter', 'twitter', 'x'];
        if (!in_array($platform, $known, true)) return new WP_Error('tix_invalid_field', 'Plattform: instagram, instagram_story, facebook, whatsapp, newsletter oder twitter.', ['status' => 400, 'field' => 'platform']);
        // TIX_Meta_Ads lädt sonst nur im WP-Admin (ohne init nachladen, nur der Kern wird gebraucht)
        if (!class_exists('TIX_Meta_Ads') && defined('TIXOMAT_PATH') && is_readable(TIXOMAT_PATH . 'includes/class-tix-meta-ads.php')) require_once TIXOMAT_PATH . 'includes/class-tix-meta-ads.php';
        if (!class_exists('TIX_Meta_Ads') || !method_exists('TIX_Meta_Ads', 'social_for')) return new WP_Error('ai_unavailable', 'Social-Texte sind hier nicht verfügbar.', ['status' => 503]);
        if ($e = self::ready_error()) return $e;
        if (self::limited()) return self::rate_error();
        $r = TIX_Meta_Ads::social_for($event_id, $platform);
        if (is_wp_error($r)) return self::map_error($r);
        return self::out(['platform' => TIX_Meta_Ads::SOCIAL_ALIASES[$platform] ?? $platform, 'variants' => array_values((array) $r['variants']), 'model' => (string) ($r['model'] ?? TIX_AI_Writer::model())]);
    }

    // ── F: KI-Schutz freigeben ──

    public static function rest_guard(WP_REST_Request $req) {
        $id = absint($req['id']);
        if (get_post_type($id) !== 'event') return new WP_Error('tix_invalid_field', 'Event nicht gefunden.', ['status' => 404]);
        if (!filter_var($req->get_param('approve'), FILTER_VALIDATE_BOOLEAN)) return new WP_Error('tix_invalid_field', 'Bitte approve: true senden.', ['status' => 400, 'field' => 'approve']);
        if (!class_exists('TIX_Content_Guard') || !method_exists('TIX_Content_Guard', 'approve')) return new WP_Error('ai_unavailable', 'KI-Schutz ist hier nicht verfügbar.', ['status' => 503]);
        TIX_Content_Guard::approve($id);
        return self::out(['event_id' => $id, 'guard' => TIX_Content_Guard::guard_info($id), 'status' => (string) get_post_status($id)]);
    }

    // ── G: KI-Einstellungen ──

    private static function settings_payload() {
        $s = get_option('tix_settings', []);
        $s = is_array($s) ? $s : [];
        $models = [];
        foreach (TIX_AI_Writer::MODELS as $id => $label) $models[] = ['id' => $id, 'label' => $label, 'provider' => preg_match('/^(gpt|o\d)/', $id) ? 'openai' : 'anthropic'];
        $model = TIX_AI_Writer::model_for($s['ai_model'] ?? '');
        $openai = preg_match('/^(gpt|o\d)/', $model) === 1;
        $key = trim((string) ($openai ? ($s['openai_api_key'] ?? '') : ($s['anthropic_api_key'] ?? '')));
        return ['model' => $model, 'models' => $models, 'default' => TIX_AI_Writer::DEFAULT_MODEL, 'guard_enabled' => !empty($s['ai_guard_enabled']),
            'provider_ready' => $key !== '', 'can_edit' => current_user_can('manage_options')];
    }

    public static function rest_settings(WP_REST_Request $req) {
        return self::out(self::settings_payload());
    }

    /** POST /studio/ai {model?, guard_enabled?} – nur Admin; der API-Schlüssel ist hier weder lesbar noch setzbar. */
    public static function rest_settings_save(WP_REST_Request $req) {
        $b = $req->get_json_params();
        if (!is_array($b)) $b = $req->get_body_params();
        $unknown = array_diff(array_keys((array) $b), ['model', 'guard_enabled']);
        if ($unknown) return new WP_Error('tix_invalid_field', 'Nur model und guard_enabled lassen sich hier ändern.', ['status' => 400, 'field' => (string) reset($unknown)]);
        $s = get_option('tix_settings', []);
        $s = is_array($s) ? $s : [];
        if (array_key_exists('model', $b)) {
            $m = (string) $b['model'];
            if (!isset(TIX_AI_Writer::MODELS[$m])) return new WP_Error('tix_invalid_field', 'Unbekanntes Modell.', ['status' => 400, 'field' => 'model']);
            $s['ai_model'] = $m;
            delete_option(TIX_AI_Writer::NOTICE_OPT);
        }
        if (array_key_exists('guard_enabled', $b)) {
            $g = filter_var($b['guard_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($g === null) return new WP_Error('tix_invalid_field', 'guard_enabled: true oder false.', ['status' => 400, 'field' => 'guard_enabled']);
            $s['ai_guard_enabled'] = $g ? 1 : 0;
        }
        update_option('tix_settings', $s);
        return self::out(self::settings_payload());
    }
}

TIX_AI_REST::init();
