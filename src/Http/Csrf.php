<?php

declare(strict_types=1);

namespace Kanon\Http;

final class Csrf
{
    private const KEY = '_csrf';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }

        return $token;
    }

    public function check(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals($this->token(), $token);
    }
}
