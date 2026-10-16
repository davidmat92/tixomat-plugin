<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_QR – QR-Code lokal erzeugen (1.38.463), ohne externen Dienst: Gutschein-Codes sind bares Geld und gehen nicht an
 * api.qrserver.com. Byte-Modus, Fehlerkorrektur M, Version 1–6 (bis 106 Byte), Maske nach Strafpunkten (ISO/IEC 18004).
 *
 *   TIX_QR::matrix($text)           → [[bool]] (true = dunkel) oder null (zu lang)
 *   TIX_QR::gd($text, $px, $quiet)  → GD-Bild (Kantenlänge $px, Ruhezone in Modulen) oder null
 *   TIX_QR::url($text, $px)         → signierte PNG-Adresse auf der eigenen Seite (?tix_qr=…&s=…) für Mails und Ticket-Seiten
 *                                      (1.38.465: Ticket- und Gästelisten-Codes gehen nicht mehr an api.qrserver.com/quickchart.io)
 */
class TIX_QR {

    public static function init() {
        add_action('init', [__CLASS__, 'serve'], 1);
    }

    private static function sig($text, $px) {
        return substr(hash_hmac('sha256', $px . '|' . $text, wp_salt('auth') . 'tix_qr'), 0, 20);
    }

    public static function url($text, $px = 300) {
        $px = max(80, min(1000, intval($px)));
        return add_query_arg(['tix_qr' => rawurlencode((string) $text), 'px' => $px, 's' => self::sig((string) $text, $px)], home_url('/'));
    }

    /** ?tix_qr=…&px=…&s=… → PNG (nur mit gültiger Signatur, sonst 404). */
    public static function serve() {
        if (!isset($_GET['tix_qr'], $_GET['s'])) return;
        $text = wp_unslash((string) $_GET['tix_qr']);
        $px = max(80, min(1000, intval($_GET['px'] ?? 300)));
        if (!hash_equals(self::sig($text, $px), (string) $_GET['s'])) { status_header(404); exit; }
        $im = self::gd($text, $px, 2);
        if (!$im) { status_header(404); exit; }
        if (!headers_sent()) {
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=31536000, immutable');
            header('X-Robots-Tag: noindex');
        }
        imagepng($im);
        imagedestroy($im);
        exit;
    }

