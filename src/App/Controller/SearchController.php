<?php

declare(strict_types=1);

namespace Kanon\App\Controller;

use Kanon\App\Ui;
use Kanon\Http\Response;
use Kanon\Repo\WorkRepository;

final class SearchController
{
    public function __construct(
        private readonly WorkRepository $works,
        private readonly int $canonId,
        private readonly \Closure $page,
        private readonly \Closure $listIds,
    ) {
    }

    public function page(array $query): Response
    {
        $q = trim((string) ($query['q'] ?? ''));

        return Response::html(($this->page)('Hledání', 'search', [
            'q'      => $q,
            'works'  => $q === '' ? [] : $this->works->search($this->canonId, $q),
            'inList' => ($this->listIds)(),
            'back'   => '/hledat?q=' . rawurlencode($q),
        ]));
    }

    /** Feeds the live search box; the page works without it. */
    public function json(array $query): Response
    {
        $q      = trim((string) ($query['q'] ?? ''));
        $inList = ($this->listIds)();

        $works = array_map(
            static fn (array $w): array => [
                'id'      => $w['id'],
                'title'   => $w['title'],
                'authors' => $w['authors'],
                'inList'  => in_array($w['id'], $inList, true),
                'tags'    => array_map(
                    static fn (array $t): array => ['group' => $t['group'], 'label' => $t['label']],
                    Ui::orderedTags($w['tags'])
                ),
            ],
            $q === '' ? [] : $this->works->search($this->canonId, $q, 15)
        );

        return Response::json(['q' => $q, 'works' => $works]);
    }
}
