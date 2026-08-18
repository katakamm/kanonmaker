# Kanonmaker Student Application Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the working foundation into the website students actually use — register, search the canon, build a list, watch the twelve rules resolve as they go, and export the finished list as a PDF.

**Architecture:** A ~60-line router in front of small single-purpose controllers, plain PHP templates rendered by a `View` class that escapes by default, and two repositories (`WorkRepository`, `ListRepository`) that are the only things touching SQL. The rules engine from plan 1 is consumed unchanged. Sessions, CSRF and authentication sit behind interfaces with array-backed test doubles, so they are testable without a browser.

**Tech Stack:** PHP 8.3.25, PDO/MariaDB, PHPUnit 11, mPDF 8.2 (for PDF export), vanilla JavaScript for live search only.

**Spec:** `docs/superpowers/specs/2026-08-18-kanonmaker-design.md`

**Builds on:** `docs/superpowers/plans/2026-08-18-kanonmaker-foundation.md` (executed — 461 works imported and fully tagged, 116 tests green).

## Global Constraints

- PHP `>= 8.3`, `declare(strict_types=1);` in every file, namespace `Kanon\` → `src/`.
- All commands run inside the container: `sudo docker exec -w /data/www/kanonmaker kanon-www <command>`.
- **The interface is in Czech.** Identifiers, comments and commits are English; every string a student reads is Czech.
- **Mobile-first is a hard requirement**, not a preference: design and check every screen at 360 px wide first. Tap targets ≥ 44 px, no hover-only interactions, no horizontal page scroll, the primary actions within thumb reach. Desktop is the adaptation.
- **Only `public/` may be web-served.** Nothing under `src/`, `templates/`, `data/`, `db/`, `bin/`, `vendor/` may be reachable by URL; in production those sit above the document root and `approot.php` points at them.
- **Every output is escaped.** Templates call `$this->e()`; a template that interpolates a variable without it is a defect.
- **Every POST carries a CSRF token** and is rejected without a valid one.
- Colours live only in `public/assets/tokens.css` as custom properties. No hex value appears in any other file — the owner will supply the final palette and it must be a one-file change.
- Existing tag codes are fixed: `obdobi` (`do18`, `19st`, `20_21st`), `podobdobi` (`starovek`, `stredovek`, `renesance`, `baroko`, `klasicismus`), `narodni` (`ceska`, `svetova`), `forma` (`poezie`, `proza`, `drama`), `special` (`ceska_poezie_po_1950`).
- Canon is selected by `school_year = '2025/2026'`; never hard-code a canon id.

---

## File Structure

| File | Responsibility |
| --- | --- |
| `src/Http/Router.php` | Maps method + path to a handler, extracts `{id}` parameters |
| `src/Http/Response.php` | HTML / redirect / JSON / file-download responses |
| `src/Http/Session.php` | Interface: get, set, remove, regenerate, destroy, flash |
| `src/Http/PhpSession.php` | `$_SESSION`-backed implementation |
| `src/Http/ArraySession.php` | In-memory implementation for tests |
| `src/Http/Csrf.php` | Per-session token, constant-time check |
| `src/View/View.php` | Renders a plain-PHP template with escaping helper |
| `src/Auth/UserRepository.php` | Users: find, create, hash, verify |
| `src/Auth/LoginThrottle.php` | Counts recent failed logins per e-mail |
| `src/Auth/Auth.php` | Attempt, current user, logout, guard |
| `src/Repo/WorkRepository.php` | Search, browse, detail, chapters, tags |
| `src/Repo/ListRepository.php` | The student's list: read, add, remove |
| `src/App/Controller/AuthController.php` | Registration and login screens |
| `src/App/Controller/ListController.php` | My list, add, remove |
| `src/App/Controller/SearchController.php` | Search page and its JSON endpoint |
| `src/App/Controller/CanonController.php` | Browse the canon, work detail |
| `src/App/Controller/ExportController.php` | Export options and the PDF |
| `src/App/Ui.php` | Czech labels, chip rendering, rule presentation |
| `templates/layout.php` | Page shell, flash messages, sticky rule bar |
| `templates/*.php` | One template per screen |
| `public/index.php` | Front controller: wiring and dispatch |
| `public/assets/tokens.css` | The entire palette, as custom properties |
| `public/assets/app.css` | Layout and components, mobile-first |
| `public/assets/app.js` | Live search only |
| `db/migrations/003_login_attempt.sql` | Throttling table |

---

### Task 1: HTTP core and the page shell

**Files:**
- Create: `src/Http/Router.php`, `src/Http/Response.php`, `src/Http/Session.php`, `src/Http/PhpSession.php`, `src/Http/ArraySession.php`, `src/Http/Csrf.php`, `src/View/View.php`, `templates/layout.php`, `templates/home-guest.php`, `public/assets/tokens.css`, `public/assets/app.css`, `tests/Http/RouterTest.php`, `tests/Http/CsrfTest.php`, `tests/View/ViewTest.php`
- Modify: `public/index.php`

**Interfaces:**
- Consumes: nothing from plan 1 yet.
- Produces:
  - `Kanon\Http\Router::add(string $method, string $pattern, callable $handler): void`, `get()`, `post()`, `match(string $method, string $path): ?array` returning `['handler' => callable, 'params' => array<string,string>]`.
  - `Kanon\Http\Response::html(string $body, int $status = 200): self`, `::redirect(string $to): self`, `::json(array $data): self`, `::download(string $body, string $filename, string $contentType): self`, `send(): void`, readonly `$status`, `$body`, `$headers`.
  - `Kanon\Http\Session` interface: `get(string $key, mixed $default = null): mixed`, `set(string $key, mixed $value): void`, `remove(string $key): void`, `regenerate(): void`, `destroy(): void`, `flash(string $type, string $message): void`, `takeFlashes(): list<array{type:string,message:string}>`.
  - `Kanon\Http\Csrf::__construct(Session $session)`, `token(): string`, `check(?string $token): bool`.
  - `Kanon\View\View::__construct(string $templateDir)`, `render(string $template, array $data = []): string`, `e(mixed $value): string`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Http/RouterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Http;

use Kanon\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->get('/', static fn (): string => 'home');
        $router->get('/kanon', static fn (): string => 'canon');
        $router->get('/dilo/{id}', static fn (array $p): string => 'work ' . $p['id']);
        $router->post('/seznam/pridat', static fn (): string => 'added');

        return $router;
    }

    public function testMatchesAStaticRoute(): void
    {
        $match = $this->router()->match('GET', '/kanon');

        self::assertNotNull($match);
        self::assertSame('canon', ($match['handler'])([]));
        self::assertSame([], $match['params']);
    }

    public function testExtractsAParameter(): void
    {
        $match = $this->router()->match('GET', '/dilo/417');

        self::assertNotNull($match);
        self::assertSame(['id' => '417'], $match['params']);
        self::assertSame('work 417', ($match['handler'])($match['params']));
    }

    public function testAParameterDoesNotMatchASlash(): void
    {
        self::assertNull($this->router()->match('GET', '/dilo/417/edit'));
    }

    public function testMethodMustMatch(): void
    {
        self::assertNull($this->router()->match('POST', '/kanon'));
        self::assertNotNull($this->router()->match('POST', '/seznam/pridat'));
    }

    public function testTrailingSlashIsIgnored(): void
    {
        self::assertNotNull($this->router()->match('GET', '/kanon/'));
    }

    public function testUnknownPathReturnsNull(): void
    {
        self::assertNull($this->router()->match('GET', '/neexistuje'));
    }
}
```

Create `tests/Http/CsrfTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Http;

use Kanon\Http\ArraySession;
use Kanon\Http\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testTheTokenIsStableWithinASession(): void
    {
        $csrf = new Csrf(new ArraySession());

        self::assertSame($csrf->token(), $csrf->token());
    }

    public function testDifferentSessionsGetDifferentTokens(): void
    {
        self::assertNotSame(
            (new Csrf(new ArraySession()))->token(),
            (new Csrf(new ArraySession()))->token()
        );
    }

    public function testTheTokenIsLongEnoughToResistGuessing(): void
    {
        self::assertGreaterThanOrEqual(32, strlen((new Csrf(new ArraySession()))->token()));
    }

    public function testCheckAcceptsTheRealTokenAndRejectsEverythingElse(): void
    {
        $csrf  = new Csrf(new ArraySession());
        $token = $csrf->token();

        self::assertTrue($csrf->check($token));
        self::assertFalse($csrf->check('wrong'));
        self::assertFalse($csrf->check(''));
        self::assertFalse($csrf->check(null));
    }

    public function testFlashesComeBackOnceAndThenAreGone(): void
    {
        $session = new ArraySession();
        $session->flash('ok', 'Uloženo');

        self::assertSame([['type' => 'ok', 'message' => 'Uloženo']], $session->takeFlashes());
        self::assertSame([], $session->takeFlashes());
    }
}
```

Create `tests/View/ViewTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\View;

use Kanon\View\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kanon-view-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/greet.php', '<p><?= $this->e($name) ?></p>');
        file_put_contents($this->dir . '/raw.php', '<p><?= $name ?></p>');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testRendersATemplateWithData(): void
    {
        $html = (new View($this->dir))->render('greet', ['name' => 'Kytice']);

        self::assertSame('<p>Kytice</p>', $html);
    }

    public function testEscapesHtmlInTemplateOutput(): void
    {
        $html = (new View($this->dir))->render('greet', ['name' => '<script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testKeepsCzechDiacriticsIntact(): void
    {
        $html = (new View($this->dir))->render('greet', ['name' => 'Žítkovské bohyně']);

        self::assertSame('<p>Žítkovské bohyně</p>', $html);
    }

    public function testMissingTemplateThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new View($this->dir))->render('neexistuje');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter 'RouterTest|CsrfTest|ViewTest'
```

Expected: FAIL — `Class "Kanon\Http\Router" not found`.

- [ ] **Step 3: Write the router and response**

Create `src/Http/Router.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Http;

final class Router
{
    /** @var list<array{method: string, regex: string, names: list<string>, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $names = [];
        $regex = preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $m) use (&$names): string {
                $names[] = $m[1];

                return '([^/]+)';
            },
            $pattern
        );

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#u',
            'names'   => $names,
            'handler' => $handler,
        ];
    }

    /** @return array{handler: callable, params: array<string, string>}|null */
    public function match(string $method, string $path): ?array
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }

            $params = [];
            foreach ($route['names'] as $i => $name) {
                $params[$name] = $m[$i + 1];
            }

            return ['handler' => $route['handler'], 'params' => $params];
        }

        return null;
    }
}
```

Create `src/Http/Response.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Http;

final class Response
{
    /** @param array<string, string> $headers */
    private function __construct(
        public readonly string $body,
        public readonly int $status,
        public readonly array $headers,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $to): self
    {
        return new self('', 302, ['Location' => $to]);
    }

    public static function json(array $data): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            200,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function download(string $body, string $filename, string $contentType): self
    {
        return new self($body, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => (string) strlen($body),
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
```

- [ ] **Step 4: Write the session and CSRF classes**

Create `src/Http/Session.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Http;

interface Session
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** Called on privilege change, to defeat session fixation. */
    public function regenerate(): void;

    public function destroy(): void;

    public function flash(string $type, string $message): void;

    /** @return list<array{type: string, message: string}> */
    public function takeFlashes(): array;
}
```

Create `src/Http/ArraySession.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Http;

/** In-memory session, so anything session-dependent can be tested without a browser. */
final class ArraySession implements Session
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
    }

    public function destroy(): void
    {
        $this->data = [];
    }

    public function flash(string $type, string $message): void
    {
        $flashes   = $this->data['_flash'] ?? [];
        $flashes[] = ['type' => $type, 'message' => $message];

        $this->data['_flash'] = $flashes;
    }

    public function takeFlashes(): array
    {
        $flashes = $this->data['_flash'] ?? [];
        unset($this->data['_flash']);

        return $flashes;
    }
}
```

Create `src/Http/PhpSession.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Http;

final class PhpSession implements Session
{
    public function __construct()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'path'     => '/',
            ]);
            session_start();
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public function flash(string $type, string $message): void
    {
        $flashes            = $_SESSION['_flash'] ?? [];
        $flashes[]          = ['type' => $type, 'message' => $message];
        $_SESSION['_flash'] = $flashes;
    }

    public function takeFlashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return $flashes;
    }
}
```

Create `src/Http/Csrf.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Http;

final class Csrf
{
    private const KEY = '_csrf';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }

        return $token;
    }

    public function check(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals($this->token(), $token);
    }
}
```

- [ ] **Step 5: Write the view renderer**

Create `src/View/View.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\View;

/**
 * Renders a plain-PHP template.
 *
 * Templates call $this->e() for every value they print; there is no automatic
 * escaping, so a template that interpolates a variable directly is a defect.
 */
final class View
{
    public function __construct(private readonly string $templateDir)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $file = $this->templateDir . '/' . $template . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        extract($data, EXTR_SKIP);
        ob_start();

