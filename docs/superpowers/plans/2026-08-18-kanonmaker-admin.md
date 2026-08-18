# Kanonmaker Administration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `kata-admin.doma.slimak.cz` the screens that keep the canon honest — a review queue that confirms 346 inferred tags in about twenty taps, editing of works and rules, and an import runner that shows what it would do before it does it.

**Architecture:** The same codebase, a second front controller, and the same router, view and session classes. Everything sits behind `role = admin`. Two new repositories — `ReviewRepository` for the queue and `AdminRepository` for works, rules and users — keep SQL out of the controllers, exactly as on the student side.

**Tech Stack:** PHP 8.3.25, PDO/MariaDB, PHPUnit 11. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-08-18-kanonmaker-design.md`

**Builds on:** the foundation and student-app plans, both executed — 461 works, 196 tests green.

## Global Constraints

- Everything from the student-app plan's Global Constraints still applies: strict types, Czech interface, escaping in templates, CSRF on every POST, colours only in `tokens.css`, mobile-first.
- **Every admin route requires `role = admin`.** A logged-in student who finds the URL gets 403, not the page. A guest gets the login form.
- **`work_tag.source = 'human'` is sacred.** Confirming or correcting a tag sets `source = 'human'`, `verified = 1`, and the importer already refuses to touch those.
- The admin document root is `admin/`, served at `kata-admin.doma.slimak.cz`. It needs its own `.htaccess`, exactly like `public/`.
- Admin templates live in `templates/admin/` and use the same layout shell.

---

## File Structure

| File | Responsibility |
| --- | --- |
| `admin/index.php` | Admin front controller: wiring and dispatch |
| `admin/.htaccess` | Front-controller rewrite |
| `src/Admin/ReviewRepository.php` | The review queue: group, confirm, correct |
| `src/Admin/AdminRepository.php` | Works, rules, users, canon statistics |
| `src/Admin/Controller/DashboardController.php` | What needs attention |
| `src/Admin/Controller/ReviewController.php` | The queue and its bulk actions |
| `src/Admin/Controller/WorkController.php` | Edit one work's tags and details |
| `src/Admin/Controller/RuleController.php` | Edit rule parameters |
| `src/Admin/Controller/ImportController.php` | Dry run, then apply |
| `src/Admin/Controller/UserController.php` | List, promote, deactivate |
| `templates/admin/*.php` | One template per screen |

---

### Task 1: The admin shell and its guard

**Files:**
- Create: `admin/.htaccess`, `src/Admin/AdminRepository.php`, `src/Admin/Controller/DashboardController.php`, `templates/admin/layout-nav.php`, `templates/admin/dashboard.php`, `templates/admin/forbidden.php`, `tests/Admin/AdminRepositoryTest.php`
- Modify: `admin/index.php`

**Interfaces:**
- Consumes: `Router`, `PhpSession`, `Csrf`, `View`, `Auth`, `Database`.
- Produces:
  - `Kanon\Admin\AdminRepository::__construct(\PDO $pdo)` with `stats(int $canonId): array{works:int, authors:int, tags_total:int, tags_unverified:int, students:int, lists:int}`.
  - `Kanon\Admin\Controller\DashboardController::show(): Response`.
  - A `$guard` closure in `admin/index.php`: returns a `Response` (login redirect or 403 page) when the visitor is not an admin, `null` when they are.

- [ ] **Step 1: Write the failing test**

Create `tests/Admin/AdminRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Admin;

use Kanon\Admin\AdminRepository;
use Kanon\Db\Database;
use PHPUnit\Framework\TestCase;

final class AdminRepositoryTest extends TestCase
{
    private AdminRepository $repo;
    private int $canonId;

    protected function setUp(): void
    {
        $config        = require dirname(__DIR__, 2) . '/config.php';
        $pdo           = Database::connect($config['db']);
        $this->canonId = (int) $pdo->query("SELECT id FROM canon WHERE school_year = '2025/2026'")->fetchColumn();
        $this->repo    = new AdminRepository($pdo);
    }

    public function testStatsDescribeTheImportedCanon(): void
    {
        $stats = $this->repo->stats($this->canonId);

        self::assertGreaterThan(440, $stats['works']);
        self::assertGreaterThan(300, $stats['authors']);
        self::assertGreaterThan(1000, $stats['tags_total']);
        self::assertGreaterThan(0, $stats['tags_unverified'], 'the curated tags await review');
    }

    public function testStatsCountStudentsAndLists(): void
    {
        $stats = $this->repo->stats($this->canonId);

        self::assertArrayHasKey('students', $stats);
        self::assertArrayHasKey('lists', $stats);
        self::assertGreaterThanOrEqual(0, $stats['students']);
    }

    public function testEveryStatIsAnInteger(): void
    {
        foreach ($this->repo->stats($this->canonId) as $key => $value) {
            self::assertIsInt($value, "{$key} should be an int");
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter AdminRepositoryTest
```

Expected: FAIL — `Class "Kanon\Admin\AdminRepository" not found`.

- [ ] **Step 3: Write the repository**

Create `src/Admin/AdminRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Admin;

final class AdminRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return array{works: int, authors: int, tags_total: int, tags_unverified: int,
     *               students: int, lists: int}
     */
    public function stats(int $canonId): array
    {
        $one = function (string $sql, array $params = []): int {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return (int) $stmt->fetchColumn();
        };

        return [
            'works'   => $one('SELECT COUNT(*) FROM work WHERE canon_id = ?', [$canonId]),
            'authors' => $one(
                'SELECT COUNT(DISTINCT wa.author_id) FROM work_author wa
                 JOIN work w ON w.id = wa.work_id WHERE w.canon_id = ?',
                [$canonId]
            ),
            'tags_total' => $one(
                'SELECT COUNT(*) FROM work_tag wt JOIN work w ON w.id = wt.work_id WHERE w.canon_id = ?',
                [$canonId]
            ),
            'tags_unverified' => $one(
                'SELECT COUNT(*) FROM work_tag wt JOIN work w ON w.id = wt.work_id
                 WHERE w.canon_id = ? AND wt.verified = 0',
                [$canonId]
            ),
            'students' => $one("SELECT COUNT(*) FROM user WHERE role = 'student'"),
            'lists'    => $one('SELECT COUNT(*) FROM list WHERE canon_id = ?', [$canonId]),
        ];
    }
}
```

- [ ] **Step 4: Write the admin templates**

Create `templates/admin/layout-nav.php` — the admin's navigation, rendered into the page body:

```php
<nav style="margin-bottom:1rem">
    <ul class="chips">
        <li><a class="chip chip--obdobi" href="/">Přehled</a></li>
        <li><a class="chip chip--podobdobi" href="/kontrola">Ke kontrole</a></li>
        <li><a class="chip chip--narodni" href="/dila">Díla</a></li>
        <li><a class="chip chip--forma" href="/pravidla">Pravidla</a></li>
        <li><a class="chip chip--special" href="/import">Import</a></li>
        <li><a class="chip chip--obdobi" href="/uzivatele">Uživatelé</a></li>
    </ul>
</nav>
```

Create `templates/admin/dashboard.php`:

```php
<?= $this->render('admin/layout-nav') ?>

<h2>Přehled</h2>

