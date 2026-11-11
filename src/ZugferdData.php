<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use DOMDocument;
use DOMXPath;
use RuntimeException;

final class ZugferdData
{
    public static function fromUbl(string $source): array
    {
        $document = new DOMDocument();
        if (!$document->loadXML($source, LIBXML_NONET) || $document->doctype !== null) {
            throw new RuntimeException('Invalid stored invoice XML');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('i', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $xpath->registerNamespace('c', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('a', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $get = static fn (string $path): string => $xpath->evaluate('string(/i:Invoice/' . $path . ')');
        $party = static function (string $role) use ($get): array {
            $path = 'a:Accounting' . $role . 'Party/a:Party/';
            return [
                'name' => $get($path . 'a:PartyLegalEntity/c:RegistrationName'),
                'street' => $get($path . 'a:PostalAddress/c:StreetName'),
                'extra' => $get($path . 'a:PostalAddress/c:AdditionalStreetName'),
                'postal' => $get($path . 'a:PostalAddress/c:PostalZone'),
                'city' => $get($path . 'a:PostalAddress/c:CityName'),
                'country' => $get($path . 'a:PostalAddress/a:Country/c:IdentificationCode'),
                'email' => $get($path . 'c:EndpointID'),
                'contact' => $get($path . 'a:Contact/c:Name'),
                'phone' => $get($path . 'a:Contact/c:Telephone'),
                'vatId' => $get($path . 'a:PartyTaxScheme/c:CompanyID'),
            ];
        };
        $totals = 'a:LegalMonetaryTotal/';
        return [
            'number' => $get('c:ID'),
            'date' => $get('c:IssueDate'),
            'taxDate' => $get('c:TaxPointDate'),
            'note' => $get('c:Note'),
            'name' => $get('a:InvoiceLine/a:Item/c:Name'),
            'description' => $get('a:InvoiceLine/a:Item/c:Description'),
            'rate' => $get('a:TaxTotal/a:TaxSubtotal/a:TaxCategory/c:Percent'),
            'vat' => $get('a:TaxTotal/c:TaxAmount'),
            'net' => $get($totals . 'c:TaxExclusiveAmount'),
            'gross' => $get($totals . 'c:TaxInclusiveAmount'),
            'rounding' => $get($totals . 'c:PayableRoundingAmount'),
            'paid' => $get($totals . 'c:PrepaidAmount'),
            'due' => $get($totals . 'c:PayableAmount'),
            'reference' => $get('a:PaymentMeans/c:PaymentID'),
            'seller' => $party('Supplier'),
            'buyer' => $party('Customer'),
        ];
    }

    public static function cii(array $invoice): string
    {
        $seller = self::party('Seller', $invoice['seller']);
        $buyer = self::party('Buyer', $invoice['buyer']);
        unset($invoice['seller'], $invoice['buyer']);
        $value = array_map(self::escape(...), $invoice);
        $date = str_replace('-', '', $value['date']);
        $taxDate = str_replace('-', '', $value['taxDate']);
        $rounding = $value['rounding'] === '' ? '' : '<ram:RoundingAmount>' . $value['rounding'] . '</ram:RoundingAmount>';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
  <rsm:ExchangedDocumentContext>
    <ram:GuidelineSpecifiedDocumentContextParameter><ram:ID>urn:cen.eu:en16931:2017</ram:ID></ram:GuidelineSpecifiedDocumentContextParameter>
  </rsm:ExchangedDocumentContext>
  <rsm:ExchangedDocument>
    <ram:ID>{$value['number']}</ram:ID>
    <ram:TypeCode>380</ram:TypeCode>
    <ram:IssueDateTime><udt:DateTimeString format="102">{$date}</udt:DateTimeString></ram:IssueDateTime>
    <ram:IncludedNote><ram:Content>{$value['note']}</ram:Content></ram:IncludedNote>
  </rsm:ExchangedDocument>
  <rsm:SupplyChainTradeTransaction>
    <ram:IncludedSupplyChainTradeLineItem>
      <ram:AssociatedDocumentLineDocument><ram:LineID>1</ram:LineID></ram:AssociatedDocumentLineDocument>
      <ram:SpecifiedTradeProduct><ram:Name>{$value['name']}</ram:Name><ram:Description>{$value['description']}</ram:Description></ram:SpecifiedTradeProduct>
      <ram:SpecifiedLineTradeAgreement><ram:NetPriceProductTradePrice><ram:ChargeAmount>{$value['net']}</ram:ChargeAmount></ram:NetPriceProductTradePrice></ram:SpecifiedLineTradeAgreement>
      <ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="C62">1</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>
      <ram:SpecifiedLineTradeSettlement>
        <ram:ApplicableTradeTax><ram:TypeCode>VAT</ram:TypeCode><ram:CategoryCode>S</ram:CategoryCode><ram:RateApplicablePercent>{$value['rate']}</ram:RateApplicablePercent></ram:ApplicableTradeTax>
        <ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>{$value['net']}</ram:LineTotalAmount></ram:SpecifiedTradeSettlementLineMonetarySummation>
      </ram:SpecifiedLineTradeSettlement>
    </ram:IncludedSupplyChainTradeLineItem>
    <ram:ApplicableHeaderTradeAgreement>
      {$seller}
      {$buyer}
    </ram:ApplicableHeaderTradeAgreement>
    <ram:ApplicableHeaderTradeDelivery/>
    <ram:ApplicableHeaderTradeSettlement>
      <ram:PaymentReference>{$value['reference']}</ram:PaymentReference>
      <ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>
      <ram:SpecifiedTradeSettlementPaymentMeans><ram:TypeCode>68</ram:TypeCode><ram:Information>PayPal</ram:Information></ram:SpecifiedTradeSettlementPaymentMeans>
      <ram:ApplicableTradeTax>
        <ram:CalculatedAmount>{$value['vat']}</ram:CalculatedAmount>
        <ram:TypeCode>VAT</ram:TypeCode>
        <ram:BasisAmount>{$value['net']}</ram:BasisAmount>
        <ram:CategoryCode>S</ram:CategoryCode>
        <ram:TaxPointDate><udt:DateString format="102">{$taxDate}</udt:DateString></ram:TaxPointDate>
        <ram:RateApplicablePercent>{$value['rate']}</ram:RateApplicablePercent>
      </ram:ApplicableTradeTax>
      <ram:SpecifiedTradeSettlementHeaderMonetarySummation>
        <ram:LineTotalAmount>{$value['net']}</ram:LineTotalAmount>
        <ram:TaxBasisTotalAmount>{$value['net']}</ram:TaxBasisTotalAmount>
        <ram:TaxTotalAmount currencyID="EUR">{$value['vat']}</ram:TaxTotalAmount>
        {$rounding}
        <ram:GrandTotalAmount>{$value['gross']}</ram:GrandTotalAmount>
        <ram:TotalPrepaidAmount>{$value['paid']}</ram:TotalPrepaidAmount>
        <ram:DuePayableAmount>{$value['due']}</ram:DuePayableAmount>
      </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
    </ram:ApplicableHeaderTradeSettlement>
  </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>
XML;
    }

    private static function party(string $role, array $party): string
    {
        $p = array_map(self::escape(...), $party);
        $extra = $p['extra'] === '' ? '' : '<ram:LineTwo>' . $p['extra'] . '</ram:LineTwo>';
        $tax = $p['vatId'] === '' ? '' : '<ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">' . $p['vatId'] . '</ram:ID></ram:SpecifiedTaxRegistration>';
        $contact = $role === 'Buyer' ? '' : <<<XML
<ram:DefinedTradeContact>
  <ram:PersonName>{$p['contact']}</ram:PersonName>
  <ram:TelephoneUniversalCommunication><ram:CompleteNumber>{$p['phone']}</ram:CompleteNumber></ram:TelephoneUniversalCommunication>
  <ram:EmailURIUniversalCommunication><ram:URIID>{$p['email']}</ram:URIID></ram:EmailURIUniversalCommunication>
</ram:DefinedTradeContact>
XML;
        return <<<XML
<ram:{$role}TradeParty>
  <ram:Name>{$p['name']}</ram:Name>
  {$contact}
  <ram:PostalTradeAddress>
    <ram:PostcodeCode>{$p['postal']}</ram:PostcodeCode>
    <ram:LineOne>{$p['street']}</ram:LineOne>
    {$extra}
    <ram:CityName>{$p['city']}</ram:CityName>
    <ram:CountryID>{$p['country']}</ram:CountryID>
  </ram:PostalTradeAddress>
  <ram:URIUniversalCommunication><ram:URIID schemeID="EM">{$p['email']}</ram:URIID></ram:URIUniversalCommunication>
  {$tax}
</ram:{$role}TradeParty>
XML;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
