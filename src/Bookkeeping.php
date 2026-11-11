<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class Bookkeeping
{
    public function __construct(private readonly PDO $db, private readonly array $emails)
    {
    }

    public function allowed(?int $user): bool
    {
        if ($user === null || $this->emails === []) {
            return false;
        }
        $read = $this->db->prepare('SELECT email FROM account_user WHERE id = ?');
        $read->execute([$user]);
        $email = $read->fetchColumn();
        if (!is_string($email)) {
            return false;
        }
        foreach ($this->emails as $approved) {
            if (is_string($approved) && strcasecmp(trim($email), trim($approved)) === 0) {
                return true;
            }
        }
        return false;
    }

    public function entries(int $user): array
    {
        $this->requireAdmin($user);
        $problems = (new SandboxInvoices($this->db))->recoverFor();
        $rows = $this->db->query(
            'SELECT s.id AS period_id, s.poll_id, s.period_number, s.amount, s.paid_at, s.capture_id,
                 i.invoice_number, i.issued_at, i.xml
             FROM poll_period s LEFT JOIN sandbox_invoice i ON i.poll_period_id = s.id
             WHERE s.paid_at IS NOT NULL ORDER BY s.paid_at DESC, s.id DESC'
        )->fetchAll();
        foreach ($rows as &$row) {
            $invoice = $row['xml'] === null ? null : ZugferdData::fromUbl($row['xml']);
            $row['invoice_problem'] = $problems[(int) $row['period_id']] ?? '';
            $row['customer'] = $invoice['buyer']['name'] ?? '';
            $row['net'] = $invoice['net'] ?? '';
            $row['vat'] = $invoice['vat'] ?? '';
            $row['vat_rate'] = $invoice['rate'] ?? '';
            $row['gross'] = $invoice['gross'] ?? $row['amount'];
            $row['invoice_date'] = $invoice['date'] ?? '';
            $row['booking_date'] = (new DateTimeImmutable($row['paid_at'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i:s');
            unset($row['xml']);
        }
        unset($row);
        return $rows;
    }

    public function find(int $user, int $period): ?array
    {
        $this->requireAdmin($user);
        $read = $this->db->prepare('SELECT invoice_number, xml FROM sandbox_invoice WHERE poll_period_id = ?');
        $read->execute([$period]);
        return $read->fetch() ?: null;
    }

    public function csv(int $user): string
    {
        $entries = $this->entries($user);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [
            'Rechnungsnummer', 'Rechnungsdatum', 'Buchungsdatum (Zahlung, Europe/Berlin)', 'Kunde',
            'Netto', 'MwSt-Satz (%)', 'MwSt-Betrag', 'Brutto', 'Waehrung',
            'Befragung Nr.', 'Buchung Nr.', 'PayPal-Transaktionsnummer', 'Rechnungsstatus',
        ], ';', '"', '');
        foreach ($entries as $entry) {
            $cells = [
                $entry['invoice_number'] ?? '', $entry['invoice_date'], $entry['booking_date'], $entry['customer'],
                str_replace('.', ',', $entry['net']), str_replace('.', ',', $entry['vat_rate']),
                str_replace('.', ',', $entry['vat']), str_replace('.', ',', $entry['gross']), 'EUR',
                (string) $entry['poll_id'], (string) $entry['period_number'], $entry['capture_id'] ?? '',
                $entry['invoice_number'] === null ? 'Rechnung fehlt' : 'Vorhanden',
            ];
            // Prevent spreadsheet programs from interpreting customer data as formulas.
            $cells = array_map(static function (string $cell): string {
                return preg_match('/\A[\s\x00-\x1f]*[=+\-@]/u', $cell) ? "'" . $cell : $cell;
            }, $cells);
            fputcsv($stream, $cells, ';', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return $csv;
    }

    private function requireAdmin(int $user): void
    {
        if (!$this->allowed($user)) {
            throw new RuntimeException('Bookkeeping access denied');
        }
    }
}
