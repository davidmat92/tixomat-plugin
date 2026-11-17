<?php
/**
 * TIX_Platform_Content – Plattform-Akte W6b (1.38.888): Partner (P4), hervorgehobene Events (P6), Städte-Texte (P7),
 * Wochenmail und Folgen per E-Mail (P8), Sperren (P10). Vertrag: uebergaben/vertrag-plattform-akte-w6-2026-11-17.md, §247.
 * Nur Plattform-Admins (TIX_Platform_Admin::perm). Partner-Schlüssel werden nie ausgegeben (neue Partner koppeln sich selbst).
 *
 *   GET    /platform/partners                     P4 Liste (ohne Schlüssel)
 *   POST   /platform/partners/{id}                P4 {name?, organizer_id?, sales_enabled?, terms_url?, channel_fee?, channel_fee_percent?, channel_fee_fixed?}
 *   POST   /platform/partners/{id}/approve        P4 Verkauf freigeben
 *   DELETE /platform/partners/{id}                P4 Partner entfernen (Kopplung endet; Events bleiben bis zur nächsten Verteilung)
 *   GET    /platform/promoted                     P6 hervorgehobene Events
 *   POST   /platform/events/{id}/promote          P6 {enabled, priority? 0–1000, until? Y-m-d|''}
 *   GET    /platform/city-texts                   P7 {cities: [{slug, name, intro, image}]}
 *   POST   /platform/city-texts                   P7 {name, intro (HTML), image_id?} – anlegen/ändern (Slug aus dem Namen)
 *   POST   /platform/city-texts/image             P7 multipart `image` (+ alt?) → {image: {id, url, thumb, alt}} (1.38.891)
 *   DELETE /platform/city-texts/{slug}            P7
 *   GET|POST /platform/weekly-mail                P8 {enabled, day 1–7, hour 0–23} + counts
 *   POST   /platform/weekly-mail/test             P8 {city?} Test an die eigene Adresse
 *   GET|POST /platform/mail-follow                P8 {enabled} + active
 *   GET|POST /platform/blocks                     P10 {ips: string[], customers: string[]} (ganze Liste)
 */
if (!defined('ABSPATH')) exit;

class TIX_Platform_Content {

    const NS = 'tixomat/v1';

    public static function init() { add_action('rest_api_init', [__CLASS__, 'routes']); }

    public static function routes() {
        $p = ['permission_callback' => ['TIX_Platform_Admin', 'perm']];
        $r = function ($path, $method, $cb) use ($p) { register_rest_route(self::NS, $path, ['methods' => $method, 'callback' => [__CLASS__, $cb]] + $p); };
        $r('/platform/partners', 'GET', 'rest_partners');
        $r('/platform/partners/(?P<id>[a-z0-9_-]{1,64})', 'POST', 'rest_partner_save');
        $r('/platform/partners/(?P<id>[a-z0-9_-]{1,64})', 'DELETE', 'rest_partner_delete');
        $r('/platform/partners/(?P<id>[a-z0-9_-]{1,64})/approve', 'POST', 'rest_partner_approve');
        $r('/platform/promoted', 'GET', 'rest_promoted');
        $r('/platform/events/(?P<id>\d+)/promote', 'POST', 'rest_promote');
        $r('/platform/city-texts', 'GET', 'rest_cities');
        $r('/platform/city-texts', 'POST', 'rest_city_save');
        $r('/platform/city-texts/image', 'POST', 'rest_city_image'); // 1.38.891: Bild hochladen → id für POST /platform/city-texts
        $r('/platform/city-texts/(?P<slug>[a-z0-9-]{1,80})', 'DELETE', 'rest_city_delete');
        $r('/platform/weekly-mail', 'GET', 'rest_weekly');
        $r('/platform/weekly-mail', 'POST', 'rest_weekly_save');
        $r('/platform/weekly-mail/test', 'POST', 'rest_weekly_test');
        $r('/platform/mail-follow', 'GET', 'rest_follow');
        $r('/platform/mail-follow', 'POST', 'rest_follow_save');
        $r('/platform/blocks', 'GET', 'rest_blocks');
        $r('/platform/blocks', 'POST', 'rest_blocks_save');
    }

