<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use PDO;
use Throwable;

final class PollDrafts
{
    private const PAYPAL_STARTED = 'order_id IS NOT NULL OR capture_id IS NOT NULL OR paid_at IS NOT NULL';
    private const PAYMENT_PROTECTION = [
        'poll_period' => self::PAYPAL_STARTED . ' OR payment_pending = TRUE OR starts_at IS NOT NULL',
        'poll_paypal_sandbox' => self::PAYPAL_STARTED,
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    public function save(int $owner, string $key, array $snapshot): int
    {
        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare(
                'INSERT INTO poll (owner_id, save_key) VALUES (?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            );
            $insert->execute([$owner, $key]);
            $poll = (int) $this->db->lastInsertId();
            $existing = $this->db->prepare('SELECT id FROM poll_version WHERE poll_id = ? AND version_number = 1');
            $existing->execute([$poll]);

            if ($existing->fetchColumn() === false) {
                $version = $this->db->prepare(
                    'INSERT INTO poll_version (poll_id, version_number, title, purpose, audience, catalog_key, wizard_answers)
                     VALUES (?, 1, ?, ?, ?, ?, ?)'
                );
                $version->execute([
                    $poll, $snapshot['title'], $snapshot['purpose'], $snapshot['audience'], $snapshot['catalog'],
                    json_encode($snapshot['selection'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
                $versionId = (int) $this->db->lastInsertId();
                $addQuestion = $this->db->prepare(
                    'INSERT INTO question (poll_version_id, sort_order, prompt, question_type, metric, scale_low, scale_high, stars)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $addOption = $this->db->prepare(
                    'INSERT INTO question_option (question_id, sort_order, label, numeric_value) VALUES (?, ?, ?, ?)'
                );
                foreach ($snapshot['questions'] as $index => $question) {
                    $ends = $question['ends'] ?? [null, null];
                    $type = isset($question['ends']) ? 'scale' : (isset($question['options']) ? 'choice' : 'text');
                    $addQuestion->execute([
                        $versionId, $index + 1, $question['text'], $type, $question['metric'] ?? null,
                        $ends[0], $ends[1], (int) !empty($question['stars']),
                    ]);
                    $questionId = (int) $this->db->lastInsertId();
                    foreach ($question['options'] ?? [] as $position => $option) {
                        $addOption->execute([$questionId, $position + 1, $option, $type === 'scale' ? (int) $option : null]);
                    }
                }
            }
            $this->db->commit();
            return $poll;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function delete(int $owner, int $poll): bool
    {
        $this->db->beginTransaction();
        try {
            // The payment flow takes the same poll lock before creating a booking period.
            $lock = $this->db->prepare("SELECT id FROM poll WHERE id = ? AND owner_id = ? AND status = 'draft' FOR UPDATE");
            $lock->execute([$poll, $owner]);
            if ($lock->fetchColumn() === false) {
                $this->db->rollBack();
                return false;
            }

            // A PayPal check can update an existing period without holding the poll lock.
            foreach (self::PAYMENT_PROTECTION as $table => $condition) {
                $periods = $this->db->prepare("SELECT ($condition) FROM $table WHERE poll_id = ? FOR UPDATE");
                $periods->execute([$poll]);
                if (in_array(1, array_map('intval', $periods->fetchAll(PDO::FETCH_COLUMN)), true)) {
                    $this->db->rollBack();
                    return false;
                }
            }

            foreach ([
                'DELETE o FROM question_option o JOIN question q ON q.id = o.question_id
                 JOIN poll_version v ON v.id = q.poll_version_id WHERE v.poll_id = ?',
                'DELETE q FROM question q JOIN poll_version v ON v.id = q.poll_version_id WHERE v.poll_id = ?',
                'DELETE FROM poll_version WHERE poll_id = ?',
                'DELETE FROM poll_public WHERE poll_id = ?',
                'DELETE FROM poll_period WHERE poll_id = ?',
                'DELETE FROM poll_paypal_sandbox WHERE poll_id = ?',
                'DELETE FROM poll WHERE id = ?',
            ] as $sql) {
                $this->db->prepare($sql)->execute([$poll]);
            }
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    private function deletableSql(): string
    {
        $conditions = ["p.status = 'draft'"];
        foreach (self::PAYMENT_PROTECTION as $table => $condition) {
            $conditions[] = "NOT EXISTS (SELECT 1 FROM $table WHERE poll_id = p.id AND ($condition))";
        }
        return implode(' AND ', $conditions);
    }

    public function listFor(int $owner): array
    {
        $statement = $this->db->prepare(
            'SELECT p.id, p.created_at, v.title, s.id AS period_id, s.order_id, s.amount AS period_amount,
                 s.period_number, s.paid_at, s.starts_at, s.ends_at,
                 s.ends_at > UTC_TIMESTAMP() AS running, s.payment_pending, (' . $this->deletableSql() . ') AS deletable
             FROM poll p JOIN poll_version v ON v.poll_id = p.id AND v.version_number = 1
             LEFT JOIN poll_period s ON s.id = (
                 SELECT id FROM poll_period WHERE poll_id = p.id ORDER BY period_number DESC LIMIT 1
             )
             WHERE p.owner_id = ? ORDER BY p.id DESC'
        );
        $statement->execute([$owner]);
        return $statement->fetchAll();
    }

    public function find(int $owner, int $poll): ?array
    {
        $statement = $this->db->prepare(
            'SELECT v.id, v.title, v.purpose, v.audience, (' . $this->deletableSql() . ') AS deletable
             FROM poll_version v JOIN poll p ON p.id = v.poll_id
             WHERE p.owner_id = ? AND p.id = ? ORDER BY v.version_number DESC LIMIT 1'
        );
        $statement->execute([$owner, $poll]);
        $version = $statement->fetch();
        if ($version === false) {
            return null;
        }

        $questions = $this->db->prepare(
            'SELECT q.id, q.prompt, q.question_type, q.scale_low, q.scale_high, q.stars, o.id AS option_id, o.label
             FROM question q LEFT JOIN question_option o ON o.question_id = q.id
             WHERE q.poll_version_id = ? ORDER BY q.sort_order, o.sort_order'
        );
        $questions->execute([$version['id']]);
        $version['questions'] = [];
        $version['comment'] = null;
        foreach ($questions as $row) {
            if ($row['question_type'] === 'text') {
                $version['comment'] = $row['prompt'];
                $version['comment_id'] = (int) $row['id'];
                continue;
            }
            $id = $row['id'];
            if (!isset($version['questions'][$id])) {
                $version['questions'][$id] = ['id' => (int) $id, 'text' => $row['prompt'], 'options' => [], 'option_ids' => [], 'stars' => (bool) $row['stars']];
                if ($row['question_type'] === 'scale') {
                    $version['questions'][$id]['ends'] = [$row['scale_low'], $row['scale_high']];
                }
            }
            $version['questions'][$id]['options'][] = $row['label'];
            $version['questions'][$id]['option_ids'][] = (int) $row['option_id'];
        }
        $version['questions'] = array_values($version['questions']);
        return $version;
    }
}
