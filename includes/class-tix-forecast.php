<?php
/**
 * TIX_Forecast – Verkaufsprognose je Event (M2, Auftrag Marketing/Reichweite/Barrierefreiheit 13.11.2026; ab 1.38.811).
 *
 * Nur eigene Daten. Grundlage ist die Verkaufskurve je Tag bis zum Event-Tag (TIX_Stats_Audience::pace, bezahlte
 * Bestellungen aller Kanäle), die schon die Studio-Statistik „Wie laufen deine nächsten Events?“ nutzt.
 *
 * Rechnung (project(), reine Funktion, ohne WordPress):
 *  - Vergleich (ab 3 abgeschlossenen ähnlichen Events mit je ≥ 20 verkauften Einheiten): Für jedes Vergleichs-Event der
 *    Anteil, der „gleich viele Tage vor dem Event“ schon verkauft war → Hochrechnung = heute verkauft ÷ Anteil.
 *    Prognose = Median, Spanne = 25./75. Perzentil. Verlauf bis zum Event = Median-Anteile (für „ausverkauft am …“).
 *  - Sonst einfache Hochrechnung: Tempo der letzten 7 Tage × verbleibende Tage, Spanne ±30 %, Hinweis „wenig Daten“.
 *  - Begrenzt auf die Kapazität; Ziel optional (Abstand zum Ziel).
 * GET /organizer/stats/forecast?event=<id>[&goal=<n>] (Rechte wie /organizer/stats, evendis nur eigene Events)
 *   → {ok, event_id, event_date, days_left, method: comparison|linear|none, low_data, sold, projected, range{low, high},
 *      capacity|null, sellout{date, days_before}|null, sold_out, goal|null, goal_gap|null, comparisons[{id, title, date, total}],
 *      hints[{code, text, action}]}
 * Rückrechnung: wp tixomat forecast backtest [--days=7] (nur lesen) – mittlere Abweichung auf vergangenen Events.
 */
if (!defined('ABSPATH')) exit;

class TIX_Forecast {

    const NS = 'tixomat/v1';
    const N = 365;            // Tage bis zum Event, die die Kurve abdeckt (davor: „before“)
    const MIN_COMPARISONS = 3;
    const MIN_TOTAL = 20;
    const MAX_COMPARISONS = 8;
    const MIN_SHARE = 0.03;