    private static function bad($field, $msg, $code = 'tix_invalid_field', $status = 400) { return new WP_Error($code, $msg, ['status' => $status, 'field' => $field]); }
    private static function body(WP_REST_Request $req) { $b = $req->get_json_params(); return is_array($b) ? $b : (array) $req->get_body_params(); }
    private static function audit($action, $subject, array $summary) {
        if (class_exists('TIX_Customers') && method_exists('TIX_Customers', 'audit')) TIX_Customers::audit($action, $subject, ['scope' => 'platform', 'oid' => 0], $summary);
    }
    private static function flush() { if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush(); }

    // ═══════════════ P4 Partner ═══════════════

    private static function partner_item(array $p) {
        $oid = intval($p['organizer_id'] ?? 0);
        return ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? ''), 'api_base' => (string) ($p['api_base'] ?? ''),
            'organizer' => $oid && get_post_type($oid) === 'tix_organizer' ? ['id' => $oid, 'name' => html_entity_decode(get_the_title($oid), ENT_QUOTES, 'UTF-8')] : null,
            'sales_enabled' => !empty($p['sales_enabled']), 'pending' => !empty($p['pending']), 'auto' => !empty($p['auto']),
            'connected' => (string) ($p['connected'] ?? '') ?: null, 'created' => (string) ($p['created'] ?? '') ?: null, 'terms_url' => (string) ($p['terms_url'] ?? ''),
            'keys_set' => strlen((string) ($p['key_in'] ?? '')) >= 20 && strlen((string) ($p['key_out'] ?? '')) >= 20,
            'channel_fee' => !empty($p['channel_fee']), 'channel_fee_percent' => ($p['channel_fee_percent'] ?? '') === '' ? null : floatval($p['channel_fee_percent']),
            'channel_fee_fixed' => ($p['channel_fee_fixed'] ?? '') === '' ? null : floatval($p['channel_fee_fixed'])];
    }

    public static function rest_partners(WP_REST_Request $req) {
        if (!class_exists('TIX_Partners')) return new WP_Error('tix_unavailable', 'Partner nicht verfügbar.', ['status' => 500]);
        $items = array_values(array_map([__CLASS__, 'partner_item'], TIX_Partners::all()));
        return rest_ensure_response(['ok' => true, 'partners' => $items, 'autoconnect' => function_exists('tix_get_settings') ? (bool) (tix_get_settings('partner_autoconnect') ?? true) : true]);
    }

    public static function rest_partner_save(WP_REST_Request $req) {
        return self::partner_save(sanitize_key((string) $req['id']), self::body($req));
    }

    private static function partner_save($id, array $b) {
        if (!class_exists('TIX_Partners')) return new WP_Error('tix_unavailable', 'Partner nicht verfügbar.', ['status' => 500]);
        $all = TIX_Partners::all();
        if (!isset($all[$id])) return new WP_Error('not_found', 'Partner nicht gefunden. Neue Partner verbinden sich über „Mit Plattform verbinden“.', ['status' => 404]);
        $p = $all[$id]; $changed = [];
        if (array_key_exists('name', $b)) { $n = trim(sanitize_text_field((string) $b['name'])); if ($n === '' || mb_strlen($n) > 120) return self::bad('name', 'Name 1–120 Zeichen.'); $p['name'] = $n; $changed[] = 'name'; }
        if (array_key_exists('organizer_id', $b)) { $o = intval($b['organizer_id']); if ($o && get_post_type($o) !== 'tix_organizer') return self::bad('organizer_id', 'Veranstalter nicht gefunden.'); $p['organizer_id'] = $o; $changed[] = 'organizer_id'; }
        if (array_key_exists('terms_url', $b)) { $u = trim((string) $b['terms_url']); if ($u !== '' && (stripos($u, 'https://') !== 0 || filter_var($u, FILTER_VALIDATE_URL) === false)) return self::bad('terms_url', 'AGB-Link mit https://.'); $p['terms_url'] = esc_url_raw($u); $changed[] = 'terms_url'; }
        if (array_key_exists('channel_fee', $b)) { $p['channel_fee'] = filter_var($b['channel_fee'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0; $changed[] = 'channel_fee'; }
        foreach (['channel_fee_percent' => [0, 20, 4], 'channel_fee_fixed' => [0, 5, 2]] as $k => $lim) {
            if (!array_key_exists($k, $b)) continue;
            $v = $b[$k];
            if ($v === null || $v === '') { $p[$k] = ''; $changed[] = $k; continue; }
            $v = str_replace(',', '.', (string) $v);
            if (!is_numeric($v) || floatval($v) < $lim[0] || floatval($v) > $lim[1]) return self::bad($k, 'Wert ' . $lim[0] . '–' . $lim[1] . ' oder leer (Vorgabe).');
            $p[$k] = (string) round(floatval($v), $lim[2]); $changed[] = $k;
        }
        if (array_key_exists('sales_enabled', $b)) { $p['sales_enabled'] = filter_var($b['sales_enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0; if ($p['sales_enabled']) $p['pending'] = 0; $changed[] = 'sales_enabled'; }
        $all[$id] = $p;
        update_option(TIX_Partners::OPTION, $all, false);
        self::flush();
        self::audit('platform_partner_save', $id, ['fields' => $changed]);
        return rest_ensure_response(['ok' => true, 'partner' => self::partner_item(TIX_Partners::get($id))]);
    }

    public static function rest_partner_approve(WP_REST_Request $req) {
        $r = self::partner_save(sanitize_key((string) $req['id']), ['sales_enabled' => true]);
        if (!is_wp_error($r)) self::audit('platform_partner_approve', sanitize_key((string) $req['id']), []);
        return $r;
    }

    public static function rest_partner_delete(WP_REST_Request $req) {
        if (!class_exists('TIX_Partners')) return new WP_Error('tix_unavailable', 'Partner nicht verfügbar.', ['status' => 500]);
        $id = sanitize_key((string) $req['id']);
        $all = TIX_Partners::all();
        if (!isset($all[$id])) return new WP_Error('not_found', 'Partner nicht gefunden.', ['status' => 404]);
        unset($all[$id]);
        update_option(TIX_Partners::OPTION, $all, false);
        self::flush();
        self::audit('platform_partner_delete', $id, []);
        return rest_ensure_response(['ok' => true]);
    }

    // ═══════════════ P6 Hervorheben ═══════════════

    private static function promoted_item($eid) {
        $until = (string) get_post_meta($eid, '_tix_promoted_until', true);
        return ['id' => intval($eid), 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'), 'date' => (string) get_post_meta($eid, '_tix_date_start', true) ?: null,
            'status' => (string) get_post_status($eid), 'promoted' => get_post_meta($eid, '_tix_promoted', true) === '1',
            'priority' => intval(get_post_meta($eid, '_tix_promoted_priority', true) ?: 10), 'until' => $until ?: null,
            'active' => get_post_meta($eid, '_tix_promoted', true) === '1' && ($until === '' || $until >= current_time('Y-m-d'))];
    }

    public static function rest_promoted(WP_REST_Request $req) {
        $ids = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'future', 'draft', 'private'], 'posts_per_page' => 200, 'fields' => 'ids', 'meta_key' => '_tix_promoted', 'meta_value' => '1']);
        $items = array_map([__CLASS__, 'promoted_item'], $ids);
        usort($items, function ($a, $b) { return $b['priority'] <=> $a['priority'] ?: strcmp((string) $a['date'], (string) $b['date']); });
        return rest_ensure_response(['ok' => true, 'events' => array_values($items)]);
    }

    public static function rest_promote(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if (get_post_type($eid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        $b = self::body($req);
        if (!array_key_exists('enabled', $b)) return self::bad('enabled', 'enabled fehlt.');
        $on = filter_var($b['enabled'], FILTER_VALIDATE_BOOLEAN);
        if ($on) {
            if (array_key_exists('priority', $b) && (!is_numeric($b['priority']) || intval($b['priority']) < 0 || intval($b['priority']) > 1000)) return self::bad('priority', 'Rang 0–1000.');
            $until = (string) ($b['until'] ?? '');
            if ($until !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $until < current_time('Y-m-d'))) return self::bad('until', 'Datum ab heute (JJJJ-MM-TT) oder leer.');
            update_post_meta($eid, '_tix_promoted', '1');
            if (array_key_exists('priority', $b)) update_post_meta($eid, '_tix_promoted_priority', intval($b['priority']));
            elseif (get_post_meta($eid, '_tix_promoted_priority', true) === '') update_post_meta($eid, '_tix_promoted_priority', 10);
            if (array_key_exists('until', $b)) { if ($until !== '') update_post_meta($eid, '_tix_promoted_until', $until); else delete_post_meta($eid, '_tix_promoted_until'); }
        } else {
            update_post_meta($eid, '_tix_promoted', '0');
        }
        self::flush();
        self::audit('platform_promote', $eid, ['enabled' => $on]);
        return rest_ensure_response(['ok' => true, 'event' => self::promoted_item($eid)]);
    }

    // ═══════════════ P7 Städte-Texte ═══════════════

    private static function cities_payload() {
        $out = [];
        foreach ((class_exists('TIX_SEO_Texts') ? TIX_SEO_Texts::city_texts() : []) as $slug => $c) {
            $img = intval($c['image_id'] ?? 0);
            $out[] = ['slug' => (string) $slug, 'name' => (string) ($c['name'] ?? $slug), 'intro' => (string) ($c['intro'] ?? ''), 'image' => $img && class_exists('TIX_SEO_Texts') ? (['id' => $img] + (array) TIX_SEO_Texts::image_payload($img)) : null];
        }
        usort($out, function ($a, $b) { return strcmp($a['name'], $b['name']); });
        return ['ok' => true, 'cities' => $out];
    }

    public static function rest_cities(WP_REST_Request $req) { return rest_ensure_response(self::cities_payload()); }

    public static function rest_city_save(WP_REST_Request $req) {
        if (!class_exists('TIX_SEO_Texts')) return new WP_Error('tix_unavailable', 'Nicht verfügbar.', ['status' => 500]);
        $b = self::body($req);
        $name = trim(sanitize_text_field((string) ($b['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 80) return self::bad('name', 'Bitte einen Stadtnamen (höchstens 80 Zeichen).');
        $intro = wp_kses_post((string) ($b['intro'] ?? ''));
        if (mb_strlen($intro) > 20000) return self::bad('intro', 'Höchstens 20.000 Zeichen.');
        $img = intval($b['image_id'] ?? 0);
        if ($img && (get_post_type($img) !== 'attachment' || strpos((string) get_post_mime_type($img), 'image/') !== 0)) return self::bad('image_id', 'Bild nicht gefunden.');
        if (trim(wp_strip_all_tags($intro)) === '' && !$img) return self::bad('intro', 'Bitte einen Text oder ein Bild.');
        $slug = TIX_SEO_Texts::city_slug($name);
        $all = TIX_SEO_Texts::city_texts();
        $all[$slug] = ['name' => $name, 'intro' => $intro, 'image_id' => $img];
        update_option(TIX_SEO_Texts::OPTION_CITY, $all, false);
        self::flush();
        self::audit('platform_city_text', $slug, []);
        return rest_ensure_response(self::cities_payload());
    }

    /** POST /platform/city-texts/image (multipart `image`, JPG/PNG/WebP ≤ 15 MB) → {ok, image: {id, url, thumb, alt}} */
    public static function rest_city_image(WP_REST_Request $req) {
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
        $aid = media_handle_sideload(['name' => 'stadt-' . time() . '.' . $ext, 'tmp_name' => $file['tmp_name'], 'type' => $mime, 'size' => $file['size'] ?? 0], 0, 'Städte-Text');
        if (is_wp_error($aid)) return new WP_Error('upload_failed', $aid->get_error_message(), ['status' => 500]);
        $alt = trim(sanitize_text_field((string) $req->get_param('alt')));
        if ($alt !== '') update_post_meta($aid, '_wp_attachment_image_alt', mb_substr($alt, 0, 200));
        self::audit('platform_city_image', intval($aid), []);
        $img = class_exists('TIX_SEO_Texts') ? (array) TIX_SEO_Texts::image_payload($aid) : ['url' => (string) wp_get_attachment_url($aid)];
        return rest_ensure_response(['ok' => true, 'image' => ['id' => intval($aid)] + $img]);
    }

    public static function rest_city_delete(WP_REST_Request $req) {
        if (!class_exists('TIX_SEO_Texts')) return new WP_Error('tix_unavailable', 'Nicht verfügbar.', ['status' => 500]);
        $all = TIX_SEO_Texts::city_texts();
        $slug = (string) $req['slug'];
        if (!isset($all[$slug])) return new WP_Error('not_found', 'Städte-Text nicht gefunden.', ['status' => 404]);
        unset($all[$slug]);
        update_option(TIX_SEO_Texts::OPTION_CITY, $all, false);
        self::flush();
        self::audit('platform_city_text_delete', $slug, []);
        return rest_ensure_response(self::cities_payload());
    }

    // ═══════════════ P8 Wochenmail, Folgen per E-Mail ═══════════════

    private static function weekly_payload() {
        if (!class_exists('TIX_Weekly_Mail')) return ['ok' => true, 'available' => false];
        return ['ok' => true, 'available' => true] + TIX_Weekly_Mail::settings() + ['counts' => TIX_Weekly_Mail::counts(),
            'hint' => 'Erst einschalten, wenn der Datenschutz-Absatz zur Wochenmail veröffentlicht ist.'];
    }

    public static function rest_weekly(WP_REST_Request $req) { return rest_ensure_response(self::weekly_payload()); }

    public static function rest_weekly_save(WP_REST_Request $req) {
        if (!class_exists('TIX_Weekly_Mail')) return new WP_Error('tix_unavailable', 'Nicht verfügbar.', ['status' => 500]);
        $b = self::body($req); $s = TIX_Weekly_Mail::settings();
        if (array_key_exists('enabled', $b)) $s['enabled'] = filter_var($b['enabled'], FILTER_VALIDATE_BOOLEAN);
        foreach (['day' => [1, 7], 'hour' => [0, 23]] as $k => $lim) {
            if (!array_key_exists($k, $b)) continue;
            if (!is_numeric($b[$k]) || intval($b[$k]) != $b[$k] || intval($b[$k]) < $lim[0] || intval($b[$k]) > $lim[1]) return self::bad($k, ($k === 'day' ? 'Wochentag 1 (Mo) – 7 (So).' : 'Stunde 0–23.'));
            $s[$k] = intval($b[$k]);
        }
        update_option(TIX_Weekly_Mail::OPTION, ['enabled' => $s['enabled'] ? 1 : 0, 'day' => $s['day'], 'hour' => $s['hour']], false);
        self::audit('platform_weekly_mail', 0, ['enabled' => (bool) $s['enabled'], 'day' => $s['day'], 'hour' => $s['hour']]);
        return rest_ensure_response(self::weekly_payload());
    }

    public static function rest_weekly_test(WP_REST_Request $req) {
        if (!class_exists('TIX_Weekly_Mail') || !method_exists('TIX_Weekly_Mail', 'send_test')) return new WP_Error('tix_unavailable', 'Nicht verfügbar.', ['status' => 500]);
        $to = (string) wp_get_current_user()->user_email;
        if (!is_email($to)) return new WP_Error('tix_no_email', 'Dein Konto hat keine E-Mail-Adresse.', ['status' => 409]);
        if (!TIX_Weekly_Mail::send_test($to, (string) $req->get_param('city'))) return new WP_Error('tix_mail_failed', 'Die Test-Mail konnte nicht gesendet werden.', ['status' => 500]);
        return rest_ensure_response(['ok' => true, 'email' => $to]);
    }

    private static function follow_payload() {
        if (!class_exists('TIX_Mail_Follow')) return ['ok' => true, 'available' => false];
        return ['ok' => true, 'available' => true, 'enabled' => TIX_Mail_Follow::enabled(), 'active' => TIX_Mail_Follow::active_count(),
            'hint' => 'Erst einschalten, wenn der Datenschutz-Absatz zu „Folgen per E-Mail“ veröffentlicht ist.'];
    }

    public static function rest_follow(WP_REST_Request $req) { return rest_ensure_response(self::follow_payload()); }

    public static function rest_follow_save(WP_REST_Request $req) {
        if (!class_exists('TIX_Mail_Follow')) return new WP_Error('tix_unavailable', 'Nicht verfügbar.', ['status' => 500]);
        $b = self::body($req);
        if (!array_key_exists('enabled', $b)) return self::bad('enabled', 'enabled fehlt.');
        $on = filter_var($b['enabled'], FILTER_VALIDATE_BOOLEAN);
        update_option(TIX_Mail_Follow::OPTION, ['enabled' => $on ? 1 : 0], false);
        self::audit('platform_mail_follow', 0, ['enabled' => $on]);
        return rest_ensure_response(self::follow_payload());
    }

    // ═══════════════ P10 Sperren ═══════════════

    /** Rein: Zeilen prüfen (IP oder Präfix mit Punkt am Ende) → [liste, null] | [null, index] */
    public static function clean_ips($in) {
        if (!is_array($in) || count($in) > 2000) return [null, -1];
        $out = [];
        foreach (array_values($in) as $i => $l) {
            $l = trim((string) $l);
            if ($l === '') continue;
            if (!filter_var($l, FILTER_VALIDATE_IP) && !preg_match('/^[0-9a-fA-F.:]+\.$/', $l)) return [null, $i];
            $out[] = $l;
        }
        return [array_values(array_unique($out)), null];
    }

    /** E-Mail (klein) oder Name → [liste, null] | [null, index] */
    public static function clean_customers($in) {
        if (!is_array($in) || count($in) > 2000) return [null, -1];
        $out = [];
        foreach (array_values($in) as $i => $l) {
            $l = trim((string) $l);
            if ($l === '') continue;
            if (mb_strlen($l) > 190) return [null, $i];
            if (strpos($l, '@') !== false) { $e = sanitize_email($l); if (!is_email($e)) return [null, $i]; $out[] = strtolower($e); }
            else $out[] = sanitize_text_field($l);
        }
        return [array_values(array_unique($out)), null];
    }

    private static function blocks_payload() {
        if (!class_exists('TIX_IP_Block')) return ['ok' => true, 'available' => false];
        $ips = get_option(TIX_IP_Block::OPTION, []); $cu = get_option(TIX_IP_Block::OPTION_CUSTOMERS, []);
        return ['ok' => true, 'available' => true, 'ips' => array_values(is_array($ips) ? $ips : []), 'customers' => array_values(is_array($cu) ? $cu : []),
            'hint' => 'IP oder Präfix mit Punkt am Ende (z. B. 84.143.). Angemeldete Admins werden nie gesperrt.'];
    }

    public static function rest_blocks(WP_REST_Request $req) { $r = rest_ensure_response(self::blocks_payload()); $r->header('Cache-Control', 'no-store'); return $r; }

    public static function rest_blocks_save(WP_REST_Request $req) {
        if (!class_exists('TIX_IP_Block')) return new WP_Error('tix_unavailable', 'Nicht verfügbar.', ['status' => 500]);
        $b = self::body($req); $w = [];
        if (array_key_exists('ips', $b)) { [$l, $i] = self::clean_ips($b['ips']); if ($l === null) return self::bad($i < 0 ? 'ips' : 'ips.' . $i, $i < 0 ? 'Liste mit höchstens 2000 Einträgen.' : 'Keine gültige IP bzw. kein Präfix mit Punkt am Ende.'); $w[TIX_IP_Block::OPTION] = $l; }
        if (array_key_exists('customers', $b)) { [$l, $i] = self::clean_customers($b['customers']); if ($l === null) return self::bad($i < 0 ? 'customers' : 'customers.' . $i, $i < 0 ? 'Liste mit höchstens 2000 Einträgen.' : 'Ungültige E-Mail bzw. zu lang.'); $w[TIX_IP_Block::OPTION_CUSTOMERS] = $l; }
        foreach ($w as $o => $v) update_option($o, $v, false);
        self::audit('platform_blocks', 0, ['ips' => isset($w[TIX_IP_Block::OPTION]) ? count($w[TIX_IP_Block::OPTION]) : null, 'customers' => isset($w[TIX_IP_Block::OPTION_CUSTOMERS]) ? count($w[TIX_IP_Block::OPTION_CUSTOMERS]) : null]);
        return self::rest_blocks($req);
    }
}

TIX_Platform_Content::init();
