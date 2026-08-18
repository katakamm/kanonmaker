<?php

declare(strict_types=1);

namespace Kanon\Tests\Http;

use Kanon\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->get('/', static fn (): string => 'home');
        $router->get('/kanon', static fn (): string => 'canon');
        $router->get('/dilo/{id}', static fn (array $p): string => 'work ' . $p['id']);
        $router->post('/seznam/pridat', static fn (): string => 'added');

        return $router;
    }

    public function testMatchesAStaticRoute(): void
    {
        $match = $this->router()->match('GET', '/kanon');

        self::assertNotNull($match);
        self::assertSame('canon', ($match['handler'])([]));
        self::assertSame([], $match['params']);
    }

    public function testExtractsAParameter(): void
    {
        $match = $this->router()->match('GET', '/dilo/417');

        self::assertNotNull($match);
        self::assertSame(['id' => '417'], $match['params']);
        self::assertSame('work 417', ($match['handler'])($match['params']));
    }

    public function testAParameterDoesNotMatchASlash(): void
    {
        self::assertNull($this->router()->match('GET', '/dilo/417/edit'));
    }

    public function testMethodMustMatch(): void
    {
        self::assertNull($this->router()->match('POST', '/kanon'));
        self::assertNotNull($this->router()->match('POST', '/seznam/pridat'));
    }

    public function testTrailingSlashIsIgnored(): void
    {
        self::assertNotNull($this->router()->match('GET', '/kanon/'));
    }

    public function testUnknownPathReturnsNull(): void
    {
        self::assertNull($this->router()->match('GET', '/neexistuje'));
    }
}
