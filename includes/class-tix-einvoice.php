<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_EInvoice – E-Rechnung nach EN 16931 im CII-Format (UN/CEFACT Cross Industry Invoice D16B), Profil
 * ZUGFeRD 2.x / Factur-X „EN 16931“ (Guideline urn:cen.eu:en16931:2017) (1.38.500).
 *
 * Aus dem gespeicherten Schnappschuss eines Belegs (tix_invoices.data), nie aus aktuellen Daten.
 *  - Rechnung R → TypeCode 380; Korrektur RK → 381 (Gutschrift) mit positiven Beträgen und Bezug auf die Rechnung (BT-25).
 *  - Belege ohne USt (B/BK, Mehrzweck-Gutschein) sind keine Rechnungen → kein XML.
 *  - Positionen netto je Steuersatz anteilig (Summe je Satz = Bemessungsgrundlage, BR-S-08), Steuer je Satz aus dem
 *    gespeicherten Schnappschuss; negative Positionen (Rabatt) mit Menge -1 und positivem Preis (BR-27).
 *  - Kleinunternehmer: Kategorie E mit Befreiungsgrund § 19 UStG; bereits bezahlt: TotalPrepaid = Gesamt, DuePayable 0.
 */
class TIX_EInvoice {

    const NS_RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    const NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    const NS_QDT = 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100';
    const NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';
    const GUIDELINE = 'urn:cen.eu:en16931:2017';

    public static function supported($inv) {
        return $inv && in_array($inv->series, ['R', 'RK'], true);
    }

    public static function filename($inv) {
        return 'E-Rechnung-' . $inv->number . '.xml';
    }

    private static function amt($v) {
        return number_format(round(floatval($v), 2), 2, '.', '');
    }

    private static function rate($v) {
        return rtrim(rtrim(number_format(floatval($v), 2, '.', ''), '0'), '.') ?: '0';
    }

    private static function date102($ymd) {
        return gmdate('Ymd', strtotime(substr((string) $ymd, 0, 10) . ' 12:00:00'));
    }

    /** Anschrift aus Zeilen → [line, zip, city, country]; letzte Zeile „PLZ Ort“ wird zerlegt. */
    private static function address(array $lines, $owner = '', $country = 'DE') {
        $lines = array_values(array_filter(array_map('trim', $lines), function ($l) use ($owner) { return $l !== '' && $l !== $owner; }));
        $zip = ''; $city = ''; $street = '';
        if ($lines) {
            $last = $lines[count($lines) - 1];
            if (preg_match('/^(\d{4,5})\s+(.+)$/u', $last, $m)) { $zip = $m[1]; $city = $m[2]; array_pop($lines); }
            elseif (preg_match('/^([A-Z]{1,2}-?\d{4,5})\s+(.+)$/u', $last, $m)) { $zip = $m[1]; $city = $m[2]; array_pop($lines); }
            if (count($lines) && preg_match('/^[A-Z]{2}$/', $lines[count($lines) - 1])) $country = array_pop($lines);
            if ($city === '' && count($lines) > 1) $city = array_pop($lines);
            $street = implode(', ', $lines);
        }
        return [$street, $zip, $city, strtoupper($country ?: 'DE')];
    }

