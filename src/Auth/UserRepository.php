<?php

declare(strict_types=1);

namespace Kanon\Auth;

final class UserRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user WHERE email = ?');
        $stmt->execute([self::normalizeEmail($email)]);

        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function exists(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }

    public function create(string $email, string $plainPassword, string $displayName, string $role = 'student'): int
    {
        $this->pdo->prepare(
            'INSERT INTO user (email, password_hash, display_name, role, active, created_at)
             VALUES (?, ?, ?, ?, 1, NOW())'
        )->execute([
            self::normalizeEmail($email),
            password_hash($plainPassword, PASSWORD_DEFAULT),
            $displayName,
            $role,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updatePassword(int $userId, string $plainPassword): void
    {
        $this->pdo->prepare('UPDATE user SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($plainPassword, PASSWORD_DEFAULT), $userId]);
    }

    public function verify(array $user, string $plainPassword): bool
    {
        return password_verify($plainPassword, $user['password_hash']);
    }
}
