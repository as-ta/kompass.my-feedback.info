<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Abo je Befragung über PayPal Subscriptions.
 * PayPal bucht monatlich ab; jede bestätigte Abbuchung wird ein eigener, nahtlos anschließender Zeitraum.
 * Maßgeblich ist immer der Stand bei PayPal (Abgleich per Webhook und beim Aufruf der Seiten).
 */
final class PollSubscriptions
{
    private const OPEN = ['APPROVAL_PENDING', 'APPROVED', 'ACTIVE', 'SUSPENDED'];

    public function __construct(
        private readonly PDO $db,
        private readonly PayPalSandbox $paypal,
        private readonly string $amount,
    ) {
    }

    public function amount(): string
    {
        return $this->amount;
    }

    /** Plan-ID für den aktuellen Monatspreis, wird beim ersten Bedarf bei PayPal angelegt. */
    public function planId(): string
    {
        $environment = $this->paypal->environment();
        $read = $this->db->prepare('SELECT plan_id FROM paypal_plan WHERE environment = ? AND amount = ?');
        $read->execute([$environment, $this->amount]);
        $plan = $read->fetchColumn();
        if (is_string($plan)) {
            return $plan;
        }
        [$product, $plan] = $this->paypal->createPlan($this->amount);
        $write = $this->db->prepare(
            'INSERT INTO paypal_plan (environment, amount, product_id, plan_id) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE plan_id = plan_id'
        );
        $write->execute([$environment, $this->amount, $product, $plan]);
        $read->execute([$environment, $this->amount]);
        return (string) $read->fetchColumn();
    }

    /** Laufendes oder noch nicht beendetes Abo einer Befragung */
    public function current(int $poll): ?array
    {
        $read = $this->db->prepare(
            'SELECT * FROM poll_subscription WHERE poll_id = ? AND status IN (\'APPROVAL_PENDING\', \'APPROVED\', \'ACTIVE\', \'SUSPENDED\')
             ORDER BY id DESC LIMIT 1'
        );
        $read->execute([$poll]);
        return $read->fetch() ?: null;
    }

    /** Nach der Freigabe im PayPal-Fenster: Abo prüfen und speichern. */
    public function register(int $poll, string $paypalId): bool
    {
        if (!preg_match('/\A[A-Z0-9-]{3,64}\z/', $paypalId)) {
            return false;
        }
        $remote = $this->paypal->subscription($paypalId);
        if (($remote['id'] ?? '') !== $paypalId || ($remote['plan_id'] ?? '') !== $this->planId()
            || (string) ($remote['custom_id'] ?? '') !== (string) $poll
            || !in_array($remote['status'] ?? '', self::OPEN, true)) {
            return false;
        }
        $write = $this->db->prepare(
            'INSERT INTO poll_subscription (poll_id, paypal_id, plan_id, amount, status, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE status = VALUES(status)'
        );
        $write->execute([$poll, $paypalId, $remote['plan_id'], $this->amount, $remote['status']]);
        $this->sync($paypalId, $remote);
        return true;
    }

    /** Gleicht ein Abo mit PayPal ab und legt für jede neue Abbuchung einen Zeitraum an. */
    public function sync(string $paypalId, ?array $remote = null): void
    {
        $read = $this->db->prepare('SELECT * FROM poll_subscription WHERE paypal_id = ?');
        $read->execute([$paypalId]);
        $subscription = $read->fetch();
        if ($subscription === false) {
            return;
        }
        $remote ??= $this->paypal->subscription($paypalId);
        $next = $remote['billing_info']['next_billing_time'] ?? null;
        $update = $this->db->prepare(
            'UPDATE poll_subscription SET status = ?, next_billing_at = ?, synced_at = UTC_TIMESTAMP(),
                 cancelled_at = IF(? IN (\'CANCELLED\', \'EXPIRED\') AND cancelled_at IS NULL, UTC_TIMESTAMP(), cancelled_at)
             WHERE id = ?'
        );
        $status = (string) ($remote['status'] ?? $subscription['status']);
        $update->execute([$status, self::utc($next), $status, $subscription['id']]);

        $from = (new DateTimeImmutable($subscription['created_at'], new DateTimeZone('UTC')))->modify('-1 day');
        $transactions = $this->paypal->subscriptionTransactions(
            $paypalId,
            $from->format('Y-m-d\TH:i:s\Z'),
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 hour')->format('Y-m-d\TH:i:s\Z'),
        );
        usort($transactions, static fn (array $a, array $b): int => strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? '')));
        foreach ($transactions as $transaction) {
            $this->book((int) $subscription['poll_id'], (int) $subscription['id'], $subscription['amount'], $transaction);
        }
    }

