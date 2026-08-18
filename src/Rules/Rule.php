<?php

declare(strict_types=1);

namespace Kanon\Rules;

interface Rule
{
    /** @param list<WorkView> $works */
    public function evaluate(array $works): RuleResult;
}
