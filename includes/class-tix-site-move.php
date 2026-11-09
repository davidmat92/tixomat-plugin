<?php
if (!defined('ABSPATH')) exit;

/**
 * Umzug eines Provisionskunden / Co-Veranstalters von der Plattform auf eine selbstgehostete Seite (1.38.702).
 * Vertrag: uebergaben/vertrag-umzug-selbstgehostet.md, Doku §112.
 *
 * Plattform (Mehr-Veranstalter-Modus):
 *   GET|POST|DELETE /platform/customers/{key}/move, POST …/move/switch   (Plattform-Admin)
 *   GET /move/export?part=&page=, POST /move/report                      (Header X-Tix-Move-Token)
 * Neue Seite (Einzel-Seite, WP-CLI):
 *   wp tixomat umzug importieren --von=<plattform-api> --schluessel=<s> [--trocken]
 *   wp tixomat umzug status
 *
 * Schritt 2 (1.38.703): Co-Events des Kunden (Ersteller = andere Veranstalter auf der Plattform) auf der neuen Seite.
 *   Plattform: GET /partner/co-events, POST /partner/co-events/{id}/call   (Header X-Tix-Syndication-Key = Partner-Schlüssel)
 *   Neue Seite: Anzeige-Spiegel (Meta _tix_platform_co), stündlicher Abgleich, GET /platform-co/events,
 *               GET|POST|DELETE /platform-co/{id}/{route} (Durchgriff auf die Plattform), wp tixomat umzug co-events
 */
class TIX_Site_Move {

    const NS        = 'tixomat/v1';
    const OPT       = 'tix_site_move_';      // + organizer_id (Plattform)
    const OPT_IMPORT = 'tix_site_move_import'; // neue Seite
    const OPT_MAP   = 'tix_site_move_map';     // neue Seite
    const META_FROM = '_tix_moved_from';
    const PAGE      = 100;
    const TTL       = 14 * DAY_IN_SECONDS;
    const PARTS     = ['organizer', 'locations', 'artists', 'events', 'orders', 'tickets', 'newsletter', 'coupons'];
    const NOT_MOVED = ['Guthaben/Geldbörsen', 'Prämienpunkte', 'Abrechnungen und Auszahlungen', 'Gebührenrechnungen', 'Kunden-Konten (Login per Code auf der neuen Seite)', 'Support-Anfragen', 'Promoter'];

    /** Plattform-interne Meta, die nicht mitzieht. */
    const SKIP_META_RE = '#^_tix_(settled|settle_|syndicat|source_|partner_|moved_|co_|organizer_id$|location_id$|org_user_id$|org_kind$|org_approval|org_terms|org_payout|org_iban|org_bank|org_fee|org_team|publish_held|edit_lock|thumbnail_id$)#';

