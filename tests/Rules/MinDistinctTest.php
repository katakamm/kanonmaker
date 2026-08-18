<?php

declare(strict_types=1);

namespace Kanon\Tests\Rules;

use Kanon\Rules\MinDistinct;
use Kanon\Rules\WorkView;
use PHPUnit\Framework\TestCase;

final class MinDistinctTest extends TestCase
{
    private function rule(): MinDistinct
    {
        return new MinDistinct(
            'Nejméně tři literární období do konce 18. století',
            'obdobi',
            'do18',
            'podobdobi',
            3,
        );
    }

    private function work(int $id, string $period, ?string $subperiod): WorkView
    {
        $tags = ['obdobi' => [$period]];
        if ($subperiod !== null) {
            $tags['podobdobi'] = [$subperiod];
        }

        return new WorkView($id, 'Dílo ' . $id, [], $tags);
    }

    public function testFailsWhenFiveWorksComeFromOnlyTwoPeriods(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'do18', 'starovek'),
            $this->work(2, 'do18', 'starovek'),
            $this->work(3, 'do18', 'starovek'),
            $this->work(4, 'do18', 'baroko'),
            $this->work(5, 'do18', 'baroko'),
        ]);

        self::assertFalse($result->satisfied, 'the count is met but the spread is not');
        self::assertSame(2, $result->current);
        self::assertSame(3, $result->required);
    }

    public function testPassesWithThreeDifferentPeriods(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'do18', 'starovek'),
            $this->work(2, 'do18', 'baroko'),
            $this->work(3, 'do18', 'renesance'),
        ]);

        self::assertTrue($result->satisfied);
        self::assertSame(3, $result->current);
    }

    public function testIgnoresWorksOutsideTheScope(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'do18', 'starovek'),
            $this->work(2, '19st', 'klasicismus'),
            $this->work(3, '20_21st', 'baroko'),
        ]);

        self::assertSame(1, $result->current, 'only works tagged do18 may contribute');
    }

    public function testUntaggedWorksInScopeContributeNothing(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'do18', 'starovek'),
            $this->work(2, 'do18', null),
        ]);

        self::assertSame(1, $result->current);
    }

    public function testDetailNamesThePeriodsAlreadyPresent(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'do18', 'starovek'),
            $this->work(2, 'do18', 'baroko'),
        ]);

        self::assertNotNull($result->detail);
        self::assertStringContainsString('starovek', $result->detail);
        self::assertStringContainsString('baroko', $result->detail);
    }

    public function testEmptyListIsNotSatisfied(): void
    {
        $result = $this->rule()->evaluate([]);

        self::assertFalse($result->satisfied);
        self::assertSame(0, $result->current);
    }
}
