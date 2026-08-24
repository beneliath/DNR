<?php

function expectDatabaseErrorMode(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Database error mode test failed: {$message}\n");
        exit(1);
    }
}

$config = file_get_contents(__DIR__ . '/../src/config.php');
expectDatabaseErrorMode(
    is_string($config)
        && str_contains($config, 'MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT')
        && str_contains($config, 'catch (mysqli_sql_exception $exception)')
        && !str_contains($config, 'MYSQLI_REPORT_OFF'),
    'database failures should raise exceptions while connection failures retain a safe 503 boundary.'
);

echo "Database error mode tests passed.\n";
