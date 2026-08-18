<?php

declare(strict_types=1);

namespace Kanon\Admin\Controller;

use Kanon\Admin\AdminRepository;
use Kanon\Http\Csrf;
use Kanon\Http\Response;
use Kanon\Http\Session;

final class RuleController
{
    public function __construct(
        private readonly AdminRepository $admin,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly int $canonId,
        private readonly \Closure $page,
    ) {
    }

    public function index(): Response
    {
        return Response::html(($this->page)('Pravidla', 'admin/rules', [
            'rules' => $this->admin->rules($this->canonId),
        ]));
    }

    public function update(array $params, array $input): Response
    {
        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect('/pravidla');
        }

        $raw   = is_array($input['params'] ?? null) ? $input['params'] : [];
        $typed = [];
        foreach ($raw as $key => $value) {
            $typed[$key] = match (true) {
                $key === 'distinct_form' => $value === '1',
                is_numeric($value)       => (int) $value,
                default                  => (string) $value,
            };
        }

        $ok = $this->admin->updateRule((int) $params['id'], $typed, isset($input['enabled']));

        $this->session->flash(
            $ok ? 'ok' : 'warn',
            $ok ? 'Pravidlo uloženo.' : 'Takhle by kontrola pravidlo nezvládla vyhodnotit — neuloženo.'
        );

        return Response::redirect('/pravidla');
    }
}
