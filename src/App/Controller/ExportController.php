<?php

declare(strict_types=1);

namespace Kanon\App\Controller;

use Kanon\App\PdfBuilder;
use Kanon\Auth\Auth;
use Kanon\Http\Csrf;
use Kanon\Http\Response;
use Kanon\Http\Session;
use Kanon\Repo\ListRepository;
use Kanon\Repo\WorkRepository;
use Kanon\Rules\RuleSet;
use Kanon\Rules\WorkViewLoader;

final class ExportController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly ListRepository $lists,
        private readonly WorkRepository $works,
        private readonly RuleSet $rules,
        private readonly WorkViewLoader $viewLoader,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly int $canonId,
        private readonly string $schoolYear,
        private readonly \Closure $page,
    ) {
    }

    public function form(): Response
    {
        $userId = $this->auth->id();
        if ($userId === null) {
            return Response::redirect('/prihlaseni');
        }

        $listId = $this->lists->forUser($userId, $this->canonId);

        return Response::html(($this->page)('Export', 'export', [
            'count'       => $this->lists->count($listId),
            'defaultName' => (string) ($this->auth->user()['display_name'] ?? ''),
            'defaultYear' => $this->schoolYear,
        ]));
    }

    public function pdf(array $input): Response
    {
        $userId = $this->auth->id();
        if ($userId === null) {
            return Response::redirect('/prihlaseni');
        }

        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect('/export');
        }

        $listId  = $this->lists->forUser($userId, $this->canonId);
        $workIds = $this->lists->workIds($listId);

        $html = PdfBuilder::html([
            'name'    => trim((string) ($input['name'] ?? '')),
            'year'    => trim((string) ($input['year'] ?? '')),
            'works'   => $this->works->findMany($this->canonId, $workIds),
            'results' => $this->rules->evaluate($this->viewLoader->load($workIds)),
            'grouped' => isset($input['grouped']),
            'chips'   => isset($input['chips']),
            'rules'   => isset($input['rules']),
        ]);

        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_top'    => 16,
            'margin_bottom' => 16,
            'margin_left'   => 18,
            'margin_right'  => 18,
            'tempDir'       => dirname(__DIR__, 3) . '/tmp',
        ]);
        $mpdf->SetTitle('Seznam četby k maturitní zkoušce');
        $mpdf->WriteHTML($html);

        return Response::download((string) $mpdf->Output('', 'S'), 'seznam-cetby.pdf', 'application/pdf');
    }
}
