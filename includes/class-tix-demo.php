<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Demo – Demo-Veranstalter „Demo Club“ für den Vertrieb (1.38.508, nur Mehr-Veranstalter-Modus).
 *
 *  - Reset: nächtlich 04:00 (Cron tix_demo_reset), WP-CLI `wp tixomat demo reset [--orders=<n>]`, POST /platform/demo/reset (Admin).
 *    Löscht alles von Demo Club (auch Änderungen der Vertriebler) und legt Beispieldaten neu an: Events (heute „Live-Demo-Abend“
 *    mit 0-€-Demo-Ticket, +7/+14/+30 Tage, ein vergangenes mit Abrechnung), Bestellungen (@demo.invalid), Tickets teils
 *    eingecheckt, Gästelisten, Gutscheine, Kundenanfragen mit fertiger ai_summary. Keine Mails, keine KI, keine echten Nummern.
 *  - GET /public/demo → Veranstalter + Events (Listenformat); POST /public/demo-ticket → Demo-Ticket ohne Konto/Zahlung;
 *    GET /public/demo-ticket/{code}?key= → Stand (Live-Anzeige).
 */
class TIX_Demo {

    const NS = 'tixomat/v1';
    const CRON = 'tix_demo_reset';
    const OPT_TICKET_EVENT = 'tix_demo_ticket_event';
    const DEMO_CAT = 'Demo-Ticket';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_action('init', function () {
            if (!TIX_Sales::multi()) return;
            if (!wp_next_scheduled(TIX_Demo::CRON)) wp_schedule_event(TIX_Demo::next_reset_ts(), 'daily', TIX_Demo::CRON);
        }, 30);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat demo', [__CLASS__, 'cli']);
    }

    /** Nächster Reset (04:00 Ortszeit) als Unix-Zeit. */
    public static function next_reset_ts() {
        $tz = wp_timezone();
        $d = new DateTime('today 04:00', $tz);
        if ($d->getTimestamp() <= time()) $d->modify('+1 day');
        return $d->getTimestamp();
    }

    public static function cron() {
        if (TIX_Sales::multi()) self::reset();
    }

    /**
     * WP-CLI: wp tixomat demo reset [--orders=<n>]
     */
    public static function cli($args, $assoc) {
        if (($args[0] ?? '') !== 'reset') { WP_CLI::error('Aufruf: wp tixomat demo reset [--orders=<n>]'); return; }
        $r = self::reset(['orders' => intval($assoc['orders'] ?? 120)]);
        WP_CLI::success('Demo Club zurückgesetzt: ' . wp_json_encode($r));
    }

    // ═══════════════ Veranstalter ═══════════════

    public static function ensure_organizer() {
        $oid = TIX_Sales::demo_id();
        if ($oid && get_post_type($oid) === 'tix_organizer') {
            if (get_post_status($oid) !== 'publish') wp_update_post(['ID' => $oid, 'post_status' => 'publish']);
            return $oid;
        }
        $oid = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'Demo Club', 'post_name' => 'demo-club',
            'post_content' => 'Der Demo Club zeigt, wie Veranstalter mit evendis arbeiten: Tickets, Einlass, Gästeliste, Statistik und Abrechnung.']);
        if (!$oid || is_wp_error($oid)) return 0;
        update_option(TIX_Sales::OPT_DEMO, intval($oid), false);
        foreach ([TIX_Sales::META_DEMO => 1, '_tix_org_approval' => 'approved', '_tix_org_landing_slug' => 'demo-club', '_tix_org_city' => 'Köln',
            '_tix_org_phone' => '+49 221 000000', '_tix_org_modules' => wp_json_encode(['tickets' => true, 'music' => true, 'loyalty' => true, 'giftcards' => true, 'support' => true, 'muttizettel' => true, 'wallet' => false])] as $k => $v) {
            update_post_meta($oid, $k, $v);
        }
        TIX_Sales::flush();
        // vorhandene Vertriebler zuordnen
        foreach (get_users(['role' => TIX_Sales::ROLE, 'fields' => 'ID']) as $uid) update_user_meta(intval($uid), '_tix_team_organizer_id', intval($oid));
        return intval($oid);
    }

    // ═══════════════ Reset ═══════════════

    /** Alles von Demo Club löschen. → Zähler */
    public static function wipe($oid) {
        global $wpdb;
        $n = ['events' => 0, 'orders' => 0, 'tickets' => 0, 'support' => 0, 'giftcards' => 0, 'settlements' => 0];
        $events = get_posts(['post_type' => 'event', 'post_status' => array_values(get_post_stati()), 'posts_per_page' => -1, 'fields' => 'ids', 'tix_include_system' => 1, 'tix_include_sandbox' => 1,
            'meta_query' => [['key' => '_tix_organizer_id', 'value' => (string) $oid]]]);
        $order_ids = [];
        if ($events) {
            $in = implode(',', array_map('intval', $events));
            $order_ids = array_map('intval', (array) $wpdb->get_col("SELECT DISTINCT order_id FROM {$wpdb->prefix}tix_order_items WHERE event_id IN ($in)"));
            foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => array_values(get_post_stati()), 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => [['key' => '_tix_ticket_event_id', 'value' => array_map('strval', $events), 'compare' => 'IN']]]) as $tid) {
                wp_delete_post($tid, true);
                $n['tickets']++;
            }
            if (class_exists('TIX_Ticket_DB') && TIX_Ticket_DB::table_exists()) $wpdb->query("DELETE FROM {$wpdb->prefix}" . TIX_Ticket_DB::TABLE_SUFFIX . " WHERE event_id IN ($in)");
        }
        $order_ids = array_values(array_unique(array_merge($order_ids, array_map('intval', (array) $wpdb->get_col("SELECT id FROM {$wpdb->prefix}tix_orders WHERE payment_method = 'demo'")))));
        if ($order_ids) {
            $oin = implode(',', $order_ids);
            $wpdb->query("DELETE FROM {$wpdb->prefix}tix_order_items WHERE order_id IN ($oin)");
            $wpdb->query("DELETE FROM {$wpdb->prefix}tix_orders WHERE id IN ($oin)");
            // Bestell-Metadaten (liegen in postmeta unter der Bestell-ID) nur löschen, wo es keinen Beitrag mit dieser ID gibt
            $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.post_id IN ($oin) AND p.ID IS NULL");
            foreach ($order_ids as $o) { delete_option('_tix_order_coupon_' . $o); delete_option('_tix_coupon_incremented_' . $o); delete_option('_tix_order_source_' . $o); }
            $n['orders'] = count($order_ids);
        }
        foreach ($events as $eid) { wp_delete_post($eid, true); $n['events']++; }
        foreach (get_posts(['post_type' => 'tix_support_ticket', 'post_status' => array_values(get_post_stati()), 'posts_per_page' => -1, 'fields' => 'ids',
            'meta_query' => ['relation' => 'OR', ['key' => '_tix_sp_organizer_id', 'value' => (string) $oid], ['key' => '_tix_demo', 'compare' => 'EXISTS']]]) as $sid) {
            wp_delete_post($sid, true);
            $n['support']++;
        }
        wp_cache_delete('tix_coupons', 'options');
        $cp = get_option('tix_coupons', []);
        if (is_array($cp)) {
            foreach ($cp as $k => $c) if (is_array($c) && (intval($c['organizer_id'] ?? 0) === intval($oid) || !empty($c['demo']))) { unset($cp[$k]); $n['giftcards']++; }
            if ($n['giftcards']) update_option('tix_coupons', $cp);
        }
        if (class_exists('TIX_Settlement') && $wpdb->get_var("SHOW TABLES LIKE '" . TIX_Settlement::table() . "'") === TIX_Settlement::table()) {
            $sids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM " . TIX_Settlement::table() . " WHERE organizer_id = %d", $oid)));
            if ($sids) {
                $sin = implode(',', $sids);
                $wpdb->query("DELETE FROM " . TIX_Settlement::items_table() . " WHERE settlement_id IN ($sin)");
                $wpdb->query("DELETE FROM " . TIX_Settlement::table() . " WHERE id IN ($sin)");
                $n['settlements'] = count($sids);
            }
        }
        return $n;
    }

    /** Demo Club zurücksetzen. $opt: orders (Anzahl Beispiel-Bestellungen, Vorgabe 120). → {organizer_id, wiped, seeded} */
    public static function reset(array $opt = []) {
        if (!TIX_Sales::multi()) return ['error' => 'Nur im Mehr-Veranstalter-Modus.'];
        $oid = self::ensure_organizer();
        if (!$oid) return ['error' => 'Demo Club konnte nicht angelegt werden.'];
        // Während des Resets keine Mails (auch nicht an das Team), keine Verteilung
        add_filter('pre_wp_mail', '__return_true', 1);
        $wiped = self::wipe($oid);
        $seeded = self::seed($oid, max(0, min(400, intval($opt['orders'] ?? 120))));
        remove_filter('pre_wp_mail', '__return_true', 1);
        update_option('tix_demo_reset_at', current_time('mysql'), false);
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
        return ['organizer_id' => $oid, 'wiped' => $wiped, 'seeded' => $seeded];
    }

    // ═══════════════ Beispieldaten ═══════════════

    const FIRST = ['Lena', 'Max', 'Sophie', 'Jonas', 'Mia', 'Paul', 'Emma', 'Ben', 'Hannah', 'Luca', 'Lea', 'Finn', 'Clara', 'Noah', 'Marie', 'Elias', 'Anna', 'Leon', 'Laura', 'Tim'];
    const LAST = ['Becker', 'Schmidt', 'Wagner', 'Hoffmann', 'Koch', 'Richter', 'Klein', 'Wolf', 'Neumann', 'Schwarz', 'Braun', 'Zimmermann', 'Krüger', 'Hartmann', 'Lange'];

    private static function day($offset) {
        return wp_date('Y-m-d', time() + intval($offset) * DAY_IN_SECONDS);
    }

    private static function make_event($oid, $title, $offset, $time, array $cats, array $extra = []) {
        $eid = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => 0], true);
        if (is_wp_error($eid) || !$eid) return 0;
        $meta = array_merge([
            '_tix_organizer_id' => $oid, '_tix_date_start' => self::day($offset), '_tix_time_start' => $time, '_tix_time_doors' => sprintf('%02d:00', max(0, intval($time) - 1)),
            '_tix_location' => 'Demo Club, Musterstraße 1, 50667 Köln', '_tix_tickets_enabled' => '1', '_tix_ticket_categories' => $cats,
            '_tix_info_description' => '<p>Beispiel-Event im Demo Club. Alle Daten sind erfunden und werden jede Nacht zurückgesetzt.</p>',
            '_tix_demo' => 1, '_tix_guest_list_enabled' => '1',
        ], $extra);
        foreach ($meta as $k => $v) update_post_meta($eid, $k, $v);
        return intval($eid);
    }

    private static function seed($oid, $orders) {
        global $wpdb;
        mt_srand(intval(wp_date('Ymd')));
        $cat = function ($name, $price, $qty, $extra = []) { return array_merge(['name' => $name, 'price' => $price, 'qty' => $qty, 'online' => '1', 'desc' => ''], $extra); };
        $ev = [];
        $ev['live'] = self::make_event($oid, 'Live-Demo-Abend', 0, '19:00', [$cat(self::DEMO_CAT, 0, 10000, ['desc' => 'Kostenlos – zum Ausprobieren des Einlasses']), $cat('Normal', 12, 300)], ['_tix_info_lineup' => 'DJ Demo<br>Live-Band „Die Beispiele“']);
        $ev['w1'] = self::make_event($oid, 'Neon Night', 7, '22:00', [$cat('Early Bird', 9, 100), $cat('Normal', 14, 400), $cat('VIP', 39, 40)], ['_tix_info_lineup' => 'DJ Lumen<br>Night Shift Crew']);
        $ev['w2'] = self::make_event($oid, 'Schlager Sause', 14, '20:00', [$cat('Vorverkauf', 13.5, 500), $cat('Abendkasse', 18.5, 200)], ['_tix_info_lineup' => 'Die Demo-Stimmungsband']);
        $ev['m1'] = self::make_event($oid, 'Open Air Sommerfest', 30, '15:00', [$cat('Tagesticket', 29, 1500), $cat('Familie (2+2)', 69, 200), $cat('Kinder', 9, 300)], ['_tix_info_lineup' => 'Headliner: The Examples<br>Support: Placeholder']);
        $ev['past'] = self::make_event($oid, 'Herbst-Opening', -10, '21:00', [$cat('Normal', 12, 300), $cat('VIP', 30, 30)]);
        update_option(self::OPT_TICKET_EVENT, $ev['live'], false);
        // Bestellungen (über die normale Bestell-Logik, Zahlart demo, Adressen @demo.invalid)
        $weights = [['live', 1, 12], ['w1', 0, 9], ['w1', 1, 14], ['w1', 2, 39], ['w2', 0, 13.5], ['m1', 0, 29], ['m1', 1, 69], ['past', 0, 12], ['past', 1, 30]];
        $made = 0; $tickets = 0;
        for ($i = 0; $i < $orders; $i++) {
            $w = $weights[mt_rand(0, count($weights) - 1)];
            $eid = $ev[$w[0]];
            $cats = (array) get_post_meta($eid, '_tix_ticket_categories', true);
            $qty = mt_rand(1, 4);
            $fn = self::FIRST[mt_rand(0, count(self::FIRST) - 1)]; $ln = self::LAST[mt_rand(0, count(self::LAST) - 1)];
            $email = strtolower(remove_accents($fn . '.' . $ln)) . $i . '@demo.invalid';
            $order_id = TIX_Native_Checkout::create_order(['billing_first_name' => $fn, 'billing_last_name' => $ln, 'billing_email' => $email, 'payment_method' => 'demo', 'total' => $w[2] * $qty,
                'items' => [['event_id' => $eid, 'cat_index' => $w[1], 'name' => (string) ($cats[$w[1]]['name'] ?? 'Ticket'), 'event_title' => get_the_title($eid), 'price' => $w[2], 'qty' => $qty, 'meta' => []]]]);
            if (!$order_id || is_wp_error($order_id)) continue;
            TIX_Native_Checkout::update_order_status($order_id, 'completed', 'demo');
            $ago = $w[0] === 'past' ? mt_rand(11, 40) : mt_rand(0, 28);
            $wpdb->update($wpdb->prefix . 'tix_orders', ['date_created' => wp_date('Y-m-d H:i:s', time() - $ago * DAY_IN_SECONDS - mt_rand(0, 80000))], ['id' => $order_id]);
            $made++;
            $tickets += $qty;
        }
        // Einlass: vergangenes Event fast ganz, Live-Abend teilweise eingecheckt
        foreach (['past' => 92, 'live' => 35] as $k => $pct) {
            foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => [['key' => '_tix_ticket_event_id', 'value' => (string) $ev[$k]]]]) as $tid) {
                if (mt_rand(1, 100) <= $pct && class_exists('TIX_Tickets') && method_exists('TIX_Tickets', 'checkin_ticket')) TIX_Tickets::checkin_ticket($tid, 'demo');
            }
        }
        // Gästelisten
        foreach (['live' => 12, 'w1' => 8] as $k => $count) {
            $g = [];
            for ($i = 0; $i < $count; $i++) {
                $in = $k === 'live' && $i < 4;
                $g[] = ['id' => wp_generate_uuid4(), 'name' => self::FIRST[($i * 3) % count(self::FIRST)] . ' ' . self::LAST[($i * 5) % count(self::LAST)], 'email' => '', 'plus' => $i % 3,
                    'note' => $i % 4 === 0 ? 'Presse' : '', 'checked_in' => $in, 'checked_in_count' => $in ? 1 + ($i % 3) : 0, 'checkin_time' => $in ? current_time('mysql') : '',
                    'checkin_by' => $in ? 'demo' : '', 'added_by' => 'demo', 'added_at' => current_time('mysql')];
            }
            update_post_meta($ev[$k], '_tix_guest_list', $g);
        }
        // Gutscheine
        wp_cache_delete('tix_coupons', 'options');
        $cp = get_option('tix_coupons', []);
        $cp = is_array($cp) ? $cp : [];
        foreach ([[50, 50], [25, 12.5], [100, 0]] as $j => $gv) {
            $code = 'GS-DEMO-' . strtoupper(wp_generate_password(4, false, false));
            $cp[$code] = ['discount_type' => 'giftcard', 'value' => $gv[0], 'balance' => $gv[1], 'expires' => self::day(3 * 365), 'organizer_id' => $oid, 'ticket_id' => 0, 'order_id' => 0,
                'redemptions' => $gv[1] < $gv[0] ? [['ts' => current_time('mysql'), 'amount' => $gv[0] - $gv[1], 'channel' => 'bar', 'ref' => 'demo', 'rest' => $gv[1]]] : [],
                'created' => current_time('mysql'), 'description' => 'Demo-Gutschein', 'max_uses' => 0, 'used' => 0, 'demo' => 1,
                'gift' => ['recipient_name' => self::FIRST[$j + 5], 'from_name' => self::FIRST[$j + 9], 'message' => 'Viel Spaß im Demo Club!']];
        }
        update_option('tix_coupons', $cp);
        // Kundenanfragen mit fertiger Zusammenfassung (keine KI beim Seed)
        $sp = [
            ['Tickets nicht angekommen', 'tickets', 'Hallo, ich habe gestern zwei Tickets für die Neon Night gekauft, aber keine Mail bekommen.', 'Kunde hat Tickets für Neon Night gekauft, Bestätigungs-Mail fehlt.'],
            ['Umtausch auf VIP möglich?', 'other', 'Kann ich mein Normal-Ticket noch auf VIP upgraden?', 'Kunde möchte Normal-Ticket auf VIP umtauschen.'],
            ['Rollstuhlplatz', 'other', 'Gibt es beim Sommerfest Plätze für Rollstuhlfahrer?', 'Frage nach Rollstuhlplätzen beim Open Air Sommerfest.'],
            ['Rechnung benötigt', 'payment', 'Ich brauche eine Rechnung mit Firmenadresse für meine Bestellung.', 'Kunde benötigt Rechnung mit Firmenadresse.'],
            ['Name auf Ticket ändern', 'tickets', 'Mein Freund kommt statt mir, kann der Name geändert werden?', 'Kunde möchte Ticket auf anderen Namen umschreiben.'],
            ['Einlass ab wann?', 'other', 'Ab wann ist Einlass beim Live-Demo-Abend?', 'Frage nach Einlasszeit beim Live-Demo-Abend.'],
        ];
        $n_sp = 0;
        if (class_exists('TIX_Support') && method_exists('TIX_Support', 'app_create')) {
            foreach ($sp as $j => $s) {
                $fn = self::FIRST[$j + 2];
                $tid = TIX_Support::app_create(['email' => strtolower($fn) . '.anfrage' . $j . '@demo.invalid', 'name' => $fn . ' ' . self::LAST[$j], 'subject' => $s[0], 'category' => $s[1], 'content' => $s[2], 'user_id' => 0, 'source' => 'demo']);
                if (is_wp_error($tid) || !$tid) continue;
                update_post_meta($tid, '_tix_sp_organizer_id', $oid);
                update_post_meta($tid, '_tix_sp_event_id', $j === 2 ? $ev['m1'] : ($j === 5 ? $ev['live'] : $ev['w1']));
                update_post_meta($tid, '_tix_sp_ai_summary', ['hash' => 'demo', 'text' => $s[3], 'date' => current_time('c')]);
                update_post_meta($tid, '_tix_demo', 1);
                $n_sp++;
            }
        }
        // Abrechnung für das vergangene Event (ohne echte Nummern, kein Rechnungs-Nummernkreis)
        $n_set = 0;
        if (class_exists('TIX_Settlement') && $wpdb->get_var("SHOW TABLES LIKE '" . TIX_Settlement::table() . "'") === TIX_Settlement::table()) {
            $gross = floatval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(i.total),0) FROM {$wpdb->prefix}tix_order_items i JOIN {$wpdb->prefix}tix_orders o ON o.id = i.order_id WHERE i.event_id = %d AND o.status = 'completed'", $ev['past'])));
            $cnt = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT order_id) FROM {$wpdb->prefix}tix_order_items WHERE event_id = %d", $ev['past'])));
            $fee = round($gross * 0.025 + $cnt * 0.59, 2);
            $wpdb->insert(TIX_Settlement::table(), ['number' => 'DEMO-' . wp_date('ymd'), 'type' => 'event', 'organizer_id' => $oid, 'event_id' => $ev['past'], 'event_title' => 'Herbst-Opening',
                'event_date' => self::day(-10), 'status' => 'paid', 'gross' => $gross, 'platform_fee' => $fee, 'payout_amount' => round($gross - $fee, 2), 'orders' => $cnt,
                'tickets' => intval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(quantity),0) FROM {$wpdb->prefix}tix_order_items WHERE event_id = %d", $ev['past']))),
                'due_date' => self::day(-3), 'invoice_number' => 'DEMO-EVR-' . wp_date('ymd'), 'invoice_total' => $fee, 'paid_at' => current_time('mysql', true), 'created' => current_time('mysql', true), 'updated' => current_time('mysql', true)]);
            $n_set = $wpdb->insert_id ? 1 : 0;
        }
        return ['events' => count($ev), 'orders' => $made, 'tickets' => $tickets, 'giftcards' => 3, 'support' => $n_sp, 'settlements' => $n_set];
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $multi = function () { return TIX_Sales::multi() ? true : new WP_Error('rest_no_route', 'Nicht gefunden.', ['status' => 404]); };
        register_rest_route(self::NS, '/public/demo', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_public'], 'permission_callback' => $multi]);
        register_rest_route(self::NS, '/public/demo-ticket', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_ticket'], 'permission_callback' => $multi]);
        register_rest_route(self::NS, '/public/demo-ticket/(?P<code>[A-Za-z0-9-]{3,40})', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_ticket_status'], 'permission_callback' => $multi]);
        register_rest_route(self::NS, '/platform/demo/reset', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_reset'], 'permission_callback' => function () {
            if (!TIX_Sales::multi()) return new WP_Error('rest_no_route', 'Nicht gefunden.', ['status' => 404]);
            return current_user_can('manage_options') ? true : new WP_Error('rest_forbidden', 'Nur für Plattform-Admins.', ['status' => 403]);
        }]);
    }

    private static function out($d, $status = 200) {
        $r = new WP_REST_Response($d, $status);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    public static function rest_reset(WP_REST_Request $req) {
        $n = $req->get_param('orders');
        $r = self::reset($n !== null ? ['orders' => intval($n)] : []);
        if (isset($r['error'])) return new WP_Error('tix_demo_failed', $r['error'], ['status' => 500]);
        return self::out(['ok' => true, 'demo' => true] + $r);
    }

    private static function demo_events() {
        $oid = TIX_Sales::demo_id();
        if (!$oid) return [];
        return get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 20, 'fields' => 'ids', 'tix_include_sandbox' => 1,
            'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'order' => 'ASC',
            'meta_query' => [['key' => '_tix_organizer_id', 'value' => (string) $oid], ['key' => '_tix_date_start', 'value' => wp_date('Y-m-d'), 'compare' => '>=', 'type' => 'DATE']]]);
    }

    /** GET /public/demo */
    public static function rest_public(WP_REST_Request $req) {
        $oid = TIX_Sales::demo_id();
        if (!$oid) return new WP_Error('tix_demo_missing', 'Der Demo Club ist noch nicht eingerichtet.', ['status' => 404]);
        $events = [];
        foreach (self::demo_events() as $eid) {
            $p = class_exists('TIX_Public_Events') ? TIX_Public_Events::payload($eid) : null;
            if ($p) $events[] = $p + ['demo' => true];
        }
        $org = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::organizer_payload($oid, true) : ['id' => $oid, 'name' => get_the_title($oid)];
        if (is_array($org)) { $org['demo'] = true; $org['upcoming_count'] = count($events); }
        return self::out(['ok' => true, 'demo' => true, 'organizer' => $org, 'events' => $events,
            'demo_ticket_event_id' => intval(get_option(self::OPT_TICKET_EVENT, 0)) ?: null, 'reset_at' => wp_date('c', self::next_reset_ts())]);
    }

    private static function limited($bucket, $max, $window) {
        return class_exists('TIX_App_Account') && TIX_App_Account::rate_limited($bucket, $max, $window, 'ip');
    }

    /** Ticket-Antwort (Vertrag C). */
    private static function ticket_payload($tid, $order_key) {
        $code = (string) get_post_meta($tid, '_tix_ticket_code', true);
        $eid = intval(get_post_meta($tid, '_tix_ticket_event_id', true));
        $qr = 'GL-' . $eid . '-' . $code;
        $base = class_exists('TIX_Customer_URL') ? (TIX_Customer_URL::base() ?: home_url('/')) : home_url('/');
        return [
            'id' => intval($tid), 'code' => $code, 'qr' => $qr,
            'qr_image' => class_exists('TIX_QR') ? TIX_QR::url($qr, 480) : '',
            'name' => (string) get_post_meta($tid, '_tix_ticket_owner_name', true),
            'category' => (string) get_post_meta($tid, '_tix_ticket_cat_name', true), 'price' => 0,
            'event' => ['id' => $eid, 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'), 'date_start' => (string) get_post_meta($eid, '_tix_date_start', true),
                'time_start' => (string) get_post_meta($eid, '_tix_time_start', true), 'location' => (string) get_post_meta($eid, '_tix_location', true)],
            'checked_in' => (bool) get_post_meta($tid, '_tix_ticket_checked_in', true),
            '_view_url' => trailingslashit($base) . 'demo-ticket/' . rawurlencode($code) . '/?key=' . rawurlencode($order_key),
        ];
    }

    /** POST /public/demo-ticket {name?, email?, event_id?} */
    public static function rest_ticket(WP_REST_Request $req) {
        $oid = TIX_Sales::demo_id();
        $eid = absint($req->get_param('event_id')) ?: intval(get_option(self::OPT_TICKET_EVENT, 0));
        if (!$oid || !$eid || get_post_type($eid) !== 'event' || intval(get_post_meta($eid, '_tix_organizer_id', true)) !== $oid || get_post_status($eid) !== 'publish') {
            return new WP_Error('tix_demo_event', 'Dieses Event gibt es im Demo Club nicht.', ['status' => 404]);
        }
        $cats = (array) get_post_meta($eid, '_tix_ticket_categories', true);
        $ci = null;
        foreach ($cats as $i => $c) if (is_array($c) && floatval($c['price'] ?? 1) <= 0) { $ci = intval($i); break; }
        if ($ci === null) return new WP_Error('tix_demo_event', 'Für dieses Demo-Event gibt es kein kostenloses Demo-Ticket.', ['status' => 404]);
        $email = sanitize_email((string) $req->get_param('email'));
        if ($email !== '' && !is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail angeben.', ['status' => 400, 'field' => 'email']);
        $name = mb_substr(trim(sanitize_text_field((string) $req->get_param('name'))), 0, 60) ?: 'Demo-Gast';
        if (self::limited('demo_ticket', 5, 600) || self::limited('demo_ticket_day', 300, DAY_IN_SECONDS)) return new WP_Error('tix_rate_limit', 'Zu viele Demo-Tickets – bitte kurz warten.', ['status' => 429]);
        $parts = preg_split('/\s+/', $name, 2);
        $order_id = TIX_Native_Checkout::create_order(['billing_first_name' => $parts[0], 'billing_last_name' => $parts[1] ?? '', 'billing_email' => $email !== '' ? $email : 'gast-' . strtolower(wp_generate_password(8, false, false)) . '@demo.invalid',
            'payment_method' => 'demo', 'total' => 0, 'items' => [['event_id' => $eid, 'cat_index' => $ci, 'name' => (string) ($cats[$ci]['name'] ?? self::DEMO_CAT), 'event_title' => get_the_title($eid), 'price' => 0, 'qty' => 1, 'meta' => []]]]);
        if (!$order_id || is_wp_error($order_id)) return new WP_Error('tix_demo_failed', 'Das Demo-Ticket konnte nicht erstellt werden.', ['status' => 500]);
        TIX_Native_Checkout::update_order_status($order_id, 'completed', 'demo');
        global $wpdb;
        $key = (string) $wpdb->get_var($wpdb->prepare("SELECT order_key FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id));
        $t = class_exists('TIX_Tickets') ? TIX_Tickets::get_all_tickets_for_order($order_id) : [];
        if (!$t) return new WP_Error('tix_demo_failed', 'Das Demo-Ticket konnte nicht erstellt werden.', ['status' => 500]);
        if (!get_post_meta($t[0]['id'], '_tix_ticket_owner_name', true)) update_post_meta($t[0]['id'], '_tix_ticket_owner_name', $name);
        $p = self::ticket_payload(intval($t[0]['id']), $key);
        $view = $p['_view_url']; unset($p['_view_url']);
        return self::out(['ok' => true, 'demo' => true, 'ticket' => $p, 'view_url' => $view, 'expires_at' => wp_date('c', self::next_reset_ts())], 201);
    }

    /** GET /public/demo-ticket/{code}?key= */
    public static function rest_ticket_status(WP_REST_Request $req) {
        global $wpdb;
        if (self::limited('demo_ticket_get', 120, 600)) return new WP_Error('tix_rate_limit', 'Zu viele Abfragen.', ['status' => 429]);
        $code = strtoupper(sanitize_text_field((string) $req['code']));
        $t = class_exists('TIX_Tickets') ? TIX_Tickets::get_ticket_by_code($code) : null;
        $tid = is_object($t) ? intval($t->ID ?? 0) : (is_array($t) ? intval($t['ID'] ?? ($t['id'] ?? 0)) : intval($t));
        $order_id = $tid ? intval(get_post_meta($tid, '_tix_ticket_order_id', true)) : 0;
        $row = $order_id ? $wpdb->get_row($wpdb->prepare("SELECT order_key, payment_method FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id)) : null;
        $key = (string) $req->get_param('key');
        if (!$row || $row->payment_method !== 'demo' || $key === '' || !hash_equals((string) $row->order_key, $key)) return new WP_Error('tix_demo_ticket', 'Demo-Ticket nicht gefunden.', ['status' => 404]);
        $p = self::ticket_payload($tid, $key);
        $view = $p['_view_url']; unset($p['_view_url']);
        return self::out(['ok' => true, 'demo' => true, 'ticket' => $p, 'view_url' => $view, 'expires_at' => wp_date('c', self::next_reset_ts())]);
    }
}

TIX_Demo::init();
