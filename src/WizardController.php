<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

final class WizardController extends AbstractActionController
{
    public function indexAction(): ViewModel|Response
    {
        $query = $this->getRequest()->getQuery()->toArray();
        if (array_key_exists('befragung', $query)) {
            return $this->forward()->dispatch(AccountController::class, ['action' => 'participate']);
        }
        if (isset($query['account'])) {
            return $this->forward()->dispatch(AccountController::class, ['action' => 'index']);
        }
        $accountEnabled = AccountAccess::configured(require dirname(__DIR__) . '/config/account.php');
        $this->layout()->accountEnabled = $accountEnabled;
        if ($accountEnabled && isset($_COOKIE[session_name()])) {
            AccountSession::start();
            if (AccountSession::userId() !== null) {
                $this->layout()->wizardWorkspace = true;
                $this->layout()->workspaceCsrf = AccountSession::csrf();
            }
        }
        if (($query['faq'] ?? null) === '1') {
            return (new ViewModel())->setTemplate('wizard/faq');
        }
        if (($query['contact'] ?? null) === '1') {
            return $this->contact();
        }
        $this->layout()->progress = [
            'current' => 1,
            'done' => $this->layout()->wizardWorkspace ? [2] : [],
        ];

        $catalog = require dirname(__DIR__) . '/config/catalog.php';
        $combinations = require dirname(__DIR__) . '/config/combinations.php';
        $wizard = new Wizard($catalog['steps'], $catalog['surveys'], $combinations);
        $result = $wizard->resolve($query);

        $back = $result['answers'];
        if ($back !== []) {
            $back['selected'] = array_pop($back);
        }

        if (!isset($result['survey'])) {
            return (new ViewModel($result + ['back' => $back, 'selected' => $query['selected'] ?? (isset($result['step']['checkboxes']) ? ($query[$result['stepId']] ?? null) : null)]))
                ->setTemplate('wizard/step');
        }

        $survey = $result['survey'];
        $answers = $result['answers'];
        $addons = require dirname(__DIR__) . '/config/addons.php';
        $options = $addons[$result['main']] + [
            'comment' => ['text' => $survey['comment'] ?? 'Möchten Sie uns noch etwas mitteilen?'],
        ];

        $submitted = $query['extras'] ?? [];
        $selected = is_array($submitted) ? array_intersect_key($options, array_fill_keys(
            array_filter($submitted, 'is_string'),
            true,
        )) : [];
        $done = ($query['review'] ?? null) === '1';
        if (!$done && !isset($query['editing'])) {
            $selected = array_intersect_key($options, ['comment' => true]);
        }

        $questions = $survey['questions'];
        foreach ($selected as $key => $question) {
            if ($key !== 'comment') {
                $questions[] = $question;
            }
        }

        $review = $answers + ['review' => '1', 'extras' => array_keys($selected)];
        $audience = $catalog['audiences'][$result['surveyId']];
        $draftKey = null;
        if ($accountEnabled && $done && ($query['guide'] ?? null) !== '1') {
            AccountSession::start();
            $snapshot = [
                'title' => $survey['title'],
                'purpose' => $survey['why'],
                'audience' => $audience,
                'catalog' => $result['surveyId'],
                'selection' => $review,
                'questions' => $questions,
            ];
            if (isset($selected['comment'])) {
                $snapshot['questions'][] = ['text' => $selected['comment']['text']];
            }
            $draftKey = AccountSession::remember($snapshot);
        }
        $view = new ViewModel([
            'survey' => $survey,
            'audience' => $audience,
            'draftKey' => $draftKey,
            'draftCsrf' => $draftKey === null ? null : AccountSession::csrf(),
            'answers' => $answers,
            'main' => $result['main'],
            'included' => $result['included'],
            'separate' => $result['separate'],
            'back' => $back,
            'options' => $options,
            'selected' => $selected,
            'questions' => $questions,
            'review' => $review,
            'progress' => Wizard::addonsProgress($answers),
            'longSurvey' => count($selected) >= 3 || count($survey['questions']) + count($selected) >= 7,
        ]);
        if (!$done) {
            return $view->setTemplate('wizard/addons');
        }

        return $view->setTemplate(($query['guide'] ?? null) === '1' ? 'wizard/guide' : 'wizard/preview');
    }

    private function contact(): ViewModel|Response
    {
        $contact = new Contact();
        if ($this->getRequest()->isPost()) {
            $result = $contact->submit($this->getRequest()->getPost()->toArray());
            if ($result === null) {
                return $this->redirect()->toRoute('wizard', [], ['query' => ['contact' => '1']])->setStatusCode(303);
            }
        } else {
            $result = [];
        }

        return (new ViewModel($result + $contact->form()))->setTemplate('wizard/contact');
    }
}
