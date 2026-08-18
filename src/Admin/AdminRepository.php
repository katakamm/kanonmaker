<?php

declare(strict_types=1);

namespace Kanon\Admin;

use Kanon\Rules\RuleFactory;

final class AdminRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return array{works: int, authors: int, tags_total: int, tags_unverified: int,
     *               students: int, lists: int}
     */
    public function stats(int $canonId): array
    {
        $one = function (string $sql, array $params = []): int {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return (int) $stmt->fetchColumn();
        };

        return [
            'works'   => $one('SELECT COUNT(*) FROM work WHERE canon_id = ?', [$canonId]),
            'authors' => $one(
                'SELECT COUNT(DISTINCT wa.author_id) FROM work_author wa
                 JOIN work w ON w.id = wa.work_id WHERE w.canon_id = ?',
                [$canonId]
            ),
            'tags_total' => $one(
                'SELECT COUNT(*) FROM work_tag wt JOIN work w ON w.id = wt.work_id WHERE w.canon_id = ?',
                [$canonId]
            ),
            'tags_unverified' => $one(
                'SELECT COUNT(*) FROM work_tag wt JOIN work w ON w.id = wt.work_id
                 WHERE w.canon_id = ? AND wt.verified = 0',
                [$canonId]
            ),
            'students' => $one("SELECT COUNT(*) FROM user WHERE role = 'student'"),
            'lists'    => $one('SELECT COUNT(*) FROM list WHERE canon_id = ?', [$canonId]),
        ];
    }

    /** @return list<array{id:int, type:string, params:array, label:string, enabled:bool, sort_order:int}> */
    public function rules(int $canonId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rule WHERE canon_id = ? ORDER BY sort_order');
        $stmt->execute([$canonId]);

        return array_map(
            static fn (array $r): array => [
                'id'         => (int) $r['id'],
                'type'       => $r['type'],
                'params'     => json_decode($r['params'], true, 512, JSON_THROW_ON_ERROR),
                'label'      => $r['label'],
                'enabled'    => (int) $r['enabled'] === 1,
                'sort_order' => (int) $r['sort_order'],
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Rejects any edit the rules engine could not evaluate, so a typo in the
     * administration can never reach a student's rule check.
     */
    public function updateRule(int $ruleId, array $params, bool $enabled): bool
    {
        $stmt = $this->pdo->prepare('SELECT type, label FROM rule WHERE id = ?');
        $stmt->execute([$ruleId]);
        $rule = $stmt->fetch();

        if ($rule === false) {
            return false;
        }

        $json = json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        try {
            RuleFactory::fromRow(['type' => $rule['type'], 'params' => $json, 'label' => $rule['label']])
                ->evaluate([]);
        } catch (\Throwable) {
            return false;
        }

        $this->pdo->prepare('UPDATE rule SET params = ?, enabled = ? WHERE id = ?')
            ->execute([$json, $enabled ? 1 : 0, $ruleId]);

        return true;
    }

    public function updateWork(int $canonId, int $workId, string $title, ?string $note): bool
    {
        if (trim($title) === '') {
            return false;
        }

        $stmt = $this->pdo->prepare('UPDATE work SET title = ?, note = ? WHERE canon_id = ? AND id = ?');
        $stmt->execute([trim($title), $note === null || trim($note) === '' ? null : trim($note), $canonId, $workId]);

        return true;
    }

    /**
     * @return list<array{id:int, email:string, display_name:string, role:string,
     *                    active:bool, created_at:string, works:int}>
     */
    public function students(): array
    {
        $stmt = $this->pdo->query(
            'SELECT u.id, u.email, u.display_name, u.role, u.active, u.created_at,
                    COALESCE(COUNT(li.work_id), 0) AS works
             FROM user u
             LEFT JOIN list l ON l.user_id = u.id
             LEFT JOIN list_item li ON li.list_id = l.id
             GROUP BY u.id, u.email, u.display_name, u.role, u.active, u.created_at
             ORDER BY u.created_at DESC'
        );

        return array_map(
            static fn (array $r): array => [
                'id'           => (int) $r['id'],
                'email'        => $r['email'],
                'display_name' => $r['display_name'],
                'role'         => $r['role'],
                'active'       => (int) $r['active'] === 1,
                'created_at'   => $r['created_at'],
                'works'        => (int) $r['works'],
            ],
            $stmt->fetchAll()
        );
    }

    public function setRole(int $userId, string $role): bool
    {
        if (!in_array($role, ['student', 'admin'], true)) {
            return false;
        }

        $this->pdo->prepare('UPDATE user SET role = ? WHERE id = ?')->execute([$role, $userId]);

        return true;
    }

    public function setActive(int $userId, bool $active): bool
    {
        $this->pdo->prepare('UPDATE user SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $userId]);

        return true;
    }
}
