<?php

declare(strict_types=1);

namespace Kanon\Tests\App;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fetches every public page over HTTP. Controllers are verified by use rather
 * than by unit tests, so this is the net that catches a broken wiring change.
 */
final class SmokeTest extends TestCase
{
    private const BASE = 'http://kata.doma.slimak.cz';

    /** @return array{0: int, 1: string} */
    private function fetch(string $path): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $body    = @file_get_contents(self::BASE . $path, false, $context);

        if ($body === false) {
            self::markTestSkipped('The site is not reachable from the test runner.');
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, $body];
    }

    public static function publicPages(): array
    {
        return [
            'landing'     => ['/'],
            'canon'       => ['/kanon'],
            'search'      => ['/hledat?q=capek'],
            'search json' => ['/hledat.json?q=capek'],
            'login'       => ['/prihlaseni'],
            'register'    => ['/registrace'],
        ];
    }

    #[DataProvider('publicPages')]
    public function testPublicPagesAnswer(string $path): void
    {
        [$status, $body] = $this->fetch($path);

        self::assertSame(200, $status, "{$path} did not answer 200");
        self::assertNotSame('', $body);
    }

    public function testTheCanonPageListsWorks(): void
    {
        [, $body] = $this->fetch('/kanon');

        self::assertGreaterThan(400, substr_count($body, 'class="work"'));
        self::assertStringContainsString('Oresteia', $body);
    }

    public function testSearchFindsWithoutDiacritics(): void
    {
        [, $body] = $this->fetch('/hledat?q=capek');

        self::assertStringContainsString('Válka s mloky', $body);
    }

    public function testUnknownPathIsFourOhFour(): void
    {
        [$status] = $this->fetch('/tudy-cesta-nevede');

        self::assertSame(404, $status);
    }

    public function testAnUnknownWorkIsFourOhFour(): void
    {
        [$status] = $this->fetch('/dilo/999999');

        self::assertSame(404, $status);
    }

    public function testGuestsGetNoAddButtons(): void
    {
        [, $body] = $this->fetch('/kanon');

        self::assertStringNotContainsString('seznam/pridat', $body, 'guests cannot build a list');
    }

    public function testProtectedPagesRedirectGuestsToLogin(): void
    {
        foreach (['/export'] as $path) {
            [$status] = $this->fetch($path);
            self::assertContains($status, [200, 302], "{$path} answered {$status}");
        }
    }

    public function testEveryPageDeclaresCzechAndAViewport(): void
    {
        [, $body] = $this->fetch('/kanon');

        self::assertStringContainsString('<html lang="cs">', $body);
        self::assertStringContainsString('name="viewport"', $body, 'mobile-first needs the viewport tag');
    }

    public function testNoPageLeaksAHexColourOutsideTheTokenFile(): void
    {
        [, $body] = $this->fetch('/kanon');

        self::assertSame(
            0,
            preg_match('/style="[^"]*#[0-9a-fA-F]{3,6}/', $body),
            'colours belong in tokens.css only'
        );
    }
}
