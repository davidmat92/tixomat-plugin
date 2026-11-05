<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Promoter_Rules – Regeln des Promoter-Programms je Bereich (Promoter-Ausbau Paket 3, 1.38.663).
 *
 * Bereich: Einzel-Seite = Option `tix_promoter_rules`, Plattform = Meta `_tix_promoter_rules` am Veranstalter
 * (Inhaber/Team-Admin ändern die eigenen Regeln).
 *   min_payout    Mindestbetrag für eine Auszahlung (0 = keiner)
 *   tiers         Staffel-Bonus je Promoter und Event: [{min_tickets, type fixed|percent, value}] – ab dem n-ten Ticket
 *                 gibt es zusätzlich fixed € je Ticket bzw. percent % vom Ticketpreis (höchste erreichte Stufe)
 *   cap_tickets   Obergrenze Tickets mit Provision je Promoter und Event (0 = keine)
 *   cap_amount    Obergrenze Provision je Promoter und Event in € (0 = keine)
 *   team          {enabled, share} – Teamleiter bekommen share % der Provision ihrer Unter-Promoter zusätzlich
 *   auto_approve  Selbst-Anmeldung sofort freigeben
 *   auto_assign   {enabled, type, value} – freigegebene Promoter bekommen „Alle Events“ mit dieser Provision
 *   attribution   last (Vorgabe) | first – zählt der letzte oder der erste Promoter-Link
 *   leaderboard   Rangliste im Promoter-Bereich
 * Je Event: Provision je Ticket-Kategorie (`_tix_promoter_cat_commission` {cat_index: {type, value}}), vor allen
 * anderen Provisionen. Je Promoter (Spalten): parent_id (Teamleiter), leader_share (eigener Anteil), cookie_days.
 * Kurzlink: <Seite>/go/<code>[?e=<event_id>] über die vorhandenen Kurzlinks (TIX_Short_Links, GET /public/go/{code}?e=):
 * hat kein Marketing-Link den Code, führt er auf das Event bzw. die Startseite mit ?ref (und &coupon).
 */
class TIX_Promoter_Rules {

