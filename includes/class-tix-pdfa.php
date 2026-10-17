<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_PDFA – kleiner PDF/A-3b-Schreiber für Rechnungen (1.38.500, ZUGFeRD/Factur-X-Hybrid).
 *
 * Gleiche Zeichen-Schnittstelle wie TIX_Simple_PDF (add_page, set_font, set_color, text, rect, load_logo, draw_logo,
 * set_footer, output), aber PDF/A-tauglich:
 *  - Schrift eingebettet (Manrope 400/700 aus assets/fonts, TrueType, WinAnsiEncoding, Breiten aus der Schriftdatei),
 *    „Helvetica“ wird auf Manrope abgebildet; € wird als echtes Zeichen gesetzt,
 *  - OutputIntent mit sRGB-Farbprofil (ICC v2, erzeugt), XMP-Metadaten (pdfaid 3/B) passend zum Info-Wörterbuch,
 *  - Dateianhänge als Associated Files (z. B. factur-x.xml mit AFRelationship), Datei-ID im Trailer.
 */
class TIX_PDFA {

    private $w, $h, $mx;
    private $pages = [];
    private $cur = '';
    private $font = 'F1';
    private $size = 10;
    private $rgb = '0 0 0';
    private $footer = '';
    private $logo = null;      // ['data' => jpeg, 'w' => px, 'h' => px]
    private $logo_used = false;
    private $files = [];       // [['name','mime','data','desc','rel']]
    private $meta = ['title' => '', 'author' => '', 'subject' => '', 'xmp_extra' => '', 'xmp_schemas' => ''];
    private static $fonts = [];

    const FONT_FILES = ['F1' => 'Manrope-400.ttf', 'F2' => 'Manrope-700.ttf'];
    const FONT_NAMES = ['F1' => 'Manrope-Regular', 'F2' => 'Manrope-Bold'];

    public function __construct($w, $h, $mx, $my = 0) {
        $this->w = $w; $this->h = $h; $this->mx = $mx;
    }

    public static function available() {
        foreach (self::FONT_FILES as $f) if (!is_readable(self::font_path($f))) return false;
        return function_exists('gzcompress');
    }

    private static function font_path($file) {
        return (defined('TIXOMAT_PATH') ? TIXOMAT_PATH : dirname(__DIR__) . '/') . 'assets/fonts/manrope/' . $file;
    }

    // ═══════════════ Schrift (TrueType einlesen) ═══════════════

    /** CP1252-Code → Unicode (nur 0x80–0x9F weichen von Latin-1 ab). */
    private static function cp1252_to_unicode($c) {
        static $map = [0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02C6,
            0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152, 0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D,
            0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014, 0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A, 0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178];
        if ($c >= 0x80 && $c <= 0x9F) return $map[$c] ?? 0;
        return $c;
    }

