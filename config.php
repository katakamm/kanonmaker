<?php

declare(strict_types=1);

$local = __DIR__ . '/config.local.php';

if (!is_file($local)) {
    throw new RuntimeException('config.local.php is missing; copy it from config.local.php.example');
}

return require $local;
