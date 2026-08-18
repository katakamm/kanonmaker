<?php

declare(strict_types=1);

namespace Kanon\Tests\Db;

use Kanon\Db\Database;
use Kanon\Db\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $config = require dirname(__DIR__, 2) . '/config.php';
        $this->pdo = Database::connect($config['db']);
    }

    public function testMigrateAppliesEveryFileAndIsIdempotent(): void
    {
        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/db/migrations');

        $migrator->migrate();

        self::assertSame([], $migrator->pending(), 'no migration should remain pending');
        self::assertSame([], $migrator->migrate(), 'a second run must apply nothing');
    }

    public function testSchemaContainsEveryExpectedTable(): void
    {
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/db/migrations'))->migrate();

        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

        foreach ([
            'school', 'canon', 'chapter', 'author', 'work', 'work_author',
            'tag', 'work_tag', 'user', 'list', 'list_item', 'rule', 'migration',
        ] as $table) {
            self::assertContains($table, $tables, "table {$table} is missing");
        }
    }

    public function testWorkMatchKeyIsUniquePerCanon(): void
    {
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/db/migrations'))->migrate();

        $indexes = $this->pdo->query("SHOW INDEX FROM work WHERE Key_name = 'uq_work_canon_key'")
            ->fetchAll(\PDO::FETCH_ASSOC);

        self::assertCount(2, $indexes, 'uq_work_canon_key must cover (canon_id, match_key)');
        self::assertSame('0', (string) $indexes[0]['Non_unique']);
    }
}
