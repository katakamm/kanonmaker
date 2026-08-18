<?php

declare(strict_types=1);

$appRoot = is_file(__DIR__ . '/approot.php')
    ? (string) require __DIR__ . '/approot.php'
    : dirname(__DIR__);

header('Content-Type: text/plain; charset=utf-8');
echo "kanonmaker admin ok php=" . PHP_VERSION . " root={$appRoot}\n";
