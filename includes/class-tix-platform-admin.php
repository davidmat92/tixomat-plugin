<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Platform_Admin – Plattform-Admin-Studio, Paket 1 (evendis, nur Plattform-Admins; 1.38.480).
 * Vertrag wie tixomat-apps/apps/veranstalter-web/src/lib/server/platform.ts (Kopfkommentar).
 *
 * Veranstalter:
 *   GET  /platform/organizers?status=pending|approved|rejected|blocked|all&q=
 *        → {organizers: [{id, name, slug, city, logo, status, created, owner {name, email}, events, upcoming, modules {key: bool}, requirements [{key, label, done}]}]}
 *   GET  /platform/organizers/{id} → {organizer: {…, description, website, phone, reason, terms {version, accepted_at}|null, payout {complete, iban_masked}|null, events_list [{id, title, date_start, status}]}}
 *   POST /platform/organizers/{id}/approve · /reject {reason} · /block {reason} · /unblock → {ok, status}
 *   POST /platform/organizers/{id}/modules {modules: {key: bool}} → {ok, modules}
 * Abrechnungen (TIX_Settlement; Studio „released“ = Plugin „approved“):
 *   GET  /platform/settlements?status=draft|ready|released|paid|held|all&q=
 *        → {settlements: [{id, number, organizer {id, name}, event {id, title, date_start}, status, gross, refunds, fee, payment_fees, payout, created, note}], totals {open, released, paid}}
 *   POST /platform/settlements/{id}/release · /paid {ref?, date?} · /hold {reason} → {ok, status}
 * Betreiber-Support („Hilfe & Support“ aus dem Studio aller Seiten):
 *   GET  /platform/support?status=open|all → {tickets: [{id, subject, status, site, organizer {id, name}, from {name, email}, last_message, last_from, updated, unread}]}
 *   GET  /platform/support/{id} → {ticket: {…, messages [{id, from: organizer|team, author, content, date}]}}
 *   POST /platform/support/{id}/reply {message, status?} · /status {status} → {ok, status}
 *   Anfragen entstehen über POST /customer/support mit Staff-Konto (Studio „Hilfe & Support“, Markierung `_tix_sp_help`):
 *   auf evendis direkt, auf Partner-Seiten über die Partner-Brücke (POST /syndicate/help, /syndicate/help/{id}/messages),
 *   Antworten gehen an die Seite zurück (POST /partner/help/{id}/reply). Hilfe-Anfragen erscheinen nicht im Kunden-Postfach.
 */
class TIX_Platform_Admin {

