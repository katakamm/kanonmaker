<?php

declare(strict_types=1);

namespace Kanon\Rules;

final class RuleResult
{
    public function __construct(
        public readonly string $label,
        public readonly bool $satisfied,
        public readonly int $current,
        public readonly int $required,
        public readonly ?string $detail = null,
        public readonly ?string $fixGroup = null,
        public readonly ?string $fixCode = null,
    ) {
    }

    public function missing(): int
    {
        return max(0, $this->required - $this->current);
    }
}
