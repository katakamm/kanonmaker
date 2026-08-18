<?php

declare(strict_types=1);

namespace Kanon\App\Controller;

use Kanon\Http\Response;
use Kanon\Repo\WorkRepository;

final class CanonController
{
    public function __construct(
        private readonly WorkRepository $works,
        private readonly int $canonId,
        private readonly \Closure $page,
        private readonly \Closure $listIds,
    ) {
    }

    public function browse(array $query, string $back = '/kanon'): Response
    {
        $chapterId = isset($query['kapitola']) ? (int) $query['kapitola'] : null;
        $group     = isset($query['skupina']) ? (string) $query['skupina'] : null;
        $code      = isset($query['znacka']) ? (string) $query['znacka'] : null;

        $chapters = $this->works->chapters($this->canonId);
        $works    = $this->works->browse($this->canonId, $chapterId, $group, $code);

        $heading = 'Celý kánon';
        foreach ($chapters as $chapter) {
            if ($chapter['id'] === $chapterId) {
                $heading = $chapter['name'];
            }
        }
        if ($group !== null && $code !== null) {
            foreach ($this->works->tagGroups($this->canonId)[$group] ?? [] as $tag) {
                if ($tag['code'] === $code) {
                    $heading = $tag['label'];
                }
            }
        }

        return Response::html(($this->page)('Kánon', 'canon', [
            'works'     => $works,
            'chapters'  => $chapters,
            'tagGroups' => $this->works->tagGroups($this->canonId),
            'total'     => array_sum(array_column($chapters, 'works')),
            'heading'   => $heading,
            'inList'    => ($this->listIds)(),
            'back'      => $back,
        ]));
    }

    public function work(array $params): Response
    {
        $work = $this->works->find($this->canonId, (int) $params['id']);

        if ($work === null) {
            return Response::html(($this->page)('Nenalezeno', 'not-found'), 404);
        }

        $unverified = false;
        foreach ($work['tags'] as $group) {
            foreach ($group as $tag) {
                $unverified = $unverified || !$tag['verified'];
            }
        }

        return Response::html(($this->page)($work['title'], 'work', [
            'work'       => $work,
            'unverified' => $unverified,
            'inList'     => in_array($work['id'], ($this->listIds)(), true),
        ]));
    }
}
