<?php

declare(strict_types=1);

namespace Kanon\Import;

use Kanon\Support\Normalize;

final class ParsedAuthor
{
    public function __construct(
        public readonly string $surname,
        public readonly ?string $firstName,
        public readonly string $display,
    ) {
    }

    public function matchKey(): string
    {
        return Normalize::key($this->surname . ' ' . ($this->firstName ?? ''));
    }
}
