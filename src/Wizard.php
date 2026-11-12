<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

/**
 * Wizard „Anliegen klären“: ein Anliegen („Wo drückt der Schuh?“), dann die Branche, dann die Fragen zum Anliegen.
 * Ergänzende Themen (z. B. Zufriedenheit zusätzlich zur Wiederkaufabsicht) gibt es als Zusatzfragen.
 */
final class Wizard
{
    public function __construct(
        private readonly array $steps,
        private readonly array $surveys,
        private readonly array $combinations,
        private readonly array $industries = [],
    ) {
    }

    public function resolve(array $query): array
    {
        // Anliegen: genau eines. Ältere Links mit goal[]=… und genau einem Eintrag funktionieren weiter.
        $submitted = $query['goal'] ?? null;
        if (is_array($submitted) && count($submitted) === 1) {
            $submitted = reset($submitted);
        }
        $goal = is_string($submitted) && isset($this->steps['goal']['options'][$submitted]) ? $submitted : null;
        if ($goal === null) {
            return $this->step('goal', $this->steps['goal'], [], $query);
        }
        $answers = ['goal' => $goal];

        if ($this->industries !== []) {
            $industry = $query['branche'] ?? null;
            if (!is_string($industry) || !isset($this->industries[$industry])) {
                return $this->step('branche', $this->industryStep(), $answers, $query);
            }
            $answers['branche'] = $industry;
        }
        $industry = $this->industries[$answers['branche'] ?? ''] ?? [];

        $stepId = $this->steps['goal']['options'][$goal]['next'];
        while (isset($this->steps[$stepId])) {
            $step = $this->adaptStep($stepId, $this->steps[$stepId], $industry);
            $value = $query[$stepId] ?? null;
            if (isset($step['checkboxes']) && is_array($value)) {
                $known = array_keys($step['checkboxes']);
                $selected = array_filter($value, static fn ($item): bool => in_array($item, $known, true));
                $value = count($selected) === count($value) ? implode('-', array_intersect($known, $selected)) : null;
            }
            if (!is_string($value) || !isset($step['options'][$value])) {
                return $this->step($stepId, $step, $answers, $query);
            }

            $answers[$stepId] = $value;
            $stepId = $step['options'][$value]['next'];
        }

        $surveyId = $stepId . ($this->combinations['metricSuffix'][$answers['metric'] ?? ''] ?? '');
        $survey = $this->adaptSurvey($surveyId, $this->surveys[$surveyId], $industry);

        return [
            'survey' => $survey,
            'surveyId' => $surveyId,
            'baseId' => $stepId,
            'main' => $goal,
            'industry' => $answers['branche'] ?? null,
            'answers' => $answers,
            'included' => [],
            'separate' => [],
        ];
    }

    /** Zielgruppe in der Sprache der Branche */
    public function audience(string $surveyId, string $audience, ?string $industry): string
    {
        $config = $this->industries[$industry ?? ''] ?? [];
        if (isset($config['visit']['audience']) && str_starts_with($surveyId, 'store-satisfaction')) {
            return $config['visit']['audience'];
        }
        return self::people($audience, $config);
    }

    private function industryStep(): array
    {
        return [
            'title' => 'In welcher Branche sind Sie tätig?',
            'hint' => 'Damit wir die Fragen in Ihrer Sprache stellen.',
            'options' => array_map(static fn (array $industry): array => ['label' => $industry['label']], $this->industries),
        ];
    }

    private function adaptStep(string $id, array $step, array $industry): array
    {
        foreach ($industry['hide'][$id] ?? [] as $hidden) {
            unset($step['options'][$hidden]);
        }
        foreach ($step['options'] as $key => $option) {
            if (isset($industry['labels'][$id . '.' . $key])) {
                $step['options'][$key]['label'] = $industry['labels'][$id . '.' . $key];
            }
        }
        $step['title'] = self::people($step['title'], $industry);
        $step['hint'] = self::people($step['hint'] ?? '', $industry);
        foreach (['options', 'checkboxes'] as $group) {
            foreach ($step[$group] ?? [] as $key => $option) {
                $step[$group][$key]['label'] = self::people($option['label'] ?? '', $industry);
                if (isset($option['hint'])) {
                    $step[$group][$key]['hint'] = self::people($option['hint'], $industry);
                }
            }
        }
        return $step;
    }

