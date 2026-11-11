<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use PDO;
use RuntimeException;
use Throwable;

final class PollPayments
{
    public function __construct(
        private readonly PDO $db,
        private readonly PayPalSandbox $paypal,
        private readonly string $amount,
    ) {
    }

    public function advance(int $poll, int $expected, string $returnUrl, ?string $returnedOrder): array
    {
        try {
            $expected = $this->ensurePeriod($poll, $expected, $returnedOrder);
            if ($expected === null) {
                return ['status' => 'review'];
            }

            $this->db->beginTransaction();
            $read = $this->db->prepare('SELECT * FROM poll_period WHERE id = ? FOR UPDATE');
            $read->execute([$expected]);
            $payment = $read->fetch();
            if ($payment === false || ($returnedOrder !== null && $payment['order_id'] !== $returnedOrder)) {
                $this->db->commit();
                return ['status' => 'review'];
            }
            $result = $this->advanceLocked($poll, $payment, $returnUrl, $returnedOrder);
            $this->db->commit();
            if (($result['status'] ?? '') === 'paid') {
                // Verlängerung: an den laufenden Zeitraum anschließen
                (new PollLifecycle($this->db))->chain($expected);
                try {
                    (new SandboxInvoices($this->db))->issue($expected);
                } catch (Throwable $error) {
                    error_log('Kompass invoice generation failed for period ' . $expected . ': ' . $error::class);
                }
            }
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Bestellung für die PayPal-Buttons (Popup) anlegen oder wiederverwenden.
     * Liefert ['orderId' => …, 'period' => …] oder einen Status wie bei advance().
     */
    public function buttonOrder(int $poll, int $expected): array
    {
        try {
            $period = $this->ensurePeriod($poll, $expected, null);
            if ($period === null) {
                return ['status' => 'review'];
            }

            $this->db->beginTransaction();
            $read = $this->db->prepare('SELECT * FROM poll_period WHERE id = ? FOR UPDATE');
            $read->execute([$period]);
            $payment = $read->fetch();
            if ($payment === false) {
                $this->db->commit();
                return ['status' => 'review'];
            }
            if ($payment['paid_at'] !== null) {
                $this->db->commit();
                return ['status' => 'paid'];
            }
            if ($payment['order_id'] !== null) {
                $order = $this->paypal->order($payment['order_id']);
                $this->verifyOrder($order, $payment['order_id'], $poll, $payment['amount']);
                $captured = !empty($order['purchase_units'][0]['payments']['captures']);
                if ($captured || in_array($order['status'] ?? '', ['APPROVED', 'COMPLETED'], true)) {
                    // Bereits freigegeben: wie nach der Rückkehr von PayPal abschließen.
                    $this->db->commit();
                    return $this->advance($poll, $period, '', $payment['order_id']);
                }
                // Offene, nie freigegebene Bestellung durch eine neue für die Buttons ersetzen.
                // Geld fließt erst beim Abbuchen, die alte Bestellung verfällt bei PayPal.
                $payment['request_key'] = bin2hex(random_bytes(16));
                $reset = $this->db->prepare(
                    'UPDATE poll_period SET order_id = NULL, approval_url = NULL, request_key = ? WHERE id = ?'
                );
                $reset->execute([$payment['request_key'], $payment['id']]);
            }

            $order = $this->paypal->createForButtons($payment['request_key'], $poll, $payment['amount'], $this->title($poll));
            $id = $order['id'] ?? '';
            if (!$this->validId($id)) {
                throw new RuntimeException('Missing PayPal order ID');
            }
            $update = $this->db->prepare('UPDATE poll_period SET order_id = ?, approval_url = ? WHERE id = ?');
            $update->execute([$id, $this->approvalUrl($order), $payment['id']]);
            $this->db->commit();
            return ['orderId' => $id, 'period' => (int) $payment['id']];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Sperrt die Befragung, prüft den erwarteten Zeitraum und legt bei Bedarf einen neuen an.
     * Gibt die ID des Zeitraums zurück oder null, wenn die Anfrage nicht (mehr) passt.
     */
    private function ensurePeriod(int $poll, int $expected, ?string $returnedOrder): ?int
    {
        $this->db->beginTransaction();
        $lock = $this->db->prepare('SELECT id FROM poll WHERE id = ? FOR UPDATE');
        $lock->execute([$poll]);
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('Unknown poll');
        }
        $payment = (new PollLifecycle($this->db))->latest($poll);
        if ((int) ($payment['id'] ?? 0) !== $expected
            || ($returnedOrder !== null && ($payment['order_id'] ?? null) !== $returnedOrder)) {
            $this->db->commit();
            return null;
        }
        if ($returnedOrder === null && ($payment === null
            || (isset($payment['paid_at']) && $payment['starts_at'] !== null))) {
            $write = $this->db->prepare(
                'INSERT INTO poll_period (poll_id, period_number, request_key, amount) VALUES (?, ?, ?, ?)'
            );
            $write->execute([$poll, (int) ($payment['period_number'] ?? 0) + 1, bin2hex(random_bytes(16)), $this->amount]);
            $expected = (int) $this->db->lastInsertId();
        }
        // Keep the request key and price even if PayPal times out.
        $this->db->commit();
        return $expected;
    }

    private function advanceLocked(int $poll, array $payment, string $returnUrl, ?string $returnedOrder): array
    {
        if ($payment['paid_at'] !== null) {
            return ['status' => 'paid'];
        }
        if ($payment['order_id'] === null) {
            $order = $this->paypal->create($payment['request_key'], $poll, $payment['amount'], $returnUrl, $this->title($poll));
            $id = $order['id'] ?? '';
            if (!$this->validId($id)) {
                throw new RuntimeException('Missing PayPal order ID');
            }
            $approval = $this->approvalUrl($order);
            $update = $this->db->prepare('UPDATE poll_period SET order_id = ?, approval_url = ? WHERE id = ?');
            $update->execute([$id, $approval, $payment['id']]);
            return $approval === null ? ['status' => 'review'] : ['redirect' => $approval];
        }

        $id = $payment['order_id'];
        $order = $this->paypal->order($id);
        $this->verifyOrder($order, $id, $poll, $payment['amount']);
        if (($order['status'] ?? '') === 'VOIDED') {
            $update = $this->db->prepare(
                'UPDATE poll_period SET order_id = NULL, approval_url = NULL, request_key = ? WHERE id = ?'
            );
            $update->execute([bin2hex(random_bytes(16)), $payment['id']]);
            return ['status' => 'expired'];
        }
        if (($order['status'] ?? '') === 'APPROVED' && empty($order['purchase_units'][0]['payments']['captures'])) {
            $this->paypal->capture($id, $payment['request_key']);
            $order = $this->paypal->order($id);
            $this->verifyOrder($order, $id, $poll, $payment['amount']);
        }
        $captures = $order['purchase_units'][0]['payments']['captures'] ?? [];
        if ($captures !== []) {
            if (count($captures) !== 1 || !$this->validId($captures[0]['id'] ?? null)
                || !$this->matchesAmount($captures[0]['amount'] ?? [], $payment['amount'])) {
                throw new RuntimeException('PayPal capture does not match the order');
            }
            $capture = $captures[0];
            if (($capture['status'] ?? '') === 'PENDING') {
                $details = $this->paypal->captureDetails($capture['id']);
                if (($details['id'] ?? '') !== $capture['id']
                    || !$this->matchesAmount($details['amount'] ?? [], $payment['amount'])) {
                    throw new RuntimeException('PayPal capture details do not match the order');
                }
                $details['status_details'] ??= [];
                $capture = $details + $capture;
            }
            if (($capture['status'] ?? '') !== 'COMPLETED') {
                $captureStatus = $capture['status'] ?? '';
                $pending = $captureStatus === 'PENDING';
                $update = $this->db->prepare('UPDATE poll_period SET payment_pending = ? WHERE id = ?');
                $update->execute([(int) $pending, $payment['id']]);
                $reason = $capture['status_details']['reason'] ?? '';
                $status = match (true) {
                    !$pending => 'review',
                    in_array($reason, ['RECEIVING_PREFERENCE_MANDATES_MANUAL_ACTION', 'INTERNATIONAL_WITHDRAWAL', 'VERIFICATION_REQUIRED', 'UNILATERAL'], true) => 'pending_merchant',
                    default => 'pending',
                };
                return ['status' => $status, 'captureStatus' => $captureStatus, 'captureReason' => $reason];
            }
            $update = $this->db->prepare(
                'UPDATE poll_period SET capture_id = ?, paid_at = UTC_TIMESTAMP(), payment_pending = FALSE WHERE id = ?'
            );
            $update->execute([$capture['id'], $payment['id']]);
            return ['status' => 'paid'];
        }

        $approval = $this->approvalUrl($order) ?? $payment['approval_url'];
        return $returnedOrder === null && $approval !== null && in_array($order['status'] ?? '', ['CREATED', 'PAYER_ACTION_REQUIRED'], true)
            ? ['redirect' => $approval]
            : ['status' => 'review'];
    }

    private function title(int $poll): string
    {
        $read = $this->db->prepare('SELECT title FROM poll_version WHERE poll_id = ? ORDER BY version_number DESC LIMIT 1');
        $read->execute([$poll]);
        return (string) ($read->fetchColumn() ?: '');
    }

    private function verifyOrder(array $order, string $id, int $poll, string $amount): void
    {
        $units = $order['purchase_units'] ?? [];
        if (($order['id'] ?? '') !== $id || ($order['intent'] ?? '') !== 'CAPTURE' || count($units) !== 1
            || ($units[0]['reference_id'] ?? '') !== 'poll-' . $poll
            || !$this->matchesAmount($units[0]['amount'] ?? [], $amount)) {
            throw new RuntimeException('PayPal order does not match the poll');
        }
    }

    private function matchesAmount(array $money, string $amount): bool
    {
        return ($money['currency_code'] ?? '') === 'EUR' && ($money['value'] ?? '') === $amount;
    }

    private function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[A-Z0-9]{1,64}\z/', $id);
    }

    private function approvalUrl(array $order): ?string
    {
        foreach ($order['links'] ?? [] as $link) {
            $url = $link['href'] ?? '';
            if (in_array($link['rel'] ?? '', ['approve', 'payer-action'], true)
                && preg_match('~\Ahttps://www\.sandbox\.paypal\.com/[^\s]{1,470}\z~', $url)) {
                return $url;
            }
        }
        return null;
    }
}
