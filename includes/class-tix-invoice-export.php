<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Invoice_Export – Belege als CSV und als DATEV-Buchungsstapel (EXTF 700, Kategorie 21) (1.38.485).
 *
 * DATEV: je Beleg und Steuersatz eine Buchung Debitor (Sammeldebitor) an Erlöskonto; Korrekturen mit „H“.
 * Konten je Aussteller einstellbar (POST /studio/invoice-config {datev: {...}}), Vorgaben SKR03: Debitor 10000,
 * Erlöse 19 % 8400, 7 % 8300, 0 %/Kleinunternehmer 8200, Gutscheine/Guthaben (ohne USt) 1700. Vor der Nutzung mit dem
 * Steuerberater abstimmen (Kontenrahmen, Berater-/Mandantennummer, Automatikkonten).
 */
class TIX_Invoice_Export {

    private static function num($v) {
        return number_format(round(floatval($v), 2), 2, ',', '');
    }

    private static function kind_label($r) {
        if ($r->series === 'B') return 'Beleg';
        if ($r->series === 'BK') return 'Belegkorrektur';
        return $r->kind === 'correction' ? 'Rechnungskorrektur' : 'Rechnung';
    }

    /** → ['filename', 'data'] */
    public static function csv(array $rows) {
        $out = [];
        foreach ($rows as $r) {
            $d = json_decode((string) $r->data, true);
            $taxes = [];
            foreach ((array) ($d['taxes'] ?? []) as $t) if ($t['rate'] !== null) $taxes[] = TIX_Invoices::fmt_rate($t['rate']) . ': ' . self::num($t['net']) . ' + ' . self::num($t['tax']);
            $out[] = [
                $r->number, self::kind_label($r), wp_date('d.m.Y', strtotime($r->created)), $r->service_date ? wp_date('d.m.Y', strtotime($r->service_date . ' 12:00:00')) : '',
                $r->order_number, (string) ($d['issuer']['name'] ?? ''), $r->recipient_name, $r->recipient_email, (string) ($d['ref']['number'] ?? ''),
                self::num($r->net), self::num($r->tax), self::num($r->gross), implode(' | ', $taxes), $r->small_business ? 'ja' : '',
            ];
        }
        $data = class_exists('TIX_Export') ? TIX_Export::csv(['Nummer', 'Art', 'Datum', 'Leistungsdatum', 'Bestellung', 'Aussteller', 'Empfänger', 'E-Mail', 'Bezug', 'Netto (€)', 'USt (€)', 'Brutto (€)', 'Steuersätze (netto + USt)', 'Kleinunternehmer'], $out) : '';
        return ['filename' => 'rechnungen-' . current_time('Y-m-d') . '.csv', 'data' => $data];
    }

    private static function q($s) {
        return '"' . str_replace('"', '""', (string) $s) . '"';
    }

    /** → ['filename', 'data'] (Windows-1252, Semikolon, CRLF) */
    public static function datev(array $rows, $issuer_key, $from = '', $to = '') {
        $cfg = TIX_Invoices::datev_config($issuer_key);
        $len = max(4, min(8, intval($cfg['account_length'])));
        $first = $rows ? substr($rows[0]->created, 0, 10) : current_time('Y-m-d');
        $last = $rows ? substr($rows[count($rows) - 1]->created, 0, 10) : current_time('Y-m-d');
        $from = $from !== '' ? $from : $first;
        $to = $to !== '' ? $to : $last;
        $wj = substr($from, 0, 4) . '0101';
        $head = ['"EXTF"', '700', '21', '"Buchungsstapel"', '13', current_time('YmdHis') . '000', '', '"TX"', '"Tixomat"', '""',
            $cfg['consultant'] !== '' ? $cfg['consultant'] : '', $cfg['client'] !== '' ? $cfg['client'] : '', $wj, (string) $len,
            str_replace('-', '', $from), str_replace('-', '', $to), self::q('Rechnungen ' . $from . ' bis ' . $to), '""', '1', '0', '0', '"EUR"',
            '', '""', '', '', '', '""', '', '', '""'];
        $cols = ['Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz', 'Kurs', 'Basis-Umsatz', 'WKZ Basis-Umsatz', 'Konto',
            'Gegenkonto (ohne BU-Schlüssel)', 'BU-Schlüssel', 'Belegdatum', 'Belegfeld 1', 'Belegfeld 2', 'Skonto', 'Buchungstext'];
        $lines = [implode(';', $head), implode(';', $cols)];
        foreach ($rows as $r) {
            $d = json_decode((string) $r->data, true);
            $parts = [];
            if (in_array($r->series, ['B', 'BK'], true)) {
                $parts[] = [floatval($r->gross), $cfg['voucher']];
            } else {
                foreach ((array) ($d['taxes'] ?? []) as $t) {
                    $rate = $t['rate'] === null ? 0 : floatval($t['rate']);
                    $acc = $r->small_business || $rate < 0.01 ? $cfg['revenue_0'] : ($rate >= 15 ? $cfg['revenue_19'] : $cfg['revenue_7']);
                    $parts[] = [floatval($t['gross']), $acc];
                }
            }
            $text = mb_substr(trim(($r->recipient_name !== '' ? $r->recipient_name . ' ' : '') . $r->order_number), 0, 60);
            foreach ($parts as $p) {
                if (abs($p[0]) < 0.005) continue;
                $sh = $p[0] >= 0 ? 'S' : 'H';
                $lines[] = implode(';', [self::num(abs($p[0])), self::q($sh), self::q('EUR'), '', '', '""', $cfg['debitor'], $p[1], '""',
                    wp_date('dm', strtotime($r->created)), self::q(mb_substr($r->number, 0, 36)), self::q(mb_substr($r->order_number, 0, 12)), '', self::q($text)]);
            }
        }
        $data = implode("\r\n", $lines) . "\r\n";
        if (function_exists('iconv')) {
            $c = @iconv('UTF-8', 'CP1252//TRANSLIT', $data);
            if ($c !== false) $data = $c;
        }
        return ['filename' => 'EXTF_Buchungsstapel_' . str_replace('-', '', $from) . '_' . str_replace('-', '', $to) . '.csv', 'data' => $data];
    }
}
