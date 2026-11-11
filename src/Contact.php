<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

final class Contact
{
    private readonly array $settings;

    public function __construct()
    {
        session_start([
            'cookie_httponly' => true,
            'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
        ]);
        $this->settings = require dirname(__DIR__) . '/config/contact.php';
    }

    public function form(): array
    {
        $success = !empty($_SESSION['studio_contact_success']);
        unset($_SESSION['studio_contact_success']);
        return [
            'email' => '',
            'message' => '',
            'error' => '',
            'success' => $success,
            'token' => $_SESSION['studio_contact_token'] ??= bin2hex(random_bytes(32)),
            'available' => $this->available(),
        ];
    }

    public function submit(array $post): ?array
    {
        $email = $this->field($post, 'email', 254);
        $message = $this->field($post, 'message', 3000);
        $token = $_SESSION['studio_contact_token'] ?? '';
        $submitted = $this->field($post, 'token', 64);

        if ($token === '' || !hash_equals($token, $submitted) || ($post['website'] ?? '') !== '') {
            return ['error' => 'Bitte laden Sie das Formular neu und versuchen Sie es noch einmal.', 'email' => $email, 'message' => $message];
        }
        if (!$this->available()) {
            return ['error' => 'Das Kontaktformular ist derzeit nicht verfügbar.', 'email' => $email, 'message' => $message];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strpbrk($email, "\r\n") !== false || mb_strlen($message) < 10) {
            return ['error' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein und beschreiben Sie Ihr Anliegen mit mindestens 10 Zeichen.', 'email' => $email, 'message' => $message];
        }
        if (time() - ($_SESSION['studio_contact_sent_at'] ?? 0) < 60) {
            return ['error' => 'Bitte warten Sie eine Minute, bevor Sie eine weitere Nachricht senden.', 'email' => $email, 'message' => $message];
        }

        $sent = @mail(
            $this->settings['to'],
            'Anfrage zum Befragungsassistenten',
            "Antwortadresse: $email\n\n$message",
            [
                'From' => $this->settings['from'],
                'Reply-To' => $email,
                'Content-Type' => 'text/plain; charset=UTF-8',
            ],
        );
        if (!$sent) {
            return ['error' => 'Die Nachricht konnte gerade nicht gesendet werden. Bitte versuchen Sie es später erneut.', 'email' => $email, 'message' => $message];
        }

        $_SESSION['studio_contact_sent_at'] = time();
        $_SESSION['studio_contact_success'] = true;
        unset($_SESSION['studio_contact_token']);
        return null;
    }

    private function available(): bool
    {
        return function_exists('mail')
            && filter_var($this->settings['to'], FILTER_VALIDATE_EMAIL)
            && filter_var($this->settings['from'], FILTER_VALIDATE_EMAIL);
    }

    private function field(array $post, string $name, int $limit): string
    {
        $value = $post[$name] ?? '';
        return is_string($value) && mb_strlen($value) <= $limit ? trim($value) : '';
    }
}
