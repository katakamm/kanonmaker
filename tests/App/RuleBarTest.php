<?php

declare(strict_types=1);

namespace Kanon\Tests\App;

use Kanon\App\RuleBar;
use Kanon\Rules\RuleResult;
use PHPUnit\Framework\TestCase;

final class RuleBarTest extends TestCase
{
    /** @return list<RuleResult> */
    private function results(int $total): array
    {
        return [
            new RuleResult('Celkem 25 titulů', $total >= 25, $total, 25),
            new RuleResult('Drama (min. 3)', false, 1, 3, null, 'forma', 'drama'),
            new RuleResult('Poezie (min. 3)', true, 4, 3, null, 'forma', 'poezie'),
            new RuleResult('Nejméně tři literární období', false, 2, 3, 'baroko, starovek', 'podobdobi', null),
            new RuleResult('Od jednoho autora nejvýše dva', false, 2, 2, 'SHAKESPEARE: 2 tituly stejné formy'),
        ];
    }

    public function testTheSummaryTakesItsCountFromTheTotalRule(): void
    {
        $summary = RuleBar::summarise($this->results(18));

        self::assertSame(18, $summary['count']);
        self::assertSame(25, $summary['required']);
    }

    public function testItCountsTheUnmetRules(): void
    {
        self::assertSame(4, RuleBar::summarise($this->results(18))['unmet']);
    }

    public function testCompleteOnlyWhenEveryRulePasses(): void
    {
        self::assertFalse(RuleBar::summarise($this->results(25))['complete']);

        $allGood = [
            new RuleResult('Celkem 25 titulů', true, 25, 25),
            new RuleResult('Drama (min. 3)', true, 3, 3, null, 'forma', 'drama'),
        ];

        self::assertTrue(RuleBar::summarise($allGood)['complete']);
    }

    public function testPercentIsCappedAtOneHundred(): void
    {
        self::assertSame(72, RuleBar::summarise($this->results(18))['percent']);
        self::assertSame(100, RuleBar::summarise($this->results(30))['percent']);
    }

    public function testAnEmptyResultListDoesNotDivideByZero(): void
    {
        $summary = RuleBar::summarise([]);

        self::assertSame(0, $summary['count']);
        self::assertSame(0, $summary['percent']);
        self::assertTrue($summary['complete'], 'no rules means nothing is broken');
    }

    public function testATagRuleLinksToTheFilteredCanon(): void
    {
        $link = RuleBar::fixLink(new RuleResult('Drama', false, 1, 3, null, 'forma', 'drama'));

        self::assertSame('/kanon?skupina=forma&znacka=drama', $link);
    }

    public function testASatisfiedRuleHasNoLink(): void
    {
        self::assertNull(RuleBar::fixLink(new RuleResult('Drama', true, 3, 3, null, 'forma', 'drama')));
    }

    public function testARuleWithoutASingleFixingTagHasNoLink(): void
    {
        self::assertNull(RuleBar::fixLink(new RuleResult('Autor', false, 2, 2, 'SHAKESPEARE…')));
        self::assertNull(RuleBar::fixLink(new RuleResult('Období', false, 2, 3, 'baroko', 'podobdobi', null)));
    }
}
