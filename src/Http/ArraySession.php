<?php

declare(strict_types=1);

namespace Kanon\Http;

/** In-memory session, so anything session-dependent can be tested without a browser. */
final class ArraySession implements Session
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
    }

    public function destroy(): void
    {
        $this->data = [];
    }

    public function flash(string $type, string $message): void
    {
        $flashes   = $this->data['_flash'] ?? [];
        $flashes[] = ['type' => $type, 'message' => $message];

        $this->data['_flash'] = $flashes;
    }

    public function takeFlashes(): array
    {
        $flashes = $this->data['_flash'] ?? [];
        unset($this->data['_flash']);

        return $flashes;
    }
}
