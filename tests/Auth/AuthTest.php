<?php

declare(strict_types=1);

namespace Kanon\Tests\Auth;

use Kanon\Auth\Auth;
use Kanon\Auth\LoginThrottle;
use Kanon\Auth\UserRepository;
use Kanon\Db\Database;
use Kanon\Db\Migrator;
use Kanon\Http\ArraySession;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    private \PDO $pdo;
    private UserRepository $users;
    private LoginThrottle $throttle;
    private string $email;

    protected function setUp(): void
    {
        $root      = dirname(__DIR__, 2);
        $config    = require $root . '/config.php';
        $this->pdo = Database::connect($config['db']);
        (new Migrator($this->pdo, $root . '/db/migrations'))->migrate();

        $this->pdo->beginTransaction();

        $this->users    = new UserRepository($this->pdo);
        $this->throttle = new LoginThrottle($this->pdo);
        $this->email    = 'student' . bin2hex(random_bytes(4)) . '@example.test';
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function auth(): Auth
    {
        return new Auth($this->users, $this->throttle, new ArraySession());
    }

    public function testTheStoredPasswordIsHashedNotThePlainText(): void
    {
        $id   = $this->users->create($this->email, 'tajneheslo123', 'Kata');
        $user = $this->users->findById($id);

        self::assertNotNull($user);
        self::assertNotSame('tajneheslo123', $user['password_hash']);
        self::assertTrue(password_verify('tajneheslo123', $user['password_hash']));
    }

    public function testEmailIsCaseInsensitive(): void
    {
        // Must not be a fixed address: real accounts exist in this database.
        $mixedCase = 'Kata' . bin2hex(random_bytes(4)) . '@Example.Test';
        $this->users->create($mixedCase, 'tajneheslo123', 'Kata');

        self::assertNotNull($this->users->findByEmail(mb_strtolower($mixedCase)));
        self::assertTrue($this->users->exists(mb_strtoupper($mixedCase)));
    }

    public function testAttemptSucceedsWithTheRightPasswordAndFailsOtherwise(): void
    {
        $this->users->create($this->email, 'tajneheslo123', 'Kata');
        $auth = $this->auth();

        self::assertTrue($auth->attempt($this->email, 'tajneheslo123'));
        self::assertTrue($auth->check());
        self::assertSame($this->email, $auth->user()['email']);

        self::assertFalse($this->auth()->attempt($this->email, 'spatneheslo'));
        self::assertFalse($this->auth()->attempt('nikdo@example.test', 'tajneheslo123'));
    }

    public function testLogoutForgetsTheUser(): void
    {
        $this->users->create($this->email, 'tajneheslo123', 'Kata');
        $auth = $this->auth();
        $auth->attempt($this->email, 'tajneheslo123');

        $auth->logout();

        self::assertFalse($auth->check());
        self::assertNull($auth->user());
    }

    public function testNewAccountsAreStudentsNotAdmins(): void
    {
        $this->users->create($this->email, 'tajneheslo123', 'Kata');
        $auth = $this->auth();
        $auth->attempt($this->email, 'tajneheslo123');

        self::assertFalse($auth->isAdmin());
    }

    public function testAnAdminAccountIsRecognised(): void
    {
        $this->users->create($this->email, 'tajneheslo123', 'Kata', 'admin');
        $auth = $this->auth();
        $auth->attempt($this->email, 'tajneheslo123');

        self::assertTrue($auth->isAdmin());
    }

    public function testRepeatedFailuresLockTheAccountOut(): void
    {
        $this->users->create($this->email, 'tajneheslo123', 'Kata');

        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
            self::assertFalse($this->auth()->attempt($this->email, 'spatneheslo'));
        }

        self::assertTrue($this->throttle->tooMany($this->email));
        self::assertFalse(
            $this->auth()->attempt($this->email, 'tajneheslo123'),
            'even the correct password is refused while locked out'
        );
    }

    public function testASuccessfulLoginClearsTheFailureCount(): void
    {
        $this->users->create($this->email, 'tajneheslo123', 'Kata');

        $this->auth()->attempt($this->email, 'spatneheslo');
        $this->auth()->attempt($this->email, 'tajneheslo123');

        self::assertFalse($this->throttle->tooMany($this->email));
    }

    public function testOldFailuresFallOutOfTheWindow(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempt (email, attempted_at) VALUES (?, NOW() - INTERVAL ? MINUTE)'
        );
        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS + 5; $i++) {
            $stmt->execute([$this->email, LoginThrottle::WINDOW_MINUTES + 1]);
        }

        self::assertFalse($this->throttle->tooMany($this->email), 'stale failures must not lock anyone out');
    }
}
