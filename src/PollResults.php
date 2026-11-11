<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use JsonException;
use PDO;

final class PollResults
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function pollsFor(int $owner): array
    {
        $read = $this->db->prepare(
            'SELECT p.id, p.created_at, v.title,
                 (SELECT MAX(r.completed_at) FROM poll_response r JOIN poll_period s ON s.id = r.poll_period_id
                  WHERE s.poll_id = p.id) AS last_response
             FROM poll p
             JOIN poll_version v ON v.poll_id = p.id AND v.version_number = (
                 SELECT MAX(version_number) FROM poll_version WHERE poll_id = p.id
             )
             WHERE p.owner_id = ?
                 AND EXISTS (SELECT 1 FROM poll_period s WHERE s.poll_id = p.id AND s.starts_at IS NOT NULL)
             ORDER BY last_response IS NULL, last_response DESC, p.id DESC'
        );
        $read->execute([$owner]);
        return $read->fetchAll();
    }

    public function report(int $owner, int $poll): ?array
    {
        $read = $this->db->prepare(
            'SELECT p.id, v.title, v.purpose FROM poll p JOIN poll_version v ON v.poll_id = p.id
             WHERE p.owner_id = ? AND p.id = ? ORDER BY v.version_number DESC LIMIT 1'
        );
        $read->execute([$owner, $poll]);
        $report = $read->fetch();
        if ($report === false) {
            return null;
        }
        $read = $this->db->prepare(
            'SELECT q.*, v.version_number FROM question q JOIN poll_version v ON v.id = q.poll_version_id
             JOIN poll p ON p.id = v.poll_id WHERE p.owner_id = ? AND p.id = ?
             ORDER BY v.version_number, q.sort_order, q.id'
        );
        $read->execute([$owner, $poll]);
        $questions = [];
        foreach ($read->fetchAll() as $question) {
            $questions[(int) $question['id']] = $question + ['options' => [], 'comments' => [], 'answered' => 0];
        }
        $read = $this->db->prepare(
            'SELECT o.* FROM question_option o JOIN question q ON q.id = o.question_id
             JOIN poll_version v ON v.id = q.poll_version_id JOIN poll p ON p.id = v.poll_id
             WHERE p.owner_id = ? AND p.id = ? ORDER BY q.id, o.sort_order, o.id'
        );
        $read->execute([$owner, $poll]);
        foreach ($read->fetchAll() as $option) {
            $questions[(int) $option['question_id']]['options'][(int) $option['id']] = [
                'label' => $option['label'], 'numeric' => $option['numeric_value'], 'count' => 0,
            ];
        }
        $read = $this->db->prepare(
            'SELECT r.answers, r.poll_version_id, r.submitted_at, r.completed_at,
                 r.completed_at IS NULL AND r.updated_at < UTC_TIMESTAMP() - INTERVAL 24 HOUR AS abandoned
             FROM poll_response r
             JOIN poll_period s ON s.id = r.poll_period_id
             JOIN poll_version v ON v.id = r.poll_version_id AND v.poll_id = s.poll_id
             JOIN poll p ON p.id = s.poll_id WHERE p.owner_id = ? AND p.id = ?
             ORDER BY r.submitted_at DESC, r.id DESC'
        );
        $read->execute([$owner, $poll]);
        $total = 0;
        $completed = 0;
        $abandoned = 0;
        $dropoff = [];
        $versions = [];
        $last = null;
        while ($response = $read->fetch()) {
            try {
                $answers = json_decode($response['answers'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (!is_array($answers)) {
                continue;
            }
            ++$total;
            if ($response['completed_at'] !== null) {
                ++$completed;
                $last ??= $response['completed_at'];
            } elseif ((int) $response['abandoned'] === 1) {
                // Abgebrochen: nach wie vielen beantworteten Fragen?
                ++$abandoned;
                $at = count($answers) + 1;
                $dropoff[$at] = ($dropoff[$at] ?? 0) + 1;
            }
            $version = (int) $response['poll_version_id'];
            $versions[$version] = ($versions[$version] ?? 0) + 1;
            foreach ($answers as $id => $answer) {
                if (!isset($questions[$id]) || (int) $questions[$id]['poll_version_id'] !== $version) {
                    continue;
                }
                $question = &$questions[$id];
                if ($question['question_type'] === 'text') {
                    if (is_string($answer) && trim($answer) !== '') {
                        $question['comments'][] = trim($answer);
                        ++$question['answered'];
                    }
                } elseif ((is_int($answer) || is_string($answer)) && isset($question['options'][$answer])) {
                    ++$question['options'][$answer]['count'];
                    ++$question['answered'];
                }
                unset($question);
            }
        }
        foreach ($questions as &$question) {
            $question['participants'] = $versions[(int) $question['poll_version_id']] ?? 0;
            $question['summary'] = $this->summary($question);
            foreach ($question['options'] as &$option) {
                $option['percent'] = $question['answered'] === 0 ? 0 : 100 * $option['count'] / $question['answered'];
            }
            unset($option);
            $question['options'] = array_values($question['options']);
        }
        unset($question);
        $timeline = $this->db->prepare(
            'SELECT DATE(CONVERT_TZ(r.completed_at, \'+00:00\', \'Europe/Berlin\')) AS day, COUNT(*) AS responses
             FROM poll_response r JOIN poll_period s ON s.id = r.poll_period_id
             JOIN poll p ON p.id = s.poll_id WHERE p.owner_id = ? AND p.id = ? AND r.completed_at IS NOT NULL
             GROUP BY day ORDER BY day'
        );
        try {
            $timeline->execute([$owner, $poll]);
            $rows = $timeline->fetchAll();
            // Ohne Zeitzonentabellen liefert CONVERT_TZ NULL: dann nach UTC-Tag gruppieren.
            if (array_filter($rows, static fn (array $row): bool => $row['day'] === null) !== []) {
                throw new \PDOException('time zone tables missing');
            }
            $report['timeline'] = $rows;
        } catch (\PDOException) {
            // Ohne Zeitzonentabellen auf dem Server: nach UTC-Tag gruppieren.
            $fallback = $this->db->prepare(
                'SELECT DATE(r.completed_at) AS day, COUNT(*) AS responses
                 FROM poll_response r JOIN poll_period s ON s.id = r.poll_period_id
                 JOIN poll p ON p.id = s.poll_id WHERE p.owner_id = ? AND p.id = ? AND r.completed_at IS NOT NULL
                 GROUP BY day ORDER BY day'
            );
            $fallback->execute([$owner, $poll]);
            $report['timeline'] = $fallback->fetchAll();
        }
        $report['responses'] = $total;
        $report['completed'] = $completed;
        $report['abandoned'] = $abandoned;
        $report['in_progress'] = $total - $completed - $abandoned;
        arsort($dropoff);
        $report['dropoff_question'] = $dropoff === [] ? null : (int) array_key_first($dropoff);
        $report['last_submitted'] = $last;
        $report['questions'] = array_values($questions);
        $report['multiple_versions'] = count(array_unique(array_column($questions, 'version_number'))) > 1;
        return $report;
    }

    private function summary(array $question): ?array
    {
        if ($question['question_type'] !== 'scale' || $question['answered'] === 0 || $question['options'] === []) {
            return null;
        }
        $sum = 0;
        $values = [];
        $groups = ['negative' => 0, 'neutral' => 0, 'positive' => 0];
        foreach ($question['options'] as $option) {
            if ($option['numeric'] === null) {
                return null;
            }
            $value = (int) $option['numeric'];
            $values[] = $value;
            $sum += $value * $option['count'];
            if ($value <= 6) {
                $groups['negative'] += $option['count'];
            } elseif ($value <= 8) {
                $groups['neutral'] += $option['count'];
            } else {
                $groups['positive'] += $option['count'];
            }
        }
        $summary = ['mean' => $sum / $question['answered'], 'min' => min($values), 'max' => max($values)];
        sort($values);
        if ($question['metric'] === 'nps' && $values === range(0, 10)) {
            $summary['nps'] = 100 * ($groups['positive'] - $groups['negative']) / $question['answered'];
            $summary['groups'] = $groups;
        }
        if ($question['metric'] === 'csat' && $values === range(1, 5)) {
            $positive = 0;
            foreach ($question['options'] as $option) {
                if ((int) $option['numeric'] >= 4) {
                    $positive += $option['count'];
                }
            }
            $summary['satisfied'] = 100 * $positive / $question['answered'];
        }
        return $summary;
    }
}
