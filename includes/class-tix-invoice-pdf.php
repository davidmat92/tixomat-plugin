<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Invoice_PDF – PDF zu Rechnung, Rechnungskorrektur und Beleg (1.38.485), ohne externe Bibliothek (TIX_Simple_PDF).
 * Erzeugt ausschließlich aus dem gespeicherten Schnappschuss des Belegs (tix_invoices.data), nie aus aktuellen Daten.
 * Inhalt nach § 14 UStG: Aussteller mit Steuernummer bzw. USt-IdNr., Empfänger, Datum, Nummer, Leistung und Leistungsdatum,
 * Netto, Steuersatz, Steuer, Brutto; Kleinunternehmer mit Hinweis § 19 UStG.
 */
class TIX_Invoice_PDF {

    const W = 595;
    const H = 842;
    const M = 50;

    private static $widths = [
        '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556,
        ',' => 278, '.' => 278, ' ' => 278, '-' => 333, '+' => 584, '%' => 889, '(' => 333, ')' => 333, ':' => 278, '/' => 278,
        'E' => 667, 'U' => 722, 'R' => 722, 'K' => 667, 'B' => 667, 'S' => 667, 'T' => 611,
    ];

    private static function eur($v) {
        return number_format(round(floatval($v), 2), 2, ',', '.') . ' EUR';
    }

    private static function num($v) {
        return number_format(round(floatval($v), 2), 2, ',', '.');
    }

    private static function text_width($str, $size, $bold = false) {
        $w = 0;
        foreach (preg_split('//u', (string) $str, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $w += self::$widths[$ch] ?? (preg_match('/[A-ZÄÖÜ]/u', $ch) ? 690 : (preg_match('/[mwMW]/u', $ch) ? 833 : 520));
        }
        return $w * $size / 1000 * ($bold ? 1.05 : 1);
    }

    private static function rtext($pdf, $x_right, $y, $str, $size, $bold = false) {
        $pdf->text(intval(round($x_right - self::text_width($str, $size, $bold))), $y, $str);
    }

    private static function wrap($text, $max) {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $para) {
            $line = '';
            foreach (preg_split('/\s+/', trim($para)) as $word) {
                if ($word === '') continue;
                if (mb_strlen($line . ' ' . $word) > $max && $line !== '') { $out[] = $line; $line = $word; }
                else $line = $line === '' ? $word : $line . ' ' . $word;
            }
            if ($line !== '') $out[] = $line;
        }
        return $out;
    }

    private static function hex_rgb($hex) {
        $h = ltrim((string) $hex, '#');
        if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        if (!preg_match('/^[0-9A-Fa-f]{6}$/', $h)) return [0.1, 0.1, 0.1];
        return [hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255];
    }

    private static function title($inv) {
        if ($inv->series === 'B') return 'Beleg';
        if ($inv->series === 'BK') return 'Belegkorrektur';
        if ($inv->kind === 'correction') return 'Rechnungskorrektur';
        return 'Rechnung';
    }

    private static function date_de($ymd) {
        $ts = strtotime(substr((string) $ymd, 0, 10) . ' 12:00:00');
        return $ts ? wp_date('d.m.Y', $ts) : '';
    }

    /** Fußzeile (zwei Zeilen) auf der aktuellen Seite. */
    private static function footer($pdf, array $iss) {
        $l1 = array_filter([$iss['name'] ?? '', implode(', ', array_filter((array) ($iss['address_lines'] ?? []), function ($l) use ($iss) { return $l !== ($iss['owner'] ?? ''); }))]);
        $l2 = [];
        if (!empty($iss['tax_id'])) $l2[] = 'Steuernr. ' . $iss['tax_id'];
        if (!empty($iss['vat_id'])) $l2[] = 'USt-IdNr. ' . $iss['vat_id'];
        if (!empty($iss['managing_director'])) $l2[] = 'Geschäftsführung: ' . $iss['managing_director'];
        if (!empty($iss['register'])) $l2[] = $iss['register'];
        if (!empty($iss['email'])) $l2[] = $iss['email'];
        $pdf->set_color(0.82, 0.82, 0.82);
        $pdf->rect(self::M, 56, self::W - 2 * self::M, 0.6, true);
        $pdf->set_color(0.45, 0.45, 0.45);
        $pdf->set_font('Helvetica', 7);
        $pdf->text(self::M, 45, mb_substr(implode(' · ', $l1), 0, 150));
        if ($l2) $pdf->text(self::M, 35, mb_substr(implode(' · ', $l2), 0, 150));
        $pdf->set_color(0, 0, 0);
    }

