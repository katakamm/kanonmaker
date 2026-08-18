<?php

declare(strict_types=1);

namespace Kanon\Import;

use Kanon\Support\Normalize;

/**
 * Turns the committed HTML export of the school canon into chapters and raw
 * entry lines.
 *
 * The HTML export is used rather than the plain-text export because the text
 * export merges adjacent paragraphs, which silently glues unrelated entries
 * together (for example Vratislav z Mitrovic and Vergilius).
 */
final class DocumentParser
{
    public const CHAPTERS = [
        'Světová a česká literatura do konce 18. století (kromě preromantismu)',
        'Světová literatura od preromantismu do konce 19. století',
        'Česká literatura od preromantismu do konce 19. století',
        'Světová a česká dramatická tvorba 20. - 21. století',
        'Světová a česká poezie 20. a 21. století',
        'Světová próza 20. a 21. století',
        'Česká próza 20. a 21. století',
    ];

    /** @return list<array{name: string, sort_order: int, entries: string[]}> */
    public function parse(string $html): array
    {
        $headings = [];
        foreach (self::CHAPTERS as $name) {
            $headings[Normalize::text($name)] = $name;
        }

        $chapters = [];
        $current  = null;

        foreach ($this->paragraphs($html) as $paragraph) {
            $key = Normalize::text($paragraph);

            if (isset($headings[$key])) {
                if ($current !== null) {
                    $chapters[] = $current;
                }
                $current = [
                    'name'       => $headings[$key],
                    'sort_order' => count($chapters) + 1,
                    'entries'    => [],
                ];
                continue;
            }

            if ($current !== null) {
                $current['entries'][] = $paragraph;
            }
        }

        if ($current !== null) {
            $chapters[] = $current;
        }

        return $chapters;
    }

    /** @return list<string> non-empty, whitespace-normalized paragraph texts */
    private function paragraphs(string $html): array
    {
        preg_match_all('#<p[^>]*>(.*?)</p>#su', $html, $matches);

        $out = [];
        foreach ($matches[1] as $raw) {
            $text = strip_tags($raw);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = str_replace("\u{00A0}", ' ', $text);
            $text = (string) preg_replace('/\s+/u', ' ', $text);
            $text = trim($text);

            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }
}
