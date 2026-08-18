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

        /**
         * Every key a type needs must be present. PHP would otherwise only warn
         * about the missing key and cast null to '' or 0, quietly building a rule
         * that counts the wrong thing - and the administration relies on this
         * check to refuse an edit the engine could not evaluate.
         */
        $require = static function (array $keys) use ($params, $row): void {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $params)) {
                    throw new \InvalidArgumentException(
                        "Rule of type {$row['type']} needs the parameter \"{$key}\""
                    );
                }
            }
        };

        match ($row['type']) {
            'min_total'      => $require(['min']),
            'min_count'      => $require(['group', 'code', 'min']),
            'min_distinct'   => $require(['scope_group', 'scope_code', 'group', 'min']),
            'max_per_author' => $require(['max']),
            default          => throw new \InvalidArgumentException("Unknown rule type: {$row['type']}"),
        };

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
