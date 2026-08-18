<?php

declare(strict_types=1);

namespace Kanon\Tests\Import;

use Kanon\Import\EntryFixes;
use PHPUnit\Framework\TestCase;

final class EntryFixesTest extends TestCase
{
    private function fixes(): EntryFixes
    {
        return new EntryFixes(dirname(__DIR__) . '/fixtures/entry-fixes-sample.json');
    }

    public function testSplitsAMergedParagraphIntoTwoEntries(): void
    {
        $result = $this->fixes()->apply([
            'AISCHYLOS: Oresteia',
            'DÜRRENMATT, Friedrich: Návštěva staré dámy HAVEL, Václav: Zahradní slavnost',
        ]);

        self::assertSame([
            'AISCHYLOS: Oresteia',
            'DÜRRENMATT, Friedrich: Návštěva staré dámy',
            'HAVEL, Václav: Zahradní slavnost',
        ], $result);
    }

    public function testAnEmptyReplacementDropsTheEntry(): void
    {
        $result = $this->fixes()->apply(['AISCHYLOS: Oresteia', 'Gymnázium Jana Keplera, Parléřova 2']);

        self::assertSame(['AISCHYLOS: Oresteia'], $result);
    }

    public function testUntouchedEntriesPassThroughUnchanged(): void
    {
        $entries = ['HOMÉR: Odysseia', 'Beowulf (poezie)'];

        self::assertSame($entries, $this->fixes()->apply($entries));
    }

    public function testMatchingIsExactSoNearMissesAreNotCorrected(): void
    {
        $result = $this->fixes()->apply(['DÜRRENMATT, Friedrich: Návštěva staré dámy']);

        self::assertSame(['DÜRRENMATT, Friedrich: Návštěva staré dámy'], $result, 'a shorter line must not match');
    }

    public function testReportsCorrectionsThatMatchedNothing(): void
    {
        $fixes = $this->fixes();
        $fixes->apply(['AISCHYLOS: Oresteia']);

        self::assertCount(2, $fixes->unused(), 'both corrections went unused');
        self::assertSame(0, $fixes->appliedCount());
    }

    public function testCountsWhatItApplied(): void
    {
        $fixes = $this->fixes();
        $fixes->apply(['Gymnázium Jana Keplera, Parléřova 2']);

        self::assertSame(1, $fixes->appliedCount());
        self::assertCount(1, $fixes->unused());
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        new EntryFixes('/nonexistent/fixes.json');
    }
}
