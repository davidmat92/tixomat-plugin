<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Demo_Seed – Demodaten für neue Kunden-Shops mit „Demodaten löschen“ im Studio (1.38.860, User 16.11.,
 * Plan uebergaben/plan-demodaten-neukunden-2026-11-16.md, DOCUMENTATION §222).
 *
 * Nur auf Kunden-Shops (Einzel-Seite, keine Demo-Instanz). Grundlage sind die Vorlagen der Demo-Instanzen (TIX_Demo_Content,
 * Bausteine aus TIX_Demo/TIX_Demo_Shop), aber ohne nächtlichen Reset und ohne Eingriff in Einstellungen des Kunden
 * (keine App-Kacheln, keine Sponsoren, kein Musikwunsch-Schalter).
 *
 *  - Kennzeichnung: Meta _tix_demo_seed = 1 an Events, Künstlern, Tipps; Bestellungen mit Zahlart demo (@demo.invalid);
 *    Option tix_demo_seed = {profile, seeded_at, events:{id: Fingerabdruck}, orders, artists, tips, giftcards, notifications}.
 *  - Löschen entfernt nur genau diese Daten. Geänderte Demo-Events (Fingerabdruck anders) bleiben, außer include_edited;
 *    Demo-Events mit echten Bestellungen bleiben immer.
 *  - Keine Wirkung nach außen: is_event() schließt Datenspiegel, Verteilung und Tages-Hinweise aus; Zahlart demo ohne Rechnung,
 *    ohne Kontingent (TIX_Trial_Site::tickets_since); *.invalid wird nie zugestellt; während des Seeds keine Mails/Pushes.
 *  - Live: Punkt demo_data in /studio/readiness, POST /studio/go-live {action: live} → 409 tix_demo_data_present ohne keep_demo_data.
 */
class TIX_Demo_Seed {

    const NS = 'tixomat/v1';
    const OPT = 'tix_demo_seed';
    const META = '_tix_demo_seed';
    const PROFILES = ['festival', 'club'];
    const ORDERS = 40;
    /** Felder, deren Änderung ein Demo-Event zu „vom Kunden bearbeitet“ macht */
    const FP_META = ['_tix_date_start', '_tix_date_end', '_tix_time_start', '_tix_time_end', '_tix_location', '_tix_ticket_categories', '_tix_info_description', '_tix_info_lineup', '_thumbnail_id'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat demo-data', [__CLASS__, 'cli']);
    }

    /** Nur Kunden-Shops: keine Mehr-Veranstalter-Plattform, keine Demo-Instanz */
    public static function available() {
        if (get_option('tix_multi_organizer', '0') === '1' || (class_exists('TIX_App_Scope') && TIX_App_Scope::multi())) return false;
        if (class_exists('TIX_Demo_Shop') && TIX_Demo_Shop::on()) return false;
        return true;
    }

    public static function registry() {
        $r = get_option(self::OPT, []);
        return is_array($r) ? $r : [];
    }

    /** Event aus den Demodaten (für Datenspiegel, Verteilung, Tages-Hinweise) */
    public static function is_event($event_id) {
        $event_id = intval($event_id);
        return $event_id > 0 && (string) get_post_meta($event_id, self::META, true) === '1';
    }

    /** Fingerabdruck eines Events (rein bis auf das Lesen): Titel, Texte, Status und die Felder aus FP_META */
    public static function fingerprint($event_id) {
        $p = get_post(intval($event_id));
        if (!$p) return '';
        $m = [];
        foreach (self::FP_META as $k) $m[$k] = get_post_meta($p->ID, $k, true);
        return md5(wp_json_encode([$p->post_title, $p->post_content, $p->post_excerpt, $p->post_status, $m]));
    }

    // ═══════════════ Anlegen ═══════════════

