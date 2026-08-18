<?php

declare(strict_types=1);

namespace Kanon\Auth;

use Kanon\Http\Session;

final class Auth
{
    private const KEY = '_user_id';

    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginThrottle $throttle,
        private readonly Session $session,
    ) {
    }

    public function attempt(string $email, string $password): bool
    {
        if ($this->throttle->tooMany($email)) {
            return false;
        }

        $user = $this->users->findByEmail($email);

        if ($user === null || (int) $user['active'] !== 1 || !$this->users->verify($user, $password)) {
            $this->throttle->record($email);

            return false;
        }

        $this->throttle->clear($email);
        $this->session->regenerate();
        $this->session->set(self::KEY, (int) $user['id']);

        return true;
    }

    public function user(): ?array
    {
        $id = $this->session->get(self::KEY);

        return is_int($id) ? $this->users->findById($id) : null;
    }

    public function id(): ?int
    {
        $id = $this->session->get(self::KEY);

        return is_int($id) ? $id : null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function isAdmin(): bool
    {
        return ($this->user()['role'] ?? null) === 'admin';
    }

    public function logout(): void
    {
        $this->session->remove(self::KEY);
        $this->session->regenerate();
    }
}