    private static function table_head($pdf, $y, array $rgb) {
        $pdf->set_color($rgb[0], $rgb[1], $rgb[2]);
        $pdf->rect(self::M, $y - 5, self::W - 2 * self::M, 18, true);
        $pdf->set_color(1, 1, 1);
        $pdf->set_font('Helvetica-Bold', 8);
        $pdf->text(self::M + 6, $y + 1, 'Pos.');
        $pdf->text(self::M + 34, $y + 1, 'Beschreibung');
        self::rtext($pdf, 372, $y + 1, 'Menge', 8, true);
        self::rtext($pdf, 444, $y + 1, 'Einzelpreis', 8, true);
        self::rtext($pdf, 484, $y + 1, 'USt', 8, true);
        self::rtext($pdf, self::W - self::M - 6, $y + 1, 'Betrag', 8, true);
        $pdf->set_color(0, 0, 0);
        return $y - 22;
    }

    /** @return string PDF-Bytes oder '' */
    public static function render($inv) {
        if (!class_exists('TIX_Simple_PDF') && defined('TIXOMAT_PATH')) require_once TIXOMAT_PATH . 'includes/class-tix-event-report.php';
        if (!class_exists('TIX_Simple_PDF')) return '';
        $d = json_decode((string) $inv->data, true);
        if (!is_array($d)) return '';
        $iss = (array) ($d['issuer'] ?? []);
        $rcp = (array) ($d['recipient'] ?? []);
        $voucher = in_array($inv->series, ['B', 'BK'], true);
        $rgb = self::hex_rgb($iss['accent'] ?? '#111111');
        // zu helle Akzentfarbe → dunkles Grau für Tabellenkopf (weiße Schrift)
        if (0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2] > 0.7) $rgb = [0.2, 0.2, 0.2];

        $pdf = new TIX_Simple_PDF(self::W, self::H, self::M, self::M);
        $pdf->add_page();
        $pdf->set_footer('Seite {p}/{n}');
        $m = self::M;

        // Akzentstreifen + Logo
        $pdf->set_color($rgb[0], $rgb[1], $rgb[2]);
        $pdf->rect(0, self::H - 8, self::W, 8, true);
        if (!empty($iss['logo']) && $pdf->load_logo((string) $iss['logo'])) $pdf->draw_logo(self::W - $m - 130, self::H - $m - 46, 130, 46);

        // Aussteller oben links
        $pdf->set_color(0, 0, 0);
        $pdf->set_font('Helvetica-Bold', 11);
        $pdf->text($m, self::H - $m - 6, mb_substr((string) ($iss['name'] ?? ''), 0, 60));
        $pdf->set_font('Helvetica', 8);
        $pdf->set_color(0.35, 0.35, 0.35);
        $y = self::H - $m - 19;
        foreach ((array) ($iss['address_lines'] ?? []) as $ln) { $pdf->text($m, $y, mb_substr((string) $ln, 0, 70)); $y -= 10; }
        $c = array_filter([(string) ($iss['email'] ?? ''), (string) ($iss['phone'] ?? '')]);
        if ($c) { $pdf->text($m, $y, implode(' · ', $c)); $y -= 10; }