    /** Schrift laden: Datei, Breiten (1000er Einheiten) für 32–255, Kennzahlen. */
    private static function font($key) {
        if (isset(self::$fonts[$key])) return self::$fonts[$key];
        $data = (string) @file_get_contents(self::font_path(self::FONT_FILES[$key]));
        $tables = [];
        $num = unpack('n', substr($data, 4, 2))[1];
        for ($i = 0; $i < $num; $i++) {
            $rec = substr($data, 12 + 16 * $i, 16);
            $tag = substr($rec, 0, 4);
            $v = unpack('Ncheck/Noffset/Nlength', substr($rec, 4));
            $tables[$tag] = [$v['offset'], $v['length']];
        }
        $u16 = function ($o) use ($data) { return unpack('n', substr($data, $o, 2))[1]; };
        $s16 = function ($o) use ($u16) { $v = $u16($o); return $v >= 0x8000 ? $v - 0x10000 : $v; };
        $head = $tables['head'][0];
        $upem = $u16($head + 18);
        $bbox = [$s16($head + 36), $s16($head + 38), $s16($head + 40), $s16($head + 42)];
        $hhea = $tables['hhea'][0];
        $asc = $s16($hhea + 4); $desc = $s16($hhea + 6);
        $nh = $u16($hhea + 34);
        $hmtx = $tables['hmtx'][0];
        $adv = function ($gid) use ($u16, $hmtx, $nh) { return $u16($hmtx + 4 * min($gid, $nh - 1)); };
        // cmap (3,1) Format 4
        $cmap = $tables['cmap'][0];
        $sub = 0;
        $n = $u16($cmap + 2);
        for ($i = 0; $i < $n; $i++) {
            $pid = $u16($cmap + 4 + 8 * $i); $eid = $u16($cmap + 6 + 8 * $i);
            $off = unpack('N', substr($data, $cmap + 8 + 8 * $i, 4))[1];
            if ($pid === 3 && $eid === 1) { $sub = $cmap + $off; break; }
        }
        $glyph = function ($u) use ($sub, $u16, $data) {
            if (!$sub || $u16($sub) !== 4) return 0;
            $segx2 = $u16($sub + 6);
            $ends = $sub + 14; $starts = $ends + $segx2 + 2; $deltas = $starts + $segx2; $ranges = $deltas + $segx2;
            for ($i = 0; $i < $segx2 / 2; $i++) {
                $end = $u16($ends + 2 * $i);
                if ($u > $end) continue;
                $start = $u16($starts + 2 * $i);
                if ($u < $start) return 0;
                $delta = $u16($deltas + 2 * $i); $ro = $u16($ranges + 2 * $i);
                if ($ro === 0) return ($u + $delta) & 0xFFFF;
                $g = $u16($ranges + 2 * $i + $ro + 2 * ($u - $start));
                return $g ? ($g + $delta) & 0xFFFF : 0;
            }
            return 0;
        };
        $widths = []; $has = [];
        for ($c = 32; $c <= 255; $c++) {
            $u = self::cp1252_to_unicode($c);
            $g = $u ? $glyph($u) : 0;
            $has[$c] = $g > 0;
            $widths[$c] = $g > 0 ? (int) round($adv($g) * 1000 / $upem) : 0;
        }
        $cap = $asc;
        if (isset($tables['OS/2'])) {
            $os2 = $tables['OS/2'][0];
            if ($u16($os2) >= 2 && $tables['OS/2'][1] >= 90) $cap = $s16($os2 + 88);
        }
        $sc = function ($v) use ($upem) { return (int) round($v * 1000 / $upem); };
        return self::$fonts[$key] = ['data' => $data, 'widths' => $widths, 'has' => $has, 'bbox' => array_map($sc, $bbox),
            'ascent' => $sc($asc), 'descent' => $sc($desc), 'cap' => $sc($cap), 'stemv' => $key === 'F2' ? 140 : 80];
    }

