<?php

declare(strict_types=1);

namespace Kanon\Tests\Rules;

use Kanon\Rules\MinCount;
use Kanon\Rules\MinTotal;
use Kanon\Rules\WorkView;
use PHPUnit\Framework\TestCase;

final class MinCountTest extends TestCase
{
    /** @param array<string, list<string>> $tags */
    private function work(int $id, array $tags, array $authors = []): WorkView
    {
        return new WorkView($id, 'Dílo ' . $id, $authors, $tags);
    }

    public function testMinTotalCountsTheWholeList(): void
    {
        $rule = new MinTotal('Celkem 25 titulů', 25);

        $result = $rule->evaluate([$this->work(1, []), $this->work(2, [])]);

        self::assertFalse($result->satisfied);
        self::assertSame(2, $result->current);
        self::assertSame(25, $result->required);
        self::assertSame(23, $result->missing());
        self::assertSame('Celkem 25 titulů', $result->label);
    }

    public function testMinTotalIsSatisfiedWhenLongEnough(): void
    {
        $works = [];
        for ($i = 1; $i <= 25; $i++) {
            $works[] = $this->work($i, []);
        }

        self::assertTrue((new MinTotal('Celkem', 25))->evaluate($works)->satisfied);
    }

    public function testMinCountCountsOnlyWorksCarryingTheTag(): void
    {
        $rule = new MinCount('Poezie (min. 3)', 'forma', 'poezie', 3);

        $result = $rule->evaluate([
            $this->work(1, ['forma' => ['poezie']]),
            $this->work(2, ['forma' => ['proza']]),
            $this->work(3, ['forma' => ['poezie']]),
        ]);

        self::assertFalse($result->satisfied);
        self::assertSame(2, $result->current);
        self::assertSame(1, $result->missing());
    }

    public function testMinCountIsSatisfiedAtExactlyTheMinimum(): void
    {
        $rule = new MinCount('Drama (min. 3)', 'forma', 'drama', 3);

        $result = $rule->evaluate([
            $this->work(1, ['forma' => ['drama']]),
            $this->work(2, ['forma' => ['drama']]),
            $this->work(3, ['forma' => ['drama']]),
        ]);

        self::assertTrue($result->satisfied);
        self::assertSame(0, $result->missing());
    }

    public function testMinCountReportsTheTagThatWouldFixIt(): void
    {
        $result = (new MinCount('Česká literatura', 'narodni', 'ceska', 8))->evaluate([]);

        self::assertSame('narodni', $result->fixGroup);
        self::assertSame('ceska', $result->fixCode);
    }

    public function testOneWorkCountsTowardSeveralRules(): void
    {
        $work = $this->work(1, ['obdobi' => ['19st'], 'narodni' => ['ceska'], 'forma' => ['poezie']]);

        self::assertSame(1, (new MinCount('a', 'obdobi', '19st', 1))->evaluate([$work])->current);
        self::assertSame(1, (new MinCount('b', 'narodni', 'ceska', 1))->evaluate([$work])->current);
        self::assertSame(1, (new MinCount('c', 'forma', 'poezie', 1))->evaluate([$work])->current);
    }

    public function testWorkWithSeveralCodesInOneGroupCountsForEach(): void
    {
        $work = $this->work(1, ['forma' => ['poezie', 'drama']]);

        self::assertTrue($work->hasTag('forma', 'poezie'));
        self::assertTrue($work->hasTag('forma', 'drama'));
        self::assertSame('poezie', $work->form(), 'form() returns the first code');
    }
}
