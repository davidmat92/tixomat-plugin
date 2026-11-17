<?php
/**
 * TIX_Platform_Ops – Plattform-Akte W6c (1.38.889): Sponsoren-Zugänge (P9), Domains + Website-Paket (P11), Awareness-Test (P12).
 * Vertrag: uebergaben/vertrag-plattform-akte-w6-2026-11-17.md (ENDGÜLTIGE FORM W6c), DOCUMENTATION.md §248.
 *
 * P9  /studio/sponsor-accounts…   Admin; Einzel-Seite auch Inhaber (tix_organizer). Sponsoren gelten für die ganze Seite.
 *     GET  /studio/sponsor-accounts                         Liste mit Kontingenten
 *     POST /studio/sponsor-accounts                         anlegen {name, email, contact_name?, notes?, coupon_code?}
 *     POST /studio/sponsor-accounts/{id}                    ändern (+ status active|inactive)
 *     DELETE /studio/sponsor-accounts/{id}                  deaktivieren (Tickets bleiben)
 *     GET  /studio/sponsor-accounts/{id}/pools              Kontingente
 *     POST /studio/sponsor-accounts/{id}/pools              {event_id, cat_index, total} oder {event_id, new_category: {name, qty, admin_only?}, total}
 *     DELETE /studio/sponsor-accounts/{id}/pools/{pool}
 *     POST /studio/sponsor-accounts/{id}/send-login         Login-Link per Mail an den Sponsor
 * P11 /platform/domain-settings (nur Plattform-Admin)      {cname_target, a_records[], aaaa_records[], ask_secret (nur schreiben)}
 *     POST /platform/organizers/{id}/domain-access          {allowed, fee_note}
 *     GET|POST /studio/website-package (Admin, nur Einzel-Seiten) {enabled}
 * P12 /awareness/test (Admin, alle Seiten)                 GET Stand · POST {action: start|cleanup|tester, minutes?, user?, enabled?}
 *     Notrufnummern: bestehend über POST /app/config nav.sos (kein Bau).
 */
if (!defined('ABSPATH')) exit;

class TIX_Platform_Ops {

    const NS = 'tixomat/v1';

