<?php
/**
 * TIX_Promoter_REST – Promoter-Verwaltung für das Studio (1.38.643, nach dem Audit vom 2026-11-05).
 *
 * Rechte wie Werbekonten (TIX_Ad_Accounts::perm: Inhaber, Team-Admin, Admin; Einzel-Seite: Admin bzw. Veranstalter-Rolle).
 * Bereich: Einzel-Seite = alle Promoter der Seite (organizer_id 0); evendis = nur die Promoter des eigenen Veranstalters,
 * Events nur aus dem eigenen Bestand (TIX_Studio_Stats::scope_ids). Der Admin wählt den Veranstalter mit ?organizer=.
 *
 * Routen (Vertrag DOCUMENTATION.md §81):
 *   GET|POST           /organizer/promoters
 *   GET|POST|DELETE    /organizer/promoters/{id}
 *   POST               /organizer/promoters/{id}/access        Login-Link an den Promoter
 *   POST               /organizer/promoters/{id}/logout-all    alle Sitzungen beenden
 *   POST|DELETE        /organizer/promoters/{id}/events[/{event_id}]   Provision je Event (0 = alle eigenen Events)
 *   POST               /organizer/promoter-settings             an/aus, Laufzeit, Standard-Provision (Seite; evendis nur Admin)
 *   GET|POST           /organizer/promoter-payouts
 *   GET                /organizer/promoter-payouts/{id}
 *   POST               /organizer/promoter-payouts/{id}/paid | /cancel
 */
if (!defined('ABSPATH')) exit;

class TIX_Promoter_REST {

