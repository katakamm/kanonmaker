<?php

declare(strict_types=1);

namespace Kanon\Tests\Import;

use Kanon\Import\HintTagger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HintTaggerTest extends TestCase
{
    public static function notes(): array
    {
        return [
            'plain poetry'        => ['poezie', 'poezie'],
            'story collection'    => ['povídkový soubor', 'proza'],
            'verse collection'    => ['veršovaný povídkový soubor', 'poezie'],
            'verse legend'        => ['česká veršovaná legenda', 'poezie'],
            'count of poems'      => ['13 básní', 'poezie'],
            'explicit drama'      => ['drama', 'drama'],
            'both parts of drama' => ['oba díly dramatu', 'drama'],
        ];
    }

    #[DataProvider('notes')]
    public function testRecognisedNotesYieldAForm(string $note, string $expectedCode): void
    {
        $tag = (new HintTagger())->formFor($note);

        self::assertNotNull($tag, "note '{$note}' should imply a form");
        self::assertSame('forma', $tag['group']);
        self::assertSame($expectedCode, $tag['code']);
    }

    public static function silentNotes(): array
    {
        return [
            'edition'   => ['EMG/Odeon, 2022'],
            'editor'    => ['ed. Jan Lehár'],
            'trilogy'   => ['trilogie'],
            'selection' => ['minimálně: Genesis + Exodus'],
        ];
    }

    #[DataProvider('silentNotes')]
    public function testUnrelatedNotesYieldNothing(string $note): void
    {
        self::assertNull((new HintTagger())->formFor($note));
    }

    public function testNullNoteYieldsNothing(): void
    {
        self::assertNull((new HintTagger())->formFor(null));
    }
}
