<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use PDO;
use Throwable;

final class AccountAccess
{
    public function __construct(private readonly PDO $db, private readonly array $settings)
    {
    }

    public static function configured(array $settings): bool
    {
        return str_starts_with($settings['dsn'], 'mysql:')
            && $settings['user'] !== ''
            && filter_var($settings['url'], FILTER_VALIDATE_URL)
            && str_starts_with($settings['url'], 'https://')
            && filter_var($settings['from'], FILTER_VALIDATE_EMAIL);
    }

    public function request(string $email, string $browser, string $source, ?string $draftKey): bool
    {
        if (!$this->permit($email, $source)) {
            return false;
        }

        $this->db->exec('DELETE FROM account_login WHERE expires_at <= UTC_TIMESTAMP() LIMIT 100');
        $this->db->exec('DELETE FROM account_mail_limit WHERE window_start < UNIX_TIMESTAMP() - 86400 LIMIT 100');
        $token = bin2hex(random_bytes(32));
        $digest = hash('sha256', $token);
        $statement = $this->db->prepare(
            'INSERT INTO account_login (token_hash, browser_hash, email, draft_key, expires_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL 20 MINUTE)'
        );
        $statement->execute([$digest, hash('sha256', $browser), $email, $draftKey]);

        $url = rtrim($this->settings['url'], '/') . '/?' . http_build_query(['account' => 'verify', 'token' => $token]);
        $sent = @mail(
            $email,
            'Ihr Anmeldelink für myFeedback Kompass',
            "Öffnen Sie diesen Link im Browser, in dem Sie ihn angefordert haben. Der Link ist 20 Minuten gültig.\n\n$url",
            ['From' => $this->settings['from'], 'Content-Type' => 'text/plain; charset=UTF-8'],
        );
        if (!$sent) {
            $this->deleteToken($digest);
        }
        return $sent;
    }

    public function verify(string $token, string $browser): ?array
    {
        if (!preg_match('/\A[0-9a-f]{64}\z/', $token)) {
            return null;
        }

        $digest = hash('sha256', $token);
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'SELECT email, draft_key FROM account_login WHERE token_hash = ? AND browser_hash = ?
                 AND expires_at > UTC_TIMESTAMP() FOR UPDATE'
            );
            $statement->execute([$digest, hash('sha256', $browser)]);
            $request = $statement->fetch();
            if ($request === false) {
                $this->db->rollBack();
                return null;
            }

            $this->deleteToken($digest);
            $user = $this->db->prepare(
                'INSERT INTO account_user (email) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            );
            $user->execute([$request['email']]);
            $id = (int) $this->db->lastInsertId();
            $this->db->commit();
            return ['user' => $id, 'draft' => $request['draft_key']];
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    private function deleteToken(string $digest): void
    {
        $statement = $this->db->prepare('DELETE FROM account_login WHERE token_hash = ?');
        $statement->execute([$digest]);
    }

    private function permit(string $email, string $source): bool
    {
        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare(
                'INSERT INTO account_mail_limit (scope_key, window_start, attempts) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE attempts = LEAST(attempts + 1, 1000)'
            );
            $read = $this->db->prepare('SELECT attempts FROM account_mail_limit WHERE scope_key = ? AND window_start = ?');
            $allowed = true;
            foreach ([['source:' . $source, 900, 10], ['email:' . $email, 60, 1]] as [$scope, $window, $limit]) {
                $parameters = [hash('sha256', $scope), intdiv(time(), $window) * $window];
                $update->execute($parameters);
                $read->execute($parameters);
                $allowed = $allowed && (int) $read->fetchColumn() <= $limit;
                $read->closeCursor();
            }
            $this->db->commit();
            return $allowed;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }
}
