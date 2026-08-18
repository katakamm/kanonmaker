# Kanonmaker Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the data foundation of kanonmaker — containers, database schema, a deterministic importer that turns the committed school-canon snapshot into ~454 tagged works, and a rules engine that can evaluate a reading list against the school's four selection criteria.

**Architecture:** Plain PHP 8.3 with no framework, PSR-4 autoloading under the `Kanon\` namespace, PDO against MariaDB in Docker. The importer is a pipeline of four small single-responsibility classes (parse → split → tag → upsert) so each stage is testable against fixtures without a database. The rules engine is a set of four `Rule` implementations built from database rows by a factory, evaluating an in-memory array of `WorkView` objects, so rule logic is pure and needs no database in tests.

**Tech Stack:** PHP 8.3.25, Composer, PHPUnit 11, MariaDB 10.11, Docker, Apache with `mod_proxy_fcgi`.

**Spec:** `docs/superpowers/specs/2026-08-18-kanonmaker-design.md`

## Global Constraints

- PHP `>= 8.3`. Declare `declare(strict_types=1);` at the top of every PHP file.
- Namespace `Kanon\` maps to `src/`, `Kanon\Tests\` maps to `tests/` (PSR-4).
- Host and container paths are identical: the project lives at `/data/www/kanonmaker` on both sides, so production deployment stays an `rsync` of the same path.
- All database tables use `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
- Composer, PHPUnit and all PHP commands run **inside the container**: `sudo docker exec -w /data/www/kanonmaker kanon-www <command>`. There is no PHP binary on the host.
- Interface language of the application is Czech. Identifiers, comments and commit messages are English; user-facing strings and tag labels are Czech.
- The importer never accesses the network. Its only inputs are `data/canon-2025-2026.html` and `data/tags-2025-2026.csv`, both committed.
- Tag vocabulary — these exact codes are used everywhere:
  - group `obdobi`: `do18`, `19st`, `20_21st`
  - group `podobdobi`: `starovek`, `stredovek`, `renesance`, `baroko`, `klasicismus`
  - group `narodni`: `ceska`, `svetova`
  - group `forma`: `poezie`, `proza`, `drama`
  - group `special`: `ceska_poezie_po_1950`
- `work_tag.source` is one of `document`, `inferred`, `human`. Code must never overwrite a row whose source is `human`.
- Secrets (`startup/.env`, `config.local.php`) are never committed.

---

## File Structure

| File | Responsibility |
| --- | --- |
| `startup/docker-compose.yaml` | The two containers, `kanon-www` (fcgi on 127.0.0.1:9058) and `kanon-db` (MariaDB on 127.0.0.1:3309) |
| `startup/kanonmaker.conf` | Apache virtual hosts for both domains, kept in the repo and copied to `/etc/apache2/sites-available/` |
| `startup/.env.example` | Template for the database passwords; the real `.env` is gitignored |
| `config.php` | Reads `config.local.php`, returns the configuration array |
| `public/index.php` | Front controller for the student host |
| `admin/index.php` | Front controller for the administration host |
| `src/Support/Normalize.php` | Diacritics-insensitive normalization; used for search text and all match keys |
| `src/Db/Database.php` | PDO factory |
| `src/Db/Migrator.php` | Applies numbered SQL files, records them |
| `db/migrations/001_schema.sql` | All tables |
| `db/migrations/002_seed_gjk.sql` | School, canon, tag vocabulary, the twelve rules |
| `src/Import/DocumentParser.php` | HTML snapshot → chapters and raw entry lines |
| `src/Import/EntrySplitter.php` | One entry line → one or more `ParsedWork` (authors, title, note) |
| `src/Import/ParsedWork.php` | Value object carried between importer stages |
| `src/Import/ChapterTagger.php` | Chapter name → the tags that chapter implies |
| `src/Import/HintTagger.php` | Parenthetical note → literary form, where stated |
| `src/Import/CuratedTags.php` | Reads `data/tags-2025-2026.csv` |
| `src/Import/Importer.php` | Orchestrates the stages, upserts idempotently, returns a report |
| `src/Import/ImportReport.php` | Counters printed at the end of an import |
| `src/Rules/WorkView.php` | A work as the rules engine sees it: id, title, authors, tags |
| `src/Rules/RuleResult.php` | Outcome of one rule |
| `src/Rules/Rule.php` | Interface implemented by the four rule types |
| `src/Rules/MinTotal.php` | List length |
| `src/Rules/MinCount.php` | "at least N works tagged X" |
| `src/Rules/MinDistinct.php` | "at least N different subperiods among works tagged X" |
| `src/Rules/MaxPerAuthor.php` | "at most N titles per author, of different forms" |
| `src/Rules/RuleFactory.php` | Database row → `Rule` |
| `src/Rules/RuleSet.php` | Evaluates all rules, reports completeness |
| `bin/migrate` | Runs migrations |
| `bin/import` | Runs the import; `--dry-run` and `--scaffold-tags` modes |

---

### Task 1: Containers, virtual hosts, and a page that answers

**Files:**
- Create: `startup/docker-compose.yaml`, `startup/.env.example`, `startup/kanonmaker.conf`, `public/index.php`, `admin/index.php`
- Modify: `.gitignore`

**Interfaces:**
- Consumes: nothing.
- Produces: a running `kanon-www` container (fcgi at `127.0.0.1:9058`) and `kanon-db` container (MariaDB at `127.0.0.1:3309`, database `kanonmaker`, user `kanonmaker`); both hostnames serving PHP.

- [ ] **Step 1: Write the compose file**

Create `startup/docker-compose.yaml`:

```yaml
# Kanonmaker - lokalni vyvojove prostredi.
# Cesty jsou mapovane identicky (host == kontejner), stejne jako u ostatnich
# projektu na tomto serveru, takze nasazeni na produkci je pouhy rsync.
services:
  kanon-www:
    image: 'slimakcz/images:php83_2026_default'
    container_name: kanon-www
    networks:
      - kanon-net
    ports:
      - "127.0.0.1:9058:9000"
    restart: always
    environment:
      - php_user=1000
      - php_group=1000
      - php_rootdir=/data/www/kanonmaker
    volumes:
      - type: bind
        source: /data/www/kanonmaker
        target: /data/www/kanonmaker

  kanon-db:
    image: 'mariadb:10.11'
    container_name: kanon-db
    networks:
      - kanon-net
    ports:
      # jen pro ladeni z hostu; 3306-3308 uz zabiraji jine projekty
      - "127.0.0.1:3309:3306"
    restart: always
    environment:
      - MARIADB_ROOT_PASSWORD=${MARIADB_ROOT_PASSWORD}
      - MARIADB_DATABASE=kanonmaker
      - MARIADB_USER=kanonmaker
      - MARIADB_PASSWORD=${MARIADB_PASSWORD}
    volumes:
      - kanon-db-data:/var/lib/mysql

networks:
  kanon-net:
    name: kanon-net

volumes:
  kanon-db-data:
```

Create `startup/.env.example`:

```
MARIADB_ROOT_PASSWORD=change-me-root
MARIADB_PASSWORD=change-me-app
```

- [ ] **Step 2: Create the real .env and gitignore it**

Append to `.gitignore`:

```
/startup/.env
```

Then create the real file with generated passwords:

```bash
cd /data/www/kanonmaker/startup
printf 'MARIADB_ROOT_PASSWORD=%s\nMARIADB_PASSWORD=%s\n' \
  "$(openssl rand -base64 18 | tr -d '/+=')" \
  "$(openssl rand -base64 18 | tr -d '/+=')" > .env
chmod 600 .env
```

- [ ] **Step 3: Start the containers and verify they are up**

Run:

```bash
cd /data/www/kanonmaker/startup && sudo docker compose up -d
sudo docker ps --filter name=kanon --format '{{.Names}} {{.Status}} {{.Ports}}'
```

Expected: two lines, `kanon-www` and `kanon-db`, both `Up`, with ports `127.0.0.1:9058->9000/tcp` and `127.0.0.1:3309->3306/tcp`.

- [ ] **Step 4: Verify the database accepts the application user**

Run:

```bash
sudo docker exec kanon-db mariadb -ukanonmaker -p"$(grep MARIADB_PASSWORD= /data/www/kanonmaker/startup/.env | cut -d= -f2)" -e 'SELECT DATABASE();' kanonmaker
```

Expected: a table printing `kanonmaker`. If this fails, the container is still initialising — wait ten seconds and repeat.

- [ ] **Step 5: Write the two front controllers**

Create `public/index.php`:

```php
<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
echo "kanonmaker student ok php=" . PHP_VERSION . "\n";
```

Create `admin/index.php`:

```php
<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
echo "kanonmaker admin ok php=" . PHP_VERSION . "\n";
```

- [ ] **Step 6: Write the Apache configuration**

Create `startup/kanonmaker.conf`:

```apache
# Kanonmaker - dva virtualni hosty nad jednim kodem.
# PHP bezi v kontejneru kanon-www, cesty jsou mapovane identicky.

<VirtualHost *:80>
        ServerAdmin kataindie@gmail.com
        ServerName kata.doma.slimak.cz
        DocumentRoot /data/www/kanonmaker/public

        ErrorLog ${APACHE_LOG_DIR}/error_log.kanonmaker
        CustomLog ${APACHE_LOG_DIR}/access_log.kanonmaker combined

        DirectoryIndex index.php

        <FilesMatch \.php$>
                SetHandler "proxy:fcgi://127.0.0.1:9058"
        </FilesMatch>

        <Directory /data/www/kanonmaker/public>
                AllowOverride All
                Require all granted
        </Directory>
</VirtualHost>

<VirtualHost *:80>
        ServerAdmin kataindie@gmail.com
        ServerName kata-admin.doma.slimak.cz
        DocumentRoot /data/www/kanonmaker/admin

        ErrorLog ${APACHE_LOG_DIR}/error_log.kanonmaker-admin
        CustomLog ${APACHE_LOG_DIR}/access_log.kanonmaker-admin combined

        DirectoryIndex index.php

        <FilesMatch \.php$>
                SetHandler "proxy:fcgi://127.0.0.1:9058"
        </FilesMatch>

        <Directory /data/www/kanonmaker/admin>
                AllowOverride All
                Require all granted
        </Directory>
</VirtualHost>
```

- [ ] **Step 7: Enable the site and reload Apache**

Run:

```bash
sudo cp /data/www/kanonmaker/startup/kanonmaker.conf /etc/apache2/sites-available/kanonmaker.conf
sudo a2ensite kanonmaker
sudo apache2ctl configtest && sudo systemctl reload apache2
```

Expected: `Syntax OK`, then a silent reload.

- [ ] **Step 8: Verify both hosts answer**

Run:

```bash
curl -s http://kata.doma.slimak.cz/ ; curl -s http://kata-admin.doma.slimak.cz/
```

Expected exactly:

```
kanonmaker student ok php=8.3.25
kanonmaker admin ok php=8.3.25
```

If a host returns the default Apache page, the `ServerName` did not match — check `sudo apache2ctl -S`.

- [ ] **Step 9: Commit**

```bash
cd /data/www/kanonmaker
git add .gitignore startup/docker-compose.yaml startup/.env.example startup/kanonmaker.conf public/index.php admin/index.php
git commit -m "feat: containers, virtual hosts and health-check front controllers"
```

---

### Task 2: Composer, PHPUnit, and diacritics-insensitive normalization

**Files:**
- Create: `composer.json`, `phpunit.xml`, `src/Support/Normalize.php`, `tests/Support/NormalizeTest.php`
- Modify: `.gitignore`

**Interfaces:**
- Consumes: the `kanon-www` container from Task 1.
- Produces: `Kanon\Support\Normalize::text(string $value): string` (lowercase, diacritics stripped, whitespace collapsed) and `Kanon\Support\Normalize::key(string $value): string` (as `text`, plus punctuation removed and spaces turned into single hyphens). Every later task uses `key()` to build match keys and `text()` to build `work.search_text`.

- [ ] **Step 1: Write composer.json and phpunit.xml**

Create `composer.json`:

```json
{
    "name": "katakamm/kanonmaker",
    "description": "Nastroj pro sestaveni maturitniho seznamu cetby",
    "type": "project",
    "require": {
        "php": ">=8.3"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.5"
    },
    "autoload": {
        "psr-4": {
            "Kanon\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Kanon\\Tests\\": "tests/"
        }
    },
    "config": {
        "sort-packages": true
    }
}
```

Create `phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <testsuite name="kanonmaker">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Append to `.gitignore`:

```
/vendor/
/.phpunit.cache/
```

- [ ] **Step 2: Install dependencies**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www composer install
```

Expected: PHPUnit 11 installed, `vendor/autoload.php` created.

- [ ] **Step 3: Write the failing test**

Create `tests/Support/NormalizeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Support;

use Kanon\Support\Normalize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NormalizeTest extends TestCase
{
    public static function textCases(): array
    {
        return [
            'czech carons'      => ['Čapek', 'capek'],
            'z with caron'      => ['Žert', 'zert'],
            'sindelka'          => ['Šindelka', 'sindelka'],
            'ring and acute'    => ['Hrůzová', 'hruzova'],
            'umlaut'            => ['Brontëová', 'bronteova'],
            'keeps punctuation' => ['Neruda, Jan', 'neruda, jan'],
            'collapses spaces'  => ["Karel   Hynek\tMácha", 'karel hynek macha'],
            'trims'             => ['  Máj  ', 'maj'],
            'keeps digits'      => ['R.U.R. 1920', 'r.u.r. 1920'],
        ];
    }

    #[DataProvider('textCases')]
    public function testTextStripsDiacriticsAndLowercases(string $input, string $expected): void
    {
        self::assertSame($expected, Normalize::text($input));
    }

    public function testKeyRemovesPunctuationAndHyphenatesSpaces(): void
    {
        self::assertSame('capek-karel', Normalize::key('ČAPEK, Karel'));
        self::assertSame('valka-s-mloky', Normalize::key('Válka s mloky'));
        self::assertSame('r-u-r', Normalize::key('R.U.R.'));
    }

    public function testKeyIsStableAcrossPunctuationDifferences(): void
    {
        self::assertSame(
            Normalize::key('Kytice, uvitá ke cti'),
            Normalize::key('Kytice uvitá ke cti')
        );
    }
}
```

- [ ] **Step 4: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter NormalizeTest
```

Expected: FAIL — `Class "Kanon\Support\Normalize" not found`.

- [ ] **Step 5: Write the implementation**

Create `src/Support/Normalize.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Support;

/**
 * Diacritics-insensitive normalization.
 *
 * An explicit character map is used rather than intl's Transliterator so that
 * the output cannot change when the container's ICU version changes. Match keys
 * built by the importer must stay byte-identical across re-imports.
 */
final class Normalize
{
    private const MAP = [
        'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
        'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
        'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
        'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ô' => 'o',
        'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ÿ' => 'y', 'ł' => 'l', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'ą' => 'a',
        'ę' => 'e', 'ğ' => 'g', 'ı' => 'i', 'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
    ];

    public static function text(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, self::MAP);

