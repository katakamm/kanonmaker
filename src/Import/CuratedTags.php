<?php

declare(strict_types=1);

namespace Kanon\Import;

/**
 * Reads the hand-curated tags that the school document does not state.
 *
 * The file is authored in the repository rather than generated at run time, so
 * that the import stays deterministic and every change to a tag is visible in
 * git history.
 */
final class CuratedTags
{
    private const HEADER = ['work_key', 'author', 'title', 'group', 'code'];

    /** @return array<string, list<array{group: string, code: string}>> */
    public function load(string $csvPath): array
    {
        if (!is_file($csvPath)) {
            throw new \RuntimeException("Curated tag file not found: {$csvPath}");
        }

        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open curated tag file: {$csvPath}");
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header !== self::HEADER) {
                throw new \RuntimeException(
                    'Curated tag file header must be: ' . implode(',', self::HEADER)
                );
            }

            $tags = [];
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }

                $key   = trim((string) ($row[0] ?? ''));
                $group = trim((string) ($row[3] ?? ''));
                $code  = trim((string) ($row[4] ?? ''));

                if ($key === '' || $group === '' || $code === '') {
                    continue;
                }

                $tags[$key][] = ['group' => $group, 'code' => $code];
            }

            return $tags;
        } finally {
            fclose($handle);
        }
    }
}