<table style="width:100%;border-collapse:collapse">
    <tr><td style="padding:.4rem 0">Děl v kánonu</td><td style="text-align:right"><?= $this->e($stats['works']) ?></td></tr>
    <tr><td style="padding:.4rem 0">Autorů</td><td style="text-align:right"><?= $this->e($stats['authors']) ?></td></tr>
    <tr><td style="padding:.4rem 0">Značek celkem</td><td style="text-align:right"><?= $this->e($stats['tags_total']) ?></td></tr>
    <tr><td style="padding:.4rem 0"><strong>Značek ke kontrole</strong></td>
        <td style="text-align:right"><strong><?= $this->e($stats['tags_unverified']) ?></strong></td></tr>
    <tr><td style="padding:.4rem 0">Studentů</td><td style="text-align:right"><?= $this->e($stats['students']) ?></td></tr>
    <tr><td style="padding:.4rem 0">Sestavených seznamů</td><td style="text-align:right"><?= $this->e($stats['lists']) ?></td></tr>
</table>

<?php if ($stats['tags_unverified'] > 0): ?>
    <p style="margin-top:1.5rem">
        <a class="btn btn--block" href="/kontrola">Zkontrolovat značky (<?= $this->e($stats['tags_unverified']) ?>)</a>
    </p>
<?php else: ?>
    <p class="flash flash--ok" style="margin-top:1.5rem">Všechny značky jsou potvrzené.</p>
<?php endif; ?>
```

Create `templates/admin/forbidden.php`:

```php
<section class="empty">
    <h2>Sem nemáš přístup</h2>
    <p class="muted">Administrace je jen pro učitele a správce.</p>
</section>
```

- [ ] **Step 5: Write the dashboard controller**

Create `src/Admin/Controller/DashboardController.php`:

```php
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
```

- [ ] **Step 6: Write the admin front controller**

Replace `admin/index.php`:

```php
<?php

declare(strict_types=1);

$appRoot = is_file(__DIR__ . '/approot.php')
    ? (string) require __DIR__ . '/approot.php'
    : dirname(__DIR__);

if (is_file($appRoot . '/chyby.php')) {
    require $appRoot . '/chyby.php';
}

require $appRoot . '/vendor/autoload.php';

use Kanon\Admin\AdminRepository;
use Kanon\Admin\Controller\DashboardController;
use Kanon\App\Controller\AuthController;
use Kanon\Auth\Auth;
use Kanon\Auth\LoginThrottle;
use Kanon\Auth\UserRepository;
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

$users    = new UserRepository($pdo);
$throttle = new LoginThrottle($pdo);
$auth     = new Auth($users, $throttle, $session);

