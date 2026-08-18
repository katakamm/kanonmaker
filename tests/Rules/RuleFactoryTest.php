<?php

declare(strict_types=1);

namespace Kanon\Tests\Rules;

use Kanon\Rules\MaxPerAuthor;
use Kanon\Rules\MinCount;
use Kanon\Rules\MinDistinct;
use Kanon\Rules\MinTotal;
use Kanon\Rules\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RuleFactoryTest extends TestCase
{
    public function testBuildsEveryRuleType(): void
    {
        self::assertInstanceOf(MinTotal::class, RuleFactory::fromRow([
            'type' => 'min_total', 'params' => '{"min":25}', 'label' => 'Celkem',
        ]));

        self::assertInstanceOf(MinCount::class, RuleFactory::fromRow([
            'type'   => 'min_count',
            'params' => '{"group":"forma","code":"drama","min":3}',
            'label'  => 'Drama',
        ]));

        self::assertInstanceOf(MinDistinct::class, RuleFactory::fromRow([
            'type'   => 'min_distinct',
            'params' => '{"scope_group":"obdobi","scope_code":"do18","group":"podobdobi","min":3}',
            'label'  => 'Období',
        ]));

        self::assertInstanceOf(MaxPerAuthor::class, RuleFactory::fromRow([
            'type'   => 'max_per_author',
            'params' => '{"max":2,"distinct_form":true}',
            'label'  => 'Autor',
        ]));
    }

    public function testBuiltRuleBehavesAsConfigured(): void
    {
        $rule   = RuleFactory::fromRow([
            'type'   => 'min_count',
            'params' => '{"group":"forma","code":"drama","min":3}',
            'label'  => 'Drama (min. 3)',
        ]);
        $result = $rule->evaluate([]);

        self::assertSame('Drama (min. 3)', $result->label);
        self::assertSame(3, $result->required);
        self::assertSame('drama', $result->fixCode);
    }

    public function testUnknownTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RuleFactory::fromRow(['type' => 'min_vibes', 'params' => '{}', 'label' => 'x']);
    }

    public function testMalformedParamsThrow(): void
    {
        $this->expectException(\JsonException::class);
        RuleFactory::fromRow(['type' => 'min_total', 'params' => 'not json', 'label' => 'x']);
    }
}
