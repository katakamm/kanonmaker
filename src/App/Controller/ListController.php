<?php

declare(strict_types=1);

namespace Kanon\App\Controller;

use Kanon\Auth\Auth;
use Kanon\Http\Csrf;
use Kanon\Http\Response;
use Kanon\Http\Session;
use Kanon\Repo\ListRepository;
use Kanon\Repo\WorkRepository;

final class ListController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly ListRepository $lists,
        private readonly WorkRepository $works,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly int $canonId,
        private readonly \Closure $page,
    ) {
    }

    public function show(): Response
    {
        $userId = $this->auth->id();
        if ($userId === null) {
            return Response::redirect('/prihlaseni');
        }

        $listId  = $this->lists->forUser($userId, $this->canonId);
        $workIds = $this->lists->workIds($listId);

        return Response::html(($this->page)('Můj seznam', 'list', [
            'works'  => $this->works->findMany($this->canonId, $workIds),
            'inList' => $workIds,
        ]));
    }

    public function add(array $input): Response
    {
        return $this->change($input, true);
    }

    public function remove(array $input): Response
    {
        return $this->change($input, false);
    }

    private function change(array $input, bool $adding): Response
    {
        $userId = $this->auth->id();
        if ($userId === null) {
            return Response::redirect('/prihlaseni');
        }

        $back = (string) ($input['zpet'] ?? '/');
        // Never redirect off-site on the strength of a form field.
        if (!str_starts_with($back, '/')) {
            $back = '/';
        }

        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect($back);
        }

        $workId = (int) ($input['work_id'] ?? 0);
        $work   = $this->works->find($this->canonId, $workId);

        if ($work === null) {
            $this->session->flash('warn', 'Takové dílo v kánonu není.');

            return Response::redirect($back);
        }

        $listId = $this->lists->forUser($userId, $this->canonId);

        if ($adding) {
            $this->lists->add($listId, $workId);
            $this->session->flash('ok', 'Přidáno: ' . $work['title']);
        } else {
            $this->lists->remove($listId, $workId);
            $this->session->flash('ok', 'Odebráno: ' . $work['title']);
        }

        return Response::redirect($back);
    }
}
