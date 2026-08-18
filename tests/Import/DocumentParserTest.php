<?php

declare(strict_types=1);

namespace Kanon\Tests\Import;

use Kanon\Import\DocumentParser;
use PHPUnit\Framework\TestCase;

final class DocumentParserTest extends TestCase
{
    private function sample(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/canon-sample.html');
    }

    public function testFindsBothChaptersInOrder(): void
    {
        $chapters = (new DocumentParser())->parse($this->sample());

        self::assertCount(2, $chapters);
        self::assertSame('Světová a česká literatura do konce 18. století (kromě preromantismu)', $chapters[0]['name']);
        self::assertSame(1, $chapters[0]['sort_order']);
        self::assertSame('Česká próza 20. a 21. století', $chapters[1]['name']);
        self::assertSame(2, $chapters[1]['sort_order']);
    }

    public function testIgnoresParagraphsBeforeTheFirstChapter(): void
    {
        $chapters = (new DocumentParser())->parse($this->sample());
        $entries  = array_merge($chapters[0]['entries'], $chapters[1]['entries']);

        self::assertNotContains('Školní kánon GJK', $entries);
        self::assertNotContains('Kritéria pro výběr maturitních děl', $entries);
    }

    public function testCollectsEntriesPerChapterAndDropsEmptyParagraphs(): void
    {
        $chapters = (new DocumentParser())->parse($this->sample());

        self::assertCount(5, $chapters[0]['entries']);
        self::assertCount(3, $chapters[1]['entries']);
        self::assertSame('Beowulf (poezie)', $chapters[0]['entries'][1]);
    }

    public function testTrimsTrailingWhitespaceAndNonBreakingSpaces(): void
    {
        $chapters = (new DocumentParser())->parse($this->sample());

        self::assertSame('ČAPEK, Karel: Hordubal; Krakatit; Válka s mloky', $chapters[1]['entries'][0]);
    }

    public function testRealSnapshotYields358Entries(): void
    {
        $html     = (string) file_get_contents(dirname(__DIR__, 2) . '/data/canon-2025-2026.html');
        $chapters = (new DocumentParser())->parse($html);

        self::assertCount(7, $chapters, 'the real snapshot has seven chapters');

        $total = array_sum(array_map(static fn (array $c): int => count($c['entries']), $chapters));
        self::assertSame(358, $total, 'the real snapshot has 358 entries');
    }
}
