<?php

declare(strict_types=1);

namespace Kanon\Repo;

final class ListRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** Returns the student's list for this canon, creating it on first use. */
    public function forUser(int $userId, int $canonId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM list WHERE user_id = ? AND canon_id = ?');
        $stmt->execute([$userId, $canonId]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $this->pdo->prepare(
            'INSERT INTO list (user_id, canon_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())'
        )->execute([$userId, $canonId]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<int> */
    public function workIds(int $listId): array
    {
        $stmt = $this->pdo->prepare('SELECT work_id FROM list_item WHERE list_id = ? ORDER BY position');
        $stmt->execute([$listId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function add(int $listId, int $workId): bool
    {
        if ($this->contains($listId, $workId)) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM list_item WHERE list_id = ?');
        $stmt->execute([$listId]);
        $position = (int) $stmt->fetchColumn();

        $this->pdo->prepare('INSERT INTO list_item (list_id, work_id, position) VALUES (?, ?, ?)')
            ->execute([$listId, $workId, $position]);
        $this->touch($listId);

        return true;
    }

    public function remove(int $listId, int $workId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM list_item WHERE list_id = ? AND work_id = ?');
        $stmt->execute([$listId, $workId]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        $this->touch($listId);

        return true;
    }

    public function contains(int $listId, int $workId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM list_item WHERE list_id = ? AND work_id = ?');
        $stmt->execute([$listId, $workId]);

        return $stmt->fetchColumn() !== false;
    }

    public function count(int $listId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM list_item WHERE list_id = ?');
        $stmt->execute([$listId]);

        return (int) $stmt->fetchColumn();
    }

    private function touch(int $listId): void
    {
        $this->pdo->prepare('UPDATE list SET updated_at = NOW() WHERE id = ?')->execute([$listId]);
    }
}