    /** CII-XML (UTF-8) oder '' (kein Rechnungs-Beleg). */
    public static function xml($inv) {
        if (!self::supported($inv)) return '';
        $d = json_decode((string) $inv->data, true);
        if (!is_array($d)) return '';
        $credit = $inv->series === 'RK';
        $sign = $credit ? -1 : 1;
        $iss = (array) ($d['issuer'] ?? []);
        $rcp = (array) ($d['recipient'] ?? []);
        $sb = !empty($inv->small_business);
        $lines = (array) ($d['lines'] ?? []);
        $taxes = (array) ($d['taxes'] ?? []);

        // Netto je Satz (aus dem Schnappschuss) anteilig auf die Positionen verteilen
        $by = [];
        foreach ($lines as $i => $l) { $k = number_format(floatval($l['rate'] ?? 0), 2, '.', ''); $by[$k][] = $i; }
        $tax_of = [];
        foreach ($taxes as $t) $tax_of[number_format(floatval($t['rate'] ?? 0), 2, '.', '')] = $t;
        $net = [];
        foreach ($by as $k => $idx) {
            $gross_sum = 0.0; foreach ($idx as $i) $gross_sum += floatval($lines[$i]['gross']);
            $net_sum = isset($tax_of[$k]) ? floatval($tax_of[$k]['net']) : $gross_sum;
            $left = round($net_sum, 2);
            foreach ($idx as $n => $i) {
                $part = $n === count($idx) - 1 ? $left : ($gross_sum != 0 ? round($net_sum * floatval($lines[$i]['gross']) / $gross_sum, 2) : 0.0);
                $net[$i] = round($part, 2);
                $left = round($left - $part, 2);
            }
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElementNS(self::NS_RSM, 'rsm:CrossIndustryInvoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ram', self::NS_RAM);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:qdt', self::NS_QDT);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:udt', self::NS_UDT);
        $doc->appendChild($root);
        $el = function ($parent, $name, $text = null, array $attr = []) use ($doc) {
            $ns = strpos($name, 'rsm:') === 0 ? self::NS_RSM : (strpos($name, 'udt:') === 0 ? self::NS_UDT : (strpos($name, 'qdt:') === 0 ? self::NS_QDT : self::NS_RAM));
            $e = $doc->createElementNS($ns, $name);
            if ($text !== null) $e->appendChild($doc->createTextNode((string) $text));
            foreach ($attr as $k => $v) $e->setAttribute($k, (string) $v);
            $parent->appendChild($e);
            return $e;
        };

        $ctx = $el($root, 'rsm:ExchangedDocumentContext');
        $el($el($ctx, 'ram:GuidelineSpecifiedDocumentContextParameter'), 'ram:ID', self::GUIDELINE);

        $ed = $el($root, 'rsm:ExchangedDocument');
        $el($ed, 'ram:ID', $inv->number);
        $el($ed, 'ram:TypeCode', $credit ? '381' : '380');
        $el($el($ed, 'ram:IssueDateTime'), 'udt:DateTimeString', self::date102($inv->created), ['format' => '102']);
        $notes = [];
        if ($sb) $notes[] = 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.';
        if (!empty($iss['on_behalf']) && !empty($iss['organizer_id'])) $notes[] = 'Rechnung im Namen und für Rechnung von ' . ($iss['name'] ?? '') . ', erstellt von ' . $iss['on_behalf'] . '.';
        $notes[] = 'Bestellung ' . $inv->order_number;
        foreach ($notes as $n) $el($el($ed, 'ram:IncludedNote'), 'ram:Content', $n);

        $tx = $el($root, 'rsm:SupplyChainTradeTransaction');
        foreach ($lines as $i => $l) {
            $qty = max(1, intval($l['qty'] ?? 1));
            $line_net = round($sign * $net[$i], 2);
            if ($line_net < 0) { $qty = -$qty; }
            $price = abs($qty) > 0 ? round(abs($line_net) / abs($qty), 4) : abs($line_net);
            // Menge × Preis soll die Positionssumme ergeben: sonst Menge 1 mit Gesamtpreis
            if (round(abs($qty) * $price, 2) != round(abs($line_net), 2)) { $qty = $line_net < 0 ? -1 : 1; $price = abs($line_net); }
            $li = $el($tx, 'ram:IncludedSupplyChainTradeLineItem');
            $el($el($li, 'ram:AssociatedDocumentLineDocument'), 'ram:LineID', (string) ($i + 1));
            $name = trim((string) ($l['text'] ?? '')) ?: 'Position';
            $prod = $el($li, 'ram:SpecifiedTradeProduct');
            $el($prod, 'ram:Name', mb_substr($name, 0, 250));
            if (!empty($l['detail'])) $el($prod, 'ram:Description', mb_substr((string) $l['detail'], 0, 500));
            $el($el($el($li, 'ram:SpecifiedLineTradeAgreement'), 'ram:NetPriceProductTradePrice'), 'ram:ChargeAmount', rtrim(rtrim(number_format($price, 4, '.', ''), '0'), '.') ?: '0');
            $el($el($li, 'ram:SpecifiedLineTradeDelivery'), 'ram:BilledQuantity', (string) $qty, ['unitCode' => 'C62']);
            $set = $el($li, 'ram:SpecifiedLineTradeSettlement');
            $tt = $el($set, 'ram:ApplicableTradeTax');
            $el($tt, 'ram:TypeCode', 'VAT');
            $r = floatval($l['rate'] ?? 0);
            $el($tt, 'ram:CategoryCode', $sb ? 'E' : ($r > 0 ? 'S' : 'Z'));
            $el($tt, 'ram:RateApplicablePercent', self::rate($sb ? 0 : $r));
            $el($el($set, 'ram:SpecifiedTradeSettlementLineMonetarySummation'), 'ram:LineTotalAmount', self::amt($line_net));
        }

        // Käufer/Verkäufer
        $ag = $el($tx, 'ram:ApplicableHeaderTradeAgreement');
        $seller = $el($ag, 'ram:SellerTradeParty');
        $el($seller, 'ram:Name', (string) ($iss['name'] ?? ''));
        list($s_street, $s_zip, $s_city, $s_cc) = isset($iss['street']) && ($iss['zip'] ?? '') !== ''
            ? [(string) $iss['street'], (string) $iss['zip'], (string) $iss['city'], (string) ($iss['country'] ?? 'DE')]
            : self::address((array) ($iss['address_lines'] ?? []), (string) ($iss['owner'] ?? ''), (string) ($iss['country'] ?? 'DE'));
        if (!empty($iss['email'])) {
            $ct = $el($seller, 'ram:DefinedTradeContact');
            $el($ct, 'ram:PersonName', (string) ($iss['owner'] ?: $iss['name']));
            $el($el($ct, 'ram:EmailURIUniversalCommunication'), 'ram:URIID', (string) $iss['email']);
        }
        $sa = $el($seller, 'ram:PostalTradeAddress');
        if ($s_zip !== '') $el($sa, 'ram:PostcodeCode', $s_zip);
        if ($s_street !== '') $el($sa, 'ram:LineOne', $s_street);
        if ($s_city !== '') $el($sa, 'ram:CityName', $s_city);
        $el($sa, 'ram:CountryID', $s_cc ?: 'DE');
        if (!empty($iss['email'])) $el($el($seller, 'ram:URIUniversalCommunication'), 'ram:URIID', (string) $iss['email'], ['schemeID' => 'EM']);
        if (!empty($iss['tax_id'])) $el($el($seller, 'ram:SpecifiedTaxRegistration'), 'ram:ID', (string) $iss['tax_id'], ['schemeID' => 'FC']);
        if (!empty($iss['vat_id'])) $el($el($seller, 'ram:SpecifiedTaxRegistration'), 'ram:ID', strtoupper(str_replace(' ', '', (string) $iss['vat_id'])), ['schemeID' => 'VA']);

        $buyer = $el($ag, 'ram:BuyerTradeParty');
        $bname = trim((string) ($rcp['company'] ?? '')) ?: (trim((string) ($rcp['name'] ?? '')) ?: (string) ($rcp['email'] ?? 'Kunde'));
        $el($buyer, 'ram:Name', $bname);
        $blines = (array) ($rcp['lines'] ?? []);
        $blines = array_values(array_filter($blines, function ($l) use ($rcp) { return $l !== ($rcp['company'] ?? null) && $l !== ($rcp['name'] ?? null); }));
        list($b_street, $b_zip, $b_city, $b_cc) = self::address($blines, '', 'DE');
        $ba = $el($buyer, 'ram:PostalTradeAddress');
        if ($b_zip !== '') $el($ba, 'ram:PostcodeCode', $b_zip);
        if ($b_street !== '') $el($ba, 'ram:LineOne', $b_street);
        if ($b_city !== '') $el($ba, 'ram:CityName', $b_city);
        $el($ba, 'ram:CountryID', $b_cc);
        if (!empty($rcp['email'])) $el($el($buyer, 'ram:URIUniversalCommunication'), 'ram:URIID', (string) $rcp['email'], ['schemeID' => 'EM']);

        // Lieferung (Leistungsdatum = Veranstaltungstag bzw. Rechnungsdatum)
        $dl = $el($tx, 'ram:ApplicableHeaderTradeDelivery');
        $el($el($el($dl, 'ram:ActualDeliverySupplyChainEvent'), 'ram:OccurrenceDateTime'), 'udt:DateTimeString', self::date102($inv->service_date ?: $inv->created), ['format' => '102']);

        // Abrechnung
        $st = $el($tx, 'ram:ApplicableHeaderTradeSettlement');
        $el($st, 'ram:InvoiceCurrencyCode', 'EUR');
        $tax_total = 0.0; $basis_total = 0.0;
        foreach ($taxes as $t) {
            $r = floatval($t['rate'] ?? 0);
            $calc = round($sign * floatval($t['tax']), 2); $basis = round($sign * floatval($t['net']), 2);
            $tax_total += $calc; $basis_total += $basis;
            $at = $el($st, 'ram:ApplicableTradeTax');
            $el($at, 'ram:CalculatedAmount', self::amt($sb ? 0 : $calc));
            $el($at, 'ram:TypeCode', 'VAT');
            if ($sb) $el($at, 'ram:ExemptionReason', 'Kleinunternehmer gemäß § 19 UStG');
            $el($at, 'ram:BasisAmount', self::amt($basis));
            $el($at, 'ram:CategoryCode', $sb ? 'E' : ($r > 0 ? 'S' : 'Z'));
            $el($at, 'ram:RateApplicablePercent', self::rate($sb ? 0 : $r));
        }
        $paid = !$credit;
        $el($el($st, 'ram:SpecifiedTradePaymentTerms'), 'ram:Description', $credit ? 'Erstattung auf dem ursprünglichen Zahlungsweg.' : 'Bereits bezahlt' . (!empty($d['payment_method']) ? ' (' . $d['payment_method'] . ')' : '') . '.');
        $line_total = 0.0; foreach ($lines as $i => $l) $line_total += round($sign * $net[$i], 2);
        $grand = round($basis_total + ($sb ? 0 : $tax_total), 2);
        $sum = $el($st, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation');
        $el($sum, 'ram:LineTotalAmount', self::amt($line_total));
        $el($sum, 'ram:TaxBasisTotalAmount', self::amt($basis_total));
        $el($sum, 'ram:TaxTotalAmount', self::amt($sb ? 0 : $tax_total), ['currencyID' => 'EUR']);
        $el($sum, 'ram:GrandTotalAmount', self::amt($grand));
        $el($sum, 'ram:TotalPrepaidAmount', self::amt($paid ? $grand : 0));
        $el($sum, 'ram:DuePayableAmount', self::amt($paid ? 0 : $grand));
        if ($credit && !empty($d['ref']['number'])) {
            $rd = $el($st, 'ram:InvoiceReferencedDocument');
            $el($rd, 'ram:IssuerAssignedID', (string) $d['ref']['number']);
            if (!empty($d['ref']['date'])) $el($el($rd, 'ram:FormattedIssueDateTime'), 'qdt:DateTimeString', self::date102($d['ref']['date']), ['format' => '102']);
        }
        return $doc->saveXML();
    }

    /** XMP-Bausteine für Factur-X/ZUGFeRD (fx-Namensraum + pdfaExtension-Schema). */
    public static function xmp_parts() {
        $ns = 'urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#';
        $extra = '<rdf:Description rdf:about="" xmlns:fx="' . $ns . '"><fx:DocumentType>INVOICE</fx:DocumentType><fx:DocumentFileName>factur-x.xml</fx:DocumentFileName><fx:Version>1.0</fx:Version><fx:ConformanceLevel>EN 16931</fx:ConformanceLevel></rdf:Description>' . "\n";
        $prop = function ($name, $desc) { return '<rdf:li rdf:parseType="Resource"><pdfaProperty:name>' . $name . '</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>' . $desc . '</pdfaProperty:description></rdf:li>'; };
        $schema = '<rdf:li rdf:parseType="Resource"><pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema><pdfaSchema:namespaceURI>' . $ns . '</pdfaSchema:namespaceURI><pdfaSchema:prefix>fx</pdfaSchema:prefix><pdfaSchema:property><rdf:Seq>'
            . $prop('DocumentFileName', 'The name of the embedded XML document') . $prop('DocumentType', 'The type of the hybrid document in capital letters, e.g. INVOICE or ORDER')
            . $prop('Version', 'The actual version of the standard applying to the embedded XML document') . $prop('ConformanceLevel', 'The conformance level of the embedded XML document')
            . '</rdf:Seq></pdfaSchema:property></rdf:li>';
        return ['xmp_extra' => $extra, 'xmp_schemas' => $schema];
    }

    /**
     * Eigene Prüfung der Rechenregeln (BR-CO-10/13/14/15/16, BR-S-08) – ersetzt keine Validierung, fängt aber Rundungsfehler.
     * @return string[] Fehler (leer = ok)
     */
    public static function check($xml) {
        $errs = [];
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml)) return ['XML nicht lesbar'];
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('ram', self::NS_RAM);
        $v = function ($q) use ($xp) { $n = $xp->query($q); return $n->length ? floatval($n->item(0)->nodeValue) : null; };
        $lines = 0.0; $by = [];
        foreach ($xp->query('//ram:IncludedSupplyChainTradeLineItem') as $li) {
            $amt = floatval($xp->query('.//ram:LineTotalAmount', $li)->item(0)->nodeValue);
            $rate = $xp->query('.//ram:RateApplicablePercent', $li)->item(0)->nodeValue;
            $cat = $xp->query('.//ram:CategoryCode', $li)->item(0)->nodeValue;
            $lines += $amt; $by[$cat . '|' . floatval($rate)] = ($by[$cat . '|' . floatval($rate)] ?? 0) + $amt;
            if (floatval($xp->query('.//ram:ChargeAmount', $li)->item(0)->nodeValue) < 0) $errs[] = 'BR-27 negativer Preis';
        }
        $sum_lines = $v('//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:LineTotalAmount');
        if (abs($sum_lines - $lines) > 0.001) $errs[] = 'BR-CO-10 Summe Positionen';
        $tax = 0.0; $basis = 0.0;
        foreach ($xp->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax') as $t) {
            $b = floatval($xp->query('ram:BasisAmount', $t)->item(0)->nodeValue); $c = floatval($xp->query('ram:CalculatedAmount', $t)->item(0)->nodeValue);
            $cat = $xp->query('ram:CategoryCode', $t)->item(0)->nodeValue; $r = floatval($xp->query('ram:RateApplicablePercent', $t)->item(0)->nodeValue);
            $tax += $c; $basis += $b;
            if (abs(($by[$cat . '|' . $r] ?? 0) - $b) > 0.001) $errs[] = 'BR-S-08 Bemessung ' . $r . ' %';
            if ($cat === 'S' && abs(round($b * $r / 100, 2) - $c) > 0.011) $errs[] = 'BR-S-09 Steuer ' . $r . ' %';
        }
        if (abs($v('//ram:TaxBasisTotalAmount') - $sum_lines) > 0.001) $errs[] = 'BR-CO-13';
        if (abs($v('//ram:TaxTotalAmount') - $tax) > 0.001) $errs[] = 'BR-CO-14';
        if (abs($v('//ram:GrandTotalAmount') - ($v('//ram:TaxBasisTotalAmount') + $v('//ram:TaxTotalAmount'))) > 0.001) $errs[] = 'BR-CO-15';
        if (abs($v('//ram:DuePayableAmount') - ($v('//ram:GrandTotalAmount') - $v('//ram:TotalPrepaidAmount'))) > 0.001) $errs[] = 'BR-CO-16';
        return $errs;
    }
}
