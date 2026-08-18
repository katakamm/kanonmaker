<?php

declare(strict_types=1);

// KANON_CONFIG lets bin/deploy run migrations against production from here,
// where PHP actually exists; production's own container has none.
$local = __DIR__ . '/' . (getenv('KANON_CONFIG') ?: 'config.local.php');

if (!is_file($local)) {
    throw new RuntimeException('config.local.php is missing; copy it from config.local.php.example');
}

return require $local;