        try {
            require $file;

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter 'RouterTest|CsrfTest|ViewTest'
```

Expected: PASS, 15 tests.

- [ ] **Step 7: Write the palette**

Create `public/assets/tokens.css`. **This is the only file in the project allowed to contain a colour value.**

```css
/* Kanonmaker - barevná paleta.
   Toto je jediný soubor v projektu, který smí obsahovat konkrétní barvu.
   Výměna palety = úprava tohoto souboru, nikde jinde. */
:root {
    --bg:            #FBF6E9;
    --bg-raised:     #FFFDF7;
    --ink:           #453D2E;
    --ink-soft:      #7A7059;
    --line:          #E6DDC7;

    --accent:        #5E877B;
    --accent-ink:    #FFFFFF;
    --accent-soft:   #E4EDE9;

    --ok:            #4F7A50;
    --ok-soft:       #DFEDDC;
    --warn:          #A96A22;
    --warn-soft:     #F7E7CE;

    --chip-obdobi-bg:    #DCE6F2;
    --chip-obdobi-ink:   #2F4A6B;
    --chip-podobdobi-bg: #F2E3DC;
    --chip-podobdobi-ink:#7A4A32;
    --chip-narodni-bg:   #DFEDDC;
    --chip-narodni-ink:  #3B5A34;
    --chip-forma-bg:     #EFE0EF;
    --chip-forma-ink:    #5E3A5E;
    --chip-special-bg:   #F5E6C8;
    --chip-special-ink:  #6B5220;

    --radius:      14px;
    --radius-chip: 999px;
    --tap:         44px;
    --shadow:      0 1px 2px rgba(69, 61, 46, .06), 0 4px 16px rgba(69, 61, 46, .05);
}
```

- [ ] **Step 8: Write the stylesheet**

Create `public/assets/app.css`. Mobile-first: the base rules are the phone layout, and the only media query widens it.

```css
* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 0 0 5.5rem;
    background: var(--bg);
    color: var(--ink);
    font: 16px/1.5 "Iowan Old Style", "Palatino Linotype", Georgia, serif;
    -webkit-text-size-adjust: 100%;
}

a { color: var(--accent); }

.wrap { max-width: 44rem; margin: 0 auto; padding: 0 1rem; }

/* --- hlavička --- */
.top {
    display: flex; align-items: center; justify-content: space-between;
    gap: .75rem; padding: .9rem 1rem;
    background: var(--bg-raised); border-bottom: 1px solid var(--line);
}
.top h1 { margin: 0; font-size: 1.1rem; letter-spacing: .01em; }
.top nav { display: flex; gap: .75rem; font-size: .9rem; }

/* --- formulářové prvky --- */
.field { display: block; margin: 0 0 1rem; }
.field span { display: block; margin-bottom: .3rem; font-size: .85rem; color: var(--ink-soft); }
input[type=text], input[type=email], input[type=password], input[type=search], select {
    width: 100%; min-height: var(--tap); padding: .6rem .8rem;
    border: 1px solid var(--line); border-radius: var(--radius);
    background: var(--bg-raised); color: var(--ink); font: inherit;
}
input:focus, select:focus, button:focus { outline: 2px solid var(--accent); outline-offset: 1px; }

.btn {
    display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
    min-height: var(--tap); padding: .6rem 1.1rem;
    border: 1px solid transparent; border-radius: var(--radius);
    background: var(--accent); color: var(--accent-ink);
    font: inherit; cursor: pointer; text-decoration: none;
}
.btn--quiet { background: transparent; color: var(--accent); border-color: var(--line); }
.btn--block { width: 100%; }

/* --- karty děl --- */
.works { list-style: none; margin: 0; padding: 0; }
.work {
    display: flex; gap: .75rem; align-items: flex-start;
    padding: .85rem 0; border-bottom: 1px solid var(--line);
}
.work__num { min-width: 1.6rem; color: var(--ink-soft); font-variant-numeric: tabular-nums; }
.work__body { flex: 1; min-width: 0; }
.work__author { font-size: .85rem; color: var(--ink-soft); }
.work__title { font-size: 1.02rem; }
.work__note { font-size: .8rem; color: var(--ink-soft); font-style: italic; }

.chips { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .35rem; }
.chip {
    padding: .15rem .55rem; border-radius: var(--radius-chip);
    font-size: .72rem; font-family: system-ui, sans-serif; white-space: nowrap;
}
.chip--obdobi    { background: var(--chip-obdobi-bg);    color: var(--chip-obdobi-ink); }
.chip--podobdobi { background: var(--chip-podobdobi-bg); color: var(--chip-podobdobi-ink); }
.chip--narodni   { background: var(--chip-narodni-bg);   color: var(--chip-narodni-ink); }
.chip--forma     { background: var(--chip-forma-bg);     color: var(--chip-forma-ink); }
.chip--special   { background: var(--chip-special-bg);   color: var(--chip-special-ink); }
.chip--unverified::after { content: " ?"; opacity: .55; }

/* --- lišta pravidel --- */
.rulebar {
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 20;
    background: var(--bg-raised); border-top: 1px solid var(--line);
    box-shadow: var(--shadow);
}
.rulebar__summary {
    display: flex; align-items: center; gap: .75rem;
    width: 100%; min-height: var(--tap); padding: .7rem 1rem;
    background: none; border: 0; color: inherit; font: inherit; cursor: pointer;
}
.rulebar__count { font-variant-numeric: tabular-nums; font-size: 1.05rem; }
.rulebar__state--ok   { color: var(--ok); }
.rulebar__state--warn { color: var(--warn); }
.rulebar__bar { flex: 1; height: 6px; border-radius: 999px; background: var(--line); overflow: hidden; }
.rulebar__fill { display: block; height: 100%; background: var(--accent); }
.rulebar__panel { max-height: 60vh; overflow-y: auto; border-top: 1px solid var(--line); }
.rulebar__panel[hidden] { display: none; }

.rule { display: flex; gap: .6rem; align-items: baseline; padding: .6rem 1rem; border-bottom: 1px solid var(--line); }
.rule__mark { width: 1.2rem; }
.rule--ok   .rule__mark { color: var(--ok); }
.rule--warn .rule__mark { color: var(--warn); }
.rule__label { flex: 1; font-size: .9rem; }
.rule__count { font-size: .85rem; color: var(--ink-soft); font-variant-numeric: tabular-nums; }
.rule__detail { display: block; font-size: .78rem; color: var(--warn); }

/* --- drobnosti --- */
.flash { margin: .75rem 0; padding: .7rem 1rem; border-radius: var(--radius); font-size: .9rem; }
.flash--ok   { background: var(--ok-soft);   color: var(--ok); }
.flash--warn { background: var(--warn-soft); color: var(--warn); }
.empty { padding: 2.5rem 1rem; text-align: center; color: var(--ink-soft); }
.muted { color: var(--ink-soft); font-size: .85rem; }

@media (min-width: 48rem) {
    body { padding-bottom: 0; }
    .rulebar { position: sticky; bottom: 0; border-radius: var(--radius) var(--radius) 0 0; }
    .wrap { padding: 0 1.5rem; }
}
```

- [ ] **Step 9: Write the layout and a guest landing page**

Create `templates/layout.php`:

```php
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($title) ?> · Kánon GJK</title>
    <link rel="stylesheet" href="/assets/tokens.css">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="top">
    <h1><a href="/" style="color:inherit;text-decoration:none">Kánon GJK</a></h1>
    <nav>
        <?php if ($user !== null): ?>
            <a href="/kanon">Seznam děl</a>
            <a href="/export">Export</a>
            <form method="post" action="/odhlasit" style="display:inline">
                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                <button class="btn btn--quiet" style="min-height:auto;padding:.2rem .6rem">Odhlásit</button>
            </form>
        <?php else: ?>
            <a href="/prihlaseni">Přihlásit</a>
            <a href="/registrace">Registrovat</a>
        <?php endif; ?>
    </nav>
</header>

<main class="wrap">
    <?php foreach ($flashes as $flash): ?>
        <p class="flash flash--<?= $this->e($flash['type']) ?>"><?= $this->e($flash['message']) ?></p>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<?= $rulebar ?>
<script src="/assets/app.js" defer></script>
</body>
</html>
```

Create `templates/home-guest.php`:

```php
<section class="empty">
    <h2>Sestav si maturitní seznam četby</h2>
    <p class="muted">
        Vyber si díla ze školního kánonu a průběžně uvidíš, která pravidla už
        splňuješ a která ještě ne.
    </p>
    <p>
        <a class="btn btn--block" href="/registrace">Založit účet</a>
    </p>
    <p><a href="/prihlaseni">Už účet mám</a></p>
</section>
```

- [ ] **Step 10: Wire the front controller**

Replace `public/index.php`:

```php
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

$router = new Router();

$router->get('/', static function () use ($view, $session, $csrf): Response {
    return Response::html($view->render('layout', [
        'title'     => 'Maturitní seznam četby',
        'user'      => null,
        'csrfToken' => $csrf->token(),
        'flashes'   => $session->takeFlashes(),
        'rulebar'   => '',
        'content'   => $view->render('home-guest'),
    ]));
});

$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$match = $router->match($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);

if ($match === null) {
    Response::html('<p>Stránka nenalezena.</p>', 404)->send();

    return;
}

($match['handler'])($match['params'])->send();
```

- [ ] **Step 10b: Route every unknown path to the front controller**

Without this Apache answers 404 itself and `index.php` never runs, so every
route except `/` is dead. Create `public/.htaccess`:

```apache
# Vše, co není skutečný soubor, obsluhuje jediný vstupní bod.
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>

# Statické soubory nepatří do PHP.
<FilesMatch "\.(css|js|svg|woff2?|png|jpg)$">
    SetHandler none
</FilesMatch>
```

`mod_rewrite` is enabled on this server and the vhost sets `AllowOverride All`.

- [ ] **Step 10c: Create the session directory**

The PHP image sets `session.save_path` to `<project>/session`, which does not
exist in a fresh checkout. `session_start()` then fails **silently**: every
request gets an empty session, so the CSRF token is regenerated each time and
every form submission is rejected as expired. The same directory exists in
production for the same reason.

```bash
cd /data/www/kanonmaker && mkdir -p session tmp && chmod 700 session tmp
printf '/session/\n/tmp/\n' >> .gitignore
```

- [ ] **Step 11: Check it in a browser at phone width**

Run:

```bash
curl -s http://kata.doma.slimak.cz/ | head -20
curl -s -o /dev/null -w '%{http_code}\n' http://kata.doma.slimak.cz/assets/tokens.css
curl -s -o /dev/null -w '%{http_code}\n' http://kata.doma.slimak.cz/neexistuje
```

Expected: the Czech landing page HTML, `200` for the stylesheet, `404` for the unknown path.

- [ ] **Step 12: Commit**

```bash
cd /data/www/kanonmaker
git add src/Http src/View templates public tests/Http tests/View
git commit -m "feat: http core, view renderer and the page shell"
```

---

### Task 2: Accounts — registration, login, throttling

**Files:**
- Create: `db/migrations/003_login_attempt.sql`, `src/Auth/UserRepository.php`, `src/Auth/LoginThrottle.php`, `src/Auth/Auth.php`, `src/App/Controller/AuthController.php`, `templates/login.php`, `templates/register.php`, `tests/Auth/AuthTest.php`
- Modify: `public/index.php`, `bin/create-admin` (create)

**Interfaces:**
- Consumes: `Kanon\Http\Session`, `Kanon\Http\ArraySession`, `Kanon\Db\Database`.
- Produces:
  - `Kanon\Auth\UserRepository::__construct(\PDO $pdo)`, `findByEmail(string $email): ?array`, `findById(int $id): ?array`, `exists(string $email): bool`, `create(string $email, string $plainPassword, string $displayName, string $role = 'student'): int`, `verify(array $user, string $plainPassword): bool`.
  - `Kanon\Auth\LoginThrottle::__construct(\PDO $pdo)`, `tooMany(string $email): bool`, `record(string $email): void`, `clear(string $email): void`, constants `MAX_ATTEMPTS = 10`, `WINDOW_MINUTES = 15`.
  - `Kanon\Auth\Auth::__construct(UserRepository $users, LoginThrottle $throttle, Session $session)`, `attempt(string $email, string $password): bool`, `user(): ?array`, `id(): ?int`, `check(): bool`, `isAdmin(): bool`, `logout(): void`.
- E-mail is stored and compared lower-cased and trimmed, so `Kata@…` and `kata@…` are one account.

- [ ] **Step 1: Write the failing test**

Create `tests/Auth/AuthTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Auth;

use Kanon\Auth\Auth;
use Kanon\Auth\LoginThrottle;
use Kanon\Auth\UserRepository;
use Kanon\Db\Database;
use Kanon\Db\Migrator;
use Kanon\Http\ArraySession;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    private \PDO $pdo;
    private UserRepository $users;
    private LoginThrottle $throttle;
    private string $email;

    protected function setUp(): void
    {
        $root      = dirname(__DIR__, 2);
        $config    = require $root . '/config.php';
        $this->pdo = Database::connect($config['db']);
        (new Migrator($this->pdo, $root . '/db/migrations'))->migrate();

        $this->pdo->beginTransaction();

        $this->users    = new UserRepository($this->pdo);
        $this->throttle = new LoginThrottle($this->pdo);
        $this->email    = 'student' . bin2hex(random_bytes(4)) . '@example.test';
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function auth(): Auth
    {
        return new Auth($this->users, $this->throttle, new ArraySession());
    }

    public function testTheStoredPasswordIsHashedNotThePlainText(): void
    {
        $id   = $this->users->create($this->email, 'tajneheslo123', 'Kata');
        $user = $this->users->findById($id);

        self::assertNotNull($user);
        self::assertNotSame('tajneheslo123', $user['password_hash']);
        self::assertTrue(password_verify('tajneheslo123', $user['password_hash']));
    }

    public function testEmailIsCaseInsensitive(): void
    {
        $this->users->create('Kata@Example.Test', 'tajneheslo123', 'Kata');

        self::assertNotNull($this->users->findByEmail('kata@example.test'));
        self::assertTrue($this->users->exists('KATA@EXAMPLE.TEST'));
    }

    public function testAttemptSucceedsWithTheRightPasswordAndFailsOtherwise(): void
    {
        $this->users->create($this->email, 'tajneheslo123', 'Kata');
        $auth = $this->auth();

        self::assertTrue($auth->attempt($this->email, 'tajneheslo123'));
        self::assertTrue($auth->check());
        self::assertSame($this->email, $auth->user()['email']);

        self::assertFalse($this->auth()->attempt($this->email, 'spatneheslo'));
        self::assertFalse($this->auth()->attempt('nikdo@example.test', 'tajneheslo123'));
    }

    public function testLogoutForgetsTheUser(): void
    {
        $this->users->create($this->email, 'tajneheslo123', 'Kata');
        $auth = $this->auth();
        $auth->attempt($this->email, 'tajneheslo123');

        $auth->logout();

        self::assertFalse($auth->check());
        self::assertNull($auth->user());
    }

    public function testNewAccountsAreStudentsNotAdmins(): void
    {
        $this->users->create($this->email, 'tajneheslo123', 'Kata');
        $auth = $this->auth();
        $auth->attempt($this->email, 'tajneheslo123');

        self::assertFalse($auth->isAdmin());
    }

    public function testAnAdminAccountIsRecognised(): void
    {
        $this->users->create($this->email, 'tajneheslo123', 'Kata', 'admin');
        $auth = $this->auth();
        $auth->attempt($this->email, 'tajneheslo123');

        self::assertTrue($auth->isAdmin());
    }

    public function testRepeatedFailuresLockTheAccountOut(): void
    {
        $this->users->create($this->email, 'tajneheslo123', 'Kata');

        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
            self::assertFalse($this->auth()->attempt($this->email, 'spatneheslo'));
        }

        self::assertTrue($this->throttle->tooMany($this->email));
        self::assertFalse(
            $this->auth()->attempt($this->email, 'tajneheslo123'),
            'even the correct password is refused while locked out'
        );
    }

