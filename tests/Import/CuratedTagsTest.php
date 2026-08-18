<?php

declare(strict_types=1);

namespace Kanon\Tests\Import;

use Kanon\Import\CuratedTags;
use PHPUnit\Framework\TestCase;

final class CuratedTagsTest extends TestCase
{
    private function fixture(): string
    {
        return dirname(__DIR__) . '/fixtures/tags-sample.csv';
    }

    public function testGroupsTagsByWorkKey(): void
    {
        $tags = (new CuratedTags())->load($this->fixture());

        self::assertArrayHasKey('1|aischylos|oresteia', $tags);
        self::assertCount(3, $tags['1|aischylos|oresteia']);
        self::assertContains(['group' => 'forma', 'code' => 'drama'], $tags['1|aischylos|oresteia']);
    }

    public function testKeepsAuthorlessWorks(): void
    {
        $tags = (new CuratedTags())->load($this->fixture());

        self::assertContains(['group' => 'podobdobi', 'code' => 'stredovek'], $tags['1|anon|beowulf']);
    }

    public function testSkipsRowsWithoutACode(): void
    {
        $tags = (new CuratedTags())->load($this->fixture());

        self::assertArrayNotHasKey('2|capek-karel|krakatit', $tags, 'unfilled scaffold rows must be ignored');
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CuratedTags())->load('/nonexistent/tags.csv');
    }

    public function testWrongHeaderThrows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tags');
        file_put_contents($path, "a,b,c\n1,2,3\n");

        try {
            $this->expectException(\RuntimeException::class);
            (new CuratedTags())->load($path);
        } finally {
            unlink($path);
        }
    }
}