    /** Synchronisiert, wenn der letzte Abgleich länger zurückliegt (Absicherung, falls ein Webhook fehlt). */
    public function refresh(int $poll, int $minutes = 10): ?array
    {
        $subscription = $this->current($poll);
        if ($subscription === null) {
            return null;
        }
        $stale = $subscription['synced_at'] === null
            || (new DateTimeImmutable($subscription['synced_at'], new DateTimeZone('UTC')))->modify('+' . $minutes . ' minutes')
                < new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($stale) {
            try {
                $this->sync($subscription['paypal_id']);
            } catch (RuntimeException $error) {
                error_log('Kompass PayPal subscription sync: ' . $error->getMessage());
            }
            $subscription = $this->current($poll) ?? $this->latest($poll);
        }
        return $subscription;
    }

    public function latest(int $poll): ?array
    {
        $read = $this->db->prepare('SELECT * FROM poll_subscription WHERE poll_id = ? ORDER BY id DESC LIMIT 1');
        $read->execute([$poll]);
        return $read->fetch() ?: null;
    }

    /** Kündigung zum Ende des bezahlten Monats: bereits bezahlte Zeiträume laufen weiter. */
    public function cancel(int $poll): bool
    {
        $subscription = $this->current($poll);
        if ($subscription === null) {
            return false;
        }
        $this->paypal->cancelSubscription($subscription['paypal_id'], 'Vom Kunden im Kompass-Konto gekündigt');
        $this->sync($subscription['paypal_id']);
        return true;
    }

    private function book(int $poll, int $subscription, string $amount, array $transaction): void
    {
        $id = $transaction['id'] ?? null;
        $gross = $transaction['amount_with_breakdown']['gross_amount'] ?? [];
        if (($transaction['status'] ?? '') !== 'COMPLETED' || !is_string($id) || !preg_match('/\A[A-Z0-9-]{1,64}\z/', $id)
            || ($gross['currency_code'] ?? '') !== 'EUR' || ($gross['value'] ?? '') !== $amount) {
            return;
        }
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT id FROM poll WHERE id = ? FOR UPDATE');
            $lock->execute([$poll]);
            $exists = $this->db->prepare('SELECT 1 FROM poll_period WHERE capture_id = ?');
            $exists->execute([$id]);
            if ($exists->fetchColumn() !== false) {
                $this->db->commit();
                return;
            }
            $paidAt = self::utc($transaction['time'] ?? null) ?? gmdate('Y-m-d H:i:s');
            $insert = $this->db->prepare(
                'INSERT INTO poll_period (poll_id, period_number, request_key, amount, capture_id, paid_at, subscription_id)
                 SELECT ?, COALESCE(MAX(period_number), 0) + 1, ?, ?, ?, ?, ? FROM poll_period WHERE poll_id = ?'
            );
            $insert->execute([$poll, bin2hex(random_bytes(16)), $amount, $id, $paidAt, $subscription, $poll]);
            $period = (int) $this->db->lastInsertId();
            $life = new PollLifecycle($this->db);
            // Läuft die Befragung noch: nahtlos anschließen. Sonst ab Zahlung einen Monat.
            $life->chain($period, '1 MONTH');
            $start = $this->db->prepare(
                'UPDATE poll_period SET starts_at = paid_at, ends_at = paid_at + INTERVAL 1 MONTH
                 WHERE id = ? AND starts_at IS NULL'
            );
            $start->execute([$period]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        try {
            (new SandboxInvoices($this->db))->issue($period);
        } catch (Throwable $error) {
            error_log('Kompass invoice generation failed for subscription period ' . $period . ': ' . $error::class);
        }
    }

    private static function utc(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
