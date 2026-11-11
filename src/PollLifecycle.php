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
            'SELECT s.*, s.ends_at > UTC_TIMESTAMP() AS running,
                 (SELECT MAX(r.ends_at) FROM poll_period r WHERE r.poll_id = s.poll_id
                     AND r.paid_at IS NOT NULL AND r.starts_at IS NOT NULL) AS running_until,
                 (SELECT COUNT(*) FROM poll_period r WHERE r.poll_id = s.poll_id AND r.paid_at IS NOT NULL
                     AND r.starts_at IS NOT NULL AND r.ends_at > UTC_TIMESTAMP()) AS running_periods
             FROM poll_period s WHERE s.poll_id = ? ORDER BY s.period_number DESC LIMIT 1'
        );
        $read->execute([$poll]);
        return $read->fetch() ?: null;
    }

    /** Der Zeitraum, der gerade läuft (bei Verlängerungen der ältere, bis der nächste anschließt). */
    public function current(int $poll): ?array
    {
        $read = $this->db->prepare(
            'SELECT * FROM poll_period WHERE poll_id = ? AND paid_at IS NOT NULL
                 AND starts_at <= UTC_TIMESTAMP() AND ends_at > UTC_TIMESTAMP()
             ORDER BY period_number LIMIT 1'
        );
        $read->execute([$poll]);
        return $read->fetch() ?: null;
    }

    public static function status(?array $period): string
    {
        // Läuft noch ein bezahlter Zeitraum, ist die Befragung aktiv, auch wenn eine Verlängerung noch offen ist.
        if ((int) ($period['running_periods'] ?? 0) > 0) {
            return 'active';
        }
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

    public function responseCount(int $poll): int
    {
        $read = $this->db->prepare(
            'SELECT COUNT(*) FROM poll_response r JOIN poll_period s ON s.id = r.poll_period_id
             WHERE s.poll_id = ? AND r.completed_at IS NOT NULL'
        );
        $read->execute([$poll]);
        return (int) $read->fetchColumn();
    }

    /**
     * Schließt einen frisch bezahlten Zeitraum nahtlos an einen noch laufenden an.
     * Läuft nichts mehr, bleibt er „bereit zum Start“ und der Kunde startet selbst.
     */
    public function chain(int $period, string $interval = '28 DAY'): void
    {
        if (!in_array($interval, ['28 DAY', '1 MONTH'], true)) {
            throw new \InvalidArgumentException('Unsupported interval');
        }
        $write = $this->db->prepare(
            'UPDATE poll_period s
             JOIN (SELECT p.poll_id, MAX(p.ends_at) AS until FROM poll_period p
                   WHERE p.paid_at IS NOT NULL AND p.starts_at IS NOT NULL AND p.id <> ? GROUP BY p.poll_id) r
               ON r.poll_id = s.poll_id
             SET s.starts_at = r.until, s.ends_at = r.until + INTERVAL ' . $interval . '
             WHERE s.id = ? AND s.paid_at IS NOT NULL AND s.starts_at IS NULL AND r.until > UTC_TIMESTAMP()'
        );
        $write->execute([$period, $period]);
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

    /**
     * Speichert den Stand einer Teilnahme nach jeder Seite. Beim Absenden der letzten Seite
     * wird sie als vollständig markiert. Gilt nur, solange der Zeitraum läuft.
     */
    public function saveProgress(int $period, int $version, string $key, array $answers, bool $complete): bool
    {
        $json = json_encode($answers === [] ? new \stdClass() : $answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $write = $this->db->prepare(
            'INSERT INTO poll_response (poll_period_id, poll_version_id, submission_key, answers, started_at, updated_at, completed_at)
             SELECT s.id, v.id, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), IF(?, UTC_TIMESTAMP(), NULL)
             FROM poll_period s JOIN poll_version v ON v.poll_id = s.poll_id
             WHERE s.id = ? AND v.id = ? AND s.paid_at IS NOT NULL
                 AND s.starts_at <= UTC_TIMESTAMP() AND s.ends_at > UTC_TIMESTAMP()
             ON DUPLICATE KEY UPDATE
                 answers = IF(poll_response.completed_at IS NULL, VALUES(answers), poll_response.answers),
                 updated_at = UTC_TIMESTAMP(),
                 completed_at = COALESCE(poll_response.completed_at, VALUES(completed_at))'
        );
        $write->execute([$key, $json, (int) $complete, $period, $version]);
        $read = $this->db->prepare('SELECT 1 FROM poll_response WHERE poll_period_id = ? AND submission_key = ?');
        $read->execute([$period, $key]);
        return $read->fetchColumn() !== false;
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
