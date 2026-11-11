<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use PDO;

final class PollLifecycle
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function latest(int $poll): ?array
    {
        $read = $this->db->prepare(
            'SELECT *, ends_at > UTC_TIMESTAMP() AS running
             FROM poll_period WHERE poll_id = ? ORDER BY period_number DESC LIMIT 1'
        );
        $read->execute([$poll]);
        return $read->fetch() ?: null;
    }

    public static function status(?array $period): string
    {
        if (!isset($period['paid_at'])) {
            return (int) ($period['period_number'] ?? 1) > 1 ? 'closed' : 'draft';
        }
        if ($period['starts_at'] === null) {
            return 'ready';
        }
        return $period['running'] ? 'active' : 'closed';
    }

    public function token(int $poll): string
    {
        $insert = $this->db->prepare(
            'INSERT INTO poll_public (poll_id, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE poll_id = poll_id'
        );
        $insert->execute([$poll, bin2hex(random_bytes(16))]);
        $read = $this->db->prepare('SELECT token FROM poll_public WHERE poll_id = ?');
        $read->execute([$poll]);
        return $read->fetchColumn();
    }

    public function identify(string $token): ?array
    {
        $read = $this->db->prepare(
            'SELECT p.id, p.owner_id FROM poll_public l JOIN poll p ON p.id = l.poll_id WHERE l.token = ?'
        );
        $read->execute([$token]);
        return $read->fetch() ?: null;
    }

    public function start(int $owner, int $poll, int $period): void
    {
        $start = $this->db->prepare(
            'UPDATE poll_period s JOIN poll p ON p.id = s.poll_id
             SET s.starts_at = UTC_TIMESTAMP(), s.ends_at = UTC_TIMESTAMP() + INTERVAL 28 DAY
             WHERE p.owner_id = ? AND p.id = ? AND s.id = ? AND s.paid_at IS NOT NULL AND s.starts_at IS NULL'
        );
        $start->execute([$owner, $poll, $period]);
    }

    public function receipts(int $poll): array
    {
        $read = $this->db->prepare(
            'SELECT s.id, s.period_number, i.invoice_number FROM poll_period s
             JOIN sandbox_invoice i ON i.poll_period_id = s.id
             WHERE s.poll_id = ? AND s.paid_at IS NOT NULL ORDER BY s.period_number DESC'
        );
        $read->execute([$poll]);
        return $read->fetchAll();
    }

    public function submit(int $period, int $version, string $key, array $answers): bool
    {
        $insert = $this->db->prepare(
            'INSERT INTO poll_response (poll_period_id, poll_version_id, submission_key, answers)
             SELECT s.id, v.id, ?, ? FROM poll_period s JOIN poll_version v ON v.poll_id = s.poll_id
             WHERE s.id = ? AND v.id = ? AND s.paid_at IS NOT NULL
                 AND s.starts_at IS NOT NULL AND s.ends_at > UTC_TIMESTAMP()
             ON DUPLICATE KEY UPDATE submission_key = poll_response.submission_key'
        );
        $insert->execute([$key, json_encode($answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $period, $version]);
        $read = $this->db->prepare('SELECT 1 FROM poll_response WHERE poll_period_id = ? AND submission_key = ?');
        $read->execute([$period, $key]);
        return $read->fetchColumn() !== false;
    }
}
