<?php

declare(strict_types=1);

namespace Kanon\Tests\Repo;

use Kanon\Db\Database;
use Kanon\Repo\WorkRepository;
use PHPUnit\Framework\TestCase;

final class WorkRepositoryTest extends TestCase
{
    private WorkRepository $repo;
    private int $canonId;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $config        = require dirname(__DIR__, 2) . '/config.php';
        $this->pdo     = Database::connect($config['db']);
        $this->canonId = (int) $this->pdo->query(
            "SELECT id FROM canon WHERE school_year = '2025/2026'"
        )->fetchColumn();
        $this->repo    = new WorkRepository($this->pdo);
    }

    /** @param list<array> $works */
    private function titles(array $works): array
    {
        return array_map(static fn (array $w): string => $w['title'], $works);
    }

    public function testSearchIgnoresDiacritics(): void
    {
        self::assertContains('Krakatit', $this->titles($this->repo->search($this->canonId, 'capek')));
        self::assertContains('Žert', $this->titles($this->repo->search($this->canonId, 'zert')));
        self::assertNotSame([], $this->repo->search($this->canonId, 'sindelka'), 'Šindelka without the caron');
        self::assertNotSame([], $this->repo->search($this->canonId, 'zitkovske'), 'Žítkovské bohyně');
    }

    public function testSearchMatchesTitleAndAuthor(): void
    {
        self::assertContains('Babička', $this->titles($this->repo->search($this->canonId, 'babicka')));
        self::assertNotSame([], $this->repo->search($this->canonId, 'Němcová'));
    }

    public function testAllWordsMustMatch(): void
    {
        $both = $this->repo->search($this->canonId, 'capek valka');

        self::assertNotSame([], $both);
        self::assertContains('Válka s mloky', $this->titles($both));
        self::assertSame([], $this->repo->search($this->canonId, 'capek jednorozec'));
    }

    public function testSearchReturnsNothingForAnEmptyQuery(): void
    {
        self::assertSame([], $this->repo->search($this->canonId, '   '));
    }

    public function testSearchRespectsTheLimit(): void
    {
        self::assertLessThanOrEqual(5, count($this->repo->search($this->canonId, 'a', 5)));
    }

    public function testWorksCarryAuthorsAndTags(): void
    {
        $works = $this->repo->search($this->canonId, 'babicka');
        $work  = $works[0];

        self::assertStringContainsString('NĚMCOVÁ', $work['authors']);
        self::assertArrayHasKey('obdobi', $work['tags']);
        self::assertArrayHasKey('forma', $work['tags']);
        self::assertSame('proza', $work['tags']['forma'][0]['code']);
        self::assertSame('próza', $work['tags']['forma'][0]['label']);
    }

    public function testAnAuthorlessWorkHasAnEmptyAuthorString(): void
    {
        $works = $this->repo->search($this->canonId, 'beowulf');

        self::assertSame('', $works[0]['authors']);
    }

    public function testInferredTagsAreMarkedUnverified(): void
    {
        $works = $this->repo->search($this->canonId, 'babicka');
        $forma = $works[0]['tags']['forma'][0];

        self::assertFalse($forma['verified'], 'curated tags await human confirmation');
    }

    public function testBrowseReturnsTheWholeCanonInDocumentOrder(): void
    {
        $all = $this->repo->browse($this->canonId);

        self::assertGreaterThan(440, count($all));
        self::assertSame('Oresteia', $all[0]['title'], 'the canon opens with Aischylos');
    }

    public function testBrowseFiltersByChapter(): void
    {
        $chapters = $this->repo->chapters($this->canonId);
        $drama    = null;
        foreach ($chapters as $chapter) {
            if (str_contains($chapter['name'], 'dramatická')) {
                $drama = $chapter;
            }
        }

        self::assertNotNull($drama);
        $works = $this->repo->browse($this->canonId, $drama['id']);

        self::assertSame($drama['works'], count($works));
        foreach ($works as $work) {
            self::assertSame('drama', $work['tags']['forma'][0]['code']);
        }
    }

    public function testBrowseFiltersByTag(): void
    {
        $poetry = $this->repo->browse($this->canonId, null, 'forma', 'poezie');

        self::assertGreaterThan(20, count($poetry));
        foreach ($poetry as $work) {
            self::assertSame('poezie', $work['tags']['forma'][0]['code']);
        }
    }

    public function testFindReturnsOneWorkOrNull(): void
    {
        $some = $this->repo->browse($this->canonId, null, 'forma', 'drama')[0];
        $work = $this->repo->find($this->canonId, $some['id']);

        self::assertNotNull($work);
        self::assertSame($some['title'], $work['title']);
        self::assertNull($this->repo->find($this->canonId, 999999));
    }

    public function testFindManyKeepsTheGivenOrder(): void
    {
        $all = $this->repo->browse($this->canonId);
        $ids = [$all[5]['id'], $all[1]['id'], $all[3]['id']];

        self::assertSame($ids, array_column($this->repo->findMany($this->canonId, $ids), 'id'));
        self::assertSame([], $this->repo->findMany($this->canonId, []));
    }

    public function testChaptersComeBackInDocumentOrderWithCounts(): void
    {
        $chapters = $this->repo->chapters($this->canonId);

        self::assertCount(7, $chapters);
        self::assertSame(1, $chapters[0]['sort_order']);
        self::assertGreaterThan(0, $chapters[0]['works']);
        self::assertSame(count($this->repo->browse($this->canonId)), array_sum(array_column($chapters, 'works')));
    }

    public function testTagGroupsAreGroupedAndLabelled(): void
    {
        $groups = $this->repo->tagGroups($this->canonId);

        self::assertArrayHasKey('obdobi', $groups);
        self::assertArrayHasKey('podobdobi', $groups);
        self::assertCount(5, $groups['podobdobi']);
        self::assertSame('starověk', $groups['podobdobi'][0]['label']);
    }
}
