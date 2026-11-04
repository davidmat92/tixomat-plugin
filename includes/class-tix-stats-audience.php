<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Stats_Audience – Kennzahlen-Katalog Besucher → Kauf → Einlass (Statistik Stufe 1, docs/plan-statistik-marketing.md §3).
 *
 *   GET /organizer/stats/audience?from&to[&event_id][&organizer (nur Admin, evendis)]
 *   → {ok, range, scope, kpis, previous, funnel[], by_source[], by_campaign[], app, visits_enabled, modules, matomo}
 *     mit event_id zusätzlich series[{date, views, app_views, orders}] (je Tag, höchstens 400 Tage) für den Verlauf bis zum Event
 *   GET /organizer/stats/events?ids=1,2,…[&days=14] (höchstens 100 Events, nur eigene) – Sammelabfrage für die Event-Liste:
 *   → {ok, days, events[{id, event_views, app_views, checkout, web_orders, conversion, views_days[], orders_days[]}]}
 *     Summen seit Verkaufsbeginn (ohne Zeitraum), die Reihen = die letzten `days` Tage (bis heute).
 *
 * Eine Zahl, eine Rechnung: Verkauf aus TIX_Sales_Figures, Besucher aus tix_web_stats (Website) bzw. tix_web_stats_dim
 * (Quelle/Kampagne, App), Herkunft der Bestellungen aus TIX_Order_Attribution. Rechte wie /organizer/stats (Inhaber,
 * Team-Admin, Admin; evendis nur eigene Events). Die Apps lesen dieselbe Route (F7: nur lesend, schlanker).
 *
 * Begriffe (Studio-(i) in content/hilfe.ts):
 *  - website_views   Seitenaufrufe der Website (evendis: Aufrufe der Veranstalterseite)
 *  - visits          geschätzte Besuche (nur mit Schalter tix_web_visits, sonst null)
 *  - event_views     Aufrufe der Event-Seiten
 *  - buyers          Käufer = bezahlte Bestellungen mit unterschiedlicher E-Mail (alle Kanäle)
 *  - ticket_guests   verkaufte gültige Tickets (alle Kanäle)
 *  - guests_on_site  Gäste vor Ort = Check-ins im Zeitraum (Tickets + Gästeliste)
 *  - conversion      bezahlte Website-Bestellungen ÷ Event-Aufrufe (in %)
 * Trichter (Website): view → event → checkout (Kasse geöffnet) → quote (Tickets gewählt, weiter zu „Deine Daten“) → order (angelegt)
 * → purchase (bezahlt) → checkin (mit Einlass). „Kasse geöffnet“ behält seine Bedeutung von vor Stufe 1 (Vergleich mit alten Zahlen).
 * Kleine Gruppen: Quellen/Kampagnen mit weniger als 3 Aufrufen und ohne Kauf werden zu „weitere“ zusammengefasst.
 */
class TIX_Stats_Audience {

    const NS = 'tixomat/v1';
    const PAID = ['completed', 'processing'];
    const MIN_GROUP = 3;
    const FUNNEL = [
        'view' => 'Seitenaufrufe', 'event' => 'Event-Seite', 'checkout' => 'Kasse geöffnet', 'quote' => 'Tickets gewählt',
        'order' => 'Bestellung angelegt', 'purchase' => 'Bezahlt', 'checkin' => 'Eingecheckt',
    ];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        register_rest_route(self::NS, '/organizer/stats/audience', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest'], 'permission_callback' => ['TIX_Studio_Stats', 'perm_manager']]);
        register_rest_route(self::NS, '/organizer/stats/events', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_events'], 'permission_callback' => ['TIX_Studio_Stats', 'perm_manager']]);
    }

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }

    private static function bad($field, $msg) { return new WP_Error('tix_invalid_field', $msg, ['status' => 400, 'field' => $field]); }

    private static function date_ok($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; }