    const CO_META   = '_tix_platform_co';
    const CO_CRON   = 'tix_platform_co_sync';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action(self::CO_CRON, [__CLASS__, 'sync_co_events']);
        add_action('init', function () {
            if (self::multi() || !self::co_ready()) return;
            if (!wp_next_scheduled(self::CO_CRON)) wp_schedule_event(time() + 300, 'hourly', self::CO_CRON);
        }, 40);
        add_filter('rest_request_before_callbacks', [__CLASS__, 'guard_co_mirror'], 10, 3);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat umzug', [__CLASS__, 'cli']);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function routes() {
        if (!self::multi()) {
            $o = ['permission_callback' => ['TIX_REST_API', 'check_organizer']];
            register_rest_route(self::NS, '/platform-co/events', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_co_list']] + $o);
            register_rest_route(self::NS, '/platform-co/sync', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_co_sync']] + $o);
            register_rest_route(self::NS, '/platform-co/(?P<id>\d+)/(?P<path>[A-Za-z0-9/_-]+)', ['methods' => ['GET', 'POST', 'DELETE'], 'callback' => [__CLASS__, 'rest_co_proxy'], 'permission_callback' => [__CLASS__, 'perm_co_proxy']]);
            return;
        }
        $pp = ['permission_callback' => [__CLASS__, 'perm_partner']];
        register_rest_route(self::NS, '/partner/co-events', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_partner_co_events']] + $pp);
        register_rest_route(self::NS, '/partner/co-events/(?P<id>\d+)/call', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_partner_co_call']] + $pp);
        $p = ['permission_callback' => ['TIX_Platform_Customers', 'perm']];
        $k = '/platform/customers/(?P<key>[a-z]-[a-z0-9_-]{1,60})/move';
        register_rest_route(self::NS, $k, [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get']] + $p,
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_prepare']] + $p,
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_cancel']] + $p,
        ]);
        register_rest_route(self::NS, $k . '/switch', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_switch']] + $p);
        register_rest_route(self::NS, '/move/export', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_export'], 'permission_callback' => [__CLASS__, 'perm_token']]);
        register_rest_route(self::NS, '/move/report', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_report'], 'permission_callback' => [__CLASS__, 'perm_token']]);
    }

    // ═══════════════ Plattform: Umzug verwalten ═══════════════

    private static function customer($key) {
        $c = class_exists('TIX_Platform_Customers') ? TIX_Platform_Customers::raw($key) : null;
        if (!$c) return new WP_Error('tix_customer_unknown', 'Diesen Kunden gibt es nicht.', ['status' => 404]);
        return $c;
    }

    /** Grund, warum der Umzug für diesen Kunden nicht geht, oder ''. Nach dem Abschluss bleibt er sichtbar. */
    private static function unavailable($c) {
        if (!self::multi()) return 'not_platform';
        if (empty($c['organizer_id'])) return 'no_organizer';
        $m = self::record(intval($c['organizer_id']));
        if ($m && ($m['status'] ?? '') === 'switched') return '';
        return in_array($c['art'], ['provision', 'co'], true) ? '' : 'art';
    }

    public static function record($oid) {
        $m = get_option(self::OPT . intval($oid), null);
        return is_array($m) ? $m : null;
    }

    private static function save($oid, array $m) {
        update_option(self::OPT . intval($oid), $m, false);
    }

    private static function log(array &$m, $step, $text) {
        $m['log'][] = ['at' => gmdate('c'), 'step' => $step, 'text' => $text];
        $m['log'] = array_slice($m['log'], -50);
    }

    private static function public_move($m) {
        if (!$m) return null;
        return ['status' => $m['status'], 'created_at' => $m['created_at'], 'by' => $m['by'] ?? '', 'partner_id' => $m['partner_id'] ?? null,
            'expires_at' => gmdate('c', intval($m['expires'] ?? 0)), 'imported_at' => $m['imported_at'] ?? null, 'switched_at' => $m['switched_at'] ?? null,
            'counts' => (object) ($m['counts'] ?? []), 'errors' => array_slice((array) ($m['errors'] ?? []), 0, 20), 'map_count' => count((array) ($m['map']['events'] ?? [])),
            'log' => array_values((array) ($m['log'] ?? []))];
    }

    public static function rest_get(WP_REST_Request $req) {
        $c = self::customer($req['key']);
        if (is_wp_error($c)) return $c;
        $why = self::unavailable($c);
        $oid = intval($c['organizer_id']);
        $out = ['ok' => true, 'key' => $c['key'], 'available' => $why === '', 'reason' => $why ?: null, 'move' => $oid ? self::public_move(self::record($oid)) : null,
            'preview' => $oid && !$why ? self::counts($oid) : null, 'partners' => self::partner_list()];
        return rest_ensure_response($out);
    }

    public static function rest_prepare(WP_REST_Request $req) {
        $c = self::customer($req['key']);
        if (is_wp_error($c)) return $c;
        $why = self::unavailable($c);
        if ($why !== '') return new WP_Error('tix_move_unavailable', 'Ein Umzug geht nur für Provisionskunden und Co-Veranstalter.', ['status' => 409, 'reason' => $why]);
        $oid = intval($c['organizer_id']);
        $m = self::record($oid);
        if ($m && $m['status'] === 'switched') return new WP_Error('tix_move_switched', 'Der Umzug ist schon abgeschlossen.', ['status' => 409]);
        $token = 'mv_' . wp_generate_password(40, false);
        $u = wp_get_current_user();
        $m = $m ?: ['status' => 'prepared', 'created_at' => gmdate('c'), 'log' => [], 'map' => []];
        $m['token_hash'] = hash('sha256', $token);
        $m['expires'] = time() + self::TTL;
        $m['by'] = (string) $u->display_name;
        self::log($m, 'prepare', 'Umzugs-Schlüssel erstellt von ' . $u->display_name);
        self::save($oid, $m);
        $res = rest_ensure_response(['ok' => true, 'token' => $token, 'export_base' => rest_url(self::NS), 'move' => self::public_move($m)]);
        $res->set_status(201);
        return $res;
    }

    public static function rest_cancel(WP_REST_Request $req) {
        $c = self::customer($req['key']);
        if (is_wp_error($c)) return $c;
        $oid = intval($c['organizer_id']);
        $m = $oid ? self::record($oid) : null;
        if (!$m) return new WP_Error('tix_move_none', 'Es gibt keinen Umzug.', ['status' => 404]);
        if ($m['status'] === 'switched') return new WP_Error('tix_move_switched', 'Der Umzug ist schon abgeschlossen.', ['status' => 409]);
        $m['token_hash'] = ''; $m['status'] = 'cancelled';
        self::log($m, 'cancel', 'Abgebrochen von ' . wp_get_current_user()->display_name);
        self::save($oid, $m);
        return rest_ensure_response(['ok' => true, 'move' => self::public_move($m)]);
    }

    private static function partner_list() {
        if (!class_exists('TIX_Partners')) return [];
        $out = [];
        foreach (TIX_Partners::all() as $id => $p) {
            $out[] = ['id' => (string) $id, 'name' => (string) $p['name'], 'host' => (string) wp_parse_url((string) $p['api_base'], PHP_URL_HOST), 'organizer_id' => intval($p['organizer_id'] ?? 0)];
        }
        return $out;
    }

    /** Abschluss: eigene Events werden Spiegel der neuen Seite. */
    public static function rest_switch(WP_REST_Request $req) {
        $c = self::customer($req['key']);
        if (is_wp_error($c)) return $c;
        $oid = intval($c['organizer_id']);
        $m = $oid ? self::record($oid) : null;
        if (!$m || !in_array($m['status'], ['imported', 'switched'], true)) return new WP_Error('tix_move_not_imported', 'Bitte zuerst die Daten auf der neuen Seite übernehmen.', ['status' => 409]);
        if ($m['status'] === 'switched') return new WP_Error('tix_move_switched', 'Der Umzug ist schon abgeschlossen.', ['status' => 409]);
        $b = $req->get_json_params() ?: $req->get_params();
        $pid = sanitize_key((string) ($b['partner_id'] ?? ''));
        $p = $pid !== '' && class_exists('TIX_Partners') ? TIX_Partners::get($pid) : null;
        if (!$p) return new WP_Error('tix_invalid_field', 'Bitte die neue Seite (Partner) auswählen.', ['status' => 400, 'field' => 'partner_id']);
        $switched = 0; $skipped = [];
        foreach (self::own_event_ids($oid) as $eid) {
            $new = intval($m['map']['events'][$eid] ?? 0);
            if (!$new) { $skipped[] = ['id' => $eid, 'reason' => 'not_imported']; continue; }
            update_post_meta($eid, '_tix_syndicated', '1');
            update_post_meta($eid, '_tix_source_partner', $pid);
            update_post_meta($eid, '_tix_source_id', $new);
            update_post_meta($eid, '_tix_source_site', (string) $p['name']);
            update_post_meta($eid, '_tix_moved_at', gmdate('c'));
            update_post_meta($eid, '_tix_moved_to', $pid);
            $switched++;
        }
        $all = get_option(TIX_Partners::OPTION, []);
        if (is_array($all) && isset($all[$pid])) { $all[$pid]['organizer_id'] = $oid; update_option(TIX_Partners::OPTION, $all, false); }
        $m['status'] = 'switched'; $m['switched_at'] = gmdate('c'); $m['partner_id'] = $pid;
        $m['expires'] = time() + 7 * DAY_IN_SECONDS;
        self::log($m, 'switch', $switched . ' Events auf ' . $p['name'] . ' umgestellt' . ($skipped ? ', ' . count($skipped) . ' übersprungen' : ''));
        self::save($oid, $m);
        if (class_exists('TIX_Platform_Customers') && method_exists('TIX_Platform_Customers', 'flush')) TIX_Platform_Customers::flush();
        $key = class_exists('TIX_Platform_Customers') ? TIX_Platform_Customers::canonical('v-' . $oid) : null;
        $nc = $key ? TIX_Platform_Customers::raw($key) : null;
        return rest_ensure_response(['ok' => true, 'switched' => $switched, 'skipped' => $skipped, 'customer' => $nc ? ['key' => $nc['key'], 'art' => $nc['art']] : null, 'move' => self::public_move($m)]);
    }

    /** Für die Abrechnung: umgezogenes Event zählt weiter als eigenes Event (Verkäufe vor dem Umzug). */
    public static function settles_as_own($event_id) {
        return (string) get_post_meta(intval($event_id), '_tix_moved_at', true) !== '';
    }

    // ═══════════════ Plattform: Export ═══════════════

    public static function perm_token(WP_REST_Request $req) {
        if (!self::multi()) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        $tok = (string) $req->get_header('X-Tix-Move-Token');
        if (strlen($tok) < 20) return new WP_Error('tix_move_token', 'Umzugs-Schlüssel fehlt.', ['status' => 401]);
        global $wpdb;
        $h = hash('sha256', $tok);
        $rows = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(self::OPT) . '%'));
        foreach ($rows as $name) {
            $oid = intval(substr($name, strlen(self::OPT)));
            $m = $oid ? self::record($oid) : null;
            if ($m && !empty($m['token_hash']) && hash_equals($m['token_hash'], $h) && intval($m['expires'] ?? 0) > time()) {
                $req->set_param('_move_oid', $oid);
                return true;
            }
        }
        return new WP_Error('tix_move_token', 'Ungültiger oder abgelaufener Umzugs-Schlüssel.', ['status' => 401]);
    }

    /** Eigene Events (Ersteller = Kunde), ohne Spiegel fremder Seiten. */
    public static function own_event_ids($oid) {
        $ids = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC',
            'meta_query' => [['key' => '_tix_organizer_id', 'value' => strval(intval($oid))]]]);
        return array_values(array_filter(array_map('intval', $ids), function ($e) {
            return get_post_meta($e, '_tix_syndicated', true) !== '1' || get_post_meta($e, '_tix_moved_at', true) !== '';
        }));
    }

    private static function co_event_ids($oid) {
        return class_exists('TIX_Co_Organizer') ? array_values(array_diff(TIX_Co_Organizer::shared_event_ids($oid), self::own_event_ids($oid))) : [];
    }

    private static function order_ids(array $events) {
        global $wpdb;
        if (!$events || !class_exists('TIX_Order')) return [];
        $in = implode(',', array_map('intval', $events));
        return array_map('intval', $wpdb->get_col('SELECT DISTINCT order_id FROM ' . TIX_Order::items_table_name() . " WHERE event_id IN ($in) ORDER BY order_id"));
    }

    private static function ticket_ids(array $events) {
        if (!$events) return [];
        return array_map('intval', get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC',
            'meta_query' => [['key' => '_tix_ticket_event_id', 'value' => array_map('strval', $events), 'compare' => 'IN']]]));
    }

