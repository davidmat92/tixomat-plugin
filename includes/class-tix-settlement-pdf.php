<?php
if (!defined('ABSPATH')) exit;

/**
 * PDF-Belege zur Veranstalter-Abrechnung (ohne externe Bibliothek, über TIX_Simple_PDF):
 *
 *  - „settlement“: Abrechnung (Vermittlung) bzw. Gutschrift (Eigenhandel) bzw. Abschlag,
 *    fortlaufende Nummer EV-JJJJ-NNNN, mit Bestellliste.
 *  - „invoice“:    Rechnung über die Plattform- und weiterberechneten Zahlungsgebühren
 *    (nur Vermittlung, EVR-JJJJ-NNNN), bereits mit der Auszahlung verrechnet.
 *
 * Erzeugt wird immer aus den gespeicherten Daten (Summen, Positionen, Snapshot zum Zeitpunkt
 * der Abrechnung/Freigabe); im PDF steht die IBAN nur gekürzt.
 */
class TIX_Settlement_PDF {

    const W = 595; // A4 in pt
    const H = 842;
    const M = 50;

    private static $widths = [
        '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556,
        ',' => 278, '.' => 278, ' ' => 278, '-' => 333, '+' => 584, 'E' => 667, 'U' => 722, 'R' => 722, '%' => 889, '(' => 333, ')' => 333,
    ];

    public static function filename($s, $doc = 'settlement') {
        if ($doc === 'invoice') return 'Rechnung-' . $s->invoice_number . '.pdf';
        $label = $s->type === 'advance' ? 'Abschlag' : ($s->tax_mode === 'reseller' ? 'Gutschrift' : 'Abrechnung');
        return $label . '-' . $s->number . '.pdf';
    }

    /** @return array|null ['bytes' => string, 'filename' => string] */
    public static function render($s, $doc = 'settlement') {
        if (!$s || $s->status === 'draft' || $s->status === 'void') return null;
        if (!class_exists('TIX_Simple_PDF') && defined('TIXOMAT_PATH')) require_once TIXOMAT_PATH . 'includes/class-tix-event-report.php';
        if (!class_exists('TIX_Simple_PDF')) return null;
        if ($doc === 'invoice') {
            if ($s->invoice_number === '' || floatval($s->invoice_total) <= 0 || $s->tax_mode !== 'agency') return null;
            $bytes = self::invoice($s);
        } else {
            $bytes = self::settlement($s);
        }
        return ['bytes' => $bytes, 'filename' => self::filename($s, $doc)];
    }

    // ──────────────────────────────────────────
    //  Hilfen
    // ──────────────────────────────────────────

    private static function eur($v) {
        return number_format(floatval($v), 2, ',', '.') . ' EUR';
    }

