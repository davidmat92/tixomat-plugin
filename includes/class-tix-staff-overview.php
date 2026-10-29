<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Staff_Overview – Zahlen für die Startseite des Veranstalter-Bereichs (1.38.590).
 *
 *   GET /staff/overview?days=14[&event_id=<id>[&live=1]]
 *     → {days: [{date, tickets, revenue}] (ältester zuerst, ohne Lücken, Ortszeit),
 *        last7 {tickets, revenue}, prev7 {tickets, revenue},
 *        images {event_id: großes Bild} der nächsten Events,
 *        event?: {id, today (Tickets heute), tickets, revenue (gesamt), image (groß)} mit event_id,
 *        live?: {event_id, checked_in, total, last15, last_checkin (Ortszeit), last_ago (Sekunden), slots: [5× je 15 Min., ältester zuerst]}}
 * Tage und Umsatz aus den nativen Bestellungen (Status abgeschlossen/in Bearbeitung), nur Events, die das Konto sehen
 * darf (Mehr-Veranstalter-Modus: eigener Veranstalter). Live nur mit `event_id` + `live=1` (Event des Tages): Einlässe der Ticket-
 * Posts nach `_tix_ticket_checkin_time`. 60 s zwischengespeichert je Konto.
 */
class TIX_Staff_Overview {

    const NS = 'tixomat/v1';

    public static function init() {
        add_action('rest_api_init', function () {
            register_rest_route(self::NS, '/staff/overview', [
                'methods' => 'GET', 'callback' => [__CLASS__, 'rest'], 'permission_callback' => ['TIX_REST_API', 'check_organizer'],
            ]);
        });
    }

    private static function allowed($eid) {
        if (!(class_exists('TIX_App_Scope') && TIX_App_Scope::multi())) return true;
        return TIX_App_Scope::event_allowed($eid);
    }

    public static function rest(WP_REST_Request $req) {
        $days = max(7, min(31, intval($req->get_param('days') ?: 14)));
        $eid = absint($req->get_param('event_id'));
        if ($eid && (get_post_type($eid) !== 'event' || !TIX_REST_API::can_access_event($eid))) {
            return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        }
        $live = $eid && filter_var($req->get_param('live'), FILTER_VALIDATE_BOOLEAN);
        $ck = 'tix_sov_' . md5(get_current_user_id() . '|' . $days . '|' . $eid . '|' . ($live ? 1 : 0) . '|' . current_time('Y-m-d H:i'));
        $hit = get_transient($ck);
        if (is_array($hit)) return rest_ensure_response($hit);
        $out = ['ok' => true] + self::days($days) + ['images' => self::upcoming_images()];
        if ($eid) $out['event'] = self::event($eid);
        if ($live) $out['live'] = self::live($eid);
        set_transient($ck, $out, 60);
        return rest_ensure_response($out);
    }

    /** Tickets und Umsatz je Tag (Tag der Bestellung, Zeitzone der Seite) */
    public static function days($days, $now = null) {
        $now = $now ?: current_time('timestamp');
        $map = [];
        // $now ist Ortszeit (current_time('timestamp')) → gmdate liefert den Ortstag
        for ($i = $days - 1; $i >= 0; $i--) $map[gmdate('Y-m-d', $now - $i * DAY_IN_SECONDS)] = ['tickets' => 0, 'revenue' => 0.0];
        $from = array_key_first($map);
        $ok = [];
        if (class_exists('TIX_Order')) {
            foreach ((array) TIX_Order::query(['status' => ['completed', 'processing'], 'date_from' => $from, 'limit' => 20000]) as $o) {
                $c = $o->get_date_created();
                $day = $c ? $c->format('Y-m-d') : '';
                if (!isset($map[$day])) continue;
                foreach ($o->get_items() as $it) {
                    $e = intval($it->get_event_id());
                    if (!array_key_exists($e, $ok)) $ok[$e] = $e && self::allowed($e);
                    if (!$ok[$e]) continue;
                    $map[$day]['tickets'] += intval($it->get_quantity());
                    $map[$day]['revenue'] += (float) $it->get_total();
                }
            }
        }
        $rows = [];
        foreach ($map as $d => $v) $rows[] = ['date' => $d, 'tickets' => $v['tickets'], 'revenue' => round($v['revenue'], 2)];
        $sum = function ($list) {
            return ['tickets' => array_sum(array_column($list, 'tickets')), 'revenue' => round(array_sum(array_column($list, 'revenue')), 2)];
        };
        $last7 = array_slice($rows, -7);
        $prev7 = count($rows) >= 14 ? array_slice($rows, -14, 7) : [];
        return ['days' => $rows, 'last7' => $sum($last7), 'prev7' => $prev7 ? $sum($prev7) : null];
    }

