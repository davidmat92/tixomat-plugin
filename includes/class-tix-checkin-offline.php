<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Checkin_Offline – Offline-Einlass als Sitzung (1.38.583).
 *
 *   GET  /checkin/{event_id}/offline   → Liste fürs Gerät: Tickets und Gästeliste mit gehashten Codes
 *        (sha256("<event>|<CODE>"), Klartext-Codes verlassen den Server nicht), Status, Name, Kategorie;
 *        dazu list_id, generated_at, expires_at und eine Signatur (HMAC des Servers).
 *   POST /checkin/batch {event_id, list_id, generated_at, sig, device, items:[{type: ticket|guest, id, at, n?}]}
 *        → je Eintrag ok | conflict (schon eingelassen, mit Zeit und Gerät) | cancelled | invalid | not_found.
 *        Wiederholung vom selben Gerät = ok (sicher bei Verbindungsabbruch). Mehrere Geräte gleichzeitig: der erste
 *        Abgleich gewinnt, die anderen bekommen „conflict“ und zeigen es an.
 * Rechte wie der Einlass (TIX_REST_API::check_entrance + Event-Zugriff + Check-in-Passwort).
 */
class TIX_Checkin_Offline {

    const NS = 'tixomat/v1';
    const MAX_AGE = 7 * DAY_IN_SECONDS; // so lange nimmt der Server Abgleiche einer Liste an

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        register_rest_route(self::NS, '/checkin/(?P<event_id>\d+)/offline', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => ['TIX_REST_API', 'check_entrance'],
        ]);
        register_rest_route(self::NS, '/checkin/batch', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_batch'], 'permission_callback' => ['TIX_REST_API', 'check_entrance'],
        ]);
    }

    public static function hash($event_id, $code) {
        return hash('sha256', intval($event_id) . '|' . strtoupper(trim((string) $code)));
    }

    private static function sign($event_id, $list_id, $generated_at) {
        return hash_hmac('sha256', 'tix-offline|' . intval($event_id) . '|' . $list_id . '|' . intval($generated_at), wp_salt('auth'));
    }

    /** Event-Zugriff + Check-in-Passwort (wie der Online-Einlass) */
    private static function guard($event_id, WP_REST_Request $req) {
        if (!$event_id || get_post_type($event_id) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::can_access_event($event_id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        $pw = (string) get_post_meta($event_id, '_tix_checkin_password', true);
        if ($pw !== '' && (string) $req->get_header('X-Checkin-Password') !== $pw) {
            return new WP_Error('checkin_unauthorized', 'Falsches Check-in-Passwort.', ['status' => 401]);
        }
        return true;
    }

    private static function cat_names($event_id) {
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (is_string($cats)) $cats = json_decode($cats, true);
        $out = [];
        foreach ((array) $cats as $i => $c) if (is_array($c)) $out[intval($i)] = (string) ($c['name'] ?? '');
        return $out;
    }

    public static function rest_list(WP_REST_Request $req) {
        $eid = intval($req['event_id']);
        if (($g = self::guard($eid, $req)) !== true) return $g;
        $cats = self::cat_names($eid);
        $tickets = [];
        $ids = get_posts(['post_type' => 'tix_ticket', 'post_status' => ['publish', 'private', 'draft'], 'numberposts' => -1, 'fields' => 'ids',
            'meta_key' => '_tix_ticket_event_id', 'meta_value' => $eid]);
        foreach ($ids as $tid) {
            $code = (string) get_post_meta($tid, '_tix_ticket_code', true);
            if ($code === '') continue;
            $st = (string) (get_post_meta($tid, '_tix_ticket_status', true) ?: 'valid');
            $in = (bool) get_post_meta($tid, '_tix_ticket_checked_in', true);
            $mirror = class_exists('TIX_Tickets') && TIX_Tickets::mirror_lock_message($tid) !== null;
            $tickets[] = [
                'id'   => intval($tid),
                'h'    => self::hash($eid, $code),
                'name' => (string) get_post_meta($tid, '_tix_ticket_owner_name', true),
                'cat'  => $cats[intval(get_post_meta($tid, '_tix_ticket_cat_index', true))] ?? '',
                'st'   => $mirror ? 'mirror' : ($st === 'cancelled' ? 'cancelled' : ($in ? 'used' : 'valid')),
                'at'   => $in ? (string) get_post_meta($tid, '_tix_ticket_checkin_time', true) : '',
            ];
        }
        $guests = [];
        foreach ((array) get_post_meta($eid, '_tix_guest_list', true) as $g) {
            if (!is_array($g) || empty($g['id'])) continue;
            $total = 1 + intval($g['plus'] ?? 0);
            $count = isset($g['checked_in_count']) ? intval($g['checked_in_count']) : (!empty($g['checked_in']) ? $total : 0);
            $guests[] = ['id' => (string) $g['id'], 'h' => self::hash($eid, (string) $g['id']), 'name' => (string) ($g['name'] ?? ''), 'total' => $total, 'count' => $count];
        }
        $now = time();
        $end = self::event_end($eid);
        $list_id = wp_generate_uuid4();
        return rest_ensure_response([
            'ok' => true, 'event_id' => $eid, 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'),
            'list_id' => $list_id, 'generated_at' => $now,
            // gültig bis Event-Ende + 12 h, mindestens 12 h, höchstens 3 Tage
            'expires_at' => min($now + 3 * DAY_IN_SECONDS, max($now + 12 * HOUR_IN_SECONDS, $end + 12 * HOUR_IN_SECONDS)),
            'sig' => self::sign($eid, $list_id, $now),
            'tickets' => $tickets, 'guests' => $guests,
        ]);
    }

    private static function event_end($eid) {
        $d = (string) (get_post_meta($eid, '_tix_date_end', true) ?: get_post_meta($eid, '_tix_date_start', true));
        $t = (string) (get_post_meta($eid, '_tix_time_end', true) ?: '23:59');
        $ts = strtotime($d . ' ' . $t . ' ' . wp_timezone_string());
        return $ts ?: time();
    }

    public static function rest_batch(WP_REST_Request $req) {
        $eid = intval($req->get_param('event_id'));
        if (($g = self::guard($eid, $req)) !== true) return $g;
        $list_id = (string) $req->get_param('list_id');
        $gen = intval($req->get_param('generated_at'));
        if (!hash_equals(self::sign($eid, $list_id, $gen), (string) $req->get_param('sig')) || time() - $gen > self::MAX_AGE) {
            return new WP_Error('tix_offline_list', 'Die Offline-Liste ist ungültig oder zu alt. Bitte neu laden.', ['status' => 409]);
        }
        $device = substr(preg_replace('/[^A-Za-z0-9._-]/', '', (string) $req->get_param('device')), 0, 40) ?: 'geraet';
        $by = 'offline:' . $device;
        $items = (array) $req->get_param('items');
        if (count($items) > 2000) return new WP_Error('tix_too_many', 'Zu viele Einträge auf einmal.', ['status' => 413]);
        $results = [];
        $guests = null; $guests_dirty = false;
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $type = ($it['type'] ?? 'ticket') === 'guest' ? 'guest' : 'ticket';
            $id = $type === 'guest' ? sanitize_text_field((string) ($it['id'] ?? '')) : intval($it['id'] ?? 0);
            $at = sanitize_text_field((string) ($it['at'] ?? ''));
            if ($type === 'ticket') {
                $results[] = ['type' => 'ticket', 'id' => $id] + self::batch_ticket($eid, $id, $by, $at);
            } else {
                if ($guests === null) { $guests = get_post_meta($eid, '_tix_guest_list', true); $guests = is_array($guests) ? $guests : []; }
                $n = max(1, intval($it['n'] ?? 1));
                $r = ['status' => 'not_found'];
                foreach ($guests as &$gu) {
                    if ((string) ($gu['id'] ?? '') !== (string) $id) continue;
                    $total = 1 + intval($gu['plus'] ?? 0);
                    $cur = isset($gu['checked_in_count']) ? intval($gu['checked_in_count']) : (!empty($gu['checked_in']) ? $total : 0);
                    $seen = (array) ($gu['offline_seen'] ?? []);
                    $key = $device . '|' . $at;
                    if (in_array($key, $seen, true)) { $r = ['status' => 'ok', 'count' => $cur, 'total' => $total]; break; }
                    if ($cur + $n > $total) { $r = ['status' => 'conflict', 'count' => $cur, 'total' => $total]; break; }
                    $gu['checked_in_count'] = $cur + $n;
                    $gu['checked_in'] = $gu['checked_in_count'] >= $total;
                    $gu['offline_seen'] = array_slice(array_merge($seen, [$key]), -50);
                    $guests_dirty = true;
                    $r = ['status' => 'ok', 'count' => $gu['checked_in_count'], 'total' => $total];
                    break;
                }
                unset($gu);
                $results[] = ['type' => 'guest', 'id' => $id] + $r;
            }
        }
        if ($guests_dirty) update_post_meta($eid, '_tix_guest_list', $guests);
        $c = array_count_values(array_column($results, 'status'));
        return rest_ensure_response(['ok' => true, 'results' => $results, 'counts' => $c]);
    }

    private static function batch_ticket($eid, $tid, $by, $at) {
        $p = $tid ? get_post($tid) : null;
        if (!$p || $p->post_type !== 'tix_ticket' || intval(get_post_meta($tid, '_tix_ticket_event_id', true)) !== $eid) return ['status' => 'not_found'];
        if (class_exists('TIX_Tickets') && TIX_Tickets::mirror_lock_message($tid) !== null) return ['status' => 'invalid'];
        $st = (string) (get_post_meta($tid, '_tix_ticket_status', true) ?: 'valid');
        if ($st === 'cancelled') return ['status' => 'cancelled'];
        if ((bool) get_post_meta($tid, '_tix_ticket_checked_in', true)) {
            $prev = (string) get_post_meta($tid, '_tix_ticket_checkin_by', true);
            // dasselbe Gerät erneut (Abbruch beim Abgleich) → ok
            if ($prev === $by) return ['status' => 'ok', 'checked_at' => (string) get_post_meta($tid, '_tix_ticket_checkin_time', true)];
            return ['status' => 'conflict', 'checked_at' => (string) get_post_meta($tid, '_tix_ticket_checkin_time', true), 'by' => $prev];
        }
        TIX_Tickets::checkin_ticket($tid, $by);
        if ($at !== '') update_post_meta($tid, '_tix_ticket_checkin_offline_at', $at);
        return ['status' => 'ok', 'checked_at' => (string) get_post_meta($tid, '_tix_ticket_checkin_time', true)];
    }
}