    private function adaptSurvey(string $surveyId, array $survey, array $industry): array
    {
        $visit = $industry['visit'] ?? null;
        if ($visit !== null && str_starts_with($surveyId, 'store-satisfaction')) {
            $default = $this->surveys['store-satisfaction'];
            $survey['title'] = str_replace($default['title'], $visit['title'], $survey['title']);
            $survey['distribution'] = $visit['distribution'];
            foreach ($survey['questions'] as $index => $question) {
                if ($question['text'] === $default['questions'][0]['text']) {
                    $survey['questions'][$index]['text'] = $visit['question'];
                } elseif ($question['text'] === $default['questions'][1]['text']) {
                    $survey['questions'][$index]['options'] = $visit['aspects'];
                } elseif (($question['metric'] ?? null) === 'ces') {
                    $survey['questions'][$index]['text'] = $visit['effort'];
                }
            }
        }
        foreach ($survey['questions'] as $index => $question) {
            $survey['questions'][$index] = self::adaptQuestion($question, $industry);
        }
        if (isset($survey['comment'])) {
            $survey['comment'] = self::phrase($survey['comment'], $industry);
        }
        $survey['title'] = self::owner(self::people($survey['title'], $industry), $industry);
        $survey['why'] = self::owner(self::people($survey['why'], $industry), $industry);
        return $survey;
    }

    /** Frage (inkl. Zusatzfrage) in der Sprache der Branche */
    public function question(array $question, ?string $industry): array
    {
        return self::adaptQuestion($question, $this->industries[$industry ?? ''] ?? []);
    }

    private static function adaptQuestion(array $question, array $industry): array
    {
        $question['text'] = self::phrase($question['text'], $industry);
        if (isset($question['options']) && !isset($question['ends'])) {
            $question['options'] = array_map(static fn (string $option): string => self::phrase($option, $industry), $question['options']);
        }
        return $question;
    }

    private static function phrase(string $text, array $industry): string
    {
        $text = strtr($text, $industry['phrases'] ?? []);
        return isset($industry['company']) ? str_replace('unser Unternehmen', $industry['company'], $text) : $text;
    }

    private static function owner(string $text, array $industry): string
    {
        return isset($industry['owner']) ? str_replace('Ihr Unternehmen', $industry['owner'], $text) : $text;
    }

    /** „Kunden“ durch die Bezeichnung der Branche ersetzen (nur ganze Wörter, „Kundenservice“ bleibt). */
    private static function people(string $text, array $industry): string
    {
        return isset($industry['people']) ? (string) preg_replace('/\bKunden\b/u', $industry['people'], $text) : $text;
    }

    /**
     * Anteil der bereits beantworteten Wizard-Fragen (0 bis 1).
     * Der Zusatzfragen-Schritt zählt als letzte Station vor der Empfehlung.
     */
    public static function addonsProgress(array $answers): float
    {
        return count($answers) / (count($answers) + 1);
    }

    private function progress(string $id, array $answers): float
    {
        $industryStep = $this->industries !== [] ? 1 : 0;
        $remaining = match ($id) {
            'goal' => $this->longest('goal') + $industryStep,
            'branche' => 1 + $this->longest($this->steps['goal']['options'][$answers['goal']]['next']),
            default => $this->longest($id),
        };

        return count($answers) / (count($answers) + $remaining + 1);
    }

    private function longest(string $id): int
    {
        static $memo = [];
        if (!isset($this->steps[$id])) {
            return 0;
        }
        if (!isset($memo[$id])) {
            $memo[$id] = 1 + max(0, ...array_map(
                fn (array $option): int => isset($option['next']) ? $this->longest($option['next']) : 0,
                array_values($this->steps[$id]['options']),
            ));
        }

        return $memo[$id];
    }

    private function step(string $id, array $step, array $answers, array $query): array
    {
        return [
            'stepId' => $id,
            'progress' => $this->progress($id, $answers),
            'step' => $step,
            'answers' => $answers,
            'invalid' => array_key_exists($id, $query) || (isset($step['checkboxes']) && isset($query['choose-' . $id])),
            'goals' => [],
        ];
    }
}
