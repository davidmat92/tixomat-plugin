<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Seatmap_Best – „Bester Platz“ (Saalplan Phase 2, 1.38.671, Doku §54.P2), angelehnt an „Best available“ großer Ticketsysteme.
 *
 *  - Nur in den gewählten Sektionen (Kategorie bzw. Sektion). Gesperrte, gehaltene, verkaufte Plätze und Rollstuhlplätze
 *    (außer wheelchair) sind ausgeschlossen.
 *  - Zusammenhängend: n Plätze nebeneinander in einer Reihe ohne Gang dazwischen. Sonst (split_rows) zwei direkt
 *    hintereinanderliegende Reihen übereinander, mit Hinweis. Nie verstreut.
 *  - Punktwert je Platz 0–1: Nähe zur Mittelachse der Bühne (55 %), Abstand zur „besten Reihe“ (35 %), Sichtwinkel (10 %);
 *    Sektions-Priorität first +0,25 / last −0,25; Single-Seat-Regel −0,5 (ein einzelner freier Platz bleibt neben der Auswahl).
 *  - Gleichstand: mehr freie Plätze in der Reihe, dann näher zur Mitte, dann Reihenfolge im Plan. Deterministisch.
 */
class TIX_Seatmap_Best {

    const BEST_ROW = ['theater' => 0.3, 'banquet' => 0.3, 'u-shape' => 0.25, 'stadium' => 0.25, 'arena' => 0.0, 'club' => 0.0, 'custom' => 0.25];
    const W_CENTER = 0.55;
    const W_DEPTH = 0.35;
    const W_ANGLE = 0.10;
    const PRIO = 0.25;
    const SINGLE = 0.5;
    const SPLIT = 0.3;
    const GAP_FACTOR = 1.6;  // Abstand > 1,6 × üblicher Sitzabstand = Gang
    const MAX_CAND = 400;

    /** „Beste Reihe“ als Anteil der Tiefe (0 = vorn, 0,3 = erstes Drittel) */
    public static function best_row(array $data) {
        if (isset($data['best_row']) && is_numeric($data['best_row'])) return max(0.0, min(1.0, floatval($data['best_row'])));
        return self::BEST_ROW[$data['layout'] ?? 'theater'] ?? 0.25;
    }

    /** Bühnenmitte; ohne Bühne die Mitte über dem Plan */
    public static function stage_point(array $data) {
        foreach ((array) ($data['objects'] ?? []) as $o) if (($o['type'] ?? '') === 'stage') return [floatval($o['x']), floatval($o['y'])];
        $b = class_exists('TIX_Seatmap_Geometry') ? TIX_Seatmap_Geometry::bounds($data) : null;
        return $b ? [($b[0] + $b[2]) / 2, $b[1] - 60] : [0.0, 0.0];
    }

    private static function centroid(array $pts) {
        $n = count($pts);
        if (!$n) return [0.0, 0.0];
        $sx = 0; $sy = 0;
        foreach ($pts as $p) { $sx += $p[0]; $sy += $p[1]; }
        return [$sx / $n, $sy / $n];
    }

    /**
     * Punktwert je Platz → [seat_id => ['score', 'lat', 'depth']] für alle buchbaren Plätze des Plans.
     * Achse: Bühne → Schwerpunkt aller Plätze; in der Arena je Sektion (Tribünen rundum).
     */
    public static function scores(array $data) {
        $stage = self::stage_point($data);
        $arena = ($data['layout'] ?? '') === 'arena';
        $all = [];
        foreach ((array) ($data['sections'] ?? []) as $sec) foreach ((array) ($sec['rows'] ?? []) as $row) foreach ((array) ($row['seats'] ?? []) as $s) {
            if (($s['type'] ?? 'standard') === 'blocked') continue;
            $all[$sec['id']][] = [floatval($s['x']), floatval($s['y']), (string) $s['id']];
        }
        $axis = function ($pts) use ($stage) {
            $c = self::centroid($pts);
            $dx = $c[0] - $stage[0]; $dy = $c[1] - $stage[1];
            $len = sqrt($dx * $dx + $dy * $dy);
            return $len > 0.001 ? [$dx / $len, $dy / $len] : [0.0, 1.0];
        };
        $flat = [];
        foreach ($all as $pts) foreach ($pts as $p) $flat[] = $p;
        $u_all = $axis($flat);
        $raw = [];
        foreach ($all as $sid => $pts) {
            $u = $arena ? $axis($pts) : $u_all;
            foreach ($pts as $p) {
                $vx = $p[0] - $stage[0]; $vy = $p[1] - $stage[1];
                $raw[$p[2]] = ['depth' => $vx * $u[0] + $vy * $u[1], 'lat' => abs($vx * $u[1] - $vy * $u[0]), 'sec' => $sid];
            }
        }
        if (!$raw) return [];
        $dmin = min(array_column($raw, 'depth')); $dmax = max(array_column($raw, 'depth'));
        $lmax = max(1.0, max(array_column($raw, 'lat')));
        $span = max(1.0, $dmax - $dmin);
        $ideal = $dmin + self::best_row($data) * ($dmax - $dmin);
        $out = [];
        foreach ($raw as $id => $r) {
            $c = 1 - $r['lat'] / $lmax;
            $d = 1 - abs($r['depth'] - $ideal) / $span;
            $a = 1 - atan2($r['lat'], max(1.0, $r['depth'] - $dmin + 40)) / (M_PI / 2);
            $out[$id] = ['score' => round(self::W_CENTER * $c + self::W_DEPTH * $d + self::W_ANGLE * $a, 6), 'lat' => $r['lat'], 'depth' => $r['depth']];
        }
        return $out;
    }

