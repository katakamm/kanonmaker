<?php

declare(strict_types=1);

namespace Kanon\Import;

use Kanon\Support\Normalize;

final class ParsedWork
{
    /** @param list<ParsedAuthor> $authors */
    public function __construct(
        public readonly array $authors,
        public readonly string $title,
        public readonly ?string $note,
        public readonly string $sourceLine,
    ) {
    }

    public function titleKey(): string
    {
        return Normalize::key($this->title);
    }

    public function authorKey(): string
    {
        if ($this->authors === []) {
            return 'anon';
        }

        return implode('+', array_map(
            static fn (ParsedAuthor $a): string => $a->matchKey(),
            $this->authors
        ));
    }

    public function displayAuthors(): string
    {
        return implode('; ', array_map(
            static fn (ParsedAuthor $a): string => $a->display,
            $this->authors
        ));
    }
}
