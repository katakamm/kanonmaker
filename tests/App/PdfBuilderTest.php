<?php

declare(strict_types=1);

namespace Kanon\Tests\App;

use Kanon\App\PdfBuilder;
use Kanon\Rules\RuleResult;
use PHPUnit\Framework\TestCase;

final class PdfBuilderTest extends TestCase
{
    private function options(array $overrides = []): array
    {
        return $overrides + [
            'name'    => 'Kata Kammová',
            'year'    => '2025/2026',
            'works'   => [
                [
                    'id' => 1, 'title' => 'Máj', 'note' => null, 'chapter' => 'Česká literatura 19. století',
                    'authors' => 'MÁCHA, Karel Hynek',
                    'tags' => ['forma' => [['code' => 'poezie', 'label' => 'poezie', 'verified' => false]]],
                ],
                [
                    'id' => 2, 'title' => 'R.U.R.', 'note' => null, 'chapter' => 'Drama 20. a 21. století',
                    'authors' => 'ČAPEK, Karel',
                    'tags' => ['forma' => [['code' => 'drama', 'label' => 'drama', 'verified' => true]]],
                ],
            ],
            'results' => [new RuleResult('Celkem 25 titulů', false, 2, 25)],
            'grouped' => false,
            'chips'   => true,
            'rules'   => true,
        ];
    }

    public function testListsEveryWorkNumbered(): void
    {
        $html = PdfBuilder::html($this->options());

        self::assertStringContainsString('Máj', $html);
        self::assertStringContainsString('MÁCHA, Karel Hynek', $html);
        self::assertStringContainsString('R.U.R.', $html);
        self::assertStringContainsString('>1.<', $html);
        self::assertStringContainsString('>2.<', $html);
    }

    public function testTheNameAndYearAppearWhenGiven(): void
    {
        $html = PdfBuilder::html($this->options());

        self::assertStringContainsString('Kata Kammová', $html);
        self::assertStringContainsString('2025/2026', $html);
    }

    public function testTheNameCanBeLeftOut(): void
    {
        $html = PdfBuilder::html($this->options(['name' => '']));

        self::assertStringNotContainsString('Kata Kammová', $html);
        self::assertStringContainsString('Máj', $html, 'the list itself is still there');
    }

    public function testChipsCanBeSwitchedOff(): void
    {
        $with    = PdfBuilder::html($this->options(['chips' => true]));
        $without = PdfBuilder::html($this->options(['chips' => false]));

        self::assertStringContainsString('poezie', $with);
        self::assertStringNotContainsString('poezie', $without);
    }

    public function testTheRuleSummaryCanBeSwitchedOff(): void
    {
        self::assertStringContainsString('Celkem 25 titulů', PdfBuilder::html($this->options(['rules' => true])));
        self::assertStringNotContainsString('Celkem 25 titulů', PdfBuilder::html($this->options(['rules' => false])));
    }

    public function testGroupingPrintsChapterHeadings(): void
    {
        $flat    = PdfBuilder::html($this->options(['grouped' => false]));
        $grouped = PdfBuilder::html($this->options(['grouped' => true]));

        self::assertStringNotContainsString('Česká literatura 19. století', $flat);
        self::assertStringContainsString('Česká literatura 19. století', $grouped);
        self::assertStringContainsString('Drama 20. a 21. století', $grouped);
    }

    public function testOutputIsEscaped(): void
    {
        $html = PdfBuilder::html($this->options(['name' => '<script>alert(1)</script>']));

        self::assertStringNotContainsString('<script>', $html);
    }

    public function testAnEmptyListStillProducesAValidDocument(): void
    {
        $html = PdfBuilder::html($this->options(['works' => []]));

        self::assertStringContainsString('<html', $html);
        self::assertStringContainsString('Seznam je prázdný', $html);
    }
}