$page = function (string $title, string $template, array $data = []) use ($view, $session, $csrf, $auth): string {
    return $view->render('layout', [
        'title'     => $title . ' · administrace',
        'user'      => $auth->user(),
        'csrfToken' => $csrf->token(),
        'flashes'   => $session->takeFlashes(),
        'rulebar'   => '',
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

$authController      = new AuthController($auth, $users, $throttle, $session, $csrf, $page);
$dashboardController = new DashboardController(new AdminRepository($pdo), $canonId, $page);

$router = new Router();

// Login lives on the admin host too, so an admin need not visit the student site.
$router->get('/prihlaseni', static fn (): Response => $authController->showLogin());
$router->post('/prihlaseni', static fn (): Response => $authController->login($_POST));
$router->post('/odhlasit', static fn (): Response => $authController->logout($_POST));

$router->get('/', static fn (): Response => $guard() ?? $dashboardController->show());

$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$match = $router->match($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);

if ($match === null) {
    Response::html($page('Nenalezeno', 'not-found'), 404)->send();

    return;
}

($match['handler'])($match['params'])->send();
```

- [ ] **Step 7: Add the rewrite and create an admin account**

Create `admin/.htaccess` with the same content as `public/.htaccess`:

```apache
# Vše, co není skutečný soubor, obsluhuje jediný vstupní bod.
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>

<FilesMatch "\.(css|js|svg|woff2?|png|jpg)$">
    SetHandler none
</FilesMatch>
```

The admin host has no `assets/` directory of its own, so point the layout's
stylesheets at the student host — they are the same files and the browser caches
them once. In `templates/layout.php` this already works because both hosts serve
`/assets/…`; copy the two asset files into `admin/assets/` with a symlink so the
admin host can serve them:

```bash
cd /data/www/kanonmaker/admin && ln -sfn ../public/assets assets
```

Then create the first administrator:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/create-admin kata@example.test <heslo> "Kata"
```

If that address already has a student account, use a different one — an existing
account is promoted in Task 6, not here.

- [ ] **Step 8: Verify the guard**

Run:

```bash
curl -s -o /dev/null -w 'admin as guest  %{http_code}\n' http://kata-admin.doma.slimak.cz/
curl -s -o /dev/null -w 'admin login     %{http_code}\n' http://kata-admin.doma.slimak.cz/prihlaseni
```

Expected: `302` for the dashboard as a guest (redirect to login) and `200` for the
login page. Then log in as a **student** on the admin host and confirm the
dashboard answers `403`, not the page. Finally log in as the admin and confirm
the statistics match the canon.

- [ ] **Step 9: Commit**

```bash
cd /data/www/kanonmaker
git add admin src/Admin templates/admin tests/Admin
git commit -m "feat: admin shell, role guard and canon dashboard"
```

---

### Task 2: The review queue

**Files:**
- Create: `src/Admin/ReviewRepository.php`, `src/Admin/Controller/ReviewController.php`, `templates/admin/review.php`, `tests/Admin/ReviewRepositoryTest.php`
- Modify: `admin/index.php`

**Interfaces:**
- Consumes: `AdminRepository`, `Auth`, `Csrf`.
- Produces `Kanon\Admin\ReviewRepository::__construct(\PDO $pdo)` with:
  - `groups(int $canonId): list<array{chapter_id:int, chapter:string, tag_group:string, code:string, label:string, count:int, works:list<array{id:int,title:string,authors:string}>}>` — unverified tags grouped by chapter and inferred value, ordered by consequence: `podobdobi` first, then `special`, `forma`, `narodni`.
  - `confirmGroup(int $canonId, int $chapterId, string $tagGroup, string $code): int` — marks every matching unverified tag `source = 'human'`, `verified = 1`; returns how many.
  - `confirmOne(int $workId, int $tagId): bool`
  - `replaceTag(int $canonId, int $workId, string $tagGroup, string $code): bool` — drops whatever the work had in that group and stores the new value as human-verified.
  - `unverifiedCount(int $canonId): int`
- Routes: `GET /kontrola`, `POST /kontrola/potvrdit-skupinu`, `POST /kontrola/opravit`.

This is the screen that makes 346 inferred tags reviewable in an evening rather than a week: a group of 41 works all inferred as *světová* is one button.

- [ ] **Step 1: Write the failing test**

Create `tests/Admin/ReviewRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Admin;

use Kanon\Admin\ReviewRepository;
use Kanon\Db\Database;
use PHPUnit\Framework\TestCase;

final class ReviewRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ReviewRepository $repo;
    private int $canonId;

    protected function setUp(): void
    {
        $config    = require dirname(__DIR__, 2) . '/config.php';
        $this->pdo = Database::connect($config['db']);
        $this->pdo->beginTransaction();

        $this->canonId = (int) $this->pdo->query(
            "SELECT id FROM canon WHERE school_year = '2025/2026'"
        )->fetchColumn();
        $this->repo = new ReviewRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function tagIdFor(string $group, string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM tag WHERE canon_id = ? AND tag_group = ? AND code = ?');
        $stmt->execute([$this->canonId, $group, $code]);

        return (int) $stmt->fetchColumn();
    }

    private function tagsOf(int $workId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.tag_group, t.code, wt.source, wt.verified
             FROM work_tag wt JOIN tag t ON t.id = wt.tag_id WHERE wt.work_id = ?'
        );
        $stmt->execute([$workId]);

        return $stmt->fetchAll();
    }

    public function testTheQueueGroupsUnverifiedTags(): void
    {
        $groups = $this->repo->groups($this->canonId);

        self::assertNotSame([], $groups, 'the curated tags are unverified after import');

        foreach ($groups as $group) {
            self::assertGreaterThan(0, $group['count']);
            self::assertSame($group['count'], count($group['works']));
            self::assertNotSame('', $group['label']);
        }
    }

    public function testTheHardestTagsComeFirst(): void
    {
        $groups = $this->repo->groups($this->canonId);

        self::assertSame(
            'podobdobi',
            $groups[0]['tag_group'],
            'sub-period drives the rule nobody can check by hand, so it is reviewed first'
        );
    }

    public function testTheQueueCoversEveryUnverifiedTag(): void
    {
        $inGroups = array_sum(array_column($this->repo->groups($this->canonId), 'count'));

        self::assertSame($this->repo->unverifiedCount($this->canonId), $inGroups);
    }

    public function testConfirmingAGroupMarksEveryTagHuman(): void
    {
        $group  = $this->repo->groups($this->canonId)[0];
        $before = $this->repo->unverifiedCount($this->canonId);

        $confirmed = $this->repo->confirmGroup(
            $this->canonId,
            $group['chapter_id'],
            $group['tag_group'],
            $group['code']
        );

        self::assertSame($group['count'], $confirmed);
        self::assertSame($before - $confirmed, $this->repo->unverifiedCount($this->canonId));

        $workId = $group['works'][0]['id'];
        foreach ($this->tagsOf($workId) as $tag) {
            if ($tag['tag_group'] === $group['tag_group'] && $tag['code'] === $group['code']) {
                self::assertSame('human', $tag['source']);
                self::assertSame(1, (int) $tag['verified']);
            }
        }
    }

    public function testConfirmingAGroupTwiceConfirmsNothingTheSecondTime(): void
    {
        $group = $this->repo->groups($this->canonId)[0];

        $this->repo->confirmGroup($this->canonId, $group['chapter_id'], $group['tag_group'], $group['code']);

        self::assertSame(
            0,
            $this->repo->confirmGroup($this->canonId, $group['chapter_id'], $group['tag_group'], $group['code'])
        );
    }

    public function testCorrectingAWorkReplacesItsTagInThatGroup(): void
    {
        $group  = $this->repo->groups($this->canonId)[0];
        $workId = $group['works'][0]['id'];

        $replacement = $group['code'] === 'starovek' ? 'baroko' : 'starovek';

        self::assertTrue($this->repo->replaceTag($this->canonId, $workId, 'podobdobi', $replacement));

        $codes = [];
        foreach ($this->tagsOf($workId) as $tag) {
            if ($tag['tag_group'] === 'podobdobi') {
                $codes[] = $tag['code'];
                self::assertSame('human', $tag['source']);
                self::assertSame(1, (int) $tag['verified']);
            }
        }

        self::assertSame([$replacement], $codes, 'a work has exactly one sub-period');
    }

    public function testCorrectingWithAnUnknownTagChangesNothing(): void
    {
        $group  = $this->repo->groups($this->canonId)[0];
        $workId = $group['works'][0]['id'];
        $before = $this->tagsOf($workId);

        self::assertFalse($this->repo->replaceTag($this->canonId, $workId, 'podobdobi', 'neexistuje'));
        self::assertEquals($before, $this->tagsOf($workId));
    }

    public function testConfirmingOneTagLeavesItsNeighboursAlone(): void
    {
        $group  = $this->repo->groups($this->canonId)[0];
        $workId = $group['works'][0]['id'];
        $tagId  = $this->tagIdFor($group['tag_group'], $group['code']);

        self::assertTrue($this->repo->confirmOne($workId, $tagId));
        self::assertFalse($this->repo->confirmOne($workId, $tagId), 'already confirmed');

        self::assertGreaterThan(
            0,
            $this->repo->unverifiedCount($this->canonId),
            'confirming one tag must not touch the rest of the queue'
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter ReviewRepositoryTest
```

Expected: FAIL — `Class "Kanon\Admin\ReviewRepository" not found`.

- [ ] **Step 3: Write the repository**

Create `src/Admin/ReviewRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Admin;

/**
 * The review queue.
 *
 * Tags the importer inferred are grouped by chapter and by the value inferred,
 * so a reviewer can accept 41 works in one action instead of 41. Groups are
 * ordered by consequence: the sub-period and the Czech-poetry flag drive the two
 * rules a student cannot check by hand, so they are reviewed first.
 */
final class ReviewRepository
{
    private const GROUP_PRIORITY = ['podobdobi' => 1, 'special' => 2, 'forma' => 3, 'narodni' => 4, 'obdobi' => 5];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return list<array{chapter_id: int, chapter: string, tag_group: string, code: string,
     *                    label: string, count: int, works: list<array{id: int, title: string, authors: string}>}>
     */
    public function groups(int $canonId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id AS chapter_id, c.name AS chapter, t.tag_group, t.code, t.label,
                    w.id AS work_id, w.title, w.sort_order,
                    COALESCE(GROUP_CONCAT(DISTINCT a.display_name SEPARATOR '; '), '') AS authors
             FROM work_tag wt
             JOIN tag t ON t.id = wt.tag_id
             JOIN work w ON w.id = wt.work_id
             JOIN chapter c ON c.id = w.chapter_id
             LEFT JOIN work_author wa ON wa.work_id = w.id
             LEFT JOIN author a ON a.id = wa.author_id
             WHERE w.canon_id = ? AND wt.verified = 0
             GROUP BY c.id, c.name, t.tag_group, t.code, t.label, w.id, w.title, w.sort_order
             ORDER BY c.sort_order, t.tag_group, t.sort_order, w.sort_order"
        );
        $stmt->execute([$canonId]);

        $groups = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = $row['chapter_id'] . '|' . $row['tag_group'] . '|' . $row['code'];

            $groups[$key] ??= [
                'chapter_id' => (int) $row['chapter_id'],
                'chapter'    => $row['chapter'],
                'tag_group'  => $row['tag_group'],
                'code'       => $row['code'],
                'label'      => $row['label'],
                'count'      => 0,
                'works'      => [],
            ];

            $groups[$key]['count']++;
            $groups[$key]['works'][] = [
                'id'      => (int) $row['work_id'],
                'title'   => $row['title'],
                'authors' => $row['authors'],
            ];
        }

        $groups = array_values($groups);

        usort($groups, static function (array $a, array $b): int {
            $pa = self::GROUP_PRIORITY[$a['tag_group']] ?? 9;
            $pb = self::GROUP_PRIORITY[$b['tag_group']] ?? 9;

            return [$pa, $a['chapter_id'], $a['code']] <=> [$pb, $b['chapter_id'], $b['code']];
        });

        return $groups;
    }

    public function confirmGroup(int $canonId, int $chapterId, string $tagGroup, string $code): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE work_tag wt
             JOIN tag t ON t.id = wt.tag_id
             JOIN work w ON w.id = wt.work_id
             SET wt.source = 'human', wt.verified = 1
             WHERE w.canon_id = ? AND w.chapter_id = ? AND t.tag_group = ? AND t.code = ? AND wt.verified = 0"
        );
        $stmt->execute([$canonId, $chapterId, $tagGroup, $code]);

        return $stmt->rowCount();
    }

    public function confirmOne(int $workId, int $tagId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE work_tag SET source = 'human', verified = 1
             WHERE work_id = ? AND tag_id = ? AND verified = 0"
        );
        $stmt->execute([$workId, $tagId]);

        return $stmt->rowCount() > 0;
    }

    /** Replaces whatever the work had in this group with a human-confirmed value. */
    public function replaceTag(int $canonId, int $workId, string $tagGroup, string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM tag WHERE canon_id = ? AND tag_group = ? AND code = ?');
        $stmt->execute([$canonId, $tagGroup, $code]);
        $tagId = $stmt->fetchColumn();

        if ($tagId === false) {
            return false;
        }

        $this->pdo->prepare(
            'DELETE wt FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
             WHERE wt.work_id = ? AND t.tag_group = ?'
        )->execute([$workId, $tagGroup]);

        $this->pdo->prepare(
            "INSERT INTO work_tag (work_id, tag_id, source, verified) VALUES (?, ?, 'human', 1)"
        )->execute([$workId, (int) $tagId]);

        return true;
    }

    public function unverifiedCount(int $canonId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM work_tag wt JOIN work w ON w.id = wt.work_id
             WHERE w.canon_id = ? AND wt.verified = 0'
        );
        $stmt->execute([$canonId]);

        return (int) $stmt->fetchColumn();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter ReviewRepositoryTest
```

Expected: PASS, 8 tests.

- [ ] **Step 5: Write the queue screen**

Create `templates/admin/review.php`:

```php
<?= $this->render('admin/layout-nav') ?>

<h2>Ke kontrole</h2>

<?php if ($groups === []): ?>
    <p class="flash flash--ok">Všechny značky jsou potvrzené. Není co kontrolovat.</p>
<?php else: ?>
    <p class="muted">
        Zbývá <?= $this->e($remaining) ?> značek v <?= $this->e(count($groups)) ?> skupinách.
        Skupiny jsou seřazené podle důležitosti — literární období a česká poezie první.
    </p>

    <?php foreach ($groups as $group): ?>
        <section style="margin:1.5rem 0;padding-bottom:1rem;border-bottom:1px solid var(--line)">
            <p class="muted" style="margin:0"><?= $this->e($group['chapter']) ?></p>

            <h3 style="margin:.2rem 0 .5rem">
                <?= $this->e(\Kanon\App\Ui::groupLabel($group['tag_group'])) ?>
                → <span class="chip chip--<?= $this->e($group['tag_group']) ?>"><?= $this->e($group['label']) ?></span>
                <span class="muted">(<?= $this->e($group['count']) ?>)</span>
            </h3>

            <details>
                <summary class="muted" style="min-height:var(--tap);display:flex;align-items:center;cursor:pointer">
                    Zobrazit díla a opravit jednotlivě
                </summary>
                <ul class="works">
                    <?php foreach ($group['works'] as $work): ?>
                        <li class="work">
                            <div class="work__body">
                                <?php if ($work['authors'] !== ''): ?>
                                    <div class="work__author"><?= $this->e($work['authors']) ?></div>
                                <?php endif; ?>
                                <div class="work__title"><?= $this->e($work['title']) ?></div>
                                <form method="post" action="/kontrola/opravit" style="margin-top:.4rem;display:flex;gap:.4rem">
                                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                    <input type="hidden" name="work_id" value="<?= $this->e($work['id']) ?>">
                                    <input type="hidden" name="skupina" value="<?= $this->e($group['tag_group']) ?>">
                                    <select name="znacka">
                                        <?php foreach ($choices[$group['tag_group']] ?? [] as $choice): ?>
                                            <option value="<?= $this->e($choice['code']) ?>"
                                                <?= $choice['code'] === $group['code'] ? 'selected' : '' ?>>
                                                <?= $this->e($choice['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn--quiet">Uložit</button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>

            <form method="post" action="/kontrola/potvrdit-skupinu" style="margin-top:.75rem">
                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                <input type="hidden" name="kapitola" value="<?= $this->e($group['chapter_id']) ?>">
                <input type="hidden" name="skupina" value="<?= $this->e($group['tag_group']) ?>">
                <input type="hidden" name="znacka" value="<?= $this->e($group['code']) ?>">
                <button class="btn btn--block">Potvrdit všech <?= $this->e($group['count']) ?></button>
            </form>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
```

- [ ] **Step 6: Write the controller**

Create `src/Admin/Controller/ReviewController.php`:

```php
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
    public function __construct(
        private readonly ReviewRepository $review,
        private readonly WorkRepository $works,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly int $canonId,
        private readonly \Closure $page,
    ) {
    }

    public function show(): Response
    {
        return Response::html(($this->page)('Ke kontrole', 'admin/review', [
            'groups'    => $this->review->groups($this->canonId),
            'remaining' => $this->review->unverifiedCount($this->canonId),
            'choices'   => $this->works->tagGroups($this->canonId),
        ]));
    }

    public function confirmGroup(array $input): Response
    {
        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect('/kontrola');
        }

        $confirmed = $this->review->confirmGroup(
            $this->canonId,
            (int) ($input['kapitola'] ?? 0),
            (string) ($input['skupina'] ?? ''),
            (string) ($input['znacka'] ?? ''),
        );

        $this->session->flash('ok', "Potvrzeno {$confirmed} značek.");

        return Response::redirect('/kontrola');
    }

    public function correct(array $input): Response
    {
        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect('/kontrola');
        }

        $ok = $this->review->replaceTag(
            $this->canonId,
            (int) ($input['work_id'] ?? 0),
            (string) ($input['skupina'] ?? ''),
            (string) ($input['znacka'] ?? ''),
        );

        $this->session->flash($ok ? 'ok' : 'warn', $ok ? 'Značka opravena.' : 'Takovou značku neznám.');

        return Response::redirect('/kontrola');
    }
}
```

- [ ] **Step 7: Wire the routes**

In `admin/index.php`:

```php
use Kanon\Admin\Controller\ReviewController;
use Kanon\Admin\ReviewRepository;
use Kanon\Repo\WorkRepository;

$workRepo         = new WorkRepository($pdo);
$reviewRepo       = new ReviewRepository($pdo);
$reviewController = new ReviewController($reviewRepo, $workRepo, $session, $csrf, $canonId, $page);

$router->get('/kontrola', static fn (): Response => $guard() ?? $reviewController->show());
$router->post('/kontrola/potvrdit-skupinu', static fn (): Response => $guard() ?? $reviewController->confirmGroup($_POST));
$router->post('/kontrola/opravit', static fn (): Response => $guard() ?? $reviewController->correct($_POST));
```

- [ ] **Step 8: Confirm one group by hand**

Log in as the admin, open `/kontrola`, and confirm the first group. The count in
the heading must drop by exactly that group's size, and the student site must
show those chips without the `?` mark afterwards.

```bash
curl -s http://kata.doma.slimak.cz/kanon | grep -c 'chip--unverified'
```

Expected: the number falls after each confirmation.

- [ ] **Step 9: Commit**

```bash
cd /data/www/kanonmaker
git add src/Admin templates/admin/review.php admin/index.php tests/Admin/ReviewRepositoryTest.php
git commit -m "feat: review queue confirming inferred tags in bulk"
```

---

### Task 3: Editing works and rules

**Files:**
- Create: `src/Admin/Controller/WorkController.php`, `src/Admin/Controller/RuleController.php`, `templates/admin/works.php`, `templates/admin/work-edit.php`, `templates/admin/rules.php`, `tests/Admin/RuleEditTest.php`
- Modify: `src/Admin/AdminRepository.php`, `admin/index.php`

**Interfaces:**
- Consumes: `WorkRepository`, `ReviewRepository`, `Kanon\Rules\RuleFactory`.
- Produces, added to `AdminRepository`:
  - `rules(int $canonId): list<array{id:int, type:string, params:array, label:string, enabled:bool, sort_order:int}>`
  - `updateRule(int $ruleId, array $params, bool $enabled): bool` — validates by building the rule through `RuleFactory` first and returns false if that throws, so a bad edit can never reach the engine.
  - `updateWork(int $canonId, int $workId, string $title, ?string $note): bool`
- Routes: `GET /dila`, `GET /dila/{id}`, `POST /dila/{id}`, `GET /pravidla`, `POST /pravidla/{id}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Admin/RuleEditTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Admin;

use Kanon\Admin\AdminRepository;
use Kanon\Db\Database;
use Kanon\Rules\RuleSet;
use PHPUnit\Framework\TestCase;

final class RuleEditTest extends TestCase
{
    private \PDO $pdo;
    private AdminRepository $repo;
    private int $canonId;

    protected function setUp(): void
    {
        $config    = require dirname(__DIR__, 2) . '/config.php';
        $this->pdo = Database::connect($config['db']);
        $this->pdo->beginTransaction();

        $this->canonId = (int) $this->pdo->query(
            "SELECT id FROM canon WHERE school_year = '2025/2026'"
        )->fetchColumn();
        $this->repo = new AdminRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function ruleOfType(string $type): array
    {
        foreach ($this->repo->rules($this->canonId) as $rule) {
            if ($rule['type'] === $type) {
                return $rule;
            }
        }

        self::fail("no {$type} rule seeded");
    }

    public function testRulesComeBackWithDecodedParams(): void
    {
        $rules = $this->repo->rules($this->canonId);

        self::assertCount(12, $rules);
        self::assertIsArray($rules[0]['params']);
        self::assertSame(25, $this->ruleOfType('min_total')['params']['min']);
    }

    public function testChangingAMinimumTakesEffectInTheEngine(): void
    {
        $rule = $this->ruleOfType('min_total');

        self::assertTrue($this->repo->updateRule($rule['id'], ['min' => 20], true));

        $results = RuleSet::fromCanon($this->pdo, $this->canonId)->evaluate([]);
        $totals  = array_values(array_filter(
            $results,
            static fn ($r): bool => $r->label === $rule['label']
        ));

        self::assertSame(20, $totals[0]->required, 'the engine reads the edited value');
    }

    public function testDisablingARuleRemovesItFromTheCheck(): void
    {
        $rule = $this->ruleOfType('max_per_author');

        self::assertTrue($this->repo->updateRule($rule['id'], $rule['params'], false));
        self::assertCount(11, RuleSet::fromCanon($this->pdo, $this->canonId)->evaluate([]));
    }

    public function testAnEditTheEngineCannotUnderstandIsRefused(): void
    {
        $rule = $this->ruleOfType('min_count');

        self::assertFalse(
            $this->repo->updateRule($rule['id'], ['group' => 'forma'], true),
            'min_count without a code or a minimum must not be stored'
        );

        $stored = $this->repo->rules($this->canonId);
        foreach ($stored as $current) {
            if ($current['id'] === $rule['id']) {
                self::assertSame($rule['params'], $current['params'], 'the old parameters survive');
            }
        }
    }

    public function testEveryRuleStillBuildsAfterAValidEdit(): void
    {
        $rule = $this->ruleOfType('min_distinct');
        $this->repo->updateRule($rule['id'], ['scope_group' => 'obdobi', 'scope_code' => 'do18', 'group' => 'podobdobi', 'min' => 2], true);

        self::assertCount(12, RuleSet::fromCanon($this->pdo, $this->canonId)->evaluate([]));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter RuleEditTest
```

Expected: FAIL — `Call to undefined method Kanon\Admin\AdminRepository::rules()`.

- [ ] **Step 3: Extend the repository**

Append these methods to `src/Admin/AdminRepository.php` (inside the class, and add `use Kanon\Rules\RuleFactory;` at the top):

```php
    /** @return list<array{id:int, type:string, params:array, label:string, enabled:bool, sort_order:int}> */
    public function rules(int $canonId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rule WHERE canon_id = ? ORDER BY sort_order');
        $stmt->execute([$canonId]);

        return array_map(
            static fn (array $r): array => [
                'id'         => (int) $r['id'],
                'type'       => $r['type'],
                'params'     => json_decode($r['params'], true, 512, JSON_THROW_ON_ERROR),
                'label'      => $r['label'],
                'enabled'    => (int) $r['enabled'] === 1,
                'sort_order' => (int) $r['sort_order'],
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Rejects any edit the rules engine could not evaluate, so a typo in the
     * administration can never reach a student's rule check.
     */
    public function updateRule(int $ruleId, array $params, bool $enabled): bool
    {
        $stmt = $this->pdo->prepare('SELECT type, label FROM rule WHERE id = ?');
        $stmt->execute([$ruleId]);
        $rule = $stmt->fetch();

        if ($rule === false) {
            return false;
        }

        $json = json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        try {
            RuleFactory::fromRow(['type' => $rule['type'], 'params' => $json, 'label' => $rule['label']])
                ->evaluate([]);
        } catch (\Throwable) {
            return false;
        }

        $this->pdo->prepare('UPDATE rule SET params = ?, enabled = ? WHERE id = ?')
            ->execute([$json, $enabled ? 1 : 0, $ruleId]);

        return true;
    }

    public function updateWork(int $canonId, int $workId, string $title, ?string $note): bool
    {
        if (trim($title) === '') {
            return false;
        }

        $stmt = $this->pdo->prepare('UPDATE work SET title = ?, note = ? WHERE canon_id = ? AND id = ?');
        $stmt->execute([trim($title), $note === null || trim($note) === '' ? null : trim($note), $canonId, $workId]);

        return true;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter RuleEditTest
```

Expected: PASS, 5 tests.

- [ ] **Step 5: Write the rules screen**

Create `templates/admin/rules.php`:

```php
<?= $this->render('admin/layout-nav') ?>

<h2>Pravidla</h2>
<p class="muted">
    Měnit lze jen čísla a zapnutí. Typ pravidla je v kódu — díky tomu nejde
    uložit pravidlo, které by kontrola neuměla vyhodnotit.
</p>

<?php foreach ($rules as $rule): ?>
    <form method="post" action="/pravidla/<?= $this->e($rule['id']) ?>"
          style="margin:1.2rem 0;padding-bottom:1rem;border-bottom:1px solid var(--line)">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

        <p style="margin:0 0 .4rem"><strong><?= $this->e($rule['label']) ?></strong></p>
        <p class="muted" style="margin:0 0 .5rem"><?= $this->e($rule['type']) ?></p>

        <?php foreach ($rule['params'] as $key => $value): ?>
            <?php if (is_int($value)): ?>
                <label class="field">
                    <span><?= $this->e($key) ?></span>
                    <input type="number" name="params[<?= $this->e($key) ?>]" value="<?= $this->e($value) ?>" min="0">
                </label>
            <?php else: ?>
                <input type="hidden" name="params[<?= $this->e($key) ?>]" value="<?= $this->e(is_bool($value) ? ($value ? '1' : '0') : $value) ?>">
                <p class="muted" style="margin:.1rem 0"><?= $this->e($key) ?>: <?= $this->e(is_bool($value) ? ($value ? 'ano' : 'ne') : $value) ?></p>
            <?php endif; ?>
        <?php endforeach; ?>

        <label class="checkline">
            <input type="checkbox" name="enabled" value="1" <?= $rule['enabled'] ? 'checked' : '' ?>> Zapnuto
        </label>

        <button class="btn btn--quiet">Uložit</button>
    </form>
<?php endforeach; ?>
```

- [ ] **Step 6: Write the works screens**

Create `templates/admin/works.php`:

```php
<?= $this->render('admin/layout-nav') ?>

<h2>Díla</h2>

<form method="get" action="/dila">
    <label class="field">
        <span>Hledat</span>
        <input type="search" name="q" value="<?= $this->e($q) ?>" placeholder="autor nebo název">
    </label>
</form>

<p class="muted"><?= $this->e(count($works)) ?> děl</p>

<ul class="works">
    <?php foreach ($works as $work): ?>
        <li class="work">
            <div class="work__body">
                <?php if ($work['authors'] !== ''): ?>
                    <div class="work__author"><?= $this->e($work['authors']) ?></div>
                <?php endif; ?>
                <div class="work__title">
                    <a href="/dila/<?= $this->e($work['id']) ?>" class="plain"><?= $this->e($work['title']) ?></a>
                </div>
                <ul class="chips">
                    <?php foreach (\Kanon\App\Ui::orderedTags($work['tags']) as $tag): ?>
                        <li class="chip chip--<?= $this->e($tag['group']) ?><?= $tag['verified'] ? '' : ' chip--unverified' ?>">
                            <?= $this->e($tag['label']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
```

Create `templates/admin/work-edit.php`:

```php
<?= $this->render('admin/layout-nav') ?>

<p class="muted"><a href="/dila">← zpět na díla</a></p>

<h2><?= $this->e($work['title']) ?></h2>
<?php if ($work['authors'] !== ''): ?>
    <p class="work__author"><?= $this->e($work['authors']) ?></p>
<?php endif; ?>
<p class="muted"><?= $this->e($work['chapter']) ?></p>

<form method="post" action="/dila/<?= $this->e($work['id']) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

    <label class="field">
        <span>Název</span>
        <input type="text" name="title" value="<?= $this->e($work['title']) ?>" required>
    </label>

    <label class="field">
        <span>Poznámka (vydání, rozsah…)</span>
        <input type="text" name="note" value="<?= $this->e($work['note'] ?? '') ?>">
    </label>

    <p style="margin:1rem 0 .3rem">Značky</p>
    <?php foreach ($choices as $group => $options): ?>
        <?php $current = $work['tags'][$group][0]['code'] ?? ''; ?>
        <label class="field">
            <span><?= $this->e(\Kanon\App\Ui::groupLabel($group)) ?></span>
            <select name="tags[<?= $this->e($group) ?>]">
                <option value="">— nic —</option>
                <?php foreach ($options as $option): ?>
                    <option value="<?= $this->e($option['code']) ?>" <?= $option['code'] === $current ? 'selected' : '' ?>>
                        <?= $this->e($option['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endforeach; ?>

    <button class="btn btn--block">Uložit</button>
</form>
```

- [ ] **Step 7: Write the controllers**

Create `src/Admin/Controller/RuleController.php`:

```php
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

        $raw = is_array($input['params'] ?? null) ? $input['params'] : [];
        $typed = [];
        foreach ($raw as $key => $value) {
            $typed[$key] = match (true) {
                $key === 'distinct_form'    => $value === '1',
                is_numeric($value)          => (int) $value,
                default                     => (string) $value,
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
```

Create `src/Admin/Controller/WorkController.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Admin\Controller;

use Kanon\Admin\AdminRepository;
use Kanon\Admin\ReviewRepository;
use Kanon\Http\Csrf;
use Kanon\Http\Response;
use Kanon\Http\Session;
use Kanon\Repo\WorkRepository;

final class WorkController
{
    public function __construct(
        private readonly AdminRepository $admin,
        private readonly ReviewRepository $review,
        private readonly WorkRepository $works,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly int $canonId,
        private readonly \Closure $page,
    ) {
    }

    public function index(array $query): Response
    {
        $q = trim((string) ($query['q'] ?? ''));

        return Response::html(($this->page)('Díla', 'admin/works', [
            'q'     => $q,
            'works' => $q === ''
                ? array_slice($this->works->browse($this->canonId), 0, 100)
                : $this->works->search($this->canonId, $q, 100),
        ]));
    }

    public function edit(array $params): Response
    {
        $work = $this->works->find($this->canonId, (int) $params['id']);

        if ($work === null) {
            return Response::html(($this->page)('Nenalezeno', 'not-found'), 404);
        }

        return Response::html(($this->page)($work['title'], 'admin/work-edit', [
            'work'    => $work,
            'choices' => $this->works->tagGroups($this->canonId),
        ]));
    }

    public function update(array $params, array $input): Response
    {
        $workId = (int) $params['id'];

        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect('/dila/' . $workId);
        }

        $this->admin->updateWork(
            $this->canonId,
            $workId,
            (string) ($input['title'] ?? ''),
            $input['note'] ?? null
        );

        foreach ((array) ($input['tags'] ?? []) as $group => $code) {
            if ((string) $code !== '') {
                $this->review->replaceTag($this->canonId, $workId, (string) $group, (string) $code);
            }
        }

        $this->session->flash('ok', 'Uloženo.');

        return Response::redirect('/dila/' . $workId);
    }
}
```

- [ ] **Step 8: Wire the routes**

In `admin/index.php`:

```php
use Kanon\Admin\Controller\RuleController;
use Kanon\Admin\Controller\WorkController;

$adminRepo      = new AdminRepository($pdo);
$ruleController = new RuleController($adminRepo, $session, $csrf, $canonId, $page);
$workController = new WorkController($adminRepo, $reviewRepo, $workRepo, $session, $csrf, $canonId, $page);

$router->get('/dila', static fn (): Response => $guard() ?? $workController->index($_GET));
$router->get('/dila/{id}', static fn (array $p): Response => $guard() ?? $workController->edit($p));
$router->post('/dila/{id}', static fn (array $p): Response => $guard() ?? $workController->update($p, $_POST));
$router->get('/pravidla', static fn (): Response => $guard() ?? $ruleController->index());
$router->post('/pravidla/{id}', static fn (array $p): Response => $guard() ?? $ruleController->update($p, $_POST));
```

Reuse the single `AdminRepository` instance for the dashboard too, rather than
constructing a second one.

- [ ] **Step 9: Verify by editing**

As the admin: open `/pravidla`, change "Celkem 25 titulů" to 24, save, reload the
student site and confirm the bar now reads `n/24`. Change it back to 25. Then
open a work in `/dila`, change its literary form, and confirm the chip changes on
the student site and loses its `?`.

- [ ] **Step 10: Commit**

```bash
cd /data/www/kanonmaker
git add src/Admin templates/admin admin/index.php tests/Admin/RuleEditTest.php
git commit -m "feat: edit works and rule parameters, refusing edits the engine cannot evaluate"
```

---

### Task 4: The import runner and user administration

**Files:**
- Create: `src/Admin/Controller/ImportController.php`, `src/Admin/Controller/UserController.php`, `templates/admin/import.php`, `templates/admin/users.php`, `tests/Admin/UserAdminTest.php`
- Modify: `src/Admin/AdminRepository.php`, `admin/index.php`

**Interfaces:**
- Consumes: `Kanon\Import\Importer` and its collaborators, `UserRepository`.
- Produces, added to `AdminRepository`:
  - `students(): list<array{id:int, email:string, display_name:string, role:string, active:bool, created_at:string, works:int}>`
  - `setRole(int $userId, string $role): bool` — accepts only `student` or `admin`
  - `setActive(int $userId, bool $active): bool`
- And `Kanon\Admin\Controller\ImportController::form(): Response`, `::run(array $input): Response`.
- Routes: `GET /import`, `POST /import`, `GET /uzivatele`, `POST /uzivatele/{id}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Admin/UserAdminTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Admin;

use Kanon\Admin\AdminRepository;
use Kanon\Auth\UserRepository;
use Kanon\Db\Database;
use PHPUnit\Framework\TestCase;

final class UserAdminTest extends TestCase
{
    private \PDO $pdo;
    private AdminRepository $repo;
    private UserRepository $users;
    private int $userId;

    protected function setUp(): void
    {
        $config    = require dirname(__DIR__, 2) . '/config.php';
        $this->pdo = Database::connect($config['db']);
        $this->pdo->beginTransaction();

        $this->repo   = new AdminRepository($this->pdo);
        $this->users  = new UserRepository($this->pdo);
        $this->userId = $this->users->create(
            'u' . bin2hex(random_bytes(4)) . '@example.test',
            'tajneheslo123',
            'Testovací student'
        );
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function find(int $id): ?array
    {
        foreach ($this->repo->students() as $student) {
            if ($student['id'] === $id) {
                return $student;
            }
        }

        return null;
    }

    public function testTheListIncludesTheNewAccount(): void
    {
        $student = $this->find($this->userId);

        self::assertNotNull($student);
        self::assertSame('Testovací student', $student['display_name']);
        self::assertSame('student', $student['role']);
        self::assertTrue($student['active']);
        self::assertSame(0, $student['works'], 'a fresh account has an empty list');
    }

    public function testPromotingAndDemoting(): void
    {
        self::assertTrue($this->repo->setRole($this->userId, 'admin'));
        self::assertSame('admin', $this->find($this->userId)['role']);

        self::assertTrue($this->repo->setRole($this->userId, 'student'));
        self::assertSame('student', $this->find($this->userId)['role']);
    }

    public function testAnUnknownRoleIsRefused(): void
    {
        self::assertFalse($this->repo->setRole($this->userId, 'reditel'));
        self::assertSame('student', $this->find($this->userId)['role']);
    }

    public function testDeactivatingBlocksLogin(): void
    {
        self::assertTrue($this->repo->setActive($this->userId, false));
        self::assertFalse($this->find($this->userId)['active']);

        $user = $this->users->findById($this->userId);
        self::assertSame(0, (int) $user['active']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter UserAdminTest
```

Expected: FAIL — `Call to undefined method Kanon\Admin\AdminRepository::students()`.

- [ ] **Step 3: Extend the repository**

Append to `src/Admin/AdminRepository.php`:

```php
    /**
     * @return list<array{id:int, email:string, display_name:string, role:string,
     *                    active:bool, created_at:string, works:int}>
     */
    public function students(): array
    {
        $stmt = $this->pdo->query(
            'SELECT u.id, u.email, u.display_name, u.role, u.active, u.created_at,
                    COALESCE(COUNT(li.work_id), 0) AS works
             FROM user u
             LEFT JOIN list l ON l.user_id = u.id
             LEFT JOIN list_item li ON li.list_id = l.id
             GROUP BY u.id, u.email, u.display_name, u.role, u.active, u.created_at
             ORDER BY u.created_at DESC'
        );

        return array_map(
            static fn (array $r): array => [
                'id'           => (int) $r['id'],
                'email'        => $r['email'],
                'display_name' => $r['display_name'],
                'role'         => $r['role'],
                'active'       => (int) $r['active'] === 1,
                'created_at'   => $r['created_at'],
                'works'        => (int) $r['works'],
            ],
            $stmt->fetchAll()
        );
    }

    public function setRole(int $userId, string $role): bool
    {
        if (!in_array($role, ['student', 'admin'], true)) {
            return false;
        }

        $this->pdo->prepare('UPDATE user SET role = ? WHERE id = ?')->execute([$role, $userId]);

        return true;
    }

    public function setActive(int $userId, bool $active): bool
    {
        $this->pdo->prepare('UPDATE user SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $userId]);

        return true;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter UserAdminTest
```

Expected: PASS, 4 tests.

- [ ] **Step 5: Write the import screen and controller**

Create `templates/admin/import.php`:

```php
<?= $this->render('admin/layout-nav') ?>

<h2>Import kánonu</h2>

<p class="muted">
    Import čte dva soubory z repozitáře: <code>data/canon-2025-2026.html</code>
    (kopie školního dokumentu) a <code>data/tags-2025-2026.csv</code> (ručně
    doplněné značky). Nesahá na internet a nikdy nepřepíše značku, kterou
    potvrdil člověk.
</p>

<form method="post" action="/import">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
    <label class="checkline">
        <input type="checkbox" name="prune" value="1"> Smazat díla, která už v dokumentu nejsou
    </label>
    <button class="btn btn--block" name="mode" value="dry">Nanečisto (nic se nezapíše)</button>
    <button class="btn btn--quiet btn--block" name="mode" value="apply" style="margin-top:.5rem">Provést import</button>
</form>

<?php if ($report !== null): ?>
    <h3 style="margin-top:1.5rem"><?= $this->e($dryRun ? 'Nanečisto — nic se nezapsalo' : 'Import proveden') ?></h3>
    <pre style="overflow-x:auto;font-size:.85rem"><?php foreach ($report->lines() as $line): ?><?= $this->e($line) ?>

<?php endforeach; ?></pre>

    <?php if ($report->unusedFixes !== []): ?>
        <p class="flash flash--warn">
            Některé opravy vstupu už nesedí — dokument se nejspíš změnil.
        </p>
    <?php endif; ?>

    <?php if ($report->orphans !== []): ?>
        <h3>Díla, která už v dokumentu nejsou</h3>
        <ul>
            <?php foreach ($report->orphans as $orphan): ?>
                <li>
                    <?= $this->e($orphan['title']) ?>
                    <?php if ($orphan['in_lists'] > 0): ?>
                        <strong>— je v <?= $this->e($orphan['in_lists']) ?> seznamech studentů</strong>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>
```

Create `src/Admin/Controller/ImportController.php`:

```php
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
```

- [ ] **Step 6: Write the users screen and controller**

Create `templates/admin/users.php`:

```php
<?= $this->render('admin/layout-nav') ?>

<h2>Uživatelé</h2>
<p class="muted"><?= $this->e(count($students)) ?> účtů</p>

<?php foreach ($students as $student): ?>
    <div style="padding:.8rem 0;border-bottom:1px solid var(--line)">
        <div><strong><?= $this->e($student['display_name']) ?></strong>
            <?php if ($student['role'] === 'admin'): ?>
                <span class="chip chip--special">správce</span>
            <?php endif; ?>
            <?php if (!$student['active']): ?>
                <span class="chip chip--podobdobi">zablokován</span>
            <?php endif; ?>
        </div>
        <div class="muted"><?= $this->e($student['email']) ?> · <?= $this->e($student['works']) ?> děl v seznamu</div>

        <form method="post" action="/uzivatele/<?= $this->e($student['id']) ?>" style="margin-top:.4rem;display:flex;gap:.4rem;flex-wrap:wrap">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <button class="btn btn--quiet btn--small" name="akce"
                    value="<?= $student['role'] === 'admin' ? 'demote' : 'promote' ?>">
                <?= $student['role'] === 'admin' ? 'Odebrat správce' : 'Udělat správcem' ?>
            </button>
            <button class="btn btn--quiet btn--small" name="akce"
                    value="<?= $student['active'] ? 'deactivate' : 'activate' ?>">
                <?= $student['active'] ? 'Zablokovat' : 'Odblokovat' ?>
            </button>
        </form>
    </div>
<?php endforeach; ?>
```

Create `src/Admin/Controller/UserController.php`:

```php
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
```

- [ ] **Step 7: Wire the routes**

In `admin/index.php`:

```php
use Kanon\Admin\Controller\ImportController;
use Kanon\Admin\Controller\UserController;
use Kanon\Import\ChapterTagger;
use Kanon\Import\CuratedTags;
use Kanon\Import\DocumentParser;
use Kanon\Import\EntryFixes;
use Kanon\Import\EntrySplitter;
use Kanon\Import\HintTagger;
use Kanon\Import\Importer;

$importer = new Importer(
    $pdo,
    new DocumentParser(),
    new EntrySplitter(),
    new ChapterTagger(),
    new HintTagger(),
    new CuratedTags(),
    new EntryFixes($appRoot . '/data/entry-fixes-2025-2026.json'),
);

$importController = new ImportController($importer, $session, $csrf, $canonId, $appRoot, $page);
$userController   = new UserController($adminRepo, $auth, $session, $csrf, $page);

$router->get('/import', static fn (): Response => $guard() ?? $importController->form());
$router->post('/import', static fn (): Response => $guard() ?? $importController->run($_POST));
$router->get('/uzivatele', static fn (): Response => $guard() ?? $userController->index());
$router->post('/uzivatele/{id}', static fn (array $p): Response => $guard() ?? $userController->update($p, $_POST));
```

- [ ] **Step 8: Verify by running an import from the browser**

As the admin, open `/import` and press "Nanečisto". The report must show
`works created 0`, `works unchanged 461`, `entry fixes applied 10` and
`entry fixes unused 0`. Then confirm the student site is unchanged.

- [ ] **Step 9: Commit**

```bash
cd /data/www/kanonmaker
git add src/Admin templates/admin admin/index.php tests/Admin/UserAdminTest.php
git commit -m "feat: import runner with dry run, and user administration"
```

---

### Task 5: Finish — admin smoke tests and the phone pass

**Files:**
- Create: `tests/Admin/AdminSmokeTest.php`
- Modify: `README.md`

- [ ] **Step 1: Write the smoke test**

Create `tests/Admin/AdminSmokeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The administration must never be reachable without an admin account. */
final class AdminSmokeTest extends TestCase
{
    private const BASE = 'http://kata-admin.doma.slimak.cz';

    /** @return array{0: int, 1: string} */
    private function fetch(string $path, bool $follow = false): array
    {
        $context = stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 15, 'follow_location' => $follow ? 1 : 0],
        ]);
        $body = @file_get_contents(self::BASE . $path, false, $context);

        if ($body === false && ($http_response_header ?? []) === []) {
            self::markTestSkipped('The admin site is not reachable from the test runner.');
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, (string) $body];
    }

    public static function protectedPages(): array
    {
        return [
            'dashboard' => ['/'],
            'review'    => ['/kontrola'],
            'works'     => ['/dila'],
            'rules'     => ['/pravidla'],
            'import'    => ['/import'],
            'users'     => ['/uzivatele'],
        ];
    }

    #[DataProvider('protectedPages')]
    public function testGuestsAreSentToLogin(string $path): void
    {
        [$status] = $this->fetch($path);

        self::assertSame(302, $status, "{$path} must not be readable by a guest");
    }

    public function testTheLoginPageIsReachable(): void
    {
        [$status, $body] = $this->fetch('/prihlaseni');

        self::assertSame(200, $status);
        self::assertStringContainsString('Přihlášení', $body);
    }

    public function testTheAdminSiteServesItsStylesheet(): void
    {
        [$status] = $this->fetch('/assets/tokens.css');

        self::assertSame(200, $status, 'the assets symlink is missing');
    }

    public function testAnUnknownAdminPathIsFourOhFour(): void
    {
        [$status] = $this->fetch('/tudy-ne');

        self::assertSame(404, $status);
    }
}
```

- [ ] **Step 2: Run it**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter AdminSmokeTest
```

Expected: PASS, 9 tests.

- [ ] **Step 3: Check the admin at phone width**

Open `/`, `/kontrola`, `/dila`, `/dila/{id}`, `/pravidla`, `/import` and
`/uzivatele` at 360 px. The review queue is the one to watch: its group blocks
and the "Potvrdit všech N" button must be reachable without horizontal scrolling.

- [ ] **Step 4: Update the README**

Add to `README.md`, after the development section:

```markdown
## Administrace

`http://kata-admin.doma.slimak.cz/` — jen pro účty s rolí `admin`.

- **Ke kontrole** — potvrzování značek, které doplnil import. Skupiny jsou
  seřazené podle důležitosti; „Potvrdit všech N" vyřídí celou skupinu naráz.
- **Díla** — úprava názvu, poznámky a značek jednoho díla.
- **Pravidla** — čísla ze školních kritérií. Uložit nejde nic, co by kontrola
  neuměla vyhodnotit.
- **Import** — nejdřív nanečisto, pak doopravdy.
- **Uživatelé** — role a blokování účtů.

Prvního správce založí:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/create-admin <e-mail> <heslo> <jméno>
```
```

- [ ] **Step 5: Run the whole suite and commit**

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit
cd /data/www/kanonmaker
git add README.md tests/Admin/AdminSmokeTest.php
git commit -m "feat: admin smoke tests and documentation"
```

---

## Notes for the executor

- **The guard runs before every admin handler.** `$guard() ?? $controller->method()` is the pattern; forgetting it exposes the page to any logged-in student.
- **`replaceTag` is the only way tags change in the administration**, so every edit lands as `source = 'human'` and survives the next import.
- **A rule edit is validated by building the rule**, not by checking fields. If `RuleFactory` cannot construct and evaluate it, it is not saved.
- **Nobody may change their own account** on the users screen — locking yourself out of the administration has no recovery path short of the command line.
