<?php

declare(strict_types=1);

namespace Kanon\Tests\Import;

use Kanon\Import\EntrySplitter;
use PHPUnit\Framework\TestCase;

final class EntrySplitterTest extends TestCase
{
    private EntrySplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new EntrySplitter();
    }

    public function testSplitsSemicolonSeparatedTitlesIntoSeparateWorks(): void
    {
        $works = $this->splitter->split('SHAKESPEARE, William: Hamlet; Král Lear; Macbeth');

        self::assertCount(3, $works);
        self::assertSame(['Hamlet', 'Král Lear', 'Macbeth'], array_map(
            static fn ($w): string => $w->title,
            $works
        ));

        foreach ($works as $work) {
            self::assertCount(1, $work->authors);
            self::assertSame('SHAKESPEARE', $work->authors[0]->surname);
            self::assertSame('William', $work->authors[0]->firstName);
        }
    }

    public function testDoesNotSplitOnPlusBecauseThosePartsAreReadTogether(): void
    {
        $works = $this->splitter->split('RIMBAUD, Arthur: Sezóna v pekle + Iluminace');

        self::assertCount(1, $works);
        self::assertSame('Sezóna v pekle + Iluminace', $works[0]->title);
    }

    public function testExtractsTrailingParenthesisAsNote(): void
    {
        $works = $this->splitter->split('AISCHYLOS: Oresteia (trilogie)');

        self::assertSame('Oresteia', $works[0]->title);
        self::assertSame('trilogie', $works[0]->note);
        self::assertSame('AISCHYLOS', $works[0]->authors[0]->surname);
        self::assertNull($works[0]->authors[0]->firstName);
    }

    public function testEntryWithoutAuthorYieldsWorkWithNoAuthors(): void
    {
        $works = $this->splitter->split('Beowulf (poezie)');

        self::assertCount(1, $works);
        self::assertSame([], $works[0]->authors);
        self::assertSame('Beowulf', $works[0]->title);
        self::assertSame('poezie', $works[0]->note);
    }

    public function testColonInsideParenthesesIsNotAnAuthorSeparator(): void
    {
        $works = $this->splitter->split('Nový zákon (minimálně: Marek + Jan + Zjevení Janovo)');

        self::assertSame([], $works[0]->authors, 'Nový zákon has no author');
        self::assertSame('Nový zákon', $works[0]->title);
        self::assertSame('minimálně: Marek + Jan + Zjevení Janovo', $works[0]->note);
    }

    public function testRepeatedSurnameFirstNamePairsBecomeSeveralAuthors(): void
    {
        $works = $this->splitter->split('BABAN, Džian, MAŠEK, Vojtěch, GRUS, Jiří: Ve stínu šumavských hvozdů');

        self::assertCount(1, $works);
        self::assertCount(3, $works[0]->authors);
        self::assertSame(['BABAN', 'MAŠEK', 'GRUS'], array_map(
            static fn ($a): string => $a->surname,
            $works[0]->authors
        ));
    }

    public function testMissingCommaBetweenSurnameAndGivenNameStillSplits(): void
    {
        $works = $this->splitter->split('ŠINDELKA, Marek, MAŠEK Vojtěch, POKORNÝ, Marek: Svatá Barbora');

        self::assertCount(3, $works[0]->authors);
        self::assertSame('MAŠEK', $works[0]->authors[1]->surname);
        self::assertSame('Vojtěch', $works[0]->authors[1]->firstName);
    }

    public function testPluralSurnameDuoStaysOneAuthor(): void
    {
        $works = $this->splitter->split('MRŠTÍKOVÉ, Alois a Vilém: Maryša');

        self::assertCount(1, $works[0]->authors);
        self::assertSame('MRŠTÍKOVÉ', $works[0]->authors[0]->surname);
        self::assertSame('Alois a Vilém', $works[0]->authors[0]->firstName);
    }

    public function testKeepsTheOriginalLineOnEveryWorkForProvenance(): void
    {
        $entry = 'ČAPEK, Karel: Hordubal; Krakatit';
        $works = $this->splitter->split($entry);

        self::assertSame($entry, $works[0]->sourceLine);
        self::assertSame($entry, $works[1]->sourceLine);
    }

    public function testAuthorMatchKeyIsDiacriticsInsensitive(): void
    {
        $works = $this->splitter->split('ČAPEK, Karel: Krakatit');

        self::assertSame('capek-karel', $works[0]->authors[0]->matchKey());
        self::assertSame('krakatit', $works[0]->titleKey());
    }
}
