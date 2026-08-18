<?php

declare(strict_types=1);

namespace Kanon\Rules;

final class MinCount implements Rule
{
    public function __construct(
        private readonly string $label,
        private readonly string $group,
        private readonly string $code,
        private readonly int $min,
    ) {
    }

    public function evaluate(array $works): RuleResult
    {
        $count = 0;
        foreach ($works as $work) {
            if ($work->hasTag($this->group, $this->code)) {
                $count++;
            }
        }

        return new RuleResult(
            $this->label,
            $count >= $this->min,
            $count,
            $this->min,
            null,
            $this->group,
            $this->code,
        );
    }
}
