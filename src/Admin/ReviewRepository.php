<?php

declare(strict_types=1);

namespace Kanon\Admin;

/**
 * The review queue.
 *
 * Tags the importer inferred are grouped by chapter and by the value inferred,
 * so a reviewer can accept 41 works in one action instead of 41. Groups are
 * ordered by consequence: the sub-period and the Czech-poetry flag drive the two
 * rules a student cannot check by hand, so they are reviewed first.
 */
final class ReviewRepository
{
    private const GROUP_PRIORITY = ['podobdobi' => 1, 'special' => 2, 'forma' => 3, 'narodni' => 4, 'obdobi' => 5];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return list<array{chapter_id: int, chapter: string, tag_group: string, code: string,
     *                    label: string, count: int, works: list<array{id: int, title: string, authors: string}>}>
     */
    public function groups(int $canonId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id AS chapter_id, COALESCE(c.short_name, c.name) AS chapter, t.tag_group, t.code, t.label,
                    w.id AS work_id, w.title, w.sort_order,
                    COALESCE(GROUP_CONCAT(DISTINCT a.display_name SEPARATOR '; '), '') AS authors
             FROM work_tag wt
             JOIN tag t ON t.id = wt.tag_id
             JOIN work w ON w.id = wt.work_id
             JOIN chapter c ON c.id = w.chapter_id
             LEFT JOIN work_author wa ON wa.work_id = w.id
             LEFT JOIN author a ON a.id = wa.author_id
             WHERE w.canon_id = ? AND wt.verified = 0
             GROUP BY c.id, c.short_name, c.name, t.tag_group, t.code, t.label, w.id, w.title, w.sort_order
             ORDER BY c.sort_order, t.tag_group, t.sort_order, w.sort_order"
        );
        $stmt->execute([$canonId]);

        $groups = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = $row['chapter_id'] . '|' . $row['tag_group'] . '|' . $row['code'];

            $groups[$key] ??= [
                'chapter_id' => (int) $row['chapter_id'],
                'chapter'    => $row['chapter'],
                'tag_group'  => $row['tag_group'],
                'code'       => $row['code'],
                'label'      => $row['label'],
                'count'      => 0,
                'works'      => [],
            ];

            $groups[$key]['count']++;
            $groups[$key]['works'][] = [
                'id'      => (int) $row['work_id'],
                'title'   => $row['title'],
                'authors' => $row['authors'],
            ];
        }

        $groups = array_values($groups);

        usort($groups, static function (array $a, array $b): int {
            $pa = self::GROUP_PRIORITY[$a['tag_group']] ?? 9;
            $pb = self::GROUP_PRIORITY[$b['tag_group']] ?? 9;

            return [$pa, $a['chapter_id'], $a['code']] <=> [$pb, $b['chapter_id'], $b['code']];
        });

        return $groups;
    }

    public function confirmGroup(int $canonId, int $chapterId, string $tagGroup, string $code): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE work_tag wt
             JOIN tag t ON t.id = wt.tag_id
             JOIN work w ON w.id = wt.work_id
             SET wt.source = 'human', wt.verified = 1
             WHERE w.canon_id = ? AND w.chapter_id = ? AND t.tag_group = ? AND t.code = ? AND wt.verified = 0"
        );
        $stmt->execute([$canonId, $chapterId, $tagGroup, $code]);

        return $stmt->rowCount();
    }

    public function confirmOne(int $workId, int $tagId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE work_tag SET source = 'human', verified = 1
             WHERE work_id = ? AND tag_id = ? AND verified = 0"
        );
        $stmt->execute([$workId, $tagId]);

        return $stmt->rowCount() > 0;
    }

    /** Replaces whatever the work had in this group with a human-confirmed value. */
    public function replaceTag(int $canonId, int $workId, string $tagGroup, string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM tag WHERE canon_id = ? AND tag_group = ? AND code = ?');
        $stmt->execute([$canonId, $tagGroup, $code]);
        $tagId = $stmt->fetchColumn();

        if ($tagId === false) {
            return false;
        }

        $this->pdo->prepare(
            'DELETE wt FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
             WHERE wt.work_id = ? AND t.tag_group = ?'
        )->execute([$workId, $tagGroup]);

        $this->pdo->prepare(
            "INSERT INTO work_tag (work_id, tag_id, source, verified) VALUES (?, ?, 'human', 1)"
        )->execute([$workId, (int) $tagId]);

        return true;
    }

    public function unverifiedCount(int $canonId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM work_tag wt JOIN work w ON w.id = wt.work_id
             WHERE w.canon_id = ? AND wt.verified = 0'
        );
        $stmt->execute([$canonId]);

        return (int) $stmt->fetchColumn();
    }
}
