<?php

declare(strict_types=1);

namespace Kanon\Tests\Import;

use Kanon\Db\Database;
use Kanon\Db\Migrator;
use Kanon\Import\ChapterTagger;
use Kanon\Import\CuratedTags;
use Kanon\Import\DocumentParser;
use Kanon\Import\EntrySplitter;
use Kanon\Import\HintTagger;
use Kanon\Import\Importer;
use PHPUnit\Framework\TestCase;

final class ImporterTest extends TestCase
{
    private \PDO $pdo;
    private int $canonId;
    private string $html;
    private string $csv;

    protected function setUp(): void
    {
        $root      = dirname(__DIR__, 2);
        $config    = require $root . '/config.php';
        $this->pdo = Database::connect($config['db']);
        (new Migrator($this->pdo, $root . '/db/migrations'))->migrate();

        $this->html = dirname(__DIR__) . '/fixtures/canon-sample.html';
        $this->csv  = dirname(__DIR__) . '/fixtures/tags-sample.csv';

        $this->pdo->beginTransaction();

        // Import into a throwaway canon of its own, never the real 2025/2026 one.
        // Sharing it would make these assertions depend on whether the real
        // canon happens to have been imported yet - and it already did once.
        $realCanonId = (int) $this->pdo->query(
            "SELECT id FROM canon WHERE school_year = '2025/2026'"
        )->fetchColumn();

        $this->pdo->exec("INSERT INTO school (name) VALUES ('Testovací škola')");
        $schoolId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO canon (school_id, school_year) VALUES (?, ?)')
            ->execute([$schoolId, 'test/0000']);
        $this->canonId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO tag (canon_id, tag_group, code, label, sort_order)
             SELECT ?, tag_group, code, label, sort_order FROM tag WHERE canon_id = ?'
        )->execute([$this->canonId, $realCanonId]);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function importer(): Importer
    {
        return new Importer(
            $this->pdo,
            new DocumentParser(),
            new EntrySplitter(),
            new ChapterTagger(),
            new HintTagger(),
            new CuratedTags(),
        );
    }

    private function workId(string $matchKey): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM work WHERE canon_id = ? AND match_key = ?');
        $stmt->execute([$this->canonId, $matchKey]);
        $id = $stmt->fetchColumn();

        self::assertNotFalse($id, "work {$matchKey} was not imported");