    public static function init() { add_action('rest_api_init', [__CLASS__, 'routes']); }

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }

    public static function routes() {
        $sp = ['permission_callback' => [__CLASS__, 'perm_sponsor']];
        $pl = ['permission_callback' => ['TIX_Platform_Admin', 'perm']];
        $ad = ['permission_callback' => [__CLASS__, 'perm_admin']];
        $r = function ($path, $method, $cb, $p) { register_rest_route(self::NS, $path, ['methods' => $method, 'callback' => [__CLASS__, $cb]] + $p); };
        $r('/studio/sponsor-accounts', 'GET', 'rest_sponsors', $sp);
        $r('/studio/sponsor-accounts', 'POST', 'rest_sponsor_save', $sp);
        $r('/studio/sponsor-accounts/(?P<id>\d+)', 'POST', 'rest_sponsor_save', $sp);
        $r('/studio/sponsor-accounts/(?P<id>\d+)', 'DELETE', 'rest_sponsor_delete', $sp);
        $r('/studio/sponsor-accounts/(?P<id>\d+)/pools', 'GET', 'rest_pools', $sp);
        $r('/studio/sponsor-accounts/(?P<id>\d+)/pools', 'POST', 'rest_pool_add', $sp);
        $r('/studio/sponsor-accounts/(?P<id>\d+)/pools/(?P<pool>\d+)', 'DELETE', 'rest_pool_delete', $sp);
        $r('/studio/sponsor-accounts/(?P<id>\d+)/send-login', 'POST', 'rest_send_login', $sp);
        $r('/platform/domain-settings', 'GET', 'rest_domain_settings', $pl);
        $r('/platform/domain-settings', 'POST', 'rest_domain_settings_save', $pl);
        $r('/platform/organizers/(?P<id>\d+)/domain-access', 'POST', 'rest_domain_access', $pl);
        $r('/studio/website-package', 'GET', 'rest_website_package', $ad);
        $r('/studio/website-package', 'POST', 'rest_website_package_save', $ad);
        $r('/awareness/test', 'GET', 'rest_aw_test', $ad);
        $r('/awareness/test', 'POST', 'rest_aw_test_action', $ad);
    }

    public static function perm_admin() {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]);
        return current_user_can('manage_options') ? true : new WP_Error('rest_forbidden', 'Nur für Admins.', ['status' => 403]);
    }

    /** Sponsoren: Admin; Einzel-Seite auch Inhaber (evendis: Sponsoren sind seitenweit → nur Admin) */
    public static function perm_sponsor() {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]);
        if (current_user_can('manage_options')) return true;
        if (!self::multi() && in_array('tix_organizer', (array) wp_get_current_user()->roles, true)) return true;
        return new WP_Error('rest_forbidden', 'Sponsoren-Zugänge verwalten nur Inhaber bzw. Admins.', ['status' => 403]);
    }

    private static function bad($field, $msg, $code = 'tix_invalid_field', $status = 400) { return new WP_Error($code, $msg, ['status' => $status, 'field' => $field]); }
    private static function body(WP_REST_Request $req) { $b = $req->get_json_params(); return is_array($b) ? $b : (array) $req->get_body_params(); }
    private static function audit($action, $subject, array $summary) {
        if (class_exists('TIX_Customers') && method_exists('TIX_Customers', 'audit')) TIX_Customers::audit($action, $subject, ['scope' => self::multi() ? 'platform' : 'site', 'oid' => 0], $summary);
    }

    // ═══════════════ P9 Sponsoren ═══════════════

    private static function sponsor_ready() {
        if (!class_exists('TIX_Sponsor_DB')) return new WP_Error('tix_unavailable', 'Sponsoren-Modul nicht verfügbar.', ['status' => 500]);
        if (!TIX_Sponsor_DB::tables_exist()) TIX_Sponsor_DB::create_tables();
        return true;
    }

    private static function sponsor_item($r) {
        global $wpdb;
        $tp = TIX_Sponsor_DB::table_pools();
        $sum = $wpdb->get_row($wpdb->prepare("SELECT COALESCE(SUM(total),0) tot, COALESCE(SUM(used),0) usd FROM $tp WHERE sponsor_id = %d", $r->id));
        return ['id' => intval($r->id), 'name' => (string) $r->name, 'contact_name' => (string) $r->contact_name, 'email' => (string) $r->email, 'notes' => (string) $r->notes,
            'status' => (string) $r->status, 'coupon_code' => (string) ($r->coupon_code ?? ''), 'has_password' => (string) ($r->password_hash ?? '') !== '',
            'total' => intval($sum->tot ?? 0), 'used' => intval($sum->usd ?? 0), 'available' => max(0, intval($sum->tot ?? 0) - intval($sum->usd ?? 0))];
    }

    public static function rest_sponsors(WP_REST_Request $req) {
        if (is_wp_error($e = self::sponsor_ready())) return $e;
        return rest_ensure_response(['ok' => true, 'sponsors' => array_values(array_map([__CLASS__, 'sponsor_item'], (array) TIX_Sponsor_DB::get_all_sponsors())),
            'dashboard_url' => class_exists('TIX_Sponsor_Auth') ? TIX_Sponsor_Auth::get_sponsor_page_url() : '']);
    }

    public static function rest_sponsor_save(WP_REST_Request $req) {
        if (is_wp_error($e = self::sponsor_ready())) return $e;
        $id = absint($req['id'] ?? 0);
        $cur = $id ? TIX_Sponsor_DB::get_sponsor($id) : null;
        if ($id && !$cur) return new WP_Error('not_found', 'Sponsor nicht gefunden.', ['status' => 404]);
        $b = self::body($req); $d = [];
        foreach (['name' => 190, 'contact_name' => 190] as $k => $max) if (array_key_exists($k, $b)) { $v = trim(sanitize_text_field((string) $b[$k])); if (mb_strlen($v) > $max) return self::bad($k, 'Zu lang.'); $d[$k] = $v; }
        if (array_key_exists('notes', $b)) { $v = sanitize_textarea_field((string) $b['notes']); if (mb_strlen($v) > 2000) return self::bad('notes', 'Höchstens 2000 Zeichen.'); $d['notes'] = $v; }
        if (array_key_exists('email', $b)) { $v = sanitize_email((string) $b['email']); if (!is_email($v)) return self::bad('email', 'Bitte eine gültige E-Mail-Adresse.'); $d['email'] = $v; }
        if (array_key_exists('coupon_code', $b)) {
            $v = strtoupper(trim(sanitize_text_field((string) $b['coupon_code'])));
            if ($v !== '' && (!class_exists('TIX_Coupons') || !TIX_Coupons::find_by_code($v))) return self::bad('coupon_code', 'Gutschein-Code „' . $v . '“ gibt es nicht – bitte zuerst unter Gutscheine anlegen.');
            $d['coupon_code'] = $v;
        }
        if ($id && array_key_exists('status', $b)) { if (!in_array($b['status'], ['active', 'inactive'], true)) return self::bad('status', 'active oder inactive.'); $d['status'] = $b['status']; }
        if (!$id) {
            if (($d['name'] ?? '') === '') return self::bad('name', 'Bitte einen Namen.');
            if (empty($d['email'])) return self::bad('email', 'Bitte eine E-Mail-Adresse.');
        } elseif (array_key_exists('name', $d) && $d['name'] === '') return self::bad('name', 'Bitte einen Namen.');
        if (!empty($d['email'])) {
            $ex = TIX_Sponsor_DB::get_sponsor_by_email($d['email']);
            if ($ex && intval($ex->id) !== $id) return self::bad('email', 'Diese E-Mail ist schon als Sponsor eingetragen.', 'tix_duplicate', 409);
        }
        if ($id) TIX_Sponsor_DB::update_sponsor($id, $d);
        else $id = TIX_Sponsor_DB::insert_sponsor($d + ['status' => 'active']);
        self::audit($cur ? 'sponsor_update' : 'sponsor_create', $id, ['fields' => array_keys($d)]);
        return rest_ensure_response(['ok' => true, 'sponsor' => self::sponsor_item(TIX_Sponsor_DB::get_sponsor($id))]);
    }

    public static function rest_sponsor_delete(WP_REST_Request $req) {
        if (is_wp_error($e = self::sponsor_ready())) return $e;
        $id = absint($req['id']);
        if (!TIX_Sponsor_DB::get_sponsor($id)) return new WP_Error('not_found', 'Sponsor nicht gefunden.', ['status' => 404]);
        TIX_Sponsor_DB::delete_sponsor($id); // = deaktivieren, Tickets und Kontingente bleiben
        self::audit('sponsor_deactivate', $id, []);
        return rest_ensure_response(['ok' => true, 'sponsor' => self::sponsor_item(TIX_Sponsor_DB::get_sponsor($id))]);
    }

    private static function pool_item($p) {
        return ['id' => intval($p->id), 'event_id' => intval($p->event_id), 'event_title' => (string) ($p->event_title ?? '') ?: html_entity_decode(get_the_title(intval($p->event_id)), ENT_QUOTES, 'UTF-8'),
            'cat_index' => intval($p->cat_index), 'cat_name' => (string) $p->cat_name, 'total' => intval($p->total), 'used' => intval($p->used), 'available' => max(0, intval($p->total) - intval($p->used))];
    }

    public static function rest_pools(WP_REST_Request $req) {
        if (is_wp_error($e = self::sponsor_ready())) return $e;
        $id = absint($req['id']);
        if (!TIX_Sponsor_DB::get_sponsor($id)) return new WP_Error('not_found', 'Sponsor nicht gefunden.', ['status' => 404]);
        return rest_ensure_response(['ok' => true, 'pools' => array_values(array_map([__CLASS__, 'pool_item'], (array) TIX_Sponsor_DB::get_pools_by_sponsor($id)))]);
    }

    public static function rest_pool_add(WP_REST_Request $req) {
        if (is_wp_error($e = self::sponsor_ready())) return $e;
        $sid = absint($req['id']);
        if (!TIX_Sponsor_DB::get_sponsor($sid)) return new WP_Error('not_found', 'Sponsor nicht gefunden.', ['status' => 404]);
        $b = self::body($req);
        $eid = absint($b['event_id'] ?? 0);
        if (!$eid || get_post_type($eid) !== 'event') return self::bad('event_id', 'Event nicht gefunden.');
        if (!TIX_REST_API::user_can_access_event($eid)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        $total = intval($b['total'] ?? 0);
        if ($total < 1 || $total > 10000) return self::bad('total', 'Anzahl 1–10.000.');
        $cats = get_post_meta($eid, '_tix_ticket_categories', true); $cats = is_array($cats) ? $cats : [];
        if (isset($b['new_category']) && is_array($b['new_category'])) {
            $name = trim(sanitize_text_field((string) ($b['new_category']['name'] ?? '')));
            if ($name === '' || mb_strlen($name) > 120) return self::bad('new_category.name', 'Bitte einen Namen (höchstens 120 Zeichen).');
            foreach ($cats as $c) if (strtolower(trim((string) ($c['name'] ?? ''))) === strtolower($name)) return self::bad('new_category.name', 'Eine Kategorie mit diesem Namen gibt es schon.', 'tix_duplicate', 409);
            $qty = max(0, min(100000, intval($b['new_category']['qty'] ?? $total)));
            $cats[] = ['name' => $name, 'price' => 0, 'sale_price' => '', 'qty' => $qty, 'desc' => '', 'image_id' => 0, 'online' => '0', 'offline_ticket' => '0',
                'admin_only' => !array_key_exists('admin_only', $b['new_category']) || !empty($b['new_category']['admin_only']) ? 1 : 0,
                'bundle_buy' => 0, 'bundle_pay' => 0, 'bundle_label' => '', 'tc_event_id' => 0, 'product_id' => 0, 'sku' => '', 'group' => '', 'seatmap_id' => 0, 'seatmap_section' => '', 'phases' => []];
            update_post_meta($eid, '_tix_ticket_categories', $cats);
            $ci = count($cats) - 1;
        } else {
            $ci = isset($b['cat_index']) ? intval($b['cat_index']) : -1;
            if (!isset($cats[$ci])) return self::bad('cat_index', 'Ticket-Art nicht gefunden.');
        }
        $pid = TIX_Sponsor_DB::insert_pool(['sponsor_id' => $sid, 'event_id' => $eid, 'cat_index' => $ci, 'cat_name' => (string) ($cats[$ci]['name'] ?? ''), 'total' => $total]);
        self::audit('sponsor_pool_add', $sid, ['event_id' => $eid, 'total' => $total]);
        return rest_ensure_response(['ok' => true, 'pool_id' => intval($pid)] + (array) self::rest_pools($req)->get_data());
    }

    public static function rest_pool_delete(WP_REST_Request $req) {
        if (is_wp_error($e = self::sponsor_ready())) return $e;
        $sid = absint($req['id']); $pid = absint($req['pool']);
        $p = TIX_Sponsor_DB::get_pool($pid);
        if (!$p || intval($p->sponsor_id) !== $sid) return new WP_Error('not_found', 'Kontingent nicht gefunden.', ['status' => 404]);
        if (intval($p->used) > 0) return new WP_Error('tix_pool_used', 'Aus diesem Kontingent sind schon Tickets ausgestellt – bitte die Anzahl nicht über das Löschen ändern.', ['status' => 409]);
        TIX_Sponsor_DB::delete_pool($pid);
        self::audit('sponsor_pool_delete', $sid, ['pool' => $pid]);
        return rest_ensure_response(['ok' => true] + (array) self::rest_pools($req)->get_data());
    }

    public static function rest_send_login(WP_REST_Request $req) {
        if (is_wp_error($e = self::sponsor_ready())) return $e;
        $s = TIX_Sponsor_DB::get_sponsor(absint($req['id']));
        if (!$s) return new WP_Error('not_found', 'Sponsor nicht gefunden.', ['status' => 404]);
        if ($s->status !== 'active') return new WP_Error('tix_inactive', 'Der Sponsor ist deaktiviert.', ['status' => 409]);
        if (!class_exists('TIX_Sponsor_Auth') || !method_exists('TIX_Sponsor_Auth', 'send_login_link')) return new WP_Error('tix_unavailable', 'Login-Mail nicht verfügbar.', ['status' => 500]);
        if (!TIX_Sponsor_Auth::send_login_link(intval($s->id))) return new WP_Error('tix_mail_failed', 'Die Mail konnte nicht gesendet werden.', ['status' => 500]);
        self::audit('sponsor_send_login', intval($s->id), []);
        return rest_ensure_response(['ok' => true, 'email' => (string) $s->email]);
    }

    // ═══════════════ P11 Domains, Website-Paket ═══════════════

    private static function domain_payload() {
        if (!class_exists('TIX_Org_Domains')) return ['ok' => true, 'available' => false];
        $s = TIX_Org_Domains::settings();
        return ['ok' => true, 'available' => true, 'cname_target' => $s['cname_target'], 'a_records' => $s['a_records'], 'aaaa_records' => $s['aaaa_records'], 'ask_secret_set' => $s['ask_secret'] !== ''];
    }

    public static function rest_domain_settings(WP_REST_Request $req) { $r = rest_ensure_response(self::domain_payload()); $r->header('Cache-Control', 'no-store'); return $r; }

    public static function rest_domain_settings_save(WP_REST_Request $req) {
        if (!class_exists('TIX_Org_Domains')) return new WP_Error('tix_unavailable', 'Nicht verfügbar.', ['status' => 500]);
        $b = self::body($req); $s = TIX_Org_Domains::settings(); $changed = [];
        if (array_key_exists('cname_target', $b)) {
            $t = strtolower(trim((string) $b['cname_target'], " .\t\n"));
            if ($t === '' || !preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $t)) return self::bad('cname_target', 'Hostname wie domains.evendis.de.');
            $s['cname_target'] = $t; $changed[] = 'cname_target';
        }
        foreach (['a_records' => FILTER_FLAG_IPV4, 'aaaa_records' => FILTER_FLAG_IPV6] as $k => $flag) {
            if (!array_key_exists($k, $b)) continue;
            if (!is_array($b[$k]) || count($b[$k]) > 8) return self::bad($k, 'Liste mit höchstens 8 Adressen.');
            $out = [];
            foreach (array_values($b[$k]) as $i => $ip) { $ip = trim((string) $ip); if ($ip === '') continue; if (!filter_var($ip, FILTER_VALIDATE_IP, $flag)) return self::bad($k . '.' . $i, $k === 'a_records' ? 'IPv4-Adresse erwartet.' : 'IPv6-Adresse erwartet.'); $out[] = $ip; }
            $s[$k] = array_values(array_unique($out)); $changed[] = $k;
        }
        if (array_key_exists('ask_secret', $b)) { // nur schreiben: leer = entfernen, Wert = setzen; zurück nur ask_secret_set
            $v = trim((string) $b['ask_secret']);
            if ($v !== '' && (strlen($v) < 16 || strlen($v) > 128)) return self::bad('ask_secret', '16–128 Zeichen oder leer.');
            $s['ask_secret'] = sanitize_text_field($v); $changed[] = 'ask_secret';
        }
        update_option(TIX_Org_Domains::OPTION, $s, false);
        self::audit('platform_domain_settings', 0, ['fields' => $changed]);
        return self::rest_domain_settings($req);
    }

    public static function rest_domain_access(WP_REST_Request $req) {
        if (!class_exists('TIX_Org_Domains')) return new WP_Error('tix_unavailable', 'Nicht verfügbar.', ['status' => 500]);
        $oid = absint($req['id']);
        if (get_post_type($oid) !== 'tix_organizer') return new WP_Error('not_found', 'Veranstalter nicht gefunden.', ['status' => 404]);
        $b = self::body($req);
        if (array_key_exists('allowed', $b)) { if (filter_var($b['allowed'], FILTER_VALIDATE_BOOLEAN)) update_post_meta($oid, TIX_Org_Domains::META_ALLOW, 1); else delete_post_meta($oid, TIX_Org_Domains::META_ALLOW); }
        if (array_key_exists('fee_note', $b)) { $v = trim(sanitize_text_field((string) $b['fee_note'])); if (mb_strlen($v) > 120) return self::bad('fee_note', 'Höchstens 120 Zeichen.'); update_post_meta($oid, TIX_Org_Domains::META_FEE, $v); }
        self::audit('platform_domain_access', $oid, ['allowed' => TIX_Org_Domains::allowed($oid)]);
        return rest_ensure_response(['ok' => true, 'organizer_id' => $oid, 'allowed' => TIX_Org_Domains::allowed($oid), 'fee_note' => (string) get_post_meta($oid, TIX_Org_Domains::META_FEE, true),
            'domain' => TIX_Org_Domains::get($oid)]);
    }

    public static function rest_website_package(WP_REST_Request $req) {
        if (self::multi()) return new WP_Error('rest_no_route', 'Auf der Plattform gibt es kein Website-Paket.', ['status' => 404]);
        if (!class_exists('TIX_Website')) return new WP_Error('tix_unavailable', 'Nicht verfügbar.', ['status' => 500]);
        return rest_ensure_response(['ok' => true, 'enabled' => TIX_Website::package_enabled(), 'hint' => 'Zeigt im Studio den Bereich „Website“. Ausschalten blendet ihn aus; Inhalte und die veröffentlichte Website bleiben.']);
    }

    public static function rest_website_package_save(WP_REST_Request $req) {
        if (self::multi()) return new WP_Error('rest_no_route', 'Auf der Plattform gibt es kein Website-Paket.', ['status' => 404]);
        if (!class_exists('TIX_Website')) return new WP_Error('tix_unavailable', 'Nicht verfügbar.', ['status' => 500]);
        $b = self::body($req);
        if (!array_key_exists('enabled', $b)) return self::bad('enabled', 'enabled fehlt.');
        $on = filter_var($b['enabled'], FILTER_VALIDATE_BOOLEAN);
        update_option(TIX_Website::OPT_ENABLED, $on ? '1' : '0', false);
        self::audit('website_package', 0, ['enabled' => $on]);
        return self::rest_website_package($req);
    }

    // ═══════════════ P12 Awareness-Test ═══════════════

    private static function aw_payload() {
        if (!class_exists('TIX_Awareness_Test')) return ['ok' => true, 'available' => false];
        $events = array_values(array_map(function ($id) {
            $m = function ($k) use ($id) { return (string) get_post_meta($id, $k, true); };
            return ['id' => intval($id), 'start' => trim($m('_tix_date_start') . ' ' . $m('_tix_time_start')), 'end' => trim($m('_tix_date_end') . ' ' . $m('_tix_time_end'))];
        }, (array) TIX_Awareness_Test::event_ids()));
        $testers = array_values(array_map(function ($u) { return ['id' => intval($u->ID), 'login' => (string) $u->user_login, 'name' => (string) $u->display_name]; },
            get_users(['meta_key' => '_tix_awareness_tester', 'meta_value' => '1', 'number' => 100])));
        $flags = class_exists('TIX_Awareness_Live') ? (array) TIX_Awareness_Live::flags() : [];
        return ['ok' => true, 'available' => true, 'events' => $events, 'testers' => $testers, 'alarm_live' => !empty($flags['alarm_live']),
            'hint' => 'Das Test-Event ist unsichtbar für Gäste; „Hilfe vor Ort“ braucht trotzdem den Schalter Live-Alarm.'];
    }

    public static function rest_aw_test(WP_REST_Request $req) { return rest_ensure_response(self::aw_payload()); }

    public static function rest_aw_test_action(WP_REST_Request $req) {
        if (!class_exists('TIX_Awareness_Test')) return new WP_Error('tix_unavailable', 'Nicht verfügbar.', ['status' => 500]);
        $b = self::body($req);
        $a = (string) ($b['action'] ?? '');
        if ($a === 'start') {
            $min = intval($b['minutes'] ?? 60);
            if ($min < 10 || $min > 720) return self::bad('minutes', '10–720 Minuten.');
            $id = TIX_Awareness_Test::create($min, intval($b['organizer_id'] ?? 0));
            if (is_wp_error($id)) return new WP_Error('tix_aw_test', $id->get_error_message(), ['status' => 500]);
            self::audit('awareness_test_start', $id, ['minutes' => $min]);
            return rest_ensure_response(['started' => intval($id)] + self::aw_payload());
        }
        if ($a === 'cleanup') {
            $r = TIX_Awareness_Test::cleanup(!array_key_exists('testers', $b) || !empty($b['testers']));
            self::audit('awareness_test_cleanup', 0, (array) $r);
            return rest_ensure_response(['cleaned' => (array) $r] + self::aw_payload());
        }
        if ($a === 'tester') {
            $u = TIX_Awareness_Test::find_user((string) ($b['user'] ?? ''));
            if (!$u) return self::bad('user', 'Konto nicht gefunden (Login, E-Mail oder ID).', 'not_found', 404);
            $on = !array_key_exists('enabled', $b) || filter_var($b['enabled'], FILTER_VALIDATE_BOOLEAN);
            TIX_Awareness_Test::set_tester($u, $on);
            self::audit('awareness_tester', intval($u->ID), ['enabled' => $on]);
            return rest_ensure_response(self::aw_payload());
        }
        return self::bad('action', 'start, cleanup oder tester.');
    }
}

TIX_Platform_Ops::init();
