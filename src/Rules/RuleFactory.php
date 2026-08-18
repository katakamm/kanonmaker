<?php

declare(strict_types=1);

namespace Kanon\Rules;

/**
 * Builds a Rule from a database row.
 *
 * Rule types live in code and only their parameters are editable, so an
 * administrative typo cannot produce a rule the engine is unable to evaluate.
 */
final class RuleFactory
{
    /** @param array{type: string, params: string, label: string} $row */
    public static function fromRow(array $row): Rule
    {
        $params = json_decode($row['params'], true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($params)) {
            throw new \InvalidArgumentException("Rule params must be a JSON object: {$row['params']}");
        }

        return match ($row['type']) {
            'min_total' => new MinTotal(
                $row['label'],
                (int) $params['min'],
            ),
            'min_count' => new MinCount(
                $row['label'],
                (string) $params['group'],
                (string) $params['code'],
                (int) $params['min'],
            ),
            'min_distinct' => new MinDistinct(
                $row['label'],
                (string) $params['scope_group'],
                (string) $params['scope_code'],
                (string) $params['group'],
                (int) $params['min'],
            ),
            'max_per_author' => new MaxPerAuthor(
                $row['label'],
                (int) $params['max'],
                (bool) ($params['distinct_form'] ?? false),
            ),
            default => throw new \InvalidArgumentException("Unknown rule type: {$row['type']}"),
        };
    }
}
