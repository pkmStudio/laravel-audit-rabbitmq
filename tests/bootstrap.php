<?php

declare(strict_types=1);

$autoloadPaths = [
    __DIR__.'/../vendor/autoload.php',
    '/var/www/vendor/autoload.php',
    '/home/user/projects/dan-center/vendor/autoload.php',
];

foreach ($autoloadPaths as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        require_once __DIR__.'/Pest.php';

        return;
    }
}

throw new RuntimeException('Unable to locate Composer autoload for audit package tests.');
