<?php

declare(strict_types=1);

namespace Kanon\Import;

/**
 * Corrects entry lines that are malformed in the school's own document.
 *
 * Five paragraphs there contain two entries each, three use a comma where a
 * semicolon was meant, one writes an author's names back to front, and the last
 * paragraph is the school's address rather than a book. Left alone, these
 * misattribute works — four Havel plays were credited to Dürrenmatt.
 *
 * The corrections live in a committed JSON file and match a whole entry
 * exactly, so the import stays deterministic and every correction is visible in
 * git. A correction that no longer matches anything is reported rather than
 * silently ignored: it means the school has edited the document and the
 * correction may no longer be wanted.
 */
final class EntryFixes
{
    /** @var array<string, list<string>> broken entry => replacements */
    private array $fixes = [];

    /** @var array<string, bool> */
    private array $used = [];

    private int $applied = 0;

    public function __construct(string $jsonPath)
    {
        if (!is_file($jsonPath)) {
            throw new \RuntimeException("Entry fix file not found: {$jsonPath}");
        }

        $raw = file_get_contents($jsonPath);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read entry fix file: {$jsonPath}");
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Entry fix file must contain a JSON array');
        }

        foreach ($decoded as $fix) {
            if (!isset($fix['broken']) || !array_key_exists('into', $fix)) {
                throw new \RuntimeException('Every entry fix needs "broken" and "into"');
            }

            $this->fixes[(string) $fix['broken']] = array_values(array_map('strval', $fix['into']));
        }
    }

    /**
     * @param  list<string> $entries
     * @return list<string>
     */
    public function apply(array $entries): array
    {
        $out = [];

        foreach ($entries as $entry) {
            if (!isset($this->fixes[$entry])) {
                $out[] = $entry;
                continue;
            }

            $this->used[$entry] = true;
            $this->applied++;

            foreach ($this->fixes[$entry] as $replacement) {
                $out[] = $replacement;
            }
        }

        return $out;
    }

    public function appliedCount(): int
    {
        return $this->applied;
    }

    /** @return list<string> corrections that matched no entry */
    public function unused(): array
    {
        $unused = [];
        foreach (array_keys($this->fixes) as $broken) {
            if (!isset($this->used[$broken])) {
                $unused[] = $broken;
            }
        }

        return $unused;
    }
}