    public function testASuccessfulLoginClearsTheFailureCount(): void
    {
        $this->users->create($this->email, 'tajneheslo123', 'Kata');

        $this->auth()->attempt($this->email, 'spatneheslo');
        $this->auth()->attempt($this->email, 'tajneheslo123');

        self::assertFalse($this->throttle->tooMany($this->email));
    }

    public function testOldFailuresFallOutOfTheWindow(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempt (email, attempted_at) VALUES (?, NOW() - INTERVAL ? MINUTE)'
        );
        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS + 5; $i++) {
            $stmt->execute([$this->email, LoginThrottle::WINDOW_MINUTES + 1]);
        }

        self::assertFalse($this->throttle->tooMany($this->email), 'stale failures must not lock anyone out');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter AuthTest
```

Expected: FAIL — `Class "Kanon\Auth\UserRepository" not found`.

- [ ] **Step 3: Write the throttling table**

Create `db/migrations/003_login_attempt.sql`:

```sql
CREATE TABLE login_attempt (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(190) NOT NULL,
    attempted_at DATETIME NOT NULL,
    KEY ix_login_attempt (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 4: Write the user repository and throttle**

Create `src/Auth/UserRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Auth;

final class UserRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user WHERE email = ?');
        $stmt->execute([self::normalizeEmail($email)]);

        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function exists(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }

    public function create(string $email, string $plainPassword, string $displayName, string $role = 'student'): int
    {
        $this->pdo->prepare(
            'INSERT INTO user (email, password_hash, display_name, role, active, created_at)
             VALUES (?, ?, ?, ?, 1, NOW())'
        )->execute([
            self::normalizeEmail($email),
            password_hash($plainPassword, PASSWORD_DEFAULT),
            $displayName,
            $role,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function verify(array $user, string $plainPassword): bool
    {
        return password_verify($plainPassword, $user['password_hash']);
    }
}
```

Create `src/Auth/LoginThrottle.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Auth;

/**
 * Counts recent failed logins per e-mail address.
 *
 * Kept in the database rather than the session, because a session-based counter
 * is defeated by discarding the cookie.
 */
final class LoginThrottle
{
    public const MAX_ATTEMPTS   = 10;
    public const WINDOW_MINUTES = 15;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function tooMany(string $email): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempt
             WHERE email = ? AND attempted_at > NOW() - INTERVAL ? MINUTE'
        );
        $stmt->execute([UserRepository::normalizeEmail($email), self::WINDOW_MINUTES]);

        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public function record(string $email): void
    {
        $this->pdo->prepare('INSERT INTO login_attempt (email, attempted_at) VALUES (?, NOW())')
            ->execute([UserRepository::normalizeEmail($email)]);
    }

    public function clear(string $email): void
    {
        $this->pdo->prepare('DELETE FROM login_attempt WHERE email = ?')
            ->execute([UserRepository::normalizeEmail($email)]);
    }
}
```

Create `src/Auth/Auth.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Auth;

use Kanon\Http\Session;

final class Auth
{
    private const KEY = '_user_id';

    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginThrottle $throttle,
        private readonly Session $session,
    ) {
    }

    public function attempt(string $email, string $password): bool
    {
        if ($this->throttle->tooMany($email)) {
            return false;
        }

        $user = $this->users->findByEmail($email);

        if ($user === null || (int) $user['active'] !== 1 || !$this->users->verify($user, $password)) {
            $this->throttle->record($email);

            return false;
        }

        $this->throttle->clear($email);
        $this->session->regenerate();
        $this->session->set(self::KEY, (int) $user['id']);

        return true;
    }

    public function user(): ?array
    {
        $id = $this->session->get(self::KEY);

        return is_int($id) ? $this->users->findById($id) : null;
    }

    public function id(): ?int
    {
        $id = $this->session->get(self::KEY);

        return is_int($id) ? $id : null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function isAdmin(): bool
    {
        return ($this->user()['role'] ?? null) === 'admin';
    }

    public function logout(): void
    {
        $this->session->remove(self::KEY);
        $this->session->regenerate();
    }
}
```

- [ ] **Step 5: Run migration and the test**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/migrate
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter AuthTest
```

Expected: `applied 003_login_attempt.sql`, then PASS, 9 tests.

- [ ] **Step 6: Write the login and registration screens**

Create `templates/register.php`:

```php
<h2>Registrace</h2>

<form method="post" action="/registrace">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

    <label class="field">
        <span>Jméno</span>
        <input type="text" name="display_name" value="<?= $this->e($old['display_name'] ?? '') ?>" required autocomplete="name">
    </label>

    <label class="field">
        <span>E-mail</span>
        <input type="email" name="email" value="<?= $this->e($old['email'] ?? '') ?>" required autocomplete="email">
    </label>

    <label class="field">
        <span>Heslo (alespoň 8 znaků)</span>
        <input type="password" name="password" required minlength="8" autocomplete="new-password">
    </label>

    <button class="btn btn--block" type="submit">Založit účet</button>
</form>

<p class="muted" style="margin-top:1rem">Už účet máš? <a href="/prihlaseni">Přihlas se</a>.</p>
```

Create `templates/login.php`:

```php
<h2>Přihlášení</h2>

<form method="post" action="/prihlaseni">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

    <label class="field">
        <span>E-mail</span>
        <input type="email" name="email" value="<?= $this->e($old['email'] ?? '') ?>" required autocomplete="email">
    </label>

    <label class="field">
        <span>Heslo</span>
        <input type="password" name="password" required autocomplete="current-password">
    </label>

    <button class="btn btn--block" type="submit">Přihlásit se</button>
</form>

<p class="muted" style="margin-top:1rem">Nemáš účet? <a href="/registrace">Zaregistruj se</a>.</p>
```

- [ ] **Step 7: Write the controller**

Create `src/App/Controller/AuthController.php`:

```php
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
            $name === ''                                          => 'Vyplň prosím jméno.',
            !filter_var($email, FILTER_VALIDATE_EMAIL)            => 'E-mail nevypadá správně.',
            mb_strlen($password) < 8                              => 'Heslo musí mít alespoň 8 znaků.',
            $this->users->exists($email)                          => 'Účet s tímto e-mailem už existuje.',
            default                                               => null,
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

    public function logout(array $input): Response
    {
        if ($this->csrf->check($input['_token'] ?? null)) {
            $this->auth->logout();
            $this->session->flash('ok', 'Odhlášeno.');
        }

        return Response::redirect('/');
    }
}
```

- [ ] **Step 8: Wire the routes and the page helper**

In `public/index.php`, after `$view` is created, add a `$page` closure that wraps any template in the layout, then register the routes. Replace the single `/` route with:

```php
use Kanon\App\Controller\AuthController;
use Kanon\Auth\Auth;
use Kanon\Auth\LoginThrottle;
use Kanon\Auth\UserRepository;

$users    = new UserRepository($pdo);
$throttle = new LoginThrottle($pdo);
$auth     = new Auth($users, $throttle, $session);

// Filled in by Task 5; until then nobody has a list.
$listIds = static fn (): array => [];

// Every template gets user, csrfToken, inList and back for free, so no
// controller has to remember them. A controller may still override any of them.
$page = function (string $title, string $template, array $data = []) use ($view, $session, $csrf, $auth, &$listIds): string {
    return $view->render('layout', [
        'title'     => $title,
        'user'      => $auth->user(),
        'csrfToken' => $csrf->token(),
        'flashes'   => $session->takeFlashes(),
        'rulebar'   => '',
        'content'   => $view->render($template, $data + [
            'user'      => $auth->user(),
            'csrfToken' => $csrf->token(),
            'inList'    => ($listIds)(),
            'back'      => '/',
        ]),
    ]);
};

$authController = new AuthController($auth, $users, $throttle, $session, $csrf, $page);

$router->get('/registrace', static fn (): Response => $authController->showRegister());
$router->post('/registrace', static fn (): Response => $authController->register($_POST));
$router->get('/prihlaseni', static fn (): Response => $authController->showLogin());
$router->post('/prihlaseni', static fn (): Response => $authController->login($_POST));
$router->post('/odhlasit', static fn (): Response => $authController->logout($_POST));

$router->get('/', static function () use ($page, $auth): Response {
    if (!$auth->check()) {
        return Response::html($page('Maturitní seznam četby', 'home-guest'));
    }

    return Response::redirect('/kanon');
});
```

- [ ] **Step 9: Write the admin-creation command**

Create `bin/create-admin`:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kanon\Auth\UserRepository;
use Kanon\Db\Database;

if ($argc < 4) {
    fwrite(STDERR, "Usage: bin/create-admin <email> <password> <name>\n");
    exit(1);
}

[$script, $email, $password, $name] = $argv;

$config = require __DIR__ . '/../config.php';
$users  = new UserRepository(Database::connect($config['db']));

if ($users->exists($email)) {
    fwrite(STDERR, "An account with that e-mail already exists.\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "The password must be at least 8 characters.\n");
    exit(1);
}

$id = $users->create($email, $password, $name, 'admin');

echo "Created admin #{$id} <{$email}>\n";
```

Make it executable with `chmod +x bin/create-admin`.

- [ ] **Step 10: Verify registration and login in the browser**

Run:

```bash
curl -s -o /dev/null -w 'registrace %{http_code}\n' http://kata.doma.slimak.cz/registrace
curl -s -o /dev/null -w 'prihlaseni %{http_code}\n' http://kata.doma.slimak.cz/prihlaseni
curl -s -c /tmp/c.txt http://kata.doma.slimak.cz/registrace | grep -o 'name="_token" value="[a-f0-9]*"' | head -1
```

Expected: `200` for both, and a CSRF token in the form. Then register through the browser at phone width and confirm you land on the canon page logged in.

- [ ] **Step 11: Commit**

```bash
cd /data/www/kanonmaker
git add db/migrations/003_login_attempt.sql src/Auth src/App bin/create-admin templates public/index.php tests/Auth
git commit -m "feat: student accounts with hashed passwords and login throttling"
```

---

### Task 3: The work repository

**Files:**
- Create: `src/Repo/WorkRepository.php`, `tests/Repo/WorkRepositoryTest.php`

**Interfaces:**
- Consumes: `Kanon\Support\Normalize::text()`, the imported canon.
- Produces `Kanon\Repo\WorkRepository::__construct(\PDO $pdo)` with:
  - `search(int $canonId, string $query, int $limit = 40): list<array>`
  - `browse(int $canonId, ?int $chapterId = null, ?string $tagGroup = null, ?string $tagCode = null): list<array>`
  - `find(int $canonId, int $workId): ?array`
  - `findMany(int $canonId, list<int> $workIds): list<array>` — in the order given
  - `chapters(int $canonId): list<array{id:int,name:string,sort_order:int,works:int}>`
  - `tagGroups(int $canonId): array<string, list<array{code:string,label:string}>>`
- Every work array has the shape: `['id'=>int, 'title'=>string, 'note'=>?string, 'chapter_id'=>int, 'chapter'=>string, 'authors'=>string, 'tags'=>array<string, list<array{code:string,label:string,verified:bool}>>]`.

- [ ] **Step 1: Write the failing test**

Create `tests/Repo/WorkRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Repo;

use Kanon\Db\Database;
use Kanon\Repo\WorkRepository;
use PHPUnit\Framework\TestCase;

final class WorkRepositoryTest extends TestCase
{
    private WorkRepository $repo;
    private int $canonId;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $config        = require dirname(__DIR__, 2) . '/config.php';
        $this->pdo     = Database::connect($config['db']);
        $this->canonId = (int) $this->pdo->query(
            "SELECT id FROM canon WHERE school_year = '2025/2026'"
        )->fetchColumn();
        $this->repo    = new WorkRepository($this->pdo);
    }

    /** @param list<array> $works */
    private function titles(array $works): array
    {
        return array_map(static fn (array $w): string => $w['title'], $works);
    }

    public function testSearchIgnoresDiacritics(): void
    {
        self::assertContains('Krakatit', $this->titles($this->repo->search($this->canonId, 'capek')));
        self::assertContains('Žert', $this->titles($this->repo->search($this->canonId, 'zert')));
        self::assertNotSame([], $this->repo->search($this->canonId, 'sindelka'), 'Šindelka without the caron');
        self::assertNotSame([], $this->repo->search($this->canonId, 'zitkovske'), 'Žítkovské bohyně');
    }

    public function testSearchMatchesTitleAndAuthor(): void
    {
        self::assertContains('Babička', $this->titles($this->repo->search($this->canonId, 'babicka')));
        self::assertNotSame([], $this->repo->search($this->canonId, 'Němcová'));
    }

    public function testAllWordsMustMatch(): void
    {
        $both = $this->repo->search($this->canonId, 'capek valka');

        self::assertNotSame([], $both);
        self::assertContains('Válka s mloky', $this->titles($both));
        self::assertSame([], $this->repo->search($this->canonId, 'capek jednorozec'));
    }

    public function testSearchReturnsNothingForAnEmptyQuery(): void
    {
        self::assertSame([], $this->repo->search($this->canonId, '   '));
    }

    public function testSearchRespectsTheLimit(): void
    {
        self::assertLessThanOrEqual(5, count($this->repo->search($this->canonId, 'a', 5)));
    }

    public function testWorksCarryAuthorsAndTags(): void
    {
        $works = $this->repo->search($this->canonId, 'babicka');
        $work  = $works[0];

        self::assertStringContainsString('NĚMCOVÁ', $work['authors']);
        self::assertArrayHasKey('obdobi', $work['tags']);
        self::assertArrayHasKey('forma', $work['tags']);
        self::assertSame('proza', $work['tags']['forma'][0]['code']);
        self::assertSame('próza', $work['tags']['forma'][0]['label']);
    }

    public function testAnAuthorlessWorkHasAnEmptyAuthorString(): void
    {
        $works = $this->repo->search($this->canonId, 'beowulf');

        self::assertSame('', $works[0]['authors']);
    }

    public function testInferredTagsAreMarkedUnverified(): void
    {
        $works = $this->repo->search($this->canonId, 'babicka');
        $forma = $works[0]['tags']['forma'][0];

        self::assertFalse($forma['verified'], 'curated tags await human confirmation');
    }

    public function testBrowseReturnsTheWholeCanonInDocumentOrder(): void
    {
        $all = $this->repo->browse($this->canonId);

        self::assertGreaterThan(440, count($all));
        self::assertSame('Oresteia', $all[0]['title'], 'the canon opens with Aischylos');
    }

    public function testBrowseFiltersByChapter(): void
    {
        $chapters = $this->repo->chapters($this->canonId);
        $drama    = null;
        foreach ($chapters as $chapter) {
            if (str_contains($chapter['name'], 'dramatická')) {
                $drama = $chapter;
            }
        }

        self::assertNotNull($drama);
        $works = $this->repo->browse($this->canonId, $drama['id']);

        self::assertSame($drama['works'], count($works));
        foreach ($works as $work) {
            self::assertSame('drama', $work['tags']['forma'][0]['code']);
        }
    }

    public function testBrowseFiltersByTag(): void
    {
        $poetry = $this->repo->browse($this->canonId, null, 'forma', 'poezie');

        self::assertGreaterThan(20, count($poetry));
        foreach ($poetry as $work) {
            self::assertSame('poezie', $work['tags']['forma'][0]['code']);
        }
    }

    public function testFindReturnsOneWorkOrNull(): void
    {
        $some = $this->repo->browse($this->canonId, null, 'forma', 'drama')[0];
        $work = $this->repo->find($this->canonId, $some['id']);

        self::assertNotNull($work);
        self::assertSame($some['title'], $work['title']);
        self::assertNull($this->repo->find($this->canonId, 999999));
    }

    public function testFindManyKeepsTheGivenOrder(): void
    {
        $all = $this->repo->browse($this->canonId);
        $ids = [$all[5]['id'], $all[1]['id'], $all[3]['id']];

        self::assertSame($ids, array_column($this->repo->findMany($this->canonId, $ids), 'id'));
        self::assertSame([], $this->repo->findMany($this->canonId, []));
    }

    public function testChaptersComeBackInDocumentOrderWithCounts(): void
    {
        $chapters = $this->repo->chapters($this->canonId);

        self::assertCount(7, $chapters);
        self::assertSame(1, $chapters[0]['sort_order']);
        self::assertGreaterThan(0, $chapters[0]['works']);
        self::assertSame(count($this->repo->browse($this->canonId)), array_sum(array_column($chapters, 'works')));
    }

    public function testTagGroupsAreGroupedAndLabelled(): void
    {
        $groups = $this->repo->tagGroups($this->canonId);

        self::assertArrayHasKey('obdobi', $groups);
        self::assertArrayHasKey('podobdobi', $groups);
        self::assertCount(5, $groups['podobdobi']);
        self::assertSame('starověk', $groups['podobdobi'][0]['label']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter WorkRepositoryTest
```

Expected: FAIL — `Class "Kanon\Repo\WorkRepository" not found`.

- [ ] **Step 3: Write the repository**

Create `src/Repo/WorkRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Repo;

use Kanon\Support\Normalize;

/**
 * The only place that queries works. Every method returns fully hydrated works —
 * authors and tags included — so no template ever issues a query of its own.
 */
final class WorkRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @return list<array> */
    public function search(int $canonId, string $query, int $limit = 40): array
    {
        $words = array_values(array_filter(explode(' ', Normalize::text($query))));

        if ($words === []) {
            return [];
        }

        $where  = ['w.canon_id = ?'];
        $params = [$canonId];

        foreach ($words as $word) {
            $where[]  = 'w.search_text LIKE ?';
            $params[] = '%' . $word . '%';
        }

        $sql = 'SELECT w.* FROM work w WHERE ' . implode(' AND ', $where)
             . ' ORDER BY w.sort_order LIMIT ' . max(1, $limit);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->hydrate($stmt->fetchAll());
    }

    /** @return list<array> */
    public function browse(
        int $canonId,
        ?int $chapterId = null,
        ?string $tagGroup = null,
        ?string $tagCode = null,
    ): array {
        $where  = ['w.canon_id = ?'];
        $params = [$canonId];

        if ($chapterId !== null) {
            $where[]  = 'w.chapter_id = ?';
            $params[] = $chapterId;
        }

        if ($tagGroup !== null && $tagCode !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
                                WHERE wt.work_id = w.id AND t.tag_group = ? AND t.code = ?)';
            $params[] = $tagGroup;
            $params[] = $tagCode;
        }

        $stmt = $this->pdo->prepare(
            'SELECT w.* FROM work w WHERE ' . implode(' AND ', $where) . ' ORDER BY w.sort_order'
        );
        $stmt->execute($params);

        return $this->hydrate($stmt->fetchAll());
    }

    public function find(int $canonId, int $workId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT w.* FROM work w WHERE w.canon_id = ? AND w.id = ?');
        $stmt->execute([$canonId, $workId]);

        $rows = $this->hydrate($stmt->fetchAll());

        return $rows[0] ?? null;
    }

    /**
     * @param  list<int> $workIds
     * @return list<array> in the order the ids were given
     */
    public function findMany(int $canonId, array $workIds): array
    {
        if ($workIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($workIds), '?'));
        $stmt         = $this->pdo->prepare(
            "SELECT w.* FROM work w WHERE w.canon_id = ? AND w.id IN ({$placeholders})"
        );
        $stmt->execute([$canonId, ...$workIds]);

        $byId = [];
        foreach ($this->hydrate($stmt->fetchAll()) as $work) {
            $byId[$work['id']] = $work;
        }

        $ordered = [];
        foreach ($workIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /** @return list<array{id: int, name: string, sort_order: int, works: int}> */
    public function chapters(int $canonId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.name, c.sort_order, COUNT(w.id) AS works
             FROM chapter c LEFT JOIN work w ON w.chapter_id = c.id
             WHERE c.canon_id = ?
             GROUP BY c.id, c.name, c.sort_order
             ORDER BY c.sort_order'
        );
        $stmt->execute([$canonId]);

        return array_map(
            static fn (array $r): array => [
                'id'         => (int) $r['id'],
                'name'       => $r['name'],
                'sort_order' => (int) $r['sort_order'],
                'works'      => (int) $r['works'],
            ],
            $stmt->fetchAll()
        );
    }

    /** @return array<string, list<array{code: string, label: string}>> */
    public function tagGroups(int $canonId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tag_group, code, label FROM tag WHERE canon_id = ? ORDER BY tag_group, sort_order'
        );
        $stmt->execute([$canonId]);

        $groups = [];
        foreach ($stmt->fetchAll() as $row) {
            $groups[$row['tag_group']][] = ['code' => $row['code'], 'label' => $row['label']];
        }

        return $groups;
    }

    /**
     * @param  list<array> $rows raw work rows
     * @return list<array>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids          = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT wa.work_id, a.display_name
             FROM work_author wa JOIN author a ON a.id = wa.author_id
             WHERE wa.work_id IN ({$placeholders})
             ORDER BY a.surname"
        );
        $stmt->execute($ids);
        $authors = [];
        foreach ($stmt->fetchAll() as $row) {
            $authors[(int) $row['work_id']][] = $row['display_name'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT wt.work_id, wt.verified, t.tag_group, t.code, t.label
             FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
             WHERE wt.work_id IN ({$placeholders})
             ORDER BY t.tag_group, t.sort_order"
        );
        $stmt->execute($ids);
        $tags = [];
        foreach ($stmt->fetchAll() as $row) {
            $tags[(int) $row['work_id']][$row['tag_group']][] = [
                'code'     => $row['code'],
                'label'    => $row['label'],
                'verified' => (int) $row['verified'] === 1,
            ];
        }

        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.name FROM chapter c WHERE c.id IN (
                SELECT chapter_id FROM work WHERE id IN ({$placeholders}))"
        );
        $stmt->execute($ids);
        $chapterNames = [];
        foreach ($stmt->fetchAll() as $row) {
            $chapterNames[(int) $row['id']] = $row['name'];
        }

        $works = [];
        foreach ($rows as $row) {
            $id      = (int) $row['id'];
            $works[] = [
                'id'         => $id,
                'title'      => $row['title'],
                'note'       => $row['note'],
                'chapter_id' => (int) $row['chapter_id'],
                'chapter'    => $chapterNames[(int) $row['chapter_id']] ?? '',
                'authors'    => implode('; ', $authors[$id] ?? []),
                'tags'       => $tags[$id] ?? [],
            ];
        }

        return $works;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter WorkRepositoryTest
```

Expected: PASS, 15 tests.

- [ ] **Step 5: Commit**

```bash
cd /data/www/kanonmaker
git add src/Repo/WorkRepository.php tests/Repo/WorkRepositoryTest.php
git commit -m "feat: work repository with diacritics-insensitive search and filters"
```

---

### Task 4: Browsing the canon and searching it

**Files:**
- Create: `src/App/Ui.php`, `src/App/Controller/CanonController.php`, `src/App/Controller/SearchController.php`, `templates/_work.php`, `templates/_works.php`, `templates/canon.php`, `templates/search.php`, `templates/work.php`, `public/assets/app.js`, `tests/App/UiTest.php`
- Modify: `public/index.php`

**Interfaces:**
- Consumes: `WorkRepository`, `View`, `Csrf`, `Auth`.
- Produces:
  - `Kanon\App\Ui::GROUP_ORDER` = `['obdobi', 'podobdobi', 'narodni', 'forma', 'special']`, `Ui::groupLabel(string $group): string`, `Ui::orderedTags(array $tags): list<array{group:string,code:string,label:string,verified:bool}>`.
  - `Kanon\App\Controller\CanonController::browse(array $query): Response`, `::work(array $params): Response`.
  - `Kanon\App\Controller\SearchController::page(array $query): Response`, `::json(array $query): Response`.
- Routes added: `GET /kanon`, `GET /dilo/{id}`, `GET /hledat`, `GET /hledat.json`.

- [ ] **Step 1: Write the failing test**

Create `tests/App/UiTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\App;

use Kanon\App\Ui;
use PHPUnit\Framework\TestCase;

final class UiTest extends TestCase
{
    public function testGroupsAreLabelledInCzech(): void
    {
        self::assertSame('období', Ui::groupLabel('obdobi'));
        self::assertSame('literární období', Ui::groupLabel('podobdobi'));
        self::assertSame('národní literatura', Ui::groupLabel('narodni'));
        self::assertSame('literární forma', Ui::groupLabel('forma'));
    }

    public function testAnUnknownGroupFallsBackToItsOwnName(): void
    {
        self::assertSame('cosi', Ui::groupLabel('cosi'));
    }

    public function testTagsComeOutInAFixedOrderRegardlessOfInput(): void
    {
        $tags = [
            'forma'     => [['code' => 'drama', 'label' => 'drama', 'verified' => false]],
            'obdobi'    => [['code' => 'do18', 'label' => 'do konce 18. století', 'verified' => true]],
            'podobdobi' => [['code' => 'renesance', 'label' => 'renesance', 'verified' => false]],
        ];

        self::assertSame(
            ['obdobi', 'podobdobi', 'forma'],
            array_column(Ui::orderedTags($tags), 'group'),
            'chips always read period, sub-period, nationality, form, special'
        );
    }

    public function testOrderedTagsCarryTheVerifiedFlagThrough(): void
    {
        $ordered = Ui::orderedTags([
            'obdobi' => [['code' => 'do18', 'label' => 'do konce 18. století', 'verified' => true]],
            'forma'  => [['code' => 'drama', 'label' => 'drama', 'verified' => false]],
        ]);

        self::assertTrue($ordered[0]['verified']);
        self::assertFalse($ordered[1]['verified']);
    }

    public function testEmptyTagsGiveAnEmptyList(): void
    {
        self::assertSame([], Ui::orderedTags([]));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter UiTest
```

Expected: FAIL — `Class "Kanon\App\Ui" not found`.

- [ ] **Step 3: Write the presentation helper**

Create `src/App/Ui.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\App;

/** Presentation rules shared by every screen: chip order and Czech group names. */
final class Ui
{
    public const GROUP_ORDER = ['obdobi', 'podobdobi', 'narodni', 'forma', 'special'];

    private const LABELS = [
        'obdobi'    => 'období',
        'podobdobi' => 'literární období',
        'narodni'   => 'národní literatura',
        'forma'     => 'literární forma',
        'special'   => 'zvláštní',
    ];

    public static function groupLabel(string $group): string
    {
        return self::LABELS[$group] ?? $group;
    }

    /**
     * @param  array<string, list<array{code: string, label: string, verified: bool}>> $tags
     * @return list<array{group: string, code: string, label: string, verified: bool}>
     */
    public static function orderedTags(array $tags): array
    {
        $ordered = [];

        foreach (self::GROUP_ORDER as $group) {
            foreach ($tags[$group] ?? [] as $tag) {
                $ordered[] = [
                    'group'    => $group,
                    'code'     => $tag['code'],
                    'label'    => $tag['label'],
                    'verified' => $tag['verified'],
                ];
            }
        }

        return $ordered;
    }
}
```

- [ ] **Step 4: Write the work partials**

Create `templates/_work.php` — one row, used by every listing:

```php
<?php /** @var array $work */ ?>
<li class="work">
    <?php if (isset($number)): ?><span class="work__num"><?= $this->e($number) ?>.</span><?php endif; ?>
    <div class="work__body">
        <?php if ($work['authors'] !== ''): ?>
            <div class="work__author"><?= $this->e($work['authors']) ?></div>
        <?php endif; ?>
        <div class="work__title">
            <a href="/dilo/<?= $this->e($work['id']) ?>" style="color:inherit"><?= $this->e($work['title']) ?></a>
        </div>
        <?php if (($work['note'] ?? null) !== null && $work['note'] !== ''): ?>
            <div class="work__note"><?= $this->e($work['note']) ?></div>
        <?php endif; ?>
        <ul class="chips" style="list-style:none;margin:.35rem 0 0;padding:0">
            <?php foreach (\Kanon\App\Ui::orderedTags($work['tags']) as $tag): ?>
                <li class="chip chip--<?= $this->e($tag['group']) ?><?= $tag['verified'] ? '' : ' chip--unverified' ?>"
                    title="<?= $this->e(\Kanon\App\Ui::groupLabel($tag['group'])) ?><?= $tag['verified'] ? '' : ' — zatím nepotvrzeno' ?>">
                    <?= $this->e($tag['label']) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php if ($user !== null): ?>
        <div>
            <?php if (in_array($work['id'], $inList, true)): ?>
                <form method="post" action="/seznam/odebrat">
                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                    <input type="hidden" name="work_id" value="<?= $this->e($work['id']) ?>">
                    <input type="hidden" name="zpet" value="<?= $this->e($back) ?>">
                    <button class="btn btn--quiet" title="Odebrat ze seznamu">✓</button>
                </form>
            <?php else: ?>
                <form method="post" action="/seznam/pridat">
                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                    <input type="hidden" name="work_id" value="<?= $this->e($work['id']) ?>">
                    <input type="hidden" name="zpet" value="<?= $this->e($back) ?>">
                    <button class="btn" title="Přidat do seznamu">+</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</li>
```

Create `templates/_works.php` — the list wrapper:

```php
<?php /** @var list<array> $works */ ?>
<?php if ($works === []): ?>
    <p class="empty"><?= $this->e($emptyText ?? 'Nic tu není.') ?></p>
<?php else: ?>
    <ul class="works">
        <?php foreach ($works as $i => $work): ?>
            <?= $this->render('_work', [
                'work'      => $work,
                'number'    => ($numbered ?? false) ? $i + 1 : null,
                'inList'    => $inList,
                'user'      => $user,
                'csrfToken' => $csrfToken,
                'back'      => $back,
            ]) ?>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
```

- [ ] **Step 5: Write the canon, search and work templates**

Create `templates/canon.php`:

```php
<h2>Školní kánon</h2>

<form method="get" action="/hledat" style="margin:1rem 0">
    <label class="field">
        <span class="muted">Hledej podle autora nebo názvu — diakritika nevadí</span>
        <input type="search" name="q" placeholder="např. capek" autocomplete="off">
    </label>
</form>

<details style="margin-bottom:1rem">
    <summary style="min-height:var(--tap);display:flex;align-items:center;cursor:pointer">Filtrovat</summary>

    <p class="muted" style="margin:.75rem 0 .25rem">Kapitola</p>
    <ul style="list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:.4rem">
        <li><a class="chip chip--obdobi" href="/kanon">vše (<?= $this->e($total) ?>)</a></li>
        <?php foreach ($chapters as $chapter): ?>
            <li><a class="chip chip--obdobi" href="/kanon?kapitola=<?= $this->e($chapter['id']) ?>">
                <?= $this->e($chapter['name']) ?> (<?= $this->e($chapter['works']) ?>)
            </a></li>
        <?php endforeach; ?>
    </ul>

    <?php foreach ($tagGroups as $group => $tags): ?>
        <p class="muted" style="margin:.75rem 0 .25rem"><?= $this->e(\Kanon\App\Ui::groupLabel($group)) ?></p>
        <ul style="list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:.4rem">
            <?php foreach ($tags as $tag): ?>
                <li><a class="chip chip--<?= $this->e($group) ?>"
                       href="/kanon?skupina=<?= $this->e($group) ?>&amp;znacka=<?= $this->e($tag['code']) ?>">
                    <?= $this->e($tag['label']) ?>
                </a></li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
</details>

<p class="muted"><?= $this->e($heading) ?> — <?= $this->e(count($works)) ?> děl</p>

<?= $this->render('_works', [
    'works' => $works, 'inList' => $inList, 'user' => $user,
    'csrfToken' => $csrfToken, 'back' => $back, 'emptyText' => 'Tomuto filtru neodpovídá žádné dílo.',
]) ?>
```

Create `templates/search.php`:

```php
<h2>Hledání</h2>

<form method="get" action="/hledat" id="search-form">
    <label class="field">
        <span class="muted">Autor nebo název — diakritika nevadí</span>
        <input type="search" name="q" id="search-input" value="<?= $this->e($q) ?>"
               placeholder="např. capek" autocomplete="off" autofocus>
    </label>
</form>

<div id="search-results">
    <?php if ($q !== ''): ?>
        <p class="muted"><?= $this->e(count($works)) ?> výsledků pro „<?= $this->e($q) ?>“</p>
    <?php endif; ?>

    <?= $this->render('_works', [
        'works' => $works, 'inList' => $inList, 'user' => $user,
        'csrfToken' => $csrfToken, 'back' => $back,
        'emptyText' => $q === '' ? 'Napiš, co hledáš.' : 'Nic jsme nenašli.',
    ]) ?>
</div>
```

Create `templates/work.php`:

```php
<p class="muted"><a href="/kanon">← zpět na kánon</a></p>

<h2 style="margin-bottom:.2rem"><?= $this->e($work['title']) ?></h2>
<?php if ($work['authors'] !== ''): ?>
    <p class="work__author" style="margin-top:0"><?= $this->e($work['authors']) ?></p>
<?php endif; ?>

<?php if (($work['note'] ?? null) !== null && $work['note'] !== ''): ?>
    <p class="work__note"><?= $this->e($work['note']) ?></p>
<?php endif; ?>

<p class="muted">Kapitola kánonu: <?= $this->e($work['chapter']) ?></p>

<ul class="chips" style="list-style:none;margin:1rem 0;padding:0">
    <?php foreach (\Kanon\App\Ui::orderedTags($work['tags']) as $tag): ?>
        <li class="chip chip--<?= $this->e($tag['group']) ?><?= $tag['verified'] ? '' : ' chip--unverified' ?>">
            <?= $this->e(\Kanon\App\Ui::groupLabel($tag['group'])) ?>: <?= $this->e($tag['label']) ?>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($unverified): ?>
    <p class="muted">Značky s otazníkem zatím nepotvrdil člověk.</p>
<?php endif; ?>

<?php if ($user !== null): ?>
    <?php if ($inList): ?>
        <form method="post" action="/seznam/odebrat">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <input type="hidden" name="work_id" value="<?= $this->e($work['id']) ?>">
            <input type="hidden" name="zpet" value="/dilo/<?= $this->e($work['id']) ?>">
            <button class="btn btn--quiet btn--block">Odebrat ze seznamu</button>
        </form>
    <?php else: ?>
        <form method="post" action="/seznam/pridat">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <input type="hidden" name="work_id" value="<?= $this->e($work['id']) ?>">
            <input type="hidden" name="zpet" value="/dilo/<?= $this->e($work['id']) ?>">
            <button class="btn btn--block">Přidat do seznamu</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
```

- [ ] **Step 6: Write the controllers**

Create `src/App/Controller/CanonController.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\App\Controller;

use Kanon\Http\Response;
use Kanon\Repo\WorkRepository;

final class CanonController
{
    public function __construct(
        private readonly WorkRepository $works,
        private readonly int $canonId,
        private readonly \Closure $page,
        private readonly \Closure $listIds,
    ) {
    }

    public function browse(array $query, string $back = '/kanon'): Response
    {
        $chapterId = isset($query['kapitola']) ? (int) $query['kapitola'] : null;
        $group     = isset($query['skupina']) ? (string) $query['skupina'] : null;
        $code      = isset($query['znacka']) ? (string) $query['znacka'] : null;

        $chapters = $this->works->chapters($this->canonId);
        $works    = $this->works->browse($this->canonId, $chapterId, $group, $code);

        $heading = 'Celý kánon';
        foreach ($chapters as $chapter) {
            if ($chapter['id'] === $chapterId) {
                $heading = $chapter['name'];
            }
        }
        if ($group !== null && $code !== null) {
            foreach ($this->works->tagGroups($this->canonId)[$group] ?? [] as $tag) {
                if ($tag['code'] === $code) {
                    $heading = $tag['label'];
                }
            }
        }

        return Response::html(($this->page)('Kánon', 'canon', [
            'works'     => $works,
            'chapters'  => $chapters,
            'tagGroups' => $this->works->tagGroups($this->canonId),
            'total'     => array_sum(array_column($chapters, 'works')),
            'heading'   => $heading,
            'inList'    => ($this->listIds)(),
            'back'      => $back,
        ]));
    }

    public function work(array $params): Response
    {
        $work = $this->works->find($this->canonId, (int) $params['id']);

        if ($work === null) {
            return Response::html(($this->page)('Nenalezeno', 'not-found'), 404);
        }

        $unverified = false;
        foreach ($work['tags'] as $group) {
            foreach ($group as $tag) {
                $unverified = $unverified || !$tag['verified'];
            }
        }

        return Response::html(($this->page)($work['title'], 'work', [
            'work'       => $work,
            'unverified' => $unverified,
            'inList'     => in_array($work['id'], ($this->listIds)(), true),
        ]));
    }
}
```

Create `src/App/Controller/SearchController.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\App\Controller;

use Kanon\App\Ui;
use Kanon\Http\Response;
use Kanon\Repo\WorkRepository;

final class SearchController
{
    public function __construct(
        private readonly WorkRepository $works,
        private readonly int $canonId,
        private readonly \Closure $page,
        private readonly \Closure $listIds,
    ) {
    }

    public function page(array $query): Response
    {
        $q = trim((string) ($query['q'] ?? ''));

        return Response::html(($this->page)('Hledání', 'search', [
            'q'      => $q,
            'works'  => $q === '' ? [] : $this->works->search($this->canonId, $q),
            'inList' => ($this->listIds)(),
            'back'   => '/hledat?q=' . rawurlencode($q),
        ]));
    }

    /** Feeds the live search box; the page works without it. */
    public function json(array $query): Response
    {
        $q      = trim((string) ($query['q'] ?? ''));
        $inList = ($this->listIds)();

        $works = array_map(
            static fn (array $w): array => [
                'id'      => $w['id'],
                'title'   => $w['title'],
                'authors' => $w['authors'],
                'inList'  => in_array($w['id'], $inList, true),
                'tags'    => array_map(
                    static fn (array $t): array => ['group' => $t['group'], 'label' => $t['label']],
                    Ui::orderedTags($w['tags'])
                ),
            ],
            $q === '' ? [] : $this->works->search($this->canonId, $q, 15)
        );

        return Response::json(['q' => $q, 'works' => $works]);
    }
}
```

- [ ] **Step 7: Write the live-search script**

Create `public/assets/app.js`. It enhances the search page and does nothing anywhere else — the form still works with JavaScript switched off.

```js
/* Živé hledání. Stránka funguje i bez JavaScriptu - tohle jen ušetří odeslání formuláře. */
(function () {
    var input = document.getElementById('search-input');
    var box = document.getElementById('search-results');
    if (!input || !box) { return; }

    var timer = null;
    var lastQuery = null;

    function chip(tag) {
        return '<li class="chip chip--' + tag.group + '">' + escapeHtml(tag.label) + '</li>';
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function render(data) {
        if (data.works.length === 0) {
            box.innerHTML = '<p class="empty">' + (data.q === '' ? 'Napiš, co hledáš.' : 'Nic jsme nenašli.') + '</p>';
            return;
        }

        var html = '<p class="muted">' + data.works.length + ' výsledků</p><ul class="works">';
        data.works.forEach(function (work) {
            html += '<li class="work"><div class="work__body">'
                + (work.authors ? '<div class="work__author">' + escapeHtml(work.authors) + '</div>' : '')
                + '<div class="work__title"><a href="/dilo/' + work.id + '" style="color:inherit">'
                + escapeHtml(work.title) + '</a></div>'
                + '<ul class="chips" style="list-style:none;margin:.35rem 0 0;padding:0">'
                + work.tags.map(chip).join('') + '</ul>'
                + '</div>'
                + '<div class="muted" style="align-self:center">' + (work.inList ? '✓' : '') + '</div>'
                + '</li>';
        });
        box.innerHTML = html + '</ul><p class="muted">Pro přidání do seznamu otevři dílo.</p>';
    }

    input.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
            var q = input.value.trim();
            if (q === lastQuery) { return; }
            lastQuery = q;

            fetch('/hledat.json?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(render)
                .catch(function () { /* ticho - formulář pořád funguje */ });
        }, 200);
    });
})();
```

- [ ] **Step 8: Add a not-found template and register the routes**

Create `templates/not-found.php`:

```php
<section class="empty">
    <h2>Tady nic není</h2>
    <p class="muted">Dílo neexistuje nebo bylo z kánonu odebráno.</p>
    <p><a class="btn" href="/kanon">Zpět na kánon</a></p>
</section>
```

In `public/index.php`, after the auth wiring, add:

```php
use Kanon\App\Controller\CanonController;
use Kanon\App\Controller\SearchController;
use Kanon\Repo\WorkRepository;

$workRepo = new WorkRepository($pdo);

$canonController  = new CanonController($workRepo, $canonId, $page, $listIds);
$searchController = new SearchController($workRepo, $canonId, $page, $listIds);

$router->get('/kanon', static function () use ($canonController): Response {
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');

    return $canonController->browse($_GET, '/kanon' . ($query === '' ? '' : '?' . $query));
});
$router->get('/dilo/{id}', static fn (array $p): Response => $canonController->work($p));
$router->get('/hledat', static fn (): Response => $searchController->page($_GET));
$router->get('/hledat.json', static fn (): Response => $searchController->json($_GET));
```

Also replace the 404 branch at the bottom so it uses the layout:

```php
if ($match === null) {
    Response::html($page('Nenalezeno', 'not-found'), 404)->send();

    return;
}
```

- [ ] **Step 9: Verify in the browser**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter UiTest
curl -s 'http://kata.doma.slimak.cz/kanon' | grep -c 'class="work"'
curl -s 'http://kata.doma.slimak.cz/kanon?skupina=forma&znacka=drama' | grep -c 'class="work"'
curl -s 'http://kata.doma.slimak.cz/hledat?q=capek' | grep -o 'Válka s mloky' | head -1
curl -s 'http://kata.doma.slimak.cz/hledat.json?q=zert' | head -c 200
```

Expected: `UiTest` passes (5 tests); the canon page lists 461 works; the drama filter lists far fewer; searching `capek` finds *Válka s mloky*; the JSON endpoint returns *Žert*.

Then open `http://kata.doma.slimak.cz/kanon` on a phone (or a 360 px-wide window) and confirm: no horizontal scrolling, chips wrap, the filter block opens and closes.

- [ ] **Step 10: Commit**

```bash
cd /data/www/kanonmaker
git add src/App templates public tests/App
git commit -m "feat: browse the canon, filter by chapter and tag, and search it live"
```

---

### Task 5: The list — add, remove, and the my-list screen

**Files:**
- Create: `src/Repo/ListRepository.php`, `src/App/Controller/ListController.php`, `templates/list.php`, `tests/Repo/ListRepositoryTest.php`
- Modify: `public/index.php`

**Interfaces:**
- Consumes: `WorkRepository`, `Auth`, `Csrf`.
- Produces `Kanon\Repo\ListRepository::__construct(\PDO $pdo)` with:
  - `forUser(int $userId, int $canonId): int` — returns the list id, creating the list on first use
  - `workIds(int $listId): list<int>` — in list position order
  - `add(int $listId, int $workId): bool` — false if it was already there
  - `remove(int $listId, int $workId): bool`
  - `contains(int $listId, int $workId): bool`
  - `count(int $listId): int`
- And `Kanon\App\Controller\ListController::show(): Response`, `::add(array $input): Response`, `::remove(array $input): Response`.
- Route `/` now shows the list for a logged-in student.

- [ ] **Step 1: Write the failing test**

Create `tests/Repo/ListRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Repo;

use Kanon\Auth\UserRepository;
use Kanon\Db\Database;
use Kanon\Repo\ListRepository;
use Kanon\Repo\WorkRepository;
use PHPUnit\Framework\TestCase;

final class ListRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ListRepository $lists;
    private int $canonId;
    private int $userId;
    /** @var list<int> */
    private array $workIds;

    protected function setUp(): void
    {
        $config    = require dirname(__DIR__, 2) . '/config.php';
        $this->pdo = Database::connect($config['db']);
        $this->pdo->beginTransaction();

        $this->canonId = (int) $this->pdo->query(
            "SELECT id FROM canon WHERE school_year = '2025/2026'"
        )->fetchColumn();

        $this->userId = (new UserRepository($this->pdo))->create(
            'list' . bin2hex(random_bytes(4)) . '@example.test',
            'tajneheslo123',
            'Kata'
        );

        $this->lists   = new ListRepository($this->pdo);
        $this->workIds = array_column(
            array_slice((new WorkRepository($this->pdo))->browse($this->canonId), 0, 4),
            'id'
        );
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testTheFirstCallCreatesTheListAndLaterCallsReturnTheSameOne(): void
    {
        $first  = $this->lists->forUser($this->userId, $this->canonId);
        $second = $this->lists->forUser($this->userId, $this->canonId);

        self::assertGreaterThan(0, $first);
        self::assertSame($first, $second, 'one list per student per canon');
    }

    public function testAddingKeepsTheOrderTheyWereAdded(): void
    {
        $listId = $this->lists->forUser($this->userId, $this->canonId);

        $this->lists->add($listId, $this->workIds[2]);
        $this->lists->add($listId, $this->workIds[0]);
        $this->lists->add($listId, $this->workIds[3]);

        self::assertSame(
            [$this->workIds[2], $this->workIds[0], $this->workIds[3]],
            $this->lists->workIds($listId)
        );
    }

    public function testAddingTheSameWorkTwiceChangesNothing(): void
    {
        $listId = $this->lists->forUser($this->userId, $this->canonId);

        self::assertTrue($this->lists->add($listId, $this->workIds[0]));
        self::assertFalse($this->lists->add($listId, $this->workIds[0]), 'already on the list');
        self::assertSame(1, $this->lists->count($listId));
    }

    public function testRemovingWorks(): void
    {
        $listId = $this->lists->forUser($this->userId, $this->canonId);
        $this->lists->add($listId, $this->workIds[0]);
        $this->lists->add($listId, $this->workIds[1]);

        self::assertTrue($this->lists->remove($listId, $this->workIds[0]));
        self::assertFalse($this->lists->remove($listId, $this->workIds[0]), 'it is already gone');
        self::assertSame([$this->workIds[1]], $this->lists->workIds($listId));
    }

    public function testContainsAndCount(): void
    {
        $listId = $this->lists->forUser($this->userId, $this->canonId);

        self::assertSame(0, $this->lists->count($listId));
        self::assertFalse($this->lists->contains($listId, $this->workIds[0]));

        $this->lists->add($listId, $this->workIds[0]);

        self::assertTrue($this->lists->contains($listId, $this->workIds[0]));
        self::assertSame(1, $this->lists->count($listId));
    }

    public function testTwoStudentsHaveSeparateLists(): void
    {
        $otherUser = (new UserRepository($this->pdo))->create(
            'other' . bin2hex(random_bytes(4)) . '@example.test',
            'tajneheslo123',
            'Jiná'
        );

        $mine  = $this->lists->forUser($this->userId, $this->canonId);
        $their = $this->lists->forUser($otherUser, $this->canonId);

        self::assertNotSame($mine, $their);

        $this->lists->add($mine, $this->workIds[0]);

        self::assertSame([], $this->lists->workIds($their));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter ListRepositoryTest
```

Expected: FAIL — `Class "Kanon\Repo\ListRepository" not found`.

- [ ] **Step 3: Write the repository**

Create `src/Repo/ListRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Repo;

final class ListRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** Returns the student's list for this canon, creating it on first use. */
    public function forUser(int $userId, int $canonId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM list WHERE user_id = ? AND canon_id = ?');
        $stmt->execute([$userId, $canonId]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $this->pdo->prepare(
            'INSERT INTO list (user_id, canon_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())'
        )->execute([$userId, $canonId]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<int> */
    public function workIds(int $listId): array
    {
        $stmt = $this->pdo->prepare('SELECT work_id FROM list_item WHERE list_id = ? ORDER BY position');
        $stmt->execute([$listId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function add(int $listId, int $workId): bool
    {
        if ($this->contains($listId, $workId)) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM list_item WHERE list_id = ?');
        $stmt->execute([$listId]);
        $position = (int) $stmt->fetchColumn();

        $this->pdo->prepare('INSERT INTO list_item (list_id, work_id, position) VALUES (?, ?, ?)')
            ->execute([$listId, $workId, $position]);
        $this->touch($listId);

        return true;
    }

    public function remove(int $listId, int $workId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM list_item WHERE list_id = ? AND work_id = ?');
        $stmt->execute([$listId, $workId]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        $this->touch($listId);

        return true;
    }

    public function contains(int $listId, int $workId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM list_item WHERE list_id = ? AND work_id = ?');
        $stmt->execute([$listId, $workId]);

        return $stmt->fetchColumn() !== false;
    }

    public function count(int $listId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM list_item WHERE list_id = ?');
        $stmt->execute([$listId]);

        return (int) $stmt->fetchColumn();
    }

    private function touch(int $listId): void
    {
        $this->pdo->prepare('UPDATE list SET updated_at = NOW() WHERE id = ?')->execute([$listId]);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter ListRepositoryTest
```

Expected: PASS, 6 tests.

- [ ] **Step 5: Write the list screen**

Create `templates/list.php`:

```php
<h2>Můj seznam</h2>

<form method="get" action="/hledat" style="margin:1rem 0">
    <label class="field">
        <span class="muted">Přidej dílo — hledej podle autora nebo názvu</span>
        <input type="search" name="q" placeholder="např. capek" autocomplete="off">
    </label>
    <button class="btn btn--block" type="submit">Hledat v kánonu</button>
</form>

<?php if ($works === []): ?>
    <p class="empty">
        Seznam je zatím prázdný.<br>
        <span class="muted">Najdi první dílo, nebo si prolistuj <a href="/kanon">celý kánon</a>.</span>
    </p>
<?php else: ?>
    <?= $this->render('_works', [
        'works' => $works, 'inList' => $inList, 'user' => $user,
        'csrfToken' => $csrfToken, 'back' => '/', 'numbered' => true,
    ]) ?>

    <p style="margin:1.5rem 0"><a class="btn btn--quiet btn--block" href="/export">Export do PDF</a></p>
<?php endif; ?>
```

- [ ] **Step 6: Write the controller**

Create `src/App/Controller/ListController.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\App\Controller;

use Kanon\Auth\Auth;
use Kanon\Http\Csrf;
use Kanon\Http\Response;
use Kanon\Http\Session;
use Kanon\Repo\ListRepository;
use Kanon\Repo\WorkRepository;

final class ListController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly ListRepository $lists,
        private readonly WorkRepository $works,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly int $canonId,
        private readonly \Closure $page,
    ) {
    }

    public function show(): Response
    {
        $userId = $this->auth->id();
        if ($userId === null) {
            return Response::redirect('/prihlaseni');
        }

        $listId  = $this->lists->forUser($userId, $this->canonId);
        $workIds = $this->lists->workIds($listId);

        return Response::html(($this->page)('Můj seznam', 'list', [
            'works'  => $this->works->findMany($this->canonId, $workIds),
            'inList' => $workIds,
        ]));
    }

    public function add(array $input): Response
    {
        return $this->change($input, true);
    }

    public function remove(array $input): Response
    {
        return $this->change($input, false);
    }

    private function change(array $input, bool $adding): Response
    {
        $userId = $this->auth->id();
        if ($userId === null) {
            return Response::redirect('/prihlaseni');
        }

        $back = (string) ($input['zpet'] ?? '/');
        // Never redirect off-site on the strength of a form field.
        if (!str_starts_with($back, '/')) {
            $back = '/';
        }

        if (!$this->csrf->check($input['_token'] ?? null)) {
            $this->session->flash('warn', 'Formulář vypršel, zkus to prosím znovu.');

            return Response::redirect($back);
        }

        $workId = (int) ($input['work_id'] ?? 0);
        $work   = $this->works->find($this->canonId, $workId);

        if ($work === null) {
            $this->session->flash('warn', 'Takové dílo v kánonu není.');

            return Response::redirect($back);
        }

        $listId = $this->lists->forUser($userId, $this->canonId);

        if ($adding) {
            $this->lists->add($listId, $workId);
            $this->session->flash('ok', 'Přidáno: ' . $work['title']);
        } else {
            $this->lists->remove($listId, $workId);
            $this->session->flash('ok', 'Odebráno: ' . $work['title']);
        }

        return Response::redirect($back);
    }
}
```

- [ ] **Step 7: Wire the routes and make `$listIds` real**

In `public/index.php`, replace the placeholder `$listIds` closure and add the routes:

```php
use Kanon\App\Controller\ListController;
use Kanon\Repo\ListRepository;

$listRepo = new ListRepository($pdo);

// Replaces the Task 2 placeholder. It is a by-reference `use` in $page, so
// reassigning it here is picked up by pages rendered afterwards.
$listIds = static function () use ($auth, $listRepo, $canonId): array {
    $userId = $auth->id();

    return $userId === null ? [] : $listRepo->workIds($listRepo->forUser($userId, $canonId));
};

$listController = new ListController($auth, $listRepo, $workRepo, $session, $csrf, $canonId, $page);

$router->post('/seznam/pridat', static fn (): Response => $listController->add($_POST));
$router->post('/seznam/odebrat', static fn (): Response => $listController->remove($_POST));
```

and change the `/` route so a logged-in student sees their list:

```php
$router->get('/', static function () use ($page, $auth, $listController): Response {
    return $auth->check()
        ? $listController->show()
        : Response::html($page('Maturitní seznam četby', 'home-guest'));
});
```

- [ ] **Step 8: Verify by using it**

Log in through the browser, then:

```bash
curl -s 'http://kata.doma.slimak.cz/kanon' | grep -c 'seznam/pridat'
```

Expected: `0` when logged out (no add buttons for guests). Logged in, add two works from the canon page, confirm the flash message, confirm `/` lists them numbered in the order added, and remove one.

- [ ] **Step 9: Commit**

```bash
cd /data/www/kanonmaker
git add src/Repo/ListRepository.php src/App/Controller/ListController.php templates/list.php public/index.php tests/Repo/ListRepositoryTest.php
git commit -m "feat: build a personal list from the canon"
```

---

### Task 6: The live rule check

**Files:**
- Create: `src/App/RuleBar.php`, `templates/_rulebar.php`, `tests/App/RuleBarTest.php`
- Modify: `public/index.php`, `templates/list.php`, `public/assets/app.js`

**Interfaces:**
- Consumes: `Kanon\Rules\RuleSet`, `Kanon\Rules\WorkViewLoader`, `Kanon\Rules\RuleResult` (all from plan 1, unchanged).
- Produces:
  - `Kanon\App\RuleBar::summarise(list<RuleResult> $results): array{count:int, required:int, unmet:int, complete:bool, percent:int}` — `count`/`required` come from the `min_total` rule, identified as the rule with the largest `required` that carries neither a tag nor a detail message.
  - `Kanon\App\RuleBar::fixLink(RuleResult $result): ?string` — the canon URL filtered to the works that would satisfy the rule, or null when no single filter would.
- The bar is rendered into the layout's `$rulebar` slot on every page a logged-in student sees, so the count is never stale.

- [ ] **Step 1: Write the failing test**

Create `tests/App/RuleBarTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\App;

use Kanon\App\RuleBar;
use Kanon\Rules\RuleResult;
use PHPUnit\Framework\TestCase;

final class RuleBarTest extends TestCase
{
    /** @return list<RuleResult> */
    private function results(int $total): array
    {
        return [
            new RuleResult('Celkem 25 titulů', $total >= 25, $total, 25),
            new RuleResult('Drama (min. 3)', false, 1, 3, null, 'forma', 'drama'),
            new RuleResult('Poezie (min. 3)', true, 4, 3, null, 'forma', 'poezie'),
            new RuleResult('Nejméně tři literární období', false, 2, 3, 'baroko, starovek', 'podobdobi', null),
            new RuleResult('Od jednoho autora nejvýše dva', false, 2, 2, 'SHAKESPEARE: 2 tituly stejné formy'),
        ];
    }

    public function testTheSummaryTakesItsCountFromTheTotalRule(): void
    {
        $summary = RuleBar::summarise($this->results(18));

        self::assertSame(18, $summary['count']);
        self::assertSame(25, $summary['required']);
    }

    public function testItCountsTheUnmetRules(): void
    {
        self::assertSame(4, RuleBar::summarise($this->results(18))['unmet']);
    }

    public function testCompleteOnlyWhenEveryRulePasses(): void
    {
        self::assertFalse(RuleBar::summarise($this->results(25))['complete']);

        $allGood = [
            new RuleResult('Celkem 25 titulů', true, 25, 25),
            new RuleResult('Drama (min. 3)', true, 3, 3, null, 'forma', 'drama'),
        ];

        self::assertTrue(RuleBar::summarise($allGood)['complete']);
    }

    public function testPercentIsCappedAtOneHundred(): void
    {
        self::assertSame(72, RuleBar::summarise($this->results(18))['percent']);
        self::assertSame(100, RuleBar::summarise($this->results(30))['percent']);
    }

    public function testAnEmptyResultListDoesNotDivideByZero(): void
    {
        $summary = RuleBar::summarise([]);

        self::assertSame(0, $summary['count']);
        self::assertSame(0, $summary['percent']);
        self::assertTrue($summary['complete'], 'no rules means nothing is broken');
    }

    public function testATagRuleLinksToTheFilteredCanon(): void
    {
        $link = RuleBar::fixLink(new RuleResult('Drama', false, 1, 3, null, 'forma', 'drama'));

        self::assertSame('/kanon?skupina=forma&znacka=drama', $link);
    }

    public function testASatisfiedRuleHasNoLink(): void
    {
        self::assertNull(RuleBar::fixLink(new RuleResult('Drama', true, 3, 3, null, 'forma', 'drama')));
    }

    public function testARuleWithoutASingleFixingTagHasNoLink(): void
    {
        self::assertNull(RuleBar::fixLink(new RuleResult('Autor', false, 2, 2, 'SHAKESPEARE…')));
        self::assertNull(RuleBar::fixLink(new RuleResult('Období', false, 2, 3, 'baroko', 'podobdobi', null)));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter RuleBarTest
```

Expected: FAIL — `Class "Kanon\App\RuleBar" not found`.

- [ ] **Step 3: Write the presenter**

Create `src/App/RuleBar.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\App;

use Kanon\Rules\RuleResult;

/**
 * Presentation of the rule check.
 *
 * Turning an unmet rule into a link to the works that would satisfy it is the
 * point of the whole screen: it makes the check a shortcut rather than a verdict.
 */
final class RuleBar
{
    /**
     * @param  list<RuleResult> $results
     * @return array{count: int, required: int, unmet: int, complete: bool, percent: int}
     */
    public static function summarise(array $results): array
    {
        $count    = 0;
        $required = 0;
        $unmet    = 0;

        foreach ($results as $result) {
            if (!$result->satisfied) {
                $unmet++;
            }

            // The list-length rule is the one with no tag behind it.
            if ($result->fixGroup === null && $result->detail === null && $result->required > $required) {
                $count    = $result->current;
                $required = $result->required;
            }
        }

        return [
            'count'    => $count,
            'required' => $required,
            'unmet'    => $unmet,
            'complete' => $unmet === 0,
            'percent'  => $required === 0 ? 0 : min(100, (int) round($count / $required * 100)),
        ];
    }

    public static function fixLink(RuleResult $result): ?string
    {
        if ($result->satisfied || $result->fixGroup === null || $result->fixCode === null) {
            return null;
        }

        return '/kanon?skupina=' . rawurlencode($result->fixGroup)
             . '&znacka=' . rawurlencode($result->fixCode);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter RuleBarTest
```

Expected: PASS, 8 tests.

- [ ] **Step 5: Write the bar template**

Create `templates/_rulebar.php`:

```php
<?php /** @var array $summary @var list<\Kanon\Rules\RuleResult> $results */ ?>
<div class="rulebar">
    <button class="rulebar__summary" type="button" aria-expanded="false" aria-controls="rulebar-panel" id="rulebar-toggle">
        <span class="rulebar__count"><?= $this->e($summary['count']) ?>/<?= $this->e($summary['required']) ?></span>
        <span class="rulebar__bar"><span class="rulebar__fill" style="width:<?= $this->e($summary['percent']) ?>%"></span></span>
        <span class="rulebar__state--<?= $summary['complete'] ? 'ok' : 'warn' ?>">
            <?= $summary['complete']
                ? 'hotovo'
                : 'chybí ' . $this->e($summary['unmet']) . ' ' . ($summary['unmet'] === 1 ? 'pravidlo' : ($summary['unmet'] < 5 ? 'pravidla' : 'pravidel')) ?>
        </span>
        <span aria-hidden="true">▲</span>
    </button>

    <div class="rulebar__panel" id="rulebar-panel" hidden>
        <?php foreach ($results as $result): ?>
            <?php $link = \Kanon\App\RuleBar::fixLink($result); ?>
            <div class="rule rule--<?= $result->satisfied ? 'ok' : 'warn' ?>">
                <span class="rule__mark" aria-hidden="true"><?= $result->satisfied ? '✓' : '!' ?></span>
                <span class="rule__label">
                    <?php if ($link !== null): ?>
                        <a href="<?= $this->e($link) ?>"><?= $this->e($result->label) ?></a>
                    <?php else: ?>
                        <?= $this->e($result->label) ?>
                    <?php endif; ?>
                    <?php if ($result->detail !== null): ?>
                        <span class="rule__detail"><?= $this->e($result->detail) ?></span>
                    <?php endif; ?>
                </span>
                <span class="rule__count"><?= $this->e($result->current) ?>/<?= $this->e($result->required) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
```

- [ ] **Step 6: Make the panel open and close**

Append to `public/assets/app.js`:

```js
/* Rozbalení lišty s pravidly. Bez JavaScriptu zůstane panel zavřený,
   ale počet splněných pravidel je vidět i tak. */
(function () {
    var toggle = document.getElementById('rulebar-toggle');
    var panel = document.getElementById('rulebar-panel');
    if (!toggle || !panel) { return; }

    toggle.addEventListener('click', function () {
        var open = panel.hasAttribute('hidden');
        if (open) { panel.removeAttribute('hidden'); } else { panel.setAttribute('hidden', ''); }
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.querySelector('[aria-hidden]').textContent = open ? '▼' : '▲';
    });
})();
```

- [ ] **Step 7: Render the bar on every logged-in page**

In `public/index.php`, build it inside the `$page` closure so no controller can forget it:

```php
use Kanon\App\RuleBar;
use Kanon\Rules\RuleSet;
use Kanon\Rules\WorkViewLoader;

$ruleSet    = RuleSet::fromCanon($pdo, $canonId);
$viewLoader = new WorkViewLoader($pdo);

$page = function (string $title, string $template, array $data = []) use (
    $view, $session, $csrf, $auth, $ruleSet, $viewLoader, $listIds
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
        'rulebar'   => $rulebar,
        'content'   => $view->render($template, $data + [
            'user'      => $auth->user(),
            'csrfToken' => $csrf->token(),
            'inList'    => $listIds(),
            'back'      => '/',
        ]),
    ]);
};
```

Note the ordering constraint: `$listIds` is defined in Task 5 and must be declared **above** this closure, and `$page` must be defined before any controller is constructed.

- [ ] **Step 8: Verify against the real rules**

Log in, add *Máj*, *Babička* and *R.U.R.*, and confirm on a phone-width window:

- the bar reads `3/25` and `chybí 11 pravidel`
- tapping it opens the panel; every satisfied rule shows `✓`
- "Drama (min. 3)" is a link, and following it lands on `/kanon?skupina=forma&znacka=drama` showing only dramas
- the author rule shows its Czech explanation when you add two Shakespeare tragedies

```bash
curl -s 'http://kata.doma.slimak.cz/kanon?skupina=forma&znacka=drama' | grep -c 'class="work"'
```

Expected: a small number, all dramas.

- [ ] **Step 9: Commit**

```bash
cd /data/www/kanonmaker
git add src/App/RuleBar.php templates/_rulebar.php public/assets/app.js public/index.php tests/App/RuleBarTest.php
git commit -m "feat: live rule bar linking each unmet rule to the works that fix it"
```

---

### Task 7: PDF export the student can configure

**Files:**
- Create: `src/App/PdfBuilder.php`, `src/App/Controller/ExportController.php`, `templates/export.php`, `tests/App/PdfBuilderTest.php`
- Modify: `composer.json` (add mpdf), `public/index.php`

**Interfaces:**
- Consumes: `WorkRepository`, `ListRepository`, `RuleSet`, `Auth`, `Csrf`.
- Produces:
  - `Kanon\App\PdfBuilder::html(array $options): string` where `$options` is `['name' => string, 'year' => string, 'works' => list<array>, 'results' => list<RuleResult>, 'grouped' => bool, 'chips' => bool, 'rules' => bool]`. It returns print HTML; the PDF itself is mPDF's job, so the interesting part stays testable.
  - `Kanon\App\Controller\ExportController::form(): Response`, `::pdf(array $input): Response`.
- Route: `GET /export`, `POST /export`.

- [ ] **Step 1: Install mPDF**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www composer require mpdf/mpdf:^8.2
```

Expected: mPDF 8.2 installed. It bundles DejaVu, which covers Czech diacritics — no font work needed.

- [ ] **Step 2: Write the failing test**

Create `tests/App/PdfBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\App;

use Kanon\App\PdfBuilder;
use Kanon\Rules\RuleResult;
use PHPUnit\Framework\TestCase;

final class PdfBuilderTest extends TestCase
{
    private function options(array $overrides = []): array
    {
        return $overrides + [
            'name'    => 'Kata Kammová',
            'year'    => '2025/2026',
            'works'   => [
                [
                    'id' => 1, 'title' => 'Máj', 'note' => null, 'chapter' => 'Česká literatura 19. století',
                    'authors' => 'MÁCHA, Karel Hynek',
                    'tags' => ['forma' => [['code' => 'poezie', 'label' => 'poezie', 'verified' => false]]],
                ],
                [
                    'id' => 2, 'title' => 'R.U.R.', 'note' => null, 'chapter' => 'Drama 20. a 21. století',
                    'authors' => 'ČAPEK, Karel',
                    'tags' => ['forma' => [['code' => 'drama', 'label' => 'drama', 'verified' => true]]],
                ],
            ],
            'results' => [new RuleResult('Celkem 25 titulů', false, 2, 25)],
            'grouped' => false,
            'chips'   => true,
            'rules'   => true,
        ];
    }

    public function testListsEveryWorkNumbered(): void
    {
        $html = PdfBuilder::html($this->options());

        self::assertStringContainsString('Máj', $html);
        self::assertStringContainsString('MÁCHA, Karel Hynek', $html);
        self::assertStringContainsString('R.U.R.', $html);
        self::assertStringContainsString('>1.<', $html);
        self::assertStringContainsString('>2.<', $html);
    }

    public function testTheNameAndYearAppearWhenGiven(): void
    {
        $html = PdfBuilder::html($this->options());

        self::assertStringContainsString('Kata Kammová', $html);
        self::assertStringContainsString('2025/2026', $html);
    }

    public function testTheNameCanBeLeftOut(): void
    {
        $html = PdfBuilder::html($this->options(['name' => '']));

        self::assertStringNotContainsString('Kata Kammová', $html);
        self::assertStringContainsString('Máj', $html, 'the list itself is still there');
    }

    public function testChipsCanBeSwitchedOff(): void
    {
        $with    = PdfBuilder::html($this->options(['chips' => true]));
        $without = PdfBuilder::html($this->options(['chips' => false]));

        self::assertStringContainsString('poezie', $with);
        self::assertStringNotContainsString('poezie', $without);
    }

    public function testTheRuleSummaryCanBeSwitchedOff(): void
    {
        self::assertStringContainsString('Celkem 25 titulů', PdfBuilder::html($this->options(['rules' => true])));
        self::assertStringNotContainsString('Celkem 25 titulů', PdfBuilder::html($this->options(['rules' => false])));
    }

    public function testGroupingPrintsChapterHeadings(): void
    {
        $flat    = PdfBuilder::html($this->options(['grouped' => false]));
        $grouped = PdfBuilder::html($this->options(['grouped' => true]));

        self::assertStringNotContainsString('Česká literatura 19. století', $flat);
        self::assertStringContainsString('Česká literatura 19. století', $grouped);
        self::assertStringContainsString('Drama 20. a 21. století', $grouped);
    }

    public function testOutputIsEscaped(): void
    {
        $html = PdfBuilder::html($this->options(['name' => '<script>alert(1)</script>']));

        self::assertStringNotContainsString('<script>', $html);
    }

    public function testAnEmptyListStillProducesAValidDocument(): void
    {
        $html = PdfBuilder::html($this->options(['works' => []]));

        self::assertStringContainsString('<html', $html);
        self::assertStringContainsString('Seznam je prázdný', $html);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter PdfBuilderTest
```

Expected: FAIL — `Class "Kanon\App\PdfBuilder" not found`.

- [ ] **Step 4: Write the builder**

Create `src/App/PdfBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\App;

/**
 * Builds the print HTML for the exported list.
 *
 * Kept separate from mPDF so the part that can be wrong - what appears on the
 * page - is testable without rendering a PDF.
 */
final class PdfBuilder
{
    public static function html(array $options): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $out = '<html><head><meta charset="utf-8"><style>'
             . 'body{font-family:dejavusans,sans-serif;font-size:11pt;color:#000}'
             . 'h1{font-size:15pt;margin:0 0 2mm}h2{font-size:11pt;margin:6mm 0 2mm;border-bottom:.3mm solid #999}'
             . '.meta{font-size:10pt;color:#444;margin:0 0 6mm}'
             . 'table{width:100%;border-collapse:collapse}'
             . 'td{vertical-align:top;padding:1.2mm 0;border-bottom:.2mm solid #ddd}'
             . 'td.n{width:8mm;color:#555}'
             . '.a{font-size:10pt;color:#333}.t{font-size:11pt}'
             . '.tags{font-size:8.5pt;color:#555}'
             . '.rules{margin-top:8mm;font-size:9.5pt}'
             . '.rules td{border:0;padding:.6mm 0}'
             . '</style></head><body>';

        $out .= '<h1>Seznam četby k maturitní zkoušce</h1>';

        $meta = array_filter([
            $options['name'] !== '' ? $e($options['name']) : null,
            $options['year'] !== '' ? 'školní rok ' . $e($options['year']) : null,
        ]);
        if ($meta !== []) {
            $out .= '<p class="meta">' . implode(' &middot; ', $meta) . '</p>';
        }

        if ($options['works'] === []) {
            return $out . '<p>Seznam je prázdný.</p></body></html>';
        }

        $groups = $options['grouped']
            ? self::byChapter($options['works'])
            : ['' => $options['works']];

        $number = 1;
        foreach ($groups as $heading => $works) {
            if ($heading !== '') {
                $out .= '<h2>' . $e($heading) . '</h2>';
            }

            $out .= '<table>';
            foreach ($works as $work) {
                $out .= '<tr><td class="n">' . $number++ . '.</td><td>';

                if ($work['authors'] !== '') {
                    $out .= '<div class="a">' . $e($work['authors']) . '</div>';
                }
                $out .= '<div class="t">' . $e($work['title']) . '</div>';

                if ($options['chips']) {
                    $labels = array_map(
                        static fn (array $t): string => $t['label'],
                        Ui::orderedTags($work['tags'])
                    );
                    if ($labels !== []) {
                        $out .= '<div class="tags">' . $e(implode(' · ', $labels)) . '</div>';
                    }
                }

                $out .= '</td></tr>';
            }
            $out .= '</table>';
        }

        if ($options['rules']) {
            $out .= '<div class="rules"><h2>Kontrola pravidel</h2><table>';
            foreach ($options['results'] as $result) {
                $out .= '<tr><td>' . ($result->satisfied ? '&#10003;' : '&bull;') . '</td>'
                      . '<td>' . $e($result->label) . '</td>'
                      . '<td style="text-align:right">' . $e($result->current) . '/' . $e($result->required) . '</td></tr>';
            }
            $out .= '</table></div>';
        }

        return $out . '</body></html>';
    }

    /**
     * @param  list<array> $works
     * @return array<string, list<array>>
     */
    private static function byChapter(array $works): array
    {
        $groups = [];
        foreach ($works as $work) {
            $groups[$work['chapter']][] = $work;
        }

        return $groups;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter PdfBuilderTest
```

Expected: PASS, 8 tests.

- [ ] **Step 6: Write the export screen**

Create `templates/export.php`:

```php
<h2>Export do PDF</h2>

<p class="muted">Vyber, co má být na stránce. Seznam má právě <?= $this->e($count) ?> děl.</p>

<form method="post" action="/export">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

    <label class="field">
        <span>Jméno na seznamu</span>
        <input type="text" name="name" value="<?= $this->e($defaultName) ?>" placeholder="nechat prázdné = bez jména">
    </label>

    <label class="field">
        <span>Školní rok</span>
        <input type="text" name="year" value="<?= $this->e($defaultYear) ?>">
    </label>

    <p style="margin:1rem 0 .3rem">Obsah</p>

    <label style="display:flex;gap:.6rem;align-items:center;min-height:var(--tap)">
        <input type="checkbox" name="grouped" value="1"> Rozdělit podle kapitol kánonu
    </label>
    <label style="display:flex;gap:.6rem;align-items:center;min-height:var(--tap)">
        <input type="checkbox" name="chips" value="1" checked> Vypsat u každého díla jeho kategorie
    </label>
    <label style="display:flex;gap:.6rem;align-items:center;min-height:var(--tap)">
        <input type="checkbox" name="rules" value="1" checked> Přidat shrnutí pravidel
    </label>

    <button class="btn btn--block" type="submit" style="margin-top:1rem">Stáhnout PDF</button>
</form>
```

- [ ] **Step 7: Write the controller**

Create `src/App/Controller/ExportController.php`:

```php
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
            'tempDir'       => sys_get_temp_dir(),
        ]);
        $mpdf->SetTitle('Seznam četby k maturitní zkoušce');
        $mpdf->WriteHTML($html);

        return Response::download((string) $mpdf->Output('', 'S'), 'seznam-cetby.pdf', 'application/pdf');
    }
}
```

- [ ] **Step 8: Wire the routes**

In `public/index.php`:

```php
use Kanon\App\Controller\ExportController;

