<?php

declare(strict_types=1);

namespace Kanon\Import;

final class ImportReport
{
    public int $entriesParsed    = 0;
    public int $chaptersCreated  = 0;
    public int $worksCreated     = 0;
    public int $worksUpdated     = 0;
    public int $worksUnchanged   = 0;
    public int $authorsCreated   = 0;
    public int $tagsDocument     = 0;
    public int $tagsInferred     = 0;
    public int $tagsSkippedHuman = 0;
    public int $duplicateKeys    = 0;
    public int $entriesFixed     = 0;

    public int $worksPruned    = 0;
    public int $authorsRemoved = 0;

    /** @var list<string> corrections in the entry-fix file that matched nothing */
    public array $unusedFixes = [];

    /** @var list<array{match_key: string, title: string, in_lists: int}> */
    public array $orphans = [];

    /** @return list<string> */
    public function lines(): array
    {
        return [
            sprintf('entries parsed      %d', $this->entriesParsed),
            sprintf('chapters created    %d', $this->chaptersCreated),
            sprintf('works created       %d', $this->worksCreated),
            sprintf('works updated       %d', $this->worksUpdated),
            sprintf('works unchanged     %d', $this->worksUnchanged),
            sprintf('authors created     %d', $this->authorsCreated),
            sprintf('tags from document  %d', $this->tagsDocument),
            sprintf('tags inferred       %d', $this->tagsInferred),
            sprintf('tags kept (human)   %d', $this->tagsSkippedHuman),
            sprintf('duplicate keys      %d', $this->duplicateKeys),
            sprintf('entry fixes applied %d', $this->entriesFixed),
            sprintf('entry fixes unused  %d', count($this->unusedFixes)),
            sprintf('orphaned works      %d', count($this->orphans)),
            sprintf('works pruned        %d', $this->worksPruned),
            sprintf('authors removed     %d', $this->authorsRemoved),
        ];
    }
}
