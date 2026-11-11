<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class SandboxInvoices
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function issue(int $period): ?string
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $problem = $this->issueLocked($period);
            if ($ownTransaction) {
                $this->db->commit();
            }
            return $problem;
        } catch (Throwable $error) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function recoverFor(?int $owner = null): array
    {
        $read = $this->db->prepare(
            'SELECT s.id FROM poll_period s JOIN poll p ON p.id = s.poll_id
             LEFT JOIN sandbox_invoice i ON i.poll_period_id = s.id
             WHERE s.paid_at IS NOT NULL AND i.poll_period_id IS NULL'
             . ($owner === null ? '' : ' AND p.owner_id = ?') . ' ORDER BY s.paid_at, s.id'
        );
        $read->execute($owner === null ? [] : [$owner]);
        $periods = $read->fetchAll(PDO::FETCH_COLUMN);
        $problems = [];
        foreach ($periods as $period) {
            try {
                $problem = $this->issue((int) $period);
            } catch (Throwable $error) {
                error_log('Kompass invoice generation failed for period ' . (int) $period . ': ' . $error::class);
                $problem = 'Die Rechnung konnte gerade nicht erstellt werden. Bitte versuchen Sie es erneut.';
            }
            if ($problem !== null) {
                $problems[(int) $period] = $problem;
            }
        }
        return $problems;
    }

    private function issueLocked(int $period): ?string
    {
        // Serialize both retries for one payment and allocation of invoice numbers.
        $lock = $this->db->prepare('SELECT paid_at, capture_id FROM poll_period WHERE id = ? FOR UPDATE');
        $lock->execute([$period]);
        $confirmed = $lock->fetch();
        if (!$confirmed || $confirmed['paid_at'] === null || empty($confirmed['capture_id'])) {
            return 'Die Zahlung wurde noch nicht vollständig bestätigt.';
        }
        $existing = $this->db->prepare('SELECT 1 FROM sandbox_invoice WHERE poll_period_id = ?');
        $existing->execute([$period]);
        if ($existing->fetchColumn() !== false) {
            return null;
        }

        $read = $this->db->prepare(
            'SELECT s.poll_id, s.period_number, s.amount, s.capture_id, s.paid_at, p.owner_id
             FROM poll_period s JOIN poll p ON p.id = s.poll_id WHERE s.id = ?'
        );
        $read->execute([$period]);
        $payment = $read->fetch();
        $buyer = (new AccountBilling($this->db))->find((int) $payment['owner_id']);
        if (!in_array(strtolower(trim($buyer['country'] ?? '')), ['de', 'deutschland', 'germany'], true)) {
            return 'Die Rechnungserstellung ist derzeit nur für Rechnungsadressen in Deutschland eingerichtet.';
        }
        $seller = $this->db->query('SELECT * FROM invoice_issuer WHERE id = 1 FOR UPDATE')->fetch();
        $problem = InvoiceIssuer::problem($seller ?: []);
        if ($problem !== null) {
            return $problem;
        }

        $number = 'XR-TEST-' . str_pad((string) $seller['next_test_number'], 6, '0', STR_PAD_LEFT);
        $issued = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $date = $issued->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
        $xml = (new XRechnung())->prepayment($number, $date, $seller, $buyer, $payment);
        $write = $this->db->prepare(
            'INSERT INTO sandbox_invoice (poll_period_id, invoice_number, issued_at, xml) VALUES (?, ?, ?, ?)'
        );
        $write->execute([$period, $number, $issued->format('Y-m-d H:i:s'), $xml]);
        $this->db->exec('UPDATE invoice_issuer SET next_test_number = next_test_number + 1 WHERE id = 1');
        return null;
    }

    public function listFor(int $owner): array
    {
        $this->recoverFor($owner);
        $read = $this->db->prepare(
            'SELECT s.id AS period_id, s.poll_id, s.period_number, s.amount, s.paid_at,
                 i.invoice_number, i.issued_at, v.title
             FROM poll_period s JOIN poll p ON p.id = s.poll_id
             JOIN poll_version v ON v.poll_id = p.id AND v.version_number = 1
             LEFT JOIN sandbox_invoice i ON i.poll_period_id = s.id
             WHERE p.owner_id = ? AND s.paid_at IS NOT NULL
             ORDER BY s.paid_at DESC, s.id DESC'
        );
        $read->execute([$owner]);
        return $read->fetchAll();
    }

    public function find(int $owner, int $period): ?array
    {
        $read = $this->db->prepare(
            'SELECT i.invoice_number, i.xml FROM sandbox_invoice i
             JOIN poll_period s ON s.id = i.poll_period_id JOIN poll p ON p.id = s.poll_id
             WHERE p.owner_id = ? AND s.id = ?'
        );
        $read->execute([$owner, $period]);
        return $read->fetch() ?: null;
    }

}
