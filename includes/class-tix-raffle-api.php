<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Raffle_API – Gewinnspiele fürs Studio und die Apps (1.38.489, Vertrag tixomat-apps veranstalter-web raffle.ts).
 * Rechte: TIX_REST_API::check_organizer + Event-Zugriff (TIX_REST_API::can_access_event, im Mehr-Veranstalter-Modus
 * eigener Veranstalter). Daten wie bisher in den Event-Metas `_tix_raffle_*` und der Tabelle `tix_raffle_entries`.
 *
 *   GET  /raffles?range=upcoming|past
 *   GET  /events/{id}/raffle            POST /events/{id}/raffle (Teil-Update; Preise nach der Auslosung → 409 tix_raffle_drawn)
 *   GET  /events/{id}/raffle/entries?q=&page=[&format=csv]
 *   POST /events/{id}/raffle/draw {notify}       409 no_entries | no_prizes | already_drawn
 *   POST /events/{id}/raffle/notify {entry_id?}  409 not_drawn, 404 tix_raffle_winner
 *   POST /events/{id}/raffle/reset               409 tix_raffle_checked_in
 */
class TIX_Raffle_API {

    const NS = 'tixomat/v1';
    const PER_PAGE = 50;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        $perm = ['TIX_REST_API', 'check_organizer'];
        $id = '/events/(?P<id>\d+)/raffle';
        register_rest_route(self::NS, '/raffles', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, $id, [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $perm],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $perm],
        ]);
        register_rest_route(self::NS, $id . '/entries', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_entries'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, $id . '/draw', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_draw'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, $id . '/notify', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_notify'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, $id . '/reset', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_reset'], 'permission_callback' => $perm]);
    }

    private static function err($code, $msg, $status, $field = '') {
        return new WP_Error($code, $msg, ['status' => $status] + ($field !== '' ? ['field' => $field] : []));
    }

    /** Event prüfen: existiert und Zugriff. → int|WP_Error */
    private static function event(WP_REST_Request $req) {
        $eid = intval($req['id']);
        if (!$eid || get_post_type($eid) !== 'event') return self::err('tix_event_missing', 'Event nicht gefunden.', 404);
        if (!TIX_REST_API::can_access_event($eid)) return self::err('rest_forbidden', 'Kein Zugriff auf dieses Event.', 403);
        return $eid;
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . TIX_Raffle::TABLE;
    }

    private static function ensure_table() {
        global $wpdb;
        $t = self::table();
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) TIX_Raffle::create_table();
    }

    /** "Y-m-d\TH:i" bzw. "Y-m-d H:i" → "Y-m-d H:i" */
    private static function plain_date($v) {
        $v = str_replace('T', ' ', trim((string) $v));
        return strlen($v) >= 16 ? substr($v, 0, 16) : $v;
    }

    private static function prizes($eid) {
        $p = get_post_meta($eid, '_tix_raffle_prizes', true);
        return array_values(array_map(function ($x) {
            $type = ($x['type'] ?? 'text') === 'ticket' ? 'ticket' : 'text';
            return ['name' => (string) ($x['name'] ?? ''), 'qty' => max(1, intval($x['qty'] ?? 1)), 'type' => $type,
                'cat_index' => intval($x['cat_index'] ?? 0), 'per_winner' => max(1, intval($x['per_winner'] ?? 1))];
        }, is_array($p) ? $p : []));
    }

    private static function categories($eid) {
        $out = [];
        foreach ((array) get_post_meta($eid, '_tix_ticket_categories', true) as $i => $c) {
            if (!is_array($c) || !empty($c['gift_card'])) continue;
            $out[] = ['index' => intval($i), 'name' => (string) ($c['name'] ?? '')];
        }
        return $out;
    }

    public static function payload($eid) {
        self::ensure_table();
        $winners = [];
        foreach ((array) get_post_meta($eid, '_tix_raffle_winners', true) as $w) {
            if (!is_array($w)) continue;
            $codes = [];
            foreach ((array) ($w['ticket_ids'] ?? (!empty($w['ticket_id']) ? [$w['ticket_id']] : [])) as $tid) {
                $c = (string) get_post_meta(intval($tid), '_tix_ticket_code', true);
                if ($c !== '') $codes[] = $c;
            }
            $winners[] = ['entry_id' => intval($w['entry_id'] ?? 0), 'name' => (string) ($w['name'] ?? ''), 'email' => (string) ($w['email'] ?? ''),
                'prize_index' => intval($w['prize_index'] ?? 0), 'prize_name' => (string) ($w['prize_name'] ?? ''), 'per_winner' => max(1, intval($w['per_winner'] ?? 1)),
                'ticket_codes' => $codes, 'notified_at' => !empty($w['notified_at']) ? substr((string) $w['notified_at'], 0, 16) : null];
        }
        $drawn = (string) get_post_meta($eid, '_tix_raffle_drawn_at', true);
        return [
            'enabled' => get_post_meta($eid, '_tix_raffle_enabled', true) === '1',
            'title' => (string) (get_post_meta($eid, '_tix_raffle_title', true) ?: 'Gewinnspiel'),
            'description' => (string) get_post_meta($eid, '_tix_raffle_description', true),
            'end_date' => self::plain_date(get_post_meta($eid, '_tix_raffle_end_date', true)),
            'max_entries' => intval(get_post_meta($eid, '_tix_raffle_max_entries', true)),
            'consent_text' => (string) get_post_meta($eid, '_tix_raffle_consent_text', true),
            'hide_count' => get_post_meta($eid, '_tix_raffle_hide_count', true) === '1',
            'auto_draw' => TIX_Raffle::auto_draw_on($eid),
            'prizes' => self::prizes($eid),
            'status' => TIX_Raffle::status($eid),
            'entries' => TIX_Raffle::count_entries($eid),
            'drawn_at' => $drawn !== '' ? substr($drawn, 0, 16) : null,
            'winners' => $winners,
        ];
    }

    private static function response($eid) {
        return rest_ensure_response(['ok' => true, 'event_id' => $eid, 'raffle' => self::payload($eid), 'categories' => self::categories($eid)]);
    }

    /** GET /raffles?range=upcoming|past → {ok, range, raffles:[{event_id, title, date_start, enabled, status, end_date, entries, prizes, drawn_at}]} */
    public static function rest_list(WP_REST_Request $req) {
        global $wpdb;
        self::ensure_table();
        $range = (string) $req->get_param('range') === 'past' ? 'past' : 'upcoming';
        $args = ['post_type' => 'event', 'post_status' => ['publish', 'private', 'draft', 'future'], 'posts_per_page' => 300, 'fields' => 'ids',
            'meta_query' => ['relation' => 'AND', ['relation' => 'OR', ['key' => '_tix_raffle_enabled', 'value' => '1'], ['key' => '_tix_raffle_prizes', 'compare' => 'EXISTS']]]];
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::scoped()) {
            $oid = TIX_App_Scope::organizer_id_for_user();
            if (!$oid) return rest_ensure_response(['ok' => true, 'range' => $range, 'raffles' => []]);
            $args['meta_query'][] = TIX_App_Scope::event_meta_clause($oid);
        }
        $today = current_time('Y-m-d');
        $yesterday = gmdate('Y-m-d', strtotime($today . ' 12:00:00') - DAY_IN_SECONDS);
        $out = [];
        foreach (array_map('intval', get_posts($args)) as $eid) {
            if (!TIX_REST_API::can_access_event($eid)) continue;
            $prizes = self::prizes($eid);
            $enabled = get_post_meta($eid, '_tix_raffle_enabled', true) === '1';
            if (!$enabled && !$prizes) continue;
            $status = TIX_Raffle::status($eid);
            $date = (string) get_post_meta($eid, '_tix_date_start', true);
            $last = (string) (get_post_meta($eid, '_tix_date_end', true) ?: $date);
            $is_past = $status === 'drawn' || ($last !== '' && $last < $yesterday);
            if (($range === 'past') !== $is_past) continue;
            $drawn = (string) get_post_meta($eid, '_tix_raffle_drawn_at', true);
            $out[] = ['event_id' => $eid, 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'), 'date_start' => $date, 'enabled' => $enabled,
                'status' => $status, 'end_date' => self::plain_date(get_post_meta($eid, '_tix_raffle_end_date', true)), 'entries' => TIX_Raffle::count_entries($eid),
                'prizes' => array_sum(array_column($prizes, 'qty')), 'drawn_at' => $drawn !== '' ? substr($drawn, 0, 16) : null];
        }
        usort($out, function ($a, $b) use ($range) { return $range === 'past' ? strcmp($b['date_start'], $a['date_start']) : strcmp($a['date_start'], $b['date_start']); });
        return rest_ensure_response(['ok' => true, 'range' => $range, 'raffles' => $out]);
    }

    public static function rest_get(WP_REST_Request $req) {
        $eid = self::event($req);
        return is_wp_error($eid) ? $eid : self::response($eid);
    }

    /** POST /events/{id}/raffle – nur mitgeschickte Felder ändern. */
    public static function rest_save(WP_REST_Request $req) {
        $eid = self::event($req);
        if (is_wp_error($eid)) return $eid;
        $b = $req->get_json_params();
        if (!is_array($b)) $b = $req->get_params();
        $set = [];
        if (array_key_exists('title', $b)) $set['_tix_raffle_title'] = mb_substr(sanitize_text_field((string) $b['title']), 0, 160);
        if (array_key_exists('description', $b)) $set['_tix_raffle_description'] = wp_kses_post((string) $b['description']);
        if (array_key_exists('consent_text', $b)) $set['_tix_raffle_consent_text'] = wp_kses_post((string) $b['consent_text']);
        if (array_key_exists('end_date', $b)) {
            $d = self::plain_date($b['end_date']);
            if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $d)) return self::err('tix_invalid', 'Teilnahmeschluss: JJJJ-MM-TT HH:MM.', 400, 'end_date');
            $set['_tix_raffle_end_date'] = $d === '' ? '' : str_replace(' ', 'T', $d); // wie der WP-Editor (datetime-local)
        }
        if (array_key_exists('max_entries', $b)) {
            if (!is_numeric($b['max_entries']) || intval($b['max_entries']) < 0) return self::err('tix_invalid', 'Höchstzahl: 0 (unbegrenzt) oder mehr.', 400, 'max_entries');
            $set['_tix_raffle_max_entries'] = intval($b['max_entries']);
        }
        if (array_key_exists('hide_count', $b)) $set['_tix_raffle_hide_count'] = filter_var($b['hide_count'], FILTER_VALIDATE_BOOLEAN) ? '1' : '';
        if (array_key_exists('auto_draw', $b)) $set['_tix_raffle_auto_draw'] = filter_var($b['auto_draw'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        if (array_key_exists('enabled', $b)) $set['_tix_raffle_enabled'] = filter_var($b['enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '';
        if (array_key_exists('prizes', $b)) {
            if (!is_array($b['prizes'])) return self::err('tix_invalid', 'prizes: Liste erwartet.', 400, 'prizes');
            $cats = array_column(self::categories($eid), 'name', 'index');
            $prizes = [];
            foreach (array_values($b['prizes']) as $i => $p) {
                if (!is_array($p)) return self::err('tix_invalid', 'Preis ungültig.', 400, 'prizes.' . $i);
                $name = mb_substr(sanitize_text_field((string) ($p['name'] ?? '')), 0, 120);
                if ($name === '') return self::err('tix_invalid', 'Jeder Preis braucht einen Namen.', 400, 'prizes.' . $i . '.name');
                $qty = intval($p['qty'] ?? 1); $per = intval($p['per_winner'] ?? 1);
                if ($qty < 1 || $qty > 500) return self::err('tix_invalid', 'Anzahl: 1 bis 500.', 400, 'prizes.' . $i . '.qty');
                if ($per < 1 || $per > 20) return self::err('tix_invalid', 'Tickets je Gewinner: 1 bis 20.', 400, 'prizes.' . $i . '.per_winner');
                $type = ($p['type'] ?? 'text') === 'ticket' ? 'ticket' : 'text';
                $prize = ['name' => $name, 'qty' => $qty, 'per_winner' => $per, 'type' => $type];
                if ($type === 'ticket') {
                    $ci = intval($p['cat_index'] ?? 0);
                    if (!array_key_exists($ci, $cats)) return self::err('tix_invalid', 'Für Freikarten bitte eine Ticketkategorie des Events wählen.', 400, 'prizes.' . $i . '.cat_index');
                    $prize['cat_index'] = $ci;
                }
                $prizes[] = $prize;
            }
            $same = wp_json_encode(array_map(function ($x) { ksort($x); return $x; }, $prizes)) === wp_json_encode(array_map(function ($x) { if ($x['type'] !== 'ticket') unset($x['cat_index']); ksort($x); return $x; }, self::prizes($eid)));
            if (TIX_Raffle::status($eid) === 'drawn' && !$same) {
                return self::err('tix_raffle_drawn', 'Nach der Auslosung lassen sich die Preise nicht mehr ändern. Setze die Auslosung zurück, wenn du neu losen willst.', 409, 'prizes');
            }
            $set['_tix_raffle_prizes'] = $prizes;
        }
        foreach ($set as $k => $v) update_post_meta($eid, $k, $v);
        if (!get_post_meta($eid, '_tix_raffle_status', true)) update_post_meta($eid, '_tix_raffle_status', 'open');
        return self::response($eid);
    }

    /** GET /events/{id}/raffle/entries?q=&page=[&format=csv] */
    public static function rest_entries(WP_REST_Request $req) {
        global $wpdb;
        $eid = self::event($req);
        if (is_wp_error($eid)) return $eid;
        self::ensure_table();
        $t = self::table();
        $where = $wpdb->prepare('event_id = %d', $eid);
        $q = trim((string) $req->get_param('q'));
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where .= $wpdb->prepare(' AND (name LIKE %s OR email LIKE %s)', $like, $like);
        }
        $prizes = self::prizes($eid);
        $row = function ($e) use ($prizes) {
            $win = intval($e->is_winner) === 1;
            return ['id' => intval($e->id), 'name' => (string) $e->name, 'email' => (string) $e->email, 'created_at' => substr((string) $e->created_at, 0, 16),
                'is_winner' => $win, 'prize_name' => $win && $e->prize_index !== null ? (string) ($prizes[intval($e->prize_index)]['name'] ?? '') : ''];
        };
        if ($req->get_param('format') === 'csv') {
            $rows = [];
            foreach ((array) $wpdb->get_results("SELECT * FROM $t WHERE $where ORDER BY created_at ASC, id ASC") as $e) {
                $r = $row($e);
                $rows[] = [$r['name'], $r['email'], wp_date('d.m.Y H:i', strtotime($r['created_at'])), $r['is_winner'] ? ($r['prize_name'] ?: 'ja') : ''];
            }
            $data = class_exists('TIX_Export') ? TIX_Export::csv(['Name', 'E-Mail', 'Teilgenommen', 'Gewinn'], $rows) : '';
            return rest_ensure_response(['ok' => true, 'filename' => 'gewinnspiel-' . $eid . '.csv', 'mime' => 'text/csv; charset=utf-8', 'rows' => count($rows), 'data' => base64_encode($data)]);
        }
        $page = max(1, intval($req->get_param('page') ?: 1));
        $total = intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE $where"));
        $list = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE $where ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", self::PER_PAGE, ($page - 1) * self::PER_PAGE));
        return rest_ensure_response(['ok' => true, 'entries' => array_map($row, $list), 'total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE, 'has_more' => $total > $page * self::PER_PAGE]);
    }

    /** POST /events/{id}/raffle/draw {notify=true} → {ok, status, winners, notified} */
    public static function rest_draw(WP_REST_Request $req) {
        $eid = self::event($req);
        if (is_wp_error($eid)) return $eid;
        $notify = $req->get_param('notify') === null ? true : filter_var($req->get_param('notify'), FILTER_VALIDATE_BOOLEAN);
        $r = TIX_Raffle::draw_winners($eid, $notify);
        if (is_wp_error($r)) {
            $msg = ['no_entries' => 'Noch niemand nimmt teil – ausgelost werden kann erst mit Teilnehmenden.', 'no_prizes' => 'Lege zuerst mindestens einen Preis an und speichere.', 'already_drawn' => 'Dieses Gewinnspiel ist schon ausgelost.'];
            return self::err($r->get_error_code(), $msg[$r->get_error_code()] ?? $r->get_error_message(), 409);
        }
        $p = self::payload($eid);
        return rest_ensure_response(['ok' => true, 'status' => $p['status'], 'notified' => $notify, 'winners' => $p['winners'], 'raffle' => $p]);
    }

    /** POST /events/{id}/raffle/notify {entry_id?} → {ok, sent} */
    public static function rest_notify(WP_REST_Request $req) {
        $eid = self::event($req);
        if (is_wp_error($eid)) return $eid;
        if (TIX_Raffle::status($eid) !== 'drawn') return self::err('not_drawn', 'Es wurde noch nicht ausgelost.', 409);
        $entry = intval($req->get_param('entry_id') ?? 0);
        if ($entry && !in_array($entry, array_map('intval', array_column((array) get_post_meta($eid, '_tix_raffle_winners', true), 'entry_id')), true)) {
            return self::err('tix_raffle_winner', 'Diese Person hat nicht gewonnen.', 404, 'entry_id');
        }
        return rest_ensure_response(['ok' => true, 'sent' => TIX_Raffle::notify($eid, $entry)]);
    }

    /** POST /events/{id}/raffle/reset → {ok, raffle} */
    public static function rest_reset(WP_REST_Request $req) {
        $eid = self::event($req);
        if (is_wp_error($eid)) return $eid;
        $r = TIX_Raffle::reset_draw($eid);
        if (is_wp_error($r)) return self::err($r->get_error_code(), $r->get_error_message(), 409);
        return self::response($eid);
    }
}
