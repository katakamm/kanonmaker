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