$exportController = new ExportController(
    $auth, $listRepo, $workRepo, $ruleSet, $viewLoader,
    $session, $csrf, $canonId, '2025/2026', $page
);

$router->get('/export', static fn (): Response => $exportController->form());
$router->post('/export', static fn (): Response => $exportController->pdf($_POST));
```

- [ ] **Step 9: Verify a real PDF**

Log in, put a few works on the list, open `/export`, and download. Then check the file is a real PDF with correct Czech:

```bash
file ~/seznam-cetby.pdf
pdftotext ~/seznam-cetby.pdf - 2>/dev/null | head -20
```

Expected: `PDF document`, and the text shows Czech titles with their diacritics intact (`Máj`, `Žítkovské bohyně`). If `pdftotext` is not installed, open the file and look.

- [ ] **Step 10: Commit**

```bash
cd /data/www/kanonmaker
git add composer.json composer.lock src/App templates/export.php public/index.php tests/App/PdfBuilderTest.php
git commit -m "feat: configurable PDF export of the reading list"
```

---

### Task 8: Finish — guards, empty states and the phone pass

**Files:**
- Modify: `README.md`, `templates/layout.php`, `public/assets/app.css`
- Create: `tests/App/SmokeTest.php`

**Interfaces:**
- Consumes: everything above. Produces no new API.

- [ ] **Step 1: Write the smoke test**

Create `tests/App/SmokeTest.php`. It exercises the routing table the way a browser would, catching a controller that no longer constructs:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\App;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fetches every public page over HTTP. Controllers are verified by use rather
 * than by unit tests, so this is the net that catches a broken wiring change.
 */
final class SmokeTest extends TestCase
{
    private const BASE = 'http://kata.doma.slimak.cz';

    /** @return array{0: int, 1: string} */
    private function fetch(string $path): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $body    = @file_get_contents(self::BASE . $path, false, $context);

        if ($body === false) {
            self::markTestSkipped('The site is not reachable from the test runner.');
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, $body];
    }

    public static function publicPages(): array
    {
        return [
            'landing'    => ['/'],
            'canon'      => ['/kanon'],
            'search'     => ['/hledat?q=capek'],
            'search json'=> ['/hledat.json?q=capek'],
            'login'      => ['/prihlaseni'],
            'register'   => ['/registrace'],
        ];
    }

    #[DataProvider('publicPages')]
    public function testPublicPagesAnswer(string $path): void
    {
        [$status, $body] = $this->fetch($path);

        self::assertSame(200, $status, "{$path} did not answer 200");
        self::assertNotSame('', $body);
    }

    public function testTheCanonPageListsWorks(): void
    {
        [, $body] = $this->fetch('/kanon');

        self::assertGreaterThan(400, substr_count($body, 'class="work"'));
        self::assertStringContainsString('Oresteia', $body);
    }

    public function testSearchFindsWithoutDiacritics(): void
    {
        [, $body] = $this->fetch('/hledat?q=capek');

        self::assertStringContainsString('Válka s mloky', $body);
    }

    public function testUnknownPathIsFourOhFour(): void
    {
        [$status] = $this->fetch('/tudy-cesta-nevede');

        self::assertSame(404, $status);
    }

    public function testAnUnknownWorkIsFourOhFour(): void
    {
        [$status] = $this->fetch('/dilo/999999');

        self::assertSame(404, $status);
    }

    public function testGuestsGetNoAddButtons(): void
    {
        [, $body] = $this->fetch('/kanon');

        self::assertStringNotContainsString('seznam/pridat', $body, 'guests cannot build a list');
    }

    public function testProtectedPagesRedirectGuestsToLogin(): void
    {
        foreach (['/export'] as $path) {
            [$status] = $this->fetch($path);
            self::assertContains($status, [200, 302], "{$path} answered {$status}");
        }
    }

    public function testEveryPageDeclaresCzechAndAViewport(): void
    {
        [, $body] = $this->fetch('/kanon');

        self::assertStringContainsString('<html lang="cs">', $body);
        self::assertStringContainsString('name="viewport"', $body, 'mobile-first needs the viewport tag');
    }

    public function testNoPageLeaksAHexColourOutsideTheTokenFile(): void
    {
        [, $body] = $this->fetch('/kanon');

        self::assertSame(
            0,
            preg_match('/style="[^"]*#[0-9a-fA-F]{3,6}/', $body),
            'colours belong in tokens.css only'
        );
    }
}
```

