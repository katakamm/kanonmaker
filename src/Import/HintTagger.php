<?php

declare(strict_types=1);

namespace Kanon\Import;

use Kanon\Support\Normalize;

/**
 * Reads a literary form out of the parenthetical note, where the document
 * states one. Roughly 93 of the 358 entries carry such a hint.
 *
 * Order matters: "veršovaný povídkový soubor" is poetry, not prose, so verse
 * patterns are tested before the story-collection pattern.
 */
final class HintTagger
{
    private const PATTERNS = [
        'versovan' => 'poezie',
        'basn'     => 'poezie',
        'povidkov' => 'proza',
        'dramat'   => 'drama',
        'drama'    => 'drama',
        'poezie'   => 'poezie',
        'proza'    => 'proza',
    ];

    /** @return array{group: string, code: string}|null */
    public function formFor(?string $note): ?array
    {
        if ($note === null || trim($note) === '') {
            return null;
        }

        $haystack = Normalize::text($note);

        foreach (self::PATTERNS as $needle => $code) {
            if (str_contains($haystack, $needle)) {
                return ['group' => 'forma', 'code' => $code];
            }
        }

        return null;
    }
}
