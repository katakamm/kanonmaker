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
    private const PER_PAGE = 40;

    public function __construct(
        private readonly ReviewRepository $review,
        private readonly WorkRepository $works,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly int $canonId,
        private readonly \Closure $page,
    ) {
    }

    /** Books needing review, in the order the school document lists them. */
    public function show(array $query = []): Response
    {
        $offset = max(0, (int) ($query['od'] ?? 0));

        return Response::html(($this->page)('Ke kontrole', 'admin/review', [
            'works'          => $this->review->worksNeedingReview($this->canonId, self::PER_PAGE, $offset),
            'remainingWorks' => $this->review->worksNeedingReviewCount($this->canonId),
            'remainingTags'  => $this->review->unverifiedCount($this->canonId),
            'offset'         => $offset,
            'perPage'        => self::PER_PAGE,
        ]));
    }

    public function confirmTag(array $input): Response
    {
        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return $this->backToWork($input);
        }

        $this->review->confirmOne((int) ($input['work_id'] ?? 0), (int) ($input['tag_id'] ?? 0));

        return $this->backToWork($input);
    }

    public function confirmWork(array $input): Response
    {
        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return $this->backToWork($input);
        }

        $confirmed = $this->review->confirmWork((int) ($input['work_id'] ?? 0));
        $this->session->flash('ok', "Potvrzeno {$confirmed} značek.");

        return $this->backToWork($input);
    }

    /** Back to the same page and the same book, so the reviewer keeps their place. */
    private function backToWork(array $input): Response
    {
        $offset = max(0, (int) ($input['od'] ?? 0));
        $workId = (int) ($input['work_id'] ?? 0);

        return Response::redirect('/kontrola?od=' . $offset . ($workId > 0 ? '#w' . $workId : ''));
    }

    public function showGroups(array $query = []): Response
    {
        return Response::html(($this->page)('Ke kontrole po skupinách', 'admin/review-groups', [
            'groups'    => $this->review->groups($this->canonId),
            'remaining' => $this->review->unverifiedCount($this->canonId),
            'choices'   => $this->works->tagGroups($this->canonId),
            'openGroup' => (string) ($query['skupina_id'] ?? ''),
        ]));
    }

    public function confirmGroup(array $input): Response
    {
        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect('/kontrola/skupiny');
        }

        $confirmed = $this->review->confirmGroup(
            $this->canonId,
            (int) ($input['kapitola'] ?? 0),
            (string) ($input['skupina'] ?? ''),
            (string) ($input['znacka'] ?? ''),
        );

        $this->session->flash('ok', "Potvrzeno {$confirmed} značek.");

        return Response::redirect('/kontrola/skupiny');
    }

    public function correct(array $input): Response
    {
        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect('/kontrola/skupiny');
        }

        $ok = $this->review->replaceTag(
            $this->canonId,
            (int) ($input['work_id'] ?? 0),
            (string) ($input['skupina'] ?? ''),
            (string) ($input['znacka'] ?? ''),
        );

        $this->session->flash($ok ? 'ok' : 'warn', $ok ? 'Značka opravena.' : 'Takovou značku neznám.');

        $groupId = preg_replace('/[^a-z0-9_-]/i', '', (string) ($input['skupina_id'] ?? ''));

        return Response::redirect(
            $groupId === '' ? '/kontrola/skupiny' : '/kontrola/skupiny?skupina_id=' . $groupId . '#' . $groupId
        );
    }
}
