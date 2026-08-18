<?php

declare(strict_types=1);

namespace Kanon\Http;

final class PhpSession implements Session
{
    public function __construct()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'path'     => '/',
            ]);
            session_start();
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public function flash(string $type, string $message): void
    {
        $flashes            = $_SESSION['_flash'] ?? [];
        $flashes[]          = ['type' => $type, 'message' => $message];
        $_SESSION['_flash'] = $flashes;
    }

    public function takeFlashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return $flashes;
    }
}
