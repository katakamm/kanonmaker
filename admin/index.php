<?php

declare(strict_types=1);

$appRoot = is_file(__DIR__ . '/approot.php')
    ? (string) require __DIR__ . '/approot.php'
    : dirname(__DIR__);

foreach ([__DIR__ . '/chyby.php', $appRoot . '/chyby.php'] as $errorHandler) {
    if (is_file($errorHandler)) {
        require $errorHandler;
        break;
    }
}

require $appRoot . '/vendor/autoload.php';

use Kanon\Admin\AdminRepository;
use Kanon\Admin\Controller\DashboardController;
use Kanon\Admin\Controller\ImportController;
use Kanon\Admin\Controller\ReviewController;
use Kanon\Admin\Controller\RuleController;
use Kanon\Admin\Controller\UserController;
use Kanon\Admin\Controller\WorkController;
use Kanon\Admin\ReviewRepository;
use Kanon\App\Controller\AuthController;
use Kanon\Auth\Auth;
use Kanon\Auth\LoginThrottle;
use Kanon\Auth\UserRepository;
use Kanon\Db\Database;
use Kanon\Http\Csrf;
use Kanon\Http\PhpSession;
use Kanon\Http\Response;
use Kanon\Http\Router;
use Kanon\Import\ChapterTagger;
use Kanon\Import\CuratedTags;
use Kanon\Import\DocumentParser;
use Kanon\Import\EntryFixes;
use Kanon\Import\EntrySplitter;
use Kanon\Import\HintTagger;
use Kanon\Import\Importer;
use Kanon\Repo\WorkRepository;
use Kanon\View\View;

$config  = require $appRoot . '/config.php';
$pdo     = Database::connect($config['db']);
$session = new PhpSession();
$csrf    = new Csrf($session);
$view    = new View($appRoot . '/templates');

$schoolYear = '2025/2026';
$canonId    = (int) $pdo->query("SELECT id FROM canon WHERE school_year = '2025/2026'")->fetchColumn();

$canonDocumentUrl = 'https://docs.google.com/document/d/1N68YrxFv_VJi6P9MoUL6-A1-0JUbtCYDYliCUSby-0M/edit';

$users    = new UserRepository($pdo);
$throttle = new LoginThrottle($pdo);
$auth     = new Auth($users, $throttle, $session);

$page = function (string $title, string $template, array $data = []) use (
    $view, $session, $csrf, $auth, $canonDocumentUrl, $schoolYear
): string {
    return $view->render('layout', [
        'title'     => $title . ' · administrace',
        'user'      => $auth->user(),
        'csrfToken' => $csrf->token(),
        'flashes'   => $session->takeFlashes(),
        'rulebar'          => '',
        // V administraci se nikdo neregistruje - účty zakládá správce.
        'showRegister'     => false,
        'canonDocumentUrl' => $canonDocumentUrl,
        'schoolYear'       => $schoolYear,
        'content'   => $view->render($template, $data + [
            'user'      => $auth->user(),
            'csrfToken' => $csrf->token(),
            'inList'    => [],
            'back'      => '/',
        ]),
    ]);
};

/** Returns a Response when the visitor may not be here, null when they may. */
$guard = static function () use ($auth, $page): ?Response {
    if (!$auth->check()) {
        return Response::redirect('/prihlaseni');
    }

    if (!$auth->isAdmin()) {
        return Response::html($page('Přístup odepřen', 'admin/forbidden'), 403);
    }

    return null;
};

$adminRepo  = new AdminRepository($pdo);
$reviewRepo = new ReviewRepository($pdo);
$workRepo   = new WorkRepository($pdo);

$importer = new Importer(
    $pdo,
    new DocumentParser(),
    new EntrySplitter(),
    new ChapterTagger(),
    new HintTagger(),
    new CuratedTags(),
    new EntryFixes($appRoot . '/data/entry-fixes-2025-2026.json'),
);

$authController      = new AuthController($auth, $users, $throttle, $session, $csrf, $page);
$dashboardController = new DashboardController($adminRepo, $canonId, $page);
$reviewController    = new ReviewController($reviewRepo, $workRepo, $session, $csrf, $canonId, $page);
$ruleController      = new RuleController($adminRepo, $session, $csrf, $canonId, $page);
$workController      = new WorkController($adminRepo, $reviewRepo, $workRepo, $session, $csrf, $canonId, $page);
$importController    = new ImportController($importer, $session, $csrf, $canonId, $appRoot, $page);
$userController      = new UserController($adminRepo, $auth, $session, $csrf, $page);

$router = new Router();

// Login lives on the admin host too, so an admin need not visit the student site.
$router->get('/prihlaseni', static fn (): Response => $authController->showLogin());
$router->post('/prihlaseni', static fn (): Response => $authController->login($_POST));
$router->post('/odhlasit', static fn (): Response => $authController->logout($_POST));
$router->get('/heslo', static fn (): Response => $guard() ?? $authController->showPassword());
$router->post('/heslo', static fn (): Response => $guard() ?? $authController->changePassword($_POST));

$router->get('/', static fn (): Response => $guard() ?? $dashboardController->show());

$router->get('/kontrola', static fn (): Response => $guard() ?? $reviewController->show($_GET));
$router->post('/kontrola/potvrdit-skupinu', static fn (): Response => $guard() ?? $reviewController->confirmGroup($_POST));
$router->post('/kontrola/opravit', static fn (): Response => $guard() ?? $reviewController->correct($_POST));

$router->get('/dila', static fn (): Response => $guard() ?? $workController->index($_GET));
$router->get('/dila/{id}', static fn (array $p): Response => $guard() ?? $workController->edit($p));
$router->post('/dila/{id}', static fn (array $p): Response => $guard() ?? $workController->update($p, $_POST));

$router->get('/pravidla', static fn (): Response => $guard() ?? $ruleController->index());
$router->post('/pravidla/{id}', static fn (array $p): Response => $guard() ?? $ruleController->update($p, $_POST));

$router->get('/import', static fn (): Response => $guard() ?? $importController->form());
$router->post('/import', static fn (): Response => $guard() ?? $importController->run($_POST));

$router->get('/uzivatele', static fn (): Response => $guard() ?? $userController->index());
$router->post('/uzivatele/{id}', static fn (array $p): Response => $guard() ?? $userController->update($p, $_POST));

$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$match = $router->match($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);

if ($match === null) {
    Response::html($page('Nenalezeno', 'not-found'), 404)->send();

    return;
}

($match['handler'])($match['params'])->send();
