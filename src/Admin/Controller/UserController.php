<?php

declare(strict_types=1);

namespace Kanon\Admin\Controller;

use Kanon\Admin\AdminRepository;
use Kanon\Auth\Auth;
use Kanon\Http\Csrf;
use Kanon\Http\Response;
use Kanon\Http\Session;

final class UserController
{
    public function __construct(
        private readonly AdminRepository $admin,
        private readonly Auth $auth,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly \Closure $page,
    ) {
    }

    public function index(): Response
    {
        return Response::html(($this->page)('Uživatelé', 'admin/users', [
            'students' => $this->admin->students(),
        ]));
    }

    public function update(array $params, array $input): Response
    {
        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect('/uzivatele');
        }

        $userId = (int) $params['id'];

        // Locking yourself out of the administration is not a recoverable mistake.
        if ($userId === $this->auth->id()) {
            $this->session->flash('warn', 'Vlastní účet si měnit nemůžeš.');

            return Response::redirect('/uzivatele');
        }

        match ((string) ($input['akce'] ?? '')) {
            'promote'    => $this->admin->setRole($userId, 'admin'),
            'demote'     => $this->admin->setRole($userId, 'student'),
            'activate'   => $this->admin->setActive($userId, true),
            'deactivate' => $this->admin->setActive($userId, false),
            default      => null,
        };

        $this->session->flash('ok', 'Uloženo.');

        return Response::redirect('/uzivatele');
    }
}
