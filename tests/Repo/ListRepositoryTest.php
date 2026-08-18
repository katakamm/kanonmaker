<?php

declare(strict_types=1);

namespace Kanon\Tests\Repo;

use Kanon\Auth\UserRepository;
use Kanon\Db\Database;
use Kanon\Repo\ListRepository;
use Kanon\Repo\WorkRepository;
use PHPUnit\Framework\TestCase;

final class ListRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ListRepository $lists;
    private int $canonId;
    private int $userId;
    /** @var list<int> */
    private array $workIds;

    protected function setUp(): void
    {
        $config    = require dirname(__DIR__, 2) . '/config.php';
        $this->pdo = Database::connect($config['db']);
        $this->pdo->beginTransaction();

        $this->canonId = (int) $this->pdo->query(
            "SELECT id FROM canon WHERE school_year = '2025/2026'"
        )->fetchColumn();

        $this->userId = (new UserRepository($this->pdo))->create(
            'list' . bin2hex(random_bytes(4)) . '@example.test',
            'tajneheslo123',
            'Kata'
        );

        $this->lists   = new ListRepository($this->pdo);
        $this->workIds = array_column(
            array_slice((new WorkRepository($this->pdo))->browse($this->canonId), 0, 4),
            'id'
        );
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testTheFirstCallCreatesTheListAndLaterCallsReturnTheSameOne(): void
    {
        $first  = $this->lists->forUser($this->userId, $this->canonId);
        $second = $this->lists->forUser($this->userId, $this->canonId);

        self::assertGreaterThan(0, $first);
        self::assertSame($first, $second, 'one list per student per canon');
    }

    public function testAddingKeepsTheOrderTheyWereAdded(): void
    {
        $listId = $this->lists->forUser($this->userId, $this->canonId);

        $this->lists->add($listId, $this->workIds[2]);
        $this->lists->add($listId, $this->workIds[0]);
        $this->lists->add($listId, $this->workIds[3]);

        self::assertSame(
            [$this->workIds[2], $this->workIds[0], $this->workIds[3]],
            $this->lists->workIds($listId)
        );
    }

    public function testAddingTheSameWorkTwiceChangesNothing(): void
    {
        $listId = $this->lists->forUser($this->userId, $this->canonId);

        self::assertTrue($this->lists->add($listId, $this->workIds[0]));
        self::assertFalse($this->lists->add($listId, $this->workIds[0]), 'already on the list');
        self::assertSame(1, $this->lists->count($listId));
    }

    public function testRemovingWorks(): void
    {
        $listId = $this->lists->forUser($this->userId, $this->canonId);
        $this->lists->add($listId, $this->workIds[0]);
        $this->lists->add($listId, $this->workIds[1]);

        self::assertTrue($this->lists->remove($listId, $this->workIds[0]));
        self::assertFalse($this->lists->remove($listId, $this->workIds[0]), 'it is already gone');
        self::assertSame([$this->workIds[1]], $this->lists->workIds($listId));
    }

    public function testContainsAndCount(): void
    {
        $listId = $this->lists->forUser($this->userId, $this->canonId);

        self::assertSame(0, $this->lists->count($listId));
        self::assertFalse($this->lists->contains($listId, $this->workIds[0]));

        $this->lists->add($listId, $this->workIds[0]);

        self::assertTrue($this->lists->contains($listId, $this->workIds[0]));
        self::assertSame(1, $this->lists->count($listId));
    }

    public function testTwoStudentsHaveSeparateLists(): void
    {
        $otherUser = (new UserRepository($this->pdo))->create(
            'other' . bin2hex(random_bytes(4)) . '@example.test',
            'tajneheslo123',
            'Jiná'
        );

        $mine  = $this->lists->forUser($this->userId, $this->canonId);
        $their = $this->lists->forUser($otherUser, $this->canonId);

        self::assertNotSame($mine, $their);

        $this->lists->add($mine, $this->workIds[0]);

        self::assertSame([], $this->lists->workIds($their));
    }
}
