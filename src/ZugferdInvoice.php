<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use DateTimeImmutable;
use TCPDF;

final class ZugferdInvoice
{
    public static function render(string $ubl): string
    {
        $invoice = ZugferdData::fromUbl($ubl);
        $pdf = new class('P', 'mm', 'A4', true, 'UTF-8', false, 3) extends TCPDF {
            protected function _out($s)
            {
                // TCPDF defaults to Source. The XML and the page are two representations of the invoice.
                if (is_string($s) && str_contains($s, '<</Type /Filespec /F ')) {
                    $s = str_replace('/AFRelationship /Source', '/AFRelationship /Alternative', $s);
                }
                parent::_out($s);
            }
        };
        $pdf->setCreator('myFeedback Kompass');
        $pdf->setAuthor($invoice['seller']['name']);
        $pdf->setTitle('Testrechnung ' . $invoice['number']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(25, 14, 25);
        $pdf->SetAutoPageBreak(false);
        $pdf->setFontSubsetting(true);
        $pdf->AddPage();
        $logo = file_get_contents(dirname(__DIR__) . '/public/logo-kompass.svg');
        $pdf->ImageSVG('@' . str_replace('<svg ', '<svg width="390" height="75" ', $logo), 123, 13, 72);
        $pdf->SetLineWidth(0.18);

        $seller = $invoice['seller'];
        $buyer = $invoice['buyer'];
        $pdf->SetFont('dejavuserif', 'U', 7);
        $pdf->SetXY(25, 49);
        $pdf->MultiCell(115, 3.8, $seller['name'] . ', ' . $seller['street'] . ', ' . $seller['postal'] . ' ' . $seller['city'], 0, 'L');
        $pdf->SetY($pdf->GetY() + 3);
        $pdf->SetFont('dejavuserif', '', 10);
        $recipient = array_filter([$buyer['name'], $buyer['street'], $buyer['extra'], $buyer['postal'] . ' ' . $buyer['city'], 'Deutschland']);
        $pdf->MultiCell(115, 5, implode("\n", $recipient), 0, 'L');

        $pdf->SetXY(25, max(96, $pdf->GetY() + 8));
        $pdf->SetFont('dejavuserif', 'B', 13);
        $pdf->Cell(0, 8, 'Rechnung', 0, 1);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->SetTextColor(77, 100, 69);
        $pdf->Cell(0, 6, 'TESTDOKUMENT · PAYPAL-SANDBOX', 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);

        $pdf->SetFont('dejavuserif', 'B', 8);
        $pdf->Cell(49, 5, 'Rechnungsnummer', 'T');
        $pdf->Cell(34, 5, 'Datum', 'T');
        $pdf->Cell(77, 5, 'Leistungszeitraum', 'T', 1);
        $pdf->SetFont('dejavuserif', '', 8);
        $pdf->Cell(49, 6, $invoice['number'], 'B');
        $pdf->Cell(34, 6, (new DateTimeImmutable($invoice['date']))->format('d.m.Y'), 'B');
        $pdf->Cell(77, 6, 'Vier Wochen ab Freischaltung', 'B', 1);
        $pdf->Ln(8);

        $pdf->SetFont('dejavuserif', 'B', 8);
        $pdf->Cell(8, 7, 'Pos.', 1, 0, 'C');
        $pdf->Cell(76, 7, 'Bezeichnung', 1);
        $pdf->Cell(27, 7, 'Einzelpreis', 1, 0, 'R');
        $pdf->Cell(15, 7, 'Menge', 1, 0, 'C');
        $pdf->Cell(34, 7, 'Netto-Betrag', 1, 1, 'R');

        $pdf->SetFont('dejavuserif', '', 8);
        $description = $invoice['name'] . "\n" . $invoice['description'];
        $height = max(15, $pdf->getStringHeight(76, $description) + 3);
        $pdf->Cell(8, $height, '1', 1, 0, 'C');
        $pdf->MultiCell(76, $height, $description, 1, 'L', false, 0);
        $pdf->Cell(27, $height, self::euro($invoice['net']), 1, 0, 'R');
        $pdf->Cell(15, $height, '1', 1, 0, 'C');
        $pdf->Cell(34, $height, self::euro($invoice['net']), 1, 1, 'R');

        $rate = rtrim(rtrim(str_replace('.', ',', $invoice['rate']), '0'), ',');
        $totals = [
            ['Zwischensumme (netto)', $invoice['net']],
            ['Umsatzsteuer ' . $rate . ' %', $invoice['vat']],
            ['Gesamtbetrag', $invoice['gross']],
        ];
        if ($invoice['rounding'] !== '') {
            $totals[] = ['Rundungsdifferenz', $invoice['rounding']];
        }
        $totals[] = ['Per PayPal-Sandbox bezahlt', $invoice['paid']];
        $totals[] = ['Offener Betrag', $invoice['due']];
        foreach ($totals as [$label, $amount]) {
            $pdf->SetFont('dejavuserif', in_array($label, ['Gesamtbetrag', 'Offener Betrag'], true) ? 'B' : '', 8);
            $pdf->Cell(126, 5.5, $label, 1);
            $pdf->Cell(34, 5.5, self::euro($amount), 1, 1, 'R');
        }
        $pdf->Ln(4);
        $pdf->SetFont('dejavuserif', '', 8);
        $pdf->MultiCell(160, 5, $invoice['note'], 0, 'L');
        $pdf->MultiCell(160, 5, 'PayPal-Referenz ' . $invoice['reference'], 0, 'L');

        $pdf->SetFont('dejavusans', '', 7);
        $address = implode("\n", [$seller['name'], $seller['street'], $seller['postal'] . ' ' . $seller['city'], 'Deutschland']);
        $contact = implode("\n", [$seller['contact'], $seller['phone'], $seller['email']]);
        $footerY = min(274, 292 - max($pdf->getStringHeight(65, $address), $pdf->getStringHeight(76, $contact)));
        $pdf->SetFillColor(232, 233, 234);
        $pdf->Polygon([0, $footerY - 3, 68, $footerY - 8, 137, $footerY - 3, 210, $footerY - 7, 210, 297, 0, 297], 'F');
        $pdf->SetXY(8, $footerY);
        $pdf->MultiCell(65, 0, $address, 0, 'L');
        $pdf->SetXY(76, $footerY);
        $pdf->MultiCell(76, 0, $contact, 0, 'L');
        $pdf->SetXY(154, $footerY);
        $pdf->MultiCell(48, 0, 'USt-IdNr. ' . $seller['vatId'], 0, 'L');
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->SetXY(152, 287);
        $pdf->Cell(50, 4, 'myFeedback Kompass', 0, 0, 'R');

        $pdf->EmbedFileFromString('factur-x.xml', ZugferdData::cii($invoice));
        $pdf->setExtraXMPRDF(<<<'XMP'
<rdf:Description rdf:about="" xmlns:fx="urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#">
  <fx:DocumentType>INVOICE</fx:DocumentType>
  <fx:DocumentFileName>factur-x.xml</fx:DocumentFileName>
  <fx:Version>1.0</fx:Version>
  <fx:ConformanceLevel>EN 16931</fx:ConformanceLevel>
</rdf:Description>
XMP);
        $properties = '';
        foreach (['DocumentType', 'DocumentFileName', 'Version', 'ConformanceLevel'] as $field) {
            $properties .= '<rdf:li rdf:parseType="Resource"><pdfaProperty:name>' . $field
                . '</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category>'
                . '<pdfaProperty:description>Factur-X ' . $field . '</pdfaProperty:description></rdf:li>';
        }
        $pdf->setExtraXMPPdfaextension(
            '<rdf:li rdf:parseType="Resource"><pdfaSchema:schema>Factur-X PDF/A Extension Schema</pdfaSchema:schema>'
            . '<pdfaSchema:namespaceURI>urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#</pdfaSchema:namespaceURI>'
            . '<pdfaSchema:prefix>fx</pdfaSchema:prefix><pdfaSchema:property><rdf:Seq>' . $properties
            . '</rdf:Seq></pdfaSchema:property></rdf:li>'
        );
        return $pdf->Output('', 'S');
    }

    private static function euro(string $amount): string
    {
        return number_format((float) $amount, 2, ',', '.') . ' €';
    }
}
