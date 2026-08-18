<?php

declare(strict_types=1);

namespace Kanon\Tests\Import;

use Kanon\Db\Database;
use PHPUnit\Framework\TestCase;

/**
 * Guards the imported data rather than the code: every work must carry the tags
 * the school's criteria need, otherwise the rule check silently under-counts.
 */
final class CanonCoverageTest extends TestCase
{
    private \PDO $pdo;
    private int $canonId;

    protected function setUp(): void
    {
        $config        = require dirname(__DIR__, 2) . '/config.php';
        $this->pdo     = Database::connect($config['db']);
        $this->canonId = (int) $this->pdo->query(
            "SELECT id FROM canon WHERE school_year = '2025/2026'"
        )->fetchColumn();
    }

    /** @return list<string> titles of works missing a tag in the group */
    private function missing(string $group, ?string $scopeCode = null): array
    {
        $sql = "SELECT w.title FROM work w
                WHERE w.canon_id = :canon
                  AND NOT EXISTS (
                      SELECT 1 FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
                      WHERE wt.work_id = w.id AND t.tag_group = :grp
                  )";

        if ($scopeCode !== null) {
            $sql .= " AND EXISTS (
                          SELECT 1 FROM work_tag wt2 JOIN tag t2 ON t2.id = wt2.tag_id
                          WHERE wt2.work_id = w.id AND t2.code = :scope
                      )";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':canon', $this->canonId, \PDO::PARAM_INT);
        $stmt->bindValue(':grp', $group);
        if ($scopeCode !== null) {
            $stmt->bindValue(':scope', $scopeCode);
        }
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testTheCanonWasImported(): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM work WHERE canon_id = ?');
        $stmt->execute([$this->canonId]);

        self::assertGreaterThan(440, (int) $stmt->fetchColumn(), 'run bin/import first');
    }

    public function testEveryWorkHasAPeriod(): void
    {
        self::assertSame([], $this->missing('obdobi'));
    }

    public function testEveryWorkHasANationality(): void
    {
        self::assertSame([], $this->missing('narodni'));
    }

    public function testEveryWorkHasAForm(): void
    {
        self::assertSame([], $this->missing('forma'));
    }

    public function testEveryPreNineteenthCenturyWorkHasASubperiod(): void
    {
        self::assertSame([], $this->missing('podobdobi', 'do18'));
    }

    public function testAtLeastOneWorkCarriesTheCzechModernPoetryTag(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
             JOIN work w ON w.id = wt.work_id
             WHERE w.canon_id = ? AND t.code = 'ceska_poezie_po_1950'"
        );
        $stmt->execute([$this->canonId]);

        self::assertGreaterThan(0, (int) $stmt->fetchColumn());
    }

    public function testAllFiveSubperiodsAreRepresented(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT t.code) FROM work_tag wt
             JOIN tag t ON t.id = wt.tag_id JOIN work w ON w.id = wt.work_id
             WHERE w.canon_id = ? AND t.tag_group = 'podobdobi'"
        );
        $stmt->execute([$this->canonId]);

        self::assertSame(5, (int) $stmt->fetchColumn(), 'a student must be able to reach three of five');
    }
}
