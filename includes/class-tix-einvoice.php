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
        // ohne Steuernummer/USt-IdNr. („Nummer folgt“, 1.38.525) kein XML: EN 16931 BR-S-02 verlangt sie
        return $inv && in_array($inv->series, ['R', 'RK'], true) && empty($inv->tax_id_missing);
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

    /** CII-XML (UTF-8) zu einem Beleg aus tix_invoices oder '' (kein Rechnungs-Beleg). */
    public static function xml($inv) {
        $doc = self::from_invoice($inv);
        return $doc ? self::render($doc) : '';
    }

    /**
     * Neutrales Dokument aus einem Beleg (tix_invoices): Beträge im Vorzeichen des Dokuments (Gutschrift 381 positiv),
     * Netto je Position anteilig aus dem gespeicherten Netto je Satz (BR-S-08).
     */
    public static function from_invoice($inv) {
        if (!self::supported($inv)) return null;
        $d = json_decode((string) $inv->data, true);
        if (!is_array($d)) return null;
        $credit = $inv->series === 'RK';
        $sign = $credit ? -1 : 1;
        $iss = (array) ($d['issuer'] ?? []);
        $rcp = (array) ($d['recipient'] ?? []);
        $sb = !empty($inv->small_business);
        $lines = (array) ($d['lines'] ?? []);
        $by = [];
        foreach ($lines as $i => $l) { $k = number_format(floatval($l['rate'] ?? 0), 2, '.', ''); $by[$k][] = $i; }
        $tax_of = [];
        foreach ((array) ($d['taxes'] ?? []) as $t) $tax_of[number_format(floatval($t['rate'] ?? 0), 2, '.', '')] = $t;
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
        $out_lines = [];
        foreach ($lines as $i => $l) {
            $out_lines[] = ['name' => (string) ($l['text'] ?? ''), 'desc' => (string) ($l['detail'] ?? ''), 'qty' => max(1, intval($l['qty'] ?? 1)),
                'net' => round($sign * $net[$i], 2), 'rate' => $sb ? 0.0 : floatval($l['rate'] ?? 0)];
        }
        $taxes = [];
        foreach ((array) ($d['taxes'] ?? []) as $t) $taxes[] = ['rate' => $sb ? 0.0 : floatval($t['rate'] ?? 0), 'net' => round($sign * floatval($t['net']), 2), 'tax' => $sb ? 0.0 : round($sign * floatval($t['tax']), 2)];
        list($s_street, $s_zip, $s_city, $s_cc) = isset($iss['street']) && ($iss['zip'] ?? '') !== ''
            ? [(string) $iss['street'], (string) $iss['zip'], (string) $iss['city'], (string) ($iss['country'] ?? 'DE')]
            : self::address((array) ($iss['address_lines'] ?? []), (string) ($iss['owner'] ?? ''), (string) ($iss['country'] ?? 'DE'));
        $blines = array_values(array_filter((array) ($rcp['lines'] ?? []), function ($l) use ($rcp) { return $l !== ($rcp['company'] ?? null) && $l !== ($rcp['name'] ?? null); }));
        list($b_street, $b_zip, $b_city, $b_cc) = self::address($blines, '', 'DE');
        $notes = [];
        if ($sb) $notes[] = 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.';
        if (!empty($iss['on_behalf']) && !empty($iss['organizer_id'])) $notes[] = 'Rechnung im Namen und für Rechnung von ' . ($iss['name'] ?? '') . ', erstellt von ' . $iss['on_behalf'] . '.';
        $notes[] = 'Bestellung ' . $inv->order_number;
        // Neuausstellung (1.38.525): neue Rechnung nennt die ersetzte, das Storno die neue
        $reissue = $credit && $inv->reason === 'reissue';
        if (!empty($d['replaces']['number'])) $notes[] = 'Ersetzt Rechnung ' . $d['replaces']['number'] . ' (Neuausstellung mit vollständigen Angaben).';
        if ($reissue) $notes[] = 'Storno wegen Neuausstellung' . (!empty($d['replaced_by']['number']) ? ' als Rechnung ' . $d['replaced_by']['number'] : '') . '.';
        return [
            'number' => (string) $inv->number, 'type' => $credit ? '381' : '380', 'issue_date' => substr((string) $inv->created, 0, 10),
            'service_date' => substr((string) ($inv->service_date ?: $inv->created), 0, 10), 'notes' => $notes, 'small_business' => $sb,
            'seller' => ['name' => (string) ($iss['name'] ?? ''), 'contact' => (string) (($iss['owner'] ?? '') ?: ($iss['name'] ?? '')), 'street' => $s_street, 'zip' => $s_zip, 'city' => $s_city,
                'country' => $s_cc, 'email' => (string) ($iss['email'] ?? ''), 'tax_id' => (string) ($iss['tax_id'] ?? ''), 'vat_id' => (string) ($iss['vat_id'] ?? '')],
            'buyer' => ['name' => trim((string) ($rcp['company'] ?? '')) ?: (trim((string) ($rcp['name'] ?? '')) ?: (string) ($rcp['email'] ?? 'Kunde')),
                'street' => $b_street, 'zip' => $b_zip, 'city' => $b_city, 'country' => $b_cc, 'email' => (string) ($rcp['email'] ?? ''), 'vat_id' => ''],
            'lines' => $out_lines, 'taxes' => $taxes,
            'prepaid' => !$credit,
            'terms' => $reissue ? 'Keine Erstattung, die Zahlung gilt für die neue Rechnung.' : ($credit ? 'Erstattung auf dem ursprünglichen Zahlungsweg.' : 'Bereits bezahlt' . (!empty($d['payment_method']) ? ' (' . $d['payment_method'] . ')' : '') . '.'),
            'ref' => $credit && !empty($d['ref']['number']) ? ['number' => (string) $d['ref']['number'], 'date' => (string) ($d['ref']['date'] ?? '')] : null,
            'due_date' => null,
        ];
    }

    /** Verkäufer „Plattform“ (Firmendaten der Seite) als Dokument-Partei. */
    public static function platform_seller() {
        $i = class_exists('TIX_Invoices') ? TIX_Invoices::issuer(TIX_Invoices::multi() ? 'platform' : 'site') : [];
        list($street, $zip, $city, $cc) = self::address((array) ($i['address_lines'] ?? []), '', 'DE');
        return ['name' => (string) ($i['name'] ?? ''), 'contact' => (string) (($i['managing_director'] ?? '') ?: ($i['name'] ?? '')), 'street' => $street, 'zip' => $zip, 'city' => $city, 'country' => $cc,
            'email' => (string) ($i['email'] ?? ''), 'tax_id' => (string) ($i['tax_id'] ?? ''), 'vat_id' => (string) ($i['vat_id'] ?? '')];
    }

    /** Käufer aus einem Abrechnungs-/Kanal-Schnappschuss (billing, vat_id, tax_number). */
    public static function snapshot_buyer(array $snap) {
        $b = (array) ($snap['billing'] ?? []);
        $name = trim((string) ($b['company'] ?? '')) ?: (trim((string) ($b['name'] ?? '')) ?: (string) ($snap['organizer'] ?? ($snap['name'] ?? 'Kunde')));
        return ['name' => $name, 'street' => (string) ($b['street'] ?? ''), 'zip' => (string) ($b['zip'] ?? ''), 'city' => (string) ($b['city'] ?? ''),
            'country' => strtoupper((string) (($b['country'] ?? '') ?: 'DE')), 'email' => (string) ($snap['email'] ?? ''),
            'vat_id' => ($snap['tax_status'] ?? '') === 'vat_id' ? (string) ($snap['vat_id'] ?? '') : ''];
    }

    /** Netto/Steuer aus einem Bruttobetrag je Satz (Steuer = Brutto − Netto, Netto gerundet). */
    public static function split_gross($gross, $rate) {
        $net = $rate > 0 ? round($gross / (1 + $rate / 100), 2) : round($gross, 2);
        return [$net, round($gross - $net, 2)];
    }

    /** Dokument → CII-XML. */
    public static function render(array $doc) {
        $sb = !empty($doc['small_business']);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS(self::NS_RSM, 'rsm:CrossIndustryInvoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ram', self::NS_RAM);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:qdt', self::NS_QDT);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:udt', self::NS_UDT);
        $dom->appendChild($root);
        $el = function ($parent, $name, $text = null, array $attr = []) use ($dom) {
            $ns = strpos($name, 'rsm:') === 0 ? self::NS_RSM : (strpos($name, 'udt:') === 0 ? self::NS_UDT : (strpos($name, 'qdt:') === 0 ? self::NS_QDT : self::NS_RAM));
            $e = $dom->createElementNS($ns, $name);
            if ($text !== null) $e->appendChild($dom->createTextNode((string) $text));
            foreach ($attr as $k => $v) $e->setAttribute($k, (string) $v);
            $parent->appendChild($e);
            return $e;
        };
        $cat = function ($r) use ($sb) { return $sb ? 'E' : ($r > 0 ? 'S' : 'Z'); };

        $ctx = $el($root, 'rsm:ExchangedDocumentContext');
        $el($el($ctx, 'ram:GuidelineSpecifiedDocumentContextParameter'), 'ram:ID', self::GUIDELINE);
        $ed = $el($root, 'rsm:ExchangedDocument');
        $el($ed, 'ram:ID', $doc['number']);
        $el($ed, 'ram:TypeCode', $doc['type']);
        $el($el($ed, 'ram:IssueDateTime'), 'udt:DateTimeString', self::date102($doc['issue_date']), ['format' => '102']);
        foreach ((array) $doc['notes'] as $n) $el($el($ed, 'ram:IncludedNote'), 'ram:Content', $n);

        $tx = $el($root, 'rsm:SupplyChainTradeTransaction');
        $line_total = 0.0;
        foreach (array_values((array) $doc['lines']) as $i => $l) {
            $line_net = round(floatval($l['net']), 2);
            $line_total += $line_net;
            $qty = max(1, intval($l['qty'] ?? 1));
            if ($line_net < 0) $qty = -$qty;
            $price = round(abs($line_net) / abs($qty), 4);
            if (round(abs($qty) * $price, 2) != round(abs($line_net), 2)) { $qty = $line_net < 0 ? -1 : 1; $price = abs($line_net); }
            $li = $el($tx, 'ram:IncludedSupplyChainTradeLineItem');
            $el($el($li, 'ram:AssociatedDocumentLineDocument'), 'ram:LineID', (string) ($i + 1));
            $prod = $el($li, 'ram:SpecifiedTradeProduct');
            $el($prod, 'ram:Name', mb_substr(trim((string) $l['name']) ?: 'Position', 0, 250));
            if (!empty($l['desc'])) $el($prod, 'ram:Description', mb_substr((string) $l['desc'], 0, 500));
            $el($el($el($li, 'ram:SpecifiedLineTradeAgreement'), 'ram:NetPriceProductTradePrice'), 'ram:ChargeAmount', rtrim(rtrim(number_format($price, 4, '.', ''), '0'), '.') ?: '0');
            $el($el($li, 'ram:SpecifiedLineTradeDelivery'), 'ram:BilledQuantity', (string) $qty, ['unitCode' => 'C62']);
            $set = $el($li, 'ram:SpecifiedLineTradeSettlement');
            $tt = $el($set, 'ram:ApplicableTradeTax');
            $r = floatval($l['rate'] ?? 0);
            $el($tt, 'ram:TypeCode', 'VAT');
            $el($tt, 'ram:CategoryCode', $cat($r));
            $el($tt, 'ram:RateApplicablePercent', self::rate($sb ? 0 : $r));
            $el($el($set, 'ram:SpecifiedTradeSettlementLineMonetarySummation'), 'ram:LineTotalAmount', self::amt($line_net));
        }

        $ag = $el($tx, 'ram:ApplicableHeaderTradeAgreement');
        $s = (array) $doc['seller'];
        $seller = $el($ag, 'ram:SellerTradeParty');
        $el($seller, 'ram:Name', (string) $s['name']);
        if (!empty($s['email'])) {
            $ct = $el($seller, 'ram:DefinedTradeContact');
            $el($ct, 'ram:PersonName', (string) ($s['contact'] ?: $s['name']));
            $el($el($ct, 'ram:EmailURIUniversalCommunication'), 'ram:URIID', (string) $s['email']);
        }
        $sa = $el($seller, 'ram:PostalTradeAddress');
        if (($s['zip'] ?? '') !== '') $el($sa, 'ram:PostcodeCode', $s['zip']);
        if (($s['street'] ?? '') !== '') $el($sa, 'ram:LineOne', $s['street']);
        if (($s['city'] ?? '') !== '') $el($sa, 'ram:CityName', $s['city']);
        $el($sa, 'ram:CountryID', ($s['country'] ?? '') ?: 'DE');
        if (!empty($s['email'])) $el($el($seller, 'ram:URIUniversalCommunication'), 'ram:URIID', (string) $s['email'], ['schemeID' => 'EM']);
        if (!empty($s['tax_id'])) $el($el($seller, 'ram:SpecifiedTaxRegistration'), 'ram:ID', (string) $s['tax_id'], ['schemeID' => 'FC']);
        if (!empty($s['vat_id'])) $el($el($seller, 'ram:SpecifiedTaxRegistration'), 'ram:ID', strtoupper(str_replace(' ', '', (string) $s['vat_id'])), ['schemeID' => 'VA']);
        $b = (array) $doc['buyer'];
        $buyer = $el($ag, 'ram:BuyerTradeParty');
        $el($buyer, 'ram:Name', (string) ($b['name'] ?: 'Kunde'));
        $ba = $el($buyer, 'ram:PostalTradeAddress');
        if (($b['zip'] ?? '') !== '') $el($ba, 'ram:PostcodeCode', $b['zip']);
        if (($b['street'] ?? '') !== '') $el($ba, 'ram:LineOne', $b['street']);
        if (($b['city'] ?? '') !== '') $el($ba, 'ram:CityName', $b['city']);
        $el($ba, 'ram:CountryID', ($b['country'] ?? '') ?: 'DE');
        if (!empty($b['email'])) $el($el($buyer, 'ram:URIUniversalCommunication'), 'ram:URIID', (string) $b['email'], ['schemeID' => 'EM']);
        if (!empty($b['vat_id'])) $el($el($buyer, 'ram:SpecifiedTaxRegistration'), 'ram:ID', strtoupper(str_replace(' ', '', (string) $b['vat_id'])), ['schemeID' => 'VA']);

        $dl = $el($tx, 'ram:ApplicableHeaderTradeDelivery');
        $el($el($el($dl, 'ram:ActualDeliverySupplyChainEvent'), 'ram:OccurrenceDateTime'), 'udt:DateTimeString', self::date102($doc['service_date'] ?: $doc['issue_date']), ['format' => '102']);

        $st = $el($tx, 'ram:ApplicableHeaderTradeSettlement');
        $el($st, 'ram:InvoiceCurrencyCode', 'EUR');
        $tax_total = 0.0; $basis_total = 0.0;
        foreach ((array) $doc['taxes'] as $t) {
            $r = floatval($t['rate'] ?? 0);
            $calc = $sb ? 0.0 : round(floatval($t['tax']), 2); $basis = round(floatval($t['net']), 2);
            $tax_total += $calc; $basis_total += $basis;
            $at = $el($st, 'ram:ApplicableTradeTax');
            $el($at, 'ram:CalculatedAmount', self::amt($calc));
            $el($at, 'ram:TypeCode', 'VAT');
            if ($sb) $el($at, 'ram:ExemptionReason', 'Kleinunternehmer gemäß § 19 UStG');
            $el($at, 'ram:BasisAmount', self::amt($basis));
            $el($at, 'ram:CategoryCode', $cat($r));
            $el($at, 'ram:RateApplicablePercent', self::rate($sb ? 0 : $r));
        }
        $terms = $el($st, 'ram:SpecifiedTradePaymentTerms');
        $el($terms, 'ram:Description', (string) $doc['terms']);
        if (!empty($doc['due_date'])) $el($el($terms, 'ram:DueDateDateTime'), 'udt:DateTimeString', self::date102($doc['due_date']), ['format' => '102']);
        $grand = round($basis_total + $tax_total, 2);
        $sum = $el($st, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation');
        $el($sum, 'ram:LineTotalAmount', self::amt($line_total));
        $el($sum, 'ram:TaxBasisTotalAmount', self::amt($basis_total));
        $el($sum, 'ram:TaxTotalAmount', self::amt($tax_total), ['currencyID' => 'EUR']);
        $el($sum, 'ram:GrandTotalAmount', self::amt($grand));
        $el($sum, 'ram:TotalPrepaidAmount', self::amt(!empty($doc['prepaid']) ? $grand : 0));
        $el($sum, 'ram:DuePayableAmount', self::amt(!empty($doc['prepaid']) ? 0 : $grand));
        if (!empty($doc['ref']['number'])) {
            $rd = $el($st, 'ram:InvoiceReferencedDocument');
            $el($rd, 'ram:IssuerAssignedID', (string) $doc['ref']['number']);
            if (!empty($doc['ref']['date'])) $el($el($rd, 'ram:FormattedIssueDateTime'), 'qdt:DateTimeString', self::date102($doc['ref']['date']), ['format' => '102']);
        }
        return $dom->saveXML();
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
