<?php

declare(strict_types=1);

namespace Kanon\Admin\Controller;

use Kanon\Admin\AdminRepository;
use Kanon\Http\Response;

final class DashboardController
{
    public function __construct(
        private readonly AdminRepository $admin,
        private readonly int $canonId,
        private readonly \Closure $page,
    ) {
    }

    public function show(): Response
    {
        return Response::html(($this->page)('Přehled', 'admin/dashboard', [
            'stats' => $this->admin->stats($this->canonId),
        ]));
    }
}
