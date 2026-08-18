<?php

declare(strict_types=1);

namespace Kanon\Tests\App;

use Kanon\App\Ui;
use PHPUnit\Framework\TestCase;

final class UiTest extends TestCase
{
    public function testGroupsAreLabelledInCzech(): void
    {
        self::assertSame('období', Ui::groupLabel('obdobi'));
        self::assertSame('literární období', Ui::groupLabel('podobdobi'));
        self::assertSame('národní literatura', Ui::groupLabel('narodni'));
        self::assertSame('literární forma', Ui::groupLabel('forma'));
    }

    public function testAnUnknownGroupFallsBackToItsOwnName(): void
    {
        self::assertSame('cosi', Ui::groupLabel('cosi'));
    }

    public function testTagsComeOutInAFixedOrderRegardlessOfInput(): void
    {
        $tags = [
            'forma'     => [['code' => 'drama', 'label' => 'drama', 'verified' => false]],
            'obdobi'    => [['code' => 'do18', 'label' => 'do konce 18. století', 'verified' => true]],
            'podobdobi' => [['code' => 'renesance', 'label' => 'renesance', 'verified' => false]],
        ];

        self::assertSame(
            ['obdobi', 'podobdobi', 'forma'],
            array_column(Ui::orderedTags($tags), 'group'),
            'chips always read period, sub-period, nationality, form, special'
        );
    }

    public function testOrderedTagsCarryTheVerifiedFlagThrough(): void
    {
        $ordered = Ui::orderedTags([
            'obdobi' => [['code' => 'do18', 'label' => 'do konce 18. století', 'verified' => true]],
            'forma'  => [['code' => 'drama', 'label' => 'drama', 'verified' => false]],
        ]);

        self::assertTrue($ordered[0]['verified']);
        self::assertFalse($ordered[1]['verified']);
    }

    public function testEmptyTagsGiveAnEmptyList(): void
    {
        self::assertSame([], Ui::orderedTags([]));
    }
}
