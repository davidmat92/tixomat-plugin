<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Seatmap_Geometry – echte Koordinaten für Saalpläne (Saalplan Phase 1, 1.38.659).
 *
 * Ein Saalplan besteht aus Sektionen. Jede Sektion kann einen **parametrischen Block** tragen (`block`);
 * daraus erzeugt der Generator deterministisch alle Sitze mit x/y und IDs `Sektion_Reihe_Platz`.
 * Ohne Block bleiben die gelieferten Sitze erhalten; fehlen ihre Koordinaten (alter WP-Editor speicherte
 * x:0,y:0), rechnet `ensure_coords()` sie einmalig nach (Raster je Sektion, Lage nach `position`).
 *
 * Block (alle Felder optional, Einheit = Punkte im Plan):
 *   shape          rows | tables                       (Vorgabe rows)
 *   origin {x,y}   Mitte der ersten Reihe bzw. erster Tisch (Vorgabe: automatisch untereinander)
 *   rows           Anzahl Reihen (1–100)
 *   seats          Plätze je Reihe: Zahl oder Liste je Reihe (1–200)
 *   seat_spacing   Abstand der Plätze (Vorgabe 26)       row_spacing  Abstand der Reihen (Vorgabe 30)
 *   curve          0 = gerade … 1 = stark gebogen (Bogen zur Bühne hin)
 *   angle          Drehung der Sektion in Grad (um origin)
 *   gaps           [{after: Platznummer, width: Plätze}] – Gänge innerhalb der Reihe
 *   stagger        true = jede zweite Reihe um einen halben Platz versetzt
 *   row_labels     alpha | numeric                       row_start  „A“ bzw. 1
 *   seat_start     erste Platznummer (Vorgabe 1)          numbering  ltr | rtl
 *   types          {Sitz-ID: vip|wheelchair|blocked}      (Pinsel)
 *   Tische: tables (Anzahl), seats_per_table, table_shape round|rect, cols, table_spacing
 *
 * Objekte ohne Sitze (Bühne, Stehbereich mit Kapazität, Beschriftung) stehen in `objects`.
 */
class TIX_Seatmap_Geometry {

    const SEAT = 26;
    const ROW = 30;
    const PAD = 40;
    const MAX_SEATS = 20000;
    const TYPES = ['standard', 'vip', 'wheelchair', 'blocked'];

    // ═══════════════ Block → Sitze ═══════════════

    private static function num($v, $def, $min, $max) {
        $v = is_numeric($v) ? floatval($v) : $def;
        return max($min, min($max, $v));
    }

    /** Reihenbezeichnung: A…Z, AA… bzw. Zahl */
    public static function row_label($i, $mode = 'alpha', $start = null) {
        if ($mode === 'numeric') return (string) (intval($start ?: 1) + $i);
        $s = is_string($start) && preg_match('/^[A-Z]{1,2}$/', strtoupper($start)) ? strtoupper($start) : 'A';
        $n = 0;
        foreach (str_split($s) as $ch) $n = $n * 26 + (ord($ch) - 64);
        $n += $i;
        $out = '';
        while ($n > 0) { $m = ($n - 1) % 26; $out = chr(65 + $m) . $out; $n = intdiv($n - 1, 26); }
        return $out;
    }

