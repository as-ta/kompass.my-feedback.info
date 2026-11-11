<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use DOMDocument;
use DOMElement;

final class XRechnung
{
    private const AGGREGATE = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const BASIC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private readonly DOMDocument $document;

    public function __construct()
    {
        $this->document = new DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = true;
    }

    public function prepayment(string $number, string $date, array $seller, array $buyer, array $payment): string
    {
        $invoice = $this->document->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'Invoice');
        $invoice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::AGGREGATE);
        $invoice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::BASIC);
        $this->document->appendChild($invoice);
        $this->text($invoice, 'CustomizationID', 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0');
        $this->text($invoice, 'ProfileID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
        $this->text($invoice, 'ID', $number);
        $this->text($invoice, 'IssueDate', $date);
        $this->text($invoice, 'InvoiceTypeCode', '380');
        $this->text($invoice, 'Note', 'TESTDOKUMENT. Vorauszahlung mit PayPal-Spielgeld. Leistungszeitraum: vier Wochen ab Freischaltung durch den Kunden.');
        $this->text($invoice, 'TaxPointDate', $date);
        $this->text($invoice, 'DocumentCurrencyCode', 'EUR');
        $this->text($invoice, 'BuyerReference', '-');

        $this->party($invoice, 'AccountingSupplierParty', [
            'name' => $seller['legal_name'],
            'email' => $seller['contact_email'],
            'street' => $seller['street_address'],
            'postal_code' => $seller['postal_code'],
            'city' => $seller['city'],
            'vat_id' => $seller['vat_id'],
            'contact' => $seller['contact_name'],
            'phone' => $seller['contact_phone'],
        ]);
        $this->party($invoice, 'AccountingCustomerParty', [
            'name' => $buyer['company'] ?: $buyer['first_name'] . ' ' . $buyer['last_name'],
            'email' => $buyer['email'],
            'street' => $buyer['address_line1'],
            'extra' => $buyer['address_line2'],
            'postal_code' => $buyer['postal_code'],
            'city' => $buyer['city'],
        ]);

        $means = $this->group($invoice, 'PaymentMeans');
        $this->text($means, 'PaymentMeansCode', '68');
        $this->text($means, 'PaymentID', $payment['capture_id']);

        $rate = (int) $seller['vat_rate_basis_points'];
        $paid = (int) str_replace('.', '', $payment['amount']);
        $net = intdiv($paid * 10000 + intdiv(10000 + $rate, 2), 10000 + $rate);
        $vat = intdiv($net * $rate + 5000, 10000);
        $rounding = $paid - ($net + $vat);

        $tax = $this->group($invoice, 'TaxTotal');
        $this->money($tax, 'TaxAmount', $vat);
        $subtotal = $this->group($tax, 'TaxSubtotal');
        $this->money($subtotal, 'TaxableAmount', $net);
        $this->money($subtotal, 'TaxAmount', $vat);
        $this->taxCategory($subtotal, 'TaxCategory', $rate);

        $totals = $this->group($invoice, 'LegalMonetaryTotal');
        $this->money($totals, 'LineExtensionAmount', $net);
        $this->money($totals, 'TaxExclusiveAmount', $net);
        $this->money($totals, 'TaxInclusiveAmount', $net + $vat);
        $this->money($totals, 'PrepaidAmount', $paid);
        if ($rounding !== 0) {
            $this->money($totals, 'PayableRoundingAmount', $rounding);
        }
        $this->money($totals, 'PayableAmount', 0);

        $line = $this->group($invoice, 'InvoiceLine');
        $this->text($line, 'ID', '1');
        $this->text($line, 'InvoicedQuantity', '1')->setAttribute('unitCode', 'C62');
        $this->money($line, 'LineExtensionAmount', $net);
        $item = $this->group($line, 'Item');
        $this->text($item, 'Description', 'Vorauszahlung für vier Wochen Teilnahme ab Freischaltung, Befragung Nr. ' . $payment['poll_id']
            . ', Buchung ' . $payment['period_number']);
        $this->text($item, 'Name', 'myFeedback Kompass – Teilnahmezeitraum');
        $this->taxCategory($item, 'ClassifiedTaxCategory', $rate);
        $this->money($this->group($line, 'Price'), 'PriceAmount', $net);

        return $this->document->saveXML();
    }

    private function party(DOMElement $invoice, string $role, array $details): void
    {
        $party = $this->group($this->group($invoice, $role), 'Party');
        $this->text($party, 'EndpointID', $details['email'])->setAttribute('schemeID', 'EM');
        $address = $this->group($party, 'PostalAddress');
        $this->text($address, 'StreetName', $details['street']);
        if (!empty($details['extra'])) {
            $this->text($address, 'AdditionalStreetName', $details['extra']);
        }
        $this->text($address, 'CityName', $details['city']);
        $this->text($address, 'PostalZone', $details['postal_code']);
        $this->text($this->group($address, 'Country'), 'IdentificationCode', 'DE');

        if (isset($details['vat_id'])) {
            $tax = $this->group($party, 'PartyTaxScheme');
            $this->text($tax, 'CompanyID', $details['vat_id']);
            $this->text($this->group($tax, 'TaxScheme'), 'ID', 'VAT');
        }
        $this->text($this->group($party, 'PartyLegalEntity'), 'RegistrationName', $details['name']);
        if (isset($details['contact'])) {
            $contact = $this->group($party, 'Contact');
            $this->text($contact, 'Name', $details['contact']);
            $this->text($contact, 'Telephone', $details['phone']);
            $this->text($contact, 'ElectronicMail', $details['email']);
        }
    }

    private function taxCategory(DOMElement $parent, string $name, int $rate): void
    {
        $category = $this->group($parent, $name);
        $this->text($category, 'ID', 'S');
        $this->text($category, 'Percent', $this->decimal($rate));
        $this->text($this->group($category, 'TaxScheme'), 'ID', 'VAT');
    }

    private function money(DOMElement $parent, string $name, int $cents): void
    {
        $this->text($parent, $name, $this->decimal($cents))->setAttribute('currencyID', 'EUR');
    }

    private function decimal(int $value): string
    {
        $absolute = abs($value);
        return ($value < 0 ? '-' : '') . sprintf('%d.%02d', intdiv($absolute, 100), $absolute % 100);
    }

    private function group(DOMElement $parent, string $name): DOMElement
    {
        return $parent->appendChild($this->document->createElementNS(self::AGGREGATE, 'cac:' . $name));
    }

    private function text(DOMElement $parent, string $name, string $value): DOMElement
    {
        $element = $this->document->createElementNS(self::BASIC, 'cbc:' . $name);
        $element->appendChild($this->document->createTextNode($value));
        return $parent->appendChild($element);
    }
}