    /** Ein Event: Tickets heute, Tickets und Umsatz gesamt (abgeschlossene Bestellungen) */
    public static function event($eid, $now = null) {
        $today = gmdate('Y-m-d', $now ?: current_time('timestamp'));
        $out = ['id' => intval($eid), 'today' => 0, 'tickets' => 0, 'revenue' => 0.0, 'image' => self::image($eid)];
        if (!class_exists('TIX_Order')) return $out;
        foreach ((array) TIX_Order::query(['event_id' => $eid, 'status' => ['completed', 'processing'], 'limit' => 20000]) as $o) {
            $c = $o->get_date_created();
            foreach ($o->get_items() as $it) {
                if (intval($it->get_event_id()) !== intval($eid)) continue;
                $q = intval($it->get_quantity());
                $out['tickets'] += $q;
                $out['revenue'] += (float) $it->get_total();
                if ($c && $c->format('Y-m-d') === $today) $out['today'] += $q;
            }
        }
        $out['revenue'] = round($out['revenue'], 2);
        return $out;
    }

    /** Großes Bild (large, ca. 1024 px) – die Staff-Liste liefert nur das kleine */
    public static function image($eid) {
        $tid = get_post_thumbnail_id($eid);
        return $tid ? (string) (wp_get_attachment_image_url($tid, 'large') ?: '') : '';
    }

    /** Große Bilder der nächsten Events, die das Konto sehen darf {event_id: url} (Bildkarten der Startseite) */
    public static function upcoming_images($limit = 12) {
        $ids = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'private'], 'numberposts' => 60, 'fields' => 'ids',
            'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'order' => 'ASC',
            'meta_query' => [['key' => '_tix_date_start', 'value' => current_time('Y-m-d'), 'compare' => '>=', 'type' => 'DATE']]]);
        $out = [];
        foreach ($ids as $eid) {
            if (!self::allowed($eid)) continue;
            $u = self::image($eid);
            if ($u !== '') $out[(string) $eid] = $u;
            if (count($out) >= $limit) break;
        }
        return (object) $out;
    }

    /** Einlässe live: gesamt, letzte 15 Min., letzte 5 Viertelstunden */
    public static function live($eid, $now = null) {
        $now = $now ?: current_time('timestamp');
        $ids = get_posts(['post_type' => 'tix_ticket', 'post_status' => ['publish', 'private', 'draft'], 'numberposts' => -1, 'fields' => 'ids',
            'meta_query' => [['key' => '_tix_ticket_event_id', 'value' => $eid]]]);
        $total = 0; $in = 0; $last = 0; $slots = [0, 0, 0, 0, 0];
        foreach ($ids as $tid) {
            if ((string) get_post_meta($tid, '_tix_ticket_status', true) === 'cancelled') continue;
            $total++;
            if (!get_post_meta($tid, '_tix_ticket_checked_in', true)) continue;
            $in++;
            $t = strtotime((string) get_post_meta($tid, '_tix_ticket_checkin_time', true));
            if (!$t) continue;
            // gespeichert als current_time('c'): Ortszeit, wie $now
            $last = max($last, $t);
            $ago = $now - $t;
            if ($ago >= 0 && $ago < 75 * MINUTE_IN_SECONDS) $slots[4 - intdiv($ago, 15 * MINUTE_IN_SECONDS)]++;
        }
        // Gästeliste zählt mit (ohne Zeit)
        foreach ((array) get_post_meta($eid, '_tix_guest_list', true) as $g) {
            if (!is_array($g)) continue;
            $n = 1 + intval($g['plus'] ?? 0);
            $total += $n;
            $in += min($n, isset($g['checked_in_count']) ? intval($g['checked_in_count']) : (!empty($g['checked_in']) ? $n : 0));
        }
        return ['event_id' => intval($eid), 'checked_in' => $in, 'total' => $total, 'last15' => $slots[4],
            'last_checkin' => $last ? gmdate('Y-m-d H:i:s', $last) : null, 'last_ago' => $last ? max(0, $now - $last) : null, 'slots' => $slots];
    }
}