    public static function init() {
        add_action('rest_api_init', function () {
            register_rest_route(self::NS, '/organizer/stats/forecast', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest'], 'permission_callback' => ['TIX_Studio_Stats', 'perm_manager']]);
        });
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat forecast', [__CLASS__, 'cli']);
    }

    // ═══════════════ Rechnung (rein) ═══════════════

    /** Kumuliert bis einschließlich Index $idx (Kurve {before, tickets[N]}) */
    public static function cum(array $curve, $idx) {
        $t = (array) ($curve['tickets'] ?? []);
        $s = intval($curve['before'] ?? 0);
        for ($i = 0; $i <= $idx && $i < count($t); $i++) $s += intval($t[$i]);
        return $s;
    }

    public static function total(array $curve) { return self::cum($curve, count((array) ($curve['tickets'] ?? [])) - 1); }

    public static function quantile(array $v, $q) {
        $v = array_values($v);
        sort($v);
        $n = count($v);
        if (!$n) return 0.0;
        $pos = ($n - 1) * $q;
        $lo = (int) floor($pos); $hi = (int) ceil($pos);
        return $v[$lo] + ($v[$hi] - $v[$lo]) * ($pos - $lo);
    }

    /**
     * @param array  $target   Kurve des Events {before, tickets[N]} (Index N−1 = Event-Tag)
     * @param int    $days_left Tage bis zum Event (0 = heute)
     * @param array  $comps    Kurven abgeschlossener Vergleichs-Events
     * @param int    $capacity  0 = unbegrenzt
     * @param int|null $goal
     * @return array {method, low_data, sold_units, projected, low, high, path[]|null (kumuliert je Index ab heute)}
     */
    public static function project(array $target, $days_left, array $comps, $capacity = 0, $goal = null) {
        $n = count((array) ($target['tickets'] ?? []));
        $days_left = max(0, intval($days_left));
        $idx = $n - 1 - $days_left;            // Index „heute“
        $sold = $idx >= 0 ? self::cum($target, $idx) : intval($target['before'] ?? 0);
        $out = ['method' => 'none', 'low_data' => true, 'sold_units' => $sold, 'projected' => $sold, 'low' => $sold, 'high' => $sold, 'path' => null, 'comparisons_used' => 0];
        if ($days_left === 0 || $idx >= $n) return $out;
        // Vergleich
        $proj = []; $shares_by_comp = [];
        if ($idx >= 0) foreach ($comps as $c) {
            $tot = self::total($c);
            if ($tot < self::MIN_TOTAL) continue;
            $share = self::cum($c, $idx) / $tot;
            if ($share < self::MIN_SHARE) continue;
            $proj[] = $sold / $share;
            $sh = [];
            for ($k = $idx; $k < $n; $k++) $sh[] = self::cum($c, $k) / $tot;
            $shares_by_comp[] = $sh;
        }
        // Ab 3 Vergleichen (Auftrag M2). Rückrechnung 13.11. mit 1–2 Vergleichen: nicht verlässlich besser als das Tempo.
        if (count($proj) >= self::MIN_COMPARISONS && $sold > 0) {
            $out['method'] = 'comparison'; $out['low_data'] = false; $out['comparisons_used'] = count($proj);
            $out['projected'] = self::quantile($proj, 0.5);
            $out['low'] = self::quantile($proj, 0.25);
            $out['high'] = self::quantile($proj, 0.75);
            // Verlauf: Median der Anteile je Tag, relativ zu heute
            $path = [];
            $base = self::quantile(array_column($shares_by_comp, 0), 0.5);
            for ($j = 0; $j < $n - $idx; $j++) {
                $m = self::quantile(array_column($shares_by_comp, $j), 0.5);
                $path[] = $base > 0 ? $sold * $m / $base : $sold;
            }
            $out['path'] = $path;
        } else {
            // einfache Hochrechnung: Tempo der letzten 7 Tage
            $from = max(0, $idx - 6);
            $v = $idx >= 0 ? (self::cum($target, $idx) - ($from > 0 ? self::cum($target, $from - 1) : intval($target['before'] ?? 0))) / max(1, $idx - $from + 1) : 0.0;
            $out['method'] = 'linear';
            $out['projected'] = $sold + $v * $days_left;
            $out['low'] = $sold + $v * $days_left * 0.7;
            $out['high'] = $sold + $v * $days_left * 1.3;
            $path = [];
            for ($j = 0; $j <= $days_left; $j++) $path[] = $sold + $v * $j;
            $out['path'] = $path;
        }
        if ($capacity > 0) foreach (['projected', 'low', 'high'] as $k) $out[$k] = min($out[$k], $capacity);
        return $out;
    }

    /** Erster Tag (0 = heute) mit Verlauf ≥ Kapazität, sonst null */
    public static function sellout_day($path, $capacity) {
        if (!$capacity || !is_array($path)) return null;
        foreach ($path as $j => $v) if ($v >= $capacity) return $j;
        return null;
    }

    // ═══════════════ Daten ═══════════════

    private static function similarity($eid, $cand, array $cats, $cap) {
        $s = 0;
        $cc = wp_get_post_terms($cand, 'event_category', ['fields' => 'ids']);
        if (!is_wp_error($cc) && $cats && array_intersect($cats, (array) $cc)) $s += 2;
        $d1 = (string) get_post_meta($eid, '_tix_date_start', true); $d2 = (string) get_post_meta($cand, '_tix_date_start', true);
        if ($d1 && $d2 && gmdate('N', strtotime($d1)) === gmdate('N', strtotime($d2))) $s += 1;
        if ($cap && class_exists('TIX_Sales_Figures')) {
            $f = TIX_Sales_Figures::for_events([$cand])['events'][$cand] ?? null;
            if ($f && $f['capacity'] && abs($f['capacity'] - $cap) / max($cap, 1) < 0.5) $s += 1;
        }
        return $s;
    }

    /** Vergangene eigene Events (abgeschlossen vor $before), ähnlichste zuerst → [id => Kurve] */
    public static function comparisons($eid, array $scope, $before, $cap = 0) {
        $past = [];
        foreach ($scope as $id) {
            if (intval($id) === intval($eid)) continue;
            $d = substr((string) get_post_meta($id, '_tix_date_start', true), 0, 10);
            if ($d && $d < $before) $past[intval($id)] = $d;
        }
        arsort($past);
        $past = array_slice($past, 0, 40, true);
        if (!$past) return [];
        $curves = TIX_Stats_Audience::pace(array_keys($past), self::N);
        $cats = wp_get_post_terms($eid, 'event_category', ['fields' => 'ids']);
        $cats = is_wp_error($cats) ? [] : (array) $cats;
        $rank = [];
        foreach ($curves as $id => $c) if (self::total($c) >= self::MIN_TOTAL) $rank[$id] = self::similarity($eid, $id, $cats, $cap) * 1000 + strtotime($past[$id]) / 86400 / 1000;
        arsort($rank);
        $out = [];
        foreach (array_slice(array_keys($rank), 0, self::MAX_COMPARISONS) as $id) $out[$id] = $curves[$id];
        return $out;
    }

    /** Preisphase, die in den nächsten 7 Tagen endet → [name, until] oder null */
    private static function phase_ending($eid, $today) {
        $best = null;
        foreach ((array) get_post_meta($eid, '_tix_ticket_categories', true) as $cat) {
            foreach ((array) ($cat['phases'] ?? []) as $ph) {
                $u = (string) ($ph['until'] ?? '');
                if ($u && $u >= $today && strtotime($u) - strtotime($today) <= 7 * DAY_IN_SECONDS && (!$best || $u < $best[1])) $best = [(string) ($ph['name'] ?? ($cat['name'] ?? 'Preisphase')), $u];
            }
        }
        return $best;
    }

    public static function for_event($eid, $goal = null, $today = null) {
        $today = $today ?: current_time('Y-m-d');
        $date = substr((string) get_post_meta($eid, '_tix_date_start', true), 0, 10);
        $fig = class_exists('TIX_Sales_Figures') ? (TIX_Sales_Figures::for_events([$eid])['events'][$eid] ?? null) : null;
        $sold = intval($fig['tickets'] ?? 0);
        $cap = $fig && empty($fig['unlimited']) ? intval($fig['capacity']) : 0;
        $out = ['ok' => true, 'event_id' => intval($eid), 'event_date' => $date ?: null, 'days_left' => null, 'method' => 'none', 'low_data' => true, 'sold' => $sold,
            'projected' => $sold, 'range' => ['low' => $sold, 'high' => $sold], 'capacity' => $cap ?: null, 'sellout' => null, 'sold_out' => $cap > 0 && $sold >= $cap,
            'goal' => $goal, 'goal_gap' => null, 'comparisons' => [], 'hints' => []];
        if (!$date || $date < $today) { $out['hints'][] = ['code' => 'past', 'text' => 'Das Event ist vorbei.', 'action' => null]; return $out; }
        $days_left = intval(round((strtotime($date) - strtotime($today)) / DAY_IN_SECONDS));
        $out['days_left'] = $days_left;
        $target = TIX_Stats_Audience::pace([$eid], self::N)[$eid] ?? ['before' => 0, 'tickets' => array_fill(0, self::N, 0)];
        $comps = self::comparisons($eid, array_map('intval', TIX_Studio_Stats::scope_ids()), $today, $cap);
        $p = self::project($target, $days_left, $comps, $cap, $goal);
        // Einheiten der Kurve (Positionen) → Tickets laut Verkaufszahlen
        $f = $p['sold_units'] > 0 && $sold > 0 ? $sold / $p['sold_units'] : 1.0;
        $r = function ($x) use ($f, $cap) { $v = (int) round($x * $f); return $cap ? min($v, $cap) : $v; };
        $out['method'] = $p['method']; $out['low_data'] = $p['low_data'];
        $out['projected'] = max($sold, $r($p['projected']));
        $out['range'] = ['low' => max($sold, $r($p['low'])), 'high' => max($sold, $r($p['high']))];
        foreach ($comps as $id => $c) $out['comparisons'][] = ['id' => $id, 'title' => html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'), 'date' => substr((string) get_post_meta($id, '_tix_date_start', true), 0, 10), 'total' => self::total($c)];
        if ($cap && !$out['sold_out'] && is_array($p['path'])) {
            $path = array_map(function ($x) use ($f) { return $x * $f; }, $p['path']);
            $j = self::sellout_day($path, $cap);
            if ($j !== null && $j <= $days_left) $out['sellout'] = ['date' => gmdate('Y-m-d', strtotime($today) + $j * DAY_IN_SECONDS), 'days_before' => $days_left - $j];
        }
        if ($goal) $out['goal_gap'] = $out['projected'] - intval($goal);
        $out['hints'] = self::hints($eid, $out, $today);
        return $out;
    }

    /** Konkrete Hinweise (Kampagne starten, Preisphase verlängern, Push an Follower) */
    public static function hints($eid, array $o, $today) {
        $h = [];
        $date_de = function ($d) { return wp_date('d.m.Y', strtotime($d . ' 12:00:00')); };
        if ($o['low_data'] && $o['method'] !== 'none') $h[] = ['code' => 'low_data', 'text' => 'Wenig Vergleichsdaten (weniger als 3 abgeschlossene Events): einfache Hochrechnung aus dem Tempo der letzten 7 Tage – die Prognose wird mit jedem Event genauer.', 'action' => null];
        if ($o['sold_out']) return array_merge($h, [['code' => 'sold_out', 'text' => 'Ausverkauft.', 'action' => null]]);
        if ($o['sellout']) $h[] = ['code' => 'sellout', 'text' => 'Ausverkauft voraussichtlich am ' . $date_de($o['sellout']['date']) . '.', 'action' => null];
        $behind = ($o['goal'] && $o['goal_gap'] < 0) || (!$o['goal'] && $o['capacity'] && $o['projected'] < 0.6 * $o['capacity']);
        if ($o['goal'] && $o['goal_gap'] < 0) $h[] = ['code' => 'goal_miss', 'text' => 'Ziel ' . intval($o['goal']) . ' wird voraussichtlich mit ' . intval($o['projected']) . ' verfehlt.', 'action' => null];
        elseif ($o['goal']) $h[] = ['code' => 'goal_reached', 'text' => 'Ziel ' . intval($o['goal']) . ' wird voraussichtlich erreicht.', 'action' => null];
        if ($behind && intval($o['days_left']) >= 3) {
            $h[] = ['code' => 'start_campaign', 'text' => 'Kampagne starten: Kurzlink mit QR teilen oder Werbung schalten.', 'action' => 'campaign'];
            $ph = self::phase_ending($eid, $today);
            if ($ph) $h[] = ['code' => 'extend_price_phase', 'text' => 'Preisphase „' . $ph[0] . '“ endet am ' . $date_de($ph[1]) . ' – verlängern oder das Ende bewerben.', 'action' => 'price_phase'];
            $oid = intval(get_post_meta($eid, '_tix_organizer_id', true));
            $fol = $oid && class_exists('TIX_Organizer_Link') ? intval(TIX_Organizer_Link::followers($oid)['total'] ?? 0) : 0;
            if ($fol > 0) $h[] = ['code' => 'push_followers', 'text' => 'Mitteilung an ' . $fol . ' Follower schicken.', 'action' => 'push'];
            elseif (class_exists('TIX_Notifications')) $h[] = ['code' => 'push_followers', 'text' => 'Mitteilung an deine App-Nutzer schicken.', 'action' => 'push'];
        }
        return $h;
    }

    public static function rest(WP_REST_Request $req) {
        $eid = absint($req->get_param('event') ?: $req->get_param('event_id'));
        if (!$eid || get_post_type($eid) !== 'event' || !in_array($eid, array_map('intval', TIX_Studio_Stats::scope_ids()), true)) {
            return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        }
        $goal = $req->get_param('goal') !== null && $req->get_param('goal') !== '' ? absint($req->get_param('goal')) : null;
        if ($goal !== null && ($goal < 1 || $goal > 1000000)) return new WP_Error('tix_invalid_field', 'Ziel 1 bis 1.000.000.', ['status' => 400, 'field' => 'goal']);
        $r = rest_ensure_response(self::for_event($eid, $goal ?: null));
        $r->header('Cache-Control', 'private, max-age=300');
        return $r;
    }

    // ═══════════════ Rückrechnung ═══════════════

    /** Für jedes vergangene Event: Prognose $days Tage vorher (nur Vergleichs-Events davor) gegen das Ist → Liste + mittlere Abweichung */
    public static function backtest(array $ids, $days = 7) {
        $rows = [];
        $curves = TIX_Stats_Audience::pace($ids, self::N);
        $dates = [];
        foreach ($curves as $id => $c) $dates[$id] = $c['date'];
        foreach ($curves as $id => $c) {
            $tot = self::total($c);
            if ($tot < self::MIN_TOTAL || $c['date'] >= current_time('Y-m-d')) continue;
            $comps = [];
            foreach ($curves as $id2 => $c2) if ($id2 !== $id && $dates[$id2] < $c['date']) $comps[$id2] = $c2;
            uksort($comps, function ($a, $b) use ($dates) { return strcmp($dates[$b], $dates[$a]); });
            $comps = array_slice($comps, 0, self::MAX_COMPARISONS, true);
            $p = self::project($c, $days, $comps, 0);
            if ($p['method'] === 'none' || !$p['sold_units']) continue;
            $rows[] = ['id' => $id, 'date' => $c['date'], 'method' => $p['method'], 'actual' => $tot, 'projected' => (int) round($p['projected']), 'error' => ($p['projected'] - $tot) / $tot];
        }
        $cmp = array_filter($rows, function ($r) { return $r['method'] === 'comparison'; });
        $mae = function ($rs) { return $rs ? array_sum(array_map(function ($r) { return abs($r['error']); }, $rs)) / count($rs) : null; };
        return ['rows' => $rows, 'mean_abs_error' => $mae($rows), 'mean_abs_error_comparison' => $mae($cmp), 'n' => count($rows), 'n_comparison' => count($cmp)];
    }

    /** wp tixomat forecast backtest [--days=7] */
    public static function cli($args, $assoc) {
        if (($args[0] ?? '') !== 'backtest') { WP_CLI::error('Aufruf: wp tixomat forecast backtest [--days=7]'); return; }
        $ids = array_map('intval', get_posts(['post_type' => 'event', 'post_status' => ['publish', 'private', 'draft'], 'posts_per_page' => -1, 'fields' => 'ids']));
        $b = self::backtest($ids, max(1, intval($assoc['days'] ?? 7)));
        foreach ($b['rows'] as $r) WP_CLI::line(sprintf('#%d %s %-10s Ist %5d  Prognose %5d  %+.0f %%', $r['id'], $r['date'], $r['method'], $r['actual'], $r['projected'], $r['error'] * 100));
        WP_CLI::line(sprintf('Events: %d (Vergleich %d) · mittlere Abweichung: %s · nur Vergleich: %s', $b['n'], $b['n_comparison'],
            $b['mean_abs_error'] === null ? '–' : round($b['mean_abs_error'] * 100, 1) . ' %', $b['mean_abs_error_comparison'] === null ? '–' : round($b['mean_abs_error_comparison'] * 100, 1) . ' %'));
    }
}

TIX_Forecast::init();
