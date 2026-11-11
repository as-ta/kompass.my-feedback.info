<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

final class Wizard
{
    public function __construct(
        private readonly array $steps,
        private readonly array $surveys,
        private readonly array $combinations,
    ) {
    }

    public function resolve(array $query): array
    {
        $submitted = $query['goal'] ?? [];
        $values = is_array($submitted) ? $submitted : [$submitted];
        $goals = array_keys(array_intersect_key($this->steps['goal']['options'], array_fill_keys(
            array_filter($values, 'is_string'),
            true,
        )));
        $valid = $goals !== [] && count($goals) <= 3
            && (!in_array('employees', $goals, true) || count($goals) === 1)
            && count(array_filter($values, 'is_string')) === count($values)
            && array_diff($values, $goals) === [];

        if (!$valid) {
            return $this->step('goal', $this->steps['goal'], [], $query, $goals);
        }

        $answers = ['goal' => $goals];
        $main = $goals[0];
        if (count($goals) > 1) {
            $options = array_intersect_key($this->steps['goal']['options'], array_flip($goals));
            $main = $query['main'] ?? null;
            if (!is_string($main) || !isset($options[$main])) {
                return $this->step('main', [
                    'title' => 'Was möchten Sie zuerst herausfinden?',
                    'hint' => 'Das Hauptanliegen gibt vor, wen Sie befragen und worum es im Fragebogen vor allem geht.',
                    'options' => $options,
                ], $answers, $query);
            }
            $answers['main'] = $main;
        }

        $stepId = $this->steps['goal']['options'][$main]['next'];
        while (isset($this->steps[$stepId])) {
            $step = $this->steps[$stepId];
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
        $survey = $this->surveys[$surveyId];
        $included = [];
        $separate = [];
        $extraTopics = array_diff($goals, [$main]);
        $compatible = in_array($stepId, $this->combinations['compatible'], true);
        foreach ($extraTopics as $topic) {
            $label = $this->steps['goal']['options'][$topic]['label'];
            if (!$compatible) {
                $editStep = match ($stepId) {
                    'cancellation', 'repurchase' => 'audience',
                    'idea-customers', 'idea-prospects' => 'product-stage',
                    'product-nonpurchase' => 'product-audience',
                    default => 'main',
                };
                $separate[$topic] = [
                    'label' => $label,
                    'edit' => $this->editChoice($answers, $editStep),
                    'editLabel' => match ($editStep) {
                        'audience', 'product-audience' => 'Zurück zur Auswahl der Befragten',
                        'product-stage' => 'Auswahl zu Produkt oder Idee ändern',
                        default => 'Hauptanliegen ändern',
                    },
                    'canInclude' => false,
                ];
                continue;
            }

            $extraId = 'combine-' . $topic;
            $step = $this->combinations['steps'][$topic];
            $value = $query[$extraId] ?? null;
            if (!is_string($value) || !isset($step['options'][$value])) {
                return $this->step($extraId, $step, $answers, $query);
            }

            $answers[$extraId] = $value;
            $question = $step['options'][$value]['question'] ?? null;
            if ($question === null) {
                $separate[$topic] = [
                    'label' => $label,
                    'edit' => $this->editChoice($answers, $extraId),
                    'editLabel' => 'Auswahl ändern und Thema aufnehmen',
                    'canInclude' => true,
                ];
            } else {
                $survey['questions'][] = $question;
                $included[] = $label;
            }
        }

        if ($included !== []) {
            $survey['comment'] = 'Möchten Sie uns noch etwas mitteilen?';
        }

        return [
            'survey' => $survey,
            'surveyId' => $surveyId,
            'main' => $main,
            'answers' => $answers,
            'included' => $included,
            'separate' => $separate,
        ];
    }

    private function editChoice(array $answers, string $step): array
    {
        $position = array_search($step, array_keys($answers), true);
        return array_slice($answers, 0, $position) + ['selected' => $answers[$step]];
    }

    private function step(string $id, array $step, array $answers, array $query, array $goals = []): array
    {
        return [
            'stepId' => $id,
            'step' => $step,
            'answers' => $answers,
            'invalid' => array_key_exists($id, $query) || ($id === 'goal' && isset($query['choose-goals']))
                || (isset($step['checkboxes']) && isset($query['choose-' . $id])),
            'goals' => $goals,
        ];
    }
}