        return (string) preg_replace('/\s+/u', ' ', $value);
    }

    public static function key(string $value): string
    {
        $value = self::text($value);
        $value = (string) preg_replace('/[^a-z0-9]+/u', '-', $value);

        return trim($value, '-');
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter NormalizeTest
```

Expected: PASS, 11 tests.

- [ ] **Step 7: Commit**

```bash
cd /data/www/kanonmaker
git add .gitignore composer.json composer.lock phpunit.xml src/Support/Normalize.php tests/Support/NormalizeTest.php
git commit -m "feat: composer, phpunit and diacritics-insensitive normalization"
```

---

### Task 3: Database configuration, connection, and the migration runner

**Files:**
- Create: `config.php`, `config.local.php` (not committed), `src/Db/Database.php`, `src/Db/Migrator.php`, `bin/migrate`, `db/migrations/001_schema.sql`, `tests/Db/MigratorTest.php`
- Modify: `.gitignore`

**Interfaces:**
- Consumes: `Kanon\Support\Normalize` (not directly, but the schema stores its output).
- Produces:
  - `Kanon\Db\Database::connect(array $config): \PDO` — throws `\RuntimeException` on failure.
  - `Kanon\Db\Migrator::__construct(\PDO $pdo, string $migrationsDir)`, `Migrator::pending(): string[]`, `Migrator::migrate(): string[]` returning the filenames applied.
  - Command `bin/migrate`.
  - All tables listed in the spec's data model.

- [ ] **Step 1: Write the configuration files**

Create `config.php`:

```php
<?php

declare(strict_types=1);

$local = __DIR__ . '/config.local.php';

if (!is_file($local)) {
    throw new RuntimeException('config.local.php is missing; copy it from config.local.php.example');
}

return require $local;
```

Create `config.local.php.example`:

```php
<?php

declare(strict_types=1);

return [
    'db' => [
        'host'     => 'kanon-db',
        'port'     => 3306,
        'database' => 'kanonmaker',
        'user'     => 'kanonmaker',
        'password' => 'change-me-app',
    ],
];
```

Append to `.gitignore`:

```
/config.local.php
```

Then create the real one, taking the password from the container environment file:

```bash
cd /data/www/kanonmaker
sed "s/change-me-app/$(grep MARIADB_PASSWORD= startup/.env | cut -d= -f2)/" \
  config.local.php.example > config.local.php
chmod 600 config.local.php
```

Note: the host is `kanon-db`, not `127.0.0.1` — the application connects over the container network by container name.

- [ ] **Step 2: Write the failing test**

Create `tests/Db/MigratorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Db;

use Kanon\Db\Database;
use Kanon\Db\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $config = require dirname(__DIR__, 2) . '/config.php';
        $this->pdo = Database::connect($config['db']);
    }

    public function testMigrateAppliesEveryFileAndIsIdempotent(): void
    {
        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/db/migrations');

        $migrator->migrate();

        self::assertSame([], $migrator->pending(), 'no migration should remain pending');
        self::assertSame([], $migrator->migrate(), 'a second run must apply nothing');
    }

    public function testSchemaContainsEveryExpectedTable(): void
    {
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/db/migrations'))->migrate();

        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

        foreach ([
            'school', 'canon', 'chapter', 'author', 'work', 'work_author',
            'tag', 'work_tag', 'user', 'list', 'list_item', 'rule', 'migration',
        ] as $table) {
            self::assertContains($table, $tables, "table {$table} is missing");
        }
    }

    public function testWorkMatchKeyIsUniquePerCanon(): void
    {
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/db/migrations'))->migrate();

        $indexes = $this->pdo->query("SHOW INDEX FROM work WHERE Key_name = 'uq_work_canon_key'")
            ->fetchAll(\PDO::FETCH_ASSOC);

        self::assertCount(2, $indexes, 'uq_work_canon_key must cover (canon_id, match_key)');
        self::assertSame('0', (string) $indexes[0]['Non_unique']);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter MigratorTest
```

Expected: FAIL — `Class "Kanon\Db\Database" not found`.

- [ ] **Step 4: Write the connection and migrator**

Create `src/Db/Database.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Db;

final class Database
{
    public static function connect(array $config): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            (int) $config['port'],
            $config['database']
        );

        try {
            return new \PDO($dsn, $config['user'], $config['password'], [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (\PDOException $e) {
            throw new \RuntimeException('Cannot connect to the database: ' . $e->getMessage(), 0, $e);
        }
    }
}
```

Create `src/Db/Migrator.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Db;

final class Migrator
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $migrationsDir,
    ) {
    }

    /** @return string[] filenames not yet applied, in order */
    public function pending(): array
    {
        $this->ensureMigrationTable();

        $applied = $this->pdo->query('SELECT filename FROM migration')->fetchAll(\PDO::FETCH_COLUMN);
        $files   = glob($this->migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $pending = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (!in_array($name, $applied, true)) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    /** @return string[] filenames applied by this run */
    public function migrate(): array
    {
        $applied = [];

        foreach ($this->pending() as $name) {
            $sql = file_get_contents($this->migrationsDir . '/' . $name);
            if ($sql === false) {
                throw new \RuntimeException("Cannot read migration {$name}");
            }

            $this->pdo->exec($sql);

            $stmt = $this->pdo->prepare('INSERT INTO migration (filename, applied_at) VALUES (?, NOW())');
            $stmt->execute([$name]);

            $applied[] = $name;
        }

        return $applied;
    }

    private function ensureMigrationTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migration (
                filename VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
```

- [ ] **Step 5: Write the schema migration**

Create `db/migrations/001_schema.sql`:

```sql
CREATE TABLE school (
    id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE canon (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    school_id   INT UNSIGNED NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    UNIQUE KEY uq_canon_school_year (school_id, school_year),
    CONSTRAINT fk_canon_school FOREIGN KEY (school_id) REFERENCES school (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chapter (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    canon_id   INT UNSIGNED NOT NULL,
    name       VARCHAR(255) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_chapter_canon_sort (canon_id, sort_order),
    CONSTRAINT fk_chapter_canon FOREIGN KEY (canon_id) REFERENCES canon (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE author (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    surname      VARCHAR(190) NOT NULL,
    first_name   VARCHAR(190) NULL,
    display_name VARCHAR(255) NOT NULL,
    match_key    VARCHAR(255) NOT NULL,
    UNIQUE KEY uq_author_key (match_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    canon_id    INT UNSIGNED NOT NULL,
    chapter_id  INT UNSIGNED NOT NULL,
    title       VARCHAR(500) NOT NULL,
    note        TEXT NULL,
    source_line TEXT NOT NULL,
    sort_order  INT UNSIGNED NOT NULL,
    match_key   VARCHAR(255) NOT NULL,
    search_text VARCHAR(700) NOT NULL,
    UNIQUE KEY uq_work_canon_key (canon_id, match_key),
    KEY ix_work_search (search_text(191)),
    KEY ix_work_chapter (chapter_id),
    CONSTRAINT fk_work_canon FOREIGN KEY (canon_id) REFERENCES canon (id) ON DELETE CASCADE,
    CONSTRAINT fk_work_chapter FOREIGN KEY (chapter_id) REFERENCES chapter (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work_author (
    work_id   INT UNSIGNED NOT NULL,
    author_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (work_id, author_id),
    KEY ix_work_author_author (author_id),
    CONSTRAINT fk_wa_work FOREIGN KEY (work_id) REFERENCES work (id) ON DELETE CASCADE,
    CONSTRAINT fk_wa_author FOREIGN KEY (author_id) REFERENCES author (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tag (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    canon_id   INT UNSIGNED NOT NULL,
    tag_group  VARCHAR(32) NOT NULL,
    code       VARCHAR(64) NOT NULL,
    label      VARCHAR(190) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_tag_canon_group_code (canon_id, tag_group, code),
    CONSTRAINT fk_tag_canon FOREIGN KEY (canon_id) REFERENCES canon (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work_tag (
    work_id  INT UNSIGNED NOT NULL,
    tag_id   INT UNSIGNED NOT NULL,
    source   ENUM('document','inferred','human') NOT NULL,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (work_id, tag_id),
    KEY ix_work_tag_tag (tag_id),
    CONSTRAINT fk_wt_work FOREIGN KEY (work_id) REFERENCES work (id) ON DELETE CASCADE,
    CONSTRAINT fk_wt_tag FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(190) NOT NULL,
    role          ENUM('student','admin') NOT NULL DEFAULT 'student',
    active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL,
    UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE list (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    canon_id   INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_list_user_canon (user_id, canon_id),
    CONSTRAINT fk_list_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE,
    CONSTRAINT fk_list_canon FOREIGN KEY (canon_id) REFERENCES canon (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE list_item (
    list_id  INT UNSIGNED NOT NULL,
    work_id  INT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (list_id, work_id),
    KEY ix_list_item_work (work_id),
    CONSTRAINT fk_li_list FOREIGN KEY (list_id) REFERENCES list (id) ON DELETE CASCADE,
    CONSTRAINT fk_li_work FOREIGN KEY (work_id) REFERENCES work (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rule (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    canon_id   INT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL,
    type       VARCHAR(32) NOT NULL,
    params     TEXT NOT NULL,
    label      VARCHAR(255) NOT NULL,
    enabled    TINYINT(1) NOT NULL DEFAULT 1,
    KEY ix_rule_canon (canon_id, sort_order),
    CONSTRAINT fk_rule_canon FOREIGN KEY (canon_id) REFERENCES canon (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 6: Write the migrate command**

Create `bin/migrate`:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kanon\Db\Database;
use Kanon\Db\Migrator;

$config   = require __DIR__ . '/../config.php';
$migrator = new Migrator(Database::connect($config['db']), __DIR__ . '/../db/migrations');

$applied = $migrator->migrate();

if ($applied === []) {
    echo "Nothing to migrate.\n";
    exit(0);
}

foreach ($applied as $name) {
    echo "applied {$name}\n";
}
```

Make it executable: `chmod +x bin/migrate`.

- [ ] **Step 7: Run the migration, then the test**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/migrate
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter MigratorTest
```

Expected: `applied 001_schema.sql`, then PASS, 3 tests.

- [ ] **Step 8: Commit**

```bash
cd /data/www/kanonmaker
git add .gitignore config.php config.local.php.example src/Db bin/migrate db/migrations/001_schema.sql tests/Db/MigratorTest.php
git commit -m "feat: database connection, migration runner and schema"
```

---

### Task 4: Seed the school, canon, tag vocabulary and the twelve rules

**Files:**
- Create: `db/migrations/002_seed_gjk.sql`, `tests/Db/SeedTest.php`

**Interfaces:**
- Consumes: the schema from Task 3.
- Produces: one `school` row (`Gymnázium Jana Keplera`), one `canon` row (`2025/2026`), 15 `tag` rows using the codes from Global Constraints, and 12 `rule` rows. Later tasks look the canon up by `school_year = '2025/2026'`.

- [ ] **Step 1: Write the failing test**

Create `tests/Db/SeedTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Db;

use Kanon\Db\Database;
use Kanon\Db\Migrator;
use PHPUnit\Framework\TestCase;

final class SeedTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $config    = require dirname(__DIR__, 2) . '/config.php';
        $this->pdo = Database::connect($config['db']);
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/db/migrations'))->migrate();
    }

    public function testCanonExists(): void
    {
        $row = $this->pdo->query(
            "SELECT c.id, c.school_year, s.name
             FROM canon c JOIN school s ON s.id = c.school_id
             WHERE c.school_year = '2025/2026'"
        )->fetch();

        self::assertNotFalse($row, 'canon 2025/2026 must be seeded');
        self::assertSame('Gymnázium Jana Keplera', $row['name']);
    }

    public function testTagVocabularyIsComplete(): void
    {
        $rows = $this->pdo->query('SELECT tag_group, code FROM tag')->fetchAll();
        $have = array_map(static fn (array $r): string => $r['tag_group'] . '/' . $r['code'], $rows);

        foreach ([
            'obdobi/do18', 'obdobi/19st', 'obdobi/20_21st',
            'podobdobi/starovek', 'podobdobi/stredovek', 'podobdobi/renesance',
            'podobdobi/baroko', 'podobdobi/klasicismus',
            'narodni/ceska', 'narodni/svetova',
            'forma/poezie', 'forma/proza', 'forma/drama',
            'special/ceska_poezie_po_1950',
        ] as $expected) {
            self::assertContains($expected, $have, "tag {$expected} is missing");
        }
    }

    public function testTwelveRulesAreSeededWithValidJsonParams(): void
    {
        $rules = $this->pdo->query('SELECT type, params FROM rule ORDER BY sort_order')->fetchAll();

        self::assertCount(12, $rules);

        foreach ($rules as $rule) {
            $params = json_decode($rule['params'], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($params, "params of {$rule['type']} must decode to an array");
        }

        $types = array_column($rules, 'type');
        self::assertSame(1, array_count_values($types)['min_total']);
        self::assertSame(1, array_count_values($types)['min_distinct']);
        self::assertSame(1, array_count_values($types)['max_per_author']);
        self::assertSame(9, array_count_values($types)['min_count']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter SeedTest
```

Expected: FAIL — `canon 2025/2026 must be seeded`.

- [ ] **Step 3: Write the seed migration**

Create `db/migrations/002_seed_gjk.sql`:

```sql
INSERT INTO school (name) VALUES ('Gymnázium Jana Keplera');

INSERT INTO canon (school_id, school_year)
SELECT id, '2025/2026' FROM school WHERE name = 'Gymnázium Jana Keplera';

INSERT INTO tag (canon_id, tag_group, code, label, sort_order)
SELECT c.id, t.tag_group, t.code, t.label, t.sort_order
FROM canon c
CROSS JOIN (
    SELECT 'obdobi'    AS tag_group, 'do18'       AS code, 'do konce 18. století'                  AS label, 1 AS sort_order UNION ALL
    SELECT 'obdobi',    '19st',       'preromantismus–19. století',                 2 UNION ALL
    SELECT 'obdobi',    '20_21st',    '20. a 21. století',                          3 UNION ALL
    SELECT 'podobdobi', 'starovek',   'starověk',                                   1 UNION ALL
    SELECT 'podobdobi', 'stredovek',  'středověk',                                  2 UNION ALL
    SELECT 'podobdobi', 'renesance',  'renesance',                                  3 UNION ALL
    SELECT 'podobdobi', 'baroko',     'baroko',                                     4 UNION ALL
    SELECT 'podobdobi', 'klasicismus','klasicismus a osvícenství',                  5 UNION ALL
    SELECT 'narodni',   'ceska',      'česká literatura',                           1 UNION ALL
    SELECT 'narodni',   'svetova',    'světová literatura',                         2 UNION ALL
    SELECT 'forma',     'poezie',     'poezie',                                     1 UNION ALL
    SELECT 'forma',     'proza',      'próza',                                      2 UNION ALL
    SELECT 'forma',     'drama',      'drama',                                      3 UNION ALL
    SELECT 'special',   'ceska_poezie_po_1950', 'česká poezie 2. pol. 20. st. a novější', 1
) AS t
WHERE c.school_year = '2025/2026';

INSERT INTO rule (canon_id, sort_order, type, params, label)
SELECT c.id, r.sort_order, r.type, r.params, r.label
FROM canon c
CROSS JOIN (
    SELECT 1 AS sort_order, 'min_total' AS type,
           '{"min":25}' AS params,
           'Celkem 25 titulů' AS label UNION ALL
    SELECT 2, 'min_count',
           '{"group":"obdobi","code":"do18","min":5}',
           'Literatura do konce 18. století (min. 5)' UNION ALL
    SELECT 3, 'min_distinct',
           '{"scope_group":"obdobi","scope_code":"do18","group":"podobdobi","min":3}',
           'Nejméně tři literární období do konce 18. století' UNION ALL
    SELECT 4, 'min_count',
           '{"group":"obdobi","code":"19st","min":3}',
           'Od preromantismu do konce 19. století (min. 3)' UNION ALL
    SELECT 5, 'min_count',
           '{"group":"obdobi","code":"20_21st","min":8}',
           'Literatura 20. a 21. století (min. 8)' UNION ALL
    SELECT 6, 'min_count',
           '{"group":"narodni","code":"svetova","min":8}',
           'Světová literatura (min. 8)' UNION ALL
    SELECT 7, 'min_count',
           '{"group":"narodni","code":"ceska","min":8}',
           'Česká literatura (min. 8)' UNION ALL
    SELECT 8, 'min_count',
           '{"group":"forma","code":"drama","min":3}',
           'Drama (min. 3)' UNION ALL
    SELECT 9, 'min_count',
           '{"group":"forma","code":"poezie","min":3}',
           'Poezie (min. 3)' UNION ALL
    SELECT 10, 'min_count',
           '{"group":"forma","code":"proza","min":3}',
           'Próza (min. 3)' UNION ALL
    SELECT 11, 'min_count',
           '{"group":"special","code":"ceska_poezie_po_1950","min":1}',
           'Česká poezie 2. pol. 20. století nebo 21. století (min. 1)' UNION ALL
    SELECT 12, 'max_per_author',
           '{"max":2,"distinct_form":true}',
           'Od jednoho autora nejvýše dva tituly různé literární formy'
) AS r
WHERE c.school_year = '2025/2026';
```

- [ ] **Step 4: Run the migration and the test**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/migrate
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter SeedTest
```

Expected: `applied 002_seed_gjk.sql`, then PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
cd /data/www/kanonmaker
git add db/migrations/002_seed_gjk.sql tests/Db/SeedTest.php
git commit -m "feat: seed GJK canon, tag vocabulary and the twelve selection rules"
```

---

### Task 5: Parse the document snapshot into chapters and entries

**Files:**
- Create: `src/Import/DocumentParser.php`, `tests/fixtures/canon-sample.html`, `tests/Import/DocumentParserTest.php`

**Interfaces:**
- Consumes: `Kanon\Support\Normalize::text()`.
- Produces: `Kanon\Import\DocumentParser::parse(string $html): array` returning a list of `['name' => string, 'sort_order' => int, 'entries' => string[]]`, in document order, `sort_order` starting at 1. Also the public constant `DocumentParser::CHAPTERS` (the seven heading strings), reused by `ChapterTagger` in Task 7.

- [ ] **Step 1: Create the test fixture**

Create `tests/fixtures/canon-sample.html`. This is a synthetic miniature of the real snapshot, shaped to exercise every parsing rule — it is not a faithful excerpt of the canon:

```html
<html><body>
<p class="c1">Školní kánon GJK</p>
<p class="c1">Kritéria pro výběr maturitních děl</p>
<p class="c1">Světová a česká literatura do konce 18. století (kromě preromantismu)</p>
<p class="c1">AISCHYLOS: Oresteia (trilogie)</p>
<p class="c1">Beowulf (poezie)</p>
<p class="c1">SHAKESPEARE, William: Hamlet; Král Lear; Macbeth</p>
<p class="c1">Nový zákon (minimálně: Marek + Jan + Zjevení Janovo)</p>
<p class="c1">RIMBAUD, Arthur: Sezóna v pekle + Iluminace</p>
<p class="c1">&nbsp;</p>
<p class="c1">Česká próza 20. a 21. století</p>
<p class="c1">ČAPEK, Karel: Hordubal; Krakatit; Válka s mloky </p>
<p class="c1">BABAN, Džian, MAŠEK, Vojtěch, GRUS, Jiří: Ve stínu šumavských hvozdů</p>
<p class="c1">MRŠTÍKOVÉ, Alois a Vilém: Maryša</p>
</body></html>
```

- [ ] **Step 2: Write the failing test**

Create `tests/Import/DocumentParserTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Import;

use Kanon\Import\DocumentParser;
use PHPUnit\Framework\TestCase;

final class DocumentParserTest extends TestCase
{
    private function sample(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/canon-sample.html');
    }

    public function testFindsBothChaptersInOrder(): void
    {
        $chapters = (new DocumentParser())->parse($this->sample());

        self::assertCount(2, $chapters);
        self::assertSame('Světová a česká literatura do konce 18. století (kromě preromantismu)', $chapters[0]['name']);
        self::assertSame(1, $chapters[0]['sort_order']);
        self::assertSame('Česká próza 20. a 21. století', $chapters[1]['name']);
        self::assertSame(2, $chapters[1]['sort_order']);
    }

    public function testIgnoresParagraphsBeforeTheFirstChapter(): void
    {
        $chapters = (new DocumentParser())->parse($this->sample());
        $entries  = array_merge($chapters[0]['entries'], $chapters[1]['entries']);

        self::assertNotContains('Školní kánon GJK', $entries);
        self::assertNotContains('Kritéria pro výběr maturitních děl', $entries);
    }

    public function testCollectsEntriesPerChapterAndDropsEmptyParagraphs(): void
    {
        $chapters = (new DocumentParser())->parse($this->sample());

        self::assertCount(5, $chapters[0]['entries']);
        self::assertCount(3, $chapters[1]['entries']);
        self::assertSame('Beowulf (poezie)', $chapters[0]['entries'][1]);
    }

    public function testTrimsTrailingWhitespaceAndNonBreakingSpaces(): void
    {
        $chapters = (new DocumentParser())->parse($this->sample());

        self::assertSame('ČAPEK, Karel: Hordubal; Krakatit; Válka s mloky', $chapters[1]['entries'][0]);
    }

    public function testRealSnapshotYields358Entries(): void
    {
        $html     = (string) file_get_contents(dirname(__DIR__, 2) . '/data/canon-2025-2026.html');
        $chapters = (new DocumentParser())->parse($html);

        self::assertCount(7, $chapters, 'the real snapshot has seven chapters');

        $total = array_sum(array_map(static fn (array $c): int => count($c['entries']), $chapters));
        self::assertSame(358, $total, 'the real snapshot has 358 entries');
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter DocumentParserTest
```

Expected: FAIL — `Class "Kanon\Import\DocumentParser" not found`.

- [ ] **Step 4: Write the implementation**

Create `src/Import/DocumentParser.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Import;

use Kanon\Support\Normalize;

/**
 * Turns the committed HTML export of the school canon into chapters and raw
 * entry lines.
 *
 * The HTML export is used rather than the plain-text export because the text
 * export merges adjacent paragraphs, which silently glues unrelated entries
 * together (for example Vratislav z Mitrovic and Vergilius).
 */
final class DocumentParser
{
    public const CHAPTERS = [
        'Světová a česká literatura do konce 18. století (kromě preromantismu)',
        'Světová literatura od preromantismu do konce 19. století',
        'Česká literatura od preromantismu do konce 19. století',
        'Světová a česká dramatická tvorba 20. - 21. století',
        'Světová a česká poezie 20. a 21. století',
        'Světová próza 20. a 21. století',
        'Česká próza 20. a 21. století',
    ];

    /** @return list<array{name: string, sort_order: int, entries: string[]}> */
    public function parse(string $html): array
    {
        $headings = [];
        foreach (self::CHAPTERS as $name) {
            $headings[Normalize::text($name)] = $name;
        }

        $chapters = [];
        $current  = null;

        foreach ($this->paragraphs($html) as $paragraph) {
            $key = Normalize::text($paragraph);

            if (isset($headings[$key])) {
                if ($current !== null) {
                    $chapters[] = $current;
                }
                $current = [
                    'name'       => $headings[$key],
                    'sort_order' => count($chapters) + 1,
                    'entries'    => [],
                ];
                continue;
            }

            if ($current !== null) {
                $current['entries'][] = $paragraph;
            }
        }

        if ($current !== null) {
            $chapters[] = $current;
        }

        return $chapters;
    }

    /** @return list<string> non-empty, whitespace-normalized paragraph texts */
    private function paragraphs(string $html): array
    {
        preg_match_all('#<p[^>]*>(.*?)</p>#su', $html, $matches);

        $out = [];
        foreach ($matches[1] as $raw) {
            $text = strip_tags($raw);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = str_replace("\u{00A0}", ' ', $text);
            $text = (string) preg_replace('/\s+/u', ' ', $text);
            $text = trim($text);

            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter DocumentParserTest
```

Expected: PASS, 5 tests. The last one asserts 358 entries against the real committed snapshot — if it fails, the snapshot changed and the count in the spec must be revisited before continuing.

- [ ] **Step 6: Commit**

```bash
cd /data/www/kanonmaker
git add src/Import/DocumentParser.php tests/fixtures/canon-sample.html tests/Import/DocumentParserTest.php
git commit -m "feat: parse the canon snapshot into chapters and entries"
```

---

### Task 6: Split entries into individual works, authors and notes

**Files:**
- Create: `src/Import/ParsedAuthor.php`, `src/Import/ParsedWork.php`, `src/Import/EntrySplitter.php`, `tests/Import/EntrySplitterTest.php`

**Interfaces:**
- Consumes: `Kanon\Support\Normalize::key()`.
- Produces:
  - `Kanon\Import\ParsedAuthor` with `public readonly string $surname`, `public readonly ?string $firstName`, `public readonly string $display`, and `matchKey(): string`.
  - `Kanon\Import\ParsedWork` with `public readonly array $authors` (list of `ParsedAuthor`), `public readonly string $title`, `public readonly ?string $note`, `public readonly string $sourceLine`, and `titleKey(): string`.
  - `Kanon\Import\EntrySplitter::split(string $entry): array` returning a list of `ParsedWork`.

**Documented decision:** inseparable writing duos written with a plural surname — `MRŠTÍKOVÉ, Alois a Vilém`, `STRUGAČTÍ, Arkadij a Boris` — stay a **single** author record, because they never appear apart and the two-titles-per-author rule gives the identical answer either way. Entries listing separate people with repeated `SURNAME, First` pairs — `BABAN, Džian, MAŠEK, Vojtěch, GRUS, Jiří` — split into several authors, because those people do appear separately elsewhere.

- [ ] **Step 1: Write the failing test**

Create `tests/Import/EntrySplitterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Import;

use Kanon\Import\EntrySplitter;
use PHPUnit\Framework\TestCase;

final class EntrySplitterTest extends TestCase
{
    private EntrySplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new EntrySplitter();
    }

    public function testSplitsSemicolonSeparatedTitlesIntoSeparateWorks(): void
    {
        $works = $this->splitter->split('SHAKESPEARE, William: Hamlet; Král Lear; Macbeth');

        self::assertCount(3, $works);
        self::assertSame(['Hamlet', 'Král Lear', 'Macbeth'], array_map(
            static fn ($w): string => $w->title,
            $works
        ));

        foreach ($works as $work) {
            self::assertCount(1, $work->authors);
            self::assertSame('SHAKESPEARE', $work->authors[0]->surname);
            self::assertSame('William', $work->authors[0]->firstName);
        }
    }

    public function testDoesNotSplitOnPlusBecauseThosePartsAreReadTogether(): void
    {
        $works = $this->splitter->split('RIMBAUD, Arthur: Sezóna v pekle + Iluminace');

        self::assertCount(1, $works);
        self::assertSame('Sezóna v pekle + Iluminace', $works[0]->title);
    }

    public function testExtractsTrailingParenthesisAsNote(): void
    {
        $works = $this->splitter->split('AISCHYLOS: Oresteia (trilogie)');

        self::assertSame('Oresteia', $works[0]->title);
        self::assertSame('trilogie', $works[0]->note);
        self::assertSame('AISCHYLOS', $works[0]->authors[0]->surname);
        self::assertNull($works[0]->authors[0]->firstName);
    }

    public function testEntryWithoutAuthorYieldsWorkWithNoAuthors(): void
    {
        $works = $this->splitter->split('Beowulf (poezie)');

        self::assertCount(1, $works);
        self::assertSame([], $works[0]->authors);
        self::assertSame('Beowulf', $works[0]->title);
        self::assertSame('poezie', $works[0]->note);
    }

    public function testColonInsideParenthesesIsNotAnAuthorSeparator(): void
    {
        $works = $this->splitter->split('Nový zákon (minimálně: Marek + Jan + Zjevení Janovo)');

        self::assertSame([], $works[0]->authors, 'Nový zákon has no author');
        self::assertSame('Nový zákon', $works[0]->title);
        self::assertSame('minimálně: Marek + Jan + Zjevení Janovo', $works[0]->note);
    }

    public function testRepeatedSurnameFirstNamePairsBecomeSeveralAuthors(): void
    {
        $works = $this->splitter->split('BABAN, Džian, MAŠEK, Vojtěch, GRUS, Jiří: Ve stínu šumavských hvozdů');

        self::assertCount(1, $works);
        self::assertCount(3, $works[0]->authors);
        self::assertSame(['BABAN', 'MAŠEK', 'GRUS'], array_map(
            static fn ($a): string => $a->surname,
            $works[0]->authors
        ));
    }

    public function testMissingCommaBetweenSurnameAndGivenNameStillSplits(): void
    {
        $works = $this->splitter->split('ŠINDELKA, Marek, MAŠEK Vojtěch, POKORNÝ, Marek: Svatá Barbora');

        self::assertCount(3, $works[0]->authors);
        self::assertSame('MAŠEK', $works[0]->authors[1]->surname);
        self::assertSame('Vojtěch', $works[0]->authors[1]->firstName);
    }

    public function testPluralSurnameDuoStaysOneAuthor(): void
    {
        $works = $this->splitter->split('MRŠTÍKOVÉ, Alois a Vilém: Maryša');

        self::assertCount(1, $works[0]->authors);
        self::assertSame('MRŠTÍKOVÉ', $works[0]->authors[0]->surname);
        self::assertSame('Alois a Vilém', $works[0]->authors[0]->firstName);
    }

    public function testKeepsTheOriginalLineOnEveryWorkForProvenance(): void
    {
        $entry = 'ČAPEK, Karel: Hordubal; Krakatit';
        $works = $this->splitter->split($entry);

        self::assertSame($entry, $works[0]->sourceLine);
        self::assertSame($entry, $works[1]->sourceLine);
    }

    public function testAuthorMatchKeyIsDiacriticsInsensitive(): void
    {
        $works = $this->splitter->split('ČAPEK, Karel: Krakatit');

        self::assertSame('capek-karel', $works[0]->authors[0]->matchKey());
        self::assertSame('krakatit', $works[0]->titleKey());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter EntrySplitterTest
```

Expected: FAIL — `Class "Kanon\Import\EntrySplitter" not found`.

- [ ] **Step 3: Write the value objects**

Create `src/Import/ParsedAuthor.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Import;

use Kanon\Support\Normalize;

final class ParsedAuthor
{
    public function __construct(
        public readonly string $surname,
        public readonly ?string $firstName,
        public readonly string $display,
    ) {
    }

    public function matchKey(): string
    {
        return Normalize::key($this->surname . ' ' . ($this->firstName ?? ''));
    }
}
```

Create `src/Import/ParsedWork.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Import;

use Kanon\Support\Normalize;

final class ParsedWork
{
    /** @param list<ParsedAuthor> $authors */
    public function __construct(
        public readonly array $authors,
        public readonly string $title,
        public readonly ?string $note,
        public readonly string $sourceLine,
    ) {
    }

    public function titleKey(): string
    {
        return Normalize::key($this->title);
    }

    public function authorKey(): string
    {
        if ($this->authors === []) {
            return 'anon';
        }

        return implode('+', array_map(
            static fn (ParsedAuthor $a): string => $a->matchKey(),
            $this->authors
        ));
    }

    public function displayAuthors(): string
    {
        return implode('; ', array_map(
            static fn (ParsedAuthor $a): string => $a->display,
            $this->authors
        ));
    }
}
```

- [ ] **Step 4: Write the splitter**

Create `src/Import/EntrySplitter.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Import;

/**
 * Turns one entry line of the canon into the works a student can actually pick.
 *
 * Titles separated by ";" are separate works, because the student chooses one of
 * them. Parts joined by "+" stay a single work, because the "+" means the parts
 * are read together.
 */
final class EntrySplitter
{
    /** @return list<ParsedWork> */
    public function split(string $entry): array
    {
        $entry = trim($entry);
        [$authorPart, $titlePart] = $this->splitAtTopLevelColon($entry);

        $authors = $authorPart === null ? [] : $this->splitAuthors($authorPart);

        $works = [];
        foreach ($this->splitTitles($titlePart) as $rawTitle) {
            [$title, $note] = $this->extractNote($rawTitle);

            if ($title === '') {
                continue;
            }

            $works[] = new ParsedWork($authors, $title, $note, $entry);
        }

        return $works;
    }

    /** @return array{0: ?string, 1: string} */
    private function splitAtTopLevelColon(string $entry): array
    {
        $depth  = 0;
        $length = mb_strlen($entry);

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($entry, $i, 1);

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ':' && $depth === 0) {
                return [
                    trim(mb_substr($entry, 0, $i)),
                    trim(mb_substr($entry, $i + 1)),
                ];
            }
        }

        return [null, $entry];
    }

    /** @return list<ParsedAuthor> */
    private function splitAuthors(string $part): array
    {
        $tokens  = array_map('trim', explode(',', $part));
        $authors = [];
        $current = null;

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if ($current === null || $this->startsNewAuthor($token)) {
                if ($current !== null) {
                    $authors[] = $current;
                }
                $current = $this->openAuthor($token);
                continue;
            }

            $current['first'] = $current['first'] === null
                ? $token
                : $current['first'] . ', ' . $token;
        }

        if ($current !== null) {
            $authors[] = $current;
        }

        return array_map(
            static fn (array $a): ParsedAuthor => new ParsedAuthor(
                $a['surname'],
                $a['first'],
                $a['first'] === null ? $a['surname'] : $a['surname'] . ', ' . $a['first'],
            ),
            $authors
        );
    }

    private function startsNewAuthor(string $token): bool
    {
        return preg_match('/^\p{Lu}{2,}/u', $token) === 1;
    }

    /** @return array{surname: string, first: ?string} */
    private function openAuthor(string $token): array
    {
        // "MAŠEK Vojtěch" - a surname and given name with the comma missing.
        if (preg_match('/^(\p{Lu}[\p{Lu}\s\'\x{2019}-]*\p{Lu})\s+(\p{Lu}\p{Ll}.*)$/u', $token, $m) === 1) {
            return ['surname' => trim($m[1]), 'first' => trim($m[2])];
        }

        return ['surname' => $token, 'first' => null];
    }

    /** @return list<string> */
    private function splitTitles(string $titlePart): array
    {
        $titles  = [];
        $buffer  = '';
        $depth   = 0;
        $length  = mb_strlen($titlePart);

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($titlePart, $i, 1);

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            }

            if ($char === ';' && $depth === 0) {
                $titles[] = trim($buffer);
                $buffer   = '';
                continue;
            }

            $buffer .= $char;
        }

        $titles[] = trim($buffer);

        return array_values(array_filter($titles, static fn (string $t): bool => $t !== ''));
    }

    /** @return array{0: string, 1: ?string} */
    private function extractNote(string $title): array
    {
        if (preg_match('/^(.*?)\s*\((.+)\)\s*$/u', $title, $m) === 1) {
            return [trim($m[1]), trim($m[2])];
        }

        return [trim($title), null];
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter EntrySplitterTest
```

Expected: PASS, 10 tests.

- [ ] **Step 6: Commit**

```bash
cd /data/www/kanonmaker
git add src/Import/ParsedAuthor.php src/Import/ParsedWork.php src/Import/EntrySplitter.php tests/Import/EntrySplitterTest.php
git commit -m "feat: split canon entries into individual works, authors and notes"
```

---

### Task 7: Derive tags from the chapter and from parenthetical hints

**Files:**
- Create: `src/Import/ChapterTagger.php`, `src/Import/HintTagger.php`, `tests/Import/ChapterTaggerTest.php`, `tests/Import/HintTaggerTest.php`

**Interfaces:**
- Consumes: `DocumentParser::CHAPTERS`, `Normalize::text()`.
- Produces:
  - `Kanon\Import\ChapterTagger::tagsFor(string $chapterName): array` — a list of `['group' => string, 'code' => string]`; throws `\InvalidArgumentException` for an unknown chapter.
  - `Kanon\Import\HintTagger::formFor(?string $note): ?array` — a single `['group' => 'forma', 'code' => …]` or `null`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Import/ChapterTaggerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Import;

use Kanon\Import\ChapterTagger;
use Kanon\Import\DocumentParser;
use PHPUnit\Framework\TestCase;

final class ChapterTaggerTest extends TestCase
{
    private ChapterTagger $tagger;

    protected function setUp(): void
    {
        $this->tagger = new ChapterTagger();
    }

    public function testMixedChapterGivesOnlyThePeriod(): void
    {
        $tags = $this->tagger->tagsFor('Světová a česká literatura do konce 18. století (kromě preromantismu)');

        self::assertSame([['group' => 'obdobi', 'code' => 'do18']], $tags);
    }

    public function testWorldNineteenthCenturyGivesPeriodAndNationality(): void
    {
        $tags = $this->tagger->tagsFor('Světová literatura od preromantismu do konce 19. století');

        self::assertContains(['group' => 'obdobi', 'code' => '19st'], $tags);
        self::assertContains(['group' => 'narodni', 'code' => 'svetova'], $tags);
        self::assertCount(2, $tags);
    }

    public function testCzechProseChapterGivesAllThree(): void
    {
        $tags = $this->tagger->tagsFor('Česká próza 20. a 21. století');

        self::assertContains(['group' => 'obdobi', 'code' => '20_21st'], $tags);
        self::assertContains(['group' => 'narodni', 'code' => 'ceska'], $tags);
        self::assertContains(['group' => 'forma', 'code' => 'proza'], $tags);
        self::assertCount(3, $tags);
    }

    public function testDramaChapterGivesFormButNotNationality(): void
    {
        $tags   = $this->tagger->tagsFor('Světová a česká dramatická tvorba 20. - 21. století');
        $groups = array_column($tags, 'group');

        self::assertContains('forma', $groups);
        self::assertNotContains('narodni', $groups, 'that chapter mixes Czech and world authors');
    }

    public function testEverySeededChapterIsMapped(): void
    {
        foreach (DocumentParser::CHAPTERS as $chapter) {
            self::assertNotSame([], $this->tagger->tagsFor($chapter), "unmapped chapter: {$chapter}");
        }
    }

    public function testUnknownChapterThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tagger->tagsFor('Nějaká neznámá kapitola');
    }
}
```

Create `tests/Import/HintTaggerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Import;

use Kanon\Import\HintTagger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HintTaggerTest extends TestCase
{
    public static function notes(): array
    {
        return [
            'plain poetry'        => ['poezie', 'poezie'],
            'story collection'    => ['povídkový soubor', 'proza'],
            'verse collection'    => ['veršovaný povídkový soubor', 'poezie'],
            'verse legend'        => ['česká veršovaná legenda', 'poezie'],
            'count of poems'      => ['13 básní', 'poezie'],
            'explicit drama'      => ['drama', 'drama'],
            'both parts of drama' => ['oba díly dramatu', 'drama'],
        ];
    }

    #[DataProvider('notes')]
    public function testRecognisedNotesYieldAForm(string $note, string $expectedCode): void
    {
        $tag = (new HintTagger())->formFor($note);

        self::assertNotNull($tag, "note '{$note}' should imply a form");
        self::assertSame('forma', $tag['group']);
        self::assertSame($expectedCode, $tag['code']);
    }

    public static function silentNotes(): array
    {
        return [
            'edition'   => ['EMG/Odeon, 2022'],
            'editor'    => ['ed. Jan Lehár'],
            'trilogy'   => ['trilogie'],
            'selection' => ['minimálně: Genesis + Exodus'],
        ];
    }

    #[DataProvider('silentNotes')]
    public function testUnrelatedNotesYieldNothing(string $note): void
    {
        self::assertNull((new HintTagger())->formFor($note));
    }

    public function testNullNoteYieldsNothing(): void
    {
        self::assertNull((new HintTagger())->formFor(null));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter 'ChapterTaggerTest|HintTaggerTest'
```

Expected: FAIL — `Class "Kanon\Import\ChapterTagger" not found`.

- [ ] **Step 3: Write the chapter tagger**

Create `src/Import/ChapterTagger.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Import;

use Kanon\Support\Normalize;

/**
 * The tags each chapter heading implies for every work inside it.
 *
 * Chapters 1, 4 and 5 mix Czech and world literature, and chapters 1, 2 and 3
 * mix literary forms, so those tags are deliberately absent here and come from
 * the curated file instead.
 */
final class ChapterTagger
{
    private const MAP = [
        'Světová a česká literatura do konce 18. století (kromě preromantismu)' => [
            ['group' => 'obdobi', 'code' => 'do18'],
        ],
        'Světová literatura od preromantismu do konce 19. století' => [
            ['group' => 'obdobi', 'code' => '19st'],
            ['group' => 'narodni', 'code' => 'svetova'],
        ],
        'Česká literatura od preromantismu do konce 19. století' => [
            ['group' => 'obdobi', 'code' => '19st'],
            ['group' => 'narodni', 'code' => 'ceska'],
        ],
        'Světová a česká dramatická tvorba 20. - 21. století' => [
            ['group' => 'obdobi', 'code' => '20_21st'],
            ['group' => 'forma', 'code' => 'drama'],
        ],
        'Světová a česká poezie 20. a 21. století' => [
            ['group' => 'obdobi', 'code' => '20_21st'],
            ['group' => 'forma', 'code' => 'poezie'],
        ],
        'Světová próza 20. a 21. století' => [
            ['group' => 'obdobi', 'code' => '20_21st'],
            ['group' => 'narodni', 'code' => 'svetova'],
            ['group' => 'forma', 'code' => 'proza'],
        ],
        'Česká próza 20. a 21. století' => [
            ['group' => 'obdobi', 'code' => '20_21st'],
            ['group' => 'narodni', 'code' => 'ceska'],
            ['group' => 'forma', 'code' => 'proza'],
        ],
    ];

    /** @return list<array{group: string, code: string}> */
    public function tagsFor(string $chapterName): array
    {
        foreach (self::MAP as $name => $tags) {
            if (Normalize::text($name) === Normalize::text($chapterName)) {
                return $tags;
            }
        }

        throw new \InvalidArgumentException("Unknown chapter: {$chapterName}");
    }
}
```

- [ ] **Step 4: Write the hint tagger**

Create `src/Import/HintTagger.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Import;

use Kanon\Support\Normalize;

/**
 * Reads a literary form out of the parenthetical note, where the document
 * states one. Roughly 93 of the 358 entries carry such a hint.
 *
 * Order matters: "veršovaný povídkový soubor" is poetry, not prose, so verse
 * patterns are tested before the story-collection pattern.
 */
final class HintTagger
{
    private const PATTERNS = [
        'versovan' => 'poezie',
        'basn'     => 'poezie',
        'povidkov' => 'proza',
        'dramat'   => 'drama',
        'drama'    => 'drama',
        'poezie'   => 'poezie',
        'proza'    => 'proza',
    ];

    /** @return array{group: string, code: string}|null */
    public function formFor(?string $note): ?array
    {
        if ($note === null || trim($note) === '') {
            return null;
        }

        $haystack = Normalize::text($note);

        foreach (self::PATTERNS as $needle => $code) {
            if (str_contains($haystack, $needle)) {
                return ['group' => 'forma', 'code' => $code];
            }
        }

        return null;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter 'ChapterTaggerTest|HintTaggerTest'
```

Expected: PASS, 18 tests.

- [ ] **Step 6: Commit**

```bash
cd /data/www/kanonmaker
git add src/Import/ChapterTagger.php src/Import/HintTagger.php tests/Import/ChapterTaggerTest.php tests/Import/HintTaggerTest.php
git commit -m "feat: derive tags from chapter headings and parenthetical hints"
```

---

### Task 8: Read the curated tags file

**Files:**
- Create: `src/Import/CuratedTags.php`, `tests/fixtures/tags-sample.csv`, `tests/Import/CuratedTagsTest.php`, `data/tags-2025-2026.csv`

**Interfaces:**
- Consumes: nothing beyond PHP's CSV functions.
- Produces: `Kanon\Import\CuratedTags::load(string $csvPath): array` — a map of `work_key` to a list of `['group' => string, 'code' => string]`. Rows with an empty `code` (scaffold rows not yet filled in) are skipped. Throws `\RuntimeException` if the file is missing or the header is wrong.

The CSV header is exactly `work_key,author,title,group,code`. The `author` and `title` columns exist so a human can read the file; only `work_key`, `group` and `code` are used.

- [ ] **Step 1: Create the fixture**

Create `tests/fixtures/tags-sample.csv`:

```csv
work_key,author,title,group,code
1|aischylos|oresteia,AISCHYLOS,Oresteia,narodni,svetova
1|aischylos|oresteia,AISCHYLOS,Oresteia,forma,drama
1|aischylos|oresteia,AISCHYLOS,Oresteia,podobdobi,starovek
1|anon|beowulf,,Beowulf,narodni,svetova
1|anon|beowulf,,Beowulf,podobdobi,stredovek
1|shakespeare-william|hamlet,"SHAKESPEARE, William",Hamlet,narodni,svetova
1|shakespeare-william|hamlet,"SHAKESPEARE, William",Hamlet,forma,drama
1|shakespeare-william|hamlet,"SHAKESPEARE, William",Hamlet,podobdobi,renesance
2|capek-karel|krakatit,"ČAPEK, Karel",Krakatit,,
```

The last row is a scaffold row awaiting a decision and must be ignored.

- [ ] **Step 2: Write the failing test**

Create `tests/Import/CuratedTagsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Import;

use Kanon\Import\CuratedTags;
use PHPUnit\Framework\TestCase;

final class CuratedTagsTest extends TestCase
{
    private function fixture(): string
    {
        return dirname(__DIR__) . '/fixtures/tags-sample.csv';
    }

    public function testGroupsTagsByWorkKey(): void
    {
        $tags = (new CuratedTags())->load($this->fixture());

        self::assertArrayHasKey('1|aischylos|oresteia', $tags);
        self::assertCount(3, $tags['1|aischylos|oresteia']);
        self::assertContains(['group' => 'forma', 'code' => 'drama'], $tags['1|aischylos|oresteia']);
    }

    public function testKeepsAuthorlessWorks(): void
    {
        $tags = (new CuratedTags())->load($this->fixture());

        self::assertContains(['group' => 'podobdobi', 'code' => 'stredovek'], $tags['1|anon|beowulf']);
    }

    public function testSkipsRowsWithoutACode(): void
    {
        $tags = (new CuratedTags())->load($this->fixture());

        self::assertArrayNotHasKey('2|capek-karel|krakatit', $tags, 'unfilled scaffold rows must be ignored');
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CuratedTags())->load('/nonexistent/tags.csv');
    }

    public function testWrongHeaderThrows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tags');
        file_put_contents($path, "a,b,c\n1,2,3\n");

        try {
            $this->expectException(\RuntimeException::class);
            (new CuratedTags())->load($path);
        } finally {
            unlink($path);
        }
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter CuratedTagsTest
```

Expected: FAIL — `Class "Kanon\Import\CuratedTags" not found`.

- [ ] **Step 4: Write the implementation**

Create `src/Import/CuratedTags.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Import;

/**
 * Reads the hand-curated tags that the school document does not state.
 *
 * The file is authored in the repository rather than generated at run time, so
 * that the import stays deterministic and every change to a tag is visible in
 * git history.
 */
final class CuratedTags
{
    private const HEADER = ['work_key', 'author', 'title', 'group', 'code'];

    /** @return array<string, list<array{group: string, code: string}>> */
    public function load(string $csvPath): array
    {
        if (!is_file($csvPath)) {
            throw new \RuntimeException("Curated tag file not found: {$csvPath}");
        }

        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open curated tag file: {$csvPath}");
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header !== self::HEADER) {
                throw new \RuntimeException(
                    'Curated tag file header must be: ' . implode(',', self::HEADER)
                );
            }

            $tags = [];
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }

                $key   = trim((string) ($row[0] ?? ''));
                $group = trim((string) ($row[3] ?? ''));
                $code  = trim((string) ($row[4] ?? ''));

                if ($key === '' || $group === '' || $code === '') {
                    continue;
                }

                $tags[$key][] = ['group' => $group, 'code' => $code];
            }

            return $tags;
        } finally {
            fclose($handle);
        }
    }
}
```

- [ ] **Step 5: Create the empty production tag file**

The real file is filled in after Task 9 produces its scaffold. Create it now with only the header so the importer has something to read:

```bash
cd /data/www/kanonmaker
echo 'work_key,author,title,group,code' > data/tags-2025-2026.csv
```

- [ ] **Step 6: Run the test to verify it passes**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter CuratedTagsTest
```

Expected: PASS, 5 tests.

- [ ] **Step 7: Commit**

```bash
cd /data/www/kanonmaker
git add src/Import/CuratedTags.php tests/fixtures/tags-sample.csv tests/Import/CuratedTagsTest.php data/tags-2025-2026.csv
git commit -m "feat: read the curated tag file"
```

---

### Task 9: The importer — idempotent upsert, report, and the `bin/import` command

**Files:**
- Create: `src/Import/ImportReport.php`, `src/Import/Importer.php`, `bin/import`, `tests/Import/ImporterTest.php`

**Interfaces:**
- Consumes: `DocumentParser`, `EntrySplitter`, `ChapterTagger`, `HintTagger`, `CuratedTags`, `Kanon\Db\Database`.
- Produces:
  - `Kanon\Import\ImportReport` with public int counters `entriesParsed`, `chaptersCreated`, `worksCreated`, `worksUpdated`, `worksUnchanged`, `authorsCreated`, `tagsDocument`, `tagsInferred`, `tagsSkippedHuman`, `duplicateKeys`, and `lines(): list<string>`.
  - `Kanon\Import\Importer::__construct(\PDO $pdo, DocumentParser $parser, EntrySplitter $splitter, ChapterTagger $chapterTagger, HintTagger $hintTagger, CuratedTags $curated)`.
  - `Importer::import(int $canonId, string $htmlPath, string $csvPath, bool $dryRun = false): ImportReport`.
  - `Importer::scaffoldRows(int $canonId): list<array{0:string,1:string,2:string,3:string,4:string}>` — CSV rows for works missing a required tag group.
  - Command `bin/import [--dry-run] [--scaffold-tags=PATH]`.
- **Work match key format:** `<chapter sort_order>|<author key>|<title key>`, e.g. `1|shakespeare-william|hamlet`, or `1|anon|beowulf` for authorless works. Task 8's curated CSV uses exactly these keys.

- [ ] **Step 1: Write the failing test**

Create `tests/Import/ImporterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Import;

use Kanon\Db\Database;
use Kanon\Db\Migrator;
use Kanon\Import\ChapterTagger;
use Kanon\Import\CuratedTags;
use Kanon\Import\DocumentParser;
use Kanon\Import\EntrySplitter;
use Kanon\Import\HintTagger;
use Kanon\Import\Importer;
use PHPUnit\Framework\TestCase;

final class ImporterTest extends TestCase
{
    private \PDO $pdo;
    private int $canonId;
    private string $html;
    private string $csv;

    protected function setUp(): void
    {
        $root      = dirname(__DIR__, 2);
        $config    = require $root . '/config.php';
        $this->pdo = Database::connect($config['db']);
        (new Migrator($this->pdo, $root . '/db/migrations'))->migrate();

        $this->canonId = (int) $this->pdo->query(
            "SELECT id FROM canon WHERE school_year = '2025/2026'"
        )->fetchColumn();

        $this->html = dirname(__DIR__) . '/fixtures/canon-sample.html';
        $this->csv  = dirname(__DIR__) . '/fixtures/tags-sample.csv';

        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function importer(): Importer
    {
        return new Importer(
            $this->pdo,
            new DocumentParser(),
            new EntrySplitter(),
            new ChapterTagger(),
            new HintTagger(),
            new CuratedTags(),
        );
    }

    private function workId(string $matchKey): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM work WHERE canon_id = ? AND match_key = ?');
        $stmt->execute([$this->canonId, $matchKey]);
        $id = $stmt->fetchColumn();

        self::assertNotFalse($id, "work {$matchKey} was not imported");

        return (int) $id;
    }

    /** @return list<string> "group/code" for one work */
    private function tagsOf(int $workId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.tag_group, t.code FROM work_tag wt JOIN tag t ON t.id = wt.tag_id WHERE wt.work_id = ?'
        );
        $stmt->execute([$workId]);

        return array_map(
            static fn (array $r): string => $r['tag_group'] . '/' . $r['code'],
            $stmt->fetchAll()
        );
    }

    public function testImportsEveryWorkFromTheFixture(): void
    {
        $report = $this->importer()->import($this->canonId, $this->html, $this->csv);

        self::assertSame(8, $report->entriesParsed);
        self::assertSame(12, $report->worksCreated, 'seven works in chapter one, five in chapter two');
        self::assertSame(2, $report->chaptersCreated);
    }

    public function testSemicolonEntriesBecomeSeparateWorks(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);

        foreach (['1|shakespeare-william|hamlet', '1|shakespeare-william|kral-lear', '1|shakespeare-william|macbeth'] as $key) {
            $this->workId($key);
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM work w JOIN work_author wa ON wa.work_id = w.id
             JOIN author a ON a.id = wa.author_id
             WHERE w.canon_id = ? AND a.match_key = ?'
        );
        $stmt->execute([$this->canonId, 'shakespeare-william']);

        self::assertSame(3, (int) $stmt->fetchColumn());
    }

    public function testAuthorlessWorkGetsNoAuthorAndKeepsItsHint(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);
        $id = $this->workId('1|anon|beowulf');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM work_author WHERE work_id = ?');
        $stmt->execute([$id]);
        self::assertSame(0, (int) $stmt->fetchColumn());

        self::assertContains('forma/poezie', $this->tagsOf($id), 'the (poezie) hint must become a tag');
        self::assertContains('obdobi/do18', $this->tagsOf($id), 'the chapter must give the period');
    }

    public function testCoAuthoredWorkLinksEveryAuthor(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);
        $id = $this->workId('2|baban-dzian+masek-vojtech+grus-jiri|ve-stinu-sumavskych-hvozdu');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM work_author WHERE work_id = ?');
        $stmt->execute([$id]);

        self::assertSame(3, (int) $stmt->fetchColumn());
    }

    public function testCuratedTagsAreAppliedAsInferredAndUnverified(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);
        $id = $this->workId('1|aischylos|oresteia');

        self::assertContains('podobdobi/starovek', $this->tagsOf($id));

        $stmt = $this->pdo->prepare(
            "SELECT wt.source, wt.verified FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
             WHERE wt.work_id = ? AND t.tag_group = 'podobdobi'"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        self::assertSame('inferred', $row['source']);
        self::assertSame(0, (int) $row['verified']);
    }

    public function testChapterTagsAreStoredAsDocumentAndVerified(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);
        $id = $this->workId('1|aischylos|oresteia');

        $stmt = $this->pdo->prepare(
            "SELECT wt.source, wt.verified FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
             WHERE wt.work_id = ? AND t.tag_group = 'obdobi'"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        self::assertSame('document', $row['source']);
        self::assertSame(1, (int) $row['verified']);
    }

    public function testSecondRunCreatesNothing(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);
        $second = $this->importer()->import($this->canonId, $this->html, $this->csv);

        self::assertSame(0, $second->worksCreated);
        self::assertSame(0, $second->chaptersCreated);
        self::assertSame(12, $second->worksUnchanged);
    }

    public function testHumanTagSurvivesReimport(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);
        $id = $this->workId('1|aischylos|oresteia');

        // A reviewer overrules the curated guess: not starověk but středověk.
        $this->pdo->prepare(
            "DELETE wt FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
             WHERE wt.work_id = ? AND t.tag_group = 'podobdobi'"
        )->execute([$id]);

        $tagId = (int) $this->pdo->query(
            "SELECT id FROM tag WHERE tag_group = 'podobdobi' AND code = 'stredovek' LIMIT 1"
        )->fetchColumn();

        $this->pdo->prepare(
            "INSERT INTO work_tag (work_id, tag_id, source, verified) VALUES (?, ?, 'human', 1)"
        )->execute([$id, $tagId]);

        $report = $this->importer()->import($this->canonId, $this->html, $this->csv);

        self::assertContains('podobdobi/stredovek', $this->tagsOf($id), 'the human decision must stand');
        self::assertNotContains('podobdobi/starovek', $this->tagsOf($id));
        self::assertGreaterThan(0, $report->tagsSkippedHuman);
    }

    public function testDryRunChangesNothing(): void
    {
        $report = $this->importer()->import($this->canonId, $this->html, $this->csv, true);

        self::assertSame(12, $report->worksCreated, 'the report still describes what would happen');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM work WHERE canon_id = ?');
        $stmt->execute([$this->canonId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'but nothing is written');
    }

    public function testScaffoldListsWorksMissingRequiredGroups(): void
    {
        $this->importer()->import($this->canonId, $this->html, $this->csv);
        $rows = $this->importer()->scaffoldRows($this->canonId);

        $keys = array_map(static fn (array $r): string => $r[0] . ' ' . $r[3], $rows);

        self::assertContains('1|rimbaud-arthur|sezona-v-pekle-iluminace narodni', $keys, 'Rimbaud is uncurated');
        self::assertContains('1|rimbaud-arthur|sezona-v-pekle-iluminace forma', $keys);
        self::assertNotContains('1|anon|beowulf forma', $keys, 'its form came from the (poezie) hint');
        self::assertNotContains('1|anon|beowulf narodni', $keys, 'the curated file supplied it');
        self::assertNotContains('1|aischylos|oresteia podobdobi', $keys, 'already curated');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter ImporterTest
```

Expected: FAIL — `Class "Kanon\Import\ImportReport" not found`.

- [ ] **Step 3: Write the report object**

Create `src/Import/ImportReport.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Import;

final class ImportReport
{
    public int $entriesParsed    = 0;
    public int $chaptersCreated  = 0;
    public int $worksCreated     = 0;
    public int $worksUpdated     = 0;
    public int $worksUnchanged   = 0;
    public int $authorsCreated   = 0;
    public int $tagsDocument     = 0;
    public int $tagsInferred     = 0;
    public int $tagsSkippedHuman = 0;
    public int $duplicateKeys    = 0;

    /** @return list<string> */
    public function lines(): array
    {
        return [
            sprintf('entries parsed      %d', $this->entriesParsed),
            sprintf('chapters created    %d', $this->chaptersCreated),
            sprintf('works created       %d', $this->worksCreated),
            sprintf('works updated       %d', $this->worksUpdated),
            sprintf('works unchanged     %d', $this->worksUnchanged),
            sprintf('authors created     %d', $this->authorsCreated),
            sprintf('tags from document  %d', $this->tagsDocument),
            sprintf('tags inferred       %d', $this->tagsInferred),
            sprintf('tags kept (human)   %d', $this->tagsSkippedHuman),
            sprintf('duplicate keys      %d', $this->duplicateKeys),
        ];
    }
}
```

- [ ] **Step 4: Write the importer**

Create `src/Import/Importer.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Import;

/**
 * Turns the committed snapshot plus the curated tag file into database rows.
 *
 * The import is idempotent: works are matched on a stable key, and a tag whose
 * source is "human" is never modified, so re-importing cannot undo a review.
 */
final class Importer
{
    /** Groups every work must end up with. */
    private const REQUIRED_GROUPS = ['obdobi', 'narodni', 'forma'];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly DocumentParser $parser,
        private readonly EntrySplitter $splitter,
        private readonly ChapterTagger $chapterTagger,
        private readonly HintTagger $hintTagger,
        private readonly CuratedTags $curated,
    ) {
    }

    public function import(int $canonId, string $htmlPath, string $csvPath, bool $dryRun = false): ImportReport
    {
        $html = file_get_contents($htmlPath);
        if ($html === false) {
            throw new \RuntimeException("Cannot read snapshot: {$htmlPath}");
        }

        $chapters = $this->parser->parse($html);
        $curated  = $this->curated->load($csvPath);
        $report   = new ImportReport();
        $tagIds   = $this->loadTagIds($canonId);
        $seen     = [];
        $sort     = 0;

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT kanon_import');
        }

        try {
            foreach ($chapters as $chapter) {
                $chapterId   = $this->upsertChapter($canonId, $chapter['name'], $chapter['sort_order'], $report);
                $chapterTags = $this->chapterTagger->tagsFor($chapter['name']);

                foreach ($chapter['entries'] as $entry) {
                    $report->entriesParsed++;

                    foreach ($this->splitter->split($entry) as $parsed) {
                        $sort++;
                        $key = $chapter['sort_order'] . '|' . $parsed->authorKey() . '|' . $parsed->titleKey();

                        if (isset($seen[$key])) {
                            $report->duplicateKeys++;
                            $key .= '-' . (++$seen[$key]);
                        }
                        $seen[$key] = 1;

                        $workId = $this->upsertWork($canonId, $chapterId, $key, $parsed, $sort, $report);
                        $this->syncAuthors($workId, $parsed, $report);

                        $documentTags = $chapterTags;
                        $hint         = $this->hintTagger->formFor($parsed->note);
                        if ($hint !== null && !$this->hasGroup($documentTags, 'forma')) {
                            $documentTags[] = $hint;
                        }

                        $this->syncTags($workId, $documentTags, 'document', 1, $tagIds, $report);
                        $this->syncTags($workId, $curated[$key] ?? [], 'inferred', 0, $tagIds, $report);
                    }
                }
            }

            if ($dryRun) {
                $ownsTransaction
                    ? $this->pdo->rollBack()
                    : $this->pdo->exec('ROLLBACK TO SAVEPOINT kanon_import');
            } elseif ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $report;
    }

    /** @return list<array{0:string,1:string,2:string,3:string,4:string}> */
    public function scaffoldRows(int $canonId): array
    {
        $sql = "SELECT w.id, w.match_key, w.title,
                       COALESCE(GROUP_CONCAT(DISTINCT a.display_name SEPARATOR '; '), '') AS authors
                FROM work w
                LEFT JOIN work_author wa ON wa.work_id = w.id
                LEFT JOIN author a ON a.id = wa.author_id
                WHERE w.canon_id = ?
                GROUP BY w.id, w.match_key, w.title
                ORDER BY w.sort_order";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$canonId]);
        $works = $stmt->fetchAll();

        $groupsStmt = $this->pdo->prepare(
            'SELECT t.tag_group, t.code FROM work_tag wt JOIN tag t ON t.id = wt.tag_id WHERE wt.work_id = ?'
        );

        $rows = [];
        foreach ($works as $work) {
            $groupsStmt->execute([(int) $work['id']]);
            $tags = $groupsStmt->fetchAll();

            $present = array_column($tags, 'tag_group');
            $codes   = array_column($tags, 'code');

            $required = self::REQUIRED_GROUPS;
            if (in_array('do18', $codes, true)) {
                $required[] = 'podobdobi';
            }

            foreach ($required as $group) {
                if (!in_array($group, $present, true)) {
                    $rows[] = [$work['match_key'], $work['authors'], $work['title'], $group, ''];
                }
            }
        }

        return $rows;
    }

    /** @return array<string, int> "group/code" => tag id */
    private function loadTagIds(int $canonId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, tag_group, code FROM tag WHERE canon_id = ?');
        $stmt->execute([$canonId]);

        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $ids[$row['tag_group'] . '/' . $row['code']] = (int) $row['id'];
        }

        return $ids;
    }

    private function upsertChapter(int $canonId, string $name, int $sortOrder, ImportReport $report): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM chapter WHERE canon_id = ? AND sort_order = ?');
        $stmt->execute([$canonId, $sortOrder]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            $this->pdo->prepare('UPDATE chapter SET name = ? WHERE id = ?')->execute([$name, (int) $id]);

            return (int) $id;
        }

        $this->pdo->prepare('INSERT INTO chapter (canon_id, name, sort_order) VALUES (?, ?, ?)')
            ->execute([$canonId, $name, $sortOrder]);
        $report->chaptersCreated++;

        return (int) $this->pdo->lastInsertId();
    }

    private function upsertWork(
        int $canonId,
        int $chapterId,
        string $matchKey,
        ParsedWork $parsed,
        int $sortOrder,
        ImportReport $report,
    ): int {
        $searchText = \Kanon\Support\Normalize::text($parsed->displayAuthors() . ' ' . $parsed->title);

        $stmt = $this->pdo->prepare(
            'SELECT id, chapter_id, title, note, source_line, sort_order, search_text
             FROM work WHERE canon_id = ? AND match_key = ?'
        );
        $stmt->execute([$canonId, $matchKey]);
        $existing = $stmt->fetch();

        if ($existing === false) {
            $this->pdo->prepare(
                'INSERT INTO work (canon_id, chapter_id, title, note, source_line, sort_order, match_key, search_text)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $canonId, $chapterId, $parsed->title, $parsed->note,
                $parsed->sourceLine, $sortOrder, $matchKey, $searchText,
            ]);
            $report->worksCreated++;

            return (int) $this->pdo->lastInsertId();
        }

        $changed = (int) $existing['chapter_id'] !== $chapterId
            || $existing['title'] !== $parsed->title
            || $existing['note'] !== $parsed->note
            || $existing['source_line'] !== $parsed->sourceLine
            || (int) $existing['sort_order'] !== $sortOrder
            || $existing['search_text'] !== $searchText;

        if ($changed) {
            $this->pdo->prepare(
                'UPDATE work SET chapter_id = ?, title = ?, note = ?, source_line = ?, sort_order = ?, search_text = ?
                 WHERE id = ?'
            )->execute([
                $chapterId, $parsed->title, $parsed->note, $parsed->sourceLine,
                $sortOrder, $searchText, (int) $existing['id'],
            ]);
            $report->worksUpdated++;
        } else {
            $report->worksUnchanged++;
        }

        return (int) $existing['id'];
    }

    private function syncAuthors(int $workId, ParsedWork $parsed, ImportReport $report): void
    {
        $ids = [];
        foreach ($parsed->authors as $author) {
            $stmt = $this->pdo->prepare('SELECT id FROM author WHERE match_key = ?');
            $stmt->execute([$author->matchKey()]);
            $id = $stmt->fetchColumn();

            if ($id === false) {
                $this->pdo->prepare(
                    'INSERT INTO author (surname, first_name, display_name, match_key) VALUES (?, ?, ?, ?)'
                )->execute([$author->surname, $author->firstName, $author->display, $author->matchKey()]);
                $id = $this->pdo->lastInsertId();
                $report->authorsCreated++;
            }

            $ids[] = (int) $id;
        }

        $stmt = $this->pdo->prepare('SELECT author_id FROM work_author WHERE work_id = ?');
        $stmt->execute([$workId]);
        $current = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        foreach (array_diff($current, $ids) as $stale) {
            $this->pdo->prepare('DELETE FROM work_author WHERE work_id = ? AND author_id = ?')
                ->execute([$workId, $stale]);
        }

        foreach (array_diff($ids, $current) as $new) {
            $this->pdo->prepare('INSERT INTO work_author (work_id, author_id) VALUES (?, ?)')
                ->execute([$workId, $new]);
        }
    }

    /**
     * @param list<array{group: string, code: string}> $desired
     * @param array<string, int>                       $tagIds
     */
    private function syncTags(
        int $workId,
        array $desired,
        string $source,
        int $verified,
        array $tagIds,
        ImportReport $report,
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT wt.tag_id, wt.source, t.tag_group, t.code
             FROM work_tag wt JOIN tag t ON t.id = wt.tag_id WHERE wt.work_id = ?'
        );
        $stmt->execute([$workId]);

        $existing    = [];
        $humanGroups = [];
        foreach ($stmt->fetchAll() as $row) {
            $existing[(int) $row['tag_id']] = $row;
            if ($row['source'] === 'human') {
                $humanGroups[$row['tag_group']] = true;
            }
        }

        $wantedIds = [];
        foreach ($desired as $tag) {
            $lookup = $tag['group'] . '/' . $tag['code'];
            if (!isset($tagIds[$lookup])) {
                throw new \RuntimeException("Tag {$lookup} is not seeded for this canon");
            }

            $tagId       = $tagIds[$lookup];
            $wantedIds[] = $tagId;

            if (isset($humanGroups[$tag['group']])) {
                $report->tagsSkippedHuman++;
                continue;
            }

            if (isset($existing[$tagId])) {
                if ($existing[$tagId]['source'] !== $source) {
                    $this->pdo->prepare('UPDATE work_tag SET source = ?, verified = ? WHERE work_id = ? AND tag_id = ?')
                        ->execute([$source, $verified, $workId, $tagId]);
                }
                continue;
            }

            $this->pdo->prepare(
                'INSERT INTO work_tag (work_id, tag_id, source, verified) VALUES (?, ?, ?, ?)'
            )->execute([$workId, $tagId, $source, $verified]);

            $source === 'document' ? $report->tagsDocument++ : $report->tagsInferred++;
        }

        // Remove tags this source previously wrote but no longer wants.
        foreach ($existing as $tagId => $row) {
            if ($row['source'] === $source && !in_array($tagId, $wantedIds, true)) {
                $this->pdo->prepare('DELETE FROM work_tag WHERE work_id = ? AND tag_id = ?')
                    ->execute([$workId, $tagId]);
            }
        }
    }

    /** @param list<array{group: string, code: string}> $tags */
    private function hasGroup(array $tags, string $group): bool
    {
        foreach ($tags as $tag) {
            if ($tag['group'] === $group) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter ImporterTest
```

Expected: PASS, 10 tests.

- [ ] **Step 6: Write the import command**

Create `bin/import`:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kanon\Db\Database;
use Kanon\Import\ChapterTagger;
use Kanon\Import\CuratedTags;
use Kanon\Import\DocumentParser;
use Kanon\Import\EntrySplitter;
use Kanon\Import\HintTagger;
use Kanon\Import\Importer;

$options  = getopt('', ['dry-run', 'scaffold-tags:', 'year:']);
$dryRun   = array_key_exists('dry-run', $options);
$year     = $options['year'] ?? '2025/2026';
$root     = dirname(__DIR__);
$config   = require $root . '/config.php';
$pdo      = Database::connect($config['db']);

$stmt = $pdo->prepare('SELECT id FROM canon WHERE school_year = ?');
$stmt->execute([$year]);
$canonId = $stmt->fetchColumn();

if ($canonId === false) {
    fwrite(STDERR, "No canon for school year {$year}. Run bin/migrate first.\n");
    exit(1);
}

$importer = new Importer(
    $pdo,
    new DocumentParser(),
    new EntrySplitter(),
    new ChapterTagger(),
    new HintTagger(),
    new CuratedTags(),
);

$report = $importer->import(
    (int) $canonId,
    $root . '/data/canon-2025-2026.html',
    $root . '/data/tags-2025-2026.csv',
    $dryRun,
);

echo $dryRun ? "DRY RUN - nothing was written\n\n" : "Import applied\n\n";
foreach ($report->lines() as $line) {
    echo $line . "\n";
}

if (isset($options['scaffold-tags'])) {
    if ($dryRun) {
        fwrite(STDERR, "\n--scaffold-tags needs a real import; drop --dry-run.\n");
        exit(1);
    }

    $path   = (string) $options['scaffold-tags'];
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        fwrite(STDERR, "Cannot write {$path}\n");
        exit(1);
    }

    fputcsv($handle, ['work_key', 'author', 'title', 'group', 'code'], ',', '"', '');
    $rows = $importer->scaffoldRows((int) $canonId);
    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }
    fclose($handle);

    echo "\nscaffold rows written  " . count($rows) . " -> {$path}\n";
}
```

Make it executable: `chmod +x bin/import`.

- [ ] **Step 7: Run the real import and read the report**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/import --dry-run
```

Expected: `DRY RUN - nothing was written`, `entries parsed 358`, `works created` between 440 and 470, `duplicate keys` 0 or a small number. If works created is far from ~454, stop and investigate the splitter before applying.

Then apply it:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/import
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/import --scaffold-tags=data/tags-scaffold.csv
```

Expected: the second run reports `works created 0` and writes a scaffold of roughly 250–270 rows.

- [ ] **Step 8: Commit**

```bash
cd /data/www/kanonmaker
git add src/Import/ImportReport.php src/Import/Importer.php bin/import tests/Import/ImporterTest.php
git commit -m "feat: idempotent importer with report, dry run and tag scaffold"
```

---

### Task 10: Rules engine core — WorkView, RuleResult, MinTotal, MinCount

**Files:**
- Create: `src/Rules/WorkView.php`, `src/Rules/RuleResult.php`, `src/Rules/Rule.php`, `src/Rules/MinTotal.php`, `src/Rules/MinCount.php`, `tests/Rules/MinCountTest.php`

**Interfaces:**
- Consumes: nothing — the rules engine is pure and never touches the database.
- Produces:
  - `Kanon\Rules\WorkView::__construct(int $id, string $title, array $authors, array $tags)` where `$authors` is `array<int, string>` (author id => display name) and `$tags` is `array<string, list<string>>` (group => codes); methods `hasTag(string $group, string $code): bool`, `tagCodes(string $group): array`, `form(): ?string`.
  - `Kanon\Rules\RuleResult` with readonly `label`, `satisfied`, `current`, `required`, `detail`, `fixGroup`, `fixCode`, and `missing(): int`.
  - `Kanon\Rules\Rule` interface: `evaluate(array $works): RuleResult`.
  - `Kanon\Rules\MinTotal::__construct(string $label, int $min)`.
  - `Kanon\Rules\MinCount::__construct(string $label, string $group, string $code, int $min)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Rules/MinCountTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Rules;

use Kanon\Rules\MinCount;
use Kanon\Rules\MinTotal;
use Kanon\Rules\WorkView;
use PHPUnit\Framework\TestCase;

final class MinCountTest extends TestCase
{
    /** @param array<string, list<string>> $tags */
    private function work(int $id, array $tags, array $authors = []): WorkView
    {
        return new WorkView($id, 'Dílo ' . $id, $authors, $tags);
    }

    public function testMinTotalCountsTheWholeList(): void
    {
        $rule = new MinTotal('Celkem 25 titulů', 25);

        $result = $rule->evaluate([$this->work(1, []), $this->work(2, [])]);

        self::assertFalse($result->satisfied);
        self::assertSame(2, $result->current);
        self::assertSame(25, $result->required);
        self::assertSame(23, $result->missing());
        self::assertSame('Celkem 25 titulů', $result->label);
    }

    public function testMinTotalIsSatisfiedWhenLongEnough(): void
    {
        $works = [];
        for ($i = 1; $i <= 25; $i++) {
            $works[] = $this->work($i, []);
        }

        self::assertTrue((new MinTotal('Celkem', 25))->evaluate($works)->satisfied);
    }

    public function testMinCountCountsOnlyWorksCarryingTheTag(): void
    {
        $rule = new MinCount('Poezie (min. 3)', 'forma', 'poezie', 3);

        $result = $rule->evaluate([
            $this->work(1, ['forma' => ['poezie']]),
            $this->work(2, ['forma' => ['proza']]),
            $this->work(3, ['forma' => ['poezie']]),
        ]);

        self::assertFalse($result->satisfied);
        self::assertSame(2, $result->current);
        self::assertSame(1, $result->missing());
    }

    public function testMinCountIsSatisfiedAtExactlyTheMinimum(): void
    {
        $rule = new MinCount('Drama (min. 3)', 'forma', 'drama', 3);

        $result = $rule->evaluate([
            $this->work(1, ['forma' => ['drama']]),
            $this->work(2, ['forma' => ['drama']]),
            $this->work(3, ['forma' => ['drama']]),
        ]);

        self::assertTrue($result->satisfied);
        self::assertSame(0, $result->missing());
    }

    public function testMinCountReportsTheTagThatWouldFixIt(): void
    {
        $result = (new MinCount('Česká literatura', 'narodni', 'ceska', 8))->evaluate([]);

        self::assertSame('narodni', $result->fixGroup);
        self::assertSame('ceska', $result->fixCode);
    }

    public function testOneWorkCountsTowardSeveralRules(): void
    {
        $work = $this->work(1, ['obdobi' => ['19st'], 'narodni' => ['ceska'], 'forma' => ['poezie']]);

        self::assertSame(1, (new MinCount('a', 'obdobi', '19st', 1))->evaluate([$work])->current);
        self::assertSame(1, (new MinCount('b', 'narodni', 'ceska', 1))->evaluate([$work])->current);
        self::assertSame(1, (new MinCount('c', 'forma', 'poezie', 1))->evaluate([$work])->current);
    }

    public function testWorkWithSeveralCodesInOneGroupCountsForEach(): void
    {
        $work = $this->work(1, ['forma' => ['poezie', 'drama']]);

        self::assertTrue($work->hasTag('forma', 'poezie'));
        self::assertTrue($work->hasTag('forma', 'drama'));
        self::assertSame('poezie', $work->form(), 'form() returns the first code');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter MinCountTest
```

Expected: FAIL — `Class "Kanon\Rules\MinCount" not found`.

- [ ] **Step 3: Write the value objects and the interface**

Create `src/Rules/WorkView.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Rules;

/** A work as the rules engine sees it. Deliberately free of database concerns. */
final class WorkView
{
    /**
     * @param array<int, string>          $authors author id => display name
     * @param array<string, list<string>> $tags    tag group => codes
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly array $authors,
        public readonly array $tags,
    ) {
    }

    public function hasTag(string $group, string $code): bool
    {
        return in_array($code, $this->tags[$group] ?? [], true);
    }

    /** @return list<string> */
    public function tagCodes(string $group): array
    {
        return $this->tags[$group] ?? [];
    }

    public function form(): ?string
    {
        return $this->tags['forma'][0] ?? null;
    }
}
```

Create `src/Rules/RuleResult.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Rules;

final class RuleResult
{
    public function __construct(
        public readonly string $label,
        public readonly bool $satisfied,
        public readonly int $current,
        public readonly int $required,
        public readonly ?string $detail = null,
        public readonly ?string $fixGroup = null,
        public readonly ?string $fixCode = null,
    ) {
    }

    public function missing(): int
    {
        return max(0, $this->required - $this->current);
    }
}
```

Create `src/Rules/Rule.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Rules;

interface Rule
{
    /** @param list<WorkView> $works */
    public function evaluate(array $works): RuleResult;
}
```

- [ ] **Step 4: Write the two counting rules**

Create `src/Rules/MinTotal.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Rules;

final class MinTotal implements Rule
{
    public function __construct(
        private readonly string $label,
        private readonly int $min,
    ) {
    }

    public function evaluate(array $works): RuleResult
    {
        $count = count($works);

        return new RuleResult($this->label, $count >= $this->min, $count, $this->min);
    }
}
```

Create `src/Rules/MinCount.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Rules;

final class MinCount implements Rule
{
    public function __construct(
        private readonly string $label,
        private readonly string $group,
        private readonly string $code,
        private readonly int $min,
    ) {
    }

    public function evaluate(array $works): RuleResult
    {
        $count = 0;
        foreach ($works as $work) {
            if ($work->hasTag($this->group, $this->code)) {
                $count++;
            }
        }

        return new RuleResult(
            $this->label,
            $count >= $this->min,
            $count,
            $this->min,
            null,
            $this->group,
            $this->code,
        );
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter MinCountTest
```

Expected: PASS, 7 tests.

- [ ] **Step 6: Commit**

```bash
cd /data/www/kanonmaker
git add src/Rules tests/Rules/MinCountTest.php
git commit -m "feat: rules engine core with list-length and tag-count rules"
```

---

### Task 11: The distinct-subperiod rule

**Files:**
- Create: `src/Rules/MinDistinct.php`, `tests/Rules/MinDistinctTest.php`

**Interfaces:**
- Consumes: `Kanon\Rules\WorkView`, `Rule`, `RuleResult` from Task 10.
- Produces: `Kanon\Rules\MinDistinct::__construct(string $label, string $scopeGroup, string $scopeCode, string $group, int $min)`. Its `RuleResult::$detail` is a comma-separated list of the codes already present, so the interface can tell the student which periods they have.

This rule implements the clause hidden inside criterion 1: the works from before the 19th century must come from at least three different literary periods. It is one of the two rules that cannot be checked by hand from the document alone.

- [ ] **Step 1: Write the failing test**

Create `tests/Rules/MinDistinctTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Rules;

use Kanon\Rules\MinDistinct;
use Kanon\Rules\WorkView;
use PHPUnit\Framework\TestCase;

final class MinDistinctTest extends TestCase
{
    private function rule(): MinDistinct
    {
        return new MinDistinct(
            'Nejméně tři literární období do konce 18. století',
            'obdobi',
            'do18',
            'podobdobi',
            3,
        );
    }

    private function work(int $id, string $period, ?string $subperiod): WorkView
    {
        $tags = ['obdobi' => [$period]];
        if ($subperiod !== null) {
            $tags['podobdobi'] = [$subperiod];
        }

        return new WorkView($id, 'Dílo ' . $id, [], $tags);
    }

    public function testFailsWhenFiveWorksComeFromOnlyTwoPeriods(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'do18', 'starovek'),
            $this->work(2, 'do18', 'starovek'),
            $this->work(3, 'do18', 'starovek'),
            $this->work(4, 'do18', 'baroko'),
            $this->work(5, 'do18', 'baroko'),
        ]);

        self::assertFalse($result->satisfied, 'the count is met but the spread is not');
        self::assertSame(2, $result->current);
        self::assertSame(3, $result->required);
    }

    public function testPassesWithThreeDifferentPeriods(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'do18', 'starovek'),
            $this->work(2, 'do18', 'baroko'),
            $this->work(3, 'do18', 'renesance'),
        ]);

        self::assertTrue($result->satisfied);
        self::assertSame(3, $result->current);
    }

    public function testIgnoresWorksOutsideTheScope(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'do18', 'starovek'),
            $this->work(2, '19st', 'klasicismus'),
            $this->work(3, '20_21st', 'baroko'),
        ]);

        self::assertSame(1, $result->current, 'only works tagged do18 may contribute');
    }

    public function testUntaggedWorksInScopeContributeNothing(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'do18', 'starovek'),
            $this->work(2, 'do18', null),
        ]);

        self::assertSame(1, $result->current);
    }

    public function testDetailNamesThePeriodsAlreadyPresent(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'do18', 'starovek'),
            $this->work(2, 'do18', 'baroko'),
        ]);

        self::assertNotNull($result->detail);
        self::assertStringContainsString('starovek', $result->detail);
        self::assertStringContainsString('baroko', $result->detail);
    }

    public function testEmptyListIsNotSatisfied(): void
    {
        $result = $this->rule()->evaluate([]);

        self::assertFalse($result->satisfied);
        self::assertSame(0, $result->current);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter MinDistinctTest
```

Expected: FAIL — `Class "Kanon\Rules\MinDistinct" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Rules/MinDistinct.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Rules;

/**
 * "At least N different codes of one tag group, among the works carrying a
 * scope tag."
 *
 * Serves the clause inside criterion 1: the pre-19th-century works must span at
 * least three of starověk / středověk / renesance / baroko / klasicismus.
 */
final class MinDistinct implements Rule
{
    public function __construct(
        private readonly string $label,
        private readonly string $scopeGroup,
        private readonly string $scopeCode,
        private readonly string $group,
        private readonly int $min,
    ) {
    }

    public function evaluate(array $works): RuleResult
    {
        $codes = [];

        foreach ($works as $work) {
            if (!$work->hasTag($this->scopeGroup, $this->scopeCode)) {
                continue;
            }

            foreach ($work->tagCodes($this->group) as $code) {
                $codes[$code] = true;
            }
        }

        $present = array_keys($codes);
        sort($present);
        $count = count($present);

        return new RuleResult(
            $this->label,
            $count >= $this->min,
            $count,
            $this->min,
            $present === [] ? null : implode(', ', $present),
            $this->group,
            null,
        );
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter MinDistinctTest
```

Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
cd /data/www/kanonmaker
git add src/Rules/MinDistinct.php tests/Rules/MinDistinctTest.php
git commit -m "feat: distinct-subperiod rule for criterion 1"
```

---

### Task 12: The two-titles-per-author rule

**Files:**
- Create: `src/Rules/MaxPerAuthor.php`, `tests/Rules/MaxPerAuthorTest.php`

**Interfaces:**
- Consumes: `WorkView`, `Rule`, `RuleResult`.
- Produces: `Kanon\Rules\MaxPerAuthor::__construct(string $label, int $max, bool $distinctForm)`. `RuleResult::$current` is the largest number of titles any single author has on the list; `$detail` is a Czech sentence naming the offending authors.

This is the rule students most often get wrong by hand: two titles by one author are allowed only if they are of **different** literary forms, so two Shakespeare plays break it while *Hamlet* plus *Sonety* do not.

- [ ] **Step 1: Write the failing test**

Create `tests/Rules/MaxPerAuthorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Rules;

use Kanon\Rules\MaxPerAuthor;
use Kanon\Rules\WorkView;
use PHPUnit\Framework\TestCase;

final class MaxPerAuthorTest extends TestCase
{
    private function rule(): MaxPerAuthor
    {
        return new MaxPerAuthor('Od jednoho autora nejvýše dva tituly různé literární formy', 2, true);
    }

    /** @param array<int, string> $authors */
    private function work(int $id, string $title, array $authors, ?string $form): WorkView
    {
        return new WorkView($id, $title, $authors, $form === null ? [] : ['forma' => [$form]]);
    }

    public function testTwoDramasByOneAuthorFail(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'Hamlet', [7 => 'SHAKESPEARE, William'], 'drama'),
            $this->work(2, 'Macbeth', [7 => 'SHAKESPEARE, William'], 'drama'),
        ]);

        self::assertFalse($result->satisfied);
        self::assertNotNull($result->detail);
        self::assertStringContainsString('SHAKESPEARE, William', $result->detail);
    }

    public function testADramaAndAPoemByOneAuthorPass(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'Hamlet', [7 => 'SHAKESPEARE, William'], 'drama'),
            $this->work(2, 'Sonety', [7 => 'SHAKESPEARE, William'], 'poezie'),
        ]);

        self::assertTrue($result->satisfied);
    }

    public function testThreeTitlesByOneAuthorFailEvenWithDifferentForms(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'Hordubal', [3 => 'ČAPEK, Karel'], 'proza'),
            $this->work(2, 'R.U.R.', [3 => 'ČAPEK, Karel'], 'drama'),
            $this->work(3, 'Básně', [3 => 'ČAPEK, Karel'], 'poezie'),
        ]);

        self::assertFalse($result->satisfied);
        self::assertSame(3, $result->current);
        self::assertSame(2, $result->required);
    }

    public function testDifferentAuthorsWithTheSameFormAreFine(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'Hamlet', [7 => 'SHAKESPEARE, William'], 'drama'),
            $this->work(2, 'Nora', [9 => 'IBSEN, Henrik'], 'drama'),
        ]);

        self::assertTrue($result->satisfied);
    }

    public function testCoAuthoredWorkCountsForEveryAuthor(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'Zůstaňte s námi', [11 => 'ŠINDELKA, Marek'], 'proza'),
            $this->work(2, 'Svatá Barbora', [11 => 'ŠINDELKA, Marek', 12 => 'MAŠEK, Vojtěch'], 'proza'),
        ]);

        self::assertFalse($result->satisfied, 'two prose titles involve Šindelka');
        self::assertStringContainsString('ŠINDELKA, Marek', (string) $result->detail);
    }

    public function testAuthorlessWorksAreIgnored(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'Beowulf', [], 'poezie'),
            $this->work(2, 'Edda', [], 'poezie'),
            $this->work(3, 'Epos o Gilgamešovi', [], 'poezie'),
        ]);

        self::assertTrue($result->satisfied);
    }

    public function testUntaggedFormsDoNotTriggerAFalseViolation(): void
    {
        $result = $this->rule()->evaluate([
            $this->work(1, 'Dílo A', [5 => 'NOVÁK, Jan'], null),
            $this->work(2, 'Dílo B', [5 => 'NOVÁK, Jan'], null),
        ]);

        self::assertTrue($result->satisfied, 'without forms the rule cannot claim a violation');
    }

    public function testEmptyListPasses(): void
    {
        self::assertTrue($this->rule()->evaluate([])->satisfied);
    }

    public function testWithoutDistinctFormOnlyTheCountMatters(): void
    {
        $rule = new MaxPerAuthor('Nejvýše dva tituly od autora', 2, false);

        $result = $rule->evaluate([
            $this->work(1, 'Hamlet', [7 => 'SHAKESPEARE, William'], 'drama'),
            $this->work(2, 'Macbeth', [7 => 'SHAKESPEARE, William'], 'drama'),
        ]);

        self::assertTrue($result->satisfied);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter MaxPerAuthorTest
```

Expected: FAIL — `Class "Kanon\Rules\MaxPerAuthor" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Rules/MaxPerAuthor.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Rules;

/**
 * "At most N titles by one author, and they must be of different literary
 * forms."
 *
 * Works whose form is still untagged cannot produce a violation of the
 * different-forms clause: the rule refuses to accuse on missing data, since a
 * false accusation would send a student hunting for a problem that is not there.
 */
final class MaxPerAuthor implements Rule
{
    public function __construct(
        private readonly string $label,
        private readonly int $max,
        private readonly bool $distinctForm,
    ) {
    }

    public function evaluate(array $works): RuleResult
    {
        /** @var array<int, array{name: string, works: list<WorkView>}> $byAuthor */
        $byAuthor = [];

        foreach ($works as $work) {
            foreach ($work->authors as $authorId => $authorName) {
                $byAuthor[$authorId] ??= ['name' => $authorName, 'works' => []];
                $byAuthor[$authorId]['works'][] = $work;
            }
        }

        $worst      = 0;
        $violations = [];

        foreach ($byAuthor as $author) {
            $count = count($author['works']);
            $worst = max($worst, $count);

            if ($count > $this->max) {
                $violations[] = sprintf('%s: %d tituly (nejvýše %d)', $author['name'], $count, $this->max);
                continue;
            }

            if (!$this->distinctForm || $count < 2) {
                continue;
            }

            $forms = [];
            foreach ($author['works'] as $work) {
                $form = $work->form();
                if ($form !== null) {
                    $forms[] = $form;
                }
            }

            if (count($forms) === $count && count(array_unique($forms)) < $count) {
                $violations[] = sprintf(
                    '%s: %d tituly stejné literární formy (%s)',
                    $author['name'],
                    $count,
                    implode(', ', array_unique($forms))
                );
            }
        }

        return new RuleResult(
            $this->label,
            $violations === [],
            $worst,
            $this->max,
            $violations === [] ? null : implode('; ', $violations),
        );
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter MaxPerAuthorTest
```

Expected: PASS, 9 tests.

- [ ] **Step 5: Commit**

```bash
cd /data/www/kanonmaker
git add src/Rules/MaxPerAuthor.php tests/Rules/MaxPerAuthorTest.php
git commit -m "feat: two-titles-per-author rule with the different-forms clause"
```

---

### Task 13: Load rules from the database and check a list from the command line

**Files:**
- Create: `src/Rules/RuleFactory.php`, `src/Rules/RuleSet.php`, `src/Rules/WorkViewLoader.php`, `bin/check-list`, `tests/Rules/RuleFactoryTest.php`, `tests/Rules/RuleSetTest.php`

**Interfaces:**
- Consumes: `MinTotal`, `MinCount`, `MinDistinct`, `MaxPerAuthor`, `WorkView`, `Kanon\Db\Database`.
- Produces:
  - `Kanon\Rules\RuleFactory::fromRow(array $row): Rule` — `$row` has keys `type`, `params` (JSON string) and `label`; throws `\InvalidArgumentException` on an unknown type and `\JsonException` on malformed params.
  - `Kanon\Rules\RuleSet::__construct(array $rules)`, `RuleSet::fromCanon(\PDO $pdo, int $canonId): self`, `evaluate(array $works): list<RuleResult>`, `isComplete(array $works): bool`.
  - `Kanon\Rules\WorkViewLoader::__construct(\PDO $pdo)`, `load(array $workIds): list<WorkView>`, `loadByMatchKeys(int $canonId, array $matchKeys): list<WorkView>`.
  - Command `bin/check-list`, reading work match keys from standard input, one per line.

- [ ] **Step 1: Write the failing tests**

Create `tests/Rules/RuleFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Rules;

use Kanon\Rules\MaxPerAuthor;
use Kanon\Rules\MinCount;
use Kanon\Rules\MinDistinct;
use Kanon\Rules\MinTotal;
use Kanon\Rules\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RuleFactoryTest extends TestCase
{
    public function testBuildsEveryRuleType(): void
    {
        self::assertInstanceOf(MinTotal::class, RuleFactory::fromRow([
            'type' => 'min_total', 'params' => '{"min":25}', 'label' => 'Celkem',
        ]));

        self::assertInstanceOf(MinCount::class, RuleFactory::fromRow([
            'type'   => 'min_count',
            'params' => '{"group":"forma","code":"drama","min":3}',
            'label'  => 'Drama',
        ]));

        self::assertInstanceOf(MinDistinct::class, RuleFactory::fromRow([
            'type'   => 'min_distinct',
            'params' => '{"scope_group":"obdobi","scope_code":"do18","group":"podobdobi","min":3}',
            'label'  => 'Období',
        ]));

        self::assertInstanceOf(MaxPerAuthor::class, RuleFactory::fromRow([
            'type'   => 'max_per_author',
            'params' => '{"max":2,"distinct_form":true}',
            'label'  => 'Autor',
        ]));
    }

    public function testBuiltRuleBehavesAsConfigured(): void
    {
        $rule   = RuleFactory::fromRow([
            'type'   => 'min_count',
            'params' => '{"group":"forma","code":"drama","min":3}',
            'label'  => 'Drama (min. 3)',
        ]);
        $result = $rule->evaluate([]);

        self::assertSame('Drama (min. 3)', $result->label);
        self::assertSame(3, $result->required);
        self::assertSame('drama', $result->fixCode);
    }

    public function testUnknownTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RuleFactory::fromRow(['type' => 'min_vibes', 'params' => '{}', 'label' => 'x']);
    }

    public function testMalformedParamsThrow(): void
    {
        $this->expectException(\JsonException::class);
        RuleFactory::fromRow(['type' => 'min_total', 'params' => 'not json', 'label' => 'x']);
    }
}
```

Create `tests/Rules/RuleSetTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Rules;

use Kanon\Db\Database;
use Kanon\Db\Migrator;
use Kanon\Rules\RuleSet;
use Kanon\Rules\WorkView;
use PHPUnit\Framework\TestCase;

final class RuleSetTest extends TestCase
{
    private \PDO $pdo;
    private int $canonId;

    protected function setUp(): void
    {
        $root      = dirname(__DIR__, 2);
        $config    = require $root . '/config.php';
        $this->pdo = Database::connect($config['db']);
        (new Migrator($this->pdo, $root . '/db/migrations'))->migrate();

        $this->canonId = (int) $this->pdo->query(
            "SELECT id FROM canon WHERE school_year = '2025/2026'"
        )->fetchColumn();
    }

    /** A list that satisfies every one of the school's criteria. */
    private function completeList(): array
    {
        $subperiods = ['starovek', 'stredovek', 'renesance', 'baroko', 'klasicismus'];
        $forms      = ['poezie', 'proza', 'drama'];
        $works      = [];

        for ($i = 1; $i <= 25; $i++) {
            $tags = [
                'obdobi'  => [$i <= 5 ? 'do18' : ($i <= 8 ? '19st' : '20_21st')],
                'narodni' => [$i % 2 === 0 ? 'ceska' : 'svetova'],
                'forma'   => [$forms[$i % 3]],
            ];

            if ($i <= 5) {
                $tags['podobdobi'] = [$subperiods[$i - 1]];
            }

            if ($i === 9) {
                $tags['special'] = ['ceska_poezie_po_1950'];
            }

            $works[] = new WorkView($i, 'Dílo ' . $i, [$i => 'AUTOR ' . $i], $tags);
        }

        return $works;
    }

    public function testTwelveRulesAreLoadedFromTheCanon(): void
    {
        $results = RuleSet::fromCanon($this->pdo, $this->canonId)->evaluate([]);

        self::assertCount(12, $results);
    }

    public function testEmptyListFailsTheCountingRulesButNotTheAuthorRule(): void
    {
        $results = RuleSet::fromCanon($this->pdo, $this->canonId)->evaluate([]);

        $byLabel = [];
        foreach ($results as $result) {
            $byLabel[$result->label] = $result;
        }

        self::assertFalse($byLabel['Celkem 25 titulů']->satisfied);
        self::assertTrue(
            $byLabel['Od jednoho autora nejvýše dva tituly různé literární formy']->satisfied,
            'an empty list cannot break the author rule'
        );
    }

    public function testACorrectlyBuiltListSatisfiesEveryRule(): void
    {
        $ruleSet = RuleSet::fromCanon($this->pdo, $this->canonId);
        $results = $ruleSet->evaluate($this->completeList());

        foreach ($results as $result) {
            self::assertTrue(
                $result->satisfied,
                sprintf('rule "%s" failed: %d/%d %s', $result->label, $result->current, $result->required, (string) $result->detail)
            );
        }

        self::assertTrue($ruleSet->isComplete($this->completeList()));
    }

    public function testRemovingOneWorkBreaksExactlyTheExpectedRules(): void
    {
        $works = $this->completeList();
        array_pop($works); // 24 works left

        $results = RuleSet::fromCanon($this->pdo, $this->canonId)->evaluate($works);

        $failed = array_values(array_filter($results, static fn ($r): bool => !$r->satisfied));

        self::assertNotSame([], $failed);
        self::assertContains('Celkem 25 titulů', array_map(static fn ($r): string => $r->label, $failed));
    }

    public function testTwoWorksOfTheSameFormByOneAuthorBreakTheAuthorRule(): void
    {
        $works    = $this->completeList();
        $works[1] = new WorkView(
            $works[1]->id,
            $works[1]->title,
            [1 => 'AUTOR 1'],
            ['obdobi' => ['do18'], 'narodni' => ['ceska'], 'forma' => [$works[0]->form()], 'podobdobi' => ['stredovek']],
        );

        self::assertFalse(RuleSet::fromCanon($this->pdo, $this->canonId)->isComplete($works));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter 'RuleFactoryTest|RuleSetTest'
```

Expected: FAIL — `Class "Kanon\Rules\RuleFactory" not found`.

- [ ] **Step 3: Write the factory**

Create `src/Rules/RuleFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Rules;

/**
 * Builds a Rule from a database row.
 *
 * Rule types live in code and only their parameters are editable, so an
 * administrative typo cannot produce a rule the engine is unable to evaluate.
 */
final class RuleFactory
{
    /** @param array{type: string, params: string, label: string} $row */
    public static function fromRow(array $row): Rule
    {
        $params = json_decode($row['params'], true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($params)) {
            throw new \InvalidArgumentException("Rule params must be a JSON object: {$row['params']}");
        }

        return match ($row['type']) {
            'min_total' => new MinTotal(
                $row['label'],
                (int) $params['min'],
            ),
            'min_count' => new MinCount(
                $row['label'],
                (string) $params['group'],
                (string) $params['code'],
                (int) $params['min'],
            ),
            'min_distinct' => new MinDistinct(
                $row['label'],
                (string) $params['scope_group'],
                (string) $params['scope_code'],
                (string) $params['group'],
                (int) $params['min'],
            ),
            'max_per_author' => new MaxPerAuthor(
                $row['label'],
                (int) $params['max'],
                (bool) ($params['distinct_form'] ?? false),
            ),
            default => throw new \InvalidArgumentException("Unknown rule type: {$row['type']}"),
        };
    }
}
```

- [ ] **Step 4: Write the rule set**

Create `src/Rules/RuleSet.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Rules;

final class RuleSet
{
    /** @param list<Rule> $rules */
    public function __construct(private readonly array $rules)
    {
    }

    public static function fromCanon(\PDO $pdo, int $canonId): self
    {
        $stmt = $pdo->prepare(
            'SELECT type, params, label FROM rule WHERE canon_id = ? AND enabled = 1 ORDER BY sort_order'
        );
        $stmt->execute([$canonId]);

        return new self(array_map(
            static fn (array $row): Rule => RuleFactory::fromRow($row),
            $stmt->fetchAll()
        ));
    }

    /**
     * @param  list<WorkView> $works
     * @return list<RuleResult>
     */
    public function evaluate(array $works): array
    {
        return array_map(
            static fn (Rule $rule): RuleResult => $rule->evaluate($works),
            $this->rules
        );
    }

    /** @param list<WorkView> $works */
    public function isComplete(array $works): bool
    {
        foreach ($this->evaluate($works) as $result) {
            if (!$result->satisfied) {
                return false;
            }
        }

        return true;
    }
}
```

- [ ] **Step 5: Write the loader**

Create `src/Rules/WorkViewLoader.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Rules;

final class WorkViewLoader
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @param  list<int> $workIds
     * @return list<WorkView> in the order the ids were given
     */
    public function load(array $workIds): array
    {
        if ($workIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($workIds), '?'));

        $stmt = $this->pdo->prepare("SELECT id, title FROM work WHERE id IN ({$placeholders})");
        $stmt->execute($workIds);
        $titles = [];
        foreach ($stmt->fetchAll() as $row) {
            $titles[(int) $row['id']] = $row['title'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT wa.work_id, a.id AS author_id, a.display_name
             FROM work_author wa JOIN author a ON a.id = wa.author_id
             WHERE wa.work_id IN ({$placeholders})"
        );
        $stmt->execute($workIds);
        $authors = [];
        foreach ($stmt->fetchAll() as $row) {
            $authors[(int) $row['work_id']][(int) $row['author_id']] = $row['display_name'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT wt.work_id, t.tag_group, t.code
             FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
             WHERE wt.work_id IN ({$placeholders})
             ORDER BY t.tag_group, t.sort_order"
        );
        $stmt->execute($workIds);
        $tags = [];
        foreach ($stmt->fetchAll() as $row) {
            $tags[(int) $row['work_id']][$row['tag_group']][] = $row['code'];
        }

        $views = [];
        foreach ($workIds as $id) {
            if (!isset($titles[$id])) {
                continue;
            }

            $views[] = new WorkView($id, $titles[$id], $authors[$id] ?? [], $tags[$id] ?? []);
        }

        return $views;
    }

    /**
     * @param  list<string> $matchKeys
     * @return list<WorkView>
     */
    public function loadByMatchKeys(int $canonId, array $matchKeys): array
    {
        if ($matchKeys === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($matchKeys), '?'));
        $stmt         = $this->pdo->prepare(
            "SELECT id, match_key FROM work WHERE canon_id = ? AND match_key IN ({$placeholders})"
        );
        $stmt->execute([$canonId, ...$matchKeys]);

        $idByKey = [];
        foreach ($stmt->fetchAll() as $row) {
            $idByKey[$row['match_key']] = (int) $row['id'];
        }

        $ids = [];
        foreach ($matchKeys as $key) {
            if (isset($idByKey[$key])) {
                $ids[] = $idByKey[$key];
            }
        }

        return $this->load($ids);
    }
}
```

- [ ] **Step 6: Write the check command**

Create `bin/check-list`:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kanon\Db\Database;
use Kanon\Rules\RuleSet;
use Kanon\Rules\WorkViewLoader;

$options = getopt('', ['year:']);
$year    = $options['year'] ?? '2025/2026';
$config  = require __DIR__ . '/../config.php';
$pdo     = Database::connect($config['db']);

$stmt = $pdo->prepare('SELECT id FROM canon WHERE school_year = ?');
$stmt->execute([$year]);
$canonId = $stmt->fetchColumn();

if ($canonId === false) {
    fwrite(STDERR, "No canon for school year {$year}.\n");
    exit(1);
}

$keys = [];
while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line !== '') {
        $keys[] = $line;
    }
}

$works = (new WorkViewLoader($pdo))->loadByMatchKeys((int) $canonId, $keys);

if (count($works) !== count($keys)) {
    fwrite(STDERR, sprintf("Warning: %d of %d keys matched a work.\n\n", count($works), count($keys)));
}

$ruleSet = RuleSet::fromCanon($pdo, (int) $canonId);

foreach ($ruleSet->evaluate($works) as $result) {
    printf(
        "%s %-62s %2d/%-2d %s\n",
        $result->satisfied ? 'OK ' : '!! ',
        $result->label,
        $result->current,
        $result->required,
        (string) $result->detail
    );
}

echo "\n", $ruleSet->isComplete($works) ? "Seznam splňuje všechna pravidla.\n" : "Seznam zatím nesplňuje všechna pravidla.\n";
```

Make it executable: `chmod +x bin/check-list`.

- [ ] **Step 7: Run the tests to verify they pass**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit --filter 'RuleFactoryTest|RuleSetTest'
```

Expected: PASS, 9 tests.

- [ ] **Step 8: Try the command against real data**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www sh -c \
  "printf '1|homer|odysseia\n1|sofokles|antigona\n' | php bin/check-list"
```

Expected: twelve rule lines, most marked `!!`, ending with `Seznam zatím nesplňuje všechna pravidla.` Tags will be sparse until Task 14 fills the curated file.

- [ ] **Step 9: Commit**

```bash
cd /data/www/kanonmaker
git add src/Rules/RuleFactory.php src/Rules/RuleSet.php src/Rules/WorkViewLoader.php bin/check-list tests/Rules/RuleFactoryTest.php tests/Rules/RuleSetTest.php
git commit -m "feat: load rules from the canon and check a list from the command line"
```

---

### Task 14: Fill the curated tag file and prove the canon is fully tagged

**Files:**
- Modify: `data/tags-2025-2026.csv`
- Create: `tests/Import/CanonCoverageTest.php`

**Interfaces:**
- Consumes: `Importer::scaffoldRows()` from Task 9, the tag vocabulary from Task 4.
- Produces: a `data/tags-2025-2026.csv` in which every scaffold row has a code, and a regression test proving no work in the canon is missing a required tag.

This is data authorship, not programming: roughly 260 decisions, each recorded as one CSV row. The scaffold from Task 9 provides the rows; this task fills in the `code` column.

- [ ] **Step 1: Regenerate the scaffold from a clean import**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/import --scaffold-tags=data/tags-scaffold.csv
wc -l /data/www/kanonmaker/data/tags-scaffold.csv
```

Expected: roughly 250–270 rows plus a header.

- [ ] **Step 2: Fill in every code, working chapter by chapter**

Append the filled rows to `data/tags-2025-2026.csv` (keeping its existing header line). Decide each code by these rules, in this order:

1. **`narodni`** — which literature the work belongs to, judged by the language it was written in, not the author's citizenship. Mácha, Komenský, Bridel, Kosmas and Havlíček Borovský are `ceska`; Aischylos, Dante, Shakespeare and Voltaire are `svetova`. Kafka, who wrote in German, is `svetova`. Anonymous Czech works (`Kristiánova legenda`, `Legenda o svaté Kateřině`, `Rukopisy královédvorský a zelenohorský`, `Česká středověká lyrika`) are `ceska`; `Beowulf`, `Edda`, `Epos o Gilgamešovi`, `Píseň o Rolandovi`, `Píseň o Cidovi`, `Píseň o Nibelunzích` are `svetova`.
2. **`forma`** — `drama` for stage plays, `poezie` for verse (including verse epics such as `Odysseia`, `Aeneis`, `Máj`, and for poetry collections), `proza` for everything else, including essays, chronicles and travel writing (`Eseje`, `Kronika česká`, `Milion`, `Labyrint světa a ráj srdce`).
3. **`podobdobi`** — for works in chapter 1 only, from when the work was written, not when its author was born: `starovek` up to roughly the 5th century (Homér, Aischylos, Ovidius, Starý zákon, Nový zákon, Epos o Gilgamešovi); `stredovek` roughly 500–1400 (Beowulf, Edda, Píseň o Rolandovi, Kosmas, Dante, Kristiánova legenda); `renesance` roughly 1400–1600 (Boccaccio, Chaucer, Cervantes, Shakespeare, Montaigne, More, Rabelais); `baroko` roughly 1600–1700 (Bridel, Komenský, Calderón, Grimmelshausen, Milton); `klasicismus` roughly 1700–1800 (Molière, Voltaire, Diderot, Defoe, Swift, Sterne, Laclos, Goldoni).
4. **`special`** — add `ceska_poezie_po_1950` to Czech poetry collections first published in the second half of the 20th century or later. This tag is not in the scaffold (it is additive, not a missing group), so add these rows by hand. Candidates in chapter 5 include Holan *Noc s Hamletem*, Skácel, Šiktanc, Hrabě, Jirous, Wernisch, Hruška, Borkovec, Děžinský, Stančáková, Novotný *Dědek*, Kolmačka, Grögrová, Antošová, Doležal, Fischerová, Hejda.

Where a work genuinely resists a single answer, leave the row's `code` empty and note it — the administration's review queue in plan 3 exists precisely for those, and an empty code is honest where a guess would not be.

- [ ] **Step 3: Re-import and check the numbers**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/import
```

Expected: `works created 0`, `works unchanged` around 454, `tags inferred` around 260.

- [ ] **Step 4: Write the coverage test**

Create `tests/Import/CanonCoverageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Kanon\Tests\Import;

use Kanon\Db\Database;
use PHPUnit\Framework\TestCase;

/**
 * Guards the imported data rather than the code: every work must carry the tags
 * the school's criteria need, otherwise the rule check silently under-counts.
 */
final class CanonCoverageTest extends TestCase
{
    private \PDO $pdo;
    private int $canonId;

    protected function setUp(): void
    {
        $config        = require dirname(__DIR__, 2) . '/config.php';
        $this->pdo     = Database::connect($config['db']);
        $this->canonId = (int) $this->pdo->query(
            "SELECT id FROM canon WHERE school_year = '2025/2026'"
        )->fetchColumn();
    }

    /** @return list<string> titles of works missing a tag in the group */
    private function missing(string $group, ?string $scopeCode = null): array
    {
        $sql = "SELECT w.title FROM work w
                WHERE w.canon_id = :canon
                  AND NOT EXISTS (
                      SELECT 1 FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
                      WHERE wt.work_id = w.id AND t.tag_group = :grp
                  )";

        if ($scopeCode !== null) {
            $sql .= " AND EXISTS (
                          SELECT 1 FROM work_tag wt2 JOIN tag t2 ON t2.id = wt2.tag_id
                          WHERE wt2.work_id = w.id AND t2.code = :scope
                      )";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':canon', $this->canonId, \PDO::PARAM_INT);
        $stmt->bindValue(':grp', $group);
        if ($scopeCode !== null) {
            $stmt->bindValue(':scope', $scopeCode);
        }
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testTheCanonWasImported(): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM work WHERE canon_id = ?');
        $stmt->execute([$this->canonId]);

        self::assertGreaterThan(440, (int) $stmt->fetchColumn(), 'run bin/import first');
    }

    public function testEveryWorkHasAPeriod(): void
    {
        self::assertSame([], $this->missing('obdobi'));
    }

    public function testEveryWorkHasANationality(): void
    {
        self::assertSame([], $this->missing('narodni'));
    }

    public function testEveryWorkHasAForm(): void
    {
        self::assertSame([], $this->missing('forma'));
    }

    public function testEveryPreNineteenthCenturyWorkHasASubperiod(): void
    {
        self::assertSame([], $this->missing('podobdobi', 'do18'));
    }

    public function testAtLeastOneWorkCarriesTheCzechModernPoetryTag(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM work_tag wt JOIN tag t ON t.id = wt.tag_id
             JOIN work w ON w.id = wt.work_id
             WHERE w.canon_id = ? AND t.code = 'ceska_poezie_po_1950'"
        );
        $stmt->execute([$this->canonId]);

        self::assertGreaterThan(0, (int) $stmt->fetchColumn());
    }
}
```

- [ ] **Step 5: Run the whole suite**

Run:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit
```

Expected: PASS, every test. If `CanonCoverageTest` reports missing works, its failure message lists their titles — add those rows to the CSV and re-import.

- [ ] **Step 6: Prove the foundation works end to end**

Build a real 25-title list from the canon and check it:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www sh -c \
  "mysql --version >/dev/null 2>&1; php -r '
    require \"vendor/autoload.php\";
    \$c = require \"config.php\";
    \$pdo = Kanon\\Db\\Database::connect(\$c[\"db\"]);
    foreach (\$pdo->query(\"SELECT match_key FROM work ORDER BY sort_order LIMIT 25\") as \$r) {
        echo \$r[\"match_key\"], PHP_EOL;
    }' | php bin/check-list"
```

Expected: twelve rule lines. The first 25 works of the canon all come from chapter 1, so period and author rules will fail — that is correct behaviour and confirms the engine is reading real data, not defaults.

- [ ] **Step 7: Commit**

```bash
cd /data/www/kanonmaker
rm -f data/tags-scaffold.csv
git add data/tags-2025-2026.csv tests/Import/CanonCoverageTest.php
git commit -m "data: curated tags for the 2025/2026 canon with a coverage guard"
git push
```

---

## Notes for the executor

- **Run everything inside the container.** There is no PHP binary on the host. Every command in this plan is already written with `sudo docker exec -w /data/www/kanonmaker kanon-www …`.
- **The database tests are not isolated from each other by a framework.** `ImporterTest` wraps itself in a transaction and rolls back; `MigratorTest`, `SeedTest`, `RuleSetTest` and `CanonCoverageTest` read committed state. Run the suite against the development database, never a production one.
- **If a test asserting a count against the real snapshot fails** (358 entries, ~454 works), do not adjust the number to make it pass. It means the snapshot or the splitter changed, and the spec's figures need revisiting first.
- **Task 14 is data authorship and will take the longest.** It is worth doing carefully: every wrong tag becomes a rule check that lies to a student.