    private static function text_width($str, $size) {
        $w = 0;
        foreach (preg_split('//u', (string) $str, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $w += self::$widths[$ch] ?? 556;
        }
        return $w * $size / 1000;
    }

    private static function rtext($pdf, $x_right, $y, $str, $size) {
        $pdf->text(intval(round($x_right - self::text_width($str, $size))), $y, $str);
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

    private static function issuer_lines() {
        $g = function ($k) { return trim((string) tix_get_settings($k)); };
        $lines = [];
        $name = $g('invoice_company_name') ?: wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $lines[] = $name;
        foreach (preg_split('/\r\n|\r|\n/', $g('invoice_company_address')) as $ln) { if (trim($ln) !== '') $lines[] = trim($ln); }
        $tax = [];
        if ($g('invoice_company_tax_id')) $tax[] = 'Steuernr. ' . $g('invoice_company_tax_id');
        if ($g('invoice_company_ust_id')) $tax[] = 'USt-IdNr. ' . $g('invoice_company_ust_id');
        if ($tax) $lines[] = implode(' · ', $tax);
        $c = array_filter([$g('invoice_email'), $g('invoice_phone')]);
        if ($c) $lines[] = implode(' · ', $c);
        return $lines;
    }

    private static function footer_text() {
        $g = function ($k) { return trim((string) tix_get_settings($k)); };
        $parts = [];
        $parts[] = $g('invoice_company_name') ?: wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        if ($g('invoice_managing_director')) $parts[] = 'Geschäftsführung: ' . $g('invoice_managing_director');
        if ($g('invoice_register_court') || $g('invoice_register_number')) $parts[] = trim($g('invoice_register_court') . ' ' . $g('invoice_register_number'));
        return implode(' · ', $parts) . ' · Seite {p}/{n}';
    }

    private static function recipient_lines($snap) {
        $b = is_array($snap['billing'] ?? null) ? $snap['billing'] : [];
        $lines = [];
        if (!empty($b['company'])) $lines[] = $b['company'];
        if (!empty($b['name'])) $lines[] = $b['name'];
        if (!$lines) $lines[] = (string) ($snap['organizer'] ?? '');
        if (!empty($b['street'])) $lines[] = $b['street'];
        $city = trim(($b['zip'] ?? '') . ' ' . ($b['city'] ?? ''));
        if ($city !== '') $lines[] = $city;
        if (!empty($b['country']) && strtoupper($b['country']) !== 'DE') $lines[] = strtoupper($b['country']);
        return $lines;
    }

    private static function recipient_tax($snap) {
        $st = (string) ($snap['tax_status'] ?? '');
        if ($st === 'vat_id' && !empty($snap['vat_id'])) return 'USt-IdNr. ' . $snap['vat_id'];
        if ($st === 'tax_number' && !empty($snap['tax_number'])) return 'Steuernr. ' . $snap['tax_number'];
        if ($st === 'small_business') return 'Kleinunternehmer (§ 19 UStG)';
        return '';
    }

    /** Briefkopf + Empfänger + Titel/Metadaten; gibt die nächste y-Position zurück. */
    private static function head($pdf, $s, $title, array $meta) {
        $snap = TIX_Settlement::snap($s);
        $m = self::M;
        $logo = (string) tix_get_settings('email_logo_url');
        if ($logo && $pdf->load_logo($logo)) $pdf->draw_logo(self::W - $m - 120, self::H - $m - 40, 120, 40);

        // Absender
        $pdf->set_color(0.45, 0.45, 0.45);
        $pdf->set_font('Helvetica', 8);
        $y = self::H - $m - 4;
        foreach (self::issuer_lines() as $ln) { $pdf->text($m, $y, $ln); $y -= 11; }

        // Empfänger
        $pdf->set_color(0, 0, 0);
        $pdf->set_font('Helvetica', 10);
        $y = self::H - 170;
        foreach (self::recipient_lines($snap) as $ln) { $pdf->text($m, $y, $ln); $y -= 13; }
        $tax = self::recipient_tax($snap);
        if ($tax) { $pdf->set_color(0.4, 0.4, 0.4); $pdf->set_font('Helvetica', 8); $pdf->text($m, $y - 2, $tax); $pdf->set_color(0, 0, 0); }

        // Metadaten rechts
        $pdf->set_font('Helvetica', 9);
        $my = self::H - 170;
        foreach ($meta as $label => $value) {
            if ($value === '' || $value === null) continue;
            $pdf->set_color(0.4, 0.4, 0.4);
            $pdf->text(340, $my, $label);
            $pdf->set_color(0, 0, 0);
            $pdf->text(440, $my, mb_substr((string) $value, 0, 28));
            $my -= 13;
        }

        $y = min($y, $my) - 30;
        $pdf->set_font('Helvetica-Bold', 16);
        $pdf->text($m, $y, $title);
        return $y - 28;
    }

    private static function row($pdf, $y, $label, $amount, $bold = false, $muted = false) {
        $pdf->set_font($bold ? 'Helvetica-Bold' : 'Helvetica', 10);
        if ($muted) $pdf->set_color(0.45, 0.45, 0.45);
        $pdf->text(self::M, $y, $label);
        self::rtext($pdf, self::W - self::M, $y, $amount, 10);
        $pdf->set_color(0, 0, 0);
        return $y - 16;
    }

    private static function line($pdf, $y) {
        $pdf->set_color(0.8, 0.8, 0.8);
        $pdf->rect(self::M, $y, self::W - 2 * self::M, 1, true);
        $pdf->set_color(0, 0, 0);
    }

    private static function paragraph($pdf, $y, $text, $size = 9, $chars = 105) {
        $pdf->set_font('Helvetica', $size);
        foreach (self::wrap($text, $chars) as $ln) { $pdf->text(self::M, $y, $ln); $y -= $size + 3; }
        return $y;
    }

    // ──────────────────────────────────────────
    //  Abrechnung / Gutschrift / Abschlag
    // ──────────────────────────────────────────

    private static function settlement($s) {
        $snap = TIX_Settlement::snap($s);
        $reseller = $s->tax_mode === 'reseller';
        $pdf = new TIX_Simple_PDF(self::W, self::H, self::M, 40);
        $pdf->add_page();
        $title = $s->type === 'advance' ? 'Abschlag ' . $s->number
            : (($reseller ? 'Gutschrift ' : 'Abrechnung ') . $s->number);
        $date = wp_date('d.m.Y', strtotime($s->created));
        $y = self::head($pdf, $s, $title, [
            'Nummer'      => $s->number,
            'Datum'       => $date,
            'Veranstalter'=> (string) ($snap['organizer'] ?? ''),
            'Event'       => $s->type === 'balance' ? 'Saldo-Abrechnung' : $s->event_title,
            'Event-Datum' => $s->event_date ? wp_date('d.m.Y', strtotime($s->event_date)) : '',
            'Zeitraum'    => ($s->period_from && $s->period_to) ? wp_date('d.m.Y', strtotime($s->period_from)) . ' – ' . wp_date('d.m.Y', strtotime($s->period_to)) : '',
        ]);

        if ($s->type === 'advance') {
            $y = self::row($pdf, $y, 'Abschlag auf die Ticketumsätze vor dem Event', self::eur($s->payout_amount), true);
            $y -= 6;
            $y = self::paragraph($pdf, $y, (string) $s->note . '. Der Abschlag wird mit der Abrechnung nach dem Event verrechnet.');
        } else {
            $y = self::row($pdf, $y, sprintf('Ticketumsatz (%d Tickets, %d Bestellungen)', $s->tickets, $s->orders), self::eur($s->gross));
            if (floatval($s->refunds) > 0) $y = self::row($pdf, $y, 'abzüglich Erstattungen', '-' . self::eur($s->refunds));
            if (floatval($s->platform_fee) > 0) $y = self::row($pdf, $y, 'abzüglich Plattformgebühr (Ihr Anteil)', '-' . self::eur($s->platform_fee));
            if (floatval($s->gateway_fees) > 0) $y = self::row($pdf, $y, 'abzüglich Zahlungsgebühren (weiterberechnet)', '-' . self::eur($s->gateway_fees));
            foreach (TIX_Settlement::items($s->id) as $it) {
                if (in_array($it->type, ['order'], true)) continue;
                if ($y < 120) { $pdf->add_page(); $y = self::H - 60; }
                $amt = floatval($it->amount);
                $y = self::row($pdf, $y, mb_substr(TIX_Settlement::item_label($it), 0, 80), ($amt < 0 ? '-' : '+') . self::eur(abs($amt)));
            }
            $y -= 2;
            self::line($pdf, $y + 10);
            $y = self::row($pdf, $y - 4, 'Auszahlungsbetrag', self::eur($s->payout_amount), true);
            $y -= 8;
            if (floatval($s->customer_fees) > 0) $y = self::row($pdf, $y, 'Info: von Kunden getragene Gebühren (nicht Teil Ihres Umsatzes)', self::eur($s->customer_fees), false, true);
            if (floatval($s->pos_total) > 0) $y = self::row($pdf, $y, 'Info: Kasse vor Ort (bereits bei Ihnen, nicht enthalten)', self::eur($s->pos_total), false, true);
        }

        // Steuer
        $y -= 10;
        if ($reseller && $s->type !== 'advance') {
            $base = floatval($s->payout_amount) + floatval($s->advances) - floatval($s->carry_over);
            if (($snap['tax_status'] ?? '') === 'small_business') {
                $y = self::paragraph($pdf, $y, 'Gutschrift über den Ankauf der Tickets. Der Leistende ist Kleinunternehmer; gemäß § 19 UStG wird keine Umsatzsteuer ausgewiesen.');
            } else {
                $rate = floatval(TIX_Settlement::opt('settlement_ticket_vat_rate'));
                $vat = round($base - $base / (1 + $rate / 100), 2);
                $y = self::paragraph($pdf, $y, sprintf('Gutschrift über den Ankauf der Tickets. Im Gutschriftsbetrag von %s ist Umsatzsteuer (%s %%) in Höhe von %s enthalten (Nettobetrag %s).',
                    self::eur($base), rtrim(rtrim(number_format($rate, 1, ',', ''), '0'), ','), self::eur($vat), self::eur($base - $vat)));
            }
        } elseif (!$reseller) {
            $txt = 'Die Ticketumsätze haben wir im Namen und für Rechnung des Veranstalters vereinnahmt.';
            if ($s->invoice_number !== '' && floatval($s->invoice_total) > 0) {
                $txt .= ' Unsere Gebühren berechnen wir mit Rechnung ' . $s->invoice_number . ' (' . self::eur($s->invoice_total) . ' brutto); sie sind im Auszahlungsbetrag bereits verrechnet.';
            }
            $y = self::paragraph($pdf, $y, $txt);
        }

        // Bank
        $y -= 8;
        $pdf->set_font('Helvetica', 9);
        $bank = 'Auszahlung auf: ' . ($snap['holder'] ?? '') . ($snap['iban_masked'] ? ' · IBAN ' . $snap['iban_masked'] : '');
        $pdf->text(self::M, $y, $bank);
        $y -= 13;
        $pdf->text(self::M, $y, 'Verwendungszweck: ' . TIX_Settlement::remittance($s));
        $y -= 13;
        $state = $s->status === 'paid' ? 'Ausgezahlt am ' . wp_date('d.m.Y', strtotime($s->paid_at))
            : ($s->due_date ? 'Geplante Auszahlung ab ' . wp_date('d.m.Y', strtotime($s->due_date)) : '');
        if ($s->status === 'held') $state = 'Auszahlung zurückgestellt' . ($s->held_reason ? ': ' . $s->held_reason : '');
        if ($state) $pdf->text(self::M, $y, $state);

        // Bestellliste
        $orders = array_values(array_filter(TIX_Settlement::items($s->id), fn($it) => $it->type === 'order'));
        if ($orders) {
            $pdf->add_page();
            $y = self::H - 60;
            $pdf->set_font('Helvetica-Bold', 12);
            $pdf->text(self::M, $y, 'Bestellungen zu ' . $s->number);
            $y -= 24;
            $headers = ['Bestellung', 'Tickets', 'Umsatz', 'Erstattung', 'Plattform', 'Zahlung', 'Netto'];
            $widths  = [120, 50, 65, 65, 65, 65, 65];
            $pdf->draw_table_header($headers, $widths, self::M, $y);
            $y -= 18;
            $pdf->set_font('Helvetica', 8);
            foreach ($orders as $it) {
                if ($y < 60) {
                    $pdf->add_page(); $y = self::H - 60;
                    $pdf->draw_table_header($headers, $widths, self::M, $y); $y -= 18; $pdf->set_font('Helvetica', 8);
                }
                $n = fn($v) => number_format(floatval($v), 2, ',', '.');
                $pdf->draw_table_row([
                    mb_substr((string) $it->note, 0, 24) . ($it->gateway_fee_source === 'rate' ? ' *' : ''),
                    (string) intval($it->tickets), $n($it->gross), $n($it->refund), $n($it->platform_fee), $n($it->gateway_fee), $n($it->amount),
                ], $widths, self::M, $y);
                $y -= 13;
            }
            if (array_filter($orders, fn($it) => $it->gateway_fee_source === 'rate')) {
                $y -= 6;
                $pdf->set_font('Helvetica', 7);
                $pdf->text(self::M, $y, '* Zahlungsgebühr nach zentralem Satz, weil die tatsächliche Gebühr des Zahlungsanbieters (noch) nicht abrufbar war.');
            }
        }

        $pdf->set_footer(self::footer_text());
        return $pdf->output();
    }

    // ──────────────────────────────────────────
    //  Gebührenrechnung (Vermittlung)
    // ──────────────────────────────────────────

    private static function invoice($s) {
        global $wpdb;
        $sum = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(platform_fee) AS pf, SUM(gateway_fee) AS gw FROM " . TIX_Settlement::items_table() . " WHERE settlement_id = %d AND type IN ('order','late')",
            intval($s->id)
        ));
        $pf = round(floatval($sum->pf ?? 0), 2);
        $gw = round(floatval($sum->gw ?? 0), 2);
        $total = round($pf + $gw, 2);
        $rate = floatval(TIX_Settlement::opt('settlement_fee_vat_rate'));
        $net = round($total / (1 + $rate / 100), 2);
        $vat = round($total - $net, 2);

        $pdf = new TIX_Simple_PDF(self::W, self::H, self::M, 40);
        $pdf->add_page();
        $leistung = $s->event_date ? wp_date('d.m.Y', strtotime($s->event_date))
            : (($s->period_from && $s->period_to) ? wp_date('d.m.Y', strtotime($s->period_from)) . ' – ' . wp_date('d.m.Y', strtotime($s->period_to)) : '');
        $y = self::head($pdf, $s, 'Rechnung ' . $s->invoice_number, [
            'Rechnungsnr.'  => $s->invoice_number,
            'Datum'         => wp_date('d.m.Y', strtotime($s->created)),
            'Leistung'      => $leistung,
            'Zu Abrechnung' => $s->number,
        ]);
        $what = $s->type === 'balance' ? 'Nachverkäufe/Korrekturen' : $s->event_title;
        if ($pf > 0) $y = self::row($pdf, $y, mb_substr('Plattformgebühr Ticketverkauf: ' . $what, 0, 80), self::eur($pf));
        if ($gw > 0) $y = self::row($pdf, $y, 'Zahlungsabwicklung (weiterberechnete Zahlungsgebühren)', self::eur($gw));
        self::line($pdf, $y + 10);
        $y = self::row($pdf, $y - 4, 'Nettobetrag', self::eur($net));
        $y = self::row($pdf, $y, sprintf('Umsatzsteuer %s %%', rtrim(rtrim(number_format($rate, 1, ',', ''), '0'), ',')), self::eur($vat));
        $y = self::row($pdf, $y, 'Rechnungsbetrag', self::eur($total), true);
        $y -= 12;
        $y = self::paragraph($pdf, $y, 'Der Rechnungsbetrag wurde mit dem Auszahlungsbetrag der Abrechnung ' . $s->number . ' verrechnet. Bitte nicht überweisen.');
        $pdf->set_footer(self::footer_text());
        return $pdf->output();
    }
}
