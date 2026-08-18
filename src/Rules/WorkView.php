<?php

declare(strict_types=1);

namespace Kanon\Rules;

/** A work as the rules engine sees it. Deliberately free of database concerns. */
final class WorkView
{
    /**
     * @param array<int, string>          $authors author id => display name
     * @param array<string, list<string>> $tags    tag group => codes
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly array $authors,
        public readonly array $tags,
    ) {
    }

    public function hasTag(string $group, string $code): bool
    {
        return in_array($code, $this->tags[$group] ?? [], true);
    }

    /** @return list<string> */
    public function tagCodes(string $group): array
    {
        return $this->tags[$group] ?? [];
    }

    public function form(): ?string
    {
        return $this->tags['forma'][0] ?? null;
    }
}