    private static function location_ids(array $events) {
        $ids = [];
        foreach ($events as $e) { $l = intval(get_post_meta($e, '_tix_location_id', true)); if ($l && get_post_type($l) === 'tix_location') $ids[$l] = $l; }
        return array_values($ids);
    }

    private static function artist_ids(array $events) {
        $ids = [];
        foreach ($events as $e) foreach ((array) get_post_meta($e, '_tix_artists', true) as $a) {
            $aid = intval(is_array($a) ? ($a['artist_id'] ?? 0) : 0);
            if ($aid && get_post_type($aid) === 'tix_artist') $ids[$aid] = $aid;
        }
        return array_values($ids);
    }

    private static function newsletter_rows($oid, $count = false, $page = 0) {
        global $wpdb;
        if (!class_exists('TIX_Newsletter')) return $count ? 0 : [];
        $t = TIX_Newsletter::tc();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) return $count ? 0 : [];
        if ($count) return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE organizer_id = %d", $oid)));
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE organizer_id = %d ORDER BY id LIMIT %d OFFSET %d", $oid, self::PAGE, $page * self::PAGE), ARRAY_A) ?: [];
    }

    private static function coupons($oid, array $events) {
        $out = [];
        $ev = array_flip($events);
        foreach ((array) get_option('tix_coupons', []) as $code => $c) {
            if (!is_array($c)) continue;
            $hit = intval($c['organizer_id'] ?? 0) === intval($oid);
            foreach (['event_ids', 'events', 'event_id'] as $k) foreach ((array) ($c[$k] ?? []) as $e) if (isset($ev[intval($e)])) $hit = true;
            if ($hit) $out[(string) $code] = $c;
        }
        return $out;
    }

    public static function counts($oid) {
        $ev = self::own_event_ids($oid);
        $guests = 0;
        foreach ($ev as $e) $guests += count(array_filter((array) get_post_meta($e, '_tix_guest_list', true), 'is_array'));
        $images = 0;
        foreach ($ev as $e) $images += (get_post_thumbnail_id($e) ? 1 : 0) + count(array_filter((array) get_post_meta($e, '_tix_gallery', true)));
        return ['events' => count($ev), 'co_events' => count(self::co_event_ids($oid)), 'orders' => count(self::order_ids($ev)), 'tickets' => count(self::ticket_ids($ev)),
            'guests' => $guests, 'newsletter' => self::newsletter_rows($oid, true), 'coupons' => count(self::coupons($oid, $ev)),
            'locations' => count(self::location_ids($ev)), 'artists' => count(self::artist_ids($ev)), 'images' => $images];
    }

    /** Alle _tix_*-Meta eines Posts (ohne Plattform-interne), mehrfache Werte als Liste. */
    private static function meta_of($post_id, $skip = true) {
        $out = [];
        foreach (get_post_meta($post_id) as $k => $vals) {
            if (strpos($k, '_tix_') !== 0 || ($skip && preg_match(self::SKIP_META_RE, $k))) continue;
            $out[$k] = array_map('maybe_unserialize', (array) $vals);
        }
        return $out;
    }

    private static function img($att) {
        $att = intval($att);
        if (!$att) return null;
        $u = wp_get_attachment_url($att);
        return $u ? ['id' => $att, 'url' => $u, 'alt' => (string) get_post_meta($att, '_wp_attachment_image_alt', true)] : null;
    }

    private static function post_row($id) {
        $p = get_post($id);
        return ['id' => intval($p->ID), 'post_type' => $p->post_type, 'post_title' => $p->post_title, 'post_content' => $p->post_content, 'post_excerpt' => $p->post_excerpt,
            'post_status' => $p->post_status, 'post_name' => $p->post_name, 'post_date_gmt' => $p->post_date_gmt, 'thumbnail' => self::img(get_post_thumbnail_id($id))];
    }

    public static function rest_export(WP_REST_Request $req) {
        $oid = intval($req->get_param('_move_oid'));
        $part = sanitize_key((string) $req->get_param('part'));
        $page = max(0, intval($req->get_param('page')));
        $ev = self::own_event_ids($oid);
        $slice = function (array $ids) use ($page) { return [array_slice($ids, $page * self::PAGE, self::PAGE), count($ids) > ($page + 1) * self::PAGE]; };
        $items = []; $more = false;
        switch ($part) {
            case 'manifest':
                return rest_ensure_response(['ok' => true, 'part' => 'manifest', 'organizer_id' => $oid, 'organizer_name' => html_entity_decode(get_the_title($oid), ENT_QUOTES, 'UTF-8'),
                    'platform' => TIX_Partners::platform_id(), 'counts' => self::counts($oid), 'parts' => self::PARTS, 'not_moved' => self::NOT_MOVED]);
            case 'organizer':
                $r = self::post_row($oid);
                $r['meta'] = self::meta_of($oid);
                $r['logo'] = self::img(get_post_meta($oid, '_tix_org_logo_id', true));
                $items = [$r];
                break;
            case 'locations':
                [$ids, $more] = $slice(self::location_ids($ev));
                foreach ($ids as $id) { $r = self::post_row($id); $r['meta'] = self::meta_of($id, false); $items[] = $r; }
                break;
            case 'artists':
                [$ids, $more] = $slice(self::artist_ids($ev));
                foreach ($ids as $id) { $r = self::post_row($id); $r['meta'] = self::meta_of($id, false); $items[] = $r; }
                break;
            case 'events':
                [$ids, $more] = $slice($ev);
                foreach ($ids as $id) {
                    $r = self::post_row($id);
                    $r['meta'] = self::meta_of($id);
                    $r['location_id'] = intval(get_post_meta($id, '_tix_location_id', true));
                    $r['categories'] = array_map(function ($t) { return ['slug' => $t->slug, 'name' => $t->name]; }, (array) wp_get_object_terms($id, 'event_category') ?: []);
                    $r['gallery'] = array_values(array_filter(array_map([__CLASS__, 'img'], (array) get_post_meta($id, '_tix_gallery', true))));
                    $items[] = $r;
                }
                break;
            case 'orders':
                global $wpdb;
                [$ids, $more] = $slice(self::order_ids($ev));
                foreach ($ids as $id) {
                    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TIX_Order::table_name() . ' WHERE id = %d', $id), ARRAY_A);
                    if (!$row) continue;
                    $row['items'] = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . TIX_Order::items_table_name() . ' WHERE order_id = %d', $id), ARRAY_A) ?: [];
                    $row['notes'] = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . TIX_Order::notes_table_name() . ' WHERE order_id = %d', $id), ARRAY_A) ?: [];
                    $row['options'] = [];
                    foreach (['_tix_pos_order_', '_tix_refund_total_'] as $o) { $v = get_option($o . $id, null); if ($v !== null) $row['options'][$o] = $v; }
                    $items[] = $row;
                }
                break;
            case 'tickets':
                global $wpdb;
                [$ids, $more] = $slice(self::ticket_ids($ev));
                $t = class_exists('TIX_Ticket_DB') ? $wpdb->prefix . TIX_Ticket_DB::TABLE_SUFFIX : '';
                foreach ($ids as $id) {
                    $r = self::post_row($id);
                    $r['meta'] = self::meta_of($id, false);
                    $r['db'] = $t ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE ticket_post_id = %d", $id), ARRAY_A) : null;
                    $items[] = $r;
                }
                break;
            case 'newsletter':
                $items = self::newsletter_rows($oid, false, $page);
                $more = count($items) === self::PAGE;
                break;
            case 'coupons':
                foreach (self::coupons($oid, $ev) as $code => $c) $items[] = ['code' => $code, 'data' => $c];
                break;
            default:
                return new WP_Error('tix_invalid_field', 'part: manifest|' . implode('|', self::PARTS), ['status' => 400, 'field' => 'part']);
        }
        return rest_ensure_response(['ok' => true, 'part' => $part, 'page' => $page, 'has_more' => (bool) $more, 'items' => $items]);
    }

    public static function rest_report(WP_REST_Request $req) {
        $oid = intval($req->get_param('_move_oid'));
        $m = self::record($oid);
        if (!$m) return new WP_Error('tix_move_none', 'Es gibt keinen Umzug.', ['status' => 404]);
        if ($m['status'] === 'switched') return new WP_Error('tix_move_switched', 'Der Umzug ist schon abgeschlossen.', ['status' => 409]);
        $b = $req->get_json_params() ?: [];
        $own = array_flip(self::own_event_ids($oid));
        $map = [];
        foreach ((array) ($b['events'] ?? []) as $from => $to) if (isset($own[intval($from)]) && intval($to) > 0) $map[intval($from)] = intval($to);
        $m['map']['events'] = $map;
        $m['counts'] = array_map('intval', array_intersect_key((array) ($b['counts'] ?? []), array_flip(array_merge(self::PARTS, ['images', 'guests']))));
        $m['errors'] = array_slice(array_map('strval', (array) ($b['errors'] ?? [])), 0, 50);
        $m['imported_at'] = gmdate('c');
        $m['status'] = 'imported';
        $m['site'] = esc_url_raw((string) ($b['site'] ?? ''));
        self::log($m, 'import', count($map) . ' Events übernommen von ' . ($m['site'] ?: 'neuer Seite') . ($m['errors'] ? ', ' . count($m['errors']) . ' Hinweise' : ''));
        self::save($oid, $m);
        return rest_ensure_response(['ok' => true, 'move' => self::public_move($m)]);
    }

    // ═══════════════ Neue Seite: Import ═══════════════

    /** Lokale Event-ID für eine (evtl. alte Plattform-)ID aus einem QR-Code. */
    public static function local_event_id($id) {
        $id = intval($id);
        if (!$id || self::multi()) return $id;
        if (get_post_type($id) === 'event' && get_post_meta($id, self::META_FROM, true) === '') {
            // Gibt es ein umgezogenes Event mit dieser alten ID und ist die lokale ID selbst kein Umzugs-Event, gilt weiter die lokale
            return $id;
        }
        $map = (array) get_option(self::OPT_MAP, []);
        $to = intval($map['events'][$id] ?? 0);
        return $to && get_post_type($to) === 'event' ? $to : $id;
    }

    /** Wie local_event_id, aber für Gästelisten: lokale ID nur, wenn der Gast dort steht. */
    public static function guest_event_id($id, $guest_id) {
        $id = intval($id);
        if (!$id || self::multi()) return $id;
        $has = function ($e) use ($guest_id) {
            foreach ((array) get_post_meta($e, '_tix_guest_list', true) as $g) if (is_array($g) && strtoupper((string) ($g['id'] ?? '')) === strtoupper((string) $guest_id)) return true;
            return false;
        };
        if (get_post_type($id) === 'event' && $has($id)) return $id;
        $map = (array) get_option(self::OPT_MAP, []);
        $to = intval($map['events'][$id] ?? 0);
        return $to && $has($to) ? $to : $id;
    }

    private static $api = '';
    private static $token = '';
    private static $dry = false;

    /** Route an der Plattform-API (auch Form ?rest_route=/tixomat/v1). */
    private static function url($route) {
        return self::$api . $route;
    }

    private static function fetch($part, $page = 0) {
        $url = self::url('/move/export') . (strpos(self::$api, '?rest_route=') !== false ? '&' : '?') . 'part=' . rawurlencode($part) . '&page=' . intval($page);
        $r = wp_remote_get($url, ['timeout' => 60, 'headers' => ['X-Tix-Move-Token' => self::$token, 'Accept' => 'application/json']]);
        if (is_wp_error($r)) return new WP_Error('tix_move_http', $r->get_error_message());
        $code = intval(wp_remote_retrieve_response_code($r));
        $d = json_decode((string) wp_remote_retrieve_body($r), true);
        if ($code !== 200 || !is_array($d)) return new WP_Error('tix_move_http', 'HTTP ' . $code . ': ' . (is_array($d) ? (string) ($d['message'] ?? '') : substr((string) wp_remote_retrieve_body($r), 0, 200)));
        return $d;
    }

    private static function each_page($part, callable $fn) {
        for ($p = 0; $p < 500; $p++) {
            $d = self::fetch($part, $p);
            if (is_wp_error($d)) return $d;
            foreach ((array) $d['items'] as $it) $fn($it);
            if (empty($d['has_more'])) break;
        }
        return true;
    }

    private static function find_moved($type, $from) {
        $ids = get_posts(['post_type' => $type, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => self::META_FROM, 'meta_value' => strval(intval($from))]);
        return $ids ? intval($ids[0]) : 0;
    }

    private static function sideload($img, $parent, array &$errors) {
        if (!$img || empty($img['url']) || self::$dry) return 0;
        static $done = [];
        if (isset($done[$img['url']])) return $done[$img['url']];
        $have = get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_tix_moved_src', 'meta_value' => $img['url']]);
        if ($have) return $done[$img['url']] = intval($have[0]);
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $att = media_sideload_image($img['url'], $parent, null, 'id');
        if (is_wp_error($att)) { $errors[] = 'Bild ' . $img['url'] . ': ' . $att->get_error_message(); return 0; }
        update_post_meta($att, '_tix_moved_src', $img['url']);
        if (!empty($img['alt'])) update_post_meta($att, '_wp_attachment_image_alt', $img['alt']);
        return $done[$img['url']] = intval($att);
    }

    private static function upsert_post(array $r, $type, array &$errors, array $meta_over = []) {
        $id = self::find_moved($type, $r['id']);
        $arr = ['post_type' => $type, 'post_title' => $r['post_title'], 'post_content' => $r['post_content'], 'post_excerpt' => $r['post_excerpt'],
            'post_status' => in_array($r['post_status'], ['publish', 'draft', 'pending', 'private', 'future'], true) ? $r['post_status'] : 'draft', 'post_name' => $r['post_name']];
        if (self::$dry) return $id ?: -intval($r['id']);
        if ($id) { $arr['ID'] = $id; wp_update_post($arr); }
        else {
            if (!empty($r['post_date_gmt']) && $r['post_date_gmt'] !== '0000-00-00 00:00:00') { $arr['post_date_gmt'] = $r['post_date_gmt']; $arr['post_date'] = get_date_from_gmt($r['post_date_gmt']); }
            $id = wp_insert_post($arr, true);
            if (is_wp_error($id)) { $errors[] = $type . ' #' . $r['id'] . ': ' . $id->get_error_message(); return 0; }
            update_post_meta($id, self::META_FROM, intval($r['id']));
        }
        foreach ((array) ($r['meta'] ?? []) as $k => $vals) {
            if (array_key_exists($k, $meta_over)) continue;
            delete_post_meta($id, $k);
            foreach ((array) $vals as $v) add_post_meta($id, $k, $v);
        }
        foreach ($meta_over as $k => $v) { if ($v === null) delete_post_meta($id, $k); else update_post_meta($id, $k, $v); }
        if (!empty($r['thumbnail'])) { $att = self::sideload($r['thumbnail'], $id, $errors); if ($att) set_post_thumbnail($id, $att); }
        return intval($id);
    }

    /** Import auf der neuen Seite. → Ergebnis-Array */
    public static function import($api, $token, $dry = false) {
        if (self::multi()) return new WP_Error('tix_move_platform', 'Der Import läuft nur auf der neuen (Einzel-)Seite.');
        $state = (array) get_option(self::OPT_IMPORT, []);
        if (($state['status'] ?? '') === 'done') return new WP_Error('tix_move_done', 'Auf dieser Seite ist schon ein Umzug abgeschlossen.');
        self::$api = untrailingslashit($api); self::$token = $token; self::$dry = (bool) $dry;
        $man = self::fetch('manifest');
        if (is_wp_error($man)) return $man;
        $map = (array) get_option(self::OPT_MAP, []);
        foreach (['events', 'orders', 'tickets', 'locations', 'artists'] as $k) $map[$k] = (array) ($map[$k] ?? []);
        $errors = []; $n = array_fill_keys(array_merge(self::PARTS, ['images']), 0);

        // Veranstalter
        $r = self::each_page('organizer', function ($o) use (&$map, &$errors, &$n) {
            $id = self::upsert_post($o, 'tix_organizer', $errors, ['_tix_org_user_id' => null]);
            if ($id > 0 && !empty($o['logo'])) { $att = self::sideload($o['logo'], $id, $errors); if ($att) update_post_meta($id, '_tix_org_logo_id', $att); }
            $map['organizer'] = $id; $n['organizer']++;
        });
        if (is_wp_error($r)) return $r;
        $org = intval($map['organizer'] ?? 0);
        // Orte, Künstler
        foreach (['locations' => 'tix_location', 'artists' => 'tix_artist'] as $part => $type) {
            $r = self::each_page($part, function ($p) use (&$map, &$errors, &$n, $part, $type) {
                $id = self::upsert_post($p, $type, $errors);
                if ($id) { $map[$part][intval($p['id'])] = $id; $n[$part]++; }
            });
            if (is_wp_error($r)) return $r;
        }
        // Events
        $r = self::each_page('events', function ($e) use (&$map, &$errors, &$n, $org) {
            $artists = [];
            foreach ((array) ($e['meta']['_tix_artists'][0] ?? []) as $a) {
                if (!is_array($a)) continue;
                $a['artist_id'] = intval($map['artists'][intval($a['artist_id'] ?? 0)] ?? 0);
                if ($a['artist_id']) $artists[] = $a;
            }
            $over = ['_tix_organizer_id' => $org ?: null, '_tix_location_id' => intval($map['locations'][intval($e['location_id'])] ?? 0) ?: null,
                '_tix_artists' => $artists ?: null, '_tix_artist_ref' => null, '_tix_gallery' => null,
                '_tix_syndicate' => '1', '_tix_syndicate_remote_id' => intval($e['id'])];
            $id = self::upsert_post($e, 'event', $errors, $over);
            if (!$id) return;
            $map['events'][intval($e['id'])] = $id; $n['events']++;
            if ($id < 0) return;
            foreach ($artists as $a) add_post_meta($id, '_tix_artist_ref', intval($a['artist_id']));
            $gal = [];
            foreach ((array) $e['gallery'] as $g) { $att = self::sideload($g, $id, $errors); if ($att) { $gal[] = $att; $n['images']++; } }
            update_post_meta($id, '_tix_gallery', $gal);
            if (!empty($e['thumbnail'])) $n['images']++;
            $terms = [];
            foreach ((array) $e['categories'] as $c) {
                $t = term_exists($c['slug'], 'event_category') ?: wp_insert_term($c['name'], 'event_category', ['slug' => $c['slug']]);
                if (!is_wp_error($t)) $terms[] = intval(is_array($t) ? $t['term_id'] : $t);
            }
            if ($terms) wp_set_object_terms($id, $terms, 'event_category');
        });
        if (is_wp_error($r)) return $r;
        // Bestellungen
        global $wpdb;
        $r = self::each_page('orders', function ($o) use (&$map, &$errors, &$n, $wpdb) {
            $from = intval($o['id']);
            if (!empty($map['orders'][$from])) { $n['orders']++; return; }
            $exists = intval($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . TIX_Order::table_name() . ' WHERE order_number = %s', (string) $o['order_number'])));
            if ($exists) { $map['orders'][$from] = $exists; $n['orders']++; return; }
            if (self::$dry) { $map['orders'][$from] = -$from; $n['orders']++; return; }
            $row = array_diff_key($o, array_flip(['id', 'items', 'notes', 'options']));
            $row['customer_id'] = 0; $row['wc_order_id'] = 0;
            if ($wpdb->insert(TIX_Order::table_name(), $row) === false) { $errors[] = 'Bestellung ' . $o['order_number'] . ': ' . $wpdb->last_error; return; }
            $new = intval($wpdb->insert_id);
            foreach ((array) $o['items'] as $it) {
                $it = array_diff_key($it, ['id' => 1]);
                $it['order_id'] = $new;
                $it['event_id'] = intval($map['events'][intval($it['event_id'])] ?? 0);
                $wpdb->insert(TIX_Order::items_table_name(), $it);
            }
            foreach ((array) $o['notes'] as $nt) { $nt = array_diff_key($nt, ['id' => 1]); $nt['order_id'] = $new; $wpdb->insert(TIX_Order::notes_table_name(), $nt); }
            foreach ((array) $o['options'] as $k => $v) update_option($k . $new, $v, false);
            $wpdb->insert(TIX_Order::notes_table_name(), ['order_id' => $new, 'note' => 'Übernommen von ' . self::$api . ' (Bestellung #' . $from . ').', 'note_type' => 'internal', 'author' => 'Umzug']);
            $map['orders'][$from] = $new; $n['orders']++;
        });
        if (is_wp_error($r)) return $r;
        // Tickets
        $tdb = class_exists('TIX_Ticket_DB') ? $wpdb->prefix . TIX_Ticket_DB::TABLE_SUFFIX : '';
        $r = self::each_page('tickets', function ($t) use (&$map, &$errors, &$n, $wpdb, $tdb) {
            $ev = intval($map['events'][intval($t['meta']['_tix_ticket_event_id'][0] ?? 0)] ?? 0);
            $ord = intval($map['orders'][intval($t['meta']['_tix_ticket_order_id'][0] ?? 0)] ?? 0);
            $id = self::upsert_post($t, 'tix_ticket', $errors, ['_tix_ticket_event_id' => $ev ?: null, '_tix_ticket_order_id' => $ord ?: null, '_tix_ticket_assigned_user_id' => null]);
            if (!$id) return;
            $map['tickets'][intval($t['id'])] = $id; $n['tickets']++;
            if ($id < 0 || !$tdb || empty($t['db'])) return;
            $row = array_diff_key($t['db'], ['id' => 1]);
            $row['ticket_post_id'] = $id; $row['event_id'] = $ev; $row['order_id'] = $ord ?: null;
            $have = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM $tdb WHERE ticket_code = %s", (string) $row['ticket_code'])));
            if ($have) $wpdb->update($tdb, $row, ['id' => $have]); else $wpdb->insert($tdb, $row);
        });
        if (is_wp_error($r)) return $r;
        // Newsletter
        if (class_exists('TIX_Newsletter')) {
            $tc = TIX_Newsletter::tc();
            $r = self::each_page('newsletter', function ($c) use (&$n, $wpdb, $tc, $org) {
                $n['newsletter']++;
                if (self::$dry) return;
                $row = array_diff_key($c, ['id' => 1]);
                $row['organizer_id'] = $org;
                $have = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM $tc WHERE organizer_id = %d AND email = %s", $org, (string) $row['email'])));
                if (!$have) $wpdb->insert($tc, $row);
            });
            if (is_wp_error($r)) return $r;
        }
        // Gutscheine
        $r = self::each_page('coupons', function ($c) use (&$map, &$n, $org) {
            $n['coupons']++;
            if (self::$dry) return;
            $all = (array) get_option('tix_coupons', []);
            $d = (array) $c['data'];
            if (isset($d['organizer_id'])) $d['organizer_id'] = $org;
            foreach (['event_ids', 'events'] as $k) if (isset($d[$k]) && is_array($d[$k])) $d[$k] = array_values(array_filter(array_map(function ($e) use ($map) { return intval($map['events'][intval($e)] ?? 0); }, $d[$k])));
            if (isset($d['event_id'])) $d['event_id'] = intval($map['events'][intval($d['event_id'])] ?? 0);
            if (!isset($all[$c['code']])) { $all[$c['code']] = $d; update_option('tix_coupons', $all); }
        });
        if (is_wp_error($r)) return $r;

        $events = [];
        foreach ($map['events'] as $from => $to) if ($to > 0) $events[$from] = $to;
        if (!self::$dry) {
            update_option(self::OPT_MAP, $map, false);
            update_option(self::OPT_IMPORT, ['status' => 'imported', 'from' => self::$api, 'at' => gmdate('c'), 'counts' => $n, 'errors' => array_slice($errors, 0, 50)], false);
            $rep = wp_remote_post(self::url('/move/report'), ['timeout' => 30,
                'headers' => ['X-Tix-Move-Token' => self::$token, 'Content-Type' => 'application/json'],
                'body' => wp_json_encode(['counts' => $n, 'events' => (object) $events, 'errors' => array_slice($errors, 0, 50), 'site' => home_url('/')])]);
            if (is_wp_error($rep) || intval(wp_remote_retrieve_response_code($rep)) !== 200) $errors[] = 'Rückmeldung an die Plattform fehlgeschlagen (' . (is_wp_error($rep) ? $rep->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($rep)) . ').';
        }
        return ['ok' => true, 'dry_run' => self::$dry, 'organizer' => $man['organizer_name'] ?? '', 'counts' => $n, 'events' => count($events), 'errors' => $errors, 'not_moved' => $man['not_moved'] ?? []];
    }

    // ═══════════════ Schritt 2: Co-Events (Plattform) ═══════════════

    /** Partner über seinen Schlüssel (X-Tix-Syndication-Key), mit zugeordnetem Veranstalter. */
    public static function perm_partner(WP_REST_Request $req) {
        if (!self::multi() || !class_exists('TIX_Partners')) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        $p = TIX_Partners::find_by_key((string) $req->get_header('X-Tix-Syndication-Key'));
        if (!$p) return new WP_Error('unauthorized', 'Ungültiger Partner-Schlüssel.', ['status' => 401]);
        if (!intval($p['organizer_id'] ?? 0)) return new WP_Error('tix_partner_no_organizer', 'Diese Seite ist keinem Veranstalter zugeordnet.', ['status' => 403]);
        wp_set_current_user(0);
        $req->set_param('_partner_oid', intval($p['organizer_id']));
        return true;
    }

    /** Co-Events des Veranstalters (Ersteller = andere), nicht im Papierkorb. */
    private static function partner_co_ids($oid) {
        return array_values(array_filter(self::co_event_ids($oid), function ($e) use ($oid) {
            return in_array(get_post_status($e), ['publish', 'draft', 'pending', 'private', 'future'], true) && intval(TIX_Co_Organizer::ref_id($e)) === intval($oid);
        }));
    }

    public static function rest_partner_co_events(WP_REST_Request $req) {
        $oid = intval($req->get_param('_partner_oid'));
        $items = [];
        foreach (self::partner_co_ids($oid) as $e) {
            $creator = intval(get_post_meta($e, '_tix_organizer_id', true));
            $thumb = get_post_thumbnail_id($e);
            $price = null;
            foreach ((array) get_post_meta($e, '_tix_ticket_categories', true) as $c) if (is_array($c) && empty($c['hidden']) && isset($c['price'])) { $p = floatval(($c['sale_price'] ?? '') !== '' ? $c['sale_price'] : $c['price']); $price = $price === null ? $p : min($price, $p); }
            $items[] = ['id' => $e, 'status' => get_post_status($e), 'title' => html_entity_decode(get_the_title($e), ENT_QUOTES, 'UTF-8'),
                'content' => (string) get_post_field('post_content', $e), 'excerpt' => (string) get_post_field('post_excerpt', $e),
                'date_start' => (string) get_post_meta($e, '_tix_date_start', true), 'time_start' => (string) get_post_meta($e, '_tix_time_start', true),
                'date_end' => (string) get_post_meta($e, '_tix_date_end', true), 'time_end' => (string) get_post_meta($e, '_tix_time_end', true),
                'location' => (string) get_post_meta($e, '_tix_location', true), 'address' => (string) get_post_meta($e, '_tix_address', true),
                'image' => $thumb ? (string) wp_get_attachment_image_url($thumb, 'large') : '', 'url' => (string) get_permalink($e),
                'tickets' => get_post_meta($e, '_tix_tickets_enabled', true) === '1', 'price_from' => $price,
                'creator' => ['id' => $creator, 'name' => html_entity_decode((string) get_the_title($creator), ENT_QUOTES, 'UTF-8')],
                'access' => TIX_Co_Organizer::has_access($e)];
        }
        return rest_ensure_response(['ok' => true, 'organizer_id' => $oid, 'items' => $items]);
    }

    /** Durchgriff der neuen Seite: Route für ein Co-Event als Inhaber des Co-Veranstalters (Rechte wie Teil A, Storno erlaubt). */
    public static function rest_partner_co_call(WP_REST_Request $req) {
        $oid = intval($req->get_param('_partner_oid'));
        $eid = absint($req['id']);
        if (!in_array($eid, self::partner_co_ids($oid), true)) return new WP_Error('tix_co_forbidden', 'Kein gemeinsames Event.', ['status' => 403]);
        if (!TIX_Co_Organizer::has_access($eid)) return new WP_Error('tix_co_no_access', 'Der Veranstalter hat den Zugriff auf dieses Event ausgeschaltet.', ['status' => 403]);
        $b = $req->get_json_params();
        if (!is_array($b)) $b = [];
        $method = strtoupper((string) ($b['method'] ?? 'GET'));
        $path = '/' . ltrim((string) ($b['path'] ?? ''), '/');
        $q = is_array($b['query'] ?? null) ? $b['query'] : [];
        $body = is_array($b['body'] ?? null) ? $b['body'] : [];
        if (preg_match('#[^A-Za-z0-9/_-]#', $path)) return new WP_Error('tix_invalid_field', 'Ungültige Route.', ['status' => 400, 'field' => 'path']);
        $ok = TIX_Co_Remote::allowed($method, $path, $q, $body, $eid, true); // Ersteller ist Provisionskunde → Storno/Erstattung erlaubt (User 09.11.)
        if (is_wp_error($ok)) return rest_ensure_response(['ok' => false, 'status' => intval($ok->get_error_data()['status'] ?? 403), 'data' => ['code' => $ok->get_error_code(), 'message' => $ok->get_error_message(), 'data' => $ok->get_error_data()]]);
        $u = TIX_Co_Organizer::owner_user($oid);
        if (!$u) return new WP_Error('tix_co_actor', 'Zum Veranstalter gibt es kein Konto auf der Plattform.', ['status' => 409]);
        wp_set_current_user($u->ID);
        try {
            $sub = new WP_REST_Request($method, '/' . self::NS . $path);
            $sub->set_query_params($q);
            if ($method !== 'GET' && $body) { $sub->set_header('content-type', 'application/json'); $sub->set_body(wp_json_encode($body)); }
            $res = rest_do_request($sub);
            $data = rest_get_server()->response_to_data($res, false);
            $status = $res->get_status(); $headers = $res->get_headers();
        } finally {
            wp_set_current_user(0);
        }
        $out = ['ok' => $status < 400, 'status' => $status, 'data' => $data];
        foreach (['Content-Type', 'Content-Disposition'] as $h) if (!empty($headers[$h])) $out['headers'][$h] = (string) $headers[$h];
        return rest_ensure_response($out);
    }

    // ═══════════════ Schritt 2: Co-Events (neue Seite) ═══════════════

    private static function co_ready() {
        return class_exists('TIX_Syndication_Push') && TIX_Syndication_Push::is_configured();
    }

    private static function co_api($route) {
        return rtrim((string) tix_get_settings('syndication_api_url'), '/') . $route;
    }

    private static function co_headers() {
        return ['X-Tix-Syndication-Key' => (string) tix_get_settings('syndication_api_key'), 'Accept' => 'application/json', 'Content-Type' => 'application/json'];
    }

    /** Plattform-ID, wenn das Event ein Spiegel eines Plattform-Co-Events ist, sonst 0. */
    public static function platform_co_id($event_id) {
        return self::multi() ? 0 : intval(get_post_meta(intval($event_id), self::CO_META, true));
    }

    public static function co_list_fields($eid) {
        return ['event_role' => 'co', 'remote' => true, 'base' => '/platform-co/' . intval($eid), 'access' => get_post_meta($eid, '_tix_platform_co_access', true) === '1',
            'shared_with' => ['id' => 0, 'name' => (string) get_post_meta($eid, '_tix_platform_co_by', true), 'logo' => '']];
    }

    public static function co_editor_payload($eid) {
        return ['co_organizer_available' => false, 'co_organizer_mode' => 'platform', 'co_organizer_unavailable_reason' => 'platform_co', 'co_organizer_editable' => false,
            'event_role' => 'co', 'co_organizer' => null, 'remote_base' => '/platform-co/' . intval($eid)];
    }

    /** Abgleich: Co-Events der Plattform als Anzeige-Spiegel (Ticketkauf beim Ersteller). → {ok, created, updated, removed} | WP_Error */
    public static function sync_co_events() {
        if (self::multi() || !self::co_ready()) return new WP_Error('tix_co_not_ready', 'Keine Verbindung zur Plattform eingerichtet.');
        $r = wp_remote_get(self::co_api('/partner/co-events'), ['timeout' => 30, 'headers' => self::co_headers()]);
        if (is_wp_error($r)) return new WP_Error('tix_co_http', $r->get_error_message());
        $code = intval(wp_remote_retrieve_response_code($r));
        $d = json_decode((string) wp_remote_retrieve_body($r), true);
        if ($code === 403 && is_array($d) && ($d['code'] ?? '') === 'tix_partner_no_organizer') return ['ok' => true, 'created' => 0, 'updated' => 0, 'removed' => 0, 'note' => 'no_organizer'];
        if ($code !== 200 || !is_array($d)) return new WP_Error('tix_co_http', 'HTTP ' . $code . ': ' . (is_array($d) ? (string) ($d['message'] ?? '') : ''));
        $map = (array) get_option(self::OPT_MAP, []);
        $org = intval($map['organizer'] ?? 0) ?: intval((get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']) ?: [0])[0]);
        $seen = []; $n = ['created' => 0, 'updated' => 0, 'removed' => 0]; $errors = [];
        foreach ((array) $d['items'] as $it) {
            $pid = intval($it['id']);
            $seen[$pid] = true;
            $have = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future', 'trash'], 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => self::CO_META, 'meta_value' => strval($pid)]);
            $meta = [self::CO_META => $pid, '_tix_syndicate' => '0', '_tix_platform_co_by' => (string) ($it['creator']['name'] ?? ''),
                '_tix_platform_co_access' => !empty($it['access']) ? '1' : '0', '_tix_platform_co_url' => (string) $it['url'], '_tix_organizer_id' => $org,
                '_tix_date_start' => (string) $it['date_start'], '_tix_time_start' => (string) $it['time_start'], '_tix_date_end' => (string) $it['date_end'], '_tix_time_end' => (string) $it['time_end'],
                '_tix_location' => (string) $it['location'], '_tix_address' => (string) $it['address'], '_tix_tickets_enabled' => '0',
                '_tix_extshop_enabled' => !empty($it['tickets']) ? '1' : '0', '_tix_extshop_url' => (string) $it['url'], '_tix_extshop_text' => 'Tickets kaufen', '_tix_extshop_mode' => 'replace',
                '_tix_extshop_price' => $it['price_from'] === null ? '' : (string) $it['price_from']];
            $post = ['post_type' => 'event', 'post_title' => (string) $it['title'], 'post_content' => (string) $it['content'], 'post_excerpt' => (string) $it['excerpt'],
                'post_status' => in_array($it['status'], ['publish', 'draft', 'pending', 'private', 'future'], true) ? $it['status'] : 'draft'];
            if ($have) {
                $id = intval($have[0]);
                wp_update_post($post + ['ID' => $id]);
                foreach ($meta as $k => $v) update_post_meta($id, $k, $v);
                $n['updated']++;
            } else {
                $id = wp_insert_post($post + ['meta_input' => $meta], true);
                if (is_wp_error($id)) { $errors[] = $id->get_error_message(); continue; }
                $n['created']++;
            }
            if ((string) $it['image'] !== (string) get_post_meta($id, '_tix_platform_co_img', true)) {
                $att = $it['image'] !== '' ? self::sideload(['url' => $it['image']], $id, $errors) : 0;
                if ($att) set_post_thumbnail($id, $att); elseif ($it['image'] === '') delete_post_thumbnail($id);
                update_post_meta($id, '_tix_platform_co_img', (string) $it['image']);
            }
        }
        foreach (get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => self::CO_META]) as $id) {
            if (empty($seen[intval(get_post_meta($id, self::CO_META, true))])) { wp_trash_post($id); $n['removed']++; }
        }
        update_option('tix_platform_co_sync', ['at' => gmdate('c'), 'counts' => $n, 'errors' => array_slice($errors, 0, 20)], false);
        return ['ok' => true] + $n + ['errors' => $errors];
    }

    public static function rest_co_sync() {
        $r = self::sync_co_events();
        return is_wp_error($r) ? new WP_Error($r->get_error_code(), $r->get_error_message(), ['status' => 502]) : rest_ensure_response($r);
    }

    public static function rest_co_list() {
        $items = [];
        foreach (get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => 200, 'fields' => 'ids', 'meta_key' => self::CO_META]) as $id) {
            $items[] = ['id' => intval($id), 'platform_id' => self::platform_co_id($id), 'title' => html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'),
                'date_start' => (string) get_post_meta($id, '_tix_date_start', true), 'time_start' => (string) get_post_meta($id, '_tix_time_start', true),
                'image' => (string) get_the_post_thumbnail_url($id, 'large'), 'url' => (string) get_post_meta($id, '_tix_platform_co_url', true)] + self::co_list_fields($id);
        }
        return rest_ensure_response(['ok' => true, 'items' => $items, 'sync' => get_option('tix_platform_co_sync', null)]);
    }

    public static function perm_co_proxy(WP_REST_Request $req) {
        $c = TIX_REST_API::check_organizer($req);
        if ($c !== true) return $c;
        $id = absint($req['id']);
        if (!self::platform_co_id($id)) return new WP_Error('tix_co_forbidden', 'Kein gemeinsames Event der Plattform.', ['status' => 403]);
        if (get_post_meta($id, '_tix_platform_co_access', true) !== '1') return new WP_Error('tix_co_no_access', 'Der Veranstalter hat den Zugriff auf dieses Event ausgeschaltet.', ['status' => 403]);
        return true;
    }

    /** GET|POST|DELETE /platform-co/{id}/{route} → Antwort der Plattform (gleiches Format wie die Route dort). */
    public static function rest_co_proxy(WP_REST_Request $req) {
        $pid = self::platform_co_id(absint($req['id']));
        $body = $req->get_json_params();
        $resp = wp_remote_post(self::co_api('/partner/co-events/' . $pid . '/call'), ['timeout' => 25, 'headers' => self::co_headers(),
            'body' => wp_json_encode(['method' => $req->get_method(), 'path' => '/' . ltrim((string) $req['path'], '/'), 'query' => $req->get_query_params() ?: new stdClass(), 'body' => is_array($body) ? $body : new stdClass()])]);
        if (is_wp_error($resp)) return new WP_Error('tix_partner_unreachable', 'Die Plattform ist gerade nicht erreichbar.', ['status' => 502]);
        $code = intval(wp_remote_retrieve_response_code($resp));
        $env = json_decode((string) wp_remote_retrieve_body($resp), true);
        if ($code !== 200 || !is_array($env) || !array_key_exists('status', $env)) {
            return new WP_Error(is_array($env) && !empty($env['code']) ? (string) $env['code'] : 'tix_co_remote_error', is_array($env) && !empty($env['message']) ? (string) $env['message'] : 'Die Plattform hat die Anfrage abgelehnt.', ['status' => in_array($code, [401, 403, 404, 409, 429], true) ? $code : 502]);
        }
        $res = new WP_REST_Response($env['data'], intval($env['status']));
        foreach ((array) ($env['headers'] ?? []) as $h => $v) if (in_array($h, ['Content-Type', 'Content-Disposition'], true)) $res->header($h, (string) $v);
        return $res;
    }

    /** Spiegel nicht lokal ändern (sonst überschreibt der nächste Abgleich die Änderung). */
    public static function guard_co_mirror($response, $handler, WP_REST_Request $req) {
        if ($response instanceof WP_Error || self::multi() || $req->get_method() === 'GET') return $response;
        if (!preg_match('#^/' . preg_quote(self::NS, '#') . '/(?:events|studio/events|pos/events|checkin)/(\d+)(/|$)#', $req->get_route(), $m)) return $response;
        if (!self::platform_co_id(intval($m[1]))) return $response;
        return new WP_Error('tix_co_platform_event', 'Dieses Event gehört ' . (get_post_meta(intval($m[1]), '_tix_platform_co_by', true) ?: 'einem anderen Veranstalter') . ' auf evendis. Änderungen bitte über den gemeinsamen Zugang.', ['status' => 409, 'base' => '/platform-co/' . intval($m[1])]);
    }

    // ═══════════════ WP-CLI ═══════════════

    /**
     * Umzug von der Plattform auf diese Seite.
     *
     * ## OPTIONS
     * <aktion>
     * : importieren | status | abschliessen | co-events
     *
     * [--von=<api>]
     * : REST-Basis der Plattform, z. B. https://evendis.de/wp-json/tixomat/v1
     *
     * [--schluessel=<s>]
     * : Umzugs-Schlüssel aus der Kundenakte
     *
     * [--trocken]
     * : nur zählen, nichts anlegen
     */
    public static function cli($args, $assoc) {
        $a = $args[0] ?? 'status';
        if ($a === 'status') {
            WP_CLI::log(wp_json_encode(['import' => get_option(self::OPT_IMPORT, null), 'map' => array_map('count', array_filter((array) get_option(self::OPT_MAP, []), 'is_array'))], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return;
        }
        if ($a === 'abschliessen') {
            $s = (array) get_option(self::OPT_IMPORT, []);
            if (($s['status'] ?? '') !== 'imported') WP_CLI::error('Noch kein Import.');
            $s['status'] = 'done'; $s['done_at'] = gmdate('c');
            update_option(self::OPT_IMPORT, $s, false);
            WP_CLI::success('Umzug auf dieser Seite abgeschlossen (weitere Importe gesperrt).');
            return;
        }
        if ($a === 'co-events') {
            $r = self::sync_co_events();
            if (is_wp_error($r)) WP_CLI::error($r->get_error_message());
            WP_CLI::success(sprintf('Co-Events: %d neu, %d aktualisiert, %d entfernt.', $r['created'], $r['updated'], $r['removed']));
            return;
        }
        if ($a !== 'importieren') WP_CLI::error('Aktion: importieren | status | abschliessen | co-events');
        if (empty($assoc['von']) || empty($assoc['schluessel'])) WP_CLI::error('--von und --schluessel angeben.');
        $r = self::import((string) $assoc['von'], (string) $assoc['schluessel'], !empty($assoc['trocken']));
        if (is_wp_error($r)) WP_CLI::error($r->get_error_message());
        foreach ($r['counts'] as $k => $v) WP_CLI::log(str_pad($k, 12) . $v);
        foreach ($r['errors'] as $e) WP_CLI::warning($e);
        WP_CLI::log('ERGEBNIS ' . wp_json_encode($r, JSON_UNESCAPED_UNICODE));
    }
}
