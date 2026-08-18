<?php

declare(strict_types=1);

namespace Kanon\Tests\Admin;

use Kanon\Admin\AdminRepository;
use Kanon\Db\Database;
use Kanon\Rules\RuleSet;
use PHPUnit\Framework\TestCase;

final class RuleEditTest extends TestCase
{
    private \PDO $pdo;
    private AdminRepository $repo;
    private int $canonId;

    protected function setUp(): void
    {
        $config    = require dirname(__DIR__, 2) . '/config.php';
        $this->pdo = Database::connect($config['db']);
        $this->pdo->beginTransaction();

        $this->canonId = (int) $this->pdo->query(
            "SELECT id FROM canon WHERE school_year = '2025/2026'"
        )->fetchColumn();
        $this->repo = new AdminRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function ruleOfType(string $type): array
    {
        foreach ($this->repo->rules($this->canonId) as $rule) {
            if ($rule['type'] === $type) {
                return $rule;
            }
        }

        self::fail("no {$type} rule seeded");
    }

    public function testRulesComeBackWithDecodedParams(): void
    {
        $rules = $this->repo->rules($this->canonId);

        self::assertCount(12, $rules);
        self::assertIsArray($rules[0]['params']);
        self::assertSame(25, $this->ruleOfType('min_total')['params']['min']);
    }

    public function testChangingAMinimumTakesEffectInTheEngine(): void
    {
        $rule = $this->ruleOfType('min_total');

        self::assertTrue($this->repo->updateRule($rule['id'], ['min' => 20], true));

        $results = RuleSet::fromCanon($this->pdo, $this->canonId)->evaluate([]);
        $totals  = array_values(array_filter(
            $results,
            static fn ($r): bool => $r->label === $rule['label']
        ));

        self::assertSame(20, $totals[0]->required, 'the engine reads the edited value');
    }

    public function testDisablingARuleRemovesItFromTheCheck(): void
    {
        $rule = $this->ruleOfType('max_per_author');

        self::assertTrue($this->repo->updateRule($rule['id'], $rule['params'], false));
        self::assertCount(11, RuleSet::fromCanon($this->pdo, $this->canonId)->evaluate([]));
    }

    public function testAnEditTheEngineCannotUnderstandIsRefused(): void
    {
        $rule = $this->ruleOfType('min_count');

        self::assertFalse(
            $this->repo->updateRule($rule['id'], ['group' => 'forma'], true),
            'min_count without a code or a minimum must not be stored'
        );

        foreach ($this->repo->rules($this->canonId) as $current) {
            if ($current['id'] === $rule['id']) {
                self::assertSame($rule['params'], $current['params'], 'the old parameters survive');
            }
        }
    }

    public function testEveryRuleStillBuildsAfterAValidEdit(): void
    {
        $rule = $this->ruleOfType('min_distinct');
        $this->repo->updateRule(
            $rule['id'],
            ['scope_group' => 'obdobi', 'scope_code' => 'do18', 'group' => 'podobdobi', 'min' => 2],
            true
        );

        self::assertCount(12, RuleSet::fromCanon($this->pdo, $this->canonId)->evaluate([]));
    }
}
