<?php

declare(strict_types=1);

namespace Kanon\Tests\View;

use Kanon\View\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kanon-view-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/greet.php', '<p><?= $this->e($name) ?></p>');
        file_put_contents($this->dir . '/raw.php', '<p><?= $name ?></p>');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testRendersATemplateWithData(): void
    {
        $html = (new View($this->dir))->render('greet', ['name' => 'Kytice']);

        self::assertSame('<p>Kytice</p>', $html);
    }

    public function testEscapesHtmlInTemplateOutput(): void
    {
        $html = (new View($this->dir))->render('greet', ['name' => '<script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testKeepsCzechDiacriticsIntact(): void
    {
        $html = (new View($this->dir))->render('greet', ['name' => 'Žítkovské bohyně']);

        self::assertSame('<p>Žítkovské bohyně</p>', $html);
    }

    public function testMissingTemplateThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new View($this->dir))->render('neexistuje');
    }
}
