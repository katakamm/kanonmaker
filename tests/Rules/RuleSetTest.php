<?php

declare(strict_types=1);

namespace Kanon\Tests\Rules;

use Kanon\Db\Database;
use Kanon\Db\Migrator;
use Kanon\Rules\RuleSet;
use Kanon\Rules\WorkView;
use PHPUnit\Framework\TestCase;

final class RuleSetTest extends TestCase
{
    private \PDO $pdo;
    private int $canonId;

    protected function setUp(): void
    {
        $root      = dirname(__DIR__, 2);
        $config    = require $root . '/config.php';
        $this->pdo = Database::connect($config['db']);
        (new Migrator($this->pdo, $root . '/db/migrations'))->migrate();

        $this->canonId = (int) $this->pdo->query(
            "SELECT id FROM canon WHERE school_year = '2025/2026'"
        )->fetchColumn();
    }

    /** A list that satisfies every one of the school's criteria. */
    private function completeList(): array
    {
        $subperiods = ['starovek', 'stredovek', 'renesance', 'baroko', 'klasicismus'];
        $forms      = ['poezie', 'proza', 'drama'];
        $works      = [];

        for ($i = 1; $i <= 25; $i++) {
            $tags = [
                'obdobi'  => [$i <= 5 ? 'do18' : ($i <= 8 ? '19st' : '20_21st')],
                'narodni' => [$i % 2 === 0 ? 'ceska' : 'svetova'],
                'forma'   => [$forms[$i % 3]],
            ];

            if ($i <= 5) {
                $tags['podobdobi'] = [$subperiods[$i - 1]];
            }

            if ($i === 9) {
                $tags['special'] = ['ceska_poezie_po_1950'];
            }

            $works[] = new WorkView($i, 'Dílo ' . $i, [$i => 'AUTOR ' . $i], $tags);
        }

        return $works;
    }

    public function testTwelveRulesAreLoadedFromTheCanon(): void
    {
        $results = RuleSet::fromCanon($this->pdo, $this->canonId)->evaluate([]);

        self::assertCount(12, $results);
    }

    public function testEmptyListFailsTheCountingRulesButNotTheAuthorRule(): void
    {
        $results = RuleSet::fromCanon($this->pdo, $this->canonId)->evaluate([]);

        $byLabel = [];
        foreach ($results as $result) {
            $byLabel[$result->label] = $result;
        }

        self::assertFalse($byLabel['Celkem 25 titulů']->satisfied);
        self::assertTrue(
            $byLabel['Od jednoho autora nejvýše dva tituly různé literární formy']->satisfied,
            'an empty list cannot break the author rule'
        );
    }

    public function testACorrectlyBuiltListSatisfiesEveryRule(): void
    {
        $ruleSet = RuleSet::fromCanon($this->pdo, $this->canonId);
        $results = $ruleSet->evaluate($this->completeList());

        foreach ($results as $result) {
            self::assertTrue(
                $result->satisfied,
                sprintf('rule "%s" failed: %d/%d %s', $result->label, $result->current, $result->required, (string) $result->detail)
            );
        }

        self::assertTrue($ruleSet->isComplete($this->completeList()));
    }

    public function testRemovingOneWorkBreaksExactlyTheExpectedRules(): void
    {
        $works = $this->completeList();
        array_pop($works);

        $results = RuleSet::fromCanon($this->pdo, $this->canonId)->evaluate($works);

        $failed = array_values(array_filter($results, static fn ($r): bool => !$r->satisfied));

        self::assertNotSame([], $failed);
        self::assertContains('Celkem 25 titulů', array_map(static fn ($r): string => $r->label, $failed));
    }

    public function testTwoWorksOfTheSameFormByOneAuthorBreakTheAuthorRule(): void
    {
        $works    = $this->completeList();
        $works[1] = new WorkView(
            $works[1]->id,
            $works[1]->title,
            [1 => 'AUTOR 1'],
            ['obdobi' => ['do18'], 'narodni' => ['ceska'], 'forma' => [$works[0]->form()], 'podobdobi' => ['stredovek']],
        );

        self::assertFalse(RuleSet::fromCanon($this->pdo, $this->canonId)->isComplete($works));
    }
}
