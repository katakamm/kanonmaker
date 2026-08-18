<?php

declare(strict_types=1);

namespace Kanon\Import;

use Kanon\Support\Normalize;

/**
 * Turns the committed snapshot plus the curated tag file into database rows.
 *
 * The import is idempotent: works are matched on a stable key, and a tag whose
 * source is "human" is never modified, so re-importing cannot undo a review.
 */
final class Importer
{
    /** Groups every work must end up with. */
    private const REQUIRED_GROUPS = ['obdobi', 'narodni', 'forma'];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly DocumentParser $parser,
        private readonly EntrySplitter $splitter,
        private readonly ChapterTagger $chapterTagger,
        private readonly HintTagger $hintTagger,
        private readonly CuratedTags $curated,
    ) {
    }

    public function import(int $canonId, string $htmlPath, string $csvPath, bool $dryRun = false): ImportReport
    {
        $html = file_get_contents($htmlPath);
        if ($html === false) {
            throw new \RuntimeException("Cannot read snapshot: {$htmlPath}");
        }

        $chapters = $this->parser->parse($html);
        $curated  = $this->curated->load($csvPath);
        $report   = new ImportReport();
        $tagIds   = $this->loadTagIds($canonId);
        $seen     = [];
        $sort     = 0;

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT kanon_import');
        }

        try {
            foreach ($chapters as $chapter) {
                $chapterId   = $this->upsertChapter($canonId, $chapter['name'], $chapter['sort_order'], $report);
                $chapterTags = $this->chapterTagger->tagsFor($chapter['name']);

                foreach ($chapter['entries'] as $entry) {
                    $report->entriesParsed++;

                    foreach ($this->splitter->split($entry) as $parsed) {
                        $sort++;
                        $key = $chapter['sort_order'] . '|' . $parsed->authorKey() . '|' . $parsed->titleKey();

                        if (isset($seen[$key])) {
                            $report->duplicateKeys++;
                            $key .= '-' . (++$seen[$key]);
                        }
                        $seen[$key] = 1;

                        $workId = $this->upsertWork($canonId, $chapterId, $key, $parsed, $sort, $report);
                        $this->syncAuthors($workId, $parsed, $report);

                        $documentTags = $chapterTags;
                        $hint         = $this->hintTagger->formFor($parsed->note);
                        if ($hint !== null && !$this->hasGroup($documentTags, 'forma')) {
                            $documentTags[] = $hint;
                        }

                        $this->syncTags($workId, $documentTags, 'document', 1, $tagIds, $report);
                        $this->syncTags($workId, $curated[$key] ?? [], 'inferred', 0, $tagIds, $report);
                    }
                }
            }

            if ($dryRun) {
                $ownsTransaction
                    ? $this->pdo->rollBack()
                    : $this->pdo->exec('ROLLBACK TO SAVEPOINT kanon_import');
            } elseif ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $report;
    }

    /** @return list<array{0:string,1:string,2:string,3:string,4:string}> */
    public function scaffoldRows(int $canonId): array
    {
        $sql = "SELECT w.id, w.match_key, w.title,
                       COALESCE(GROUP_CONCAT(DISTINCT a.display_name SEPARATOR '; '), '') AS authors
                FROM work w
                LEFT JOIN work_author wa ON wa.work_id = w.id
                LEFT JOIN author a ON a.id = wa.author_id
                WHERE w.canon_id = ?
                GROUP BY w.id, w.match_key, w.title
                ORDER BY w.sort_order";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$canonId]);
        $works = $stmt->fetchAll();

        $groupsStmt = $this->pdo->prepare(
            'SELECT t.tag_group, t.code FROM work_tag wt JOIN tag t ON t.id = wt.tag_id WHERE wt.work_id = ?'
        );

        $rows = [];
        foreach ($works as $work) {
            $groupsStmt->execute([(int) $work['id']]);
            $tags = $groupsStmt->fetchAll();

            $present = array_column($tags, 'tag_group');
            $codes   = array_column($tags, 'code');

            $required = self::REQUIRED_GROUPS;
            if (in_array('do18', $codes, true)) {
                $required[] = 'podobdobi';
            }

            foreach ($required as $group) {
                if (!in_array($group, $present, true)) {
                    $rows[] = [$work['match_key'], $work['authors'], $work['title'], $group, ''];
                }
            }
        }

        return $rows;
    }

    /** @return array<string, int> "group/code" => tag id */
    private function loadTagIds(int $canonId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, tag_group, code FROM tag WHERE canon_id = ?');
        $stmt->execute([$canonId]);

        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $ids[$row['tag_group'] . '/' . $row['code']] = (int) $row['id'];
        }

        return $ids;
    }

    private function upsertChapter(int $canonId, string $name, int $sortOrder, ImportReport $report): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM chapter WHERE canon_id = ? AND sort_order = ?');
        $stmt->execute([$canonId, $sortOrder]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            $this->pdo->prepare('UPDATE chapter SET name = ? WHERE id = ?')->execute([$name, (int) $id]);

            return (int) $id;
        }

        $this->pdo->prepare('INSERT INTO chapter (canon_id, name, sort_order) VALUES (?, ?, ?)')
            ->execute([$canonId, $name, $sortOrder]);
        $report->chaptersCreated++;

        return (int) $this->pdo->lastInsertId();
    }

    private function upsertWork(
        int $canonId,
        int $chapterId,
        string $matchKey,
        ParsedWork $parsed,
        int $sortOrder,
        ImportReport $report,
    ): int {
        $searchText = Normalize::text($parsed->displayAuthors() . ' ' . $parsed->title);

        $stmt = $this->pdo->prepare(
            'SELECT id, chapter_id, title, note, source_line, sort_order, search_text
             FROM work WHERE canon_id = ? AND match_key = ?'
        );
        $stmt->execute([$canonId, $matchKey]);
        $existing = $stmt->fetch();

        if ($existing === false) {
            $this->pdo->prepare(
                'INSERT INTO work (canon_id, chapter_id, title, note, source_line, sort_order, match_key, search_text)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $canonId, $chapterId, $parsed->title, $parsed->note,
                $parsed->sourceLine, $sortOrder, $matchKey, $searchText,
            ]);
            $report->worksCreated++;

            return (int) $this->pdo->lastInsertId();
        }

        $changed = (int) $existing['chapter_id'] !== $chapterId
            || $existing['title'] !== $parsed->title
            || $existing['note'] !== $parsed->note
            || $existing['source_line'] !== $parsed->sourceLine
            || (int) $existing['sort_order'] !== $sortOrder
            || $existing['search_text'] !== $searchText;

        if ($changed) {
            $this->pdo->prepare(
                'UPDATE work SET chapter_id = ?, title = ?, note = ?, source_line = ?, sort_order = ?, search_text = ?
                 WHERE id = ?'
            )->execute([
                $chapterId, $parsed->title, $parsed->note, $parsed->sourceLine,
                $sortOrder, $searchText, (int) $existing['id'],
            ]);
            $report->worksUpdated++;
        } else {
            $report->worksUnchanged++;
        }

        return (int) $existing['id'];
    }

    private function syncAuthors(int $workId, ParsedWork $parsed, ImportReport $report): void
    {
        $ids = [];
        foreach ($parsed->authors as $author) {
            $stmt = $this->pdo->prepare('SELECT id FROM author WHERE match_key = ?');
            $stmt->execute([$author->matchKey()]);
            $id = $stmt->fetchColumn();

            if ($id === false) {
                $this->pdo->prepare(
                    'INSERT INTO author (surname, first_name, display_name, match_key) VALUES (?, ?, ?, ?)'
                )->execute([$author->surname, $author->firstName, $author->display, $author->matchKey()]);
                $id = $this->pdo->lastInsertId();
                $report->authorsCreated++;
            }

            $ids[] = (int) $id;
        }

        $stmt = $this->pdo->prepare('SELECT author_id FROM work_author WHERE work_id = ?');
        $stmt->execute([$workId]);
        $current = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        foreach (array_diff($current, $ids) as $stale) {
            $this->pdo->prepare('DELETE FROM work_author WHERE work_id = ? AND author_id = ?')
                ->execute([$workId, $stale]);
        }

        foreach (array_diff($ids, $current) as $new) {
            $this->pdo->prepare('INSERT INTO work_author (work_id, author_id) VALUES (?, ?)')
                ->execute([$workId, $new]);
        }
    }

    /**
     * @param list<array{group: string, code: string}> $desired
     * @param array<string, int>                       $tagIds
     */
    private function syncTags(
        int $workId,
        array $desired,
        string $source,
        int $verified,
        array $tagIds,
        ImportReport $report,
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT wt.tag_id, wt.source, t.tag_group, t.code
             FROM work_tag wt JOIN tag t ON t.id = wt.tag_id WHERE wt.work_id = ?'
        );
        $stmt->execute([$workId]);

        $existing    = [];
        $humanGroups = [];
        foreach ($stmt->fetchAll() as $row) {
            $existing[(int) $row['tag_id']] = $row;
            if ($row['source'] === 'human') {
                $humanGroups[$row['tag_group']] = true;
            }
        }

        $wantedIds = [];
        foreach ($desired as $tag) {
            $lookup = $tag['group'] . '/' . $tag['code'];
            if (!isset($tagIds[$lookup])) {
                throw new \RuntimeException("Tag {$lookup} is not seeded for this canon");
            }

            $tagId       = $tagIds[$lookup];
            $wantedIds[] = $tagId;

            if (isset($humanGroups[$tag['group']])) {
                $report->tagsSkippedHuman++;
                continue;
            }

            if (isset($existing[$tagId])) {
                if ($existing[$tagId]['source'] !== $source) {
                    $this->pdo->prepare('UPDATE work_tag SET source = ?, verified = ? WHERE work_id = ? AND tag_id = ?')
                        ->execute([$source, $verified, $workId, $tagId]);
                }
                continue;
            }

            $this->pdo->prepare(
                'INSERT INTO work_tag (work_id, tag_id, source, verified) VALUES (?, ?, ?, ?)'
            )->execute([$workId, $tagId, $source, $verified]);

            $source === 'document' ? $report->tagsDocument++ : $report->tagsInferred++;
        }

        // Remove tags this source previously wrote but no longer wants.
        foreach ($existing as $tagId => $row) {
            if ($row['source'] === $source && !in_array($tagId, $wantedIds, true)) {
                $this->pdo->prepare('DELETE FROM work_tag WHERE work_id = ? AND tag_id = ?')
                    ->execute([$workId, $tagId]);
            }
        }
    }

    /** @param list<array{group: string, code: string}> $tags */
    private function hasGroup(array $tags, string $group): bool
    {
        foreach ($tags as $tag) {
            if ($tag['group'] === $group) {
                return true;
            }
        }

        return false;
    }
}
