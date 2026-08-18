<?php

declare(strict_types=1);

namespace Kanon\Tests\Support;

use Kanon\Support\Normalize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NormalizeTest extends TestCase
{
    public static function textCases(): array
    {
        return [
            'czech carons'      => ['Čapek', 'capek'],
            'z with caron'      => ['Žert', 'zert'],
            'sindelka'          => ['Šindelka', 'sindelka'],
            'ring and acute'    => ['Hrůzová', 'hruzova'],
            'umlaut'            => ['Brontëová', 'bronteova'],
            'keeps punctuation' => ['Neruda, Jan', 'neruda, jan'],
            'collapses spaces'  => ["Karel   Hynek\tMácha", 'karel hynek macha'],
            'trims'             => ['  Máj  ', 'maj'],
            'keeps digits'      => ['R.U.R. 1920', 'r.u.r. 1920'],
        ];
    }

    #[DataProvider('textCases')]
    public function testTextStripsDiacriticsAndLowercases(string $input, string $expected): void
    {
        self::assertSame($expected, Normalize::text($input));
    }

    public function testKeyRemovesPunctuationAndHyphenatesSpaces(): void
    {
        self::assertSame('capek-karel', Normalize::key('ČAPEK, Karel'));
        self::assertSame('valka-s-mloky', Normalize::key('Válka s mloky'));
        self::assertSame('r-u-r', Normalize::key('R.U.R.'));
    }

    public function testKeyIsStableAcrossPunctuationDifferences(): void
    {
        self::assertSame(
            Normalize::key('Kytice, uvitá ke cti'),
            Normalize::key('Kytice uvitá ke cti')
        );
    }
}