    private static function event_ids_with($meta, $type = 'event') {
        return array_map('intval', get_posts(['post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true, 'tix_include_system' => 1, 'tix_include_sandbox' => 1, 'meta_key' => $meta, 'meta_value' => '1']));
    }

    private static function demo_coupons() {
        wp_cache_delete('tix_coupons', 'options');
        $cp = get_option('tix_coupons', []);
        return is_array($cp) ? array_keys(array_filter($cp, function ($c) { return is_array($c) && !empty($c['demo']); })) : [];
    }

    private static function demo_feed() {
        if (!class_exists('TIX_Notifications')) return [];
        return array_values(array_filter(array_map(function ($it) { return is_array($it) ? (string) ($it['id'] ?? '') : ''; }, (array) get_option(TIX_Notifications::OPT_FEED, [])), function ($id) { return strpos($id, 'b_demo_') === 0; }));
    }

    /** → {ok, action, profile, skipped?, counts, ms} oder {ok:false, error, message} */
    public static function seed($profile, $orders = self::ORDERS) {
        $t0 = microtime(true);
        $base = ['ok' => false, 'action' => 'seed'];
        if (!self::available()) return $base + ['error' => 'tix_demo_seed_unavailable', 'message' => 'Demodaten gibt es nur in Kunden-Shops.'];
        if (!in_array($profile, self::PROFILES, true)) return $base + ['error' => 'tix_invalid_field', 'field' => 'profile', 'message' => 'Profil: festival oder club.'];
        if (!class_exists('TIX_Demo') || !class_exists('TIX_Demo_Content') || !class_exists('TIX_Demo_Shop') || !class_exists('TIX_Native_Checkout')) return $base + ['error' => 'tix_demo_missing', 'message' => 'Demo-Bausteine fehlen.'];
        if (self::present()) return ['ok' => true, 'action' => 'seed', 'skipped' => true, 'profile' => (string) (self::registry()['profile'] ?? '')] + self::status_counts();
        $lock = 'tix_demo_seed_running';
        if (get_transient($lock)) return $base + ['error' => 'tix_demo_busy', 'message' => 'Läuft schon.'];
        set_transient($lock, 1, 10 * MINUTE_IN_SECONDS);
        global $wpdb;
        $ot = $wpdb->prefix . 'tix_orders';
        $max_order = intval($wpdb->get_var("SELECT MAX(id) FROM $ot"));
        $before = ['events' => self::event_ids_with('_tix_demo'), 'artists' => class_exists('TIX_Artists') ? self::event_ids_with('_tix_demo_artist', TIX_Artists::CPT) : [],
            'tips' => class_exists('TIX_App_Tips') ? self::event_ids_with('_tix_demo', TIX_App_Tips::CPT) : [], 'coupons' => self::demo_coupons(), 'feed' => self::demo_feed()];
        // Während des Laufs: keine Mails, keine Pushes
        add_filter('pre_wp_mail', '__return_true', 1);
        add_filter('tix_staff_push_recipients', '__return_empty_array', 99);
        try {
            mt_srand(intval(wp_date('Ymd')) + ($profile === 'club' ? 0 : 11));
            $ev = $profile === 'club' ? self::seed_club($orders) : self::seed_festival($orders);
            self::giftcards();
            if (class_exists('TIX_App_Tips')) TIX_Demo_Shop::seed_tips($profile === 'club' ? 'club' : 'openair', $ev);
            TIX_Demo_Shop::seed_notifications($profile === 'club' ? 'club' : 'openair', $ev);
        } finally {
            delete_transient($lock);
            remove_filter('pre_wp_mail', '__return_true', 1);
            remove_filter('tix_staff_push_recipients', '__return_empty_array', 99);
        }
        $events = array_values(array_diff(self::event_ids_with('_tix_demo'), $before['events']));
        $artists = class_exists('TIX_Artists') ? array_values(array_diff(self::event_ids_with('_tix_demo_artist', TIX_Artists::CPT), $before['artists'])) : [];
        $tips = class_exists('TIX_App_Tips') ? array_values(array_diff(self::event_ids_with('_tix_demo', TIX_App_Tips::CPT), $before['tips'])) : [];
        foreach (array_merge($events, $artists, $tips) as $id) update_post_meta($id, self::META, '1');
        $fp = [];
        foreach ($events as $id) $fp[$id] = self::fingerprint($id);
        $order_ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM $ot WHERE id > %d AND payment_method = 'demo'", $max_order)));
        update_option(self::OPT, ['profile' => $profile, 'seeded_at' => wp_date('c'), 'events' => $fp, 'orders' => $order_ids, 'artists' => $artists, 'tips' => $tips,
            'giftcards' => array_values(array_diff(self::demo_coupons(), $before['coupons'])), 'notifications' => array_values(array_diff(self::demo_feed(), $before['feed']))], false);
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
        return ['ok' => true, 'action' => 'seed', 'profile' => $profile] + self::status_counts() + ['ms' => intval((microtime(true) - $t0) * 1000)];
    }

    /** Club: Programm wie Demo B (ohne Musikwunsch-Schalter) → [key => event_id] */
    private static function seed_club($orders) {
        $ev = []; $tpl = [];
        foreach (array_merge(TIX_Demo_Content::club_events(), TIX_Demo_Content::club_weekly(), TIX_Demo_Content::club_more()) as $s) {
            $tpl[$s['key']] = $s;
            unset($s['image'], $s['gallery']); // nur KI-Beispielbilder
            $eid = TIX_Demo::make_from($s, 0, TIX_Demo_Content::ADDRESS_CLUB);
            if (!$eid) continue;
            $ev[$s['key']] = $eid;
            TIX_Demo_Content::no_lookup($eid, array_map('wp_strip_all_tags', preg_split('/<br\s*\/?>/', (string) $s['lineup'])));
            if (!empty($s['free'])) { update_post_meta($eid, '_tix_tickets_enabled', '0'); update_post_meta($eid, '_tix_free_entry', '1'); update_post_meta($eid, '_tix_free_entry_note', (string) $s['free']); }
        }
        TIX_Demo_Shop::enrich_club($ev, $tpl);
        TIX_Demo::seed_orders($ev, $orders, 'past');
        self::checkins($ev, ['past' => 90, 'live' => 30]);
        foreach (['live' => [8, 3], 'w1' => [6, 0]] as $k => $g) if (!empty($ev[$k])) update_post_meta($ev[$k], '_tix_guest_list', TIX_Demo_Shop::guests($g[0], $g[1]));
        return $ev;
    }

    /** Festival: das Festival der Demo A plus Warm-up, Kino und vergangener Saisonstart (Statistik) → [key => event_id] */
    private static function seed_festival($orders) {
        $f = TIX_Demo_Shop::seed_openair(intval($orders * 0.7));
        $ev = !empty($f['event_id']) ? ['fest' => intval($f['event_id'])] : [];
        $more = [];
        foreach (TIX_Demo_Content::openair_events() as $s) {
            unset($s['image'], $s['gallery']);
            $eid = TIX_Demo::make_from($s, 0, TIX_Demo_Content::ADDRESS_FESTIVAL);
            if (!$eid) continue;
            $more[$s['key']] = $eid;
            TIX_Demo_Content::no_lookup($eid, array_map('wp_strip_all_tags', preg_split('/<br\s*\/?>/', (string) $s['lineup'])));
            if (class_exists('TIX_Demo_Content') && ($img = TIX_Demo_Content::ki_image(TIX_Demo_Content::KI_IMAGES[count($more) % count(TIX_Demo_Content::KI_IMAGES)]))) set_post_thumbnail($eid, $img);
        }
        TIX_Demo::seed_orders($more, max(0, $orders - intval($orders * 0.7)), 'past');
        self::checkins($more, ['past' => 90]);
        return $ev + $more;
    }

    private static function checkins(array $ev, array $pct) {
        if (!class_exists('TIX_Tickets') || !method_exists('TIX_Tickets', 'checkin_ticket')) return;
        foreach ($pct as $k => $p) {
            if (empty($ev[$k])) continue;
            foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => [['key' => '_tix_ticket_event_id', 'value' => (string) $ev[$k]]]]) as $tid) {
                if (mt_rand(1, 100) <= $p) TIX_Tickets::checkin_ticket($tid, 'demo');
            }
        }
    }

