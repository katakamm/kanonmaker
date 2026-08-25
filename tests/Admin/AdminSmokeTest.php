<?php

declare(strict_types=1);

namespace Kanon\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The administration must never be reachable without an admin account. */
final class AdminSmokeTest extends TestCase
{
    private const BASE = 'http://kata-admin.doma.slimak.cz';

    /** @return array{0: int, 1: string} */
    private function fetch(string $path): array
    {
        $context = stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 15, 'follow_location' => 0],
        ]);
        $body = @file_get_contents(self::BASE . $path, false, $context);

        if ($body === false && ($http_response_header ?? []) === []) {
            self::markTestSkipped('The admin site is not reachable from the test runner.');
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, (string) $body];
    }

    public static function protectedPages(): array
    {
        return [
            'dashboard' => ['/'],
            'review'    => ['/kontrola'],
            'works'     => ['/dila'],
            'rules'     => ['/pravidla'],
            'import'    => ['/import'],
            'users'     => ['/uzivatele'],
            'help'      => ['/napoveda'],
        ];
    }

    #[DataProvider('protectedPages')]
    public function testGuestsAreSentToLogin(string $path): void
    {
        [$status] = $this->fetch($path);

        self::assertSame(302, $status, "{$path} must not be readable by a guest");
    }

    public function testTheLoginPageIsReachable(): void
    {
        [$status, $body] = $this->fetch('/prihlaseni');

        self::assertSame(200, $status);
        self::assertStringContainsString('Přihlášení', $body);
    }

    public function testTheAdminSiteServesItsStylesheet(): void
    {
        [$status] = $this->fetch('/assets/tokens.css');

        self::assertSame(200, $status, 'the assets symlink is missing');
    }

    public function testNobodyCanRegisterOnTheAdminHost(): void
    {
        [$getStatus]  = $this->fetch('/registrace');
        [, $loginPage] = $this->fetch('/prihlaseni');

        self::assertSame(404, $getStatus, 'accounts on the admin host are created by an administrator');
        self::assertStringNotContainsString('Registrovat', $loginPage, 'and nothing invites you to try');
    }

    public function testAnUnknownAdminPathIsFourOhFour(): void
    {
        [$status] = $this->fetch('/tudy-ne');

        self::assertSame(404, $status);
    }
}
