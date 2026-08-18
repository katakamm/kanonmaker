<?php

declare(strict_types=1);

namespace Kanon\Tests\Admin;

use Kanon\Admin\AdminRepository;
use Kanon\Auth\UserRepository;
use Kanon\Db\Database;
use PHPUnit\Framework\TestCase;

final class UserAdminTest extends TestCase
{
    private \PDO $pdo;
    private AdminRepository $repo;
    private UserRepository $users;
    private int $userId;

    protected function setUp(): void
    {
        $config    = require dirname(__DIR__, 2) . '/config.php';
        $this->pdo = Database::connect($config['db']);
        $this->pdo->beginTransaction();

        $this->repo   = new AdminRepository($this->pdo);
        $this->users  = new UserRepository($this->pdo);
        $this->userId = $this->users->create(
            'u' . bin2hex(random_bytes(4)) . '@example.test',
            'tajneheslo123',
            'Testovací student'
        );
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function find(int $id): ?array
    {
        foreach ($this->repo->students() as $student) {
            if ($student['id'] === $id) {
                return $student;
            }
        }

        return null;
    }

    public function testTheListIncludesTheNewAccount(): void
    {
        $student = $this->find($this->userId);

        self::assertNotNull($student);
        self::assertSame('Testovací student', $student['display_name']);
        self::assertSame('student', $student['role']);
        self::assertTrue($student['active']);
        self::assertSame(0, $student['works'], 'a fresh account has an empty list');
    }

    public function testPromotingAndDemoting(): void
    {
        self::assertTrue($this->repo->setRole($this->userId, 'admin'));
        self::assertSame('admin', $this->find($this->userId)['role']);

        self::assertTrue($this->repo->setRole($this->userId, 'student'));
        self::assertSame('student', $this->find($this->userId)['role']);
    }

    public function testAnUnknownRoleIsRefused(): void
    {
        self::assertFalse($this->repo->setRole($this->userId, 'reditel'));
        self::assertSame('student', $this->find($this->userId)['role']);
    }

    public function testDeactivatingBlocksLogin(): void
    {
        self::assertTrue($this->repo->setActive($this->userId, false));
        self::assertFalse($this->find($this->userId)['active']);

        $user = $this->users->findById($this->userId);
        self::assertSame(0, (int) $user['active']);
    }
}