    /** Normalisierter Block (Grenzen, Vorgaben) */
    public static function clean_block(array $b) {
        $shape = ($b['shape'] ?? 'rows') === 'tables' ? 'tables' : 'rows';
        $rows = intval(self::num($b['rows'] ?? 1, 1, 1, 100));
        $seats = $b['seats'] ?? 10;
        if (is_array($seats)) {
            $seats = array_values(array_map(function ($v) { return intval(self::num($v, 10, 0, 200)); }, array_slice($seats, 0, 100)));
            if (!$seats) $seats = [10];
            $rows = count($seats);
        } else {
            $seats = intval(self::num($seats, 10, 1, 200));
        }
        $gaps = [];
        foreach ((array) ($b['gaps'] ?? []) as $g) {
            if (!is_array($g)) continue;
            $after = intval($g['after'] ?? 0);
            if ($after > 0) $gaps[] = ['after' => $after, 'width' => self::num($g['width'] ?? 1, 1, 0.5, 20)];
        }
        $types = [];
        foreach ((array) ($b['types'] ?? []) as $sid => $t) {
            if (in_array($t, self::TYPES, true) && $t !== 'standard') $types[sanitize_text_field((string) $sid)] = $t;
        }
        $o = (array) ($b['origin'] ?? []);
        return [
            'shape' => $shape,
            'origin' => (isset($o['x']) && is_numeric($o['x']) && isset($o['y']) && is_numeric($o['y'])) ? ['x' => round(floatval($o['x']), 1), 'y' => round(floatval($o['y']), 1)] : null,
            'rows' => $rows,
            'seats' => $seats,
            'seat_spacing' => self::num($b['seat_spacing'] ?? self::SEAT, self::SEAT, 10, 120),
            'row_spacing' => self::num($b['row_spacing'] ?? self::ROW, self::ROW, 10, 160),
            'curve' => self::num($b['curve'] ?? 0, 0, 0, 1),
            'angle' => self::num($b['angle'] ?? 0, 0, -360, 360),
            'gaps' => $gaps,
            'stagger' => !empty($b['stagger']),
            'row_labels' => ($b['row_labels'] ?? 'alpha') === 'numeric' ? 'numeric' : 'alpha',
            'row_start' => isset($b['row_start']) ? sanitize_text_field((string) $b['row_start']) : null,
            'seat_start' => intval(self::num($b['seat_start'] ?? 1, 1, 0, 9999)),
            'numbering' => ($b['numbering'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr',
            'types' => $types,
            'tables' => intval(self::num($b['tables'] ?? 0, 0, 0, 300)),
            'seats_per_table' => intval(self::num($b['seats_per_table'] ?? 8, 8, 1, 24)),
            'table_shape' => ($b['table_shape'] ?? 'round') === 'rect' ? 'rect' : 'round',
            'cols' => intval(self::num($b['cols'] ?? 4, 4, 1, 30)),
            'table_spacing' => self::num($b['table_spacing'] ?? 0, 0, 0, 400),
        ];
    }

    /**
     * Sitze einer Sektion aus dem Block. Koordinaten relativ zu origin (0/0 = Mitte der ersten Reihe),
     * gedreht um origin. → [rows[], tables[] (nur bei Tischen: {id,label,x,y,r|w,h})]
     */
    public static function block_rows($section_id, array $b) {
        $b = self::clean_block($b);
        $ox = $b['origin']['x'] ?? 0;
        $oy = $b['origin']['y'] ?? 0;
        $rad = deg2rad($b['angle']);
        $cos = cos($rad); $sin = sin($rad);
        $rot = function ($x, $y) use ($ox, $oy, $cos, $sin) {
            return [round($ox + $x * $cos - $y * $sin, 1), round($oy + $x * $sin + $y * $cos, 1)];
        };
        $rows = [];
        $tables = [];
        if ($b['shape'] === 'tables') {
            $n = max(1, $b['tables']);
            $per = $b['seats_per_table'];
            $tr = max(18, $per * $b['seat_spacing'] / (2 * M_PI)); // Tischradius
            $step = $b['table_spacing'] ?: (2 * $tr + 2 * $b['seat_spacing'] + 20);
            $cols = min($b['cols'], $n);
            for ($t = 0; $t < $n; $t++) {
                $c = $t % $cols; $r = intdiv($t, $cols);
                $cx = ($c - ($cols - 1) / 2) * $step;
                $cy = $r * $step;
                $label = 'T' . ($t + 1);
                $seats = [];
                for ($s = 0; $s < $per; $s++) {
                    if ($b['table_shape'] === 'rect') {
                        // Rechteck: Plätze auf Ober- und Unterseite verteilt
                        $top = (int) ceil($per / 2);
                        $onTop = $s < $top;
                        $k = $onTop ? $s : $s - $top;
                        $cnt = $onTop ? $top : $per - $top;
                        $x = ($k - ($cnt - 1) / 2) * $b['seat_spacing'];
                        $y = $onTop ? -$tr * 0.7 - 10 : $tr * 0.7 + 10;
                    } else {
                        $a = -M_PI / 2 + 2 * M_PI * $s / $per;
                        $x = cos($a) * ($tr + 12); $y = sin($a) * ($tr + 12);
                    }
                    list($px, $py) = $rot($cx + $x, $cy + $y);
                    $id = $section_id . '_' . $label . '_' . ($s + 1);
                    $seats[] = ['id' => $id, 'x' => $px, 'y' => $py, 'type' => $b['types'][$id] ?? 'standard', 'label' => (string) ($s + 1)];
                }
                list($tx, $ty) = $rot($cx, $cy);
                $tables[] = $b['table_shape'] === 'rect'
                    ? ['id' => $section_id . '_' . $label, 'label' => $label, 'shape' => 'rect', 'x' => $tx, 'y' => $ty, 'w' => round(max(40, (int) ceil($per / 2) * $b['seat_spacing']), 1), 'h' => round($tr * 1.4, 1), 'angle' => $b['angle']]
                    : ['id' => $section_id . '_' . $label, 'label' => $label, 'shape' => 'round', 'x' => $tx, 'y' => $ty, 'r' => round($tr, 1)];
                $rows[] = ['id' => $label, 'label' => 'Tisch ' . ($t + 1), 'seats' => $seats];
            }
            return [$rows, $tables];
        }
        $per = is_array($b['seats']) ? $b['seats'] : array_fill(0, $b['rows'], $b['seats']);
        $maxw = 0;
        foreach ($per as $cnt) $maxw = max($maxw, $cnt * $b['seat_spacing']);
        // Bogen: Mittelpunkt hinter der Bühne (negatives y), Radius aus Breite und Krümmung
        $arc = $b['curve'] > 0 ? $b['curve'] * (M_PI * 2 / 3) : 0; // bis 120°
        $r0 = $arc > 0 ? max($maxw / $arc, 60) : 0;
        foreach ($per as $ri => $cnt) {
            $label = self::row_label($ri, $b['row_labels'], $b['row_start']);
            $gapAt = [];
            foreach ($b['gaps'] as $g) $gapAt[$g['after']] = ($gapAt[$g['after']] ?? 0) + $g['width'];
            // Positionen in Platz-Einheiten inkl. Gänge
            $units = []; $u = 0;
            for ($s = 1; $s <= $cnt; $s++) {
                $units[] = $u;
                $u += 1 + ($gapAt[$s] ?? 0);
            }
            $width = $cnt > 0 ? end($units) : 0;
            $shift = ($b['stagger'] && $ri % 2 === 1) ? 0.5 : 0;
            $seats = [];
            for ($s = 0; $s < $cnt; $s++) {
                $pos = ($units[$s] - $width / 2 + $shift) * $b['seat_spacing'];
                if ($arc > 0) {
                    $r = $r0 + $ri * $b['row_spacing'];
                    $a = $pos / $r;
                    $x = sin($a) * $r;
                    $y = cos($a) * $r - $r0;
                } else {
                    $x = $pos;
                    $y = $ri * $b['row_spacing'];
                }
                list($px, $py) = $rot($x, $y);
                $num = $b['numbering'] === 'rtl' ? $b['seat_start'] + $cnt - 1 - $s : $b['seat_start'] + $s;
                $id = $section_id . '_' . $label . '_' . $num;
                $seats[] = ['id' => $id, 'x' => $px, 'y' => $py, 'type' => $b['types'][$id] ?? 'standard', 'label' => (string) $num];
            }
            if ($b['numbering'] === 'rtl') $seats = array_reverse($seats);
            $rows[] = ['id' => $label, 'label' => 'Reihe ' . $label, 'seats' => $seats];
        }
        return [$rows, $tables];
    }

    // ═══════════════ Plan aufbauen ═══════════════

    /** Grenzen aller Sitze und Objekte → [minx, miny, maxx, maxy] | null */
    public static function bounds(array $data) {
        $minx = $miny = INF; $maxx = $maxy = -INF;
        foreach (($data['sections'] ?? []) as $sec) foreach (($sec['rows'] ?? []) as $row) foreach (($row['seats'] ?? []) as $s) {
            $minx = min($minx, $s['x']); $maxx = max($maxx, $s['x']); $miny = min($miny, $s['y']); $maxy = max($maxy, $s['y']);
        }
        foreach (($data['objects'] ?? []) as $o) {
            $w = floatval($o['w'] ?? 0) / 2; $h = floatval($o['h'] ?? 0) / 2;
            $minx = min($minx, $o['x'] - $w); $maxx = max($maxx, $o['x'] + $w); $miny = min($miny, $o['y'] - $h); $maxy = max($maxy, $o['y'] + $h);
        }
        return is_finite($minx) ? [$minx, $miny, $maxx, $maxy] : null;
    }

    /** Alles so verschieben, dass der Plan bei PAD/PAD beginnt; width/height daraus */
    public static function normalize(array $data) {
        $b = self::bounds($data);
        if (!$b) { $data['width'] = 800; $data['height'] = 600; return $data; }
        $dx = self::PAD - $b[0]; $dy = self::PAD - $b[1];
        $mv = function ($v, $d) { return round($v + $d, 1); };
        foreach ($data['sections'] as &$sec) {
            foreach ($sec['rows'] as &$row) foreach ($row['seats'] as &$s) { $s['x'] = $mv($s['x'], $dx); $s['y'] = $mv($s['y'], $dy); }
            unset($row, $s);
            foreach (($sec['tables'] ?? []) as &$t) { $t['x'] = $mv($t['x'], $dx); $t['y'] = $mv($t['y'], $dy); }
            unset($t);
            if (!empty($sec['block']['origin'])) { $sec['block']['origin'] = ['x' => $mv($sec['block']['origin']['x'], $dx), 'y' => $mv($sec['block']['origin']['y'], $dy)]; }
        }
        unset($sec);
        foreach (($data['objects'] ?? []) as &$o) { $o['x'] = $mv($o['x'], $dx); $o['y'] = $mv($o['y'], $dy); }
        unset($o);
        $data['width'] = (int) ceil($b[2] - $b[0] + 2 * self::PAD);
        $data['height'] = (int) ceil($b[3] - $b[1] + 2 * self::PAD);
        return $data;
    }

    /**
     * Sektionen mit Block erzeugen, Sektionen ohne Koordinaten nachrechnen, alles normalisieren.
     * Sektionen ohne origin werden untereinander unter die Bühne gelegt.
     */
    public static function build(array $data, $normalize = true) {
        $data['sections'] = array_values((array) ($data['sections'] ?? []));
        $data['objects'] = array_values((array) ($data['objects'] ?? []));
        $cursor = 0; // nächste freie y-Position für automatisch gelegte Sektionen
        $hasStage = false;
        foreach ($data['objects'] as $o) if (($o['type'] ?? '') === 'stage') $hasStage = true;
        if (!$hasStage) $cursor = 90; // Platz für die Bühne
        $placed = [];
        foreach ($data['sections'] as $i => &$sec) {
            if (!empty($sec['block']) && is_array($sec['block'])) {
                $blk = self::clean_block($sec['block']);
                if (!$blk['origin']) {
                    $blk['origin'] = ['x' => 0, 'y' => $cursor];
                }
                list($rows, $tables) = self::block_rows($sec['id'], $blk);
                $sec['block'] = $blk;
                $sec['rows'] = $rows;
                if ($tables) $sec['tables'] = $tables; else unset($sec['tables']);
            }
            $b = self::bounds(['sections' => [$sec]]);
            if ($b) $cursor = max($cursor, $b[3] + 60);
            $placed[$i] = true;
        }
        unset($sec);
        $data = self::ensure_coords($data);
        if (!$hasStage && self::bounds($data)) {
            $b = self::bounds($data);
            $data['objects'][] = ['type' => 'stage', 'x' => round(($b[0] + $b[2]) / 2, 1), 'y' => round($b[1] - 60, 1),
                'w' => round(max(160, min(600, ($b[2] - $b[0]) * 0.6)), 1), 'h' => 40, 'label' => (string) ($data['stage_label'] ?? 'BÜHNE')];
        }
        return $normalize ? self::normalize($data) : $data;
    }

    /** Sitzen ohne Koordinaten (alle 0/0) eine Lage geben: Raster je Sektion, nach `position` um die Bühne */
    public static function ensure_coords(array $data) {
        $need = [];
        foreach (($data['sections'] ?? []) as $i => $sec) {
            if (!empty($sec['block'])) continue;
            $n = 0; $zero = 0;
            foreach (($sec['rows'] ?? []) as $row) foreach (($row['seats'] ?? []) as $s) { $n++; if (floatval($s['x'] ?? 0) == 0 && floatval($s['y'] ?? 0) == 0) $zero++; }
            if ($n > 0 && ($zero === $n || $zero > 1)) $need[] = $i;
        }
        if (!$need) return $data;
        $theater = ($data['layout'] ?? 'theater') === 'theater';
        $cell = ['top-left' => [-1, -1], 'top' => [0, -1], 'top-right' => [1, -1], 'left' => [-1, 0], 'center' => [0, 1],
            'right' => [1, 0], 'bottom-left' => [-1, 1], 'bottom' => [0, 1], 'bottom-right' => [1, 1]];
        // Größe je Sektion
        $size = [];
        foreach ($need as $i) {
            $w = 0; $rows = count($data['sections'][$i]['rows'] ?? []);
            foreach ($data['sections'][$i]['rows'] as $row) $w = max($w, count($row['seats'] ?? []));
            $size[$i] = [max(1, $w) * self::SEAT, max(1, $rows) * self::ROW];
        }
        $cw = max(array_map(function ($s) { return $s[0]; }, $size)) + 60;
        $ch = max(array_map(function ($s) { return $s[1]; }, $size)) + 60;
        $stack = [];
        $existing = self::bounds(['sections' => array_values(array_diff_key($data['sections'], array_flip($need)))]);
        $ytheater = $existing ? $existing[3] + 60 : 90;
        foreach ($need as $i) {
            $sec = &$data['sections'][$i];
            if ($theater) {
                $x0 = 0; $y0 = $ytheater; $ytheater += $size[$i][1] + 50;
            } else {
                list($cx, $cy) = $cell[$sec['position'] ?? 'center'] ?? [0, 1];
                $k = $cx . ':' . $cy;
                $off = $stack[$k] ?? 0;
                $stack[$k] = $off + $size[$i][1] + 40;
                $h = $size[$i][1];
                $x0 = $cx * $cw;
                $y0 = $cy < 0 ? -$ch / 2 - $h - $off : ($cy === 0 ? -$h / 2 + $off : $ch / 2 + $off);
            }
            foreach ($sec['rows'] as $ri => &$row) {
                $cnt = count($row['seats'] ?? []);
                foreach ($row['seats'] as $si => &$s) {
                    $s['x'] = round($x0 + ($si - ($cnt - 1) / 2) * self::SEAT, 1);
                    $s['y'] = round($y0 + $ri * self::ROW, 1);
                }
                unset($s);
            }
            unset($row, $sec);
        }
        if (!$theater) {
            $hasStage = false;
            foreach (($data['objects'] ?? []) as $o) if (($o['type'] ?? '') === 'stage') $hasStage = true;
            if (!$hasStage) $data['objects'][] = ['type' => 'stage', 'x' => 0, 'y' => 0, 'w' => round(max(120, $cw - 60), 1), 'h' => round(max(60, $ch - 80), 1), 'label' => (string) ($data['stage_label'] ?? 'BÜHNE')];
        }
        return $data;
    }

    // ═══════════════ Prüfung ═══════════════

    /**
     * Prüfung nach dem Aufbau: doppelte IDs (entfernt), Überlappungen (gezählt), Summe.
     * → {seats, bookable, sections, duplicates[], overlaps, overlap_pairs[] (max. 20)}
     */
    public static function check(array &$data) {
        $seen = []; $dups = [];
        $pts = [];
        $seats = 0; $bookable = 0;
        foreach ($data['sections'] as &$sec) {
            foreach ($sec['rows'] as &$row) {
                $keep = [];
                foreach ($row['seats'] as $s) {
                    if ($s['id'] === '' || isset($seen[$s['id']])) { $dups[] = $s['id']; continue; }
                    $seen[$s['id']] = true;
                    $keep[] = $s;
                    $seats++;
                    if ($s['type'] !== 'blocked') $bookable++;
                    $pts[] = [$s['x'], $s['y'], $s['id']];
                }
                $row['seats'] = $keep;
            }
            unset($row);
        }
        unset($sec);
        // Überlappung: Abstand < 12 Punkte (Raster-Hash)
        $min = 12; $grid = []; $pairs = []; $over = 0;
        foreach ($pts as $p) {
            $gx = (int) floor($p[0] / $min); $gy = (int) floor($p[1] / $min);
            for ($dx = -1; $dx <= 1; $dx++) for ($dy = -1; $dy <= 1; $dy++) {
                foreach ($grid[($gx + $dx) . ':' . ($gy + $dy)] ?? [] as $q) {
                    if (($p[0] - $q[0]) ** 2 + ($p[1] - $q[1]) ** 2 < $min * $min) {
                        $over++;
                        if (count($pairs) < 20) $pairs[] = [$q[2], $p[2]];
                    }
                }
            }
            $grid[$gx . ':' . $gy][] = $p;
        }
        return ['seats' => $seats, 'bookable' => $bookable, 'sections' => count($data['sections']),
            'duplicates' => array_values(array_unique($dups)), 'overlaps' => $over, 'overlap_pairs' => $pairs];
    }
}
