<?php

declare(strict_types=1);

namespace Kanon\Import;

/**
 * Turns one entry line of the canon into the works a student can actually pick.
 *
 * Titles separated by ";" are separate works, because the student chooses one of
 * them. Parts joined by "+" stay a single work, because the "+" means the parts
 * are read together.
 */
final class EntrySplitter
{
    /** @return list<ParsedWork> */
    public function split(string $entry): array
    {
        $entry = trim($entry);
        [$authorPart, $titlePart] = $this->splitAtTopLevelColon($entry);

        $authors = $authorPart === null ? [] : $this->splitAuthors($authorPart);

        $works = [];
        foreach ($this->splitTitles($titlePart) as $rawTitle) {
            [$title, $note] = $this->extractNote($rawTitle);

            if ($title === '') {
                continue;
            }

            $works[] = new ParsedWork($authors, $title, $note, $entry);
        }

        return $works;
    }

    /** @return array{0: ?string, 1: string} */
    private function splitAtTopLevelColon(string $entry): array
    {
        $depth  = 0;
        $length = mb_strlen($entry);

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($entry, $i, 1);

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ':' && $depth === 0) {
                return [
                    trim(mb_substr($entry, 0, $i)),
                    trim(mb_substr($entry, $i + 1)),
                ];
            }
        }

        return [null, $entry];
    }

    /** @return list<ParsedAuthor> */
    private function splitAuthors(string $part): array
    {
        $tokens  = array_map('trim', explode(',', $part));
        $authors = [];
        $current = null;

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if ($current === null || $this->startsNewAuthor($token)) {
                if ($current !== null) {
                    $authors[] = $current;
                }
                $current = $this->openAuthor($token);
                continue;
            }

            $current['first'] = $current['first'] === null
                ? $token
                : $current['first'] . ', ' . $token;
        }

        if ($current !== null) {
            $authors[] = $current;
        }

        return array_map(
            static fn (array $a): ParsedAuthor => new ParsedAuthor(
                $a['surname'],
                $a['first'],
                $a['first'] === null ? $a['surname'] : $a['surname'] . ', ' . $a['first'],
            ),
            $authors
        );
    }

    private function startsNewAuthor(string $token): bool
    {
        return preg_match('/^\p{Lu}{2,}/u', $token) === 1;
    }

    /** @return array{surname: string, first: ?string} */
    private function openAuthor(string $token): array
    {
        // "MAŠEK Vojtěch" - a surname and given name with the comma missing.
        if (preg_match('/^(\p{Lu}[\p{Lu}\s\'\x{2019}-]*\p{Lu})\s+(\p{Lu}\p{Ll}.*)$/u', $token, $m) === 1) {
            return ['surname' => trim($m[1]), 'first' => trim($m[2])];
        }

        return ['surname' => $token, 'first' => null];
    }

    /** @return list<string> */
    private function splitTitles(string $titlePart): array
    {
        $titles = [];
        $buffer = '';
        $depth  = 0;
        $length = mb_strlen($titlePart);

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($titlePart, $i, 1);

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            }

            if ($char === ';' && $depth === 0) {
                $titles[] = trim($buffer);
                $buffer   = '';
                continue;
            }

            $buffer .= $char;
        }

        $titles[] = trim($buffer);

        return array_values(array_filter($titles, static fn (string $t): bool => $t !== ''));
    }

    /** @return array{0: string, 1: ?string} */
    private function extractNote(string $title): array
    {
        if (preg_match('/^(.*?)\s*\((.+)\)\s*$/u', $title, $m) === 1) {
            return [trim($m[1]), trim($m[2])];
        }

        return [trim($title), null];
    }
}
