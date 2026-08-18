<?php

declare(strict_types=1);

namespace Kanon\Tests\Admin;

use Kanon\Admin\ReviewRepository;
use Kanon\Db\Database;
use PHPUnit\Framework\TestCase;

final class ReviewRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ReviewRepository $repo;
    private int $canonId;

    protected function setUp(): void
    {
        $config    = require dirname(__DIR__, 2) . '/config.php';
        $this->pdo = Database::connect($config['db']);
        $this->pdo->beginTransaction();

        $this->canonId = (int) $this->pdo->query(
            "SELECT id FROM canon WHERE school_year = '2025/2026'"
        )->fetchColumn();
        $this->repo = new ReviewRepository($this->pdo);

        if ($this->repo->unverifiedCount($this->canonId) === 0) {
            self::markTestSkipped('every tag is already confirmed; nothing to review');
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function tagIdFor(string $group, string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM tag WHERE canon_id = ? AND tag_group = ? AND code = ?');
        $stmt->execute([$this->canonId, $group, $code]);

        return (int) $stmt->fetchColumn();
    }

    private function tagsOf(int $workId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.tag_group, t.code, wt.source, wt.verified
             FROM work_tag wt JOIN tag t ON t.id = wt.tag_id WHERE wt.work_id = ?'
        );
        $stmt->execute([$workId]);

        return $stmt->fetchAll();
    }

    public function testTheQueueGroupsUnverifiedTags(): void
    {
        $groups = $this->repo->groups($this->canonId);

        self::assertNotSame([], $groups);

        foreach ($groups as $group) {
            self::assertGreaterThan(0, $group['count']);
            self::assertSame($group['count'], count($group['works']));
            self::assertNotSame('', $group['label']);
        }
    }

    public function testTheHardestTagsComeFirst(): void
    {
        $groups = $this->repo->groups($this->canonId);
        $first  = $groups[0]['tag_group'];

        self::assertContains(
            $first,
            ['podobdobi', 'special', 'forma', 'narodni'],
            'the queue is ordered by consequence'
        );

        $priority = ['podobdobi' => 1, 'special' => 2, 'forma' => 3, 'narodni' => 4];
        $seen     = array_map(static fn (array $g): int => $priority[$g['tag_group']] ?? 9, $groups);
        $sorted   = $seen;
        sort($sorted);

        self::assertSame($sorted, $seen, 'groups never go back to an easier tag');
    }

    public function testTheQueueCoversEveryUnverifiedTag(): void
    {
        $inGroups = array_sum(array_column($this->repo->groups($this->canonId), 'count'));

        self::assertSame($this->repo->unverifiedCount($this->canonId), $inGroups);
    }

    public function testConfirmingAGroupMarksEveryTagHuman(): void
    {
        $group  = $this->repo->groups($this->canonId)[0];
        $before = $this->repo->unverifiedCount($this->canonId);

        $confirmed = $this->repo->confirmGroup(
            $this->canonId,
            $group['chapter_id'],
            $group['tag_group'],
            $group['code']
        );

        self::assertSame($group['count'], $confirmed);
        self::assertSame($before - $confirmed, $this->repo->unverifiedCount($this->canonId));

        $workId = $group['works'][0]['id'];
        foreach ($this->tagsOf($workId) as $tag) {
            if ($tag['tag_group'] === $group['tag_group'] && $tag['code'] === $group['code']) {
                self::assertSame('human', $tag['source']);
                self::assertSame(1, (int) $tag['verified']);
            }
        }
    }

    public function testConfirmingAGroupTwiceConfirmsNothingTheSecondTime(): void
    {
        $group = $this->repo->groups($this->canonId)[0];

        $this->repo->confirmGroup($this->canonId, $group['chapter_id'], $group['tag_group'], $group['code']);

        self::assertSame(
            0,
            $this->repo->confirmGroup($this->canonId, $group['chapter_id'], $group['tag_group'], $group['code'])
        );
    }

    public function testCorrectingAWorkReplacesItsTagInThatGroup(): void
    {
        $group  = $this->repo->groups($this->canonId)[0];
        $workId = $group['works'][0]['id'];
        $groupName = $group['tag_group'];

        $options = $this->pdo->prepare('SELECT code FROM tag WHERE canon_id = ? AND tag_group = ? AND code <> ?');
        $options->execute([$this->canonId, $groupName, $group['code']]);
        $replacement = (string) $options->fetchColumn();

        self::assertNotSame('', $replacement, 'the group needs a second possible value');
        self::assertTrue($this->repo->replaceTag($this->canonId, $workId, $groupName, $replacement));

        $codes = [];
        foreach ($this->tagsOf($workId) as $tag) {
            if ($tag['tag_group'] === $groupName) {
                $codes[] = $tag['code'];
                self::assertSame('human', $tag['source']);
                self::assertSame(1, (int) $tag['verified']);
            }
        }

        self::assertSame([$replacement], $codes, 'a work has exactly one value in this group');
    }

    public function testCorrectingWithAnUnknownTagChangesNothing(): void
    {
        $group  = $this->repo->groups($this->canonId)[0];
        $workId = $group['works'][0]['id'];
        $before = $this->tagsOf($workId);

        self::assertFalse($this->repo->replaceTag($this->canonId, $workId, $group['tag_group'], 'neexistuje'));
        self::assertEquals($before, $this->tagsOf($workId));
    }

    public function testConfirmingOneTagLeavesItsNeighboursAlone(): void
    {
        $group  = $this->repo->groups($this->canonId)[0];
        $workId = $group['works'][0]['id'];
        $tagId  = $this->tagIdFor($group['tag_group'], $group['code']);

        self::assertTrue($this->repo->confirmOne($workId, $tagId));
        self::assertFalse($this->repo->confirmOne($workId, $tagId), 'already confirmed');

        self::assertGreaterThan(
            0,
            $this->repo->unverifiedCount($this->canonId),
            'confirming one tag must not touch the rest of the queue'
        );
    }
}
