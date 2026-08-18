<?php

declare(strict_types=1);

namespace Kanon\Tests\Db;

use Kanon\Db\Database;
use Kanon\Db\Migrator;
use PHPUnit\Framework\TestCase;

final class SeedTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $config    = require dirname(__DIR__, 2) . '/config.php';
        $this->pdo = Database::connect($config['db']);
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/db/migrations'))->migrate();
    }

    public function testCanonExists(): void
    {
        $row = $this->pdo->query(
            "SELECT c.id, c.school_year, s.name
             FROM canon c JOIN school s ON s.id = c.school_id
             WHERE c.school_year = '2025/2026'"
        )->fetch();

        self::assertNotFalse($row, 'canon 2025/2026 must be seeded');
        self::assertSame('Gymnázium Jana Keplera', $row['name']);
    }

    public function testTagVocabularyIsComplete(): void
    {
        $rows = $this->pdo->query('SELECT tag_group, code FROM tag')->fetchAll();
        $have = array_map(static fn (array $r): string => $r['tag_group'] . '/' . $r['code'], $rows);

        foreach ([
            'obdobi/do18', 'obdobi/19st', 'obdobi/20_21st',
            'podobdobi/starovek', 'podobdobi/stredovek', 'podobdobi/renesance',
            'podobdobi/baroko', 'podobdobi/klasicismus',
            'narodni/ceska', 'narodni/svetova',
            'forma/poezie', 'forma/proza', 'forma/drama',
            'special/ceska_poezie_po_1950',
        ] as $expected) {
            self::assertContains($expected, $have, "tag {$expected} is missing");
        }
    }

    public function testTwelveRulesAreSeededWithValidJsonParams(): void
    {
        $rules = $this->pdo->query('SELECT type, params FROM rule ORDER BY sort_order')->fetchAll();

        self::assertCount(12, $rules);

        foreach ($rules as $rule) {
            $params = json_decode($rule['params'], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($params, "params of {$rule['type']} must decode to an array");
        }

        $types = array_column($rules, 'type');
        self::assertSame(1, array_count_values($types)['min_total']);
        self::assertSame(1, array_count_values($types)['min_distinct']);
        self::assertSame(1, array_count_values($types)['max_per_author']);
        self::assertSame(9, array_count_values($types)['min_count']);
    }
}