    private static function giftcards() {
        wp_cache_delete('tix_coupons', 'options');
        $cp = get_option('tix_coupons', []);
        $cp = is_array($cp) ? $cp : [];
        foreach ([[50, 50], [25, 12.5], [100, 0]] as $j => $gv) {
            $code = 'GS-DEMO-' . strtoupper(wp_generate_password(4, false, false));
            $cp[$code] = ['discount_type' => 'giftcard', 'value' => $gv[0], 'balance' => $gv[1], 'expires' => wp_date('Y-m-d', time() + 3 * YEAR_IN_SECONDS), 'organizer_id' => 0, 'ticket_id' => 0, 'order_id' => 0,
                'redemptions' => $gv[1] < $gv[0] ? [['ts' => current_time('mysql'), 'amount' => $gv[0] - $gv[1], 'channel' => 'bar', 'ref' => 'demo', 'rest' => $gv[1]]] : [],
                'created' => current_time('mysql'), 'description' => 'Beispiel-Gutschein', 'max_uses' => 0, 'used' => 0, 'demo' => 1,
                'gift' => ['recipient_name' => TIX_Demo::FIRST[$j + 5], 'from_name' => TIX_Demo::FIRST[$j + 9], 'message' => 'Viel Spaß!']];
        }
        update_option('tix_coupons', $cp);
    }

