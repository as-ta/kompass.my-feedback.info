<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

final class SandboxReceipt
{
    public static function number(int $id): string
    {
        return 'TEST-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    public static function render(array $payment): string
    {
        $date = (new \DateTimeImmutable($payment['paid_at'], new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/Berlin'));
        $lines = [
            'myFeedback Kompass',
            'Testbeleg ' . self::number((int) $payment['id']),
            'PayPal-Sandbox | Keine steuerliche Rechnung',
            'Befragung Nr. ' . $payment['poll_id'] . ' | Zeitraum ' . $payment['period_number'],
            'Leistung im Test: vier Wochen Teilnahme nach dem Start',
            'Testzahlung bestätigt am ' . $date->format('d.m.Y'),
            'Testbetrag: ' . str_replace('.', ',', $payment['amount']) . ' EUR Spielgeld',
            'PayPal-Referenz: ' . $payment['capture_id'],
        ];
        $stream = "BT /F1 13 Tf 56 780 Td 25 TL\n";
        foreach ($lines as $line) {
            $text = iconv('UTF-8', 'Windows-1252', $line);
            $stream .= '(' . strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ") Tj T*\n";
        }
        $stream .= "ET\n";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream',
        ];
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = "0000000000 65535 f \n";
        foreach ($objects as $index => $object) {
            $offsets .= sprintf("%010d 00000 n \n", strlen($pdf));
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        return $pdf . "xref\n0 6\n" . $offsets . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}
