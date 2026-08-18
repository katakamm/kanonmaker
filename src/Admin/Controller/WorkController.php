<?php

declare(strict_types=1);

namespace Kanon\Admin\Controller;

use Kanon\Admin\AdminRepository;
use Kanon\Admin\ReviewRepository;
use Kanon\Http\Csrf;
use Kanon\Http\Response;
use Kanon\Http\Session;
use Kanon\Repo\WorkRepository;

final class WorkController
{
    public function __construct(
        private readonly AdminRepository $admin,
        private readonly ReviewRepository $review,
        private readonly WorkRepository $works,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly int $canonId,
        private readonly \Closure $page,
    ) {
    }

    public function index(array $query): Response
    {
        $q = trim((string) ($query['q'] ?? ''));

        return Response::html(($this->page)('Díla', 'admin/works', [
            'q'     => $q,
            'works' => $q === ''
                ? array_slice($this->works->browse($this->canonId), 0, 100)
                : $this->works->search($this->canonId, $q, 100),
        ]));
    }

    public function edit(array $params): Response
    {
        $work = $this->works->find($this->canonId, (int) $params['id']);

        if ($work === null) {
            return Response::html(($this->page)('Nenalezeno', 'not-found'), 404);
        }

        return Response::html(($this->page)($work['title'], 'admin/work-edit', [
            'work'    => $work,
            'choices' => $this->works->tagGroups($this->canonId),
        ]));
    }

    public function update(array $params, array $input): Response
    {
        $workId = (int) $params['id'];

        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect('/dila/' . $workId);
        }

        $this->admin->updateWork(
            $this->canonId,
            $workId,
            (string) ($input['title'] ?? ''),
            $input['note'] ?? null
        );

        foreach ((array) ($input['tags'] ?? []) as $group => $code) {
            if ((string) $code !== '') {
                $this->review->replaceTag($this->canonId, $workId, (string) $group, (string) $code);
            }
        }

        $this->session->flash('ok', 'Uloženo.');

        return Response::redirect('/dila/' . $workId);
    }
}
