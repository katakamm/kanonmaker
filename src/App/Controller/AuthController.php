<?php

declare(strict_types=1);

namespace Kanon\App\Controller;

use Kanon\Auth\Auth;
use Kanon\Auth\LoginThrottle;
use Kanon\Auth\UserRepository;
use Kanon\Http\Csrf;
use Kanon\Http\Response;
use Kanon\Http\Session;

final class AuthController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly UserRepository $users,
        private readonly LoginThrottle $throttle,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly \Closure $page,
    ) {
    }

    public function showRegister(): Response
    {
        return Response::html(($this->page)('Registrace', 'register', ['old' => []]));
    }

    public function register(array $input): Response
    {
        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect('/registrace');
        }

        $name     = trim((string) ($input['display_name'] ?? ''));
        $email    = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        $error = match (true) {
            $name === ''                               => 'Vyplň prosím jméno.',
            !filter_var($email, FILTER_VALIDATE_EMAIL) => 'E-mail nevypadá správně.',
            mb_strlen($password) < 8                   => 'Heslo musí mít alespoň 8 znaků.',
            $this->users->exists($email)               => 'Účet s tímto e-mailem už existuje.',
            default                                    => null,
        };

        if ($error !== null) {
            $this->session->flash('warn', $error);

            return Response::html(($this->page)('Registrace', 'register', [
                'old' => ['display_name' => $name, 'email' => $email],
            ]));
        }

        $this->users->create($email, $password, $name);
        $this->auth->attempt($email, $password);
        $this->session->flash('ok', 'Účet je založený. Můžeš začít sestavovat seznam.');

        return Response::redirect('/');
    }

    public function showLogin(): Response
    {
        return Response::html(($this->page)('Přihlášení', 'login', ['old' => []]));
    }

    public function login(array $input): Response
    {
        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect('/prihlaseni');
        }

        $email    = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($this->throttle->tooMany($email)) {
            $this->session->flash('warn', 'Příliš mnoho pokusů. Zkus to prosím za čtvrt hodiny.');

            return Response::html(($this->page)('Přihlášení', 'login', ['old' => ['email' => $email]]));
        }

        if (!$this->auth->attempt($email, $password)) {
            $this->session->flash('warn', 'E-mail nebo heslo nesouhlasí.');

            return Response::html(($this->page)('Přihlášení', 'login', ['old' => ['email' => $email]]));
        }

        return Response::redirect('/');
    }

    public function showPassword(): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/prihlaseni');
        }

        return Response::html(($this->page)('Změna hesla', 'password'));
    }

    public function changePassword(array $input): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return Response::redirect('/prihlaseni');
        }

        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect('/heslo');
        }

        $current = (string) ($input['current'] ?? '');
        $new     = (string) ($input['password'] ?? '');
        $again   = (string) ($input['password_again'] ?? '');

        $error = match (true) {
            !$this->users->verify($user, $current) => 'Stávající heslo nesouhlasí.',
            mb_strlen($new) < 8                    => 'Nové heslo musí mít alespoň 8 znaků.',
            $new !== $again                        => 'Nová hesla se neshodují.',
            $new === $current                      => 'Nové heslo je stejné jako to stávající.',
            default                                => null,
        };

        if ($error !== null) {
            $this->session->flash('warn', $error);

            return Response::redirect('/heslo');
        }

        $this->users->updatePassword((int) $user['id'], $new);
        $this->session->flash('ok', 'Heslo změněno.');

        return Response::redirect('/');
    }

    public function logout(array $input): Response
    {
        if ($this->csrf->check($input['_token'] ?? null)) {
            $this->auth->logout();
            $this->session->flash('ok', 'Odhlášeno.');
        }

        return Response::redirect('/');
    }
}
