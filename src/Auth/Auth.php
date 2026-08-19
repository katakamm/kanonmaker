<?php

declare(strict_types=1);

namespace Kanon\Auth;

use Kanon\Http\Session;

final class Auth
{
    private const KEY = '_user_id';

    private ?array $cachedUser = null;
    private bool $loaded       = false;

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
        $this->forget();

        return true;
    }

    /**
     * The signed-in user, or null.
     *
     * A session can outlive the account it names: the account may have been
     * deleted or deactivated while the cookie sat in someone's browser. Such a
     * session is treated as signed out and cleared, so that no caller is handed
     * an id with no row behind it — doing so once produced a foreign key
     * violation and a fatal error on every page view.
     */
    public function user(): ?array
    {
        if ($this->loaded) {
            return $this->cachedUser;
        }

        $this->loaded = true;
        $id           = $this->session->get(self::KEY);

        if (!is_int($id)) {
            return $this->cachedUser = null;
        }

        $user = $this->users->findById($id);

        if ($user === null || (int) $user['active'] !== 1) {
            $this->session->remove(self::KEY);

            return $this->cachedUser = null;
        }

        return $this->cachedUser = $user;
    }

    /** Never returns an id whose user is missing or deactivated. */
    public function id(): ?int
    {
        $user = $this->user();

        return $user === null ? null : (int) $user['id'];
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
        $this->forget();
    }

    private function forget(): void
    {
        $this->loaded     = false;
        $this->cachedUser = null;
    }
}