        return (int) $id;
    }

    /** @return list<string> "group/code" for one work */
    private function tagsOf(int $workId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.tag_group, t.code FROM work_tag wt JOIN tag t ON t.id = wt.tag_id WHERE wt.work_id = ?'
        );
        $stmt->execute([$workId]);

        return array_map(
            static fn (array $r): string => $r['tag_group'] . '/' . $r['code'],
            $stmt->fetchAll()
        );
    }

    public function testImportsEveryWorkFromTheFixture(): void
    {
        $report = $this->importer()->import($this->canonId, $this->html, $this->csv);

        self::assertSame(8, $report->entriesParsed);
        self::assertSame(12, $report->worksCreated, 'seven works in chapter one, five in chapter two');
        self::assertSame(2, $report->chaptersCreated);
    }

    public function testSemicolonEntriesBecomeSeparateWorks(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);

        foreach (['1|shakespeare-william|hamlet', '1|shakespeare-william|kral-lear', '1|shakespeare-william|macbeth'] as $key) {
            $this->workId($key);
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM work w JOIN work_author wa ON wa.work_id = w.id
             JOIN author a ON a.id = wa.author_id
             WHERE w.canon_id = ? AND a.match_key = ?'
        );
        $stmt->execute([$this->canonId, 'shakespeare-william']);

        self::assertSame(3, (int) $stmt->fetchColumn());
    }

    public function testAuthorlessWorkGetsNoAuthorAndKeepsItsHint(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);
        $id = $this->workId('1|anon|beowulf');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM work_author WHERE work_id = ?');
        $stmt->execute([$id]);
        self::assertSame(0, (int) $stmt->fetchColumn());

        self::assertContains('forma/poezie', $this->tagsOf($id), 'the (poezie) hint must become a tag');
        self::assertContains('obdobi/do18', $this->tagsOf($id), 'the chapter must give the period');
    }

    public function testCoAuthoredWorkLinksEveryAuthor(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);
        $id = $this->workId('2|baban-dzian+masek-vojtech+grus-jiri|ve-stinu-sumavskych-hvozdu');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM work_author WHERE work_id = ?');
        $stmt->execute([$id]);

        self::assertSame(3, (int) $stmt->fetchColumn());
    }

    public function testCuratedTagsAreAppliedAsInferredAndUnverified(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);
        $id = $this->workId('1|aischylos|oresteia');

        self::assertContains('podobdobi/starovek', $this->tagsOf($id));

        $stmt = $this->pdo->prepare(
            "SELECT wt.source, wt.verified FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
             WHERE wt.work_id = ? AND t.tag_group = 'podobdobi'"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        self::assertSame('inferred', $row['source']);
        self::assertSame(0, (int) $row['verified']);
    }

    public function testChapterTagsAreStoredAsDocumentAndVerified(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);
        $id = $this->workId('1|aischylos|oresteia');

        $stmt = $this->pdo->prepare(
            "SELECT wt.source, wt.verified FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
             WHERE wt.work_id = ? AND t.tag_group = 'obdobi'"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        self::assertSame('document', $row['source']);
        self::assertSame(1, (int) $row['verified']);
    }

    public function testSecondRunCreatesNothing(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);
        $second = $this->importer()->import($this->canonId, $this->html, $this->csv);

        self::assertSame(0, $second->worksCreated);
        self::assertSame(0, $second->chaptersCreated);
        self::assertSame(12, $second->worksUnchanged);
    }

    public function testHumanTagSurvivesReimport(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);
        $id = $this->workId('1|aischylos|oresteia');

        // A reviewer overrules the curated guess: not starověk but středověk.
        $this->pdo->prepare(
            "DELETE wt FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
             WHERE wt.work_id = ? AND t.tag_group = 'podobdobi'"
        )->execute([$id]);

        $stmt = $this->pdo->prepare(
            "SELECT id FROM tag WHERE canon_id = ? AND tag_group = 'podobdobi' AND code = 'stredovek'"
        );
        $stmt->execute([$this->canonId]);
        $tagId = (int) $stmt->fetchColumn();

        $this->pdo->prepare(
            "INSERT INTO work_tag (work_id, tag_id, source, verified) VALUES (?, ?, 'human', 1)"
        )->execute([$id, $tagId]);

        $report = $this->importer()->import($this->canonId, $this->html, $this->csv);

        self::assertContains('podobdobi/stredovek', $this->tagsOf($id), 'the human decision must stand');
        self::assertNotContains('podobdobi/starovek', $this->tagsOf($id));
        self::assertGreaterThan(0, $report->tagsSkippedHuman);
    }

    public function testAChaptersDisplayNameSurvivesReimport(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);

        $this->pdo->prepare('UPDATE chapter SET short_name = ? WHERE canon_id = ? AND sort_order = 1')
            ->execute(['Zkrácený název', $this->canonId]);

        $this->importer()->import($this->canonId, $this->html, $this->csv);

        $stmt = $this->pdo->prepare('SELECT name, short_name FROM chapter WHERE canon_id = ? AND sort_order = 1');
        $stmt->execute([$this->canonId]);
        $chapter = $stmt->fetch();

        self::assertSame('Zkrácený název', $chapter['short_name'], 'the display name is ours to keep');
        self::assertStringContainsString(
            'Světová a česká literatura',
            $chapter['name'],
            'while the document heading stays verbatim, because the import matches on it'
        );
    }

    public function testDryRunChangesNothing(): void
    {
        $report = $this->importer()->import($this->canonId, $this->html, $this->csv, true);

        self::assertSame(12, $report->worksCreated, 'the report still describes what would happen');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM work WHERE canon_id = ?');
        $stmt->execute([$this->canonId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'but nothing is written');
    }

    public function testScaffoldListsWorksMissingRequiredGroups(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);
        $rows = $this->importer()->scaffoldRows($this->canonId);

        $keys = array_map(static fn (array $r): string => $r[0] . ' ' . $r[3], $rows);

        self::assertContains('1|rimbaud-arthur|sezona-v-pekle-iluminace narodni', $keys, 'Rimbaud is uncurated');
        self::assertContains('1|rimbaud-arthur|sezona-v-pekle-iluminace forma', $keys);
        self::assertNotContains('1|anon|beowulf forma', $keys, 'its form came from the (poezie) hint');
        self::assertNotContains('1|anon|beowulf narodni', $keys, 'the curated file supplied it');
        self::assertNotContains('1|aischylos|oresteia podobdobi', $keys, 'already curated');
    }
}
