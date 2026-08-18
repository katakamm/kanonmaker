<?php

declare(strict_types=1);

namespace Kanon\Auth;

/**
 * Counts recent failed logins per e-mail address.
 *
 * Kept in the database rather than the session, because a session-based counter
 * is defeated by discarding the cookie.
 */
final class LoginThrottle
{
    public const MAX_ATTEMPTS   = 10;
    public const WINDOW_MINUTES = 15;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function tooMany(string $email): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempt
             WHERE email = ? AND attempted_at > NOW() - INTERVAL ? MINUTE'
        );
        $stmt->execute([UserRepository::normalizeEmail($email), self::WINDOW_MINUTES]);

        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public function record(string $email): void
    {
        $this->pdo->prepare('INSERT INTO login_attempt (email, attempted_at) VALUES (?, NOW())')
            ->execute([UserRepository::normalizeEmail($email)]);
    }

    public function clear(string $email): void
    {
        $this->pdo->prepare('DELETE FROM login_attempt WHERE email = ?')
            ->execute([UserRepository::normalizeEmail($email)]);
    }
}