    /** Version => [Fehlerkorrektur-Bytes je Block, [[Blöcke, Datenbytes je Block], …]] für Stufe M */
    const BLOCKS = [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
    ];
    const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34]];

    private static $exp = [];
    private static $log = [];

    private static function gf_init() {
        if (self::$exp) return;
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) $x ^= 0x11D;
        }
        for ($i = 255; $i < 512; $i++) self::$exp[$i] = self::$exp[$i - 255];
    }

    private static function gf_mul($a, $b) {
        if ($a === 0 || $b === 0) return 0;
        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /** Reed-Solomon-Prüfbytes. */
    private static function rs($data, $n) {
        self::gf_init();
        $gen = [1];
        for ($i = 0; $i < $n; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $g) {
                $next[$j] ^= $g;
                $next[$j + 1] ^= self::gf_mul($g, self::$exp[$i]);
            }
            $gen = $next;
        }
        $res = array_fill(0, $n, 0);
        foreach ($data as $d) {
            $f = $d ^ $res[0];
            array_shift($res);
            $res[] = 0;
            for ($j = 0; $j < $n; $j++) $res[$j] ^= self::gf_mul($gen[$j + 1], $f);
        }
        return $res;
    }

    private static function capacity($v) {
        $c = 0;
        foreach (self::BLOCKS[$v][1] as $g) $c += $g[0] * $g[1];
        return $c;
    }

    /** Codewörter (Daten + Prüfbytes, verschränkt) für Version $v. */
    private static function codewords($bytes, $v) {
        $cap = self::capacity($v);
        $bits = '0100' . str_pad(decbin(count($bytes)), 8, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        $bits .= str_repeat('0', min(4, $cap * 8 - strlen($bits)));
        if (strlen($bits) % 8) $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        $data = array_map('bindec', str_split($bits, 8));
        for ($i = 0; count($data) < $cap; $i++) $data[] = $i % 2 ? 0x11 : 0xEC;
        list($ecn, $groups) = self::BLOCKS[$v];
        $blocks = [];
        $pos = 0;
        foreach ($groups as $g) {
            for ($b = 0; $b < $g[0]; $b++) {
                $d = array_slice($data, $pos, $g[1]);
                $pos += $g[1];
                $blocks[] = [$d, self::rs($d, $ecn)];
            }
        }
        $out = [];
        $max = max(array_map(function ($b) { return count($b[0]); }, $blocks));
        for ($i = 0; $i < $max; $i++) foreach ($blocks as $b) if (isset($b[0][$i])) $out[] = $b[0][$i];
        for ($i = 0; $i < $ecn; $i++) foreach ($blocks as $b) $out[] = $b[1][$i];
        return $out;
    }

    private static function mask_bit($m, $r, $c) {
        switch ($m) {
            case 0: return ($r + $c) % 2 === 0;
            case 1: return $r % 2 === 0;
            case 2: return $c % 3 === 0;
            case 3: return ($r + $c) % 3 === 0;
            case 4: return (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0;
            case 5: return ($r * $c) % 2 + ($r * $c) % 3 === 0;
            case 6: return (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0;
            default: return (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0;
        }
    }

    /** Matrix (Zeilen von bool) oder null; $force_mask nur für Tests. */
    public static function matrix($text, $force_mask = null) {
        $bytes = array_values(unpack('C*', (string) $text) ?: []);
        $v = 0;
        foreach (array_keys(self::BLOCKS) as $cand) {
            if (count($bytes) + 2 <= self::capacity($cand)) { $v = $cand; break; }
        }
        if (!$v || !$bytes) return null;
        $n = 17 + 4 * $v;
        $m = array_fill(0, $n, array_fill(0, $n, null)); // null = frei
        $set = function ($r, $c, $dark) use (&$m) { $m[$r][$c] = (bool) $dark; };
        // Suchmuster + Trennlinien
        foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as $o) {
            for ($r = -1; $r <= 7; $r++) for ($c = -1; $c <= 7; $c++) {
                $rr = $o[0] + $r; $cc = $o[1] + $c;
                if ($rr < 0 || $cc < 0 || $rr >= $n || $cc >= $n) continue;
                $in = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6;
                $set($rr, $cc, $in && ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4)));
            }
        }
        // Ausrichtungsmuster
        $al = self::ALIGN[$v];
        foreach ($al as $ar) foreach ($al as $ac) {
            if ($m[$ar][$ac] !== null) continue;
            for ($r = -2; $r <= 2; $r++) for ($c = -2; $c <= 2; $c++) $set($ar + $r, $ac + $c, max(abs($r), abs($c)) !== 1);
        }
        // Taktlinien
        for ($i = 8; $i < $n - 8; $i++) {
            if ($m[6][$i] === null) $set(6, $i, $i % 2 === 0);
            if ($m[$i][6] === null) $set($i, 6, $i % 2 === 0);
        }
        // dunkles Modul + Formatbereiche reservieren
        $set(4 * $v + 9, 8, true);
        $fmt_cells = [];
        for ($i = 0; $i <= 8; $i++) { if ($i !== 6) { $fmt_cells[] = [8, $i]; $fmt_cells[] = [$i, 8]; } }
        for ($i = 0; $i < 8; $i++) { $fmt_cells[] = [8, $n - 1 - $i]; $fmt_cells[] = [$n - 1 - $i, 8]; }
        $reserved = $m;
        foreach ($fmt_cells as $fc) if ($reserved[$fc[0]][$fc[1]] === null) $reserved[$fc[0]][$fc[1]] = false;
        // Daten im Zickzack
        $cw = self::codewords($bytes, $v);
        $bits = '';
        foreach ($cw as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        $data_cells = [];
        $k = 0;
        $up = true;
        for ($c = $n - 1; $c > 0; $c -= 2) {
            if ($c === 6) $c--;
            for ($i = 0; $i < $n; $i++) {
                $r = $up ? $n - 1 - $i : $i;
                for ($d = 0; $d < 2; $d++) {
                    $cc = $c - $d;
                    if ($reserved[$r][$cc] !== null) continue;
                    $data_cells[] = [$r, $cc, $k < strlen($bits) && $bits[$k] === '1'];
                    $k++;
                }
            }
            $up = !$up;
        }
        $best = null;
        $best_score = PHP_INT_MAX;
        foreach ($force_mask !== null ? [intval($force_mask)] : range(0, 7) as $mask) {
            $g = $reserved;
            foreach ($data_cells as $dc) $g[$dc[0]][$dc[1]] = ($dc[2] !== self::mask_bit($mask, $dc[0], $dc[1]));
            self::place_format($g, $n, $mask);
            $score = $force_mask !== null ? 0 : self::penalty($g, $n);
            if ($score < $best_score) { $best_score = $score; $best = $g; }
        }
        return array_map(function ($row) { return array_map(function ($x) { return (bool) $x; }, $row); }, $best);
    }

    private static function place_format(&$g, $n, $mask) {
        $data = (0b00 << 3) | $mask; // M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;
        $bit = function ($i) use ($bits) { return (($bits >> $i) & 1) === 1; };
        for ($i = 0; $i <= 5; $i++) $g[$i][8] = $bit($i);
        $g[7][8] = $bit(6);
        $g[8][8] = $bit(7);
        $g[8][7] = $bit(8);
        for ($i = 9; $i < 15; $i++) $g[8][14 - $i] = $bit($i);
        for ($i = 0; $i < 8; $i++) $g[8][$n - 1 - $i] = $bit($i);
        for ($i = 8; $i < 15; $i++) $g[$n - 15 + $i][8] = $bit($i);
        $g[$n - 8][8] = true;
    }

    private static function penalty($g, $n) {
        $p = 0;
        for ($pass = 0; $pass < 2; $pass++) {
            for ($i = 0; $i < $n; $i++) {
                $run = 1;
                $line = [];
                for ($j = 0; $j < $n; $j++) $line[] = $pass ? $g[$j][$i] : $g[$i][$j];
                for ($j = 1; $j < $n; $j++) {
                    if ($line[$j] === $line[$j - 1]) { $run++; }
                    else { if ($run >= 5) $p += $run - 2; $run = 1; }
                }
                if ($run >= 5) $p += $run - 2;
                $s = implode('', array_map(function ($x) { return $x ? '1' : '0'; }, $line));
                $p += 40 * (substr_count($s, '10111010000') + substr_count($s, '00001011101'));
            }
        }
        for ($r = 0; $r < $n - 1; $r++) for ($c = 0; $c < $n - 1; $c++) {
            $x = $g[$r][$c];
            if ($x === $g[$r][$c + 1] && $x === $g[$r + 1][$c] && $x === $g[$r + 1][$c + 1]) $p += 3;
        }
        $dark = 0;
        foreach ($g as $row) foreach ($row as $x) if ($x) $dark++;
        $p += intdiv(abs($dark * 20 - $n * $n * 10), $n * $n) * 10;
        return $p;
    }

    /** GD-Bild des Codes (quadratisch, $px Kantenlänge, Ruhezone $quiet Module). */
    public static function gd($text, $px = 400, $quiet = 2) {
        if (!function_exists('imagecreatetruecolor')) return null;
        $mx = self::matrix($text);
        if (!$mx) return null;
        $n = count($mx);
        $mod = max(1, intdiv($px, $n + 2 * $quiet));
        $size = $mod * ($n + 2 * $quiet);
        $img = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $size, $size, $white);
        foreach ($mx as $r => $row) foreach ($row as $c => $dark) {
            if ($dark) imagefilledrectangle($img, ($c + $quiet) * $mod, ($r + $quiet) * $mod, ($c + $quiet + 1) * $mod - 1, ($r + $quiet + 1) * $mod - 1, $black);
        }
        return $img;
    }
}
