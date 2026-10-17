<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Customers – Kunden im Studio und in der App (1.38.494, User-Entscheid 2026-10-17).
 *
 * Rechte: Admin; Einzel-Seite zusätzlich der Inhaber (Rolle tix_organizer); evendis Inhaber bzw. Team-Admin des Veranstalters
 * (nur eigene Kunden). Team ohne Admin-Rolle, Einlass und Kasse: 403. Löschen: Einzel-Seite Admin + Inhaber, evendis nur
 * Plattform-Admin.
 *
 * Kunde = Käufer bezahlter, erstatteter oder stornierter Bestellungen (zusammengefasst über die E-Mail, Gastbestellungen
 * gleicher E-Mail gehören zum Konto) und Gäste der Gästelisten mit E-Mail. Auf evendis nur Bestellungen mit Positionen
 * eigener Events und Gästelisten eigener Events; Follower nur als Zahl. ID stabil: `u<user_id>` bzw. `e<16 Hex>`
 * (HMAC der kleingeschriebenen E-Mail, verrät die E-Mail nicht).
 *
 * Änderungsprotokoll (Tabelle tix_audit_log, ohne Klartextdaten Betroffener): Löschungen und CSV-Exporte; Hook
 * `tix_audit_logged` für den Datenspiegel.
 */
class TIX_Customers {

    const NS = 'tixomat/v1';
    const STATUSES = ['completed', 'processing', 'refunded', 'cancelled'];
    const PAID = ['completed', 'processing'];
    const AUDIT_DB = '1';
    const WALLET_MAX_MANUAL = 500; // € je Buchung (Gutschrift/Korrektur vom Team)

