<?php

declare(strict_types=1);

namespace Kanon\Tests\Admin;

use Kanon\Admin\AdminRepository;
use Kanon\Db\Database;
use PHPUnit\Framework\TestCase;

final class AdminRepositoryTest extends TestCase
{
    private AdminRepository $repo;
    private int $canonId;

    protected function setUp(): void
    {
        $config        = require dirname(__DIR__, 2) . '/config.php';
        $pdo           = Database::connect($config['db']);
        $this->canonId = (int) $pdo->query("SELECT id FROM canon WHERE school_year = '2025/2026'")->fetchColumn();
        $this->repo    = new AdminRepository($pdo);
    }

    public function testStatsDescribeTheImportedCanon(): void
    {
        $stats = $this->repo->stats($this->canonId);

        self::assertGreaterThan(440, $stats['works']);
        self::assertGreaterThan(300, $stats['authors']);
        self::assertGreaterThan(1000, $stats['tags_total']);
    }

    public function testStatsCountStudentsAndLists(): void
    {
        $stats = $this->repo->stats($this->canonId);

        self::assertArrayHasKey('students', $stats);
        self::assertArrayHasKey('lists', $stats);
        self::assertGreaterThanOrEqual(0, $stats['students']);
    }

    public function testEveryStatIsAnInteger(): void
    {
        foreach ($this->repo->stats($this->canonId) as $key => $value) {
            self::assertIsInt($value, "{$key} should be an int");
        }
    }
}