    const NS = 'tixomat/v1';
    const OPT = 'tix_promoter_rules';
    const META = '_tix_promoter_rules';
    const META_CAT = '_tix_promoter_cat_commission';
    const DEFAULTS = ['min_payout' => 0.0, 'tiers' => [], 'cap_tickets' => 0, 'cap_amount' => 0.0, 'team' => ['enabled' => false, 'share' => 10.0],
        'auto_approve' => false, 'auto_assign' => ['enabled' => false, 'type' => 'percent', 'value' => 0.0], 'attribution' => 'last', 'leaderboard' => false];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('tix_promoter_approved', [__CLASS__, 'on_approved']);
        // Kurzlink /go/<code>: Rückfall der Marketing-Kurzlinks (TIX_Short_Links), wenn kein Link den Code hat
        add_filter('tix_go_fallback', function ($url, $code, $eid, $qr) { return $url ?: self::go_target($code, $eid, $qr); }, 10, 4);
    }

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }
    private static function bad($field, $msg, $code = 'tix_invalid_field', $status = 400) { return new WP_Error($code, $msg, ['status' => $status, 'field' => $field]); }

    // ═══════════════ Regeln ═══════════════

    public static function get($org = 0) {
        $raw = (self::multi() && $org) ? get_post_meta(intval($org), self::META, true) : get_option(self::OPT, []);
        $r = array_replace_recursive(self::DEFAULTS, is_array($raw) ? $raw : []);
        // Bestand: alter Schalter promoter_signup_auto_events (Einzel-Seite)
        if (!is_array($raw) || !isset($raw['auto_assign'])) {
            $s = get_option('tix_settings', []);
            if (!self::multi() && !empty($s['promoter_signup_auto_events'])) $r['auto_assign'] = ['enabled' => true, 'type' => in_array($s['promoter_signup_commission_type'] ?? '', ['percent', 'fixed'], true) ? $s['promoter_signup_commission_type'] : 'fixed', 'value' => floatval($s['promoter_signup_commission_value'] ?? 2)];
        }
        $r['tiers'] = array_values((array) $r['tiers']);
        return $r;
    }

    public static function for_promoter($p) {
        return self::get(self::multi() ? intval($p->organizer_id ?? 0) : 0);
    }

    /** Regeln prüfen und speichern → Regeln | WP_Error */
    public static function save($org, array $in) {
        $r = self::get($org);
        $num = function ($v) { return round(floatval($v), 2); };
        if (array_key_exists('min_payout', $in)) { $v = $num($in['min_payout']); if ($v < 0 || $v > 1000) return self::bad('min_payout', 'Mindest-Auszahlung: 0–1.000 €.'); $r['min_payout'] = $v; }
        if (array_key_exists('cap_tickets', $in)) { $v = intval($in['cap_tickets']); if ($v < 0 || $v > 100000) return self::bad('cap_tickets', 'Obergrenze Tickets: 0 (keine) bis 100.000.'); $r['cap_tickets'] = $v; }
        if (array_key_exists('cap_amount', $in)) { $v = $num($in['cap_amount']); if ($v < 0 || $v > 100000) return self::bad('cap_amount', 'Obergrenze Provision: 0 (keine) bis 100.000 €.'); $r['cap_amount'] = $v; }
        if (array_key_exists('tiers', $in)) {
            $t = [];
            foreach ((array) $in['tiers'] as $x) {
                if (!is_array($x)) continue;
                $min = intval($x['min_tickets'] ?? 0); $type = (string) ($x['type'] ?? 'fixed'); $val = $num($x['value'] ?? 0);
                if ($min < 2 || $min > 100000) return self::bad('tiers', 'Staffel ab mindestens 2 Tickets.');
                if (!in_array($type, ['fixed', 'percent'], true) || $val <= 0 || ($type === 'percent' && $val > 50) || ($type === 'fixed' && $val > 100)) return self::bad('tiers', 'Bonus: 0–50 % bzw. 0–100 € je Ticket.');
                $t[$min] = ['min_tickets' => $min, 'type' => $type, 'value' => $val];
            }
            if (count($t) > 10) return self::bad('tiers', 'Höchstens 10 Stufen.');
            ksort($t);
            $r['tiers'] = array_values($t);
        }
        if (array_key_exists('team', $in) && is_array($in['team'])) {
            $sh = $num($in['team']['share'] ?? $r['team']['share']);
            if ($sh < 0 || $sh > 50) return self::bad('team', 'Anteil für Teamleiter: 0–50 %.');
            $r['team'] = ['enabled' => !empty($in['team']['enabled']), 'share' => $sh];
        }
        if (array_key_exists('auto_approve', $in)) $r['auto_approve'] = (bool) filter_var($in['auto_approve'], FILTER_VALIDATE_BOOLEAN);
        if (array_key_exists('auto_assign', $in) && is_array($in['auto_assign'])) {
            $type = (string) ($in['auto_assign']['type'] ?? 'percent'); $val = $num($in['auto_assign']['value'] ?? 0);
            if (!in_array($type, ['fixed', 'percent'], true) || $val < 0 || ($type === 'percent' && $val > 50) || ($type === 'fixed' && $val > 100)) return self::bad('auto_assign', 'Provision: 0–50 % bzw. 0–100 € je Ticket.');
            $r['auto_assign'] = ['enabled' => !empty($in['auto_assign']['enabled']), 'type' => $type, 'value' => $val];
        }
        if (array_key_exists('attribution', $in)) { if (!in_array($in['attribution'], ['first', 'last'], true)) return self::bad('attribution', 'Zuordnung: first oder last.'); $r['attribution'] = $in['attribution']; }
        if (array_key_exists('leaderboard', $in)) $r['leaderboard'] = (bool) filter_var($in['leaderboard'], FILTER_VALIDATE_BOOLEAN);
        if (self::multi() && $org) update_post_meta(intval($org), self::META, $r); else update_option(self::OPT, $r, false);
        return $r;
    }

    // ═══════════════ Spalten je Promoter ═══════════════

    public static function ensure_columns() {
        static $done = false;
        if ($done) return;
        $done = true;
        global $wpdb;
        $t = TIX_Promoter_DB::table_promoters();
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) return;
        $cols = array_map('strtolower', (array) $wpdb->get_col("SHOW COLUMNS FROM $t"));
        if (!in_array('parent_id', $cols, true)) $wpdb->query("ALTER TABLE $t ADD COLUMN parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD INDEX parent_id (parent_id)");
        if (!in_array('leader_share', $cols, true)) $wpdb->query("ALTER TABLE $t ADD COLUMN leader_share DECIMAL(5,2) NULL DEFAULT NULL");
        if (!in_array('cookie_days', $cols, true)) $wpdb->query("ALTER TABLE $t ADD COLUMN cookie_days SMALLINT UNSIGNED NOT NULL DEFAULT 0");
    }

    private static function row($pid) {
        global $wpdb;
        self::ensure_columns();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Promoter_DB::table_promoters() . " WHERE id = %d", intval($pid)));
    }

    /** Felder für Studio-Listen: {leader {id, name}|null, leader_share, cookie_days, team_size} */
    public static function promoter_fields($p) {
        global $wpdb;
        $r = self::row(intval($p->id));
        if (!$r) return [];
        $leader = intval($r->parent_id) ? TIX_Promoter_DB::get_promoter(intval($r->parent_id)) : null;
        $team = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Promoter_DB::table_promoters() . " WHERE parent_id = %d AND status = 'active'", intval($p->id))));
        return ['leader' => $leader ? ['id' => intval($leader->id), 'name' => (string) $leader->display_name] : null,
            'leader_share' => $r->leader_share !== null ? floatval($r->leader_share) : null, 'cookie_days' => intval($r->cookie_days) ?: null, 'team_size' => $team];
    }

    /** Cookie-Laufzeit für einen Promoter (eigene, sonst Seite) */
    public static function cookie_days($p) {
        $r = $p ? self::row(intval($p->id)) : null;
        if ($r && intval($r->cookie_days) > 0) return intval($r->cookie_days);
        $s = get_option('tix_settings', []);
        return intval($s['promoter_cookie_days'] ?? 30) ?: 30;
    }

    // ═══════════════ Provision ═══════════════

    public static function cat_commission($event_id) {
        $v = get_post_meta(intval($event_id), self::META_CAT, true);
        return is_array($v) ? $v : [];
    }

    /** Kategorie-Index einer Bestellposition (Meta, sonst Name) oder -1 */
    public static function cat_index_of($item, $event_id) {
        if (is_object($item) && method_exists($item, 'get_meta')) {
            $ci = $item->get_meta('cat_index');
            if ($ci !== '' && $ci !== null && is_numeric($ci)) return intval($ci);
            $name = method_exists($item, 'get_cat_name') ? (string) $item->get_cat_name() : '';
            if ($name !== '') foreach ((array) get_post_meta($event_id, '_tix_ticket_categories', true) as $i => $c) if (is_array($c) && strcasecmp((string) ($c['name'] ?? ''), $name) === 0) return intval($i);
        }
        return -1;
    }

    /** Bisherige Tickets und Provision des Promoters am Event (ohne Team- und Storno-Zeilen) */
    private static function so_far($pid, $event_id) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT COALESCE(SUM(tickets_qty),0) t, COALESCE(SUM(commission_amount),0) c FROM " . TIX_Promoter_DB::table_commissions() . "
            WHERE promoter_id = %d AND event_id = %d AND status != 'cancelled' AND attribution != 'team'", intval($pid), intval($event_id)));
        return [intval($r->t ?? 0), floatval($r->c ?? 0)];
    }

    /**
     * Provision einer Position nach den Regeln: Kategorie-Provision, Staffel-Bonus, Obergrenzen.
     * → {commission, bonus, base, capped}
     */
    public static function apply($promoter, $event_id, $cat_index, $qty, $line_total, array $eff) {
        $rules = self::for_promoter($promoter);
        $qty = max(0, intval($qty));
        $unit = $qty > 0 ? $line_total / $qty : 0.0;
        $cat = self::cat_commission($event_id);
        if ($cat_index >= 0 && isset($cat[$cat_index]) && is_array($cat[$cat_index]) && floatval($cat[$cat_index]['value'] ?? 0) > 0) {
            $eff = ['type' => (string) $cat[$cat_index]['type'], 'value' => floatval($cat[$cat_index]['value']), 'source' => 'category'];
        }
        list($t0, $c0) = self::so_far(intval($promoter->id), $event_id);
        // Obergrenze Tickets: nur Tickets bis zur Grenze zählen
        $count = $qty;
        if ($rules['cap_tickets'] > 0) $count = max(0, min($qty, $rules['cap_tickets'] - $t0));
        $base = $eff['source'] === 'none' ? 0.0 : ($eff['type'] === 'percent' ? $unit * $count * $eff['value'] / 100 : $eff['value'] * $count);
        // Staffel-Bonus: Ticket Nr. k (t0+1 … t0+count) bekommt den Bonus der höchsten Stufe mit min_tickets ≤ k
        $bonus = 0.0;
        if ($rules['tiers'] && $eff['source'] !== 'none') {
            for ($k = $t0 + 1; $k <= $t0 + $count; $k++) {
                $tier = null;
                foreach ($rules['tiers'] as $x) if ($x['min_tickets'] <= $k) $tier = $x;
                if ($tier) $bonus += $tier['type'] === 'percent' ? $unit * $tier['value'] / 100 : $tier['value'];
            }
        }
        $sum = $base + $bonus;
        $capped = $count < $qty;
        if ($rules['cap_amount'] > 0 && $c0 + $sum > $rules['cap_amount']) { $sum = max(0.0, $rules['cap_amount'] - $c0); $capped = true; }
        return ['commission' => round($sum, 2), 'bonus' => round($bonus, 2), 'base' => round($base, 2), 'capped' => $capped, 'source' => $eff['source']];
    }

    /** Teamleiter-Provision zur Provision eines Unter-Promoters (eigene Zeile, attribution team) */
    public static function leader_commission($promoter, array $row) {
        $r = self::row(intval($promoter->id));
        if (!$r || !intval($r->parent_id) || floatval($row['commission_amount']) <= 0) return 0;
        $rules = self::for_promoter($promoter);
        if (empty($rules['team']['enabled'])) return 0;
        $leader = TIX_Promoter_DB::get_promoter(intval($r->parent_id));
        if (!$leader || $leader->status !== 'active' || intval($leader->organizer_id ?? 0) !== intval($promoter->organizer_id ?? 0)) return 0;
        $share = $r->leader_share !== null ? floatval($r->leader_share) : floatval($rules['team']['share']);
        $amt = round(floatval($row['commission_amount']) * $share / 100, 2);
        if ($amt <= 0) return 0;
        return TIX_Promoter_DB::insert_commission(['promoter_id' => intval($leader->id), 'event_id' => intval($row['event_id']), 'order_id' => intval($row['order_id']),
            'order_item_id' => intval($row['order_item_id'] ?? 0), 'attribution' => 'team', 'tickets_qty' => 0, 'order_total' => 0, 'commission_amount' => $amt]);
    }

    /** Freigabe: automatische Zuordnung „Alle Events“ */
    public static function on_approved($pid) {
        $p = TIX_Promoter_DB::get_promoter(intval($pid));
        if (!$p) return;
        $r = self::for_promoter($p);
        if (empty($r['auto_assign']['enabled']) || floatval($r['auto_assign']['value']) <= 0) return;
        if (TIX_Promoter_DB::get_global_assignment(intval($pid))) return;
        TIX_Promoter_DB::assign_event(['promoter_id' => intval($pid), 'event_id' => 0, 'commission_type' => $r['auto_assign']['type'], 'commission_value' => floatval($r['auto_assign']['value']), 'status' => 'active']);
    }

    /** Mindest-Auszahlung: Fehler, wenn der auszahlbare Betrag darunter liegt */
    public static function payout_error($promoter, $amount) {
        $min = floatval(self::for_promoter($promoter)['min_payout']);
        if ($min > 0 && round($amount, 2) < $min) return new WP_Error('tix_payout_min', sprintf('Auszahlung erst ab %s €.', number_format($min, 2, ',', '.')), ['status' => 409, 'field' => 'period', 'min_payout' => $min]);
        return null;
    }

    // ═══════════════ Kurzlink /go/ ═══════════════

    /** Ziel eines Kurzlinks → URL mit ?ref (und &coupon beim Event) | null */
    public static function go_target($code, $event_id = 0, $qr = false) {
        $p = TIX_Promoter_DB::get_promoter_by_code(strtolower(sanitize_text_field((string) $code)));
        if (!$p) return null;
        $eid = intval($event_id);
        $base = class_exists('TIX_Customer_URL') ? (string) TIX_Customer_URL::base() : '';
        $base = trailingslashit($base !== '' ? $base : home_url('/'));
        if ($eid && get_post_type($eid) === 'event' && get_post_status($eid) === 'publish' && !TIX_Promoter::excluded_event($eid)) {
            $u = (string) get_permalink($eid);
            if (class_exists('TIX_Customer_URL')) $u = TIX_Customer_URL::to($u);
            $a = TIX_Promoter_DB::get_assignment_by_promoter_event(intval($p->id), $eid, true);
            return TIX_Promoter_REST::links((string) $p->promoter_code, $u, TIX_Promoter_REST::link_coupon($a))[$qr ? 'qr_link' : 'link'];
        }
        return TIX_Promoter_REST::links((string) $p->promoter_code, $base)[$qr ? 'qr_link' : 'link'];
    }

    public static function short_link($code, $event_id = 0) {
        $base = class_exists('TIX_Customer_URL') ? (string) TIX_Customer_URL::base() : '';
        $base = trailingslashit($base !== '' ? $base : home_url('/'));
        return $base . 'go/' . rawurlencode((string) $code) . ($event_id ? '?e=' . intval($event_id) : '');
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $p = ['TIX_Ad_Accounts', 'perm'];
        $r = function ($path, $m, $cb, $perm) { register_rest_route(self::NS, $path, ['methods' => $m, 'callback' => [__CLASS__, $cb], 'permission_callback' => $perm]); };
        $r('/organizer/promoter-rules', 'GET', 'rest_rules', $p);
        $r('/organizer/promoter-rules', 'POST', 'rest_rules_save', $p);
        $r('/organizer/events/(?P<id>\d+)/promoter-categories', 'GET', 'rest_cats', $p);
        $r('/organizer/events/(?P<id>\d+)/promoter-categories', 'POST', 'rest_cats_save', $p);
        $r('/organizer/promoters/(?P<id>\d+)/rules', 'POST', 'rest_promoter_save', $p);
        $r('/organizer/promoters/export', 'GET', 'rest_export', $p);
        $r('/promoter/leaderboard', 'GET', 'rest_leaderboard', ['TIX_Promoter_Portal', 'perm']);
        $r('/public/promoter-config', 'GET', 'rest_public_config', '__return_true');
    }

    private static function org(WP_REST_Request $req) { return TIX_Ad_Accounts::org($req); }

    public static function rest_rules(WP_REST_Request $req) {
        return rest_ensure_response(['ok' => true, 'rules' => self::get(self::org($req))]);
    }

    public static function rest_rules_save(WP_REST_Request $req) {
        $org = self::org($req);
        if (self::multi() && !$org) return self::bad('organizer', 'Bitte einen Veranstalter wählen.');
        $r = self::save($org, (array) $req->get_json_params() + (array) $req->get_body_params());
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(['ok' => true, 'rules' => $r]);
    }

    private static function own_event($eid) {
        return get_post_type($eid) === 'event' && in_array(intval($eid), array_map('intval', TIX_Studio_Stats::scope_ids()), true);
    }

    public static function rest_cats(WP_REST_Request $req) {
        $eid = intval($req['id']);
        if (!self::own_event($eid)) return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        $cc = self::cat_commission($eid);
        $out = [];
        foreach ((array) get_post_meta($eid, '_tix_ticket_categories', true) as $i => $c) {
            if (!is_array($c)) continue;
            $out[] = ['index' => intval($i), 'name' => (string) ($c['name'] ?? ''), 'price' => floatval($c['price'] ?? 0), 'commission' => isset($cc[$i]) ? ['type' => (string) $cc[$i]['type'], 'value' => floatval($cc[$i]['value'])] : null];
        }
        return rest_ensure_response(['ok' => true, 'event_id' => $eid, 'categories' => $out]);
    }

    /** POST {categories: [{index, commission: {type, value} | null}]} */
    public static function rest_cats_save(WP_REST_Request $req) {
        $eid = intval($req['id']);
        if (!self::own_event($eid)) return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        $cats = (array) get_post_meta($eid, '_tix_ticket_categories', true);
        $cc = self::cat_commission($eid);
        foreach ((array) $req->get_param('categories') as $x) {
            if (!is_array($x) || !isset($x['index']) || !isset($cats[intval($x['index'])])) return self::bad('categories', 'Unbekannte Kategorie.');
            $i = intval($x['index']);
            $c = $x['commission'] ?? null;
            if (!is_array($c) || floatval($c['value'] ?? 0) <= 0) { unset($cc[$i]); continue; }
            $type = (string) ($c['type'] ?? 'percent'); $val = round(floatval($c['value']), 2);
            if (!in_array($type, ['percent', 'fixed'], true) || ($type === 'percent' && $val > 50) || ($type === 'fixed' && $val > 100)) return self::bad('categories', 'Provision: 0–50 % bzw. 0–100 € je Ticket.');
            $cc[$i] = ['type' => $type, 'value' => $val];
        }
        if ($cc) update_post_meta($eid, self::META_CAT, $cc); else delete_post_meta($eid, self::META_CAT);
        return self::rest_cats($req);
    }

    /** POST /organizer/promoters/{id}/rules {leader_id (0 = keiner), leader_share (null = Regel), cookie_days (0 = Seite)} */
    public static function rest_promoter_save(WP_REST_Request $req) {
        global $wpdb;
        $org = self::org($req);
        $p = TIX_Promoter_DB::get_promoter(intval($req['id']));
        if (!$p || intval($p->organizer_id ?? 0) !== intval($org)) return new WP_Error('not_found', 'Promoter nicht gefunden.', ['status' => 404]);
        self::ensure_columns();
        $set = [];
        if ($req->has_param('leader_id')) {
            $lid = absint($req->get_param('leader_id'));
            if ($lid) {
                $l = TIX_Promoter_DB::get_promoter($lid);
                if (!$l || intval($l->organizer_id ?? 0) !== intval($org) || $lid === intval($p->id)) return self::bad('leader_id', 'Teamleiter nicht gefunden.');
                if (intval(self::row($lid)->parent_id ?? 0)) return self::bad('leader_id', 'Ein Teamleiter kann selbst keinen Teamleiter haben.');
                if (intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Promoter_DB::table_promoters() . " WHERE parent_id = %d", intval($p->id))))) return self::bad('leader_id', 'Dieser Promoter leitet selbst ein Team.');
            }
            $set['parent_id'] = $lid;
        }
        if ($req->has_param('leader_share')) {
            $v = $req->get_param('leader_share');
            if ($v === null || $v === '') $set['leader_share'] = null;
            else { $v = round(floatval($v), 2); if ($v < 0 || $v > 50) return self::bad('leader_share', 'Anteil: 0–50 %.'); $set['leader_share'] = $v; }
        }
        if ($req->has_param('cookie_days')) { $d = intval($req->get_param('cookie_days')); if ($d < 0 || $d > 90) return self::bad('cookie_days', 'Laufzeit: 0 (wie Seite) bis 90 Tage.'); $set['cookie_days'] = $d; }
        if ($set) {
            if (array_key_exists('leader_share', $set) && $set['leader_share'] === null) { $wpdb->query($wpdb->prepare("UPDATE " . TIX_Promoter_DB::table_promoters() . " SET leader_share = NULL WHERE id = %d", intval($p->id))); unset($set['leader_share']); }
            if ($set) $wpdb->update(TIX_Promoter_DB::table_promoters(), $set, ['id' => intval($p->id)]);
        }
        return rest_ensure_response(['ok' => true, 'id' => intval($p->id)] + self::promoter_fields($p));
    }

    private static function csv(array $rows) {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($rows as $r) fputcsv($fh, array_map(function ($v) { $v = (string) $v; return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; }, $r), ';');
        rewind($fh);
        $s = stream_get_contents($fh);
        fclose($fh);
        return $s;
    }

    /** GET /organizer/promoters/export?type=promoters|sales&from=&to= → {filename, mime, csv} (keine Käuferdaten) */
    public static function rest_export(WP_REST_Request $req) {
        global $wpdb;
        $org = self::org($req);
        $type = $req->get_param('type') === 'sales' ? 'sales' : 'promoters';
        $ok = function ($d) { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d); };
        $from = $ok($req->get_param('from')) ? (string) $req->get_param('from') : '';
        $to = $ok($req->get_param('to')) ? (string) $req->get_param('to') : '';
        $prom = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . TIX_Promoter_DB::table_promoters() . " WHERE organizer_id = %d ORDER BY display_name", intval($org)));
        $ids = array_map(function ($p) { return intval($p->id); }, $prom);
        $money = function ($v) { return number_format(floatval($v), 2, ',', ''); };
        if ($type === 'promoters') {
            $st = TIX_Promoter_REST::stats($ids, $from, $to);
            $rows = [['Name', 'Code', 'E-Mail', 'Status', 'Klicks', 'Bestellungen', 'Tickets', 'Umsatz', 'Provision offen', 'Provision ausgezahlt', 'Provision gesamt']];
            foreach ($prom as $p) {
                $s = $st[intval($p->id)] ?? [];
                $rows[] = [$p->display_name, $p->promoter_code, $p->email, $p->status, intval($s['clicks'] ?? 0), intval($s['orders'] ?? 0), intval($s['tickets'] ?? 0), $money($s['revenue'] ?? 0),
                    $money(floatval($s['commission']['open'] ?? 0) + floatval($s['commission']['approved'] ?? 0)), $money($s['commission']['paid'] ?? 0), $money($s['commission']['total'] ?? 0)];
            }
        } else {
            $rows = [['Datum', 'Promoter', 'Code', 'Event', 'Bestellung', 'Art', 'Tickets', 'Umsatz', 'Provision', 'Status']];
            if ($ids) {
                $w = '';
                if ($from) $w .= $wpdb->prepare(' AND c.created_at >= %s', $from . ' 00:00:00');
                if ($to) $w .= $wpdb->prepare(' AND c.created_at <= %s', $to . ' 23:59:59');
                $list = (array) $wpdb->get_results("SELECT c.*, pr.display_name, pr.promoter_code, p.post_title FROM " . TIX_Promoter_DB::table_commissions() . " c JOIN " . TIX_Promoter_DB::table_promoters() . " pr ON pr.id = c.promoter_id
                    LEFT JOIN {$wpdb->posts} p ON p.ID = c.event_id WHERE c.promoter_id IN (" . implode(',', $ids) . ") $w ORDER BY c.created_at DESC LIMIT 20000");
                foreach ($list as $c) {
                    $nr = class_exists('TIX_Order') && method_exists('TIX_Order', 'number_for') ? (string) TIX_Order::number_for(intval($c->order_id)) : (string) $c->order_id;
                    $rows[] = [get_date_from_gmt($c->created_at, 'd.m.Y H:i'), $c->display_name, $c->promoter_code, html_entity_decode((string) $c->post_title, ENT_QUOTES, 'UTF-8'), $nr, $c->attribution,
                        intval($c->tickets_qty), $money($c->order_total), $money($c->commission_amount), $c->status];
                }
            }
        }
        $name = 'promoter-' . ($type === 'sales' ? 'verkaeufe' : 'uebersicht') . '-' . current_time('Y-m-d') . '.csv';
        return rest_ensure_response(['ok' => true, 'filename' => $name, 'mime' => 'text/csv', 'rows' => count($rows) - 1, 'csv' => self::csv($rows)]);
    }

    /** GET /promoter/leaderboard?from=&to= – nur wenn der Veranstalter die Rangliste erlaubt; Namen gekürzt */
    public static function rest_leaderboard(WP_REST_Request $req) {
        global $wpdb;
        $me = TIX_Promoter_Portal::current();
        $rules = self::for_promoter($me);
        if (empty($rules['leaderboard'])) return rest_ensure_response(['ok' => true, 'enabled' => false]);
        $to = current_time('Y-m-d');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $req->get_param('from')) ? (string) $req->get_param('from') : gmdate('Y-m-d', strtotime($to) - 29 * DAY_IN_SECONDS);
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT c.promoter_id, SUM(c.tickets_qty) t FROM " . TIX_Promoter_DB::table_commissions() . " c JOIN " . TIX_Promoter_DB::table_promoters() . " p ON p.id = c.promoter_id
            WHERE p.organizer_id = %d AND p.status = 'active' AND c.status != 'cancelled' AND c.attribution != 'team' AND c.created_at >= %s GROUP BY c.promoter_id HAVING t > 0 ORDER BY t DESC LIMIT 200",
            intval($me->organizer_id ?? 0), $from . ' 00:00:00'));
        $short = function ($n) { $parts = preg_split('/\s+/', trim((string) $n)); return $parts[0] . (isset($parts[1]) ? ' ' . mb_substr($parts[1], 0, 1) . '.' : ''); };
        $top = []; $mine = null;
        foreach ($rows as $i => $r) {
            $p = TIX_Promoter_DB::get_promoter(intval($r->promoter_id));
            $e = ['rank' => $i + 1, 'name' => $short($p ? $p->display_name : ''), 'tickets' => intval($r->t), 'me' => intval($r->promoter_id) === intval($me->id)];
            if ($i < 10) $top[] = $e;
            if ($e['me']) $mine = $e;
        }
        return rest_ensure_response(['ok' => true, 'enabled' => true, 'from' => $from, 'to' => $to, 'top' => $top, 'me' => $mine]);
    }

    /** GET /public/promoter-config?ref=<code> → {enabled, attribution, cookie_days, valid} (für Websites und Apps) */
    public static function rest_public_config(WP_REST_Request $req) {
        $s = get_option('tix_settings', []);
        $code = strtolower(sanitize_text_field((string) $req->get_param('ref')));
        $p = $code !== '' && class_exists('TIX_Promoter_DB') ? TIX_Promoter_DB::get_promoter_by_code($code) : null;
        $rules = $p ? self::for_promoter($p) : self::get(0);
        $res = rest_ensure_response(['ok' => true, 'enabled' => !empty($s['promoter_enabled']), 'attribution' => $rules['attribution'], 'cookie_days' => self::cookie_days($p),
            'valid' => $code === '' ? null : (bool) $p, 'consent_category' => 'marketing']);
        $res->header('Cache-Control', 'public, max-age=300');
        return $res;
    }

}

TIX_Promoter_Rules::init();