    const NS = 'tixomat/v1';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        // Selbst-Anmeldung: Veranstalter per Mail und Push informieren (1.38.660, vorher erfuhr er nichts)
        add_action('tix_promoter_signup', [__CLASS__, 'notify_signup'], 10, 2);
    }

    /** Empfänger einer Veranstalter-Mitteilung: Inhaber (Plattform) bzw. Admin-Adresse der Seite */
    private static function org_mail($org) {
        if ($org && ($uid = intval(get_post_meta(intval($org), '_tix_org_user_id', true))) && ($u = get_userdata($uid)) && is_email($u->user_email)) return $u->user_email;
        $s = function_exists('tix_get_settings') ? (array) tix_get_settings() : [];
        $m = (string) ($s['promoter_notify_email'] ?? '');
        return is_email($m) ? $m : (string) get_option('admin_email');
    }

    /** Neue Selbst-Anmeldung → Mail an den Veranstalter mit Knopf zur Freigabe, Push „Neue Promoter-Anmeldung“ */
    public static function notify_signup($pid, $org = 0) {
        $p = class_exists('TIX_Promoter_DB') ? TIX_Promoter_DB::get_promoter(intval($pid)) : null;
        if (!$p || $p->status !== 'pending') return;
        $org = intval($org);
        $n = self::pending_count($org);
        $url = class_exists('TIX_Studio') && TIX_Studio::active() ? TIX_Studio::url('/promoter-verwaltung/?status=pending') : admin_url('admin.php?page=tix-promoters');
        if (class_exists('TIX_Mail_Layout')) {
            $to = self::org_mail($org);
            if (is_email($to)) TIX_Mail_Layout::send($to, 'Neue Promoter-Anmeldung: ' . $p->display_name, [
                'title' => 'Neue Promoter-Anmeldung',
                'blocks' => [['type' => 'html', 'html' => '<p><strong>' . esc_html($p->display_name) . '</strong> (' . esc_html((string) $p->email) . ') möchte als Promoter für dich werben.</p>'
                    . '<p>Bis du freigibst, sieht die Person keine Links und verdient nichts.' . ($n > 1 ? ' Insgesamt warten ' . $n . ' Anmeldungen.' : '') . '</p>'
                    . (class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::button('Anmeldung prüfen', $url) : '')]],
                'kind' => 'admin',
            ], ['customer' => false]);
        }
        if (class_exists('TIX_Staff_Push') && isset(TIX_Staff_Push::EVENTS['promoter_signup'])) {
            TIX_Staff_Push::enqueue('promoter_signup', 0, ['name' => (string) $p->display_name] + ($org ? ['org' => $org] : []));
        }
    }

    /** GET /pos/promoters?event_id=&q= → {promoters[{id, name, code}]} – nur aktive, für das Event erlaubte */
    public static function rest_pos_search(WP_REST_Request $req) {
        $eid = absint($req->get_param('event_id'));
        if (!$eid || get_post_type($eid) !== 'event') return self::bad('event_id', 'Bitte ein Event wählen.');
        if (!TIX_REST_API::can_access_event($eid)) return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        if (!self::ready()) return rest_ensure_response(['ok' => true, 'promoters' => []]);
        return rest_ensure_response(['ok' => true, 'promoters' => TIX_Promoter::pos_search($eid, mb_substr(sanitize_text_field((string) $req->get_param('q')), 0, 60))]);
    }

    /** Anzahl wartender Selbst-Anmeldungen im Bereich */
    public static function pending_count($org) {
        global $wpdb;
        if (!class_exists('TIX_Promoter_DB')) return 0;
        return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Promoter_DB::table_promoters() . " WHERE organizer_id = %d AND status = 'pending'", intval($org))));
    }

    /** GET /organizer/promoters/pending → {count, promoters[{id, name, email, created, notes}]} (Menü-Zähler „Wartet auf Freigabe“) */
    public static function rest_pending(WP_REST_Request $req) {
        if (!self::ready()) return new WP_Error('tix_unavailable', 'Promoter sind hier nicht verfügbar.', ['status' => 503]);
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . TIX_Promoter_DB::table_promoters() . " WHERE organizer_id = %d AND status = 'pending' ORDER BY created_at ASC, id ASC LIMIT 200", self::org($req)));
        $items = array_map(function ($p) { return ['id' => intval($p->id), 'name' => (string) $p->display_name, 'email' => (string) $p->email, 'code' => (string) $p->promoter_code,
            'created' => $p->created_at ? mysql_to_rfc3339($p->created_at) : null, 'notes' => (string) ($p->notes ?? '')]; }, $rows);
        return rest_ensure_response(['ok' => true, 'count' => count($items), 'promoters' => $items]);
    }

    public static function routes() {
        $p = ['TIX_Ad_Accounts', 'perm'];
        $r = function ($path, $methods, $cb) use ($p) { register_rest_route(self::NS, $path, ['methods' => $methods, 'callback' => [__CLASS__, $cb], 'permission_callback' => $p]); };
        $r('/organizer/promoters', 'GET', 'rest_list');
        $r('/organizer/promoters', 'POST', 'rest_create');
        $r('/organizer/promoters/pending', 'GET', 'rest_pending');
        $r('/organizer/promoters/(?P<id>\d+)', 'GET', 'rest_get');
        $r('/organizer/promoters/(?P<id>\d+)', 'POST', 'rest_update');
        $r('/organizer/promoters/(?P<id>\d+)', 'DELETE', 'rest_delete');
        $r('/organizer/promoters/(?P<id>\d+)/access', 'POST', 'rest_access');
        $r('/organizer/promoters/(?P<id>\d+)/logout-all', 'POST', 'rest_logout_all');
        $r('/organizer/promoters/(?P<id>\d+)/events', 'POST', 'rest_assign');
        $r('/organizer/promoters/(?P<id>\d+)/events/(?P<event_id>\d+)', 'DELETE', 'rest_unassign');
        $r('/organizer/promoter-settings', 'POST', 'rest_settings');
        // Abendkasse: Promoter suchen (Rechte wie die Kasse)
        register_rest_route(self::NS, '/pos/promoters', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_pos_search'], 'permission_callback' => ['TIX_REST_API', 'check_pos']]);
        $r('/organizer/promoter-payouts', 'GET', 'rest_payouts');
        $r('/organizer/promoter-payouts', 'POST', 'rest_payout_create');
        $r('/organizer/promoter-payouts/(?P<id>\d+)', 'GET', 'rest_payout');
        $r('/organizer/promoter-payouts/(?P<id>\d+)/paid', 'POST', 'rest_payout_paid');
        $r('/organizer/promoter-payouts/(?P<id>\d+)/cancel', 'POST', 'rest_payout_cancel');
    }

    private static function bad($field, $msg, $code = 'tix_invalid_field', $status = 400) { return new WP_Error($code, $msg, ['status' => $status, 'field' => $field]); }
    private static function nf() { return new WP_Error('not_found', 'Promoter nicht gefunden.', ['status' => 404]); }
    private static function org(WP_REST_Request $req) { return TIX_Ad_Accounts::org($req); }

    private static function ready() {
        if (!class_exists('TIX_Promoter_DB')) return false;
        if (!TIX_Promoter_DB::tables_exist()) TIX_Promoter_DB::create_tables();
        TIX_Promoter_DB::ensure_email_column();
        TIX_Promoter_DB::ensure_default_coupon_column();
        TIX_Promoter_DB::ensure_org_column();
        TIX_Promoter_DB::ensure_clicks_table();
        if (class_exists('TIX_Promoter_Partner')) TIX_Promoter_Partner::ensure_columns(); // Partner (R1, 1.38.812)
        return true;
    }

    /** Promoter des Bereichs oder null */
    private static function own($id, $org) {
        $p = TIX_Promoter_DB::get_promoter(intval($id));
        return ($p && intval($p->organizer_id ?? 0) === intval($org)) ? $p : null;
    }

    private static function in_scope($eid) {
        return $eid > 0 && get_post_type($eid) === 'event' && in_array(intval($eid), array_map('intval', TIX_Studio_Stats::scope_ids()), true);
    }

    private static function base() {
        $b = class_exists('TIX_Customer_URL') ? (string) TIX_Customer_URL::base() : '';
        return trailingslashit($b !== '' ? $b : home_url('/'));
    }

    private static function event_url($eid) {
        $u = (string) get_permalink($eid);
        return class_exists('TIX_Customer_URL') ? TIX_Customer_URL::to($u) : $u;
    }

    /**
     * Werbe-Links: link (Teilen), qr_link (für QR-Codes, Quelle „qr“ in der Statistik).
     * Mit Rabatt-Code (Promoter-Rabatt am Event) zusätzlich &coupon=, damit der Rabatt auch in der nativen Kasse,
     * der App und auf den Marken-Websites ohne Eintippen greift (1.38.660).
     */
    public static function links($code, $url, $coupon = '', $event_id = 0) {
        $q = ['ref' => $code] + ($coupon !== '' ? ['coupon' => $coupon] : []);
        return ['link' => add_query_arg($q, $url),
            'qr_link' => add_query_arg($q + ['utm_source' => 'promoter', 'utm_medium' => 'qr', 'utm_campaign' => 'promoter-' . $code], $url),
            'coupon' => $coupon !== '' ? $coupon : null,
            // Kurzlink /go/<code>[?e=] (1.38.663)
            'short' => class_exists('TIX_Promoter_Rules') ? TIX_Promoter_Rules::short_link($code, intval($event_id)) : null];
    }

    /** Rabatt-Code einer Zuordnung, wenn dort ein Rabatt gesetzt ist, sonst '' */
    public static function link_coupon($a) {
        return ($a && (string) ($a->promo_code ?? '') !== '' && (string) ($a->discount_type ?? '') !== '' && floatval($a->discount_value ?? 0) > 0) ? (string) $a->promo_code : '';
    }

    private static function money($v) { return round(floatval($v), 2); }

    /** Kennzahlen je Promoter im Zeitraum (Provisionen ohne Stornos, Klicks) → [id => {...}] */
    public static function stats(array $ids, $from, $to) {
        global $wpdb;
        $out = [];
        if (!$ids) return $out;
        $in = implode(',', array_map('intval', $ids));
        $tc = TIX_Promoter_DB::table_commissions();
        $w = '';
        if ($from) $w .= $wpdb->prepare(' AND created_at >= %s', $from . ' 00:00:00');
        if ($to) $w .= $wpdb->prepare(' AND created_at <= %s', $to . ' 23:59:59');
        foreach ((array) $wpdb->get_results("SELECT promoter_id, COUNT(DISTINCT CASE WHEN commission_amount > 0 AND attribution != 'team' THEN order_id END) orders, SUM(tickets_qty) tickets, SUM(order_total) revenue,
            SUM(CASE WHEN status = 'pending' THEN commission_amount ELSE 0 END) open_c, SUM(CASE WHEN status = 'approved' THEN commission_amount ELSE 0 END) appr,
            SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END) paid, SUM(commission_amount) total, MAX(CASE WHEN commission_amount > 0 THEN created_at END) last_sale
            FROM $tc WHERE promoter_id IN ($in) AND status != 'cancelled' $w GROUP BY promoter_id") as $r) {
            $out[intval($r->promoter_id)] = ['orders' => intval($r->orders), 'tickets' => intval($r->tickets), 'revenue' => self::money($r->revenue),
                'commission' => ['open' => self::money($r->open_c), 'approved' => self::money($r->appr), 'paid' => self::money($r->paid), 'total' => self::money($r->total)],
                'last_sale' => $r->last_sale ? mysql_to_rfc3339($r->last_sale) : null];
        }
        $tk = TIX_Promoter_DB::table_clicks();
        $wc = '';
        if ($from) $wc .= $wpdb->prepare(' AND click_date >= %s', $from);
        if ($to) $wc .= $wpdb->prepare(' AND click_date <= %s', $to);
        foreach ((array) $wpdb->get_results("SELECT promoter_id, COUNT(*) n, COUNT(DISTINCT visitor_id) u FROM $tk WHERE promoter_id IN ($in) $wc GROUP BY promoter_id") as $r) {
            $out[intval($r->promoter_id)]['clicks'] = intval($r->n);
            $out[intval($r->promoter_id)]['visitors'] = intval($r->u);
        }
        // offenes Guthaben (alle Zeiträume): auszahlbar = Events vorbei (+ Gegenbuchungen), wartet = Event steht noch aus
        foreach ($ids as $i) { list($now, $wait) = TIX_Promoter_DB::open_balance(intval($i)); $out[intval($i)]['balance'] = $now; $out[intval($i)]['balance_waiting'] = $wait; }
        return $out;
    }

    private static function item($p, array $st = []) {
        $code = (string) $p->promoter_code;
        $email = (string) ($p->email ?? '');
        if ($email === '' && !empty($p->user_id) && ($u = get_userdata(intval($p->user_id)))) $email = $u->user_email;
        $z = ['open' => 0.0, 'approved' => 0.0, 'paid' => 0.0, 'total' => 0.0];
        return ['id' => intval($p->id), 'name' => (string) $p->display_name, 'email' => $email, 'code' => $code, 'status' => (string) $p->status, 'notes' => (string) ($p->notes ?? ''),
            'has_account' => intval($p->user_id) > 0, 'created' => $p->created_at ? mysql_to_rfc3339($p->created_at) : null, 'self_signup' => (string) ($p->notes ?? '') === 'Self-Signup',
            'clicks' => intval($st['clicks'] ?? 0), 'visitors' => intval($st['visitors'] ?? 0), 'orders' => intval($st['orders'] ?? 0), 'tickets' => intval($st['tickets'] ?? 0),
            'revenue' => floatval($st['revenue'] ?? 0), 'commission' => $st['commission'] ?? $z, 'balance' => floatval($st['balance'] ?? 0), 'balance_waiting' => floatval($st['balance_waiting'] ?? 0), 'last_sale' => $st['last_sale'] ?? null,
            'links' => self::links($code, self::base())] + (class_exists('TIX_Promoter_Rules') ? TIX_Promoter_Rules::promoter_fields($p) : [])
            + (class_exists('TIX_Promoter_Partner') ? TIX_Promoter_Partner::item_fields($p) : []);
    }

    private static function period(WP_REST_Request $req) {
        $ok = function ($d) { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d); };
        $from = (string) $req->get_param('from'); $to = (string) $req->get_param('to');
        return [$ok($from) ? $from : '', $ok($to) ? $to : ''];
    }

    // ═══════════════ Promoter ═══════════════

    public static function rest_list(WP_REST_Request $req) {
        if (!self::ready()) return new WP_Error('tix_unavailable', 'Promoter sind hier nicht verfügbar.', ['status' => 503]);
        global $wpdb;
        $org = self::org($req);
        $status = (string) ($req->get_param('status') ?: 'active');
        $sql = $wpdb->prepare("SELECT * FROM " . TIX_Promoter_DB::table_promoters() . " WHERE organizer_id = %d", $org);
        if (in_array($status, ['active', 'inactive', 'pending'], true)) $sql .= $wpdb->prepare(' AND status = %s', $status);
        $kind = (string) $req->get_param('kind'); // Partner (R1): ?kind=partner|person
        if (in_array($kind, ['partner', 'person'], true)) $sql .= $wpdb->prepare(" AND COALESCE(kind, 'person') = %s", $kind);
        $rows = (array) $wpdb->get_results($sql . ' ORDER BY display_name ASC, id ASC');
        list($from, $to) = self::period($req);
        $st = self::stats(array_map(function ($p) { return intval($p->id); }, $rows), $from, $to);
        $items = array_map(function ($p) use ($st) { return self::item($p, $st[intval($p->id)] ?? []); }, $rows);
        $tot = ['clicks' => 0, 'orders' => 0, 'tickets' => 0, 'revenue' => 0.0, 'commission_open' => 0.0, 'commission_paid' => 0.0, 'balance' => 0.0, 'balance_waiting' => 0.0];
        foreach ($items as $i) { $tot['clicks'] += $i['clicks']; $tot['orders'] += $i['orders']; $tot['tickets'] += $i['tickets']; $tot['revenue'] += $i['revenue'];
            $tot['commission_open'] += $i['commission']['open'] + $i['commission']['approved']; $tot['commission_paid'] += $i['commission']['paid']; $tot['balance'] += $i['balance']; $tot['balance_waiting'] += $i['balance_waiting']; }
        $tot = array_map(function ($v) { return is_float($v) ? round($v, 2) : $v; }, $tot);
        $s = function_exists('tix_get_settings') ? (array) tix_get_settings() : [];
        $r = rest_ensure_response(['ok' => true, 'promoters' => $items, 'totals' => $tot, 'pending_count' => self::pending_count($org), 'from' => $from ?: null, 'to' => $to ?: null,
            'settings' => ['enabled' => !empty($s['promoter_enabled']), 'can_edit' => self::can_settings(), 'cookie_days' => intval($s['promoter_cookie_days'] ?? 30) ?: 30, 'consent_category' => 'marketing', 'self_signup' => !empty($s['promoter_self_signup']),
                'dashboard_url' => class_exists('TIX_Promoter_Auth') ? TIX_Promoter_Auth::get_promoter_page_url() : '',
                'default_commission' => ['type' => (string) ($s['promoter_default_commission_type'] ?? 'percent'), 'value' => floatval($s['promoter_default_commission_value'] ?? 0)],
                'multi' => class_exists('TIX_App_Scope') && TIX_App_Scope::multi()]]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    private static function valid_code($code, $except = 0) {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{2,29}$/', $code)) return self::bad('code', 'Code: 3–30 Zeichen, a–z, 0–9, _ und -.');
        global $wpdb;
        $id = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM " . TIX_Promoter_DB::table_promoters() . " WHERE promoter_code = %s", $code)));
        if ($id && $id !== intval($except)) return self::bad('code', 'Diesen Code gibt es schon.', 'tix_code_taken', 409);
        return null;
    }

    private static function gen_code($name) {
        $b = preg_replace('/[^a-z0-9]/', '', sanitize_title(mb_substr((string) $name, 0, 12))) ?: 'promo';
        if (strlen($b) < 3) $b .= 'promo';
        for ($i = 0; $i < 20; $i++) { $c = $b . wp_rand(10, 999); if (!self::valid_code($c)) return $c; }
        return $b . wp_generate_password(6, false, false);
    }

    public static function rest_create(WP_REST_Request $req) {
        if (!self::ready()) return new WP_Error('tix_unavailable', 'Promoter sind hier nicht verfügbar.', ['status' => 503]);
        $org = self::org($req);
        $name = mb_substr(trim(sanitize_text_field((string) $req->get_param('name'))), 0, 120);
        if ($name === '') return self::bad('name', 'Bitte einen Namen angeben.');
        $email = strtolower(sanitize_email((string) $req->get_param('email')));
        if ($req->get_param('email') && !is_email($email)) return self::bad('email', 'Bitte eine gültige E-Mail-Adresse angeben.');
        if ($email !== '') {
            global $wpdb;
            if ($wpdb->get_var($wpdb->prepare("SELECT id FROM " . TIX_Promoter_DB::table_promoters() . " WHERE LOWER(email) = %s AND organizer_id = %d AND status = 'active'", $email, $org)))
                return self::bad('email', 'Für diese E-Mail gibt es schon einen Promoter.', 'tix_promoter_exists', 409);
        }
        $code = strtolower(trim((string) $req->get_param('code')));
        if ($code !== '') { if ($e = self::valid_code($code)) return $e; } else $code = self::gen_code($name);
        $pset = [];
        if (class_exists('TIX_Promoter_Partner')) { list($pset, $perr) = TIX_Promoter_Partner::fields($req); if ($perr) return $perr; }
        $id = TIX_Promoter_DB::insert_promoter(['user_id' => 0, 'organizer_id' => $org, 'email' => $email, 'promoter_code' => $code, 'display_name' => $name, 'status' => 'active',
            'notes' => mb_substr(sanitize_textarea_field((string) $req->get_param('notes')), 0, 1000)]);
        if (!$id) return new WP_Error('tix_db', 'Der Promoter konnte nicht angelegt werden.', ['status' => 500]);
        if ($pset) { global $wpdb; $wpdb->update(TIX_Promoter_DB::table_promoters(), $pset, ['id' => intval($id)]); }
        $c = $req->get_param('commission');
        if (is_array($c) && floatval($c['value'] ?? 0) > 0) {
            $res = self::assign($id, 0, $c, []);
            if (is_wp_error($res)) return $res;
        }
        $sent = false;
        if (rest_sanitize_boolean($req->get_param('send_access')) && $email !== '' && class_exists('TIX_Promoter_Auth')) $sent = TIX_Promoter_Auth::send_login_link(TIX_Promoter_DB::get_promoter($id), 'invite') === true;
        return new WP_REST_Response(['ok' => true, 'promoter' => self::item(TIX_Promoter_DB::get_promoter($id)), 'access_sent' => $sent], 201);
    }

    public static function rest_get(WP_REST_Request $req) {
        if (!self::ready()) return self::nf();
        $org = self::org($req);
        $p = self::own($req['id'], $org);
        if (!$p) return self::nf();
        list($from, $to) = self::period($req);
        $st = self::stats([intval($p->id)], $from, $to);
        $out = self::item($p, $st[intval($p->id)] ?? []);
        $out['assignments'] = [];
        foreach ((array) TIX_Promoter_DB::get_promoter_events(intval($p->id)) as $a) {
            $eid = intval($a->event_id);
            $url = $eid ? self::event_url($eid) : self::base();
            $out['assignments'][] = ['event_id' => $eid, 'event_title' => $eid ? html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8') : 'Alle Events', 'event_date' => $eid ? (string) get_post_meta($eid, '_tix_date_start', true) : '',
                'commission_type' => (string) $a->commission_type, 'commission_value' => floatval($a->commission_value), 'discount_type' => (string) $a->discount_type, 'discount_value' => floatval($a->discount_value),
                'category_rates' => (function ($raw) { $r = $raw ? json_decode((string) $raw, true) : null; $o = []; foreach ((array) $r as $ix => $x) $o[] = ['cat_index' => intval($ix), 'type' => (string) ($x['type'] ?? 'percent'), 'value' => floatval($x['value'] ?? 0)]; return $o; })($a->category_rates ?? null),
                'promo_code' => (string) $a->promo_code, 'links' => self::links($p->promoter_code, $url, $eid ? self::link_coupon($a) : '', $eid)];
        }
        $out['sales'] = array_map(function ($c) {
            return ['date' => mysql_to_rfc3339($c->created_at), 'event_id' => intval($c->event_id), 'event_title' => html_entity_decode((string) ($c->event_title ?: ''), ENT_QUOTES, 'UTF-8'), 'order_id' => intval($c->order_id),
                'type' => intval(round(floatval($c->commission_amount) * 100)) < 0 ? (string) $c->attribution : 'sale', 'attribution' => (string) $c->attribution, 'tickets' => intval($c->tickets_qty),
                'revenue' => floatval($c->order_total), 'commission' => floatval($c->commission_amount), 'status' => (string) $c->status, 'payout_id' => $c->payout_id ? intval($c->payout_id) : null];
        }, (array) TIX_Promoter_DB::get_commissions(array_filter(['promoter_id' => intval($p->id), 'date_from' => $from, 'date_to' => $to, 'limit' => 200])));
        $clicks = TIX_Promoter_DB::get_click_stats(intval($p->id));
        $out['clicks_by_day'] = array_map(function ($d) { return ['date' => (string) $d->click_date, 'clicks' => intval($d->clicks), 'visitors' => intval($d->uniques)]; }, (array) ($clicks['timeseries'] ?? []));
        $out['payouts'] = array_map([__CLASS__, 'payout_item'], (array) TIX_Promoter_DB::get_payouts(['promoter_id' => intval($p->id)]));
        // Auszahlungsdaten des Promoters (aus seinem Bereich) – voll, weil der Veranstalter überweist
        $out['payout_details'] = class_exists('TIX_Promoter_Portal') ? TIX_Promoter_Portal::payout_details(intval($p->id)) : null;
        $r = rest_ensure_response(['ok' => true, 'promoter' => $out]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    public static function rest_update(WP_REST_Request $req) {
        $org = self::org($req);
        $p = self::ready() ? self::own($req['id'], $org) : null;
        if (!$p) return self::nf();
        $set = [];
        if ($req->has_param('name')) { $n = mb_substr(trim(sanitize_text_field((string) $req->get_param('name'))), 0, 120); if ($n === '') return self::bad('name', 'Bitte einen Namen angeben.'); $set['display_name'] = $n; }
        if ($req->has_param('email')) { $e = strtolower(sanitize_email((string) $req->get_param('email'))); if ($req->get_param('email') !== '' && !is_email($e)) return self::bad('email', 'Bitte eine gültige E-Mail-Adresse angeben.'); $set['email'] = $e; }
        if ($req->has_param('notes')) $set['notes'] = mb_substr(sanitize_textarea_field((string) $req->get_param('notes')), 0, 1000);
        if ($req->has_param('status')) { $s = (string) $req->get_param('status'); if (!in_array($s, ['active', 'inactive'], true)) return self::bad('status', 'Status: active oder inactive.'); $set['status'] = $s; }
        $approve = isset($set['status']) && $set['status'] === 'active' && $p->status === 'pending'; // Selbst-Anmeldung freigegeben
        if ($req->has_param('code')) {
            $c = strtolower(trim((string) $req->get_param('code')));
            if ($c !== $p->promoter_code) {
                if ($e = self::valid_code($c, $p->id)) return $e;
                $set['promoter_code'] = $c; // alte Links ordnen danach nicht mehr zu (Hinweis im Studio)
            }
        }
        if (class_exists('TIX_Promoter_Partner')) { list($pset, $perr) = TIX_Promoter_Partner::fields($req, $p); if ($perr) return $perr; if ($pset) { global $wpdb; $wpdb->update(TIX_Promoter_DB::table_promoters(), $pset, ['id' => intval($p->id)]); } }
        if ($set) TIX_Promoter_DB::update_promoter(intval($p->id), $set);
        if ($approve) do_action('tix_promoter_approved', intval($p->id)); // Regeln: automatische Zuordnung
        // Nach der Freigabe bekommt der Promoter seinen Zugang per Mail (Studio-Domain)
        $sent = $approve && class_exists('TIX_Promoter_Portal') && TIX_Promoter_Portal::send_login(TIX_Promoter_DB::get_promoter(intval($p->id))) === true;
        return rest_ensure_response(['ok' => true, 'promoter' => self::item(TIX_Promoter_DB::get_promoter(intval($p->id))), 'access_sent' => $sent]);
    }

    public static function rest_delete(WP_REST_Request $req) {
        $org = self::org($req);
        $p = self::ready() ? self::own($req['id'], $org) : null;
        if (!$p) return self::nf();
        TIX_Promoter_DB::delete_promoter(intval($p->id)); // deaktivieren: Provisionen und Auszahlungen bleiben (Buchhaltung)
        return rest_ensure_response(['ok' => true, 'status' => 'inactive']);
    }

    public static function rest_access(WP_REST_Request $req) {
        $p = self::ready() ? self::own($req['id'], self::org($req)) : null;
        if (!$p) return self::nf();
        if (!in_array($p->status, ['active', 'pending'], true)) return self::bad('status', 'Der Promoter ist deaktiviert.', 'tix_promoter_inactive', 409);
        $r = TIX_Promoter_Auth::send_login_link($p);
        return is_wp_error($r) ? $r : rest_ensure_response(['ok' => true, 'sent' => true]);
    }

    public static function rest_logout_all(WP_REST_Request $req) {
        $p = self::ready() ? self::own($req['id'], self::org($req)) : null;
        if (!$p) return self::nf();
        TIX_Promoter_Auth::revoke_sessions(intval($p->id));
        return rest_ensure_response(['ok' => true]);
    }

    // ═══════════════ Provision je Event ═══════════════

    /** Zuordnung prüfen und speichern → assignment id | WP_Error */
    private static function assign($pid, $eid, array $c, array $d) {
        $ct = (string) ($c['type'] ?? 'percent');
        if (!in_array($ct, ['percent', 'fixed'], true)) return self::bad('commission_type', 'Provision: percent oder fixed.');
        $cv = round(floatval(str_replace(',', '.', (string) ($c['value'] ?? 0))), 2);
        if ($cv < 0 || ($ct === 'percent' && $cv > 50) || ($ct === 'fixed' && $cv > 100)) return self::bad('commission_value', 'Provision: 0–50 % bzw. 0–100 € je Ticket.');
        $dt = (string) ($d['type'] ?? '');
        $dv = round(floatval(str_replace(',', '.', (string) ($d['value'] ?? 0))), 2);
        $promo = strtoupper(trim((string) ($d['promo_code'] ?? '')));
        if ($dt !== '' && !in_array($dt, ['percent', 'fixed'], true)) return self::bad('discount_type', 'Rabatt: percent oder fixed.');
        if ($dt !== '' && ($dv <= 0 || ($dt === 'percent' && $dv > 100))) return self::bad('discount_value', 'Bitte einen Rabatt größer 0 angeben.');
        if ($dt !== '' && $promo === '') return self::bad('promo_code', 'Ein Rabatt braucht einen Promo-Code (Gäste geben ihn in der Kasse ein).');
        if ($promo !== '') {
            if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $promo)) return self::bad('promo_code', 'Promo-Code: 3–40 Zeichen, A–Z, 0–9, _ und -.');
            if ($eid === 0) return self::bad('promo_code', 'Promo-Codes gelten je Event – bitte ein Event wählen.');
            foreach (array_keys((array) get_option('tix_coupons', [])) as $k) if (strcasecmp((string) $k, $promo) === 0) return self::bad('promo_code', 'Diesen Code gibt es schon als Gutschein.', 'tix_code_taken', 409);
            $a = TIX_Promoter_DB::get_assignment_by_promo_code($promo);
            if ($a && (intval($a->promoter_id) !== intval($pid) || intval($a->event_id) !== intval($eid))) return self::bad('promo_code', 'Diesen Promo-Code nutzt schon ein anderer Promoter.', 'tix_code_taken', 409);
        }
        return TIX_Promoter_DB::assign_event(['promoter_id' => $pid, 'event_id' => $eid, 'commission_type' => $ct, 'commission_value' => $cv,
            'discount_type' => $dt, 'discount_value' => $dt !== '' ? $dv : 0, 'promo_code' => $promo, 'status' => 'active']);
    }

    public static function rest_assign(WP_REST_Request $req) {
        $p = self::ready() ? self::own($req['id'], self::org($req)) : null;
        if (!$p) return self::nf();
        $eid = absint($req->get_param('event_id'));
        if ($eid && !self::in_scope($eid)) return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404, 'field' => 'event_id']);
        $r = self::assign(intval($p->id), $eid, ['type' => $req->get_param('commission_type'), 'value' => $req->get_param('commission_value')],
            ['type' => (string) $req->get_param('discount_type'), 'value' => $req->get_param('discount_value'), 'promo_code' => (string) $req->get_param('promo_code')]);
        if (is_wp_error($r)) return $r;
        // Kategorie-Sätze je Partner/Promoter und Event (R1, 1.38.812): [{cat_index, type, value}]
        if ($req->has_param('category_rates') && class_exists('TIX_Promoter_Partner')) {
            list($json, $err) = TIX_Promoter_Partner::clean_rates($req->get_param('category_rates'), $eid);
            if ($err) return $err;
            global $wpdb;
            $wpdb->update(TIX_Promoter_DB::table_events(), ['category_rates' => $json], ['promoter_id' => intval($p->id), 'event_id' => $eid]);
        }
        $req2 = new WP_REST_Request('GET'); $req2->set_url_params(['id' => $p->id]); $req2->set_query_params(['organizer' => $req->get_param('organizer')]);
        return self::rest_get($req2);
    }

    public static function rest_unassign(WP_REST_Request $req) {
        global $wpdb;
        $p = self::ready() ? self::own($req['id'], self::org($req)) : null;
        if (!$p) return self::nf();
        $n = $wpdb->update(TIX_Promoter_DB::table_events(), ['status' => 'ended'], ['promoter_id' => intval($p->id), 'event_id' => absint($req['event_id']), 'status' => 'active']);
        return $n ? rest_ensure_response(['ok' => true]) : new WP_Error('not_found', 'Zuordnung nicht gefunden.', ['status' => 404]);
    }

    // ═══════════════ Einstellungen der Seite ═══════════════

    /** Einstellungen gelten für die ganze Seite: Einzel-Seite Inhaber/Admin, evendis nur Admin */
    private static function can_settings() {
        return current_user_can('manage_options') || !(class_exists('TIX_App_Scope') && TIX_App_Scope::multi());
    }

    public static function rest_settings(WP_REST_Request $req) {
        if (!self::can_settings()) return new WP_Error('rest_forbidden', 'Die Promoter-Einstellungen der Plattform ändert nur ein Admin.', ['status' => 403]);
        $s = (array) get_option('tix_settings', []);
        if ($req->has_param('enabled')) $s['promoter_enabled'] = rest_sanitize_boolean($req->get_param('enabled')) ? 1 : 0;
        if ($req->has_param('self_signup')) $s['promoter_self_signup'] = rest_sanitize_boolean($req->get_param('self_signup')) ? 1 : 0;
        if ($req->has_param('cookie_days')) {
            $d = intval($req->get_param('cookie_days'));
            if ($d < 1 || $d > 90) return self::bad('cookie_days', 'Laufzeit: 1–90 Tage.');
            $s['promoter_cookie_days'] = $d;
        }
        $c = $req->get_param('default_commission');
        if (is_array($c)) {
            $t = (string) ($c['type'] ?? 'percent');
            $v = round(floatval($c['value'] ?? 0), 2);
            if (!in_array($t, ['percent', 'fixed'], true) || $v < 0 || ($t === 'percent' && $v > 50) || ($t === 'fixed' && $v > 100)) return self::bad('default_commission', 'Provision: 0–50 % bzw. 0–100 € je Ticket.');
            $s['promoter_default_commission_type'] = $t;
            $s['promoter_default_commission_value'] = $v;
        }
        update_option('tix_settings', $s);
        return rest_ensure_response(['ok' => true, 'enabled' => !empty($s['promoter_enabled']), 'self_signup' => !empty($s['promoter_self_signup']), 'cookie_days' => intval($s['promoter_cookie_days'] ?? 30) ?: 30,
            'default_commission' => ['type' => (string) ($s['promoter_default_commission_type'] ?? 'percent'), 'value' => floatval($s['promoter_default_commission_value'] ?? 0)]]);
    }

    // ═══════════════ Auszahlungen ═══════════════

    public static function payout_item($po) {
        return ['id' => intval($po->id), 'promoter_id' => intval($po->promoter_id), 'promoter_name' => (string) ($po->promoter_name ?? ''), 'from' => (string) $po->period_from, 'to' => (string) $po->period_to,
            'revenue' => floatval($po->total_sales), 'commission' => floatval($po->total_commission), 'count' => intval($po->commission_count), 'status' => (string) $po->status,
            'paid_at' => $po->paid_date ? mysql_to_rfc3339($po->paid_date) : null, 'note' => (string) ($po->payment_note ?? ''), 'created' => $po->created_at ? mysql_to_rfc3339($po->created_at) : null]
            + (function ($po) { $d = !empty($po->doc_data) ? json_decode((string) $po->doc_data, true) : null;
                return ['credit_note' => is_array($d) ? ['number' => (string) $d['number'], 'net' => floatval($d['net']), 'vat' => floatval($d['vat']), 'gross' => floatval($d['gross'])] : null]; })($po);
    }

    private static function own_payout($id, $org) {
        $po = TIX_Promoter_DB::get_payout(intval($id));
        return ($po && self::own($po->promoter_id, $org)) ? $po : null;
    }

    public static function rest_payouts(WP_REST_Request $req) {
        if (!self::ready()) return rest_ensure_response(['ok' => true, 'payouts' => []]);
        global $wpdb;
        $org = self::org($req);
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM " . TIX_Promoter_DB::table_promoters() . " WHERE organizer_id = %d", $org)));
        $pid = absint($req->get_param('promoter_id'));
        if ($pid && !in_array($pid, $ids, true)) return self::nf();
        $rows = [];
        foreach ($pid ? [$pid] : $ids as $i) foreach ((array) TIX_Promoter_DB::get_payouts(array_filter(['promoter_id' => $i, 'status' => (string) $req->get_param('status')])) as $po) $rows[] = self::payout_item($po);
        usort($rows, function ($a, $b) { return strcmp((string) $b['created'], (string) $a['created']); });
        return rest_ensure_response(['ok' => true, 'payouts' => $rows]);
    }

    public static function rest_payout_create(WP_REST_Request $req) {
        $p = self::ready() ? self::own($req->get_param('promoter_id'), self::org($req)) : null;
        if (!$p) return self::nf();
        list($from, $to) = self::period($req);
        $from = $from ?: '2000-01-01';
        $to = $to ?: current_time('Y-m-d');
        if ($from > $to) return self::bad('from', '„Von“ liegt nach „Bis“.');
        // Mindest-Auszahlung (Regeln, 1.38.663)
        if (class_exists('TIX_Promoter_Rules')) {
            $sum = array_sum(array_map(function ($c) { return floatval($c->commission_amount); }, (array) TIX_Promoter_DB::get_commissions_for_payout(intval($p->id), $from, $to)));
            if ($sum > 0 && ($e = TIX_Promoter_Rules::payout_error($p, $sum))) return $e;
        }
        $id = TIX_Promoter_DB::create_payout(['promoter_id' => intval($p->id), 'period_from' => $from, 'period_to' => $to, 'payment_note' => (string) $req->get_param('note')]);
        if (!$id) {
            $wait = array_filter((array) TIX_Promoter_DB::get_commissions_for_payout_all(intval($p->id), $from, $to), function ($c) { return floatval($c->commission_amount) > 0 && !TIX_Promoter_DB::event_ended(intval($c->event_id)); });
            if ($wait) return self::bad('period', 'Provision wird erst nach dem Event auszahlbar.', 'tix_payout_waiting', 409);
            return self::bad('period', 'Im Zeitraum gibt es keine offene Provision.', 'tix_payout_empty', 409);
        }
        if (class_exists('TIX_Promoter_Partner') && TIX_Promoter_Partner::is_partner($p)) { $doc = TIX_Promoter_Partner::issue($id); if (is_wp_error($doc)) error_log('TIX Partner-Gutschrift #' . $id . ': ' . $doc->get_error_message()); }
        return new WP_REST_Response(['ok' => true, 'payout' => self::payout_item(TIX_Promoter_DB::get_payout($id))], 201);
    }

    public static function rest_payout(WP_REST_Request $req) {
        $po = self::ready() ? self::own_payout($req['id'], self::org($req)) : null;
        if (!$po) return new WP_Error('not_found', 'Auszahlung nicht gefunden.', ['status' => 404]);
        $rows = array_map(function ($c) { return ['date' => mysql_to_rfc3339($c->created_at), 'event_title' => html_entity_decode((string) ($c->event_title ?: ''), ENT_QUOTES, 'UTF-8'), 'order_id' => intval($c->order_id),
            'attribution' => (string) $c->attribution, 'tickets' => intval($c->tickets_qty), 'revenue' => floatval($c->order_total), 'commission' => floatval($c->commission_amount)]; },
            (array) TIX_Promoter_DB::get_commissions(['payout_id' => intval($po->id)]));
        return rest_ensure_response(['ok' => true, 'payout' => self::payout_item($po) + ['lines' => $rows]]);
    }

    public static function rest_payout_paid(WP_REST_Request $req) {
        $po = self::ready() ? self::own_payout($req['id'], self::org($req)) : null;
        if (!$po) return new WP_Error('not_found', 'Auszahlung nicht gefunden.', ['status' => 404]);
        if ($po->status !== 'pending') return self::bad('status', 'Nur offene Auszahlungen lassen sich als bezahlt markieren.', 'tix_payout_locked', 409);
        TIX_Promoter_DB::mark_payout_paid(intval($po->id), (string) $req->get_param('note'));
        return rest_ensure_response(['ok' => true, 'payout' => self::payout_item(TIX_Promoter_DB::get_payout(intval($po->id)))]);
    }

    public static function rest_payout_cancel(WP_REST_Request $req) {
        $po = self::ready() ? self::own_payout($req['id'], self::org($req)) : null;
        if (!$po) return new WP_Error('not_found', 'Auszahlung nicht gefunden.', ['status' => 404]);
        if ($po->status !== 'pending') return self::bad('status', 'Bezahlte Auszahlungen lassen sich nicht zurücknehmen.', 'tix_payout_locked', 409);
        TIX_Promoter_DB::cancel_payout(intval($po->id));
        return rest_ensure_response(['ok' => true]);
    }
}

TIX_Promoter_REST::init();
