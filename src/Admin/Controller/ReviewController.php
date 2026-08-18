<?php

declare(strict_types=1);

namespace Kanon\Admin\Controller;

use Kanon\Admin\ReviewRepository;
use Kanon\Http\Csrf;
use Kanon\Http\Response;
use Kanon\Http\Session;
use Kanon\Repo\WorkRepository;

final class ReviewController
{
    public function __construct(
        private readonly ReviewRepository $review,
        private readonly WorkRepository $works,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly int $canonId,
        private readonly \Closure $page,
    ) {
    }

    public function show(): Response
    {
        return Response::html(($this->page)('Ke kontrole', 'admin/review', [
            'groups'    => $this->review->groups($this->canonId),
            'remaining' => $this->review->unverifiedCount($this->canonId),
            'choices'   => $this->works->tagGroups($this->canonId),
        ]));
    }

    public function confirmGroup(array $input): Response
    {
        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect('/kontrola');
        }

        $confirmed = $this->review->confirmGroup(
            $this->canonId,
            (int) ($input['kapitola'] ?? 0),
            (string) ($input['skupina'] ?? ''),
            (string) ($input['znacka'] ?? ''),
        );

        $this->session->flash('ok', "Potvrzeno {$confirmed} značek.");

        return Response::redirect('/kontrola');
    }

    public function correct(array $input): Response
    {
        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect('/kontrola');
        }

        $ok = $this->review->replaceTag(
            $this->canonId,
            (int) ($input['work_id'] ?? 0),
            (string) ($input['skupina'] ?? ''),
            (string) ($input['znacka'] ?? ''),
        );

        $this->session->flash($ok ? 'ok' : 'warn', $ok ? 'Značka opravena.' : 'Takovou značku neznám.');

        return Response::redirect('/kontrola');
    }
}
