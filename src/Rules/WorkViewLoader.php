<?php

declare(strict_types=1);

namespace Kanon\Rules;

final class WorkViewLoader
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @param  list<int> $workIds
     * @return list<WorkView> in the order the ids were given
     */
    public function load(array $workIds): array
    {
        if ($workIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($workIds), '?'));

        $stmt = $this->pdo->prepare("SELECT id, title FROM work WHERE id IN ({$placeholders})");
        $stmt->execute($workIds);
        $titles = [];
        foreach ($stmt->fetchAll() as $row) {
            $titles[(int) $row['id']] = $row['title'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT wa.work_id, a.id AS author_id, a.display_name
             FROM work_author wa JOIN author a ON a.id = wa.author_id
             WHERE wa.work_id IN ({$placeholders})"
        );
        $stmt->execute($workIds);
        $authors = [];
        foreach ($stmt->fetchAll() as $row) {
            $authors[(int) $row['work_id']][(int) $row['author_id']] = $row['display_name'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT wt.work_id, t.tag_group, t.code
             FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
             WHERE wt.work_id IN ({$placeholders})
             ORDER BY t.tag_group, t.sort_order"
        );
        $stmt->execute($workIds);
        $tags = [];
        foreach ($stmt->fetchAll() as $row) {
            $tags[(int) $row['work_id']][$row['tag_group']][] = $row['code'];
        }

        $views = [];
        foreach ($workIds as $id) {
            if (!isset($titles[$id])) {
                continue;
            }

            $views[] = new WorkView($id, $titles[$id], $authors[$id] ?? [], $tags[$id] ?? []);
        }

        return $views;
    }

    /**
     * @param  list<string> $matchKeys
     * @return list<WorkView>
     */
    public function loadByMatchKeys(int $canonId, array $matchKeys): array
    {
        if ($matchKeys === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($matchKeys), '?'));
        $stmt         = $this->pdo->prepare(
            "SELECT id, match_key FROM work WHERE canon_id = ? AND match_key IN ({$placeholders})"
        );
        $stmt->execute([$canonId, ...$matchKeys]);

        $idByKey = [];
        foreach ($stmt->fetchAll() as $row) {
            $idByKey[$row['match_key']] = (int) $row['id'];
        }

        $ids = [];
        foreach ($matchKeys as $key) {
            if (isset($idByKey[$key])) {
                $ids[] = $idByKey[$key];
            }
        }

        return $this->load($ids);
    }
}
