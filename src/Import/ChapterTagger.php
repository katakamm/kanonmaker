<?php

declare(strict_types=1);

namespace Kanon\Import;

use Kanon\Support\Normalize;

/**
 * The tags each chapter heading implies for every work inside it.
 *
 * Chapters 1, 4 and 5 mix Czech and world literature, and chapters 1, 2 and 3
 * mix literary forms, so those tags are deliberately absent here and come from
 * the curated file instead.
 */
final class ChapterTagger
{
    private const MAP = [
        'Světová a česká literatura do konce 18. století (kromě preromantismu)' => [
            ['group' => 'obdobi', 'code' => 'do18'],
        ],
        'Světová literatura od preromantismu do konce 19. století' => [
            ['group' => 'obdobi', 'code' => '19st'],
            ['group' => 'narodni', 'code' => 'svetova'],
        ],
        'Česká literatura od preromantismu do konce 19. století' => [
            ['group' => 'obdobi', 'code' => '19st'],
            ['group' => 'narodni', 'code' => 'ceska'],
        ],
        'Světová a česká dramatická tvorba 20. - 21. století' => [
            ['group' => 'obdobi', 'code' => '20_21st'],
            ['group' => 'forma', 'code' => 'drama'],
        ],
        'Světová a česká poezie 20. a 21. století' => [
            ['group' => 'obdobi', 'code' => '20_21st'],
            ['group' => 'forma', 'code' => 'poezie'],
        ],
        'Světová próza 20. a 21. století' => [
            ['group' => 'obdobi', 'code' => '20_21st'],
            ['group' => 'narodni', 'code' => 'svetova'],
            ['group' => 'forma', 'code' => 'proza'],
        ],
        'Česká próza 20. a 21. století' => [
            ['group' => 'obdobi', 'code' => '20_21st'],
            ['group' => 'narodni', 'code' => 'ceska'],
            ['group' => 'forma', 'code' => 'proza'],
        ],
    ];

    /** @return list<array{group: string, code: string}> */
    public function tagsFor(string $chapterName): array
    {
        foreach (self::MAP as $name => $tags) {
            if (Normalize::text($name) === Normalize::text($chapterName)) {
                return $tags;
            }
        }

        throw new \InvalidArgumentException("Unknown chapter: {$chapterName}");
    }
}
