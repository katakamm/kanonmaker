<?php

declare(strict_types=1);

$appRoot = is_file(__DIR__ . '/approot.php')
    ? (string) require __DIR__ . '/approot.php'
    : dirname(__DIR__);

// Production captures PHP errors itself; FPM there locks error_log.
if (is_file($appRoot . '/chyby.php')) {
    require $appRoot . '/chyby.php';
}

require $appRoot . '/vendor/autoload.php';

use Kanon\Db\Database;
use Kanon\Http\Csrf;
use Kanon\Http\PhpSession;
use Kanon\Http\Response;
use Kanon\Http\Router;
use Kanon\View\View;

$config  = require $appRoot . '/config.php';
$pdo     = Database::connect($config['db']);
$session = new PhpSession();
$csrf    = new Csrf($session);
$view    = new View($appRoot . '/templates');

$canonId = (int) $pdo->query("SELECT id FROM canon WHERE school_year = '2025/2026'")->fetchColumn();

// Filled in by the list wiring below; until a student is logged in, nobody has a list.
$listIds = static fn (): array => [];

// Every template gets user, csrfToken, inList and back for free, so no
// controller has to remember them. A controller may still override any of them.
$page = function (string $title, string $template, array $data = []) use ($view, $session, $csrf, &$listIds): string {
    return $view->render('layout', [
        'title'     => $title,
        'user'      => null,
        'csrfToken' => $csrf->token(),
        'flashes'   => $session->takeFlashes(),
        'rulebar'   => '',
        'content'   => $view->render($template, $data + [
            'user'      => null,
            'csrfToken' => $csrf->token(),
            'inList'    => ($listIds)(),
            'back'      => '/',
        ]),
    ]);
};

$router = new Router();

$router->get('/', static fn (): Response => Response::html($page('Maturitní seznam četby', 'home-guest')));

$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$match = $router->match($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);

if ($match === null) {
    Response::html($page('Nenalezeno', 'not-found'), 404)->send();

    return;
}

($match['handler'])($match['params'])->send();
