<?php

declare(strict_types=1);

namespace Kanon\Rules;

final class MinTotal implements Rule
{
    public function __construct(
        private readonly string $label,
        private readonly int $min,
    ) {
    }

    public function evaluate(array $works): RuleResult
    {
        $count = count($works);

        return new RuleResult($this->label, $count >= $this->min, $count, $this->min);
    }
}