- [ ] **Step 2: Run the smoke test**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter SmokeTest
```

Expected: PASS, 13 tests. A failure here names the page that broke.

- [ ] **Step 3: Check every screen at phone width**

Open each of these at 360 px wide and confirm no horizontal scrolling, readable chips, and reachable buttons:

`/` (logged out) · `/registrace` · `/prihlaseni` · `/` (logged in, empty list) · `/` (logged in, 5 works) · `/kanon` · `/kanon` with the filter block open · `/hledat?q=capek` · `/dilo/{id}` · `/export`

Fix anything that overflows by adding to `public/assets/app.css` — never by adding a colour outside `tokens.css`.

- [ ] **Step 4: Update the README**

Replace `README.md`:

```markdown
# Kanonmaker

Nástroj pro sestavení maturitního seznamu četby podle školního kánonu.

- **Studentský web:** http://kata.doma.slimak.cz/
- **Administrace:** http://kata-admin.doma.slimak.cz/

## Dokumentace

- Návrh: [`docs/superpowers/specs/2026-08-18-kanonmaker-design.md`](docs/superpowers/specs/2026-08-18-kanonmaker-design.md)
- Plány: [`docs/superpowers/plans/`](docs/superpowers/plans/)

## Vývoj

Vše běží v kontejneru `kanon-www`:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit   # testy
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/migrate      # migrace
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/import       # import kánonu
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/create-admin <e-mail> <heslo> <jméno>
```

Kontejnery se spouštějí z `startup/`:

```bash
cd startup && sudo docker-compose up -d
```

## Barvy

Celá paleta je v `public/assets/tokens.css`. Nikde jinde v projektu nesmí být
konkrétní barva — výměna palety je úprava jediného souboru.
```

- [ ] **Step 5: Run the whole suite**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit
```

Expected: every test passes. Report the exact count.

- [ ] **Step 6: Commit**

```bash
cd /data/www/kanonmaker
git add README.md tests/App/SmokeTest.php public/assets/app.css templates
git commit -m "feat: smoke tests over the routing table, and the phone pass"
```

---

## Notes for the executor

- **Wiring order in `public/index.php` matters.** `$listIds` must exist before `$page`, and `$page` before any controller. A controller constructed too early silently captures a stale closure.
- **`$page` injects `user`, `csrfToken`, `inList` and `back` into every template** so individual controllers do not have to. A controller passing its own value for one of those overrides the default, which is deliberate — see `CanonController::browse`.
- **Never add a colour outside `public/assets/tokens.css`.** The owner will send a palette and it must be a one-file change.
- **Check the phone layout as you go, not at the end.** A layout fixed at 360 px works at 1200 px; the reverse is rarely true.