    /** UTF-8 → CP1252, Zeichen ohne Glyphe entfallen (PDF/A: keine .notdef). */
    private function enc($s) {
        $s = str_replace(["\r", "\n", "\t"], ' ', (string) $s);
        $c = function_exists('iconv') ? @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) : false;
        if ($c === false) $c = function_exists('mb_convert_encoding') ? (string) @mb_convert_encoding($s, 'CP1252', 'UTF-8') : $s;
        $f = self::font($this->font);
        $out = '';
        $len = strlen($c);
        for ($i = 0; $i < $len; $i++) { $o = ord($c[$i]); if ($o >= 32 && !empty($f['has'][$o])) $out .= $c[$i]; }
        return $out;
    }

    /** Breite eines Textes in pt bei aktueller Schrift (bzw. $font_name/$size). */
    public function width($str, $size = null, $font_name = null) {
        $key = $font_name !== null ? (stripos($font_name, 'bold') !== false ? 'F2' : 'F1') : $this->font;
        $prev = $this->font; $this->font = $key;
        $s = $this->enc($str);
        $this->font = $prev;
        $f = self::font($key);
        $w = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) $w += $f['widths'][ord($s[$i])] ?? 0;
        return $w * ($size ?? $this->size) / 1000;
    }

    // ═══════════════ Zeichnen ═══════════════

    public function add_page() {
        if ($this->cur !== '') $this->pages[] = $this->cur;
        $this->cur = ' ';
    }

    public function set_font($name, $size) {
        $this->font = stripos((string) $name, 'bold') !== false ? 'F2' : 'F1';
        $this->size = $size;
    }

    public function set_color($r, $g, $b) {
        $this->rgb = sprintf('%.3F %.3F %.3F', $r, $g, $b);
    }

    private static function esc($s) {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    public function text($x, $y, $str) {
        $s = $this->enc($str);
        if ($s === '') return;
        $this->cur .= sprintf("q %s rg BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET Q\n", $this->rgb, $this->font, $this->size, $x, $y, self::esc($s));
    }

    public function rect($x, $y, $w, $h, $fill = false) {
        $this->cur .= sprintf("q %s %s %.2F %.2F %.2F %.2F re %s Q\n", $this->rgb, $fill ? 'rg' : 'RG', $x, $y, $w, $h, $fill ? 'f' : 'S');
    }

    public function set_footer($text) { $this->footer = (string) $text; }

    /** Logo (URL oder Pfad, lokale Uploads bevorzugt) → JPEG ohne Transparenz (weißer Grund). */
    public function load_logo($src) {
        if (!$src || !function_exists('imagecreatefromstring')) return false;
        $bytes = null;
        $up = wp_get_upload_dir();
        if (strpos($src, $up['baseurl']) === 0) {
            $p = str_replace($up['baseurl'], $up['basedir'], $src);
            if (is_readable($p)) $bytes = @file_get_contents($p);
        } elseif (@is_readable($src)) {
            $bytes = @file_get_contents($src);
        } else {
            $r = wp_remote_get($src, ['timeout' => 8]);
            if (!is_wp_error($r) && intval(wp_remote_retrieve_response_code($r)) === 200) $bytes = wp_remote_retrieve_body($r);
        }
        if (!$bytes) return false;
        $im = @imagecreatefromstring($bytes);
        if (!$im) return false;
        $w = imagesx($im); $h = imagesy($im);
        $bg = imagecreatetruecolor($w, $h);
        imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
        imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
        ob_start(); imagejpeg($bg, null, 90); $jpg = ob_get_clean();
        imagedestroy($im); imagedestroy($bg);
        $this->logo = ['data' => $jpg, 'w' => $w, 'h' => $h];
        return true;
    }

    public function draw_logo($x, $y, $max_w, $max_h = null) {
        if (!$this->logo) return;
        $max_h = $max_h ?: $max_w;
        $ratio = $this->logo['h'] / max(1, $this->logo['w']);
        $w = $max_w; $h = $w * $ratio;
        if ($h > $max_h) { $h = $max_h; $w = $h / $ratio; }
        $this->cur .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q\n", $w, $h, $x + ($max_w - $w), $y + ($max_h - $h) / 2);
        $this->logo_used = true;
    }

    // ═══════════════ Anhänge, Metadaten ═══════════════

    /** Datei als Associated File anhängen ($rel: Alternative | Data | Source | Supplement). */
    public function attach($name, $data, $mime, $desc = '', $rel = 'Alternative') {
        $this->files[] = ['name' => $name, 'data' => (string) $data, 'mime' => $mime, 'desc' => $desc, 'rel' => $rel];
    }

    /** Titel/Autor; $xmp_extra = zusätzliche rdf:Description-Blöcke, $xmp_schemas = pdfaExtension-Schemata (rdf:li). */
    public function set_meta(array $m) {
        $this->meta = array_merge($this->meta, $m);
    }

    /** sRGB-Profil (ICC v2.1, D50-Primärvalenzen, Gamma 2,2) – PDF/A-OutputIntent. */
    private static function srgb_icc() {
        $s15 = function ($v) { return pack('N', (int) round($v * 65536) & 0xFFFFFFFF); };
        $xyz = function ($x, $y, $z) use ($s15) { return 'XYZ ' . "\0\0\0\0" . $s15($x) . $s15($y) . $s15($z); };
        $desc_txt = "sRGB IEC61966-2.1 (Tixomat)";
        $desc = 'desc' . "\0\0\0\0" . pack('N', strlen($desc_txt) + 1) . $desc_txt . "\0" . pack('N', 0) . pack('N', 0) . pack('n', 0) . pack('C', 0) . str_repeat("\0", 67);
        $cprt = 'text' . "\0\0\0\0" . "No copyright, use freely\0";
        $curv = 'curv' . "\0\0\0\0" . pack('N', 1) . pack('n', 0x0233) . "\0\0";
        $tags = [
            ['desc', $desc], ['cprt', $cprt], ['wtpt', $xyz(0.9642, 1.0, 0.8249)],
            ['rXYZ', $xyz(0.4361, 0.2225, 0.0139)], ['gXYZ', $xyz(0.3851, 0.7169, 0.0971)], ['bXYZ', $xyz(0.1431, 0.0606, 0.7141)],
            ['rTRC', $curv], ['gTRC', $curv], ['bTRC', $curv],
        ];
        $count = count($tags);
        $offset = 128 + 4 + 12 * $count;
        $table = pack('N', $count); $body = '';
        $seen = [];
        foreach ($tags as $t) {
            $key = md5($t[1]);
            if (isset($seen[$key])) { $table .= $t[0] . pack('N', $seen[$key][0]) . pack('N', $seen[$key][1]); continue; }
            while (strlen($body) % 4) $body .= "\0";
            $o = $offset + strlen($body);
            $table .= $t[0] . pack('N', $o) . pack('N', strlen($t[1]));
            $seen[$key] = [$o, strlen($t[1])];
            $body .= $t[1];
        }
        while (strlen($body) % 4) $body .= "\0";
        $size = 128 + strlen($table) + strlen($body);
        $hdr = pack('N', $size) . 'none' . pack('N', 0x02100000) . 'mntr' . 'RGB ' . 'XYZ '
            . pack('n6', 2026, 1, 1, 0, 0, 0) . 'acsp' . 'APPL' . pack('N', 0) . "\0\0\0\0" . "\0\0\0\0" . "\0\0\0\0\0\0\0\0" . pack('N', 0)
            . $s15(0.9642) . $s15(1.0) . $s15(0.8249) . "\0\0\0\0" . str_repeat("\0", 16) . str_repeat("\0", 28);
        return substr($hdr, 0, 128) . $table . $body;
    }

    private static function pdf_date($ts) {
        $o = (int) wp_date('Z', $ts);
        return 'D:' . wp_date('YmdHis', $ts) . ($o >= 0 ? '+' : '-') . sprintf("%02d'%02d'", intdiv(abs($o), 3600), intdiv(abs($o) % 3600, 60));
    }

    private static function xmp_date($ts) {
        return wp_date('Y-m-d\TH:i:sP', $ts);
    }

    private static function pdf_string($s) {
        // Info-Wörterbuch: UTF-16BE mit BOM (eindeutig, wie im XMP)
        $u = function_exists('mb_convert_encoding') ? mb_convert_encoding((string) $s, 'UTF-16BE', 'UTF-8') : (string) $s;
        return '<FEFF' . strtoupper(bin2hex($u)) . '>';
    }

    // ═══════════════ Ausgabe ═══════════════

    public function output() {
        if ($this->cur !== '') { $this->pages[] = $this->cur; $this->cur = ''; }
        if (!$this->pages) $this->pages[] = ' ';
        $ts = current_time('timestamp', true);
        $objs = [];
        $add = function ($body) use (&$objs) { $objs[] = $body; return count($objs); };
        // nur das letzte „>>“ des äußeren Wörterbuchs ersetzen (innere Wörterbücher wie /Params bleiben geschlossen)
        $open = function ($dict) { return substr($dict, 0, strrpos($dict, '>>')); };
        $stream = function ($dict, $data, $compress = true) use ($open) {
            if ($compress) { $data = gzcompress($data, 9); $dict = $open($dict) . ' /Filter /FlateDecode>>'; }
            return $open($dict) . ' /Length ' . strlen($data) . ">>\nstream\n" . $data . "\nendstream";
        };
        // Platzhalter: 1 Catalog, 2 Pages
        $add(''); $add('');
        // Schriften
        $font_ref = [];
        foreach (array_keys(self::FONT_FILES) as $k) {
            $f = self::font($k);
            $ff = $add($stream('<</Length1 ' . strlen($f['data']) . '>>', $f['data']));
            $fd = $add(sprintf('<</Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV %d /FontFile2 %d 0 R>>',
                self::FONT_NAMES[$k], $f['bbox'][0], $f['bbox'][1], $f['bbox'][2], $f['bbox'][3], $f['ascent'], $f['descent'], $f['cap'], $f['stemv'], $ff));
            $w = [];
            for ($c = 32; $c <= 255; $c++) $w[] = $f['widths'][$c];
            $font_ref[$k] = $add(sprintf('<</Type /Font /Subtype /TrueType /BaseFont /%s /FirstChar 32 /LastChar 255 /Widths [%s] /Encoding /WinAnsiEncoding /FontDescriptor %d 0 R>>',
                self::FONT_NAMES[$k], implode(' ', $w), $fd));
        }
        $img = 0;
        if ($this->logo && $this->logo_used) {
            $img = $add($stream(sprintf('<</Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode>>', $this->logo['w'], $this->logo['h']), $this->logo['data'], false));
        }
        $res = sprintf('<</Font <</F1 %d 0 R /F2 %d 0 R>>%s>>', $font_ref['F1'], $font_ref['F2'], $img ? ' /XObject <</Im1 ' . $img . ' 0 R>>' : '');
        $res_id = $add($res);
        $kids = [];
        $n = count($this->pages);
        foreach ($this->pages as $i => $content) {
            if ($this->footer !== '') {
                $prev = [$this->font, $this->size, $this->rgb];
                $this->font = 'F1'; $this->size = 8; $this->rgb = '0.500 0.500 0.500';
                $content .= sprintf("q %s rg BT /F1 8 Tf %.2F 20 Td (%s) Tj ET Q\n", $this->rgb, $this->mx, self::esc($this->enc(str_replace(['{p}', '{n}'], [$i + 1, $n], $this->footer))));
                list($this->font, $this->size, $this->rgb) = $prev;
            }
            $cid = $add($stream('<<>>', $content));
            $kids[] = $add(sprintf('<</Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources %d 0 R /Contents %d 0 R>>', $this->w, $this->h, $res_id, $cid));
        }
        $objs[1] = sprintf('<</Type /Pages /Kids [%s] /Count %d>>', implode(' ', array_map(function ($k) { return $k . ' 0 R'; }, $kids)), count($kids));
        // Anhänge
        $af = []; $names = [];
        foreach ($this->files as $file) {
            $ef = $add($stream(sprintf('<</Type /EmbeddedFile /Subtype /%s /Params <</Size %d /ModDate (%s)>>>>', str_replace('/', '#2F', $file['mime']), strlen($file['data']), self::pdf_date($ts)), $file['data']));
            $fs = $add(sprintf('<</Type /Filespec /F (%s) /UF %s /Desc %s /AFRelationship /%s /EF <</F %d 0 R /UF %d 0 R>>>>',
                self::esc($file['name']), self::pdf_string($file['name']), self::pdf_string($file['desc'] ?: $file['name']), $file['rel'], $ef, $ef));
            $af[] = $fs . ' 0 R';
            $names[] = '(' . self::esc($file['name']) . ') ' . $fs . ' 0 R';
        }
        // Farbprofil + OutputIntent
        $icc = $add($stream('<</N 3>>', self::srgb_icc()));
        $oi = $add(sprintf('<</Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB IEC61966-2.1) /Info (sRGB IEC61966-2.1) /DestOutputProfile %d 0 R>>', $icc));
        // XMP
        $title = (string) $this->meta['title']; $author = (string) $this->meta['author'];
        $producer = 'Tixomat ' . (defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : '');
        $x = function ($s) { return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
        $xmp = '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>' . "\n"
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">' . "\n"
            . '<rdf:Description rdf:about="" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/"><pdfaid:part>3</pdfaid:part><pdfaid:conformance>B</pdfaid:conformance></rdf:Description>' . "\n"
            . '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:format>application/pdf</dc:format>'
            . '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . $x($title) . '</rdf:li></rdf:Alt></dc:title>'
            . '<dc:creator><rdf:Seq><rdf:li>' . $x($author) . '</rdf:li></rdf:Seq></dc:creator></rdf:Description>' . "\n"
            . '<rdf:Description rdf:about="" xmlns:pdf="http://ns.adobe.com/pdf/1.3/"><pdf:Producer>' . $x($producer) . '</pdf:Producer></rdf:Description>' . "\n"
            . '<rdf:Description rdf:about="" xmlns:xmp="http://ns.adobe.com/xap/1.0/"><xmp:CreatorTool>' . $x($producer) . '</xmp:CreatorTool><xmp:CreateDate>' . self::xmp_date($ts) . '</xmp:CreateDate><xmp:ModifyDate>' . self::xmp_date($ts) . '</xmp:ModifyDate></rdf:Description>' . "\n"
            . (string) $this->meta['xmp_extra']
            . ($this->meta['xmp_schemas'] !== '' ? '<rdf:Description rdf:about="" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/" xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#"><pdfaExtension:schemas><rdf:Bag>' . $this->meta['xmp_schemas'] . '</rdf:Bag></pdfaExtension:schemas></rdf:Description>' . "\n" : '')
            . '</rdf:RDF></x:xmpmeta>' . "\n" . '<?xpacket end="w"?>';
        $md = $add($stream('<</Type /Metadata /Subtype /XML>>', $xmp, false));
        $info = $add(sprintf('<</Title %s /Author %s /Producer %s /Creator %s /CreationDate (%s) /ModDate (%s)>>',
            self::pdf_string($title), self::pdf_string($author), self::pdf_string($producer), self::pdf_string($producer), self::pdf_date($ts), self::pdf_date($ts)));
        $objs[0] = '<</Type /Catalog /Pages 2 0 R /Metadata ' . $md . ' 0 R /OutputIntents [' . $oi . ' 0 R] /Lang (de-DE)'
            . ($names ? ' /Names <</EmbeddedFiles <</Names [' . implode(' ', $names) . ']>>>> /AF [' . implode(' ', $af) . ']' : '') . '>>';
        // Schreiben
        $out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offs = [];
        foreach ($objs as $i => $body) {
            $offs[$i + 1] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objs); $i++) $out .= sprintf("%010d 00000 n \n", $offs[$i]);
        $id = md5($out . $title . $ts);
        $out .= "trailer\n<</Size " . (count($objs) + 1) . " /Root 1 0 R /Info " . $info . " 0 R /ID [<" . $id . "> <" . $id . ">]>>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $out;
    }
}