        // Empfänger (Absenderzeile darüber)
        $y = self::H - 160;
        $pdf->set_font('Helvetica', 6);
        $pdf->set_color(0.5, 0.5, 0.5);
        $sender = array_filter(array_merge([(string) ($iss['name'] ?? '')], array_filter((array) ($iss['address_lines'] ?? []), function ($l) use ($iss) { return $l !== ($iss['owner'] ?? ''); })));
        $pdf->text($m, $y + 12, mb_substr(implode(' · ', $sender), 0, 95));
        $pdf->set_color(0, 0, 0);
        $pdf->set_font('Helvetica', 10);
        foreach ((array) ($rcp['lines'] ?? []) as $ln) { $pdf->text($m, $y, mb_substr((string) $ln, 0, 55)); $y -= 13; }

        // Angaben rechts
        $meta = [
            (in_array($inv->series, ['B', 'BK'], true) ? 'Beleg-Nr.' : 'Rechnungs-Nr.') => (string) $inv->number,
            'Datum' => self::date_de($inv->created),
        ];
        if (!$voucher && $inv->service_date) $meta['Leistungsdatum'] = self::date_de($inv->service_date);
        $meta['Bestellung'] = (string) $inv->order_number;
        if (!empty($d['ref'])) $meta['Bezug'] = (string) $d['ref']['number'];
        if (!empty($d['payment_method'])) $meta['Zahlart'] = (string) $d['payment_method'];
        $pdf->set_font('Helvetica', 9);
        $my = self::H - 160;
        foreach ($meta as $label => $value) {
            if ($value === '') continue;
            $pdf->set_color(0.4, 0.4, 0.4);
            $pdf->text(350, $my, $label);
            $pdf->set_color(0, 0, 0);
            $pdf->text(440, $my, mb_substr($value, 0, 26));
            $my -= 13;
        }

        $y = min($y, $my) - 26;
        $pdf->set_font('Helvetica-Bold', 17);
        $pdf->text($m, $y, self::title($inv) . ' ' . $inv->number);
        $y -= 18;
        $pdf->set_font('Helvetica', 9);
        $pdf->set_color(0.3, 0.3, 0.3);
        if (!empty($d['ref'])) {
            $pdf->text($m, $y, 'Korrektur zu ' . ($voucher ? 'Beleg ' : 'Rechnung ') . $d['ref']['number'] . ' vom ' . self::date_de($d['ref']['date']) . '.');
            $y -= 13;
        }
        if (!empty($iss['on_behalf']) && !empty($iss['organizer_id'])) {
            foreach (self::wrap('Rechnung im Namen und für Rechnung von ' . ($iss['name'] ?? '') . ', erstellt von ' . $iss['on_behalf'] . '.', 110) as $ln) { $pdf->text($m, $y, $ln); $y -= 12; }
        }
        $pdf->set_color(0, 0, 0);
        $y -= 10;

        // Positionen
        $y = self::table_head($pdf, $y, $rgb);
        $pos = 0;
        foreach ((array) ($d['lines'] ?? []) as $l) {
            $text = self::wrap((string) $l['text'], 50);
            $detail = $l['detail'] !== '' ? self::wrap((string) $l['detail'], 62) : [];
            $need = 14 + 11 * (count($text) - 1) + 10 * count($detail);
            if ($y - $need < 150) {
                self::footer($pdf, $iss);
                $pdf->add_page();
                $y = self::table_head($pdf, self::H - $m - 20, $rgb);
            }
            $pos++;
            $pdf->set_font('Helvetica', 9);
            $pdf->text($m + 6, $y, (string) $pos);
            self::rtext($pdf, 372, $y, (string) intval($l['qty']), 9);
            self::rtext($pdf, 444, $y, self::num($l['unit']), 9);
            self::rtext($pdf, 484, $y, $l['rate'] === null ? '-' : TIX_Invoices::fmt_rate($l['rate']), 9);
            self::rtext($pdf, self::W - $m - 6, $y, self::num($l['gross']), 9);
            foreach ($text as $tl) { $pdf->text($m + 34, $y, $tl); $y -= 11; }
            if ($detail) {
                $pdf->set_font('Helvetica', 8);
                $pdf->set_color(0.45, 0.45, 0.45);
                foreach ($detail as $dl) { $pdf->text($m + 34, $y, $dl); $y -= 10; }
                $pdf->set_color(0, 0, 0);
            }
            $pdf->set_color(0.9, 0.9, 0.9);
            $pdf->rect($m, $y + 4, self::W - 2 * $m, 0.5, true);
            $pdf->set_color(0, 0, 0);
            $y -= 6;
        }

