<?php

declare(strict_types=1);

namespace Kanon\Rules;

/**
 * "At most N titles by one author, and they must be of different literary
 * forms."
 *
 * Works whose form is still untagged cannot produce a violation of the
 * different-forms clause: the rule refuses to accuse on missing data, since a
 * false accusation would send a student hunting for a problem that is not there.
 */
final class MaxPerAuthor implements Rule
{
    public function __construct(
        private readonly string $label,
        private readonly int $max,
        private readonly bool $distinctForm,
    ) {
    }

    public function evaluate(array $works): RuleResult
    {
        /** @var array<int, array{name: string, works: list<WorkView>}> $byAuthor */
        $byAuthor = [];

        foreach ($works as $work) {
            foreach ($work->authors as $authorId => $authorName) {
                $byAuthor[$authorId] ??= ['name' => $authorName, 'works' => []];
                $byAuthor[$authorId]['works'][] = $work;
            }
        }

        $worst      = 0;
        $violations = [];

        foreach ($byAuthor as $author) {
            $count = count($author['works']);
            $worst = max($worst, $count);

            if ($count > $this->max) {
                $violations[] = sprintf('%s: %d tituly (nejvýše %d)', $author['name'], $count, $this->max);
                continue;
            }

            if (!$this->distinctForm || $count < 2) {
                continue;
            }

            $forms = [];
            foreach ($author['works'] as $work) {
                $form = $work->form();
                if ($form !== null) {
                    $forms[] = $form;
                }
            }

            if (count($forms) === $count && count(array_unique($forms)) < $count) {
                $violations[] = sprintf(
                    '%s: %d tituly stejné literární formy (%s)',
                    $author['name'],
                    $count,
                    implode(', ', array_unique($forms))
                );
            }
        }

        return new RuleResult(
            $this->label,
            $violations === [],
            $worst,
            $this->max,
            $violations === [] ? null : implode('; ', $violations),
        );
    }
}
