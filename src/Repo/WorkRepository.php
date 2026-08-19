<?php

declare(strict_types=1);

namespace Kanon\Repo;

use Kanon\Support\Normalize;

/**
 * The only place that queries works. Every method returns fully hydrated works —
 * authors and tags included — so no template ever issues a query of its own.
 */
final class WorkRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @return list<array> */
    public function search(int $canonId, string $query, int $limit = 40): array
    {
        $words = array_values(array_filter(explode(' ', Normalize::text($query))));

        if ($words === []) {
            return [];
        }

        $where  = ['w.canon_id = ?'];
        $params = [$canonId];

        foreach ($words as $word) {
            $where[]  = 'w.search_text LIKE ?';
            $params[] = '%' . $word . '%';
        }

        $sql = 'SELECT w.* FROM work w WHERE ' . implode(' AND ', $where)
             . ' ORDER BY w.sort_order LIMIT ' . max(1, $limit);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->hydrate($stmt->fetchAll());
    }

    /** @return list<array> */
    public function browse(
        int $canonId,
        ?int $chapterId = null,
        ?string $tagGroup = null,
        ?string $tagCode = null,
    ): array {
        $where  = ['w.canon_id = ?'];
        $params = [$canonId];

        if ($chapterId !== null) {
            $where[]  = 'w.chapter_id = ?';
            $params[] = $chapterId;
        }

        if ($tagGroup !== null && $tagCode !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
                                WHERE wt.work_id = w.id AND t.tag_group = ? AND t.code = ?)';
            $params[] = $tagGroup;
            $params[] = $tagCode;
        }

        $stmt = $this->pdo->prepare(
            'SELECT w.* FROM work w WHERE ' . implode(' AND ', $where) . ' ORDER BY w.sort_order'
        );
        $stmt->execute($params);

        return $this->hydrate($stmt->fetchAll());
    }

    public function find(int $canonId, int $workId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT w.* FROM work w WHERE w.canon_id = ? AND w.id = ?');
        $stmt->execute([$canonId, $workId]);

        $rows = $this->hydrate($stmt->fetchAll());

        return $rows[0] ?? null;
    }

    /**
     * @param  list<int> $workIds
     * @return list<array> in the order the ids were given
     */
    public function findMany(int $canonId, array $workIds): array
    {
        if ($workIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($workIds), '?'));
        $stmt         = $this->pdo->prepare(
            "SELECT w.* FROM work w WHERE w.canon_id = ? AND w.id IN ({$placeholders})"
        );
        $stmt->execute([$canonId, ...$workIds]);

        $byId = [];
        foreach ($this->hydrate($stmt->fetchAll()) as $work) {
            $byId[$work['id']] = $work;
        }

        $ordered = [];
        foreach ($workIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /** @return list<array{id: int, name: string, sort_order: int, works: int}> */
    public function chapters(int $canonId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, COALESCE(c.short_name, c.name) AS name, c.sort_order, COUNT(w.id) AS works
             FROM chapter c LEFT JOIN work w ON w.chapter_id = c.id
             WHERE c.canon_id = ?
             GROUP BY c.id, c.short_name, c.name, c.sort_order
             ORDER BY c.sort_order'
        );
        $stmt->execute([$canonId]);

        return array_map(
            static fn (array $r): array => [
                'id'         => (int) $r['id'],
                'name'       => $r['name'],
                'sort_order' => (int) $r['sort_order'],
                'works'      => (int) $r['works'],
            ],
            $stmt->fetchAll()
        );
    }

    /** @return array<string, list<array{code: string, label: string}>> */
    public function tagGroups(int $canonId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tag_group, code, label FROM tag WHERE canon_id = ? ORDER BY tag_group, sort_order'
        );
        $stmt->execute([$canonId]);

        $groups = [];
        foreach ($stmt->fetchAll() as $row) {
            $groups[$row['tag_group']][] = ['code' => $row['code'], 'label' => $row['label']];
        }

        return $groups;
    }

    /**
     * @param  list<array> $rows raw work rows
     * @return list<array>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids          = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT wa.work_id, a.display_name
             FROM work_author wa JOIN author a ON a.id = wa.author_id
             WHERE wa.work_id IN ({$placeholders})
             ORDER BY a.surname"
        );
        $stmt->execute($ids);
        $authors = [];
        foreach ($stmt->fetchAll() as $row) {
            $authors[(int) $row['work_id']][] = $row['display_name'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT wt.work_id, wt.verified, t.tag_group, t.code, t.label
             FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
             WHERE wt.work_id IN ({$placeholders})
             ORDER BY t.tag_group, t.sort_order"
        );
        $stmt->execute($ids);
        $tags = [];
        foreach ($stmt->fetchAll() as $row) {
            $tags[(int) $row['work_id']][$row['tag_group']][] = [
                'code'     => $row['code'],
                'label'    => $row['label'],
                'verified' => (int) $row['verified'] === 1,
            ];
        }

        $stmt = $this->pdo->prepare(
            "SELECT c.id, COALESCE(c.short_name, c.name) AS name FROM chapter c WHERE c.id IN (
                SELECT chapter_id FROM work WHERE id IN ({$placeholders}))"
        );
        $stmt->execute($ids);
        $chapterNames = [];
        foreach ($stmt->fetchAll() as $row) {
            $chapterNames[(int) $row['id']] = $row['name'];
        }

        $works = [];
        foreach ($rows as $row) {
            $id      = (int) $row['id'];
            $works[] = [
                'id'         => $id,
                'title'      => $row['title'],
                'note'       => $row['note'],
                'chapter_id' => (int) $row['chapter_id'],
                'chapter'    => $chapterNames[(int) $row['chapter_id']] ?? '',
                'authors'    => implode('; ', $authors[$id] ?? []),
                'tags'       => $tags[$id] ?? [],
            ];
        }

        return $works;
    }
}