    // ═══════════════ Stand ═══════════════

    public static function present() {
        $r = self::registry();
        return !empty($r['events']) || !empty($r['orders']) || !empty($r['artists']) || !empty($r['tips']) || !empty($r['giftcards']) || !empty($r['notifications']);
    }

    /** Echte (nicht-Demo) Bestellungen je Event → [event_id => Anzahl] */
    private static function real_orders(array $events) {
        global $wpdb;
        $events = array_filter(array_map('intval', $events));
        if (!$events) return [];
        $rows = $wpdb->get_results("SELECT i.event_id, COUNT(DISTINCT o.id) n FROM {$wpdb->prefix}tix_order_items i JOIN {$wpdb->prefix}tix_orders o ON o.id = i.order_id
            WHERE i.event_id IN (" . implode(',', $events) . ") AND o.payment_method <> 'demo' GROUP BY i.event_id");
        $out = [];
        foreach ((array) $rows as $r) $out[intval($r->event_id)] = intval($r->n);
        return $out;
    }

    private static function status_counts() {
        global $wpdb;
        $r = self::registry();
        $ev = array_map('intval', array_keys((array) ($r['events'] ?? [])));
        $ev = array_values(array_filter($ev, function ($id) { return (bool) get_post($id); }));
        $orders = array_map('intval', (array) ($r['orders'] ?? []));
        $tickets = $orders && class_exists('TIX_Ticket_DB') && TIX_Ticket_DB::table_exists()
            ? intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}" . TIX_Ticket_DB::TABLE_SUFFIX . " WHERE order_id IN (" . implode(',', $orders) . ")")) : 0;
        return ['counts' => ['events' => count($ev), 'orders' => count($orders), 'tickets' => $tickets, 'artists' => count((array) ($r['artists'] ?? [])), 'giftcards' => count((array) ($r['giftcards'] ?? [])),
            'tips' => count((array) ($r['tips'] ?? [])), 'notifications' => count((array) ($r['notifications'] ?? []))]];
    }

    public static function status() {
        $r = self::registry();
        $ev = array_filter((array) ($r['events'] ?? []), function ($fp, $id) { return (bool) get_post(intval($id)); }, ARRAY_FILTER_USE_BOTH);
        $real = self::real_orders(array_keys($ev));
        $edited = []; $in_use = [];
        foreach ($ev as $id => $fp) {
            $id = intval($id);
            $t = html_entity_decode((string) get_the_title($id), ENT_QUOTES, 'UTF-8');
            if (!empty($real[$id])) $in_use[] = ['id' => $id, 'title' => $t, 'orders' => $real[$id]];
            elseif (self::fingerprint($id) !== $fp) $edited[] = ['id' => $id, 'title' => $t];
        }
        return ['ok' => true, 'present' => self::present(), 'profile' => $r['profile'] ?? null, 'seeded_at' => $r['seeded_at'] ?? null] + self::status_counts()
            + ['ids' => ['events' => array_map('intval', array_keys($ev)), 'orders' => array_map('intval', (array) ($r['orders'] ?? [])), 'artists' => array_map('intval', (array) ($r['artists'] ?? [])), 'tips' => array_map('intval', (array) ($r['tips'] ?? []))],
               'edited' => $edited, 'in_use' => $in_use];
    }

    // ═══════════════ Löschen ═══════════════

