<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use PDO;
use PDOException;
use RuntimeException;

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
            if ($user !== null && !$adminAction && !in_array($action, ['save', 'login', 'verify', 'logout', 'billing'], true)
                && !$billing->hasDetails($user)) {
                return $this->go('billing');
            }
            $paypal = require dirname(__DIR__) . '/config/paypal.php';
            $payments = PayPalSandbox::configured($paypal)
                ? new PollPayments($db, new PayPalSandbox($paypal), $paypal['amount'])
                : null;

            return match ($action) {
                'save' => $this->save($drafts, $user, $post),
                'login' => $this->login(new AccountAccess($db, $accountSettings), $user, $post),
                'verify' => $this->verify(new AccountAccess($db, $accountSettings), $drafts, $post),
                'logout' => $this->logout($post),
                'billing' => $this->billing($billing, $user, $post),
                'draft' => $this->draft($drafts, $life, $user, $payments !== null, $payments === null ? '' : $paypal['amount'], $accountSettings['url']),
                'delete' => $this->deleteDraft($drafts, $user, $post),
                'start' => $this->startPoll($life, $user, $post),
                'issuer' => $this->issuer(new InvoiceIssuer($db), new SandboxInvoices($db), $post),
                'bookkeeping', 'bookkeeping-export', 'bookkeeping-receipt' => $this->bookkeeping($bookkeeping, $user, $action),
                'invoices' => $this->invoices(new SandboxInvoices($db), $user),
                'receipt' => $this->receipt(new SandboxInvoices($db), $user),
                'pay' => $this->pay($drafts, $user, $payments, $post, $accountSettings['url']),
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
            $pollId = (int) $identity['id'];
            $owner = (int) $identity['owner_id'];
            $period = $life->latest($pollId);
            $state = PollLifecycle::status($period);
            $data = [
                'state' => $state,
                'manage' => AccountSession::userId() === $owner ? $pollId : null,
                'token' => $token,
            ];
            if ($state !== 'active') {
                return (new ViewModel($data))->setTemplate('wizard/participation');
            }
            $poll = (new PollDrafts($db))->find($owner, $pollId);
            if ($poll === null) {
                $this->getResponse()->setStatusCode(404);
                return (new ViewModel(['state' => 'missing']))->setTemplate('wizard/participation');
            }

            $csrf = AccountSession::csrf();
            $post = $this->getRequest()->isPost() ? $this->getRequest()->getPost()->toArray() : null;
            $nonce = bin2hex(random_bytes(16));
            $values = [];
            $error = '';
            if ($post !== null) {
                $sentNonce = $post['nonce'] ?? null;
                $signature = $post['signature'] ?? null;
                if (!is_string($sentNonce) || !preg_match('/\A[0-9a-f]{32}\z/', $sentNonce)
                    || !is_string($signature) || !hash_equals(hash_hmac('sha256', $token . $sentNonce, $csrf), $signature)) {
                    $error = 'Das Formular ist abgelaufen. Bitte senden Sie Ihre Antworten erneut.';
                } else {
                    $nonce = $sentNonce;
                    [$values, $answers, $error] = $this->answers($poll, $post);
                    if ($error === '') {
                        if ($life->submit((int) $period['id'], (int) $poll['id'], $signature, $answers)) {
                            $_SESSION['kompass_response_saved'] = $token;
                            return $this->redirect()->toRoute('wizard', [], ['query' => ['befragung' => $token]])->setStatusCode(303);
                        }
                        return (new ViewModel(['state' => 'closed']))->setTemplate('wizard/participation');
                    }
                }
                $this->getResponse()->setStatusCode(422);
            }
            $thanks = ($_SESSION['kompass_response_saved'] ?? null) === $token;
            if ($thanks) {
                unset($_SESSION['kompass_response_saved']);
            }
            return (new ViewModel($data + [
                'poll' => $poll, 'thanks' => $thanks, 'error' => $error, 'values' => $values,
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
        $_SESSION['kompass_mail_sent'] = true;
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
        $id = $drafts->save($user, $key, $_SESSION['kompass_previews'][$key]);
        unset($_SESSION['kompass_pending']);
        $_SESSION['kompass_saved_poll'] = $id;
        return $this->go('list');
    }

    private function billing(AccountBilling $billing, ?int $user, ?array $post): ViewModel|Response
    {
        if ($user === null) {
            return $this->go('login');
        }
        $stored = $billing->find($user);
        $setup = !isset($stored['account_id']);
        $values = $setup ? ['country' => 'Deutschland'] : $stored;
        $errors = [];
        if ($post !== null) {
            [$values, $errors] = AccountBilling::validate($post);
            if ($errors === []) {
                $billing->save($user, $values);
                if ($setup) {
                    return $this->go('list');
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

    private function draft(PollDrafts $drafts, PollLifecycle $life, ?int $user, bool $paymentEnabled, string $price, string $site): ViewModel|Response
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
        $payment = $life->latest($id);
        $state = PollLifecycle::status($payment);
        $notice = $_SESSION['kompass_payment_notice'] ?? [];
        if (($notice['poll'] ?? null) === $id) {
            unset($_SESSION['kompass_payment_notice']);
        } else {
            $notice = [];
        }
        $returnedOrder = $this->params()->fromQuery('token');
        return $this->page('draft', [
            'poll' => $poll,
            'pollId' => $id,
            'paymentEnabled' => $paymentEnabled,
            'receipts' => $life->receipts($id),
            'state' => $state,
            'participationUrl' => rtrim($site, '/') . '/?' . http_build_query(['befragung' => $life->token($id)]),
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

    private function pay(PollDrafts $drafts, ?int $user, ?PollPayments $payments, ?array $post, string $site): ViewModel|Response
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
        $this->layout()->accountWorkspace = in_array($page, ['list', 'draft', 'delete', 'missing', 'billing', 'invoices', 'bookkeeping', 'issuer', 'forbidden'], true);
        $data += [
            'pendingDraft' => isset($_SESSION['kompass_pending']),
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
