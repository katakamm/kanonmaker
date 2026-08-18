<?php

declare(strict_types=1);

namespace Kanon\Rules;

final class RuleSet
{
    /** @param list<Rule> $rules */
    public function __construct(private readonly array $rules)
    {
    }

    public static function fromCanon(\PDO $pdo, int $canonId): self
    {
        $stmt = $pdo->prepare(
            'SELECT type, params, label FROM rule WHERE canon_id = ? AND enabled = 1 ORDER BY sort_order'
        );
        $stmt->execute([$canonId]);

        return new self(array_map(
            static fn (array $row): Rule => RuleFactory::fromRow($row),
            $stmt->fetchAll()
        ));
    }

    /**
     * @param  list<WorkView> $works
     * @return list<RuleResult>
     */
    public function evaluate(array $works): array
    {
        return array_map(
            static fn (Rule $rule): RuleResult => $rule->evaluate($works),
            $this->rules
        );
    }

    /** @param list<WorkView> $works */
    public function isComplete(array $works): bool
    {
        foreach ($this->evaluate($works) as $result) {
            if (!$result->satisfied) {
                return false;
            }
        }

        return true;
    }
}