    /** Nur die Demodaten. Geänderte Events bleiben ohne $include_edited, Events mit echten Bestellungen immer. */
    public static function delete($include_edited = false) {
        global $wpdb;
        $r = self::registry();
        $n = ['events' => 0, 'orders' => 0, 'tickets' => 0, 'artists' => 0, 'giftcards' => 0, 'tips' => 0, 'notifications' => 0];
        $evs = (array) ($r['events'] ?? []);
        $real = self::real_orders(array_keys($evs));
        $kept = []; $drop = []; $keep_fp = [];
        foreach ($evs as $id => $fp) {
            $id = intval($id);
            if (!get_post($id)) continue;
            $t = html_entity_decode((string) get_the_title($id), ENT_QUOTES, 'UTF-8');
            if (!empty($real[$id])) { $kept[] = ['id' => $id, 'title' => $t, 'reason' => 'in_use']; $keep_fp[$id] = $fp; continue; }
            if (!$include_edited && self::fingerprint($id) !== $fp) { $kept[] = ['id' => $id, 'title' => $t, 'reason' => 'edited']; $keep_fp[$id] = $fp; continue; }
            $drop[] = $id;
        }
        // Demo-Bestellungen: nur die, deren Positionen alle an gelöschten Events hängen
        $orders = array_map('intval', (array) ($r['orders'] ?? []));
        $del_orders = []; $keep_orders = [];
        if ($orders) {
            $map = [];
            foreach ((array) $wpdb->get_results("SELECT order_id, event_id FROM {$wpdb->prefix}tix_order_items WHERE order_id IN (" . implode(',', $orders) . ")") as $row) $map[intval($row->order_id)][] = intval($row->event_id);
            foreach ($orders as $o) {
                $pm = (string) $wpdb->get_var($wpdb->prepare("SELECT payment_method FROM {$wpdb->prefix}tix_orders WHERE id = %d", $o));
                if ($pm !== 'demo') continue; // nie fremde Bestellungen
                if (array_diff($map[$o] ?? [], $drop)) $keep_orders[] = $o; else $del_orders[] = $o;
            }
        }
        if ($del_orders && class_exists('TIX_Ticket_DB') && TIX_Ticket_DB::table_exists()) {
            $in = implode(',', $del_orders);
            foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => array_values(get_post_stati()), 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true, 'meta_query' => [['key' => '_tix_ticket_order_id', 'value' => array_map('strval', $del_orders), 'compare' => 'IN']]]) as $tp) wp_delete_post($tp, true);
            $n['tickets'] = intval($wpdb->query("DELETE FROM {$wpdb->prefix}" . TIX_Ticket_DB::TABLE_SUFFIX . " WHERE order_id IN ($in)"));
        }
        $n['orders'] = $del_orders ? TIX_Demo::delete_orders($del_orders) : 0;
        $re = $wpdb->prefix . (class_exists('TIX_Raffle') ? TIX_Raffle::TABLE : 'tix_raffle_entries');
        if ($drop && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $re)) === $re) $wpdb->query("DELETE FROM $re WHERE event_id IN (" . implode(',', $drop) . ")");
        foreach ($drop as $id) { if (get_post_meta($id, self::META, true) === '1') { wp_delete_post($id, true); $n['events']++; } }
        // Künstler und Tipps: nur gekennzeichnete; Künstler bleiben, solange ein Demo-Event stehen bleibt (Line-up)
        $keep_artists = $kept ? array_map('intval', (array) ($r['artists'] ?? [])) : [];
        foreach (array_diff(array_map('intval', (array) ($r['artists'] ?? [])), $keep_artists) as $a) if (get_post_meta($a, self::META, true) === '1') { wp_delete_post($a, true); $n['artists']++; }
        foreach (array_map('intval', (array) ($r['tips'] ?? [])) as $tp) if (get_post_meta($tp, self::META, true) === '1') { wp_delete_post($tp, true); $n['tips']++; }
        if (class_exists('TIX_App_Tips') && method_exists('TIX_App_Tips', 'flush')) TIX_App_Tips::flush();
        // Gutscheine: nur die angelegten Codes, solange sie noch Demo sind
        wp_cache_delete('tix_coupons', 'options');
        $cp = get_option('tix_coupons', []);
        if (is_array($cp)) {
            foreach ((array) ($r['giftcards'] ?? []) as $code) if (isset($cp[$code]) && !empty($cp[$code]['demo'])) { unset($cp[$code]); $n['giftcards']++; }
            update_option('tix_coupons', $cp);
        }
        if (class_exists('TIX_Notifications') && !empty($r['notifications'])) {
            $ids = (array) $r['notifications'];
            $feed = (array) get_option(TIX_Notifications::OPT_FEED, []);
            $left = array_values(array_filter($feed, function ($it) use ($ids) { return !(is_array($it) && in_array((string) ($it['id'] ?? ''), $ids, true)); }));
            $n['notifications'] = count($feed) - count($left);
            update_option(TIX_Notifications::OPT_FEED, $left, false);
        }
        if ($kept) update_option(self::OPT, ['profile' => $r['profile'] ?? null, 'seeded_at' => $r['seeded_at'] ?? null, 'events' => $keep_fp, 'orders' => $keep_orders, 'artists' => $keep_artists, 'tips' => [], 'giftcards' => [], 'notifications' => []], false);
        else delete_option(self::OPT);
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
        return ['ok' => true, 'deleted' => $n, 'kept' => $kept, 'present' => self::present()];
    }

    // ═══════════════ Live-Schaltung ═══════════════

    /** Punkt für die Checkliste von /studio/readiness (nie Pflicht) */
    public static function readiness_item() {
        $p = self::present();
        return ['key' => 'demo_data', 'ok' => !$p, 'state' => $p ? 'present' : 'ok', 'required' => false, 'overridable' => true,
            'label' => $p ? 'Noch Beispieldaten im Shop' : 'Keine Beispieldaten', 'hint' => $p ? 'Vor dem Start löschen? Studio → Übersicht → „Demodaten löschen“.' : null, 'link' => '/'];
    }

    /** go-live: 409, solange Demodaten da sind und nicht bewusst behalten werden */
    public static function go_live_check(WP_REST_Request $req) {
        if (!self::present() || rest_sanitize_boolean($req->get_param('keep_demo_data'))) return null;
        return new WP_Error('tix_demo_data_present', 'Es sind noch Beispieldaten im Shop. Erst Demodaten löschen?', ['status' => 409] + self::status_counts());
    }

    // ═══════════════ REST / CLI ═══════════════

    public static function routes() {
        $perm = function (WP_REST_Request $req) {
            if (!self::available()) return new WP_Error('rest_no_route', 'Nicht gefunden.', ['status' => 404]);
            return class_exists('TIX_Studio') ? TIX_Studio::perm_brand($req) : current_user_can('manage_options');
        };
        register_rest_route(self::NS, '/studio/demo-data/status', ['methods' => 'GET', 'callback' => function () { return self::out(self::status()); }, 'permission_callback' => $perm]);
        register_rest_route(self::NS, '/studio/demo-data/delete', ['methods' => 'POST', 'callback' => function (WP_REST_Request $req) { return self::out(self::delete(rest_sanitize_boolean($req->get_param('include_edited')))); }, 'permission_callback' => $perm]);
        register_rest_route(self::NS, '/studio/demo-data/seed', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_seed'], 'permission_callback' => $perm]);
    }

    public static function rest_seed(WP_REST_Request $req) {
        if (self::present()) return new WP_Error('tix_demo_seed_exists', 'Es sind schon Beispieldaten da.', ['status' => 409]);
        $r = self::seed((string) $req->get_param('profile'));
        if (empty($r['ok'])) return new WP_Error($r['error'] ?? 'tix_demo_seed_failed', $r['message'] ?? 'Hat nicht geklappt.', ['status' => ($r['error'] ?? '') === 'tix_invalid_field' ? 400 : 409] + (isset($r['field']) ? ['field' => $r['field']] : []));
        return self::out($r, 201);
    }

    private static function out($d, $status = 200) {
        $r = new WP_REST_Response($d, $status);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /**
     * WP-CLI: wp tixomat demo-data seed --profil=festival|club [--bestellungen=40] | status | delete [--auch-bearbeitete]
     * → eine JSON-Zeile.
     */
    public static function cli($args, $assoc) {
        $a = (string) ($args[0] ?? 'status');
        if (!self::available()) $out = ['ok' => false, 'error' => 'tix_demo_seed_unavailable', 'message' => 'Demodaten gibt es nur in Kunden-Shops.'];
        elseif ($a === 'seed') $out = self::seed((string) ($assoc['profil'] ?? $assoc['profile'] ?? ''), max(0, min(200, intval($assoc['bestellungen'] ?? self::ORDERS))));
        elseif ($a === 'delete') $out = self::delete(!empty($assoc['auch-bearbeitete']));
        elseif ($a === 'status') $out = self::status();
        else $out = ['ok' => false, 'error' => 'tix_invalid_field', 'message' => 'seed | status | delete'];
        WP_CLI::line(wp_json_encode($out));
        if (empty($out['ok'])) WP_CLI::halt(1);
    }
}
TIX_Demo_Seed::init();
