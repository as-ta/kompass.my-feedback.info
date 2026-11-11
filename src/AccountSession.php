<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

final class AccountSession
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
                'use_only_cookies' => true,
            ]);
        }
    }

    public static function csrf(): string
    {
        return $_SESSION['kompass_csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function accepts(mixed $token): bool
    {
        return is_string($token) && hash_equals(self::csrf(), $token);
    }

    public static function userId(): ?int
    {
        return $_SESSION['kompass_user'] ?? null;
    }

    public static function remember(array $snapshot): string
    {
        $drafts = $_SESSION['kompass_previews'] ?? [];
        foreach ($drafts as $key => $draft) {
            if ($draft === $snapshot) {
                return $key;
            }
        }
        $key = bin2hex(random_bytes(16));
        $drafts[$key] = $snapshot;
        $_SESSION['kompass_previews'] = array_slice($drafts, -8, null, true);
        return $key;
    }
}
