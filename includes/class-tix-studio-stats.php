<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Studio_Stats – Studio Paket 2 „Statistik + Export“ (1.38.454).
 *
 *   GET  /organizer/stats?from&to[&event_id][&group=day|week|month]
 *        → {ok, range, totals, previous, series, by_event, by_channel, by_category_top}
 *   GET  /events/{id}/statistics  (TIX_REST_API) zusätzlich sales_by_day, by_channel, by_source, checkin_by_slot, views, conversion
 *   POST /tickets/{id}/transfer {first_name, last_name, email, notify?, create_account?} · POST /tickets/{id}/holder {name} – Umschreiben durch das Team
 *   Export (TIX_Export): type=checkins|coupons
 * Rechte: Statistik, Bestellungen/Kunden-Export, Umschreiben nur Admin sowie Inhaber und Team-Admins
 * (Plattform: is_org_manager; Ein-Veranstalter: Rolle Veranstalter). Mehr-Veranstalter: nur eigene Events.
 * Kanal einer Bestellung (channel_of): pos (payment_method pos_*) · evendis (über die Partner-API vermittelt) ·
 * link (utm_source=veranstalter-link) · app · web · other.
 */
class TIX_Studio_Stats {

    const NS = 'tixomat/v1';
    const PAID = ['completed', 'processing'];
    const CHANNELS = ['web' => 'Website', 'app' => 'App', 'pos' => 'Abendkasse', 'evendis' => 'evendis (Vermittlung)', 'link' => 'Dein Link', 'other' => 'Sonstige'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/organizer/stats', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_stats'], 'permission_callback' => [__CLASS__, 'perm_manager']]);
        register_rest_route(self::NS, '/tickets/(?P<id>\d+)/transfer', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_transfer'], 'permission_callback' => [__CLASS__, 'perm_manager']]);
        register_rest_route(self::NS, '/tickets/(?P<id>\d+)/holder', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_holder'], 'permission_callback' => [__CLASS__, 'perm_manager']]);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    /** Inhaber bzw. Team-Admin (oder Admin)? */
    public static function is_manager($uid = 0) {
        $u = $uid ? get_userdata(intval($uid)) : wp_get_current_user();
        if (!$u || !$u->ID) return false;
        if (user_can($u, 'manage_options')) return true;
        if (self::multi()) return TIX_App_Scope::is_org_manager($u->ID);
        return in_array('tix_organizer', (array) $u->roles, true);
    }

    public static function perm_manager(WP_REST_Request $req) {
        $c = TIX_REST_API::check_organizer($req);
        if ($c !== true) return $c;
        return self::is_manager() ? true : new WP_Error('rest_forbidden', 'Statistik, Bestellungen und Kunden sehen nur Inhaber und Team-Admins.', ['status' => 403]);
    }

    private static function bad($field, $msg) {
        return new WP_Error('tix_invalid_field', $msg, ['status' => 400, 'field' => $field]);
    }

    private static function date_ok($d) {
        return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    }

    // ═══════════════ Daten ═══════════════

    /** Positionen bezahlter Bestellungen der Events im Zeitraum: [order_id, date, payment_method, event_id, qty, total, cat] */
    private static function rows(array $event_ids, $from, $to) {
        global $wpdb;
        if (!$event_ids || !class_exists('TIX_Order')) return [];
        $t = TIX_Order::table_name(); $ti = TIX_Order::items_table_name();
        $out = [];
        foreach (array_chunk(array_map('intval', $event_ids), 500) as $chunk) {
            $sql = "SELECT o.id oid, o.date_created d, o.payment_method pm, i.event_id eid, i.quantity q, i.total tot, i.cat_name cat, i.name nm
                FROM $t o INNER JOIN $ti i ON i.order_id = o.id
                WHERE o.status IN ('" . implode("','", self::PAID) . "') AND i.event_id IN (" . implode(',', $chunk) . ")";
            $args = [];
            if ($from) { $sql .= ' AND o.date_created >= %s'; $args[] = $from . ' 00:00:00'; }
            if ($to)   { $sql .= ' AND o.date_created <= %s'; $args[] = $to . ' 23:59:59'; }
            foreach ((array) $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql) as $r) $out[] = $r;
        }
        return $out;
    }

    /** Kanäle für viele Bestellungen auf einmal (Postmeta + Optionen in Blöcken). */
    public static function channels(array $order_ids) {
        global $wpdb;
        $order_ids = array_values(array_unique(array_map('intval', $order_ids)));
        $meta = []; $src = []; $pm = [];
        foreach (array_chunk($order_ids, 500) as $chunk) {
            $in = implode(',', $chunk);
            foreach ((array) $wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key IN ('_tix_order_channel','_tix_campaign_source')") as $r) {
                $meta[intval($r->post_id)][$r->meta_key] = (string) $r->meta_value;
            }
            $names = "'" . implode("','", array_map(function ($id) { return '_tix_order_source_' . $id; }, $chunk)) . "'";
            foreach ((array) $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ($names)") as $r) {
                $src[intval(substr($r->option_name, strlen('_tix_order_source_')))] = (string) $r->option_value;
            }
            foreach ((array) $wpdb->get_results("SELECT id, payment_method FROM " . TIX_Order::table_name() . " WHERE id IN ($in)") as $r) $pm[intval($r->id)] = (string) $r->payment_method;
        }
        $out = [];
        foreach ($order_ids as $id) {
            $m = $meta[$id] ?? [];
            $out[$id] = self::channel_of($pm[$id] ?? '', $src[$id] ?? '', $m['_tix_order_channel'] ?? '', $m['_tix_campaign_source'] ?? '');
        }
        return $out;
    }

    /** Kanal aus Zahlart, Bestellquelle (Option _tix_order_source_), _tix_order_channel und _tix_campaign_source. */
    public static function channel_of($payment_method, $order_source, $order_channel, $campaign_source) {
        if (strpos((string) $payment_method, 'pos_') === 0) return 'pos';
        if (strpos((string) $order_source, 'partner:') === 0) return 'evendis';
        if ($campaign_source === 'veranstalter-link') return 'link';
        if ($order_source === 'app') return $order_channel === 'web' ? 'web' : 'app';
        // Website-Kasse (native Kasse) merkt sich immer eine Kampagnen-Quelle (mind. „direct“)
        if ($campaign_source !== '') return 'web';
        return 'other';
    }

    private static function channel_list(array $agg) {
        $out = [];
        foreach (self::CHANNELS as $k => $label) {
            if (empty($agg[$k])) continue;
            $out[] = ['key' => $k, 'label' => $label, 'orders' => count($agg[$k]['o']), 'tickets' => $agg[$k]['t'], 'revenue' => round($agg[$k]['r'], 2)];
        }
        usort($out, function ($a, $b) { return $b['revenue'] <=> $a['revenue']; });
        return $out;
    }

    /** by_channel aus Positionen. */
    private static function by_channel(array $rows) {
        if (!$rows) return [];
        $ch = self::channels(array_map(function ($r) { return $r->oid; }, $rows));
        $agg = [];
        foreach ($rows as $r) {
            $k = $ch[intval($r->oid)] ?? 'other';
            if (!isset($agg[$k])) $agg[$k] = ['o' => [], 't' => 0, 'r' => 0.0];
            $agg[$k]['o'][intval($r->oid)] = 1; $agg[$k]['t'] += intval($r->q); $agg[$k]['r'] += floatval($r->tot);
        }
        return self::channel_list($agg);
    }

    private static function totals(array $rows, array $event_ids, $from, $to) {
        $rev = 0.0; $tix = 0; $orders = [];
        foreach ($rows as $r) { $rev += floatval($r->tot); $tix += intval($r->q); $orders[intval($r->oid)] = 1; }
        $n = count($orders);
        return ['revenue' => round($rev, 2), 'tickets' => $tix, 'orders' => $n, 'refunds' => self::refunds($event_ids, $from, $to), 'avg_order' => $n ? round($rev / $n, 2) : 0.0];
    }

    /** Erstattet (€) für Bestellungen des Zeitraums: Teil-Erstattungen + voll erstattete Bestellungen, höchstens die eigenen Positionen. */
    private static function refunds(array $event_ids, $from, $to) {
        global $wpdb;
        if (!$event_ids || !class_exists('TIX_Order')) return 0.0;
        $t = TIX_Order::table_name(); $ti = TIX_Order::items_table_name();
        $sum = 0.0;
        foreach (array_chunk(array_map('intval', $event_ids), 500) as $chunk) {
            $sql = "SELECT o.id, o.status, o.total ototal, SUM(i.total) own FROM $t o INNER JOIN $ti i ON i.order_id = o.id
                WHERE o.status IN ('completed','processing','refunded') AND i.event_id IN (" . implode(',', $chunk) . ")";
            $args = [];
            if ($from) { $sql .= ' AND o.date_created >= %s'; $args[] = $from . ' 00:00:00'; }
            if ($to)   { $sql .= ' AND o.date_created <= %s'; $args[] = $to . ' 23:59:59'; }
            $sql .= ' GROUP BY o.id';
            foreach ((array) $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql) as $r) {
                $own = floatval($r->own);
                $ref = class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'refunded_total') ? TIX_Order_Admin::refunded_total(intval($r->id)) : 0.0;
                if ($r->status === 'refunded' && $ref <= 0) $ref = $own;
                // Anteil der eigenen Positionen an der Bestellung (Bestellungen über mehrere Veranstalter)
                $share = floatval($r->ototal) > 0 ? min(1.0, $own / floatval($r->ototal)) : 1.0;
                $sum += min($own, $ref * $share);
            }
        }
        return round($sum, 2);
    }

    private static function bucket($date, $group) {
        $ts = strtotime(substr((string) $date, 0, 10));
        if ($group === 'month') return gmdate('Y-m-01', $ts);
        if ($group === 'week') return gmdate('Y-m-d', $ts - ((intval(gmdate('N', $ts)) - 1) * DAY_IN_SECONDS));
        return gmdate('Y-m-d', $ts);
    }

    private static function series(array $rows, $from, $to, $group) {
        $s = [];
        for ($ts = strtotime($from); $ts <= strtotime($to); $ts += DAY_IN_SECONDS) {
            $k = self::bucket(gmdate('Y-m-d', $ts), $group);
            if (!isset($s[$k])) $s[$k] = ['date' => $k, 'revenue' => 0.0, 'tickets' => 0, 'orders' => []];
        }
        foreach ($rows as $r) {
            $k = self::bucket($r->d, $group);
            if (!isset($s[$k])) $s[$k] = ['date' => $k, 'revenue' => 0.0, 'tickets' => 0, 'orders' => []];
            $s[$k]['revenue'] += floatval($r->tot); $s[$k]['tickets'] += intval($r->q); $s[$k]['orders'][intval($r->oid)] = 1;
        }
        ksort($s);
        return array_values(array_map(function ($x) { return ['date' => $x['date'], 'revenue' => round($x['revenue'], 2), 'tickets' => $x['tickets'], 'orders' => count($x['orders'])]; }, $s));
    }

    /** Kontingent: Summe der Kategorie-Mengen (0 = unbegrenzt bzw. unbekannt). */
    public static function capacity($eid) {
        $cats = get_post_meta($eid, '_tix_ticket_categories', true);
        $sum = 0;
        foreach (is_array($cats) ? $cats : [] as $c) $sum += max(0, intval($c['qty'] ?? 0));
        return $sum;
    }

    /** Check-in-Quote wie /events/{id}/statistics (Gästeliste + Tickets). */
    public static function checkin_rate($eid) {
        $total = 0; $done = 0;
        $guests = get_post_meta($eid, '_tix_guest_list', true);
        foreach (is_array($guests) ? $guests : [] as $g) {
            $exp = 1 + intval($g['plus'] ?? 0);
            $total += $exp;
            $d = isset($g['checked_in_count']) ? intval($g['checked_in_count']) : (empty($g['checked_in']) ? 0 : $exp);
            $done += min($d, $exp);
        }
        $c = TIX_REST_API::ticket_counts($eid);
        $total += $c['total']; $done += $c['checked'];
        return $total > 0 ? round($done / $total * 100, 1) : 0.0;
    }

    /** Events, die der Nutzer auswerten darf (Mehr-Veranstalter: nur eigene). */
    private static function scope_ids() {
        return TIX_Export::accessible_event_ids();
    }

    // ═══════════════ S1: /organizer/stats ═══════════════

    public static function rest_stats(WP_REST_Request $req) {
        $to = (string) ($req->get_param('to') ?: current_time('Y-m-d'));
        $from = (string) ($req->get_param('from') ?: gmdate('Y-m-d', strtotime($to) - 29 * DAY_IN_SECONDS));
        if (!self::date_ok($from)) return self::bad('from', 'Bitte ein Datum im Format JJJJ-MM-TT angeben.');
        if (!self::date_ok($to)) return self::bad('to', 'Bitte ein Datum im Format JJJJ-MM-TT angeben.');
        if ($from > $to) return self::bad('from', '„Von“ liegt nach „Bis“.');
        $days = intval(round((strtotime($to) - strtotime($from)) / DAY_IN_SECONDS)) + 1;
        if ($days > 731) return self::bad('from', 'Höchstens zwei Jahre auf einmal.');
        $group = sanitize_key((string) ($req->get_param('group') ?: 'day'));
        if (!in_array($group, ['day', 'week', 'month'], true)) return self::bad('group', 'group: day, week oder month.');
        $eid = absint($req->get_param('event_id'));
        if ($eid) {
            if (get_post_type($eid) !== 'event' || !TIX_REST_API::can_access_event($eid)) return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
            $ids = [$eid];
        } else {
            $ids = self::scope_ids();
        }
        $rows = self::rows($ids, $from, $to);
        $pto = gmdate('Y-m-d', strtotime($from) - DAY_IN_SECONDS);
        $pfrom = gmdate('Y-m-d', strtotime($pto) - ($days - 1) * DAY_IN_SECONDS);
        $prev = self::rows($ids, $pfrom, $pto);

        // je Event
        $ev = []; $cat = [];
        foreach ($rows as $r) {
            $e = intval($r->eid);
            if (!isset($ev[$e])) $ev[$e] = ['revenue' => 0.0, 'tickets' => 0];
            $ev[$e]['revenue'] += floatval($r->tot); $ev[$e]['tickets'] += intval($r->q);
            $name = trim((string) ($r->cat !== '' ? $r->cat : $r->nm)) ?: 'Ticket';
            if (!isset($cat[$name])) $cat[$name] = ['name' => $name, 'tickets' => 0, 'revenue' => 0.0];
            $cat[$name]['tickets'] += intval($r->q); $cat[$name]['revenue'] += floatval($r->tot);
        }
        if ($eid && !isset($ev[$eid])) $ev[$eid] = ['revenue' => 0.0, 'tickets' => 0];
        uasort($ev, function ($a, $b) { return $b['revenue'] <=> $a['revenue']; });
        $by_event = [];
        foreach (array_slice($ev, 0, 50, true) as $id => $x) {
            $by_event[] = ['id' => $id, 'title' => html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'), 'date' => (string) get_post_meta($id, '_tix_date_start', true) ?: null,
                'revenue' => round($x['revenue'], 2), 'tickets' => $x['tickets'], 'capacity' => self::capacity($id), 'checkin_rate' => self::checkin_rate($id)];
        }
        usort($cat, function ($a, $b) { return $b['tickets'] <=> $a['tickets'] ?: $b['revenue'] <=> $a['revenue']; });
        $cat = array_map(function ($c) { $c['revenue'] = round($c['revenue'], 2); return $c; }, array_slice($cat, 0, 10));

        return rest_ensure_response([
            'ok' => true,
            'range' => ['from' => $from, 'to' => $to, 'days' => $days, 'group' => $group, 'previous' => ['from' => $pfrom, 'to' => $pto], 'event_id' => $eid ?: null],
            'totals' => self::totals($rows, $ids, $from, $to),
            'previous' => self::totals($prev, $ids, $pfrom, $pto),
            'series' => self::series($rows, $from, $to, $group),
            'by_event' => $by_event,
            'by_channel' => self::by_channel($rows),
            'by_category_top' => $cat,
        ]);
    }

    // ═══════════════ S3: Ausbau /events/{id}/statistics ═══════════════

    /** Zusatzfelder für /events/{id}/statistics (rein hinzufügend). */
    public static function event_extra($eid) {
        global $wpdb;
        $rows = self::rows([$eid], '', '');
        // Verkauf über den ganzen Zeitraum (erster bis letzter Verkaufstag, Lücken mit 0)
        $sales = [];
        if ($rows) {
            $dates = array_map(function ($r) { return substr((string) $r->d, 0, 10); }, $rows);
            $sales = self::series($rows, min($dates), max($dates), 'day');
        }
        // Herkunft (utm_source; ohne = direct)
        $src = [];
        $oids = array_values(array_unique(array_map(function ($r) { return intval($r->oid); }, $rows)));
        $cs = [];
        foreach (array_chunk($oids, 500) as $chunk) {
            foreach ((array) $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_tix_campaign_source' AND post_id IN (" . implode(',', $chunk) . ")") as $r) $cs[intval($r->post_id)] = (string) $r->meta_value;
        }
        foreach ($rows as $r) {
            $k = ($cs[intval($r->oid)] ?? '') ?: 'direct';
            if (!isset($src[$k])) $src[$k] = ['source' => $k, 'orders' => [], 'revenue' => 0.0];
            $src[$k]['orders'][intval($r->oid)] = 1; $src[$k]['revenue'] += floatval($r->tot);
        }
        $by_source = array_values(array_map(function ($x) { return ['source' => $x['source'], 'orders' => count($x['orders']), 'revenue' => round($x['revenue'], 2)]; }, $src));
        usort($by_source, function ($a, $b) { return $b['revenue'] <=> $a['revenue']; });
        // Aufrufe der Event-Seite (Kampagnen-Zähler) + Conversion = Bestellungen / Aufrufe
        $views = 0;
        $vt = $wpdb->prefix . 'tixomat_campaign_views';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $vt)) === $vt) {
            $views = intval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(views),0) FROM $vt WHERE event_id = %d", $eid)));
        }
        return [
            'sales_by_day' => $sales,
            'by_channel' => self::by_channel($rows),
            'by_source' => $by_source,
            'checkin_by_slot' => self::checkin_slots($eid),
            'views' => $views,
            'conversion' => $views > 0 ? round(count($oids) / $views * 100, 2) : null,
        ];
    }

    /** Zeitstempel (ISO mit Versatz oder MySQL-Ortszeit) → Ortszeit „Y-m-d H:i:s“ bzw. ''. */
    private static function local($s) {
        $s = trim((string) $s);
        if ($s === '' || !strtotime($s)) return '';
        if (preg_match('/([+-]\d{2}:?\d{2}|Z)$/', $s)) {
            try { return (new DateTime($s))->setTimezone(wp_timezone())->format('Y-m-d H:i:s'); } catch (\Throwable $e) { return ''; }
        }
        return gmdate('Y-m-d H:i:s', strtotime($s . ' UTC'));
    }

    /** Check-ins in 15-Minuten-Schritten (Tickets + Gästeliste), Ortszeit: [{time: "YYYY-MM-DDTHH:MM", count}] */
    public static function checkin_slots($eid) {
        $slots = [];
        foreach (self::checkin_times($eid) as $c) {
            if ($c['local'] === '') continue;
            $ts = strtotime($c['local'] . ' UTC');
            $k = gmdate('Y-m-d\TH:i', $ts - ($ts % 900));
            $slots[$k] = ($slots[$k] ?? 0) + 1;
        }
        ksort($slots);
        $out = [];
        foreach ($slots as $k => $n) $out[] = ['time' => $k, 'count' => $n];
        return $out;
    }

    /** Alle Check-ins eines Events: [{time, type: ticket|guest, code, name, category, by}] (Gäste mit Begleitung zählen einmal je Eintrag). */
    public static function checkin_times($eid) {
        global $wpdb;
        $out = [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, t.meta_value tm, b.meta_value byu, c.meta_value code, n.meta_value nm, k.meta_value cat
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} ev ON ev.post_id = p.ID AND ev.meta_key = '_tix_ticket_event_id' AND ev.meta_value = %s
             INNER JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_tix_ticket_checkin_time'
             LEFT JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = '_tix_ticket_checkin_by'
             LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = '_tix_ticket_code'
             LEFT JOIN {$wpdb->postmeta} n ON n.post_id = p.ID AND n.meta_key = '_tix_ticket_owner_name'
             LEFT JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = '_tix_ticket_cat_name'
             WHERE p.post_type = 'tix_ticket' AND t.meta_value <> ''", (string) intval($eid)));
        foreach ((array) $rows as $r) $out[] = ['time' => (string) $r->tm, 'local' => self::local($r->tm), 'type' => 'ticket', 'code' => (string) $r->code, 'name' => (string) $r->nm, 'category' => (string) $r->cat, 'by' => (string) $r->byu];
        $guests = get_post_meta($eid, '_tix_guest_list', true);
        foreach (is_array($guests) ? $guests : [] as $g) {
            if (empty($g['checkin_time'])) continue;
            $out[] = ['time' => (string) $g['checkin_time'], 'local' => self::local($g['checkin_time']), 'type' => 'guest', 'code' => (string) ($g['ticket_code'] ?? ($g['qr_code'] ?? '')), 'name' => (string) ($g['name'] ?? ''), 'category' => 'Gästeliste', 'by' => (string) ($g['checkin_by'] ?? '')];
        }
        usort($out, function ($a, $b) { return strcmp($a['local'], $b['local']); });
        return $out;
    }

    // ═══════════════ S5: Export-Zeilen ═══════════════

    const CHECKIN_HEADER = ['Uhrzeit', 'Typ', 'Ticket-Code', 'Name', 'Kategorie', 'Einlass durch'];
    const COUPON_HEADER = ['Datum', 'Bestellnr', 'Code', 'Rabatt', 'Bestellbetrag', 'Event', 'E-Mail', 'Status'];

    public static function checkin_rows($eid) {
        $rows = [];
        foreach (self::checkin_times($eid) as $c) {
            $rows[] = [$c['local'] !== '' ? mysql2date('d.m.Y H:i', $c['local']) : $c['time'], $c['type'] === 'guest' ? 'Gast' : 'Ticket', $c['code'], $c['name'], $c['category'], $c['by']];
        }
        return $rows;
    }

    /** Gutschein-Einlösungen: Bestellungen der Events mit Rabatt-Code. */
    public static function coupon_rows(array $event_ids, $from = '', $to = '', $status = 'paid') {
        global $wpdb;
        if (!$event_ids || !class_exists('TIX_Order')) return [];
        list(, $result) = TIX_Organizer_Admin::event_orders($event_ids, ['status' => TIX_Export::status_list($status), 'date_from' => $from, 'date_to' => $to]);
        if (!$result) return [];
        $ids = array_map(function ($r) { return intval($r['order']->id); }, $result);
        $cp = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $names = "'" . implode("','", array_map(function ($id) { return '_tix_order_coupon_' . $id; }, $chunk)) . "'";
            foreach ((array) $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ($names)") as $r) {
                $v = maybe_unserialize($r->option_value);
                if (is_array($v) && !empty($v['code']) && floatval($v['discount'] ?? 0) > 0) $cp[intval(substr($r->option_name, strlen('_tix_order_coupon_')))] = $v;
            }
        }
        $rows = [];
        foreach ($result as $r) {
            $o = $r['order'];
            $c = $cp[intval($o->id)] ?? null;
            if (!$c) continue;
            $events = [];
            foreach ($r['items'] as $it) { $t = get_the_title(intval($it->event_id)); if ($t !== '' && !in_array($t, $events, true)) $events[] = $t; }
            $rows[] = [$o->date_created ? mysql2date('d.m.Y H:i', $o->date_created) : '', (string) $o->order_number, strtoupper((string) $c['code']),
                number_format(floatval($c['discount']), 2, ',', '.'), number_format(floatval($o->total), 2, ',', '.'), implode(', ', $events), (string) $o->billing_email, (string) $o->status];
        }
        return $rows;
    }

    // ═══════════════ Ticket umschreiben (Team) ═══════════════

    /** Ticket fürs Team laden: 404 not_found bzw. tix_transfer 403 mit Grund. */
    private static function team_ticket($tid) {
        if (get_post_type($tid) !== 'tix_ticket') return new WP_Error('not_found', 'Ticket nicht gefunden.', ['status' => 404]);
        $eid = intval(get_post_meta($tid, '_tix_ticket_event_id', true));
        if (!$eid || !TIX_REST_API::can_access_event($eid)) return new WP_Error('not_found', 'Ticket nicht gefunden.', ['status' => 404]);
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::scoped() && class_exists('TIX_Team') && !TIX_Team::user_can(get_current_user_id(), 'view_orders')) {
            return new WP_Error('rest_forbidden', 'Keine Berechtigung für Bestellungen.', ['status' => 403]);
        }
        if ((string) get_post_meta($tid, '_tix_ticket_mirror', true) === '1') return new WP_Error('tix_transfer', 'Dieses Ticket gehört zu einem geteilten Event und wird beim Veranstalter umgeschrieben.', ['status' => 403]);
        if ((string) get_post_meta($tid, '_tix_ticket_checked_in', true) === '1') return new WP_Error('tix_transfer', 'Das Ticket ist schon eingecheckt.', ['status' => 403]);
        $status = (string) (get_post_meta($tid, '_tix_ticket_status', true) ?: 'valid');
        if (!in_array($status, ['valid', 'transferred'], true)) return new WP_Error('tix_transfer', 'Stornierte bzw. verbrauchte Tickets können nicht umgeschrieben werden.', ['status' => 403]);
        return $eid;
    }

    private static function ticket_out($tid) {
        return ['id' => $tid, 'code' => (string) get_post_meta($tid, '_tix_ticket_code', true), 'event_id' => intval(get_post_meta($tid, '_tix_ticket_event_id', true)),
            'name' => (string) get_post_meta($tid, '_tix_ticket_owner_name', true), 'email' => (string) get_post_meta($tid, '_tix_ticket_owner_email', true),
            'status' => (string) (get_post_meta($tid, '_tix_ticket_status', true) ?: 'valid')];
    }

    private static function log_change($tid, $mode, $from, $to, $email = '') {
        $log = get_post_meta($tid, '_tix_ticket_rename_log', true);
        $log = is_array($log) ? $log : [];
        $log[] = ['at' => current_time('c'), 'by' => get_current_user_id(), 'mode' => $mode, 'from' => $from, 'to' => $to, 'email' => $email];
        update_post_meta($tid, '_tix_ticket_rename_log', array_slice($log, -20));
    }

    /**
     * POST /tickets/{id}/transfer {first_name, last_name, email, notify?: true, create_account?: false}
     * Übertragung auf eine neue Person über TIX_Ticket_Transfer – unabhängig von den Kunden-Schaltern (das Team darf immer).
     */
    public static function rest_transfer(WP_REST_Request $req) {
        $tid = absint($req['id']);
        $eid = self::team_ticket($tid);
        if (is_wp_error($eid)) return $eid;
        $first = trim(sanitize_text_field((string) $req->get_param('first_name')));
        $last = trim(sanitize_text_field((string) $req->get_param('last_name')));
        $email = trim((string) $req->get_param('email'));
        if ($first === '') return self::bad('first_name', 'Bitte den Vornamen angeben.');
        if ($last === '') return self::bad('last_name', 'Bitte den Nachnamen angeben.');
        if ($email === '' || !is_email($email)) return self::bad('email', 'Bitte eine gültige E-Mail-Adresse angeben.');
        $notify = $req->get_param('notify') === null ? true : filter_var($req->get_param('notify'), FILTER_VALIDATE_BOOLEAN);
        $account = filter_var($req->get_param('create_account'), FILTER_VALIDATE_BOOLEAN);
        $old = (string) get_post_meta($tid, '_tix_ticket_owner_name', true);
        $r = TIX_Ticket_Transfer::transfer([$tid], $first, $last, sanitize_email($email), true, ['create_account' => $account, 'notify' => $notify]);
        if (is_wp_error($r)) return $r;
        self::log_change($tid, 'transfer', $old, $first . ' ' . $last, sanitize_email($email));
        return rest_ensure_response(['ok' => true, 'ticket' => self::ticket_out($tid)]);
    }

    /** POST /tickets/{id}/holder {name} – nur den Namen korrigieren (E-Mail, Status und Konto bleiben, keine Mail). */
    public static function rest_holder(WP_REST_Request $req) {
        $tid = absint($req['id']);
        $eid = self::team_ticket($tid);
        if (is_wp_error($eid)) return $eid;
        $name = trim(preg_replace('/\s+/u', ' ', sanitize_text_field((string) $req->get_param('name'))));
        if ($name === '' || mb_strlen($name) > 120) return self::bad('name', 'Bitte einen Namen (höchstens 120 Zeichen) angeben.');
        $old = (string) get_post_meta($tid, '_tix_ticket_owner_name', true);
        update_post_meta($tid, '_tix_ticket_owner_name', $name);
        $code = (string) get_post_meta($tid, '_tix_ticket_code', true);
        $guests = get_post_meta($eid, '_tix_guest_list', true);
        if (is_array($guests) && $code !== '') {
            foreach ($guests as &$g) { if (($g['ticket_code'] ?? '') === $code) { $g['name'] = $name; break; } }
            unset($g);
            update_post_meta($eid, '_tix_guest_list', $guests);
        }
        do_action('tix_ticket_renamed', $tid, $name);
        self::log_change($tid, 'holder', $old, $name);
        return rest_ensure_response(['ok' => true, 'ticket' => self::ticket_out($tid)]);
    }
}
