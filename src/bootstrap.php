<?php

declare(strict_types=1);

foreach ([dirname(__DIR__) . '/vendor/autoload.php', '/opt/dnr/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}
require_once __DIR__ . '/application_runtime.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
