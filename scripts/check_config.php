<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$sourceDirectory = trim((string) (getenv('DNR_APPLICATION_SOURCE_DIR') ?: ''));
if ($sourceDirectory === '') {
    $sourceDirectory = is_file('/var/www/html/application_runtime.php')
        ? '/var/www/html'
        : dirname(__DIR__) . '/src';
}
require_once $sourceDirectory . '/application_runtime.php';

$configuration = deploymentConfig();
fwrite(
    STDOUT,
    'Deployment configuration valid for ' . applicationBrandLabel()
    . ' (' . applicationTimezoneName() . ").\n"
);
