<?php

declare(strict_types=1);

namespace Kanon\Tests\Import;

use Kanon\Import\ChapterTagger;
use Kanon\Import\DocumentParser;
use PHPUnit\Framework\TestCase;

final class ChapterTaggerTest extends TestCase
{
    private ChapterTagger $tagger;

    protected function setUp(): void
    {
        $this->tagger = new ChapterTagger();
    }

    public function testMixedChapterGivesOnlyThePeriod(): void
    {
        $tags = $this->tagger->tagsFor('Světová a česká literatura do konce 18. století (kromě preromantismu)');

        self::assertSame([['group' => 'obdobi', 'code' => 'do18']], $tags);
    }

    public function testWorldNineteenthCenturyGivesPeriodAndNationality(): void
    {
        $tags = $this->tagger->tagsFor('Světová literatura od preromantismu do konce 19. století');

        self::assertContains(['group' => 'obdobi', 'code' => '19st'], $tags);
        self::assertContains(['group' => 'narodni', 'code' => 'svetova'], $tags);
        self::assertCount(2, $tags);
    }

    public function testCzechProseChapterGivesAllThree(): void
    {
        $tags = $this->tagger->tagsFor('Česká próza 20. a 21. století');

        self::assertContains(['group' => 'obdobi', 'code' => '20_21st'], $tags);
        self::assertContains(['group' => 'narodni', 'code' => 'ceska'], $tags);
        self::assertContains(['group' => 'forma', 'code' => 'proza'], $tags);
        self::assertCount(3, $tags);
    }

    public function testDramaChapterGivesFormButNotNationality(): void
    {
        $tags   = $this->tagger->tagsFor('Světová a česká dramatická tvorba 20. - 21. století');
        $groups = array_column($tags, 'group');

        self::assertContains('forma', $groups);
        self::assertNotContains('narodni', $groups, 'that chapter mixes Czech and world authors');
    }

    public function testEverySeededChapterIsMapped(): void
    {
        foreach (DocumentParser::CHAPTERS as $chapter) {
            self::assertNotSame([], $this->tagger->tagsFor($chapter), "unmapped chapter: {$chapter}");
        }
    }

    public function testUnknownChapterThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tagger->tagsFor('Nějaká neznámá kapitola');
    }
}
