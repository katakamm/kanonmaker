<?php

declare(strict_types=1);

namespace Kanon\App;

use Kanon\Rules\RuleResult;

/**
 * Presentation of the rule check.
 *
 * Turning an unmet rule into a link to the works that would satisfy it is the
 * point of the whole screen: it makes the check a shortcut rather than a verdict.
 */
final class RuleBar
{
    /**
     * @param  list<RuleResult> $results
     * @return array{count: int, required: int, unmet: int, complete: bool, percent: int}
     */
    public static function summarise(array $results): array
    {
        $count    = 0;
        $required = 0;
        $unmet    = 0;

        foreach ($results as $result) {
            if (!$result->satisfied) {
                $unmet++;
            }

            // The list-length rule is the one with no tag and no detail behind it.
            if ($result->fixGroup === null && $result->detail === null && $result->required > $required) {
                $count    = $result->current;
                $required = $result->required;
            }
        }

        return [
            'count'    => $count,
            'required' => $required,
            'unmet'    => $unmet,
            'complete' => $unmet === 0,
            'percent'  => $required === 0 ? 0 : min(100, (int) round($count / $required * 100)),
        ];
    }

    public static function fixLink(RuleResult $result): ?string
    {
        if ($result->satisfied || $result->fixGroup === null || $result->fixCode === null) {
            return null;
        }

        return '/kanon?skupina=' . rawurlencode($result->fixGroup)
             . '&znacka=' . rawurlencode($result->fixCode);
    }
}