    /** Freie, zusammenhängende Läufe einer Reihe (Gänge und belegte Plätze trennen) → [[seat …], …] */
    private static function runs(array $seats, callable $free) {
        $d = [];
        for ($i = 1; $i < count($seats); $i++) $d[] = hypot($seats[$i]['x'] - $seats[$i - 1]['x'], $seats[$i]['y'] - $seats[$i - 1]['y']);
        $sorted = $d; sort($sorted);
        $norm = $sorted ? $sorted[intval(floor((count($sorted) - 1) / 2))] : 0;
        $runs = []; $cur = [];
        foreach ($seats as $i => $s) {
            $gap = $i > 0 && $norm > 0 && $d[$i - 1] > self::GAP_FACTOR * $norm;
            if (!$free($s) || $gap) { if ($cur) $runs[] = $cur; $cur = []; }
            if ($free($s)) $cur[] = $s;
        }
        if ($cur) $runs[] = $cur;
        return $runs;
    }

    /**
     * Beste Plätze finden.
     * $opt: qty, sections (Sektions-IDs; leer = alle), taken (IDs), wheelchair (bool), split_rows (bool, Vorgabe true),
     *       single_seat_rule (bool, Vorgabe true), attempt (0 = bester Vorschlag, 1 = nächster …)
     * → {seats[], split, rows[], section_id, score, attempt, alternatives, message, reason (''|none|no_more)}
     */
    public static function find(array $data, array $opt) {
        $qty = max(1, intval($opt['qty'] ?? 1));
        $want = array_values(array_filter(array_map('strval', (array) ($opt['sections'] ?? []))));
        $taken = array_flip(array_map('strval', (array) ($opt['taken'] ?? [])));
        $wheel = !empty($opt['wheelchair']);
        $split_ok = !array_key_exists('split_rows', $opt) || !empty($opt['split_rows']);
        $single = !array_key_exists('single_seat_rule', $opt) || !empty($opt['single_seat_rule']);
        $attempt = max(0, intval($opt['attempt'] ?? 0));
        $sc = self::scores($data);
        $free = function ($s) use ($taken, $wheel) {
            $t = $s['type'] ?? 'standard';
            return $t !== 'blocked' && !isset($taken[(string) $s['id']]) && ($t !== 'wheelchair' || $wheel);
        };
        $prio = function ($sec) { $p = $sec['priority'] ?? 'normal'; return $p === 'first' ? self::PRIO : ($p === 'last' ? -self::PRIO : 0.0); };
        $row_label = function ($row) { return (string) (($row['label'] ?? '') !== '' ? $row['label'] : 'Reihe ' . ($row['id'] ?? '')); };
        $mean = function (array $ids, $k) use ($sc) { $s = 0; foreach ($ids as $id) $s += $sc[$id][$k] ?? 0; return $ids ? $s / count($ids) : 0; };

        // Blöcke je Reihe (für einzelne Reihen und für die Verteilung auf zwei Reihen)
        $blocks_of = function (array $run, $n, $row_free) use ($single, $sc, $mean, $wheel) {
            $out = [];
            $L = count($run);
            for ($b = 0; $b + $n <= $L; $b++) {
                $ids = array_map(function ($s) { return (string) $s['id']; }, array_slice($run, $b, $n));
                if ($wheel && !array_filter(array_slice($run, $b, $n), function ($s) { return ($s['type'] ?? '') === 'wheelchair'; })) continue;
                $left = $b; $right = $L - $b - $n;
                $pen = ($single && ($left === 1 || $right === 1)) ? self::SINGLE : 0.0;
                $xs = array_map(function ($s) { return floatval($s['x']); }, array_slice($run, $b, $n));
                $out[] = ['ids' => $ids, 'score' => $mean($ids, 'score') - $pen, 'lat' => $mean($ids, 'lat'), 'cx' => array_sum($xs) / $n, 'row_free' => $row_free, 'single' => $pen > 0];
            }
            return $out;
        };

        $cands = []; $order = 0; $secs = [];
        foreach ((array) ($data['sections'] ?? []) as $sec) {
            if ($want && !in_array((string) $sec['id'], $want, true)) continue;
            $secs[] = $sec;
            foreach ((array) ($sec['rows'] ?? []) as $row) {
                $seats = array_values((array) ($row['seats'] ?? []));
                $row_free = count(array_filter($seats, $free));
                foreach (self::runs($seats, $free) as $run) {
                    foreach ($blocks_of($run, $qty, $row_free) as $bk) {
                        $cands[] = ['seats' => $bk['ids'], 'score' => round($bk['score'] + $prio($sec), 6), 'row_free' => $row_free, 'lat' => $bk['lat'], 'order' => $order++,
                            'split' => false, 'rows' => [$row_label($row)], 'section_id' => (string) $sec['id']];
                    }
                }
            }
        }
        if (!$cands && $split_ok && $qty >= 2) {
            $a = (int) ceil($qty / 2); $b = $qty - $a;
            foreach ($secs as $sec) {
                $rows = array_values((array) ($sec['rows'] ?? []));
                for ($r = 0; $r + 1 < count($rows); $r++) {
                    $sp = self::spacing($rows[$r]);
                    $one = []; $two = [];
                    $f1 = count(array_filter((array) $rows[$r]['seats'], $free)); $f2 = count(array_filter((array) $rows[$r + 1]['seats'], $free));
                    foreach (self::runs(array_values((array) $rows[$r]['seats']), $free) as $run) $one = array_merge($one, $blocks_of($run, $a, $f1));
                    foreach (self::runs(array_values((array) $rows[$r + 1]['seats']), $free) as $run) $two = array_merge($two, $blocks_of($run, $b, $f2));
                    foreach ($one as $x) foreach ($two as $y) {
                        if (abs($x['cx'] - $y['cx']) > 1.5 * $sp) continue; // übereinander
                        $s = ($x['score'] * $a + $y['score'] * $b) / $qty - self::SPLIT + $prio($sec);
                        $cands[] = ['seats' => array_merge($x['ids'], $y['ids']), 'score' => round($s, 6), 'row_free' => $f1 + $f2, 'lat' => ($x['lat'] + $y['lat']) / 2, 'order' => $order++,
                            'split' => true, 'rows' => [$row_label($rows[$r]), $row_label($rows[$r + 1])], 'section_id' => (string) $sec['id']];
                        if (count($cands) > self::MAX_CAND * 4) break 2;
                    }
                }
            }
        }
        $res = ['seats' => [], 'split' => false, 'rows' => [], 'section_id' => null, 'score' => null, 'attempt' => $attempt, 'alternatives' => 0, 'message' => '', 'reason' => 'none'];
        if (!$cands) {
            $res['message'] = 'Nicht genügend zusammenhängende freie Plätze. Bitte wähle selbst oder weniger Plätze.';
            return $res;
        }
        usort($cands, function ($x, $y) {
            if (abs($x['score'] - $y['score']) > 1e-6) return $x['score'] < $y['score'] ? 1 : -1;
            if ($x['row_free'] !== $y['row_free']) return $y['row_free'] <=> $x['row_free'];
            if (abs($x['lat'] - $y['lat']) > 1e-6) return $x['lat'] <=> $y['lat'];
            return $x['order'] <=> $y['order'];
        });
        // Verschiedene Vorschläge: ohne gemeinsame Plätze
        $picked = []; $used = [];
        foreach ($cands as $c) {
            $hit = false;
            foreach ($c['seats'] as $id) if (isset($used[$id])) { $hit = true; break; }
            if ($hit) continue;
            $picked[] = $c;
            foreach ($c['seats'] as $id) $used[$id] = true;
            if (count($picked) >= 50) break;
        }
        if ($attempt >= count($picked)) {
            $res['reason'] = 'no_more'; $res['alternatives'] = 0;
            $res['message'] = 'Keine weiteren Vorschläge. Bitte wähle selbst.';
            return $res;
        }
        $c = $picked[$attempt];
        return ['seats' => $c['seats'], 'split' => $c['split'], 'rows' => $c['rows'], 'section_id' => $c['section_id'], 'score' => round($c['score'], 3),
            'attempt' => $attempt, 'alternatives' => count($picked) - 1 - $attempt,
            'message' => $c['split'] ? sprintf('Nebeneinander war nichts mehr frei. Deine Plätze liegen in zwei Reihen direkt hintereinander (%s und %s).', $c['rows'][0], $c['rows'][1]) : '',
            'reason' => ''];
    }

    /** Üblicher Sitzabstand einer Reihe (Median) */
    private static function spacing(array $row) {
        $s = array_values((array) ($row['seats'] ?? []));
        $d = [];
        for ($i = 1; $i < count($s); $i++) $d[] = hypot($s[$i]['x'] - $s[$i - 1]['x'], $s[$i]['y'] - $s[$i - 1]['y']);
        if (!$d) return 30.0;
        sort($d);
        return max(1.0, $d[intval(floor((count($d) - 1) / 2))]);
    }
}
