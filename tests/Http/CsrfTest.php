<?php

declare(strict_types=1);

namespace Kanon\Tests\Http;

use Kanon\Http\ArraySession;
use Kanon\Http\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testTheTokenIsStableWithinASession(): void
    {
        $csrf = new Csrf(new ArraySession());

        self::assertSame($csrf->token(), $csrf->token());
    }

    public function testDifferentSessionsGetDifferentTokens(): void
    {
        self::assertNotSame(
            (new Csrf(new ArraySession()))->token(),
            (new Csrf(new ArraySession()))->token()
        );
    }

    public function testTheTokenIsLongEnoughToResistGuessing(): void
    {
        self::assertGreaterThanOrEqual(32, strlen((new Csrf(new ArraySession()))->token()));
    }

    public function testCheckAcceptsTheRealTokenAndRejectsEverythingElse(): void
    {
        $csrf  = new Csrf(new ArraySession());
        $token = $csrf->token();

        self::assertTrue($csrf->check($token));
        self::assertFalse($csrf->check('wrong'));
        self::assertFalse($csrf->check(''));
        self::assertFalse($csrf->check(null));
    }

    public function testFlashesComeBackOnceAndThenAreGone(): void
    {
        $session = new ArraySession();
        $session->flash('ok', 'Uloženo');

        self::assertSame([['type' => 'ok', 'message' => 'Uloženo']], $session->takeFlashes());
        self::assertSame([], $session->takeFlashes());
    }
}
