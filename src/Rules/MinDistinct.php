<?php

declare(strict_types=1);

namespace Kanon\Rules;

/**
 * "At least N different codes of one tag group, among the works carrying a
 * scope tag."
 *
 * Serves the clause inside criterion 1: the pre-19th-century works must span at
 * least three of starověk / středověk / renesance / baroko / klasicismus.
 */
final class MinDistinct implements Rule
{
    public function __construct(
        private readonly string $label,
        private readonly string $scopeGroup,
        private readonly string $scopeCode,
        private readonly string $group,
        private readonly int $min,
    ) {
    }

    public function evaluate(array $works): RuleResult
    {
        $codes = [];

        foreach ($works as $work) {
            if (!$work->hasTag($this->scopeGroup, $this->scopeCode)) {
                continue;
            }

            foreach ($work->tagCodes($this->group) as $code) {
                $codes[$code] = true;
            }
        }

        $present = array_keys($codes);
        sort($present);
        $count = count($present);

        return new RuleResult(
            $this->label,
            $count >= $this->min,
            $count,
            $this->min,
            $present === [] ? null : implode(', ', $present),
            $this->group,
            null,
        );
    }
}
