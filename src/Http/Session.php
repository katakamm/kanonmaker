<?php

declare(strict_types=1);

namespace Kanon\Http;

interface Session
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** Called on privilege change, to defeat session fixation. */
    public function regenerate(): void;

    public function destroy(): void;

    public function flash(string $type, string $message): void;

    /** @return list<array{type: string, message: string}> */
    public function takeFlashes(): array;
}
