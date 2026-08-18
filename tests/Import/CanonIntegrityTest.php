<?php

declare(strict_types=1);

namespace Kanon\Tests\Import;

use Kanon\Db\Database;
use PHPUnit\Framework\TestCase;

/**
 * Guards the imported canon against the malformations present in the school's
 * document: paragraphs holding two entries, commas used as title separators, an
 * author's names written back to front, and the address footer.
 *
 * Without the corrections, four Havel plays were credited to Dürrenmatt.
 */
final class CanonIntegrityTest extends TestCase
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

    /** @return list<string> author display names for the work with this key */
    private function authorsOf(string $matchKey): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.display_name FROM work w
             JOIN work_author wa ON wa.work_id = w.id
             JOIN author a ON a.id = wa.author_id
             WHERE w.canon_id = ? AND w.match_key = ?
             ORDER BY a.display_name'
        );
        $stmt->execute([$this->canonId, $matchKey]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testNoTitleStillContainsAnEmbeddedAuthorName(): void
    {
        $stmt = $this->pdo->prepare('SELECT title FROM work WHERE canon_id = ?');
        $stmt->execute([$this->canonId]);

        // A run of three or more capitals before a colon is how the document
        // writes an author, so finding one inside a title means two entries were
        // merged. Ordinary colons are fine: "1913: Léto jednoho století".
        $suspect = array_values(array_filter(
            $stmt->fetchAll(\PDO::FETCH_COLUMN),
            static fn (string $t): bool => preg_match('/\p{Lu}{3,}[^:]{0,40}:/u', $t) === 1
        ));

        self::assertSame([], $suspect);
    }

    public function testTheSecondWorkOfEachMergedParagraphExistsOnItsOwn(): void
    {
        foreach ([
            '1|vergilius|aeneis'            => 'VERGILIUS',
            '2|dickinsonova-emily|a-basnikem-byt-nechci' => 'DICKINSONOVÁ, Emily',
            '3|jirasek-alois|temno'         => 'JIRÁSEK, Alois',
            '3|kollar-jan|slavy-dcera'      => 'KOLLÁR, Ján',
            '4|havel-vaclav|zahradni-slavnost' => 'HAVEL, Václav',
        ] as $key => $author) {
            self::assertSame([$author], $this->authorsOf($key), "wrong author for {$key}");
        }
    }

    public function testHavelsPlaysAreNotCreditedToDurrenmatt(): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM work w
             JOIN work_author wa ON wa.work_id = w.id
             JOIN author a ON a.id = wa.author_id
             WHERE w.canon_id = ? AND a.display_name LIKE ?'
        );
        $stmt->execute([$this->canonId, 'DÜRRENMATT%']);

        self::assertSame(1, (int) $stmt->fetchColumn(), 'Dürrenmatt has exactly one title in this canon');

        $stmt->execute([$this->canonId, 'HAVEL, Václav']);
        self::assertSame(5, (int) $stmt->fetchColumn(), 'Havel has five');
    }

    public function testCommaSeparatedTitlesWereSplit(): void
    {
        foreach ([
            '4|havel-vaclav|odchazeni',
            '4|havel-vaclav|pokouseni',
            '7|ajvaz-michal|lucemburska-zahrada',
            '7|kundera-milan|smesne-lasky',
            '7|urban-milos|posledni-tecka-za-rukopisy',
        ] as $key) {
            self::assertNotSame([], $this->authorsOf($key), "{$key} should exist as its own work");
        }
    }

    public function testGarciaMarquezHasOneCorrectlyNamedAuthorRecord(): void
    {
        self::assertSame(['GARCÍA MÁRQUEZ, Gabriel'], $this->authorsOf('6|garcia-marquez-gabriel|sto-roku-samoty'));

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM author WHERE display_name = ?');
        $stmt->execute(['MÁRQUEZ']);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'the truncated author record must be gone');
    }

    public function testTheSchoolAddressIsNotAWork(): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM work WHERE canon_id = ? AND title LIKE ?');
        $stmt->execute([$this->canonId, 'Gymnázium Jana Keplera%']);

        self::assertSame(0, (int) $stmt->fetchColumn());
    }
}