    public static function rest(WP_REST_Request $req) {
        $to = (string) ($req->get_param('to') ?: current_time('Y-m-d'));
        $from = (string) ($req->get_param('from') ?: gmdate('Y-m-d', strtotime($to) - 29 * DAY_IN_SECONDS));
        if (!self::date_ok($from)) return self::bad('from', 'Bitte ein Datum im Format JJJJ-MM-TT angeben.');
        if (!self::date_ok($to)) return self::bad('to', 'Bitte ein Datum im Format JJJJ-MM-TT angeben.');
        if ($from > $to) return self::bad('from', '„Von“ liegt nach „Bis“.');
        $days = intval(round((strtotime($to) - strtotime($from)) / DAY_IN_SECONDS)) + 1;
        if ($days > 3660) return self::bad('from', 'Höchstens zehn Jahre auf einmal.');

        // Bereich: evendis-Veranstalter = eigener Veranstalter; Admin optional ?organizer=; Einzel-Seite = ganze Seite
        $org = 0;
        if (self::multi()) {
            $org = current_user_can('manage_options') ? max(0, intval($req->get_param('organizer'))) : intval(TIX_App_Scope::organizer_id_for_user());
            if ($org && get_post_type($org) !== 'tix_organizer') return new WP_Error('not_found', 'Veranstalter nicht gefunden.', ['status' => 404]);
        }
        $eid = absint($req->get_param('event_id'));
        if ($eid) {
            if (get_post_type($eid) !== 'event' || !TIX_REST_API::can_access_event($eid)) return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
            $ids = [$eid];
        } else {
            $ids = TIX_Studio_Stats::scope_ids();
            if ($org && current_user_can('manage_options')) {
                $mine = array_map('intval', get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => [TIX_App_Scope::event_meta_clause($org)]]));
                $ids = array_values(array_intersect($ids, $mine));
            }
        }
        $scope = ['organizer' => $org ?: null, 'event_id' => $eid ?: null, 'view' => $org ? 'organizer_page' : 'site'];

        $pto = gmdate('Y-m-d', strtotime($from) - DAY_IN_SECONDS);
        $pfrom = gmdate('Y-m-d', strtotime($pto) - ($days - 1) * DAY_IN_SECONDS);
        $cur = self::compute($ids, $org, $eid, $from, $to, true);
        $prev = self::compute($ids, $org, $eid, $pfrom, $pto, false);
        $mk = class_exists('TIX_Features') && isset(TIX_Features::FEATURES['marketing']) ? TIX_Features::on('marketing', $org) : false;

        return rest_ensure_response([
            'ok' => true,
            'range' => ['from' => $from, 'to' => $to, 'days' => $days, 'previous' => ['from' => $pfrom, 'to' => $pto]],
            'scope' => $scope,
            'kpis' => $cur['kpis'],
            'previous' => $prev['kpis'],
            'funnel' => $cur['funnel'],
            'by_source' => $cur['by_source'],
            'by_campaign' => $cur['by_campaign'],
            'app' => $cur['app'],
            'series' => $eid && $days <= 400 ? self::series($eid, $from, $to) : null,
            'visits_enabled' => TIX_Web_Stats::visits_on(),
            'modules' => ['marketing' => (bool) $mk],
            // F2: Matomo-Zahlen kommen später hierher (eigene Instanz stats.evendis.de) – Platz für das Studio
            'matomo' => ['available' => false, 'connected' => false],
        ]);
    }

    /** Bezahlte Bestellungen je Event: [event_id => [order_id, …]] (optional nur ab $since, Ortszeit) */
    private static function paid_by_event(array $ids, $since = '') {
        global $wpdb;
        $out = [];
        if (!$ids || !class_exists('TIX_Order')) return $out;
        $t = TIX_Order::table_name(); $ti = TIX_Order::items_table_name();
        foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
            $w = $since !== '' ? $wpdb->prepare(' AND o.date_created >= %s', $since) : '';
            foreach ((array) $wpdb->get_results("SELECT DISTINCT i.event_id, o.id, DATE(o.date_created) d FROM $t o INNER JOIN $ti i ON i.order_id = o.id WHERE i.event_id IN (" . implode(',', $chunk) . ") AND o.status IN ('completed','processing')$w") as $r) {
                $out[intval($r->event_id)][intval($r->id)] = (string) $r->d;
            }
        }
        return $out;
    }

    /** GET /organizer/stats/events?ids=…&days=14 – kompakte Kennzahlen für die Event-Liste in einer Abfrage */
    public static function rest_events(WP_REST_Request $req) {
        global $wpdb;
        $want = array_values(array_unique(array_filter(array_map('absint', explode(',', (string) $req->get_param('ids'))))));
        if (!$want) return self::bad('ids', 'Bitte Event-IDs angeben.');
        if (count($want) > 100) return self::bad('ids', 'Höchstens 100 Events auf einmal.');
        $days = max(1, min(60, intval($req->get_param('days') ?: 14)));
        $ids = array_values(array_intersect($want, TIX_Studio_Stats::scope_ids()));
        $today = current_time('Y-m-d');
        $from = gmdate('Y-m-d', strtotime($today) - ($days - 1) * DAY_IN_SECONDS);
        $axis = [];
        for ($i = 0; $i < $days; $i++) $axis[gmdate('Y-m-d', strtotime($from) + $i * DAY_IN_SECONDS)] = $i;
        $ev = [];
        foreach ($ids as $id) $ev[$id] = ['id' => $id, 'event_views' => 0, 'app_views' => 0, 'checkout' => 0, 'web_orders' => 0, 'conversion' => null, 'views_days' => array_fill(0, $days, 0), 'orders_days' => array_fill(0, $days, 0)];
        if ($ids) {
            $t = TIX_Web_Stats::table(); $d = TIX_Web_Stats::dim_table();
            foreach (array_chunk($ids, 500) as $chunk) {
                $in = implode(',', $chunk);
                foreach ((array) $wpdb->get_results("SELECT ref, step, SUM(n) n FROM $t WHERE step IN ('event','checkout') AND ref IN ($in) GROUP BY ref, step") as $r) {
                    $ev[intval($r->ref)][$r->step === 'event' ? 'event_views' : 'checkout'] += intval($r->n);
                }
                foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT ref, day, SUM(n) n FROM $t WHERE step = 'event' AND day >= %s AND ref IN ($in) GROUP BY ref, day", $from)) as $r) {
                    if (isset($axis[(string) $r->day])) $ev[intval($r->ref)]['views_days'][$axis[(string) $r->day]] += intval($r->n);
                }
                foreach ((array) $wpdb->get_results("SELECT event_id, SUM(n) n FROM $d WHERE channel = 'app' AND step = 'event' AND event_id IN ($in) GROUP BY event_id") as $r) {
                    $ev[intval($r->event_id)]['app_views'] += intval($r->n);
                }
            }
            $paid = self::paid_by_event($ids);
            $all = [];
            foreach ($paid as $os) foreach ($os as $oid => $_) $all[$oid] = 1;
            $ch = $all ? TIX_Studio_Stats::channels(array_keys($all)) : [];
            foreach ($paid as $eid => $os) {
                foreach ($os as $oid => $day) {
                    if (isset($axis[$day])) $ev[$eid]['orders_days'][$axis[$day]]++;
                    if (in_array($ch[$oid] ?? '', ['web', 'link'], true)) $ev[$eid]['web_orders']++;
                }
            }
            foreach ($ev as &$x) $x['conversion'] = $x['event_views'] > 0 ? round($x['web_orders'] / $x['event_views'] * 100, 2) : null;
            unset($x);
        }
        // Reihenfolge wie angefragt; fremde/unbekannte IDs fehlen einfach
        $out = [];
        foreach ($want as $id) if (isset($ev[$id])) $out[] = $ev[$id];
        $res = rest_ensure_response(['ok' => true, 'days' => $days, 'from' => $from, 'to' => $today, 'events' => $out]);
        $res->header('Cache-Control', 'private, max-age=60');
        return $res;
    }

    /** Verlauf eines Events je Tag: Aufrufe der Event-Seite (Website), App-Aufrufe, bezahlte Bestellungen (alle Kanäle) */
    public static function series($eid, $from, $to) {
        global $wpdb;
        $eid = intval($eid);
        $rows = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t += DAY_IN_SECONDS) $rows[gmdate('Y-m-d', $t)] = ['date' => gmdate('Y-m-d', $t), 'views' => 0, 'app_views' => 0, 'orders' => 0];
        $w = TIX_Web_Stats::table(); $d = TIX_Web_Stats::dim_table();
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT day, SUM(n) n FROM $w WHERE step = 'event' AND ref = %d AND day BETWEEN %s AND %s GROUP BY day", $eid, $from, $to)) as $r) if (isset($rows[$r->day])) $rows[$r->day]['views'] += intval($r->n);
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT day, SUM(n) n FROM $d WHERE channel = 'app' AND step = 'event' AND event_id = %d AND day BETWEEN %s AND %s GROUP BY day", $eid, $from, $to)) as $r) if (isset($rows[$r->day])) $rows[$r->day]['app_views'] += intval($r->n);
        foreach (self::paid_by_event([$eid], $from . ' 00:00:00')[$eid] ?? [] as $day) if (isset($rows[$day])) $rows[$day]['orders']++;
        return array_values($rows);
    }

    /** Alle Zahlen für einen Zeitraum; $detail = Trichter/Quellen mitrechnen */
    public static function compute(array $ids, $org, $eid, $from, $to, $detail) {
        $hits = self::web_hits($ids, $org, $eid, $from, $to);
        $f = TIX_Sales_Figures::for_events($ids, $from, $to);
        $paid_all = [];
        foreach ($f['rows'] as $r) if ($r->money['paid']) $paid_all[intval($r->id)] = 1;
        $orders = self::orders($ids, $from, $to);
        $web = array_filter($orders, function ($o) { return in_array($o['channel'], ['web', 'link'], true); });
        $web_paid = array_filter($web, function ($o) { return in_array($o['status'], self::PAID, true); });
        $kpis = [
            'website_views' => $eid ? null : $hits['view'],
            'visits' => TIX_Web_Stats::visits_on() && !$eid ? $hits['visit'] : null,
            'event_views' => $hits['event'],
            'buyers' => self::buyers(array_keys($paid_all)),
            'ticket_guests' => intval($f['totals']['tickets']),
            'guests_on_site' => self::checkins($ids, $from, $to),
            'orders' => intval($f['totals']['orders']),
            'revenue' => round(floatval($f['totals']['revenue']), 2),
            'web_orders' => count($web_paid),
            'conversion' => $hits['event'] > 0 ? round(count($web_paid) / $hits['event'] * 100, 2) : null,
            'checkout_rate' => $hits['checkout'] > 0 ? round(count($web_paid) / $hits['checkout'] * 100, 1) : null,
        ];
        if (!$detail) return ['kpis' => $kpis];
        $checked = self::orders_with_checkin($ids, array_keys($web_paid));
        $funnel = [];
        $vals = ['view' => $eid ? null : $hits['view'], 'event' => $hits['event'], 'checkout' => $hits['checkout'], 'quote' => $hits['quote'],
            'order' => count($web), 'purchase' => count($web_paid), 'checkin' => count($checked)];
        foreach (self::FUNNEL as $k => $label) $funnel[] = ['key' => $k, 'label' => $label, 'n' => $vals[$k]];
        list($by_source, $by_campaign) = self::origins($ids, $org, $eid, $from, $to, $web_paid, $f);
        return ['kpis' => $kpis, 'funnel' => $funnel, 'by_source' => $by_source, 'by_campaign' => $by_campaign, 'app' => self::app_hits($ids, $from, $to)];
    }

    /** Website-Schritte aus tix_web_stats (mit Verlauf vor Stufe 1) */
    private static function web_hits(array $ids, $org, $eid, $from, $to) {
        global $wpdb;
        $t = TIX_Web_Stats::table();
        $out = ['view' => 0, 'visit' => 0, 'event' => 0, 'quote' => 0, 'checkout' => 0];
        if (!$eid) {
            $rows = $org
                ? $wpdb->get_results($wpdb->prepare("SELECT step, SUM(n) n FROM $t WHERE day BETWEEN %s AND %s AND step IN ('oview','ovisit') AND ref = %d GROUP BY step", $from, $to, $org))
                : $wpdb->get_results($wpdb->prepare("SELECT step, SUM(n) n FROM $t WHERE day BETWEEN %s AND %s AND step IN ('view','visit') AND ref = 0 GROUP BY step", $from, $to));
            foreach ((array) $rows as $r) $out[in_array($r->step, ['view', 'oview'], true) ? 'view' : 'visit'] += intval($r->n);
        }
        foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
            if (!$chunk) continue;
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT step, SUM(n) n FROM $t WHERE day BETWEEN %s AND %s AND step IN ('event','quote','checkout') AND ref IN (" . implode(',', $chunk) . ") GROUP BY step", $from, $to)) as $r) $out[$r->step] += intval($r->n);
        }
        return $out;
    }

    /** App-Treffer (nur Dimensionen, ab Stufe 1) */
    private static function app_hits(array $ids, $from, $to) {
        global $wpdb;
        $out = ['event_views' => 0, 'quote' => 0, 'checkout' => 0];
        $d = TIX_Web_Stats::dim_table();
        foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
            if (!$chunk) continue;
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT step, SUM(n) n FROM $d WHERE day BETWEEN %s AND %s AND channel = 'app' AND step IN ('event','quote','checkout') AND event_id IN (" . implode(',', $chunk) . ") GROUP BY step", $from, $to)) as $r) {
                $out[$r->step === 'event' ? 'event_views' : $r->step] += intval($r->n);
            }
        }
        return $out;
    }

    /** Bestellungen (alle Zustände) der Events im Zeitraum: [id => {status, channel, email}] */
    private static function orders(array $ids, $from, $to) {
        global $wpdb;
        if (!$ids || !class_exists('TIX_Order')) return [];
        $t = TIX_Order::table_name(); $ti = TIX_Order::items_table_name();
        $rows = [];
        foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT DISTINCT o.id, o.status FROM $t o INNER JOIN $ti i ON i.order_id = o.id WHERE i.event_id IN (" . implode(',', $chunk) . ") AND o.date_created BETWEEN %s AND %s AND o.status NOT IN ('trash','draft')", $from . ' 00:00:00', $to . ' 23:59:59')) as $r) $rows[intval($r->id)] = ['status' => (string) $r->status];
        }
        if (!$rows) return [];
        foreach (TIX_Studio_Stats::channels(array_keys($rows)) as $id => $ch) if (isset($rows[$id])) $rows[$id]['channel'] = $ch;
        return $rows;
    }

    /** Bestellungen mit mindestens einem eingecheckten Ticket */
    private static function orders_with_checkin(array $ids, array $order_ids) {
        if (!$order_ids) return [];
        $want = array_flip(array_map('intval', $order_ids));
        $out = [];
        foreach (TIX_Sales_Figures::ticket_rows($ids) as $tk) if ($tk['checked'] && isset($want[$tk['oid']])) $out[$tk['oid']] = 1;
        return array_keys($out);
    }

    /** Käufer = unterschiedliche E-Mail-Adressen bezahlter Bestellungen */
    private static function buyers(array $order_ids) {
        global $wpdb;
        if (!$order_ids) return 0;
        $mails = [];
        foreach (array_chunk(array_map('intval', $order_ids), 500) as $chunk) {
            foreach ((array) $wpdb->get_col("SELECT LOWER(TRIM(billing_email)) FROM " . TIX_Order::table_name() . " WHERE id IN (" . implode(',', $chunk) . ")") as $m) if ($m !== '') $mails[$m] = 1;
        }
        return count($mails);
    }

    /** Gäste vor Ort: Check-ins im Zeitraum (Ticket-Check-ins + Gästeliste, Begleitung zählt mit) */
    public static function checkins(array $ids, $from, $to) {
        global $wpdb;
        $n = 0;
        $lo = $from . ' 00:00:00'; $hi = $to . ' 23:59:59';
        foreach (array_chunk(array_map('intval', $ids), 300) as $chunk) {
            if (!$chunk) continue;
            $in = "'" . implode("','", $chunk) . "'";
            // Zeitstempel als Ortszeit oder ISO: die ersten 19 Zeichen reichen für den Tagesvergleich
            $n += intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} ev ON ev.post_id = p.ID AND ev.meta_key = '_tix_ticket_event_id' AND ev.meta_value IN ($in)
                 INNER JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_tix_ticket_checkin_time'
                 WHERE p.post_type = 'tix_ticket' AND t.meta_value <> '' AND REPLACE(LEFT(t.meta_value, 19), 'T', ' ') BETWEEN %s AND %s", $lo, $hi)));
            foreach ($chunk as $eid) {
                $g = get_post_meta($eid, '_tix_guest_list', true);
                foreach (is_array($g) ? $g : [] as $x) {
                    $tm = str_replace('T', ' ', substr((string) ($x['checkin_time'] ?? ''), 0, 19));
                    if ($tm === '' || $tm < $lo || $tm > $hi) continue;
                    $exp = 1 + intval($x['plus'] ?? 0);
                    $n += isset($x['checked_in_count']) ? min(intval($x['checked_in_count']), $exp) : $exp;
                }
            }
        }
        return $n;
    }

    /** Quellen und Kampagnen: Aufrufe der Event-Seiten (Dimensionen) + bezahlte Website-Bestellungen (Herkunft) */
    private static function origins(array $ids, $org, $eid, $from, $to, array $web_paid, array $f) {
        global $wpdb;
        $d = TIX_Web_Stats::dim_table();
        $src = []; $cmp = [];
        $add = function (&$bucket, $key, $label, $k, $v) {
            if (!isset($bucket[$key])) $bucket[$key] = $label + ['views' => 0, 'orders' => 0, 'tickets' => 0, 'revenue' => 0.0];
            $bucket[$key][$k] += $v;
        };
        foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
            if (!$chunk) continue;
            $rows = $wpdb->get_results($wpdb->prepare("SELECT source, medium, campaign, SUM(n) n FROM $d WHERE day BETWEEN %s AND %s AND channel = 'web' AND step = 'event' AND event_id IN (" . implode(',', $chunk) . ") GROUP BY source, medium, campaign", $from, $to));
            foreach ((array) $rows as $r) {
                $s = (string) $r->source ?: 'direct';
                $add($src, $s . '|' . $r->medium, ['source' => $s, 'medium' => (string) $r->medium], 'views', intval($r->n));
                if ((string) $r->campaign !== '') $add($cmp, $r->campaign . '|' . $s, ['campaign' => (string) $r->campaign, 'source' => $s], 'views', intval($r->n));
            }
        }
        if ($web_paid) {
            $attr = TIX_Studio_Stats::attribution(array_keys($web_paid));
            $rev = [];
            foreach ($f['rows'] as $r) if (isset($web_paid[intval($r->id)])) $rev[intval($r->id)] = ($rev[intval($r->id)] ?? 0.0) + $r->money['net'];
            foreach (array_keys($web_paid) as $oid) {
                $a = $attr[$oid] ?? [];
                $s = TIX_Web_Stats::norm($a['source'] ?? '') ?: 'direct';
                $m = TIX_Web_Stats::norm($a['medium'] ?? '');
                $c = TIX_Web_Stats::norm($a['campaign'] ?? '');
                $tk = intval($f['order_tickets'][$oid] ?? 0); $rv = floatval($rev[$oid] ?? 0);
                foreach (['orders' => 1, 'tickets' => $tk, 'revenue' => $rv] as $k => $v) {
                    $add($src, $s . '|' . $m, ['source' => $s, 'medium' => $m], $k, $v);
                    if ($c !== '') $add($cmp, $c . '|' . $s, ['campaign' => $c, 'source' => $s], $k, $v);
                }
            }
        }
        return [self::finish($src, ['source' => 'weitere', 'medium' => '']), array_slice(self::finish($cmp, ['campaign' => 'weitere', 'source' => '']), 0, 20)];
    }

    /** Conversion je Zeile, kleine Gruppen zusammenfassen, sortieren */
    private static function finish(array $rows, array $other_label) {
        $out = []; $other = null;
        foreach ($rows as $r) {
            if ($r['views'] < self::MIN_GROUP && $r['orders'] === 0) {
                if (!$other) $other = $other_label + ['views' => 0, 'orders' => 0, 'tickets' => 0, 'revenue' => 0.0, 'grouped' => true];
                $other['views'] += $r['views'];
                continue;
            }
            $out[] = $r;
        }
        if ($other && $other['views'] > 0) $out[] = $other;
        foreach ($out as &$r) {
            $r['revenue'] = round($r['revenue'], 2);
            $r['conversion'] = $r['views'] > 0 ? round($r['orders'] / $r['views'] * 100, 2) : null;
        }
        unset($r);
        usort($out, function ($a, $b) {
            if (!empty($a['grouped']) !== !empty($b['grouped'])) return empty($a['grouped']) ? -1 : 1;
            return $b['revenue'] <=> $a['revenue'] ?: $b['views'] <=> $a['views'];
        });
        return $out;
    }
}

TIX_Stats_Audience::init();
