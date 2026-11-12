<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class AccountController extends AbstractActionController
{
    public function indexAction(): ViewModel|Response
    {
        $accountSettings = require dirname(__DIR__) . '/config/account.php';
        $this->layout()->accountEnabled = AccountAccess::configured($accountSettings);
        $this->getResponse()->getHeaders()
            ->addHeaderLine('Cache-Control', 'no-store')
            ->addHeaderLine('Referrer-Policy', 'no-referrer');

        if (!$this->layout()->accountEnabled) {
            $this->getResponse()->setStatusCode(503);
            return $this->page('unavailable');
        }

        AccountSession::start();
        $action = $this->params()->fromQuery('account', '');
        // „Fragebogen anpassen“ endet, sobald man den Wizard über das Konto verlässt.
        if (!in_array($action, ['save', 'edit', 'edit-cancel'], true)) {
            unset($_SESSION['kompass_edit']);
        }
        $post = $this->getRequest()->isPost() ? $this->getRequest()->getPost()->toArray() : null;
        if ($post !== null && !AccountSession::accepts($post['csrf'] ?? null)) {
            $this->getResponse()->setStatusCode(400);
            return $this->page('invalid');
        }

        try {
            $db = $this->database($accountSettings);
            $life = new PollLifecycle($db);
            $drafts = new PollDrafts($db);
            $user = AccountSession::userId();
            $adminFile = dirname(__DIR__) . '/config/admin.php';
            $adminSettings = is_file($adminFile) ? require $adminFile : [];
            $bookkeeping = new Bookkeeping($db, (array) ($adminSettings['emails'] ?? []));
            $this->layout()->bookkeepingAdmin = $bookkeeping->allowed($user);
            if ($user !== null) {
                $this->layout()->accountUser = ['csrf' => AccountSession::csrf(), 'admin' => (bool) $this->layout()->bookkeepingAdmin];
            }
            $this->layout()->accountCurrent = match ($action) {
                'draft', 'delete', 'print' => 'list',
                'bookkeeping-export', 'bookkeeping-receipt' => 'bookkeeping',
                default => $action === '' ? 'list' : $action,
            };
            $adminAction = in_array($action, ['bookkeeping', 'bookkeeping-export', 'bookkeeping-receipt', 'issuer'], true);
            if ($adminAction && !$this->layout()->bookkeepingAdmin) {
                if ($user === null) {
                    return $this->go('login');
                }
                $this->getResponse()->setStatusCode(403);
                return $this->page('forbidden');
            }
            if ($this->layout()->bookkeepingAdmin && !$adminAction && !in_array($action, ['login', 'verify', 'logout'], true)) {
                return $this->go('bookkeeping');
            }
            $billing = new AccountBilling($db);
            if ($action !== 'billing') {
                // Ein abgebrochener Zahlungsversuch soll später nicht ungefragt zu PayPal weiterleiten.
                unset($_SESSION['kompass_billing_next']);
            }
            $paypal = require dirname(__DIR__) . '/config/paypal.php';
            $payments = PayPalSandbox::configured($paypal)
                ? new PollPayments($db, new PayPalSandbox($paypal), $paypal['amount'])
                : null;
            $subscriptions = self::subscriptions($db, $paypal);

            return match ($action) {
                'save' => $this->save($drafts, $user, $post),
                'login' => $this->login(new AccountAccess($db, $accountSettings), $user, $post),
                'verify' => $this->verify(new AccountAccess($db, $accountSettings), $drafts, $post),
                'logout' => $this->logout($post),
                'billing' => $this->billing($billing, $user, $post),
                'branding' => $this->branding(new AccountBranding($db), $user, $post),
                'draft' => $this->draft($drafts, $life, $billing, $user, $payments !== null, $payments === null ? '' : $paypal['amount'], $accountSettings['url'], $payments === null ? '' : (string) $paypal['client_id'], new AccountBranding($db), $subscriptions),
                'delete' => $this->deleteDraft($drafts, $user, $post),
                'edit' => $this->editDraft($drafts, $user),
                'edit-cancel' => $this->cancelEdit(),
                'start' => $this->startPoll($life, $user, $post),
                'issuer' => $this->issuer(new InvoiceIssuer($db), new SandboxInvoices($db), $post),
                'bookkeeping', 'bookkeeping-export', 'bookkeeping-receipt' => $this->bookkeeping($bookkeeping, $user, $action),
                'results' => $this->results(new PollResults($db), $user),
                'print' => $this->printTemplate($drafts, $life, $user, new AccountBranding($db), $accountSettings['url']),
                'invoices' => $this->invoices(new SandboxInvoices($db), $user),
                'receipt' => $this->receipt(new SandboxInvoices($db), $user),
                'pay' => $this->pay($drafts, $billing, $user, $payments, $post, $accountSettings['url']),
                'paypal-order' => $this->paypalOrder($drafts, $billing, $user, $payments, $post),
                'paypal-capture' => $this->paypalCapture($drafts, $user, $payments, $post),
                'paypal-plan' => $this->paypalPlan($drafts, $billing, $life, $user, $subscriptions, $post),
                'paypal-subscribe' => $this->paypalSubscribe($drafts, $user, $subscriptions, $post),
                'subscription-cancel' => $this->cancelSubscription($drafts, $user, $subscriptions, $post),
                default => $this->polls($drafts, $user, $payments !== null, $payments === null ? '' : $paypal['amount']),
            };
        } catch (PDOException $error) {
            error_log('Kompass account database error: ' . $error->getCode());
            $this->getResponse()->setStatusCode(503);
            return $this->page('unavailable');
        }
    }

    public function participateAction(): ViewModel|Response
    {
        $headers = $this->getResponse()->getHeaders();
        $headers->addHeaderLine('Cache-Control', 'no-store')->addHeaderLine('Referrer-Policy', 'no-referrer')
            ->addHeaderLine('X-Robots-Tag', 'noindex, nofollow');
        $this->layout()->setTemplate('layout/participation');
        $this->layout()->branding = ['primary' => null, 'accent' => null, 'logo' => null, 'name' => null];
        $token = $this->params()->fromQuery('befragung');
        $data = ['state' => 'missing'];
        if (!is_string($token) || !preg_match('/\A[0-9a-f]{32}\z/', $token)) {
            $this->getResponse()->setStatusCode(404);
            return (new ViewModel($data))->setTemplate('wizard/participation');
        }

        $settings = require dirname(__DIR__) . '/config/account.php';
        try {
            $db = $this->database($settings);
            $life = new PollLifecycle($db);
            $identity = $life->identify($token);
            if ($identity === null) {
                $this->getResponse()->setStatusCode(404);
                return (new ViewModel($data))->setTemplate('wizard/participation');
            }

            AccountSession::start();
            // Tablet-Modus: ein Gerät für viele Teilnehmer. Nach dem Absenden startet die Befragung neu,
            // Antworten werden immer gespeichert (auch wenn der Inhaber auf dem Tablet angemeldet ist).
            $tablet = $this->params()->fromQuery('geraet') === 'tablet';
            $base = ['befragung' => $token] + ($tablet ? ['geraet' => 'tablet'] : []);
            if ($tablet && $this->params()->fromQuery('neu') === '1') {
                unset($_SESSION['kompass_take'][$token]);
                return $this->redirect()->toRoute('wizard', [], ['query' => $base])->setStatusCode(303);
            }
            $pollId = (int) $identity['id'];
            $owner = (int) $identity['owner_id'];
            $this->layout()->branding = (new AccountBranding($db))->find($owner);
            $period = $life->latest($pollId);
            $state = PollLifecycle::status($period);
            if ($state !== 'active') {
                // Abo: Ist eine neue Abbuchung noch nicht verbucht (z. B. Webhook fehlt), jetzt abgleichen.
                try {
                    $subscriptions = self::subscriptions($db, require dirname(__DIR__) . '/config/paypal.php');
                    if ($subscriptions?->current($pollId) !== null) {
                        $subscriptions->refresh($pollId, 2);
                        $period = $life->latest($pollId);
                        $state = PollLifecycle::status($period);
                    }
                } catch (Throwable $error) {
                    error_log('Kompass subscription refresh: ' . $error->getMessage());
                }
            }
            // Testansicht: Ist der Inhaber angemeldet, kann er die Befragung jederzeit durchklicken.
            // Seine Antworten werden nicht gespeichert und beeinflussen die Ergebnisse nicht.
            $preview = !$tablet && AccountSession::userId() === $owner;
            $data = [
                'tablet' => $tablet,
                'state' => $state,
                'manage' => $preview ? $pollId : null,
                'preview' => $preview,
                'token' => $token,
            ];
            if ($state !== 'active' && !$preview) {
                return (new ViewModel($data))->setTemplate('wizard/participation');
            }
            $poll = (new PollDrafts($db))->find($owner, $pollId);
            if ($poll === null) {
                $this->getResponse()->setStatusCode(404);
                return (new ViewModel(['state' => 'missing']))->setTemplate('wizard/participation');
            }

            $csrf = AccountSession::csrf();
            $thanks = ($_SESSION['kompass_response_saved'] ?? null) === $token;
            if ($thanks) {
                unset($_SESSION['kompass_response_saved']);
                return (new ViewModel($data + ['poll' => $poll, 'thanks' => true]))->setTemplate('wizard/participation');
            }

            // Eine Frage pro Seite. Die freiwillige Freitextfrage ist immer die letzte Seite.
            $pages = array_map(static fn (array $question): array => ['type' => 'question', 'question' => $question], $poll['questions']);
            if (isset($poll['comment_id'])) {
                $pages[] = ['type' => 'comment', 'id' => (int) $poll['comment_id'], 'text' => $poll['comment']];
            }
            $total = count($pages);
            if ($total === 0) {
                return (new ViewModel(['state' => 'unavailable']))->setTemplate('wizard/participation');
            }

            // Fortschritt dieser Teilnahme in der Sitzung: zufälliger Schlüssel und bisherige Antworten
            $take = $_SESSION['kompass_take'][$token] ?? null;
            if (!is_array($take) || (int) ($take['version'] ?? 0) !== (int) $poll['id']) {
                $take = ['key' => bin2hex(random_bytes(32)), 'version' => (int) $poll['id'], 'answers' => []];
            }
            $answered = 0;
            foreach ($pages as $page) {
                if ($page['type'] !== 'question' || !isset($take['answers'][$page['question']['id']])) {
                    break;
                }
                ++$answered;
            }
            $requested = filter_var($this->params()->fromQuery('frage'), FILTER_VALIDATE_INT);
            $number = $requested === false || $requested === null ? $answered + 1 : $requested;
            $number = max(1, min($number, $answered + 1, $total));
            $page = $pages[$number - 1];

            $post = $this->getRequest()->isPost() ? $this->getRequest()->getPost()->toArray() : null;
            $nonce = bin2hex(random_bytes(16));
            $error = '';
            if ($post !== null) {
                $sentNonce = $post['nonce'] ?? null;
                $signature = $post['signature'] ?? null;
                if (!is_string($sentNonce) || !preg_match('/\A[0-9a-f]{32}\z/', $sentNonce)
                    || !is_string($signature) || !hash_equals(hash_hmac('sha256', $token . $sentNonce, $csrf), $signature)) {
                    $error = 'Die Seite ist abgelaufen. Bitte wählen Sie Ihre Antwort noch einmal.';
                } elseif ($page['type'] === 'question') {
                    $option = filter_var($post['answer'] ?? null, FILTER_VALIDATE_INT);
                    if (!in_array($option, $page['question']['option_ids'], true)) {
                        $error = 'Bitte wählen Sie eine Antwort.';
                    } else {
                        $take['answers'][$page['question']['id']] = $option;
                    }
                } else {
                    $comment = $post['comment'] ?? '';
                    if (!is_string($comment) || !mb_check_encoding($comment, 'UTF-8') || mb_strlen($comment, 'UTF-8') > 4000) {
                        $error = 'Bitte verwenden Sie höchstens 4.000 Zeichen.';
                    } elseif (trim($comment) !== '') {
                        $take['answers'][$page['id']] = trim($comment);
                    } else {
                        unset($take['answers'][$page['id']]);
                    }
                }

                if ($error === '') {
                    $complete = $number === $total;
                    $current = $life->current($pollId);
                    if (!$preview && ($current === null || !$life->saveProgress((int) $current['id'], (int) $poll['id'], $take['key'], $take['answers'], $complete))) {
                        unset($_SESSION['kompass_take'][$token]);
                        return (new ViewModel(['state' => 'closed']))->setTemplate('wizard/participation');
                    }
                    if ($complete) {
                        unset($_SESSION['kompass_take'][$token]);
                        $_SESSION['kompass_response_saved'] = $token;
                        return $this->redirect()->toRoute('wizard', [], ['query' => $base])->setStatusCode(303);
                    }
                    $_SESSION['kompass_take'][$token] = $take;
                    return $this->redirect()->toRoute('wizard', [], ['query' => $base + ['frage' => $number + 1]])->setStatusCode(303);
                }
                $this->getResponse()->setStatusCode(422);
            }
            $_SESSION['kompass_take'][$token] = $take;

            $questionCount = count($poll['questions']);
            return (new ViewModel($data + [
                'poll' => $poll, 'thanks' => false, 'error' => $error,
                'page' => $page, 'number' => $number, 'total' => $total,
                'selected' => $page['type'] === 'question' ? ($take['answers'][$page['question']['id']] ?? null) : ($take['answers'][$page['id']] ?? ''),
                'minutes' => max(1, (int) ceil(($questionCount * 10 + (isset($poll['comment_id']) ? 30 : 0)) / 60)),
                'nonce' => $nonce, 'signature' => hash_hmac('sha256', $token . $nonce, $csrf),
            ]))->setTemplate('wizard/participation');
        } catch (PDOException $error) {
            error_log('Kompass participation database error: ' . $error->getCode());
            $this->getResponse()->setStatusCode(503);
            return (new ViewModel(['state' => 'unavailable']))->setTemplate('wizard/participation');
        }
    }

    private function answers(array $poll, array $post): array
    {
        $values = [];
        $answers = [];
        $error = '';
        foreach ($poll['questions'] as $question) {
            $id = $question['id'];
            $option = filter_var($post['answer-' . $id] ?? null, FILTER_VALIDATE_INT);
            if (!in_array($option, $question['option_ids'], true)) {
                $error = 'Bitte beantworten Sie alle Pflichtfragen.';
                continue;
            }
            $values[$id] = $answers[$id] = $option;
        }
        if (isset($poll['comment_id'])) {
            $comment = $post['comment'] ?? '';
            if (!is_string($comment) || !mb_check_encoding($comment, 'UTF-8') || mb_strlen($comment, 'UTF-8') > 4000) {
                $error = 'Bitte verwenden Sie für den Kommentar höchstens 4.000 Zeichen.';
            } else {
                $values['comment'] = $comment;
                if (trim($comment) !== '') {
                    $answers[$poll['comment_id']] = trim($comment);
                }
            }
        }
        return [$values, $answers, $error];
    }

    private function database(array $settings): PDO
    {
        return new PDO($settings['dsn'], $settings['user'], $settings['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function save(PollDrafts $drafts, ?int $user, ?array $post): ViewModel|Response
    {
        $key = $post['draft'] ?? null;
        if (!is_string($key) || !isset($_SESSION['kompass_previews'][$key])) {
            $this->getResponse()->setStatusCode(400);
            return $this->page('invalid');
        }
        $_SESSION['kompass_pending'] = $key;
        return $user === null ? $this->go('login') : $this->finishDraft($drafts, $user);
    }

    private function login(AccountAccess $access, ?int $user, ?array $post): ViewModel|Response
    {
        if ($user !== null) {
            return $this->go('list');
        }
        if ($post === null) {
            $sent = $_SESSION['kompass_mail_sent'] ?? false;
            unset($_SESSION['kompass_mail_sent']);
            return $this->page('login', ['sent' => $sent]);
        }

        $email = is_string($post['email'] ?? null) ? strtolower(trim($post['email'])) : '';
        if (strlen($email) > 254 || strpbrk($email, "\r\n") !== false || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->page('login', ['error' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.']);
        }
        $browser = $_SESSION['kompass_browser'] ??= bin2hex(random_bytes(32));
        if (!$access->request($email, $browser, $_SERVER['REMOTE_ADDR'] ?? 'unknown', $_SESSION['kompass_pending'] ?? null)) {
            return $this->page('login', ['error' => 'Der Link konnte gerade nicht gesendet werden. Bitte versuchen Sie es in einigen Minuten erneut.']);
        }
        $_SESSION['kompass_mail_sent'] = $email;
        return $this->go('login');
    }

    private function verify(AccountAccess $access, PollDrafts $drafts, ?array $post): ViewModel|Response
    {
        $token = $post === null ? $this->params()->fromQuery('token') : ($post['token'] ?? null);
        if (!is_string($token) || !preg_match('/\A[0-9a-f]{64}\z/', $token) || !isset($_SESSION['kompass_browser'])) {
            return $this->page('expired');
        }
        if ($post === null) {
            return $this->page('verify', ['token' => $token]);
        }

        $verified = $access->verify($token, $_SESSION['kompass_browser']);
        if ($verified === null) {
            return $this->page('expired');
        }
        session_regenerate_id(true);
        $user = $verified['user'];
        $_SESSION['kompass_user'] = $user;
        $_SESSION['kompass_pending'] = $verified['draft'];
        unset($_SESSION['kompass_csrf'], $_SESSION['kompass_browser'], $_SESSION['kompass_billing_saved']);
        return $this->finishDraft($drafts, $user);
    }

    private function finishDraft(PollDrafts $drafts, int $user): Response
    {
        $key = $_SESSION['kompass_pending'] ?? '';
        if (!isset($_SESSION['kompass_previews'][$key])) {
            unset($_SESSION['kompass_pending']);
            return $this->go('list');
        }
        $snapshot = $_SESSION['kompass_previews'][$key];
        $replace = $snapshot['replace'] ?? null;
        unset($snapshot['replace'], $_SESSION['kompass_pending']);
        if (is_int($replace) && $drafts->replace($user, $replace, $snapshot)) {
            unset($_SESSION['kompass_edit'], $_SESSION['kompass_previews'][$key]);
            $_SESSION['kompass_poll_updated'] = $replace;
            return $this->go('draft', ['id' => $replace]);
        }
        unset($_SESSION['kompass_edit']);
        $id = $drafts->save($user, $key, $snapshot);
        $_SESSION['kompass_saved_poll'] = $id;
        return $this->go('draft', ['id' => $id]);
    }

    /** „Fragebogen anpassen“: Wizard mit den gespeicherten Antworten öffnen, beim Speichern wird der Entwurf ersetzt. */
    private function editDraft(PollDrafts $drafts, ?int $user): Response|ViewModel
    {
        if ($user === null) {
            return $this->go('login');
        }
        $id = filter_var($this->params()->fromQuery('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $poll = $id === false ? null : $drafts->find($user, $id);
        if ($poll === null || !$poll['deletable']) {
            return $id === false || $poll === null ? $this->go('list') : $this->go('draft', ['id' => $id]);
        }
        $_SESSION['kompass_edit'] = ['id' => $id, 'title' => $poll['title']];
        $answers = json_decode((string) $poll['wizard_answers'], true);
        $query = is_array($answers) ? $answers : [];
        // Ältere Entwürfe: Anliegen als Liste, keine Branche – der Wizard fragt dann nach.
        if (is_array($query['goal'] ?? null) && count($query['goal']) === 1) {
            $query['goal'] = reset($query['goal']);
        }
        return $this->redirect()->toRoute('wizard', [], ['query' => $query])->setStatusCode(303);
    }

    private function cancelEdit(): Response
    {
        $id = $_SESSION['kompass_edit']['id'] ?? null;
        unset($_SESSION['kompass_edit']);
        return is_int($id) ? $this->go('draft', ['id' => $id]) : $this->go('list');
    }

    private function branding(AccountBranding $branding, ?int $user, ?array $post): ViewModel|Response
    {
        if ($user === null) {
            return $this->go('login');
        }
        $errors = [];
        if ($post !== null) {
            $files = $this->getRequest()->getFiles()->toArray();
            $errors = $branding->save($user, $post, is_array($files['logo'] ?? null) ? $files['logo'] : null);
            if ($errors === []) {
                $_SESSION['kompass_branding_saved'] = true;
                return $this->go('branding');
            }
            $this->getResponse()->setStatusCode(422);
        }
        $saved = $_SESSION['kompass_branding_saved'] ?? false;
        unset($_SESSION['kompass_branding_saved']);
        $current = $branding->find($user);
        return $this->page('branding', [
            'branding' => $current,
            'brandingErrors' => $errors,
            'brandingSaved' => $saved,
            'brandingPost' => $post ?? [],
        ])->setTemplate('wizard/branding');
    }

    private function billing(AccountBilling $billing, ?int $user, ?array $post): ViewModel|Response
    {
        if ($user === null) {
            return $this->go('login');
        }
        $stored = $billing->find($user);
        $setup = !isset($stored['account_id']);
        $next = $_SESSION['kompass_billing_next'] ?? null;
        $next = is_int($next) ? $next : null;
        $values = $setup ? ['country' => 'Deutschland'] : $stored;
        $errors = [];
        if ($post !== null) {
            [$values, $errors] = AccountBilling::validate($post);
            if ($errors === []) {
                $billing->save($user, $values);
                if ($next !== null) {
                    unset($_SESSION['kompass_billing_next']);
                    $_SESSION['kompass_continue_payment'] = $next;
                    return $this->go('draft', ['id' => $next]);
                }
                $_SESSION['kompass_billing_saved'] = true;
                return $this->go('billing');
            }
            $this->getResponse()->setStatusCode(422);
        }

        $saved = $_SESSION['kompass_billing_saved'] ?? false;
        unset($_SESSION['kompass_billing_saved']);
        return $this->page('billing', [
            'billingSetup' => $setup,
            'billingForPayment' => $next,
            'billingSaved' => $saved,
            'billingEmail' => $stored['email'] ?? '',
            'billingFields' => AccountBilling::FIELDS,
            'billingValues' => $values,
            'billingErrors' => $errors,
        ]);
    }

    private function polls(PollDrafts $drafts, ?int $user, bool $paymentEnabled, string $price): ViewModel|Response
    {
        if ($user === null) {
            return $this->go('login');
        }
        if (isset($_SESSION['kompass_pending'])) {
            return $this->finishDraft($drafts, $user);
        }
        $polls = $drafts->listFor($user);
        $saved = $_SESSION['kompass_saved_poll'] ?? null;
        $deleted = ($_SESSION['kompass_deleted_poll'] ?? null) === $user;
        unset($_SESSION['kompass_saved_poll'], $_SESSION['kompass_deleted_poll']);
        return $this->page('list', ['polls' => $polls, 'saved' => $saved, 'deleted' => $deleted, 'paymentEnabled' => $paymentEnabled, 'price' => $price]);
    }

    private function draft(PollDrafts $drafts, PollLifecycle $life, AccountBilling $billing, ?int $user, bool $paymentEnabled, string $price, string $site, string $paypalClientId = '', ?AccountBranding $brandingStore = null, ?PollSubscriptions $subscriptions = null): ViewModel|Response
    {
        if ($user === null) {
            return $this->go('login');
        }
        $id = filter_var($this->params()->fromQuery('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $poll = $id === false ? null : $drafts->find($user, $id);
        if ($poll === null) {
            $this->getResponse()->setStatusCode(404);
            return $this->page('missing');
        }
        // Abo vor der Statusberechnung mit PayPal abgleichen (neue Abbuchungen werden zu Zeiträumen)
        try {
            $subscription = $subscriptions?->refresh($id) ?? $subscriptions?->latest($id);
        } catch (PDOException $error) {
            throw $error;
        } catch (Throwable $error) {
            error_log('Kompass subscription refresh: ' . $error->getMessage());
            $subscription = null;
        }
        $payment = $life->latest($id);
        $state = PollLifecycle::status($payment);
        $notice = $_SESSION['kompass_payment_notice'] ?? [];
        if (($notice['poll'] ?? null) === $id) {
            unset($_SESSION['kompass_payment_notice']);
        } else {
            $notice = [];
        }
        $returnedOrder = $this->params()->fromQuery('token');
        $justSaved = ($_SESSION['kompass_saved_poll'] ?? null) === $id;
        $justUpdated = ($_SESSION['kompass_poll_updated'] ?? null) === $id;
        if ($justUpdated) {
            unset($_SESSION['kompass_poll_updated']);
        }
        $continuePayment = ($_SESSION['kompass_continue_payment'] ?? null) === $id;
        if ($justSaved) {
            unset($_SESSION['kompass_saved_poll']);
        }
        if ($continuePayment) {
            unset($_SESSION['kompass_continue_payment']);
        }
        $ownBranding = $brandingStore?->find($user) ?? ['primary' => null, 'accent' => null, 'logo' => null, 'name' => null];
        $participationUrl = rtrim($site, '/') . '/?' . http_build_query(['befragung' => $life->token($id)]);
        $subscribed = $subscription !== null && in_array($subscription['status'], ['APPROVAL_PENDING', 'APPROVED', 'ACTIVE', 'SUSPENDED'], true);
        $shareEnds = $state === 'active' && !$subscribed && isset($payment['running_until'])
            ? (new \DateTimeImmutable($payment['running_until'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))
            : null;
        $shareKit = ShareKit::build($poll, $ownBranding['name'] ?? null, $shareEnds, $participationUrl);
        $subscriptionNotice = $_SESSION['kompass_subscription_notice'] ?? null;
        unset($_SESSION['kompass_subscription_notice']);
        return $this->page('draft', [
            'subscription' => $subscription,
            'subscriptionsEnabled' => $subscriptions !== null,
            'subscriptionAmount' => $subscriptions?->amount() ?? '',
            'subscriptionNotice' => $subscriptionNotice,
            'branding' => $ownBranding,
            'brandingEmpty' => $ownBranding['primary'] === null && $ownBranding['accent'] === null && $ownBranding['logo'] === null,
            'responseCount' => $life->responseCount($id),
            'paypalClientId' => $paypalClientId,
            'hasBilling' => $billing->hasDetails($user),
            'justSaved' => $justSaved,
            'justUpdated' => $justUpdated,
            'continuePayment' => $continuePayment,
            'poll' => $poll,
            'pollId' => $id,
            'paymentEnabled' => $paymentEnabled,
            'state' => $state,
            'participationUrl' => $participationUrl,
            'shareKit' => $shareKit,
            'payment' => $payment,
            'price' => isset($payment['paid_at']) ? $price : ($payment['amount'] ?? $price),
            'paymentNotice' => $notice['status'] ?? '',
            'captureStatus' => $notice['captureStatus'] ?? '',
            'captureReason' => $notice['captureReason'] ?? '',
            'paymentReturned' => !isset($payment['paid_at']) && isset($payment['order_id']) && $returnedOrder === $payment['order_id'],
            'paymentCancelled' => $this->params()->fromQuery('cancel') === '1',
        ]);
    }

    private function deleteDraft(PollDrafts $drafts, ?int $user, ?array $post): ViewModel|Response
    {
        if ($user === null) {
            return $this->go('login');
        }
        $id = filter_var($this->params()->fromQuery('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $poll = $id === false ? null : $drafts->find($user, $id);
        if ($poll === null) {
            $this->getResponse()->setStatusCode(404);
            return $this->page('missing');
        }
        if ($post !== null && $drafts->delete($user, $id)) {
            $_SESSION['kompass_deleted_poll'] = $user;
            return $this->go('list');
        }
        $blocked = $post !== null || !$poll['deletable'];
        if ($blocked) {
            $this->getResponse()->setStatusCode(409);
        }
        return $this->page('delete', ['pollId' => $id, 'title' => $poll['title'], 'blocked' => $blocked]);
    }

    /** Druckvorlagen (Aushang, Tischaufsteller, Kärtchen, Tablet-Schild) als eigene Druckseite im Browser */
    private function printTemplate(PollDrafts $drafts, PollLifecycle $life, ?int $user, AccountBranding $brandingStore, string $site): ViewModel|Response
    {
        if ($user === null) {
            return $this->go('login');
        }
        $id = filter_var($this->params()->fromQuery('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $poll = $id === false ? null : $drafts->find($user, $id);
        if ($poll === null) {
            $this->getResponse()->setStatusCode(404);
            return $this->page('missing');
        }
        $template = $this->params()->fromQuery('vorlage');
        $template = in_array($template, ['aushang', 'aufsteller', 'kaertchen', 'tablet'], true) ? $template : 'aushang';
        $branding = $brandingStore->find($user);
        $url = rtrim($site, '/') . '/?' . http_build_query(['befragung' => $life->token($id)]);
        $kit = ShareKit::build($poll, $branding['name'], null, $url);
        $duration = $kit['durationShort'];
        $primary = $branding['primary'];
        $this->getResponse()->getHeaders()->addHeaderLine('Cache-Control', 'no-store')->addHeaderLine('X-Robots-Tag', 'noindex, nofollow');
        return (new ViewModel([
            'pollId' => $id,
            'pollTitle' => $poll['title'],
            'template' => $template,
            'url' => $url,
            'branding' => $branding,
            'headlineColor' => $primary !== null && AccountBranding::contrast($primary, '#ffffff') >= 3 ? $primary : ($primary === null ? '#346c09' : '#192921'),
            'bandColor' => $primary ?? '#346c09',
            'headline' => $kit['headline'],
            'text' => match ($template) {
                'kaertchen' => 'Ihre Meinung in ' . $duration . ', einfach QR-Code scannen.',
                'tablet' => 'Sagen Sie es uns direkt hier am Tablet. Dauert nur ' . $duration . '.',
                default => 'Sagen Sie es uns in ' . $duration . '. Anonym und ohne Anmeldung.',
            },
        ]))->setTemplate('wizard/print')->setTerminal(true);
    }

    private function results(PollResults $results, ?int $user): ViewModel|Response
    {
        if ($user === null) {
            return $this->go('login');
        }
        $polls = $results->pollsFor($user);
        $requested = $this->params()->fromQuery('id');
        $id = $requested === null
            ? ($polls[0]['id'] ?? null)
            : filter_var($requested, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $started = in_array((int) $id, array_map('intval', array_column($polls, 'id')), true);
        $report = $id === null || $id === false || !$started ? null : $results->report($user, (int) $id);
        if ($requested !== null && !$started) {
            // Nicht gestartete oder fremde Befragung: zurück auf die Ergebnisübersicht.
            return $polls === [] ? $this->page('results', ['resultPolls' => [], 'report' => null])->setTemplate('wizard/results')
                : $this->go('results');
        }
        return $this->page('results', ['resultPolls' => $polls, 'report' => $report])
            ->setTemplate('wizard/results');
    }

    private function invoices(SandboxInvoices $invoices, ?int $user): ViewModel|Response
    {
        if ($user === null) {
            return $this->go('login');
        }
        return $this->page('invoices', ['invoices' => $invoices->listFor($user)]);
    }

    private function issuer(InvoiceIssuer $issuer, SandboxInvoices $invoices, ?array $post): ViewModel|Response
    {
        $values = $issuer->find();
        $errors = [];
        $saved = !empty($_SESSION['kompass_issuer_saved']);
        unset($_SESSION['kompass_issuer_saved']);
        if ($post !== null) {
            $validation = InvoiceIssuer::validate($post);
            $values = $validation['values'] + $values;
            $errors = $validation['errors'];
            if ($errors === []) {
                $issuer->save($validation['values']);
                $invoices->recoverFor();
                $_SESSION['kompass_issuer_saved'] = true;
                return $this->go('issuer');
            }
            $this->getResponse()->setStatusCode(422);
        }
        return $this->page('issuer', ['issuer' => $values, 'issuerErrors' => $errors, 'issuerSaved' => $saved])
            ->setTemplate('wizard/issuer');
    }

    private function bookkeeping(Bookkeeping $bookkeeping, int $user, string $action): ViewModel|Response
    {
        if ($action === 'bookkeeping-export') {
            $response = $this->getResponse();
            $response->getHeaders()
                ->addHeaderLine('Content-Type', 'text/csv; charset=UTF-8')
                ->addHeaderLine('Content-Disposition', 'attachment; filename="kompass-buchhaltung.csv"')
                ->addHeaderLine('X-Content-Type-Options', 'nosniff');
            $response->setContent($bookkeeping->csv($user));
            return $response;
        }
        if ($action === 'bookkeeping-receipt') {
            $id = filter_var($this->params()->fromQuery('period'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            return $this->downloadInvoice($id === false ? null : $bookkeeping->find($user, $id));
        }
        return $this->page('bookkeeping', ['entries' => $bookkeeping->entries($user)])
            ->setTemplate('wizard/bookkeeping');
    }

    private function receipt(SandboxInvoices $invoices, ?int $user): ViewModel|Response
    {
        if ($user === null) {
            return $this->go('login');
        }
        $id = filter_var($this->params()->fromQuery('period'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $this->downloadInvoice($id === false ? null : $invoices->find($user, $id));
    }

    private function downloadInvoice(?array $invoice): ViewModel|Response
    {
        if ($invoice === null) {
            $this->getResponse()->setStatusCode(404);
            return $this->page('missing');
        }

        $xml = $this->params()->fromQuery('format') === 'xml';
        $content = $xml ? $invoice['xml'] : ZugferdInvoice::render($invoice['xml']);
        $extension = $xml ? 'xml' : 'pdf';
        $response = $this->getResponse();
        $response->getHeaders()
            ->addHeaderLine('X-Content-Type-Options', 'nosniff')
            ->addHeaderLine('Content-Type', $xml ? 'application/xml; charset=UTF-8' : 'application/pdf')
            ->addHeaderLine('Content-Disposition', 'attachment; filename="' . $invoice['invoice_number'] . '.' . $extension . '"');
        $response->setContent($content);
        return $response;
    }

    /** Abo-Funktionen sind verfügbar, wenn PayPal eingerichtet ist und ein gültiger Monatspreis hinterlegt ist. */
    private static function subscriptions(PDO $db, array $paypal): ?PollSubscriptions
    {
        $amount = (string) ($paypal['subscription_amount'] ?? '');
        if (!PayPalSandbox::configured($paypal) || !preg_match('/\A[1-9][0-9]{0,5}\.[0-9]{2}\z/', $amount)) {
            return null;
        }
        return new PollSubscriptions($db, new PayPalSandbox($paypal), $amount);
    }

    /** PayPal-Abo-Buttons: Plan und Startzeitpunkt liefern. Antwortet mit JSON. */
    private function paypalPlan(PollDrafts $drafts, AccountBilling $billing, PollLifecycle $life, ?int $user, ?PollSubscriptions $subscriptions, ?array $post): Response
    {
        $id = $this->subscriptionRequest($drafts, $user, $subscriptions, $post);
        if ($id === null) {
            return $this->json(['error' => 'invalid'], 400);
        }
        if (!$billing->hasDetails($user)) {
            $_SESSION['kompass_billing_next'] = $id;
            return $this->json(['redirect' => $this->accountLink('billing')]);
        }
        if ($subscriptions->current($id) !== null) {
            return $this->json(['redirect' => $this->accountLink('draft', ['id' => $id])]);
        }
        try {
            $plan = $subscriptions->planId();
        } catch (RuntimeException $error) {
            error_log('Kompass PayPal plan: ' . $error->getMessage());
            return $this->json(['error' => 'unavailable'], 503);
        }
        // Läuft die Befragung noch, beginnt das Abo nahtlos am Ende des bezahlten Zeitraums.
        $latest = $life->latest($id);
        $until = $latest['running_until'] ?? null;
        $start = null;
        if ($until !== null && strtotime($until . ' UTC') > time() + 600) {
            $start = gmdate('Y-m-d\TH:i:s\Z', strtotime($until . ' UTC'));
        }
        return $this->json(['planId' => $plan, 'customId' => (string) $id, 'startTime' => $start]);
    }

    /** Nach der Freigabe im PayPal-Fenster */
    private function paypalSubscribe(PollDrafts $drafts, ?int $user, ?PollSubscriptions $subscriptions, ?array $post): Response
    {
        $id = $this->subscriptionRequest($drafts, $user, $subscriptions, $post);
        $paypalId = $post['subscription'] ?? null;
        if ($id === null || !is_string($paypalId)) {
            return $this->json(['error' => 'invalid'], 400);
        }
        try {
            $ok = $subscriptions->register($id, $paypalId);
        } catch (PDOException $error) {
            throw $error;
        } catch (RuntimeException $error) {
            error_log('Kompass PayPal subscribe: ' . $error->getMessage());
            $ok = false;
        }
        $_SESSION['kompass_subscription_notice'] = $ok ? 'started' : 'failed';
        return $this->json(['redirect' => $this->accountLink('draft', ['id' => $id])]);
    }

    private function cancelSubscription(PollDrafts $drafts, ?int $user, ?PollSubscriptions $subscriptions, ?array $post): Response|ViewModel
    {
        $id = $this->subscriptionRequest($drafts, $user, $subscriptions, $post);
        if ($id === null) {
            return $user === null ? $this->go('login') : $this->go('list');
        }
        try {
            $ok = $subscriptions->cancel($id);
        } catch (PDOException $error) {
            throw $error;
        } catch (RuntimeException $error) {
            error_log('Kompass PayPal cancel: ' . $error->getMessage());
            $ok = false;
        }
        $_SESSION['kompass_subscription_notice'] = $ok ? 'cancelled' : 'cancel_failed';
        return $this->go('draft', ['id' => $id]);
    }

    private function subscriptionRequest(PollDrafts $drafts, ?int $user, ?PollSubscriptions $subscriptions, ?array $post): ?int
    {
        if ($user === null || $post === null || $subscriptions === null) {
            return null;
        }
        $id = filter_var($post['poll'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false || $drafts->find($user, $id) === null ? null : $id;
    }

    /**
     * Webhook von PayPal. Der Inhalt wird nicht vertraut: Er liefert nur die Abo-ID,
     * der Stand wird anschließend direkt bei PayPal abgefragt.
     */
    public function webhookAction(): Response
    {
        $response = $this->getResponse();
        $body = json_decode((string) $this->getRequest()->getContent(), true);
        $resource = is_array($body['resource'] ?? null) ? $body['resource'] : [];
        $type = (string) ($body['event_type'] ?? '');
        $id = str_starts_with($type, 'BILLING.SUBSCRIPTION.') ? ($resource['id'] ?? null) : ($resource['billing_agreement_id'] ?? null);
        if (!$this->getRequest()->isPost() || !is_string($id) || !preg_match('/\A[A-Z0-9-]{3,64}\z/', $id)) {
            $response->setStatusCode(202);
            return $response;
        }
        try {
            $db = $this->database(require dirname(__DIR__) . '/config/account.php');
            $subscriptions = self::subscriptions($db, require dirname(__DIR__) . '/config/paypal.php');
            $subscriptions?->sync($id);
            $response->setStatusCode(200);
        } catch (Throwable $error) {
            error_log('Kompass PayPal webhook: ' . $error->getMessage());
            $response->setStatusCode(500);
        }
        return $response;
    }

    private function startPoll(PollLifecycle $life, ?int $user, ?array $post): ViewModel|Response
    {
        if ($user === null) {
            return $this->go('login');
        }
        if ($post === null) {
            $this->getResponse()->setStatusCode(405);
            return $this->page('invalid');
        }
        $poll = filter_var($post['poll'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $period = filter_var($post['period'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($poll === false || $period === false) {
            $this->getResponse()->setStatusCode(400);
            return $this->page('invalid');
        }
        $life->start($user, $poll, $period);
        return $this->go('draft', ['id' => $poll]);
    }

    /**
     * PayPal-Buttons: Bestellung anlegen. Antwortet mit JSON.
     */
    private function paypalOrder(PollDrafts $drafts, AccountBilling $billing, ?int $user, ?PollPayments $payments, ?array $post): Response
    {
        $id = $this->buttonRequest($drafts, $user, $payments, $post);
        if ($id === null) {
            return $this->json(['error' => 'invalid'], 400);
        }
        $period = filter_var($post['period'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($period === false) {
            return $this->json(['error' => 'invalid'], 400);
        }
        if (!$billing->hasDetails($user)) {
            $_SESSION['kompass_billing_next'] = $id;
            return $this->json(['redirect' => $this->accountLink('billing')]);
        }
        try {
            $result = $payments->buttonOrder($id, $period);
        } catch (PDOException $error) {
            throw $error;
        } catch (RuntimeException $error) {
            error_log('Kompass PayPal sandbox: ' . $error->getMessage());
            $result = ['status' => 'unavailable'];
        }
        if (isset($result['orderId'])) {
            return $this->json(['orderId' => $result['orderId'], 'period' => $result['period']]);
        }
        $_SESSION['kompass_payment_notice'] = ['poll' => $id] + $result;
        return $this->json(['redirect' => $this->accountLink('draft', ['id' => $id])]);
    }

    /**
     * PayPal-Buttons: freigegebene Bestellung abbuchen. Antwortet mit JSON.
     */
    private function paypalCapture(PollDrafts $drafts, ?int $user, ?PollPayments $payments, ?array $post): Response
    {
        $id = $this->buttonRequest($drafts, $user, $payments, $post);
        $period = filter_var($post['period'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $order = $post['order'] ?? null;
        if ($id === null || $period === false || !is_string($order) || !preg_match('/\A[A-Z0-9]{1,64}\z/', $order)) {
            return $this->json(['error' => 'invalid'], 400);
        }
        try {
            $result = $payments->advance($id, $period, '', $order);
        } catch (PDOException $error) {
            throw $error;
        } catch (RuntimeException $error) {
            error_log('Kompass PayPal sandbox: ' . $error->getMessage());
            $result = ['status' => 'unavailable'];
        }
        if (($result['status'] ?? '') !== 'paid') {
            $_SESSION['kompass_payment_notice'] = ['poll' => $id] + $result;
        }
        return $this->json(['redirect' => $this->accountLink('draft', ['id' => $id])]);
    }

    /** Gemeinsame Prüfung für beide PayPal-Button-Aufrufe. Gibt die Befragungs-ID zurück. */
    private function buttonRequest(PollDrafts $drafts, ?int $user, ?PollPayments $payments, ?array $post): ?int
    {
        if ($user === null || $post === null || $payments === null) {
            return null;
        }
        $id = filter_var($post['poll'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false || $drafts->find($user, $id) === null ? null : $id;
    }

    private function accountLink(string $page, array $query = []): string
    {
        return $this->url()->fromRoute('wizard', [], ['query' => ['account' => $page] + $query]);
    }

    private function json(array $data, int $status = 200): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode($status);
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json; charset=utf-8');
        $response->setContent(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $response;
    }

    private function pay(PollDrafts $drafts, AccountBilling $billing, ?int $user, ?PollPayments $payments, ?array $post, string $site): ViewModel|Response
    {
        if ($user === null) {
            return $this->go('login');
        }
        if ($post === null) {
            $this->getResponse()->setStatusCode(405);
            return $this->page('invalid');
        }

        $id = filter_var($post['poll'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $drafts->find($user, $id) === null) {
            $this->getResponse()->setStatusCode(404);
            return $this->page('missing');
        }
        if ($payments === null) {
            return $this->go('draft', ['id' => $id]);
        }

        $period = filter_var($post['period'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $returnedOrder = $post['returned_order'] ?? null;
        if ($period === false || ($returnedOrder !== null && (!is_string($returnedOrder) || !preg_match('/\A[A-Z0-9]{1,64}\z/', $returnedOrder)))) {
            $this->getResponse()->setStatusCode(400);
            return $this->page('invalid');
        }
        // Rechnungsdaten werden erst gebraucht, wenn eine neue Zahlung beginnt.
        if ($returnedOrder === null && !$billing->hasDetails($user)) {
            $_SESSION['kompass_billing_next'] = $id;
            return $this->go('billing');
        }
        $returnUrl = rtrim($site, '/') . '/?' . http_build_query(['account' => 'draft', 'id' => $id]);
        try {
            $result = $payments->advance($id, $period, $returnUrl, $returnedOrder);
        } catch (PDOException $error) {
            throw $error;
        } catch (RuntimeException $error) {
            error_log('Kompass PayPal sandbox: ' . $error->getMessage());
            $result = ['status' => 'unavailable'];
        }
        if (isset($result['redirect'])) {
            return $this->redirect()->toUrl($result['redirect'])->setStatusCode(303);
        }

        $_SESSION['kompass_payment_notice'] = ['poll' => $id] + $result;
        return $this->go('draft', ['id' => $id]);
    }

    private function logout(?array $post): ViewModel|Response
    {
        if ($post === null) {
            $this->getResponse()->setStatusCode(405);
            return $this->page('invalid');
        }
        unset($_SESSION['kompass_user'], $_SESSION['kompass_csrf'], $_SESSION['kompass_browser'],
            $_SESSION['kompass_pending'], $_SESSION['kompass_previews'], $_SESSION['kompass_saved_poll'], $_SESSION['kompass_payment_notice'],
            $_SESSION['kompass_billing_saved']);
        session_regenerate_id(true);
        return $this->go('login');
    }

    private function page(string $page, array $data = []): ViewModel
    {
        $this->layout()->accountWorkspace = in_array($page, ['list', 'draft', 'delete', 'missing', 'billing', 'invoices', 'bookkeeping', 'issuer', 'forbidden', 'results', 'branding'], true);
        $this->layout()->pageTitle = isset($data['poll']['title']) && in_array($page, ['draft', 'delete'], true) ? $data['poll']['title'] : match ($page) {
            'list' => 'Meine Befragungen',
            'billing' => 'Rechnungsdaten',
            'invoices' => 'Meine Rechnungen',
            'results' => 'Ergebnisse',
            'branding' => 'Erscheinungsbild',
            'login', 'verify', 'expired' => 'Anmelden',
            'bookkeeping' => 'Buchhaltung',
            'issuer' => 'Rechnungsaussteller',
            default => null,
        };
        if (in_array($page, ['login', 'verify', 'expired'], true)) {
            $this->layout()->progress = ['current' => 2];
        }
        $data += [
            'pendingDraft' => isset($_SESSION['kompass_pending']),
            'pendingTitle' => isset($_SESSION['kompass_pending'])
                ? ($_SESSION['kompass_previews'][$_SESSION['kompass_pending']]['title'] ?? null)
                : null,
            'bookkeepingAdmin' => (bool) $this->layout()->bookkeepingAdmin,
        ];
        return (new ViewModel($data + ['page' => $page, 'csrf' => session_status() === PHP_SESSION_ACTIVE ? AccountSession::csrf() : '', 'error' => '', 'sent' => false]))
            ->setTemplate('wizard/account');
    }

    private function go(string $page, array $query = []): Response
    {
        return $this->redirect()->toRoute('wizard', [], ['query' => ['account' => $page] + $query])->setStatusCode(303);
    }
}
