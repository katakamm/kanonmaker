<?php

declare(strict_types=1);

namespace Kanon\Tests\Rules;

use Kanon\Rules\MaxPerAuthor;
use Kanon\Rules\WorkView;
use PHPUnit\Framework\TestCase;

final class MaxPerAuthorTest extends TestCase
{
    private function rule(): MaxPerAuthor
    {
        return new MaxPerAuthor('Od jednoho autora nejvýše dva tituly různé literární formy', 2, true);
    }

    /** @param array<int, string> $authors */
    private function work(int $id, string $title, array $authors, ?string $form): WorkView
    {
        return new WorkView($id, $title, $authors, $form === null ? [] : ['forma' => [$form]]);
    }

    public function testTwoDramasByOneAuthorFail(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'Hamlet', [7 => 'SHAKESPEARE, William'], 'drama'),
            $this->work(2, 'Macbeth', [7 => 'SHAKESPEARE, William'], 'drama'),
        ]);

        self::assertFalse($result->satisfied);
        self::assertNotNull($result->detail);
        self::assertStringContainsString('SHAKESPEARE, William', $result->detail);
    }

    public function testADramaAndAPoemByOneAuthorPass(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'Hamlet', [7 => 'SHAKESPEARE, William'], 'drama'),
            $this->work(2, 'Sonety', [7 => 'SHAKESPEARE, William'], 'poezie'),
        ]);

        self::assertTrue($result->satisfied);
    }

    public function testThreeTitlesByOneAuthorFailEvenWithDifferentForms(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'Hordubal', [3 => 'ČAPEK, Karel'], 'proza'),
            $this->work(2, 'R.U.R.', [3 => 'ČAPEK, Karel'], 'drama'),
            $this->work(3, 'Básně', [3 => 'ČAPEK, Karel'], 'poezie'),
        ]);

        self::assertFalse($result->satisfied);
        self::assertSame(3, $result->current);
        self::assertSame(2, $result->required);
    }

    public function testDifferentAuthorsWithTheSameFormAreFine(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'Hamlet', [7 => 'SHAKESPEARE, William'], 'drama'),
            $this->work(2, 'Nora', [9 => 'IBSEN, Henrik'], 'drama'),
        ]);

        self::assertTrue($result->satisfied);
    }

    public function testCoAuthoredWorkCountsForEveryAuthor(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'Zůstaňte s námi', [11 => 'ŠINDELKA, Marek'], 'proza'),
            $this->work(2, 'Svatá Barbora', [11 => 'ŠINDELKA, Marek', 12 => 'MAŠEK, Vojtěch'], 'proza'),
        ]);

        self::assertFalse($result->satisfied, 'two prose titles involve Šindelka');
        self::assertStringContainsString('ŠINDELKA, Marek', (string) $result->detail);
    }

    public function testAuthorlessWorksAreIgnored(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'Beowulf', [], 'poezie'),
            $this->work(2, 'Edda', [], 'poezie'),
            $this->work(3, 'Epos o Gilgamešovi', [], 'poezie'),
        ]);

        self::assertTrue($result->satisfied);
    }

    public function testUntaggedFormsDoNotTriggerAFalseViolation(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'Dílo A', [5 => 'NOVÁK, Jan'], null),
            $this->work(2, 'Dílo B', [5 => 'NOVÁK, Jan'], null),
        ]);

        self::assertTrue($result->satisfied, 'without forms the rule cannot claim a violation');
    }

    public function testEmptyListPasses(): void
    {
        self::assertTrue($this->rule()->evaluate([])->satisfied);
    }

    public function testWithoutDistinctFormOnlyTheCountMatters(): void
    {
        $rule = new MaxPerAuthor('Nejvýše dva tituly od autora', 2, false);

        $result = $rule->evaluate([
            $this->work(1, 'Hamlet', [7 => 'SHAKESPEARE, William'], 'drama'),
            $this->work(2, 'Macbeth', [7 => 'SHAKESPEARE, William'], 'drama'),
        ]);

        self::assertTrue($result->satisfied);
    }
}