        // Summen
        if ($y < 230) { self::footer($pdf, $iss); $pdf->add_page(); $y = self::H - $m - 20; }
        $y -= 8;
        $xr = self::W - $m - 6;
        $row = function ($label, $value, $bold = false, $muted = false) use ($pdf, &$y, $xr) {
            $pdf->set_font($bold ? 'Helvetica-Bold' : 'Helvetica', $bold ? 11 : 9);
            if ($muted) $pdf->set_color(0.4, 0.4, 0.4);
            $pdf->text(300, $y, $label);
            self::rtext($pdf, $xr, $y, $value, $bold ? 11 : 9, $bold);
            $pdf->set_color(0, 0, 0);
            $y -= $bold ? 17 : 13;
        };
        $taxes = (array) ($d['taxes'] ?? []);
        if (!$voucher && empty($inv->small_business)) {
            $row('Summe netto', self::eur($inv->net));
            foreach ($taxes as $tx) {
                if ($tx['rate'] === null) continue;
                $row('USt ' . TIX_Invoices::fmt_rate($tx['rate']) . ' auf ' . self::num($tx['net']), self::eur($tx['tax']), false, true);
            }
        }
        $pdf->set_color(0.8, 0.8, 0.8);
        $pdf->rect(300, $y + 9, self::W - $m - 300, 0.8, true);
        $pdf->set_color(0, 0, 0);
        $row($inv->kind === 'correction' ? 'Korrekturbetrag' : 'Gesamtbetrag', self::eur($inv->gross), true);
        $paid = 0.0;
        foreach ((array) ($d['payments'] ?? []) as $p) { $row((string) $p['label'], '-' . self::eur($p['amount']), false, true); $paid += floatval($p['amount']); }
        if ($paid > 0) $row('Zahlbetrag', self::eur(floatval($inv->gross) - $paid));

        // Hinweise
        $y -= 12;
        $notes = [];
        if ($inv->kind === 'correction') $notes[] = 'Der Betrag wird auf dem ursprünglichen Zahlungsweg erstattet.';
        elseif (!empty($d['payment_method'])) $notes[] = 'Bereits bezahlt (' . $d['payment_method'] . '). Vielen Dank!';
        if (!empty($inv->small_business)) $notes[] = 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.';
        if ($voucher) $notes[] = 'Mehrzweck-Gutschein bzw. Guthaben: Die Umsatzsteuer entsteht erst bei der Einlösung, dieser Beleg weist daher keine Umsatzsteuer aus.';
        if (!$voucher && $inv->service_date) $notes[] = 'Leistungsdatum ist der Veranstaltungstag.';
        if (!empty($iss['footer'])) $notes[] = (string) $iss['footer'];
        $pdf->set_font('Helvetica', 8);
        $pdf->set_color(0.3, 0.3, 0.3);
        foreach ($notes as $n) {
            foreach (self::wrap($n, 120) as $ln) {
                if ($y < 75) { self::footer($pdf, $iss); $pdf->add_page(); $y = self::H - $m - 20; $pdf->set_font('Helvetica', 8); $pdf->set_color(0.3, 0.3, 0.3); }
                $pdf->text($m, $y, $ln);
                $y -= 11;
            }
        }
        $pdf->set_color(0, 0, 0);
        self::footer($pdf, $iss);
        return (string) $pdf->output();
    }
}
