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
