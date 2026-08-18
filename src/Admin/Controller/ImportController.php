<?php

declare(strict_types=1);

namespace Kanon\Admin\Controller;

use Kanon\Http\Csrf;
use Kanon\Http\Response;
use Kanon\Http\Session;
use Kanon\Import\Importer;

final class ImportController
{
    public function __construct(
        private readonly Importer $importer,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly int $canonId,
        private readonly string $appRoot,
        private readonly \Closure $page,
    ) {
    }

    public function form(): Response
    {
        return Response::html(($this->page)('Import', 'admin/import', [
            'report' => null,
            'dryRun' => true,
        ]));
    }

    public function run(array $input): Response
    {
        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect('/import');
        }

        $dryRun = ($input['mode'] ?? 'dry') !== 'apply';

        $report = $this->importer->import(
            $this->canonId,
            $this->appRoot . '/data/canon-2025-2026.html',
            $this->appRoot . '/data/tags-2025-2026.csv',
            $dryRun,
            isset($input['prune']),
        );

        return Response::html(($this->page)('Import', 'admin/import', [
            'report' => $report,
            'dryRun' => $dryRun,
        ]));
    }
}
