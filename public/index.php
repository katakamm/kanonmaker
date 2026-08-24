<?php

declare(strict_types=1);

$appRoot = is_file(__DIR__ . '/approot.php')
    ? (string) require __DIR__ . '/approot.php'
    : dirname(__DIR__);

// Production captures PHP errors itself; FPM there locks error_log. The
// handler lives inside the document root on production and beside the app in
// development, so both places are checked.
foreach ([__DIR__ . '/chyby.php', $appRoot . '/chyby.php'] as $errorHandler) {
    if (is_file($errorHandler)) {
        require $errorHandler;
        break;
    }
}

require $appRoot . '/vendor/autoload.php';

use Kanon\App\Controller\AuthController;
use Kanon\App\Controller\CanonController;
use Kanon\App\Controller\ExportController;
use Kanon\App\Controller\ListController;
use Kanon\App\Controller\SearchController;
use Kanon\App\RuleBar;
use Kanon\Auth\Auth;
use Kanon\Auth\LoginThrottle;
use Kanon\Auth\UserRepository;
use Kanon\Db\Database;
use Kanon\Http\Csrf;
use Kanon\Http\PhpSession;
use Kanon\Http\Response;
use Kanon\Http\Router;
use Kanon\Repo\ListRepository;
use Kanon\Repo\WorkRepository;
use Kanon\Rules\RuleSet;
use Kanon\Rules\WorkViewLoader;
use Kanon\View\View;

$config  = require $appRoot . '/config.php';
$pdo     = Database::connect($config['db']);
$session = new PhpSession();
$csrf    = new Csrf($session);
$view    = new View($appRoot . '/templates');

$schoolYear = '2025/2026';
$canonId    = (int) $pdo->query("SELECT id FROM canon WHERE school_year = '2025/2026'")->fetchColumn();

// Odkaz na původní školní dokument - odtud se kánon importuje.
$canonDocumentUrl = 'https://docs.google.com/document/d/1N68YrxFv_VJi6P9MoUL6-A1-0JUbtCYDYliCUSby-0M/edit';

$users    = new UserRepository($pdo);
$throttle = new LoginThrottle($pdo);
$auth     = new Auth($users, $throttle, $session);

$workRepo   = new WorkRepository($pdo);
$listRepo   = new ListRepository($pdo);
$ruleSet    = RuleSet::fromCanon($pdo, $canonId);
$viewLoader = new WorkViewLoader($pdo);

$listIds = static function () use ($auth, $listRepo, $canonId): array {
    $userId = $auth->id();

    return $userId === null ? [] : $listRepo->workIds($listRepo->forUser($userId, $canonId));
};

// Every template gets user, csrfToken, inList and back for free, so no
// controller has to remember them. A controller may still override any of them.
$page = function (string $title, string $template, array $data = []) use (
    $view, $session, $csrf, $auth, $ruleSet, $viewLoader, $listIds, $canonDocumentUrl, $schoolYear
): string {
    $rulebar = '';

    if ($auth->check()) {
        $results = $ruleSet->evaluate($viewLoader->load($listIds()));
        $rulebar = $view->render('_rulebar', [
            'summary' => RuleBar::summarise($results),
            'results' => $results,
        ]);
    }

    return $view->render('layout', [
        'title'     => $title,
        'user'      => $auth->user(),
        'csrfToken' => $csrf->token(),
        'flashes'   => $session->takeFlashes(),
        'rulebar'          => $rulebar,
        'showRegister'     => true,
        'canonUrl'         => '/kanon',
        'canonDocumentUrl' => $canonDocumentUrl,
        'schoolYear'       => $schoolYear,
        'content'   => $view->render($template, $data + [
            'user'      => $auth->user(),
            'csrfToken' => $csrf->token(),
            'inList'    => $listIds(),
            'back'      => '/',
        ]),
    ]);
};

$authController   = new AuthController($auth, $users, $throttle, $session, $csrf, $page, $listRepo, $canonId);
$canonController  = new CanonController($workRepo, $canonId, $page, $listIds);
$searchController = new SearchController($workRepo, $canonId, $page, $listIds);
$listController   = new ListController($auth, $listRepo, $workRepo, $session, $csrf, $canonId, $page);
$exportController = new ExportController(
    $auth, $listRepo, $workRepo, $ruleSet, $viewLoader,
    $session, $csrf, $canonId, '2025/2026', $page
);

$router = new Router();

$router->get('/registrace', static fn (): Response => $authController->showRegister());
$router->post('/registrace', static fn (): Response => $authController->register($_POST));
$router->get('/prihlaseni', static fn (): Response => $authController->showLogin());
$router->post('/prihlaseni', static fn (): Response => $authController->login($_POST));
$router->post('/odhlasit', static fn (): Response => $authController->logout($_POST));
$router->get('/ucet', static fn (): Response => $authController->showAccount());
$router->get('/heslo', static fn (): Response => $authController->showPassword());
$router->post('/heslo', static fn (): Response => $authController->changePassword($_POST));

$router->get('/kanon', static function () use ($canonController): Response {
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');

    return $canonController->browse($_GET, '/kanon' . ($query === '' ? '' : '?' . $query));
});
$router->get('/dilo/{id}', static fn (array $p): Response => $canonController->work($p));
$router->get('/hledat', static fn (): Response => $searchController->page($_GET));
$router->get('/hledat.json', static fn (): Response => $searchController->json($_GET));

$router->post('/seznam/pridat', static fn (): Response => $listController->add($_POST));
$router->post('/seznam/odebrat', static fn (): Response => $listController->remove($_POST));

$router->get('/export', static fn (): Response => $exportController->form());
$router->post('/export', static fn (): Response => $exportController->pdf($_POST));

$router->get('/', static function () use ($page, $auth, $listController): Response {
    return $auth->check()
        ? $listController->show()
        : Response::html($page('Maturitní seznam četby', 'home-guest'));
});

$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$match = $router->match($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);

if ($match === null) {
    Response::html($page('Nenalezeno', 'not-found'), 404)->send();

    return;
}

($match['handler'])($match['params'])->send();