    /** Guthaben je Konto im Bereich: [user_id => [balance, topped_up, spent]] (Cent), null = Modul aus */
    private static $wallets = null;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        $p = ['permission_callback' => [__CLASS__, 'perm']];
        foreach (['/customers', '/platform/customers'] as $base) {
            register_rest_route(self::NS, $base, ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list']] + $p);
            register_rest_route(self::NS, $base . '/audit', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_audit']] + $p);
            register_rest_route(self::NS, $base . '/(?P<id>[ue][0-9a-f]{1,20})', [
                ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_detail']] + $p,
                ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_delete']] + $p,
            ]);
            register_rest_route(self::NS, $base . '/(?P<id>[ue][0-9a-f]{1,20})/delete', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_delete']] + $p);
            register_rest_route(self::NS, $base . '/(?P<id>[ue][0-9a-f]{1,20})/wallet', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_wallet']] + $p);
        }
    }

    // ═══════════════ Rechte / Bereich ═══════════════

    private static function err($code, $msg, $status, $extra = []) {
        return new WP_Error($code, $msg, ['status' => $status] + $extra);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function perm(WP_REST_Request $req) {
        if (class_exists('TIX_REST_API') && method_exists('TIX_REST_API', 'check_authenticated')) {
            $r = TIX_REST_API::check_authenticated($req);
            if ($r !== true) return $r;
        } elseif (!is_user_logged_in()) {
            return self::err('rest_not_logged_in', 'Authentifizierung erforderlich.', 401);
        }
        $u = wp_get_current_user();
        if ($u->has_cap('manage_options')) return true;
        if (strpos($req->get_route(), '/platform/') !== false) return self::err('rest_forbidden', 'Nur für Plattform-Admins.', 403);
        if (self::multi()) {
            return (TIX_App_Scope::is_org_manager() && TIX_App_Scope::organizer_id_for_user()) ? true : self::err('rest_forbidden', 'Nur Inhaber bzw. Team-Admin sehen die Kunden.', 403);
        }
        return in_array('tix_organizer', (array) $u->roles, true) ? true : self::err('rest_forbidden', 'Nur der Inhaber sieht die Kunden.', 403);
    }

    /** Bereich: ['oid' => 0|Veranstalter, 'scope' => site|platform|organizer] */
    private static function scope(WP_REST_Request $req) {
        if (!self::multi()) return ['oid' => 0, 'scope' => 'site'];
        if (current_user_can('manage_options')) {
            $o = intval($req->get_param('organizer') ?? 0);
            return $o ? ['oid' => $o, 'scope' => 'organizer'] : ['oid' => 0, 'scope' => 'platform'];
        }
        return ['oid' => intval(TIX_App_Scope::organizer_id_for_user()), 'scope' => 'organizer'];
    }

    private static function can_delete_caller() {
        if (current_user_can('manage_options')) return true;
        if (self::multi()) return false;
        return in_array('tix_organizer', (array) wp_get_current_user()->roles, true);
    }

    // ═══════════════ IDs ═══════════════

    public static function guest_id($email) {
        return 'e' . substr(hash_hmac('sha256', strtolower(trim((string) $email)), wp_salt('auth')), 0, 16);
    }

    // ═══════════════ Sammeln ═══════════════

    /** Bestellungen im Bereich: [order_id => row + own_total + qty] */
    private static function orders(array $sc) {
        global $wpdb;
        $o = $wpdb->prefix . 'tix_orders'; $i = $wpdb->prefix . 'tix_order_items';
        if ($wpdb->get_var("SHOW TABLES LIKE '$o'") !== $o) return [];
        $st = "'" . implode("','", self::STATUSES) . "'";
        if ($sc['oid']) {
            $items = $wpdb->get_results($wpdb->prepare("SELECT i.order_id, SUM(i.total) own_total, SUM(i.quantity) qty FROM $i i
                JOIN {$wpdb->postmeta} pm ON pm.post_id = i.event_id AND pm.meta_key = '_tix_organizer_id' AND pm.meta_value = %s GROUP BY i.order_id", (string) $sc['oid']), OBJECT_K);
            if (!$items) return [];
            $ids = implode(',', array_map('intval', array_keys($items)));
            $rows = $wpdb->get_results("SELECT * FROM $o WHERE id IN ($ids) AND status IN ($st) AND billing_email <> '' AND billing_email NOT LIKE '%@removed.invalid' ORDER BY date_created ASC, id ASC");
        } else {
            $items = $wpdb->get_results("SELECT order_id, SUM(total) own_total, SUM(quantity) qty FROM $i GROUP BY order_id", OBJECT_K);
            $rows = $wpdb->get_results("SELECT * FROM $o WHERE status IN ($st) AND billing_email <> '' AND billing_email NOT LIKE '%@removed.invalid' ORDER BY date_created ASC, id ASC");
        }
        $out = [];
        foreach ((array) $rows as $r) {
            $it = $items[$r->id] ?? null;
            $r->own_total = $sc['oid'] ? floatval($it->own_total ?? 0) : floatval($r->total);
            $r->qty = intval($it->qty ?? 0);
            $out[intval($r->id)] = $r;
        }
        return $out;
    }

    /** Gästelisten im Bereich: [[email, name, event_id, plus, checked_in]] */
    private static function guests(array $sc) {
        $args = ['post_type' => 'event', 'post_status' => ['publish', 'private', 'draft', 'future'], 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => ['relation' => 'OR', ['key' => '_tix_guest_list', 'compare' => 'EXISTS'], ['key' => '_tix_guestlist', 'compare' => 'EXISTS']]];
        if ($sc['oid']) $args['meta_query'] = ['relation' => 'AND', $args['meta_query'], ['key' => '_tix_organizer_id', 'value' => (string) $sc['oid']]];
        $out = [];
        foreach (get_posts($args) as $eid) {
            foreach (['_tix_guest_list', '_tix_guestlist'] as $k) {
                $list = get_post_meta($eid, $k, true);
                if (!is_array($list)) continue;
                foreach ($list as $g) {
                    $em = is_array($g) ? sanitize_email((string) ($g['email'] ?? '')) : '';
                    if ($em === '' || !is_email($em) || strpos($em, '@removed.invalid') !== false) continue;
                    $out[] = ['email' => $em, 'name' => (string) ($g['name'] ?? ''), 'event_id' => intval($eid), 'plus' => intval($g['plus'] ?? 0), 'checked_in' => !empty($g['checked_in']) || !empty($g['checked_in_count'])];
                }
            }
        }
        return $out;
    }

    private static function in_range($date, $from, $to) {
        $d = substr((string) $date, 0, 10);
        return ($from === '' || $d >= $from) && ($to === '' || $d <= $to);
    }

    private static function wallet_ready() {
        return class_exists('TIX_Wallet_Balance') && TIX_Wallet_Balance::ready();
    }

    /** Guthaben-Kennzahlen je Konto (wie /wallet/admin/customers: Aufgeladen = topup+bonus, Ausgegeben = −(charge+reversal)). */
    private static function wallet_maps(array $sc) {
        global $wpdb;
        if (!self::wallet_ready()) return self::$wallets = null;
        $W = TIX_Wallet_Balance::t('wallets'); $T = TIX_Wallet_Balance::t('wallet_tx');
        $wh = $sc['oid'] ? $wpdb->prepare('w.organizer_id = %d', $sc['oid']) : '1=1';
        $out = [];
        foreach ((array) $wpdb->get_results("SELECT w.user_id, SUM(w.balance_paid + w.balance_bonus) bal FROM $W w WHERE $wh AND w.user_id > 0 GROUP BY w.user_id", ARRAY_A) as $r) {
            $out[intval($r['user_id'])] = ['balance' => intval($r['bal']), 'topped_up' => 0, 'spent' => 0];
        }
        foreach ((array) $wpdb->get_results("SELECT w.user_id,
                SUM(CASE WHEN t.type IN ('topup','bonus') THEN t.amount_paid + t.amount_bonus ELSE 0 END) up,
                -SUM(CASE WHEN t.type IN ('charge','reversal') THEN t.amount_paid + t.amount_bonus ELSE 0 END) sp
                FROM $T t JOIN $W w ON w.id = t.wallet_id WHERE $wh AND w.user_id > 0 GROUP BY w.user_id", ARRAY_A) as $r) {
            $u = intval($r['user_id']);
            if (!isset($out[$u])) $out[$u] = ['balance' => 0, 'topped_up' => 0, 'spent' => 0];
            $out[$u]['topped_up'] = intval($r['up']); $out[$u]['spent'] = intval($r['sp']);
        }
        return self::$wallets = $out;
    }

    /** Alle Kunden im Bereich (ungefiltert): [id => customer] */
    private static function collect(array $sc, $from = '', $to = '') {
        $by_email = [];
        foreach (self::orders($sc) as $r) {
            $em = strtolower(trim((string) $r->billing_email));
            if (!isset($by_email[$em])) $by_email[$em] = ['email' => (string) $r->billing_email, 'user_id' => 0, 'orders' => 0, 'tickets' => 0, 'revenue' => 0.0,
                'first_order_at' => null, 'last_order_at' => null, 'range_orders' => 0, 'range_revenue' => 0.0, 'order_ids' => [], 'sources' => [], 'last' => null];
            $c = &$by_email[$em];
            $c['orders']++; $c['tickets'] += $r->qty; $c['order_ids'][] = intval($r->id);
            $paid = in_array($r->status, self::PAID, true);
            if ($paid) $c['revenue'] += $r->own_total;
            if (!$c['first_order_at']) $c['first_order_at'] = (string) $r->date_created;
            $c['last_order_at'] = (string) $r->date_created;
            if (($from !== '' || $to !== '') && self::in_range($r->date_created, $from, $to)) { $c['range_orders']++; if ($paid) $c['range_revenue'] += $r->own_total; }
            if (intval($r->customer_id) > 0) $c['user_id'] = intval($r->customer_id);
            $c['last'] = $r;
            $c['sources']['orders'] = true;
            unset($c);
        }
        foreach (self::guests($sc) as $g) {
            $em = strtolower($g['email']);
            if (!isset($by_email[$em])) $by_email[$em] = ['email' => $g['email'], 'user_id' => 0, 'orders' => 0, 'tickets' => 0, 'revenue' => 0.0, 'first_order_at' => null,
                'last_order_at' => null, 'range_orders' => 0, 'range_revenue' => 0.0, 'order_ids' => [], 'sources' => [], 'last' => null, 'guest_name' => $g['name']];
            $by_email[$em]['sources']['guestlist'] = true;
            $by_email[$em]['guests'][] = $g;
            if (empty($by_email[$em]['guest_name'])) $by_email[$em]['guest_name'] = $g['name'];
        }
        // Konten zuordnen (Bestellung mit Konto, sonst Konto mit gleicher E-Mail)
        $need = array_keys(array_filter($by_email, function ($c) { return !$c['user_id']; }));
        foreach (array_chunk($need, 500) as $chunk) {
            global $wpdb;
            $in = implode(',', array_map(function ($e) use ($wpdb) { return $wpdb->prepare('%s', $e); }, $chunk));
            foreach ((array) $wpdb->get_results("SELECT ID, LOWER(user_email) em FROM {$wpdb->users} WHERE LOWER(user_email) IN ($in)") as $u) {
                if (isset($by_email[$u->em])) $by_email[$u->em]['user_id'] = intval($u->ID);
            }
        }
        // Konten mit Geldbörse im Bereich (auch ohne Bestellung, z. B. Aufladung an der Kasse)
        $wm = self::wallet_maps($sc);
        if ($wm) {
            $known = [];
            foreach ($by_email as $c) if ($c['user_id']) $known[$c['user_id']] = true;
            foreach (array_keys($wm) as $wuid) {
                if (isset($known[$wuid])) continue;
                $wu = get_userdata($wuid);
                if (!$wu || strpos((string) $wu->user_email, '@removed.invalid') !== false) continue;
                $em = strtolower((string) $wu->user_email);
                if (!isset($by_email[$em])) $by_email[$em] = ['email' => (string) $wu->user_email, 'user_id' => $wuid, 'orders' => 0, 'tickets' => 0, 'revenue' => 0.0, 'first_order_at' => null,
                    'last_order_at' => null, 'range_orders' => 0, 'range_revenue' => 0.0, 'order_ids' => [], 'sources' => [], 'last' => null];
                $by_email[$em]['user_id'] = $wuid;
                $by_email[$em]['sources']['wallet'] = true;
            }
            foreach ($by_email as $em => $c) if ($c['user_id'] && isset($wm[$c['user_id']])) $by_email[$em]['sources']['wallet'] = true;
        }
        $out = [];
        foreach ($by_email as $em => $c) {
            $id = $c['user_id'] ? 'u' . intval($c['user_id']) : self::guest_id($em);
            if (isset($out[$id])) { // gleiches Konto, andere E-Mail → zusammenführen
                $x = &$out[$id];
                foreach (['orders', 'tickets', 'revenue', 'range_orders', 'range_revenue'] as $k) $x[$k] += $c[$k];
                $x['order_ids'] = array_merge($x['order_ids'], $c['order_ids']);
                $x['sources'] += $c['sources'];
                $x['guests'] = array_merge($x['guests'] ?? [], $c['guests'] ?? []);
                if ($c['first_order_at'] && (!$x['first_order_at'] || $c['first_order_at'] < $x['first_order_at'])) $x['first_order_at'] = $c['first_order_at'];
                if ($c['last_order_at'] && (!$x['last_order_at'] || $c['last_order_at'] > $x['last_order_at'])) { $x['last_order_at'] = $c['last_order_at']; $x['last'] = $c['last']; }
                $x['emails'][] = $c['email'];
                unset($x);
                continue;
            }
            $c['id'] = $id;
            $c['emails'] = [$c['email']];
            $out[$id] = $c;
        }
        return $out;
    }

    /** Ausgabe-Zeile (Liste). */
    private static function row(array $c, array $sc, $with_extras = true) {
        $l = $c['last'];
        $u = $c['user_id'] ? get_userdata($c['user_id']) : null;
        $name = $l ? trim($l->billing_first_name . ' ' . $l->billing_last_name) : '';
        if ($name === '' && $u) $name = trim($u->first_name . ' ' . $u->last_name) ?: (string) $u->display_name;
        if ($name === '') $name = (string) ($c['guest_name'] ?? '');
        $out = [
            'id' => $c['id'], 'user_id' => $c['user_id'] ?: null, 'name' => $name, 'email' => $u ? (string) $u->user_email : (string) $c['email'],
            'phone' => $l && $l->billing_phone !== '' ? (string) $l->billing_phone : null,
            'orders' => $c['orders'], 'tickets' => $c['tickets'], 'revenue' => round($c['revenue'], 2),
            'first_order_at' => $c['first_order_at'], 'last_order_at' => $c['last_order_at'],
            'has_account' => (bool) $u, 'sources' => array_keys($c['sources']),
            'wallet_balance' => null, 'wallet_topped_up' => null, 'wallet_spent' => null, 'loyalty_points' => null,
        ];
        if (self::$wallets !== null && $u) {
            $w = self::$wallets[$u->ID] ?? ['balance' => 0, 'topped_up' => 0, 'spent' => 0];
            $out['wallet_balance'] = round($w['balance'] / 100, 2); $out['wallet_topped_up'] = round($w['topped_up'] / 100, 2); $out['wallet_spent'] = round($w['spent'] / 100, 2);
            $out['has_wallet'] = isset(self::$wallets[$u->ID]);
        }
        if ($with_extras && $u) $out['loyalty_points'] = self::loyalty_points($u->ID, $sc);
        return $out;
    }

    private static function wallet_balance($uid, array $sc) {
        global $wpdb;
        if (!class_exists('TIX_Wallet_Balance') || !TIX_Wallet_Balance::ready()) return null;
        $w = TIX_Wallet_Balance::t('wallets');
        $sql = $sc['oid'] ? $wpdb->prepare("SELECT COALESCE(SUM(balance_paid + balance_bonus),0) FROM $w WHERE user_id = %d AND organizer_id = %d", $uid, $sc['oid'])
            : $wpdb->prepare("SELECT COALESCE(SUM(balance_paid + balance_bonus),0) FROM $w WHERE user_id = %d", $uid);
        return round(intval($wpdb->get_var($sql)) / 100, 2);
    }

    private static function loyalty_points($uid, array $sc) {
        if (!class_exists('TIX_Loyalty')) return null;
        if ($sc['oid']) {
            $c = get_post_meta($sc['oid'], '_tix_org_loyalty', true);
            if (!is_array($c) || empty($c['enabled'])) return null;
            return max(0, intval(get_user_meta($uid, '_tix_loyalty_points_o' . $sc['oid'], true)));
        }
        if (self::multi() || !TIX_Loyalty::enabled()) return null;
        return TIX_Loyalty::points($uid);
    }

    // ═══════════════ Liste ═══════════════

    /** GET /customers?q=&sort=last|name|orders|revenue&dir=&from=&to=&source=&page=&per_page=&format=csv */
    public static function rest_list(WP_REST_Request $req) {
        $sc = self::scope($req);
        if (self::multi() && $sc['scope'] === 'organizer' && !$sc['oid']) return self::err('rest_forbidden', 'Dieses Konto ist keinem Veranstalter zugeordnet.', 403);
        $from = (string) $req->get_param('from'); $to = (string) $req->get_param('to');
        foreach (['from' => $from, 'to' => $to] as $k => $v) if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return self::err('tix_invalid', $k . ': JJJJ-MM-TT', 400, ['field' => $k]);
        $sort = $req->get_param('sort') === 'wallet' ? 'balance' : $req->get_param('sort'); // „wallet“ = Alias (Studio)
        $sort = in_array($sort, ['last', 'name', 'orders', 'revenue', 'balance'], true) ? $sort : 'last';
        $has_wallet = filter_var($req->get_param('has_wallet'), FILTER_VALIDATE_BOOLEAN);
        $dir = $req->get_param('dir') === 'asc' ? 'asc' : ($req->get_param('dir') === 'desc' ? 'desc' : ($sort === 'name' ? 'asc' : 'desc'));
        $source = in_array($req->get_param('source'), ['orders', 'guestlist', 'wallet'], true) ? $req->get_param('source') : 'all';
        $q = mb_strtolower(trim((string) $req->get_param('q')));
        $ranged = $from !== '' || $to !== '';
        $all = self::collect($sc, $from, $to);
        $rows = [];
        foreach ($all as $c) {
            if ($source !== 'all' && empty($c['sources'][$source])) continue;
            if ($ranged && !$c['range_orders']) continue;
            $r = self::row($c, $sc, false);
            if ($has_wallet && empty($r['has_wallet'])) continue;
            if ($q !== '' && mb_strpos(mb_strtolower($r['name'] . ' ' . $r['email'] . ' ' . implode(' ', $c['emails']) . ' ' . (string) $r['phone']), $q) === false) continue;
            if ($ranged) { $r['range_orders'] = $c['range_orders']; $r['range_revenue'] = round($c['range_revenue'], 2); }
            $rows[] = $r;
        }
        $key = ['last' => 'last_order_at', 'name' => 'name', 'orders' => 'orders', 'revenue' => 'revenue', 'balance' => 'wallet_balance'][$sort];
        usort($rows, function ($a, $b) use ($key, $dir) {
            $x = $a[$key]; $y = $b[$key];
            $c = is_string($x) || is_string($y) ? strcasecmp((string) $x, (string) $y) : ($x <=> $y);
            return $dir === 'asc' ? $c : -$c;
        });
        $since = $ranged ? null : wp_date('Y-m-d', time() - 30 * DAY_IN_SECONDS);
        $totals = ['customers' => count($rows), 'new' => 0, 'revenue' => 0.0];
        foreach ($rows as $r) {
            $first = substr((string) $r['first_order_at'], 0, 10);
            if ($first !== '' && ($ranged ? self::in_range($first, $from, $to) : $first >= $since)) $totals['new']++;
            $totals['revenue'] += $ranged ? $r['range_revenue'] : $r['revenue'];
        }
        $totals['revenue'] = round($totals['revenue'], 2);
        if ($sc['oid'] && class_exists('TIX_Mail_Follow')) $totals['followers'] = TIX_Mail_Follow::count($sc['oid']);
        if (self::$wallets !== null) {
            $wt = ['topped_up' => 0.0, 'spent' => 0.0, 'open' => 0.0];
            foreach ($rows as $r) { $wt['topped_up'] += (float) $r['wallet_topped_up']; $wt['spent'] += (float) $r['wallet_spent']; $wt['open'] += (float) $r['wallet_balance']; }
            $totals['wallet'] = array_map(function ($v) { return round($v, 2); }, $wt);
        }
        if ($req->get_param('format') === 'csv') {
            $lines = [];
            foreach ($rows as $r) {
                $id = $r['id'];
                $full = self::row($all[$id], $sc, true);
                $lines[] = [$full['name'], $full['email'], (string) $full['phone'], $full['orders'], $full['tickets'], number_format($full['revenue'], 2, ',', ''),
                    $full['first_order_at'] ? wp_date('d.m.Y', strtotime($full['first_order_at'])) : '', $full['last_order_at'] ? wp_date('d.m.Y', strtotime($full['last_order_at'])) : '',
                    $full['has_account'] ? 'ja' : 'nein', implode('+', $full['sources']), $full['wallet_balance'] === null ? '' : number_format($full['wallet_balance'], 2, ',', ''), $full['wallet_topped_up'] === null ? '' : number_format($full['wallet_topped_up'], 2, ',', ''), $full['wallet_spent'] === null ? '' : number_format($full['wallet_spent'], 2, ',', ''), $full['loyalty_points'] === null ? '' : $full['loyalty_points'], $id];
            }
            $data = class_exists('TIX_Export') ? TIX_Export::csv(['Name', 'E-Mail', 'Telefon', 'Bestellungen', 'Tickets', 'Umsatz (€)', 'Erste Bestellung', 'Letzte Bestellung', 'Konto', 'Quelle', 'Guthaben (€)', 'Aufgeladen (€)', 'Ausgegeben (€)', 'Punkte', 'Kunden-ID'], $lines) : '';
            self::audit('customer_export', '', $sc, ['rows' => count($lines), 'filters' => array_filter(['q' => $q !== '' ? 'ja' : '', 'from' => $from, 'to' => $to, 'source' => $source !== 'all' ? $source : ''])]);
            return rest_ensure_response(['ok' => true, 'filename' => 'kunden-' . current_time('Y-m-d') . '.csv', 'mime' => 'text/csv', 'rows' => count($lines), 'data' => base64_encode($data)]);
        }
        $per = max(1, min(100, intval($req->get_param('per_page') ?: 50)));
        $page = max(1, intval($req->get_param('page') ?: 1));
        $slice = array_slice($rows, ($page - 1) * $per, $per);
        foreach ($slice as $i => $r) {
            if ($r['has_account']) $slice[$i]['loyalty_points'] = self::loyalty_points($r['user_id'], $sc);
        }
        return rest_ensure_response(['ok' => true, 'scope' => $sc['scope'], 'organizer_id' => $sc['oid'] ?: null, 'totals' => $totals, 'customers' => $slice,
            'page' => $page, 'per_page' => $per, 'total' => count($rows), 'has_more' => count($rows) > $page * $per]);
    }

    // ═══════════════ Detail ═══════════════

    private static function find(WP_REST_Request $req, array $sc) {
        $all = self::collect($sc);
        $id = strtolower((string) $req['id']);
        return [$all[$id] ?? null, $all];
    }

    public static function rest_detail(WP_REST_Request $req) {
        $sc = self::scope($req);
        list($c) = self::find($req, $sc);
        if (!$c) return self::err('tix_customer_missing', 'Kunde nicht gefunden.', 404);
        return rest_ensure_response(['ok' => true, 'scope' => $sc['scope'], 'customer' => self::detail($c, $sc)]);
    }

    private static function detail(array $c, array $sc) {
        $out = self::row($c, $sc, true);
        $l = $c['last'];
        $u = $c['user_id'] ? get_userdata($c['user_id']) : null;
        $out['address'] = $l ? ['company' => (string) $l->billing_company ?: null, 'street' => (string) $l->billing_address_1, 'zip' => (string) $l->billing_postcode, 'city' => (string) $l->billing_city, 'country' => (string) ($l->billing_country ?: 'DE')] : null;
        $out['account_created_at'] = $u ? (string) $u->user_registered : null;
        $orders = [];
        $ids = array_map('intval', $c['order_ids']);
        if ($ids) {
            global $wpdb;
            $in = implode(',', $ids);
            $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}tix_orders WHERE id IN ($in) ORDER BY date_created DESC");
            $ev = [];
            foreach ((array) $wpdb->get_results("SELECT order_id, event_id, SUM(quantity) q FROM {$wpdb->prefix}tix_order_items WHERE order_id IN ($in) GROUP BY order_id, event_id") as $it) {
                if ($sc['oid'] && intval(get_post_meta(intval($it->event_id), '_tix_organizer_id', true)) !== $sc['oid']) continue;
                $ev[intval($it->order_id)][] = ['id' => intval($it->event_id), 'title' => html_entity_decode(get_the_title(intval($it->event_id)), ENT_QUOTES, 'UTF-8'), 'tickets' => intval($it->q)];
            }
            foreach ((array) $rows as $r) {
                $orders[] = ['id' => intval($r->id), 'order_number' => (string) ($r->order_number ?: $r->id), 'status' => (string) $r->status, 'total' => round(floatval($r->total), 2),
                    'created_at' => (string) $r->date_created, 'events' => $ev[intval($r->id)] ?? [], 'tickets' => array_sum(array_column($ev[intval($r->id)] ?? [], 'tickets'))];
            }
        }
        $out['orders'] = $orders;
        $tickets = [];
        if ($ids) {
            $tp = get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => 500, 'fields' => 'ids', 'meta_query' => [['key' => '_tix_ticket_order_id', 'value' => $ids, 'compare' => 'IN']]]);
            foreach ($tp as $tid) {
                $eid = intval(get_post_meta($tid, '_tix_ticket_event_id', true));
                if ($sc['oid'] && intval(get_post_meta($eid, '_tix_organizer_id', true)) !== $sc['oid']) continue;
                $tickets[] = ['id' => intval($tid), 'code' => (string) get_post_meta($tid, '_tix_ticket_code', true), 'event_id' => $eid, 'event_title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'),
                    'status' => (string) (get_post_meta($tid, '_tix_ticket_status', true) ?: 'valid'), 'checked_in' => (bool) get_post_meta($tid, '_tix_ticket_checked_in', true)];
            }
        }
        $out['tickets'] = $tickets;
        $out['wallet'] = null;
        if ($out['wallet_balance'] !== null && $u) {
            global $wpdb;
            $W = TIX_Wallet_Balance::t('wallets'); $T = TIX_Wallet_Balance::t('wallet_tx');
            $wh = $sc['oid'] ? $wpdb->prepare(' AND w.organizer_id = %d', $sc['oid']) : '';
            $txs = (array) $wpdb->get_results($wpdb->prepare("SELECT t.* FROM $T t JOIN $W w ON w.id = t.wallet_id WHERE w.user_id = %d$wh ORDER BY t.id DESC LIMIT 50", $u->ID), ARRAY_A);
            $wrow = $wpdb->get_row($wpdb->prepare("SELECT id FROM $W w WHERE w.user_id = %d" . ($sc['oid'] ? ' AND w.organizer_id = %d' : ' AND w.organizer_id = 0'), ...array_filter([$u->ID, $sc['oid'] ?: null])), ARRAY_A);
            $out['wallet_id'] = $wrow ? intval($wrow['id']) : null;
            $out['wallet'] = ['id' => $out['wallet_id'], 'balance' => $out['wallet_balance'], 'topped_up' => $out['wallet_topped_up'], 'spent' => $out['wallet_spent'],
                'transactions' => array_map(function ($x) { return TIX_Wallet_Balance::tx_payload($x, true); }, $txs)];
        }
        $out['loyalty'] = $out['loyalty_points'] === null ? null : ['points' => $out['loyalty_points']];
        // Support-Anfragen (evendis: nur zu eigenen Events/Bestellungen)
        $support = [];
        $emails = array_values(array_unique(array_merge($c['emails'], $u ? [(string) $u->user_email] : [])));
        $sp = get_posts(['post_type' => 'tix_support_ticket', 'post_status' => array_values(get_post_stati()), 'posts_per_page' => 100, 'fields' => 'ids', 'orderby' => 'modified', 'order' => 'DESC',
            'meta_query' => [['key' => '_tix_sp_email', 'value' => $emails, 'compare' => 'IN']]]);
        foreach ($sp as $sid) {
            if (get_post_meta($sid, '_tix_sp_help', true)) continue;
            if ($sc['oid']) {
                $se = intval(get_post_meta($sid, '_tix_sp_event_id', true)); $so = intval(get_post_meta($sid, '_tix_sp_order_id', true));
                $own = ($se && intval(get_post_meta($se, '_tix_organizer_id', true)) === $sc['oid']) || ($so && in_array($so, $ids, true));
                if (!$own) continue;
            }
            $support[] = ['id' => intval($sid), 'subject' => html_entity_decode(get_the_title($sid), ENT_QUOTES, 'UTF-8'), 'status' => (string) get_post_status($sid), 'updated_at' => (string) get_post_field('post_modified', $sid)];
        }
        $out['support'] = $support;
        // Gutscheine aus den Bestellungen des Kunden
        $gift = [];
        foreach ((array) get_option('tix_coupons', []) as $code => $cp) {
            if (!is_array($cp) || ($cp['discount_type'] ?? '') !== 'giftcard' || !in_array(intval($cp['order_id'] ?? 0), $ids, true)) continue;
            if ($sc['oid'] && intval($cp['organizer_id'] ?? 0) !== $sc['oid']) continue;
            $gift[] = ['code' => (string) $code, 'value' => round(floatval($cp['value'] ?? 0), 2), 'balance' => round(floatval($cp['balance'] ?? 0), 2), 'expires' => (string) ($cp['expires'] ?? '')]
                + (class_exists('TIX_Giftcard_Send') ? TIX_Giftcard_Send::list_fields($cp + ['code' => (string) $code]) : []); // Versand an die beschenkte Person (1.38.496)
        }
        $out['giftcards'] = $gift;
        $out['guestlists'] = array_map(function ($g) { return ['event_id' => $g['event_id'], 'event_title' => html_entity_decode(get_the_title($g['event_id']), ENT_QUOTES, 'UTF-8'), 'plus' => $g['plus'], 'checked_in' => $g['checked_in']]; }, $c['guests'] ?? []);
        list($can, $blockers) = self::delete_check($c, $sc);
        $out['can_delete'] = $can;
        $out['delete_blockers'] = $blockers;
        return $out;
    }

    // ═══════════════ Löschen ═══════════════

    /** [darf löschen, Blocker[]] für den Aufrufer */
    private static function delete_check(array $c, array $sc) {
        $blockers = [];
        if (!self::can_delete_caller()) return [false, ['forbidden']];
        $u = $c['user_id'] ? get_userdata($c['user_id']) : null;
        if ($u) {
            $roles = array_diff((array) $u->roles, ['subscriber', 'customer', 'tix_customer']);
            if ($roles || user_can($u, 'manage_options') || (class_exists('TIX_REST_API') && TIX_REST_API::is_staff_user($u)) || user_can($u, 'tix_app_pos')) $blockers[] = 'protected';
        }
        return [!$blockers, $blockers];
    }

    private static function summary(array $c) {
        $u = $c['user_id'] ? get_userdata($c['user_id']) : null;
        $retained = 0;
        global $wpdb;
        if ($c['order_ids']) {
            $in = implode(',', array_map('intval', $c['order_ids']));
            $retained = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tix_orders WHERE id IN ($in) AND status IN ('completed','processing','refunded')"));
        }
        $wallet = $u ? self::wallet_balance($u->ID, ['oid' => 0]) : null;
        $points = $u && class_exists('TIX_Loyalty') && !self::multi() && TIX_Loyalty::enabled() ? TIX_Loyalty::points($u->ID) : null;
        return ['orders' => $c['orders'], 'retained_orders' => $retained, 'tickets' => $c['tickets'], 'wallet_balance' => $wallet, 'loyalty_points' => $points,
            'giftcards' => 0, 'account' => (bool) $u];
    }

    /** POST /customers/{id}/delete {confirm, confirm_wallet} bzw. DELETE /customers/{id}?confirm=1 */
    public static function rest_delete(WP_REST_Request $req) {
        $sc = self::scope($req);
        if (!self::can_delete_caller()) return self::err('tix_customer_delete_forbidden', self::multi() ? 'Kunden löscht auf evendis nur der Plattform-Admin.' : 'Kunden löschen dürfen nur Inhaber und Admin.', 403);
        if (self::multi()) $sc = ['oid' => 0, 'scope' => 'platform']; // Plattform-Admin: alle Kunden
        list($c) = self::find($req, $sc);
        if (!$c) return self::err('tix_customer_missing', 'Kunde nicht gefunden.', 404);
        list(, $blockers) = self::delete_check($c, $sc);
        $sum = self::summary($c);
        $confirm = filter_var($req->get_param('confirm'), FILTER_VALIDATE_BOOLEAN) || $req->get_method() === 'DELETE' && filter_var($req->get_param('confirm'), FILTER_VALIDATE_BOOLEAN);
        if ($sum['wallet_balance'] !== null && $sum['wallet_balance'] > 0 && !filter_var($req->get_param('confirm_wallet'), FILTER_VALIDATE_BOOLEAN)) $blockers[] = 'wallet';
        if (!$confirm) return rest_ensure_response(['ok' => true, 'preview' => true, 'customer_id' => $c['id'], 'summary' => $sum, 'blockers' => array_values($blockers)]);
        if (in_array('protected', $blockers, true)) return self::err('tix_customer_protected', 'Konten von Admins, Personal und Veranstaltern lassen sich hier nicht löschen.', 409);
        if (in_array('wallet', $blockers, true)) return self::err('tix_customer_wallet', 'Der Kunde hat noch ' . number_format($sum['wallet_balance'], 2, ',', '.') . ' € Guthaben. Bitte mit confirm_wallet bestätigen.', 409, ['wallet_balance' => $sum['wallet_balance']]);
        if (!class_exists('TIX_Account_Delete')) return self::err('tix_unavailable', 'Löschen ist nicht verfügbar.', 500);
        $res = $c['user_id'] ? TIX_Account_Delete::delete($c['user_id'], 'studio') : TIX_Account_Delete::delete_guest($c['email'], 'studio');
        if (is_wp_error($res)) return $res;
        // weitere E-Mails desselben Kontos (Gastbestellungen mit anderer Adresse)
        if ($c['user_id']) foreach (array_slice($c['emails'], 0) as $em) { if (strcasecmp($em, (string) ($res['email'] ?? '')) !== 0 && !get_user_by('email', $em)) TIX_Account_Delete::delete_guest($em, 'studio'); }
        $deleted = ['orders_anonymized' => intval($res['orders'] ?? 0), 'tickets' => intval($res['tickets'] ?? 0), 'support' => intval($res['support'] ?? 0), 'account' => (bool) $c['user_id']];
        self::audit('customer_delete', $c['id'], $sc, ['account' => (bool) $c['user_id'], 'orders' => $deleted['orders_anonymized'], 'retained' => count((array) ($res['retained'] ?? [])), 'tickets' => $deleted['tickets'], 'support' => $deleted['support'], 'wallet_confirmed' => $sum['wallet_balance'] !== null && $sum['wallet_balance'] > 0]);
        return rest_ensure_response(['ok' => true, 'customer_id' => $c['id'], 'deleted' => $deleted, 'retained' => array_values((array) ($res['retained'] ?? [])),
            'retention' => (string) ($res['retention'] ?? ''), 'wallet_payout_request' => $res['wallet_payout_request'] ?? null]);
    }

    // ═══════════════ Guthaben manuell ═══════════════

    /**
     * POST /customers/{id}/wallet {amount (€, ±, höchstens 500), reason (Pflicht), notify?, as?: bonus|paid, organizer_id?, idempotency_key?}
     * Gutschrift bzw. Korrektur vom Team (Buchung `adjust`, Kanal admin, Bearbeiter + Grund). Admin; Einzel-Seite auch der Inhaber;
     * evendis nur Plattform-Admin. Gast ohne Konto: 409 tix_customer_no_account.
     */
    public static function rest_wallet(WP_REST_Request $req) {
        $sc = self::scope($req);
        if (!self::can_delete_caller()) return self::err('tix_customer_wallet_forbidden', self::multi() ? 'Guthaben bucht auf evendis nur der Plattform-Admin.' : 'Guthaben buchen dürfen nur Inhaber und Admin.', 403);
        if (!self::wallet_ready()) return self::err('tix_wallet_unavailable', 'Guthaben ist hier nicht eingerichtet.', 409);
        if (self::multi()) $sc = ['oid' => 0, 'scope' => 'platform'];
        list($c) = self::find($req, $sc);
        if (!$c) return self::err('tix_customer_missing', 'Kunde nicht gefunden.', 404);
        if (!$c['user_id']) return self::err('tix_customer_no_account', 'Der Kunde hat kein Konto. Guthaben gibt es erst, wenn er sich mit dieser E-Mail registriert.', 409);
        $amount = $req->get_param('amount');
        if (!is_numeric($amount) || abs(floatval($amount)) < 0.01 || abs(floatval($amount)) > self::WALLET_MAX_MANUAL) {
            return self::err('tix_invalid', 'Betrag: ±0,01 bis ±' . self::WALLET_MAX_MANUAL . ' €.', 400, ['field' => 'amount']);
        }
        $reason = trim(sanitize_text_field((string) $req->get_param('reason')));
        if ($reason === '') return self::err('tix_invalid', 'Bitte einen Grund angeben.', 400, ['field' => 'reason']);
        $cents = TIX_Wallet_Balance::cents(abs(floatval($amount))) * (floatval($amount) < 0 ? -1 : 1);
        $oid = self::multi() ? max(0, intval($req->get_param('organizer_id'))) : 0;
        $w = TIX_Wallet_Balance::get_or_create($c['user_id'], $oid);
        if (!$w) return self::err('tix_wallet_failed', 'Geldbörse konnte nicht angelegt werden.', 500);
        $cur = intval($w['balance_paid']) + intval($w['balance_bonus']);
        if ($cents > 0) {
            $max = TIX_Wallet_Balance::cents(TIX_Wallet_Balance::settings($oid)['max_balance']);
            if ($cur + $cents > $max) return self::err('tix_wallet_limit_balance', 'Höchstguthaben ' . TIX_Wallet_Balance::fmt($max) . ' – möglich sind noch ' . TIX_Wallet_Balance::fmt(max(0, $max - $cur)) . '.', 409, ['field' => 'amount', 'room' => TIX_Wallet_Balance::euro(max(0, $max - $cur))]);
            $as_paid = $req->get_param('as') === 'paid';
            $args = $as_paid ? ['amount_paid' => $cents] : ['amount_bonus' => $cents];
        } else {
            if ($cur + $cents < 0) return self::err('tix_wallet_insufficient', 'Die Korrektur würde das Guthaben unter 0 drücken (Stand ' . TIX_Wallet_Balance::fmt($cur) . ').', 409, ['field' => 'amount', 'balance' => TIX_Wallet_Balance::euro($cur)]);
            $args = ['amount' => $cents];
        }
        $me = wp_get_current_user();
        $key = trim((string) $req->get_param('idempotency_key'));
        $tx = TIX_Wallet_Balance::post_tx(intval($w['id']), $args + ['type' => 'adjust', 'channel' => 'admin', 'staff_user_id' => intval($me->ID), 'allow_locked' => true,
            'idempotency_key' => $key !== '' ? 'team:' . mb_substr($key, 0, 80) : 'team:' . wp_generate_uuid4(), 'organizer_id' => $oid,
            'meta' => ['reason' => $reason, 'by' => (string) $me->display_name, 'manual' => $cents > 0 ? 'credit' : 'correction']]);
        if (is_wp_error($tx)) return $tx;
        $notify = filter_var($req->get_param('notify'), FILTER_VALIDATE_BOOLEAN);
        if ($notify && $cents > 0 && empty($tx['replay']) && class_exists('TIX_Notifications')) {
            TIX_Notifications::add_user_item($c['user_id'], 'Guthaben gutgeschrieben', 'Dir wurden ' . TIX_Wallet_Balance::fmt($cents) . ' gutgeschrieben.', ['type' => 'wallet', 'action' => 'wallet']);
        }
        if (empty($tx['replay'])) self::audit('customer_wallet', $c['id'], $sc, ['amount' => round($cents / 100, 2), 'reason' => $reason, 'as' => $cents > 0 ? ($req->get_param('as') === 'paid' ? 'paid' : 'bonus') : 'correction', 'organizer_id' => $oid ?: null, 'notified' => $notify && $cents > 0]);
        $after = TIX_Wallet_Balance::get_wallet(intval($w['id']));
        return rest_ensure_response(['ok' => true, 'customer_id' => $c['id'], 'transaction' => TIX_Wallet_Balance::tx_payload($tx, true),
            'balance' => TIX_Wallet_Balance::euro(intval($after['balance_paid']) + intval($after['balance_bonus'])), 'notified' => $notify && $cents > 0]);
    }

    // ═══════════════ Änderungsprotokoll ═══════════════

    public static function audit_table() {
        global $wpdb;
        return $wpdb->prefix . 'tix_audit_log';
    }

    private static function audit_install() {
        if (get_option('tix_audit_db') === self::AUDIT_DB) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::audit_table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created DATETIME NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            actor_name VARCHAR(190) NOT NULL DEFAULT '',
            action VARCHAR(40) NOT NULL DEFAULT '',
            subject_id VARCHAR(40) NOT NULL DEFAULT '',
            scope VARCHAR(40) NOT NULL DEFAULT '',
            summary LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY action_created (action,created)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option('tix_audit_db', self::AUDIT_DB, false);
    }

    /** Eintrag ohne Klartextdaten Betroffener (Kunden-ID, Zahlen). */
    public static function audit($action, $object_id, array $sc, array $summary) {
        global $wpdb;
        self::audit_install();
        $u = wp_get_current_user();
        $row = ['created' => current_time('mysql', true), 'actor_id' => intval($u->ID), 'actor_name' => mb_substr((string) $u->display_name, 0, 190), 'action' => $action,
            'subject_id' => (string) $object_id, 'scope' => $sc['scope'] . ($sc['oid'] ? ':' . $sc['oid'] : ''), 'summary' => wp_json_encode($summary)];
        $wpdb->insert(self::audit_table(), $row);
        $row['id'] = intval($wpdb->insert_id);
        do_action('tix_audit_logged', $row);
        return $row['id'];
    }

    /** GET /customers/audit?page= – Löschungen und Exporte (Einzel-Seite: Admin/Inhaber; evendis: Admin alle, Veranstalter eigene Exporte) */
    public static function rest_audit(WP_REST_Request $req) {
        global $wpdb;
        self::audit_install();
        $sc = self::scope($req);
        $t = self::audit_table();
        $where = "action IN ('customer_delete','customer_export','customer_wallet')";
        if (self::multi() && !current_user_can('manage_options')) $where .= $wpdb->prepare(' AND scope = %s', 'organizer:' . $sc['oid']);
        $page = max(1, intval($req->get_param('page') ?: 1));
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE $where ORDER BY id DESC LIMIT 50 OFFSET %d", ($page - 1) * 50), ARRAY_A);
        return rest_ensure_response(['ok' => true, 'entries' => array_map(function ($r) {
            return ['id' => intval($r['id']), 'at' => get_date_from_gmt($r['created'], 'Y-m-d H:i'), 'actor' => ['id' => intval($r['actor_id']), 'name' => $r['actor_name']],
                'action' => $r['action'], 'customer_id' => $r['subject_id'] ?: null, 'scope' => $r['scope'], 'summary' => json_decode((string) $r['summary'], true)];
        }, $rows), 'page' => $page]);
    }
}