    const NS = 'tixomat/v1';
    const MODULE_KEYS = ['tickets', 'guestlist', 'pos', 'coupons', 'loyalty', 'wallet', 'giftcards', 'music', 'awareness', 'support', 'muttizettel'];
    const HELP_META = '_tix_sp_help';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('tix_support_message_added', [__CLASS__, 'on_message'], 30, 2);
        add_action('tix_support_status_changed', [__CLASS__, 'on_status'], 30, 3);
    }

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function perm(WP_REST_Request $req) {
        if (!self::multi()) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        if (!current_user_can('manage_options')) return new WP_Error('rest_forbidden', 'Nur für Plattform-Admins.', ['status' => 403]);
        return true;
    }

    public static function routes() {
        $p = ['permission_callback' => [__CLASS__, 'perm']];
        $r = function ($path, $method, $cb) use ($p) { register_rest_route(self::NS, $path, ['methods' => $method, 'callback' => [__CLASS__, $cb]] + $p); };
        $r('/platform/organizers', 'GET', 'rest_orgs');
        $r('/platform/organizers/(?P<id>\d+)', 'GET', 'rest_org');
        $r('/platform/organizers/(?P<id>\d+)/(?P<action>approve|reject|block|unblock)', 'POST', 'rest_org_action');
        $r('/platform/organizers/(?P<id>\d+)/modules', 'POST', 'rest_org_modules');
        $r('/platform/settlements', 'GET', 'rest_settlements');
        $r('/platform/settlements/(?P<id>\d+)/(?P<action>release|paid|hold)', 'POST', 'rest_settlement_action');
        $r('/platform/support', 'GET', 'rest_support');
        $r('/platform/support/(?P<id>\d+)', 'GET', 'rest_support_one');
        $r('/platform/support/(?P<id>\d+)/reply', 'POST', 'rest_support_reply');
        $r('/platform/support/(?P<id>\d+)/status', 'POST', 'rest_support_status');
        // Partner-Brücke für „Hilfe & Support“
        register_rest_route(self::NS, '/syndicate/help', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_bridge_create'], 'permission_callback' => [__CLASS__, 'perm_partner']]);
        register_rest_route(self::NS, '/syndicate/help/(?P<id>\d+)/messages', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_bridge_message'], 'permission_callback' => [__CLASS__, 'perm_partner']]);
        register_rest_route(self::NS, '/partner/help/(?P<id>\d+)/reply', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_bridge_reply_in'], 'permission_callback' => ['TIX_Support_Model', 'perm_from_platform']]);
    }

    private static function bad($field, $msg, $status = 400, $code = 'tix_invalid_field') {
        return new WP_Error($code, $msg, ['status' => $status, 'field' => $field]);
    }

    private static function dt($mysql) {
        return $mysql ? mysql2date('Y-m-d H:i', $mysql, false) : '';
    }

    // ═══════════════ Veranstalter ═══════════════

    public static function modules($oid) {
        $raw = get_post_meta($oid, '_tix_org_modules', true);
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        $raw = is_array($raw) ? $raw : [];
        $base = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::modules($oid) : [];
        $out = [];
        foreach (self::MODULE_KEYS as $k) {
            if (array_key_exists($k, $raw)) $out[$k] = (bool) $raw[$k];
            elseif (array_key_exists($k, $base)) $out[$k] = (bool) $base[$k];
            else $out[$k] = true; // Gästeliste, Abendkasse, Rabatt-Codes, Awareness: bisher immer verfügbar
        }
        $out['tickets'] = true;
        return $out;
    }

    private static function owner($oid) {
        $u = get_userdata(intval(get_post_meta($oid, '_tix_org_user_id', true)));
        return ['name' => $u ? (string) $u->display_name : '', 'email' => $u ? (string) $u->user_email : (string) get_post_meta($oid, '_tix_org_email', true)];
    }

    private static function event_counts($oid) {
        global $wpdb;
        $all = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_tix_organizer_id' AND m.meta_value = %s WHERE p.post_type = 'event' AND p.post_status NOT IN ('trash', 'auto-draft')", (string) $oid)));
        $up = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_tix_organizer_id' AND m.meta_value = %s JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '_tix_date_start' AND d.meta_value >= %s WHERE p.post_type = 'event' AND p.post_status IN ('publish', 'pending', 'future')", (string) $oid, current_time('Y-m-d'))));
        return [$all, $up];
    }

    private static function logo($oid) {
        foreach (['_tix_org_landing_logo_id', '_tix_org_image_id'] as $k) {
            $id = intval(get_post_meta($oid, $k, true));
            if ($id && ($u = wp_get_attachment_image_url($id, 'medium'))) return (string) $u;
        }
        return '';
    }

    private static function city($oid) {
        $c = (string) get_post_meta($oid, '_tix_org_city', true);
        if ($c === '' && class_exists('TIX_Public_Platform')) list($c) = TIX_Public_Platform::parse_city((string) get_post_meta($oid, '_tix_org_address', true));
        return (string) $c;
    }

    public static function org_row($oid) {
        $p = get_post($oid);
        list($all, $up) = self::event_counts($oid);
        $req = [];
        if (class_exists('TIX_Org_Approval')) foreach (TIX_Org_Approval::requirements($oid) as $it) $req[] = ['key' => (string) $it['key'], 'label' => (string) $it['label'], 'done' => (bool) $it['done']];
        return [
            'id' => intval($oid), 'name' => html_entity_decode((string) $p->post_title, ENT_QUOTES, 'UTF-8'),
            'slug' => class_exists('TIX_Public_Platform') ? (string) TIX_Public_Platform::slug($oid) : (string) $p->post_name,
            'city' => self::city($oid), 'logo' => self::logo($oid),
            'status' => class_exists('TIX_Org_Approval') ? TIX_Org_Approval::status($oid) : 'approved',
            'created' => self::dt($p->post_date), 'owner' => self::owner($oid), 'events' => $all, 'upcoming' => $up,
            'modules' => self::modules($oid), 'requirements' => $req,
        ];
    }

    public static function rest_orgs(WP_REST_Request $req) {
        $status = (string) ($req->get_param('status') ?: 'all');
        $q = mb_strtolower(trim((string) $req->get_param('q')));
        $ids = get_posts(['post_type' => 'tix_organizer', 'post_status' => ['publish', 'pending', 'draft', 'private'], 'posts_per_page' => 1000, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true]);
        $out = [];
        foreach ($ids as $oid) {
            $st = class_exists('TIX_Org_Approval') ? TIX_Org_Approval::status($oid) : 'approved';
            if ($status !== 'all' && $st !== $status) continue;
            $row = self::org_row($oid);
            if ($q !== '' && mb_strpos(mb_strtolower($row['name'] . ' ' . $row['city'] . ' ' . $row['owner']['email'] . ' ' . $row['owner']['name']), $q) === false) continue;
            $out[] = $row;
        }
        // Wartende zuerst (älteste oben), sonst neueste zuerst
        if ($status === 'pending') usort($out, function ($a, $b) { return strcmp($a['created'], $b['created']); });
        return rest_ensure_response(['ok' => true, 'organizers' => $out]);
    }

    private static function org_or_404($id) {
        $id = absint($id);
        return get_post_type($id) === 'tix_organizer' ? $id : new WP_Error('tix_not_found', 'Diesen Veranstalter gibt es nicht.', ['status' => 404]);
    }

    public static function rest_org(WP_REST_Request $req) {
        $oid = self::org_or_404($req['id']);
        if (is_wp_error($oid)) return $oid;
        $row = self::org_row($oid);
        $info = get_post_meta($oid, '_tix_org_approval_info', true);
        $pl = class_exists('TIX_Org_Approval') ? TIX_Org_Approval::payload($oid) : [];
        $terms = $pl['terms'] ?? null;
        $events = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'pending', 'draft', 'future', 'private'], 'posts_per_page' => 50, 'fields' => 'ids', 'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'order' => 'DESC',
            'meta_query' => [['key' => '_tix_organizer_id', 'value' => (string) $oid]], 'no_found_rows' => true]);
        $labels = ['publish' => 'Online', 'pending' => 'Wartet auf Freigabe', 'draft' => 'Entwurf', 'future' => 'Geplant', 'private' => 'Privat'];
        $list = [];
        foreach ($events as $eid) {
            $st = get_post_status($eid);
            if ($st === 'pending' && get_post_meta($eid, '_tix_publish_held', true) === '') $labels_st = 'Zur Prüfung'; else $labels_st = $labels[$st] ?? $st;
            $list[] = ['id' => intval($eid), 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'), 'date_start' => (string) get_post_meta($eid, '_tix_date_start', true), 'status' => $labels_st];
        }
        $pay = null;
        if (class_exists('TIX_Payout_Details')) {
            $iban = (string) TIX_Payout_Details::iban($oid);
            $pay = ['complete' => (bool) TIX_Payout_Details::complete($oid), 'iban_masked' => $iban !== '' ? (string) TIX_Payout_Details::mask_iban($iban) : ''];
        }
        $desc = (string) (get_post_meta($oid, '_tix_org_landing_description', true) ?: get_post_meta($oid, '_tix_org_description', true));
        $social = get_post_meta($oid, '_tix_org_landing_social', true);
        $row += [
            'description' => wp_strip_all_tags($desc),
            'website' => (string) ((is_array($social) ? ($social['website'] ?? '') : '') ?: get_post_meta($oid, '_tix_org_website', true)),
            'phone' => (string) (get_post_meta($oid, '_tix_org_phone', true) ?: get_post_meta($oid, '_tix_org_review_phone', true)),
            'reason' => is_array($info) ? (string) ($info['reason'] ?? '') : '',
            'terms' => is_array($terms) && !empty($terms['accepted']) ? ['version' => (string) ($terms['accepted_version'] ?? $terms['version'] ?? ''), 'accepted_at' => (string) ($terms['accepted_at'] ?? '')] : null,
            'payout' => $pay,
            'events_list' => $list,
        ];
        return rest_ensure_response(['ok' => true, 'organizer' => $row]);
    }

    public static function rest_org_action(WP_REST_Request $req) {
        $oid = self::org_or_404($req['id']);
        if (is_wp_error($oid)) return $oid;
        if (!class_exists('TIX_Org_Approval')) return new WP_Error('tix_unavailable', 'Freigabe nicht verfügbar.', ['status' => 409]);
        $action = (string) $req['action'];
        $reason = trim(sanitize_textarea_field((string) $req->get_param('reason')));
        $cur = TIX_Org_Approval::status($oid);
        if (in_array($action, ['reject', 'block'], true) && $reason === '') return self::bad('reason', 'Bitte eine Begründung angeben (geht an den Veranstalter).', 400, 'tix_reason_required');
        if ($action === 'unblock' && $cur !== 'blocked') return new WP_Error('tix_not_blocked', 'Der Veranstalter ist nicht gesperrt.', ['status' => 409]);
        $map = ['approve' => 'approved', 'unblock' => 'approved', 'reject' => 'rejected', 'block' => 'blocked'];
        $new = $map[$action];
        $missing = $new === 'approved' ? TIX_Org_Approval::review_missing($oid) : [];
        TIX_Org_Approval::set_status($oid, $new, ['reason' => $reason, 'forced' => (bool) $missing, 'note' => $action === 'unblock' ? 'Entsperrt (Plattform-Studio)' : 'Plattform-Studio']);
        return rest_ensure_response(['ok' => true, 'status' => TIX_Org_Approval::status($oid), 'forced' => (bool) $missing]);
    }

    public static function rest_org_modules(WP_REST_Request $req) {
        $oid = self::org_or_404($req['id']);
        if (is_wp_error($oid)) return $oid;
        $in = $req->get_param('modules');
        if (!is_array($in)) return self::bad('modules', 'Bitte Module als {key: true|false} senden.');
        $unknown = array_diff(array_keys($in), self::MODULE_KEYS);
        if ($unknown) return self::bad('modules.' . reset($unknown), 'Unbekanntes Modul: ' . reset($unknown) . '.');
        $raw = get_post_meta($oid, '_tix_org_modules', true);
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        $raw = is_array($raw) ? $raw : [];
        foreach ($in as $k => $v) $raw[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
        $raw['tickets'] = true;
        update_post_meta($oid, '_tix_org_modules', wp_json_encode($raw));
        if (class_exists('TIX_App_Scope') && method_exists('TIX_App_Scope', 'flush_public_cache')) TIX_App_Scope::flush_public_cache();
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
        return rest_ensure_response(['ok' => true, 'modules' => self::modules($oid)]);
    }

    // ═══════════════ Abrechnungen ═══════════════

    const ST_OUT = ['draft' => 'draft', 'ready' => 'ready', 'approved' => 'released', 'paid' => 'paid', 'held' => 'held'];

    private static function settlement_row($s) {
        $s = (object) $s;
        return [
            'id' => intval($s->id), 'number' => (string) ($s->number ?? ''),
            'organizer' => ['id' => intval($s->organizer_id), 'name' => html_entity_decode(get_the_title(intval($s->organizer_id)), ENT_QUOTES, 'UTF-8')],
            'event' => ['id' => intval($s->event_id), 'title' => (string) ($s->event_title ?: (intval($s->event_id) ? get_the_title(intval($s->event_id)) : '')), 'date_start' => intval($s->event_id) ? (string) get_post_meta(intval($s->event_id), '_tix_date_start', true) : ''],
            'type' => (string) ($s->type ?? 'event'),
            'status' => self::ST_OUT[$s->status] ?? (string) $s->status,
            'gross' => round(floatval($s->gross), 2), 'refunds' => round(floatval($s->refunds), 2), 'fee' => round(floatval($s->platform_fee), 2),
            'payment_fees' => round(floatval($s->gateway_fees), 2), 'payout' => round(floatval($s->payout_amount), 2),
            'created' => $s->created ? mysql2date('Y-m-d', $s->created, false) : '', 'note' => (string) ($s->held_reason ?: ($s->note ?? '')),
            'paid_at' => !empty($s->paid_at) ? mysql2date('Y-m-d', $s->paid_at, false) : null,
        ];
    }

    public static function rest_settlements(WP_REST_Request $req) {
        global $wpdb;
        $t = $wpdb->prefix . 'tix_settlements';
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) return rest_ensure_response(['ok' => true, 'settlements' => [], 'totals' => ['open' => 0, 'released' => 0, 'paid' => 0]]);
        $status = (string) ($req->get_param('status') ?: 'all');
        $in = array_flip(self::ST_OUT);
        $where = "status <> 'void'";
        if ($status !== 'all' && isset($in[$status])) $where = $wpdb->prepare('status = %s', $in[$status]);
        $rows = (array) $wpdb->get_results("SELECT * FROM $t WHERE $where ORDER BY created DESC LIMIT 500");
        $q = mb_strtolower(trim((string) $req->get_param('q')));
        $out = [];
        foreach ($rows as $s) {
            $row = self::settlement_row($s);
            if ($q !== '' && mb_strpos(mb_strtolower($row['organizer']['name'] . ' ' . $row['event']['title'] . ' ' . $row['number']), $q) === false) continue;
            $out[] = $row;
        }
        $sum = function ($st) use ($wpdb, $t) { return round(floatval($wpdb->get_var("SELECT SUM(payout_amount) FROM $t WHERE status IN ('" . implode("','", array_map('esc_sql', (array) $st)) . "')")), 2); };
        return rest_ensure_response(['ok' => true, 'settlements' => $out, 'totals' => ['open' => $sum(['draft', 'ready', 'held']), 'released' => $sum('approved'), 'paid' => $sum('paid')]]);
    }

    public static function rest_settlement_action(WP_REST_Request $req) {
        if (!class_exists('TIX_Settlement')) return new WP_Error('tix_unavailable', 'Abrechnungen nicht verfügbar.', ['status' => 409]);
        $sid = absint($req['id']);
        $s = TIX_Settlement::get($sid);
        if (!$s) return new WP_Error('tix_not_found', 'Diese Abrechnung gibt es nicht.', ['status' => 404]);
        $action = (string) $req['action'];
        if ($action === 'hold') {
            $reason = trim(sanitize_textarea_field((string) $req->get_param('reason')));
            if ($reason === '') return self::bad('reason', 'Bitte einen Grund angeben.', 400, 'tix_reason_required');
            $r = TIX_Settlement::hold($sid, $reason);
        } elseif ($action === 'release') {
            if ($s->status === 'held') {
                $r = TIX_Settlement::release($sid);
                $s = TIX_Settlement::get($sid);
                if (!is_wp_error($r) && $s && $s->status === 'ready') $r = TIX_Settlement::approve($sid);
            } else {
                $r = TIX_Settlement::approve($sid);
            }
            if (is_wp_error($r) && $s && $s->status === 'draft') return new WP_Error('tix_payout_details_missing', 'Freigeben geht erst, wenn die Auszahlungsdaten des Veranstalters vollständig sind.', ['status' => 409]);
        } else {
            $ref = sanitize_text_field((string) $req->get_param('ref'));
            $date = (string) $req->get_param('date');
            $r = TIX_Settlement::mark_paid($sid, $ref, preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : current_time('Y-m-d'));
        }
        if (is_wp_error($r)) {
            $d = (array) $r->get_error_data();
            return new WP_Error($r->get_error_code(), $r->get_error_message(), ['status' => intval($d['status'] ?? 409)]);
        }
        $s = TIX_Settlement::get($sid);
        return rest_ensure_response(['ok' => true, 'status' => self::ST_OUT[$s->status] ?? (string) $s->status, 'settlement' => self::settlement_row($s)]);
    }

    // ═══════════════ Betreiber-Support ═══════════════

    const SP_OUT = ['tix_open' => 'open', 'tix_progress' => 'progress', 'tix_resolved' => 'resolved', 'tix_closed' => 'closed'];

    /** Neue Anfrage über /customer/support mit Staff-Konto = „Hilfe & Support“ aus dem Studio. */
    public static function mark_help($tid, $user) {
        if (!$user || !class_exists('TIX_REST_API') || !TIX_REST_API::is_staff_user($user)) return false;
        update_post_meta($tid, self::HELP_META, 1);
        $host = preg_replace('/^www\./', '', (string) wp_parse_url(class_exists('TIX_Customer_URL') ? (TIX_Customer_URL::base() ?: home_url()) : home_url(), PHP_URL_HOST));
        update_post_meta($tid, '_tix_sp_help_site', $host);
        if (self::multi()) {
            $oid = class_exists('TIX_App_Scope') ? intval(TIX_App_Scope::organizer_id_for_user($user->ID)) : 0;
            if ($oid) update_post_meta($tid, '_tix_sp_help_org', $oid);
            update_post_meta($tid, '_tix_sp_help_unread', 1);
        } else {
            self::forward_new($tid);
        }
        return true;
    }

    /** Partner-Seite: Verbindung zu evendis (Verteilung) – [url, key] oder null. */
    private static function platform_link() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $url = untrailingslashit((string) ($s['syndication_api_url'] ?? ''));
        $key = (string) ($s['syndication_api_key'] ?? '');
        return ($url !== '' && $key !== '') ? [$url, $key] : null;
    }

    private static function call_platform($route, array $body) {
        $l = self::platform_link();
        if (!$l) return new WP_Error('tix_no_platform', 'Keine Verbindung zur Plattform eingerichtet.');
        $r = wp_remote_post($l[0] . $route, ['timeout' => 20, 'body' => wp_json_encode($body), 'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Tix-Syndication-Key' => $l[1]]]);
        if (is_wp_error($r)) return $r;
        $code = intval(wp_remote_retrieve_response_code($r));
        $data = json_decode((string) wp_remote_retrieve_body($r), true);
        return ($code >= 200 && $code < 300 && is_array($data)) ? $data : new WP_Error('tix_bridge', 'HTTP ' . $code);
    }

    private static function forward_new($tid) {
        $msgs = class_exists('TIX_Support') ? (array) TIX_Support::staff_messages($tid) : [];
        $first = $msgs[0] ?? [];
        $res = self::call_platform('/syndicate/help', [
            'ext_id' => intval($tid), 'subject' => (string) get_the_title($tid), 'category' => (string) get_post_meta($tid, '_tix_sp_category', true),
            'message' => (string) ($first['content'] ?? ''), 'name' => (string) get_post_meta($tid, '_tix_sp_name', true), 'email' => (string) get_post_meta($tid, '_tix_sp_email', true),
            'site' => (string) get_post_meta($tid, '_tix_sp_help_site', true), 'site_name' => class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand()['name'] : get_bloginfo('name'),
        ]);
        if (is_wp_error($res) || empty($res['id'])) {
            update_post_meta($tid, '_tix_sp_help_pending', 1);
            if (class_exists('TIX_Support')) TIX_Support::staff_note($tid, 'Weiterleitung an den Plattform-Support fehlgeschlagen' . (is_wp_error($res) ? ' (' . $res->get_error_message() . ')' : '') . '.', 'System');
            return false;
        }
        update_post_meta($tid, '_tix_sp_help_remote', intval($res['id']));
        delete_post_meta($tid, '_tix_sp_help_pending');
        return true;
    }

    private static $in_bridge = false;

    /** Neue Nachricht: Partner-Seite → evendis (Antwort des Seiten-Teams), evendis → Partner-Seite (Antwort des Plattform-Teams). */
    public static function on_message($tid, $msg) {
        if (self::$in_bridge || !get_post_meta($tid, self::HELP_META, true)) return;
        $type = (string) ($msg['type'] ?? '');
        if (!self::multi()) {
            if ($type !== 'customer') return;
            $remote = intval(get_post_meta($tid, '_tix_sp_help_remote', true));
            if (!$remote) { self::forward_new($tid); return; }
            self::call_platform('/syndicate/help/' . $remote . '/messages', ['content' => (string) ($msg['content'] ?? ''), 'author' => (string) ($msg['author'] ?? ''), 'message_id' => (string) ($msg['id'] ?? '')]);
            return;
        }
        if ($type === 'customer') update_post_meta($tid, '_tix_sp_help_unread', 1);
    }

    public static function on_status($tid, $new, $old) {
        if (self::$in_bridge || !self::multi() || !get_post_meta($tid, self::HELP_META, true)) return;
        $p = self::help_partner($tid);
        if ($p) self::to_partner($tid, $p, '', $new);
    }

    private static function help_partner($tid) {
        $pid = (string) get_post_meta($tid, '_tix_sp_help_partner', true);
        return ($pid !== '' && class_exists('TIX_Partners')) ? TIX_Partners::get($pid) : null;
    }

    private static function to_partner($tid, array $p, $content, $status) {
        $ext = intval(get_post_meta($tid, '_tix_sp_help_ext', true));
        if (!$ext || empty($p['api_base'])) return false;
        $r = wp_remote_post(untrailingslashit($p['api_base']) . '/partner/help/' . $ext . '/reply', ['timeout' => 20, 'body' => wp_json_encode(['content' => $content, 'status' => self::SP_OUT[$status] ?? '', 'author' => 'evendis-Team', 'message_id' => 'h' . $tid . '-' . md5($content . $status . microtime())]),
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Tix-Partner-Id' => TIX_Partners::platform_id(), 'X-Tix-Partner-Key' => (string) ($p['key_out'] ?? '')]]);
        $ok = !is_wp_error($r) && intval(wp_remote_retrieve_response_code($r)) < 300;
        if (!$ok && class_exists('TIX_Support')) TIX_Support::staff_note($tid, 'Antwort an ' . ($p['name'] ?? 'die Partner-Seite') . ' nicht zugestellt – bitte später erneut senden.', 'System');
        return $ok;
    }

    /** evendis: Anfrage von einer Partner-Seite (X-Tix-Syndication-Key). */
    public static function perm_partner(WP_REST_Request $req) {
        if (!self::multi() || !class_exists('TIX_Partners')) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        $p = TIX_Partners::find_by_key((string) $req->get_header('X-Tix-Syndication-Key'));
        if (!$p) return new WP_Error('unauthorized', 'Ungültiger Partner-Schlüssel.', ['status' => 401]);
        if (isset($req['id'])) {
            $tid = absint($req['id']);
            if (get_post_type($tid) !== 'tix_support_ticket' || (string) get_post_meta($tid, '_tix_sp_help_partner', true) !== (string) $p['id']) return new WP_Error('not_found', 'Anfrage nicht gefunden.', ['status' => 404]);
        }
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('help_bridge', 120, 600, 'p' . $p['id'])) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen.', ['status' => 429]);
        $req->set_param('_partner', $p);
        wp_set_current_user(0);
        return true;
    }

    public static function rest_bridge_create(WP_REST_Request $req) {
        $p = $req->get_param('_partner');
        $ext = absint($req->get_param('ext_id'));
        $subject = mb_substr(sanitize_text_field((string) $req->get_param('subject')), 0, 120);
        $message = mb_substr(sanitize_textarea_field((string) $req->get_param('message')), 0, 5000);
        if (!$ext || $subject === '' || $message === '') return self::bad('subject', 'Betreff und Nachricht fehlen.');
        $dup = get_posts(['post_type' => 'tix_support_ticket', 'post_status' => array_keys(self::SP_OUT), 'fields' => 'ids', 'posts_per_page' => 1, 'meta_query' => [['key' => '_tix_sp_help_partner', 'value' => (string) $p['id']], ['key' => '_tix_sp_help_ext', 'value' => (string) $ext]]]);
        if ($dup) return rest_ensure_response(['ok' => true, 'id' => intval($dup[0]), 'duplicate' => true]);
        $email = sanitize_email((string) $req->get_param('email'));
        $name = mb_substr(sanitize_text_field((string) $req->get_param('name')), 0, 80);
        $tid = wp_insert_post(['post_type' => 'tix_support_ticket', 'post_title' => $subject, 'post_status' => 'tix_open'], true);
        if (is_wp_error($tid) || !$tid) return new WP_Error('tix_support_failed', 'Anfrage konnte nicht angelegt werden.', ['status' => 500]);
        $meta = ['_tix_sp_email' => $email, '_tix_sp_name' => $name, '_tix_sp_category' => sanitize_key((string) $req->get_param('category')) ?: 'beratung', '_tix_sp_priority' => 'normal',
            '_tix_sp_source' => 'partner_help', '_tix_sp_last_reply' => current_time('c'), self::HELP_META => 1, '_tix_sp_help_partner' => (string) $p['id'], '_tix_sp_help_ext' => $ext,
            '_tix_sp_help_site' => mb_substr(sanitize_text_field((string) $req->get_param('site')), 0, 120) ?: (string) wp_parse_url((string) ($p['api_base'] ?? ''), PHP_URL_HOST),
            '_tix_sp_help_site_name' => mb_substr(sanitize_text_field((string) $req->get_param('site_name')), 0, 120) ?: (string) ($p['name'] ?? ''), '_tix_sp_help_unread' => 1];
        foreach ($meta as $k => $v) update_post_meta($tid, $k, $v);
        update_post_meta($tid, '_tix_sp_messages', [['id' => 'msg_' . bin2hex(random_bytes(8)), 'type' => 'customer', 'author' => $name ?: $email, 'email' => $email, 'content' => $message, 'date' => current_time('c')]]);
        return rest_ensure_response(['ok' => true, 'id' => intval($tid)]);
    }

    public static function rest_bridge_message(WP_REST_Request $req) {
        $tid = absint($req['id']);
        $c = sanitize_textarea_field((string) $req->get_param('content'));
        if (trim($c) === '') return self::bad('content', 'Nachricht fehlt.');
        $mid = (string) $req->get_param('message_id');
        $seen = (array) get_post_meta($tid, '_tix_sp_help_seen', true);
        if ($mid !== '' && in_array($mid, $seen, true)) return rest_ensure_response(['ok' => true, 'duplicate' => true]);
        if ($mid !== '') { $seen[] = $mid; update_post_meta($tid, '_tix_sp_help_seen', array_slice($seen, -100)); }
        self::$in_bridge = true;
        TIX_Support::add_customer_message($tid, $c, sanitize_text_field((string) $req->get_param('author')) ?: 'Veranstalter', (string) get_post_meta($tid, '_tix_sp_email', true));
        self::$in_bridge = false;
        update_post_meta($tid, '_tix_sp_help_unread', 1);
        return rest_ensure_response(['ok' => true]);
    }

    /** Partner-Seite: Antwort bzw. Status vom Plattform-Team (Absicherung wie die Support-Brücke). */
    public static function rest_bridge_reply_in(WP_REST_Request $req) {
        $tid = absint($req['id']);
        if (get_post_type($tid) !== 'tix_support_ticket' || !get_post_meta($tid, self::HELP_META, true)) return new WP_Error('not_found', 'Anfrage nicht gefunden.', ['status' => 404]);
        $mid = (string) $req->get_param('message_id');
        $seen = (array) get_post_meta($tid, '_tix_sp_help_seen', true);
        if ($mid !== '' && in_array($mid, $seen, true)) return rest_ensure_response(['ok' => true, 'duplicate' => true]);
        if ($mid !== '') { $seen[] = $mid; update_post_meta($tid, '_tix_sp_help_seen', array_slice($seen, -100)); }
        $c = sanitize_textarea_field((string) $req->get_param('content'));
        $st = array_search((string) $req->get_param('status'), self::SP_OUT, true);
        self::$in_bridge = true;
        if (trim($c) !== '') TIX_Support::staff_reply($tid, $c, ['author' => sanitize_text_field((string) $req->get_param('author')) ?: 'Plattform-Team', 'deliver' => true]);
        if ($st) TIX_Support::set_status($tid, $st, true);
        self::$in_bridge = false;
        return rest_ensure_response(['ok' => true]);
    }

    private static function help_row($tid) {
        $msgs = class_exists('TIX_Support') ? (array) TIX_Support::staff_messages($tid) : [];
        $msgs = array_values(array_filter($msgs, function ($m) { return in_array($m['type'] ?? '', ['customer', 'admin'], true); }));
        $last = $msgs ? end($msgs) : [];
        $oid = intval(get_post_meta($tid, '_tix_sp_help_org', true));
        $pname = (string) get_post_meta($tid, '_tix_sp_help_site_name', true);
        $p = get_post($tid);
        return [
            'id' => intval($tid), 'subject' => html_entity_decode((string) $p->post_title, ENT_QUOTES, 'UTF-8'), 'status' => self::SP_OUT[$p->post_status] ?? 'open',
            'site' => (string) get_post_meta($tid, '_tix_sp_help_site', true),
            'organizer' => ['id' => $oid, 'name' => $oid ? html_entity_decode(get_the_title($oid), ENT_QUOTES, 'UTF-8') : $pname],
            'from' => ['name' => (string) get_post_meta($tid, '_tix_sp_name', true), 'email' => (string) get_post_meta($tid, '_tix_sp_email', true)],
            'last_message' => $last ? mb_substr(wp_strip_all_tags((string) $last['content']), 0, 160) : '',
            'last_from' => ($last['type'] ?? '') === 'admin' ? 'team' : 'organizer',
            'updated' => self::dt($p->post_modified), 'unread' => (bool) get_post_meta($tid, '_tix_sp_help_unread', true),
            'category' => (string) get_post_meta($tid, '_tix_sp_category', true),
            'category_label' => class_exists('TIX_Support') && method_exists('TIX_Support', 'label_of') ? TIX_Support::label_of((string) get_post_meta($tid, '_tix_sp_category', true)) : '',
        ];
    }

    public static function rest_support(WP_REST_Request $req) {
        $status = $req->get_param('status') === 'all' ? ['tix_open', 'tix_progress', 'tix_resolved', 'tix_closed'] : ['tix_open', 'tix_progress'];
        $ids = get_posts(['post_type' => 'tix_support_ticket', 'post_status' => $status, 'posts_per_page' => 300, 'fields' => 'ids', 'orderby' => 'modified', 'order' => 'DESC',
            'meta_query' => [['key' => self::HELP_META, 'value' => '1']], 'no_found_rows' => true]);
        return rest_ensure_response(['ok' => true, 'tickets' => array_map([__CLASS__, 'help_row'], $ids)]);
    }

    private static function help_or_404($id) {
        $tid = absint($id);
        return (get_post_type($tid) === 'tix_support_ticket' && get_post_meta($tid, self::HELP_META, true)) ? $tid : new WP_Error('tix_not_found', 'Diese Anfrage gibt es nicht.', ['status' => 404]);
    }

    public static function rest_support_one(WP_REST_Request $req) {
        $tid = self::help_or_404($req['id']);
        if (is_wp_error($tid)) return $tid;
        delete_post_meta($tid, '_tix_sp_help_unread');
        $row = self::help_row($tid);
        $msgs = [];
        foreach ((array) TIX_Support::staff_messages($tid) as $i => $m) {
            if (!in_array($m['type'] ?? '', ['customer', 'admin'], true)) continue;
            $msgs[] = ['id' => (string) ($m['id'] ?? ($i + 1)), 'from' => $m['type'] === 'admin' ? 'team' : 'organizer', 'author' => (string) ($m['author'] ?? ''), 'content' => (string) ($m['content'] ?? ''),
                'date' => !empty($m['date']) ? wp_date('Y-m-d H:i', strtotime((string) $m['date'])) : ''];
        }
        $row['messages'] = $msgs;
        return rest_ensure_response(['ok' => true, 'ticket' => $row]);
    }

    public static function rest_support_reply(WP_REST_Request $req) {
        $tid = self::help_or_404($req['id']);
        if (is_wp_error($tid)) return $tid;
        $c = mb_substr(sanitize_textarea_field((string) $req->get_param('message')), 0, 5000);
        if (trim($c) === '') return self::bad('message', 'Bitte eine Antwort eingeben.');
        $st_in = (string) $req->get_param('status');
        $st = $st_in !== '' ? array_search($st_in, self::SP_OUT, true) : 'tix_progress';
        if ($st_in !== '' && !$st) return self::bad('status', 'Status: open, progress, resolved oder closed.');
        $p = self::help_partner($tid);
        $u = wp_get_current_user();
        self::$in_bridge = true;
        // Partner-Seite: die Seite benachrichtigt ihr Team selbst (keine zweite Mail von evendis)
        TIX_Support::staff_reply($tid, $c, ['author' => 'evendis-Team', 'user_id' => intval($u->ID), 'deliver' => !$p]);
        TIX_Support::set_status($tid, $st, !$p);
        self::$in_bridge = false;
        if ($p) self::to_partner($tid, $p, $c, $st);
        delete_post_meta($tid, '_tix_sp_help_unread');
        return rest_ensure_response(['ok' => true, 'status' => self::SP_OUT[$st]]);
    }

    public static function rest_support_status(WP_REST_Request $req) {
        $tid = self::help_or_404($req['id']);
        if (is_wp_error($tid)) return $tid;
        $st = array_search((string) $req->get_param('status'), self::SP_OUT, true);
        if (!$st) return self::bad('status', 'Status: open, progress, resolved oder closed.');
        $p = self::help_partner($tid);
        self::$in_bridge = true;
        TIX_Support::set_status($tid, $st, !$p);
        self::$in_bridge = false;
        if ($p) self::to_partner($tid, $p, '', $st);
        return rest_ensure_response(['ok' => true, 'status' => self::SP_OUT[$st]]);
    }
}
